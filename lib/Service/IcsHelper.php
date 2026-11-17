<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Exception\ServiceException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;

/**
 * ICS の生成・書き換え・読み取り。Sabre\VObject だけに依存する。
 *
 * 予定データ（event 配列）の形は {@see EventMerger} を参照。
 */
class IcsHelper {
	private const PRODID = '-//CalenAI//Nextcloud app//JA';
	private const TIME_FIELDS = ['start', 'end', 'allDay'];

	/**
	 * 新しい予定の ICS を作る。
	 */
	public static function build(string $uid, array $event, DateTimeZone $tz, DateTimeImmutable $now): string {
		$vcalendar = new VCalendar();
		$vcalendar->PRODID = self::PRODID;

		$stamp = self::utcStamp($now);
		/** @var VEvent $vevent */
		$vevent = $vcalendar->add('VEVENT', [
			'UID' => $uid,
			'SUMMARY' => $event['title'],
		]);
		$vevent->DTSTAMP = $stamp;
		$vevent->add('CREATED', $stamp);
		$vevent->add('LAST-MODIFIED', $stamp);
		$vevent->add('SEQUENCE', '0');

		self::writeTimes($vcalendar, $vevent, $event, $tz);
		self::writeOptionalText($vevent, 'LOCATION', $event['location'] ?? '');
		self::writeOptionalText($vevent, 'DESCRIPTION', $event['description'] ?? '');

		return $vcalendar->serialize();
	}

	/**
	 * 既存の ICS に、変更された項目だけを書き込む。それ以外のプロパティ（アラームなど）は残す。
	 *
	 * @param list<string> $changedFields
	 * @return string|null VEVENT がなければ null
	 */
	public static function apply(string $ics, array $event, array $changedFields, DateTimeZone $tz, DateTimeImmutable $now): ?string {
		$vcalendar = self::read($ics);
		$vevent = $vcalendar === null ? null : self::baseEvent($vcalendar);
		if ($vcalendar === null || $vevent === null) {
			return null;
		}

		if (in_array('title', $changedFields, true)) {
			$vevent->SUMMARY = $event['title'];
		}
		if (in_array('location', $changedFields, true)) {
			self::writeOptionalText($vevent, 'LOCATION', $event['location'] ?? '');
		}
		if (in_array('description', $changedFields, true)) {
			self::writeOptionalText($vevent, 'DESCRIPTION', $event['description'] ?? '');
		}
		if (array_intersect(self::TIME_FIELDS, $changedFields) !== []) {
			self::writeTimes($vcalendar, $vevent, $event, $tz);
		}

		self::touch($vevent, self::sequenceOf($vevent) + 1, $now);
		return $vcalendar->serialize();
	}

	/**
	 * 「元に戻す」用: 以前の ICS を、今の ICS より新しい版として書き戻せる形にする。
	 *
	 * @return string|null VEVENT がなければ null
	 */
	public static function restore(string $previousIcs, string $currentIcs, DateTimeImmutable $now): ?string {
		$vcalendar = self::read($previousIcs);
		$vevent = $vcalendar === null ? null : self::baseEvent($vcalendar);
		if ($vcalendar === null || $vevent === null) {
			return null;
		}
		$current = self::read($currentIcs);
		$currentEvent = $current === null ? null : self::baseEvent($current);
		$sequence = max(self::sequenceOf($vevent), $currentEvent === null ? 0 : self::sequenceOf($currentEvent)) + 1;

		self::touch($vevent, $sequence, $now);
		return $vcalendar->serialize();
	}

	/**
	 * ICS から予定データを読む。日時は $tz の現地時刻に直す。
	 *
	 * @return array{title: string, start: string, end: string, allDay: bool, location: string, description: string, uid: string, hasAttendees: bool}|null
	 */
	public static function parse(string $ics, DateTimeZone $tz, ?int &$durationSeconds = null): ?array {
		$durationSeconds = null;
		$vcalendar = self::read($ics);
		$vevent = $vcalendar === null ? null : self::baseEvent($vcalendar);
		if ($vevent === null || !isset($vevent->DTSTART)) {
			return null;
		}

		try {
			$dtStart = $vevent->DTSTART;
			$allDay = !$dtStart->hasTime();
			$start = DateTimeImmutable::createFromInterface($dtStart->getDateTime($tz));

			if ($allDay) {
				// DTEND は「翌日」を指す（その日を含まない）ので、最終日に直す
				$end = $start;
				if (isset($vevent->DTEND)) {
					$endExclusive = DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime($tz));
					$candidate = $endExclusive->modify('-1 day');
					if ($candidate > $start) {
						$end = $candidate;
					}
				} elseif (isset($vevent->DURATION)) {
					$candidate = $start->add($vevent->DURATION->getDateInterval())->modify('-1 day');
					if ($candidate > $start) {
						$end = $candidate;
					}
				}
				$startStr = $start->format('Y-m-d');
				$endStr = $end->format('Y-m-d');
			} else {
				$start = $start->setTimezone($tz);
				if (isset($vevent->DTEND)) {
					$end = DateTimeImmutable::createFromInterface($vevent->DTEND->getDateTime($tz))->setTimezone($tz);
				} elseif (isset($vevent->DURATION)) {
					$end = $start->add($vevent->DURATION->getDateInterval());
				} else {
					$end = $start;
				}
				$durationSeconds = $end->getTimestamp() - $start->getTimestamp();
				$startStr = $start->format('Y-m-d\TH:i');
				$endStr = $end->format('Y-m-d\TH:i');
			}
		} catch (\Throwable) {
			return null;
		}

		return [
			'title' => isset($vevent->SUMMARY) ? (string)$vevent->SUMMARY : '',
			'start' => $startStr,
			'end' => $endStr,
			'allDay' => $allDay,
			'location' => isset($vevent->LOCATION) ? (string)$vevent->LOCATION : '',
			'description' => isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : '',
			'uid' => isset($vevent->UID) ? (string)$vevent->UID : '',
			'hasAttendees' => self::componentsHaveAttendees($vcalendar),
		];
	}

	/**
	 * 出席者（ATTENDEE）のいる予定か。繰り返しの例外も含めて調べる。
	 * 出席者のいる予定は、変更のたびに出席者へ通知が要る（{@see EventManager} が修正・削除を断る）。
	 */
	public static function hasAttendees(string $ics): bool {
		$vcalendar = self::read($ics);
		return $vcalendar !== null && self::componentsHaveAttendees($vcalendar);
	}

	private static function componentsHaveAttendees(VCalendar $vcalendar): bool {
		foreach ($vcalendar->select('VEVENT') as $vevent) {
			if (isset($vevent->ATTENDEE)) {
				return true;
			}
		}
		return false;
	}

	private static function read(string $ics): ?VCalendar {
		try {
			$document = Reader::read($ics);
		} catch (\Throwable) {
			return null;
		}
		return $document instanceof VCalendar ? $document : null;
	}

	/**
	 * 繰り返しの例外（RECURRENCE-ID 付き）ではない、元の VEVENT を返す。
	 */
	private static function baseEvent(VCalendar $vcalendar): ?VEvent {
		$base = $vcalendar->getBaseComponent('VEVENT');
		return $base instanceof VEvent ? $base : null;
	}

	private static function writeTimes(VCalendar $vcalendar, VEvent $vevent, array $event, DateTimeZone $tz): void {
		unset($vevent->DTSTART, $vevent->DTEND, $vevent->DURATION);

		$naive = new DateTimeZone('UTC');
		if ($event['allDay']) {
			$start = new DateTimeImmutable($event['start'], $naive);
			// 終日の DTEND は最終日の翌日
			$endExclusive = (new DateTimeImmutable($event['end'], $naive))->modify('+1 day');
			$vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
			$vevent->add('DTEND', $endExclusive->format('Ymd'), ['VALUE' => 'DATE']);
			return;
		}

		$start = new DateTimeImmutable($event['start'], $tz);
		$end = new DateTimeImmutable($event['end'], $tz);
		if ($start->format('Y-m-d\TH:i') !== $event['start'] || $end->format('Y-m-d\TH:i') !== $event['end']) {
			throw ServiceException::invalid('夏時間の切り替えにより存在しない時刻です。開始と終了の時刻を確かめてください。');
		}
		// 長さ 0 の予定は DTEND を書かない（DTEND は DTSTART より後でなければならないため）
		if (isset($event['endTimestamp'])) {
			$end = $end->setTimestamp($event['endTimestamp']);
		}
		$hasEnd = $end > $start;
		if ($tz->getName() === 'UTC') {
			$vevent->add('DTSTART', $start->format('Ymd\THis\Z'));
			if ($hasEnd) {
				$vevent->add('DTEND', $end->format('Ymd\THis\Z'));
			}
			return;
		}

		$vevent->add('DTSTART', $start->format('Ymd\THis'), ['TZID' => $tz->getName()]);
		if ($hasEnd) {
			if (isset($event['endTimestamp'])) {
				$vevent->add('DTEND', self::utcStamp($end));
			} else {
				$vevent->add('DTEND', $end->format('Ymd\THis'), ['TZID' => $tz->getName()]);
			}
		}
		self::ensureTimezone($vcalendar, $tz, $start, $end);
	}

	private static function writeOptionalText(VEvent $vevent, string $name, string $value): void {
		if ($value === '') {
			unset($vevent->{$name});
		} else {
			$vevent->{$name} = $value;
		}
	}

	private static function touch(VEvent $vevent, int $sequence, DateTimeImmutable $now): void {
		$stamp = self::utcStamp($now);
		unset($vevent->DTSTAMP, $vevent->{'LAST-MODIFIED'}, $vevent->SEQUENCE);
		$vevent->add('DTSTAMP', $stamp);
		$vevent->add('LAST-MODIFIED', $stamp);
		$vevent->add('SEQUENCE', (string)$sequence);
	}

	private static function sequenceOf(VEvent $vevent): int {
		return isset($vevent->SEQUENCE) ? max(0, (int)(string)$vevent->SEQUENCE) : 0;
	}

	private static function utcStamp(DateTimeImmutable $now): string {
		return $now->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	/**
	 * TZID を使うときは、そのタイムゾーンの定義（VTIMEZONE）も入れておく。
	 * 予定の前後1年ぶんの切り替え（夏時間など）を書き出す。
	 */
	private static function ensureTimezone(VCalendar $vcalendar, DateTimeZone $tz, DateTimeImmutable $start, DateTimeImmutable $end): void {
		$tzid = $tz->getName();
		foreach ($vcalendar->select('VTIMEZONE') as $existing) {
			if (isset($existing->TZID) && (string)$existing->TZID === $tzid) {
				$vcalendar->remove($existing);
			}
		}

		$transitions = $tz->getTransitions(
			$start->modify('-1 year')->getTimestamp(),
			$end->modify('+1 year')->getTimestamp(),
		);
		if ($transitions === false || $transitions === []) {
			return;
		}

		$vtimezone = $vcalendar->createComponent('VTIMEZONE');
		$vtimezone->add('TZID', $tzid);

		if (count($transitions) === 1) {
			// 期間中に切り替えがない（例: Asia/Tokyo）
			$only = $transitions[0];
			$rule = $vcalendar->createComponent('STANDARD');
			$rule->add('DTSTART', '19700101T000000');
			$rule->add('TZOFFSETFROM', self::formatOffset($only['offset']));
			$rule->add('TZOFFSETTO', self::formatOffset($only['offset']));
			$rule->add('TZNAME', $only['abbr']);
			$vtimezone->add($rule);
		} else {
			for ($i = 1, $count = count($transitions); $i < $count; $i++) {
				$previous = $transitions[$i - 1];
				$transition = $transitions[$i];
				$rule = $vcalendar->createComponent($transition['isdst'] ? 'DAYLIGHT' : 'STANDARD');
				// 切り替えの瞬間を「切り替え前の現地時刻」で書く
				$rule->add('DTSTART', gmdate('Ymd\THis', $transition['ts'] + $previous['offset']));
				$rule->add('TZOFFSETFROM', self::formatOffset($previous['offset']));
				$rule->add('TZOFFSETTO', self::formatOffset($transition['offset']));
				$rule->add('TZNAME', $transition['abbr']);
				$vtimezone->add($rule);
			}
		}

		$vcalendar->add($vtimezone);
	}

	private static function formatOffset(int $seconds): string {
		$sign = $seconds < 0 ? '-' : '+';
		$seconds = abs($seconds);
		return sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
	}
}
