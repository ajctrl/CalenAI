<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Exception\ServiceException;

/**
 * 予定データの検証と差分マージ。Nextcloud に依存しない純粋なロジック。
 *
 * 予定データ（event 配列）の形:
 *   title        string
 *   start, end   時刻あり: "YYYY-MM-DDTHH:MM" / 終日: "YYYY-MM-DD"（現地時刻。終日の end は最終日を含む）
 *   allDay       bool
 *   location     string
 *   description  string
 */
class EventMerger {
	public const FIELDS = ['title', 'start', 'end', 'allDay', 'location', 'description'];

	public const MAX_TITLE = 255;
	public const MAX_LOCATION = 255;
	public const MAX_DESCRIPTION = 2000;

	private const DEFAULT_DURATION_MINUTES = 60;
	private const MIN_YEAR = 2000;
	private const MAX_YEAR = 2100;

	/**
	 * 新規作成用: AI またはフォームからの値を検証して正規化する。
	 *
	 * @param array<string, mixed> $raw
	 * @return array{title: string, start: string, end: string, allDay: bool, location: string, description: string}
	 * @throws ServiceException 開始日時がない、日付として正しくない、終了が開始より前、など
	 */
	public static function normalizeNew(array $raw, ?DateTimeZone $tz = null): array {
		$start = self::parseOrFail($raw['start'] ?? null);
		if ($start === null) {
			throw ServiceException::invalid('開始日時がわかりませんでした。日付と時刻を入れてもう一度お試しください。');
		}
		$end = self::parseOrFail($raw['end'] ?? null);

		// allDay が明示されていなければ、開始に時刻があるかどうかで決める
		$allDay = is_bool($raw['allDay'] ?? null) ? $raw['allDay'] : !$start['hasTime'];
		if (!$allDay && !$start['hasTime']) {
			$allDay = true;
		}

		if ($allDay) {
			$startDate = $start['dt']->setTime(0, 0);
			$endDate = $end === null ? $startDate : $end['dt']->setTime(0, 0);
			if ($endDate < $startDate) {
				throw ServiceException::invalid('終了日が開始日より前になっています。');
			}
			$range = ['start' => $startDate->format('Y-m-d'), 'end' => $endDate->format('Y-m-d'), 'allDay' => true];
		} elseif ($end === null || (!$end['hasTime'] && $start['dt']->format('Y-m-d') === $end['dt']->format('Y-m-d'))) {
			$range = self::timedRange($start['dt'], $tz);
		} else {
			$startDt = $start['dt'];
			$endDt = $end['hasTime'] ? $end['dt'] : $end['dt']->setTime((int)$startDt->format('H'), (int)$startDt->format('i'));
			if ($endDt <= $startDt) {
				throw ServiceException::invalid('終了が開始より前になっています。');
			}
			$range = ['start' => $startDt->format('Y-m-d\TH:i'), 'end' => $endDt->format('Y-m-d\TH:i'), 'allDay' => false];
		}

		$title = self::cleanText($raw['title'] ?? '', self::MAX_TITLE, true);
		if ($title === '') {
			$title = '予定';
		}

		return ['title' => $title] + $range + [
			'location' => self::cleanText($raw['location'] ?? '', self::MAX_LOCATION, true),
			'description' => self::cleanText($raw['description'] ?? '', self::MAX_DESCRIPTION, false),
		];
	}

	private static function timedRange(DateTimeImmutable $localStart, ?DateTimeZone $tz, int $seconds = self::DEFAULT_DURATION_MINUTES * 60): array {
		$tz ??= new DateTimeZone('UTC');
		$startStr = $localStart->format('Y-m-d\TH:i');
		$start = new DateTimeImmutable($startStr, $tz);
		$end = $start->setTimestamp($start->getTimestamp() + $seconds);
		$endStr = $end->format('Y-m-d\TH:i');
		$range = ['start' => $startStr, 'end' => $endStr, 'allDay' => false];
		// 秋の切り替えで同じ現地時刻が2回ある場合は、終了の実時刻も ICS に渡す。
		if ((new DateTimeImmutable($endStr, $tz))->getTimestamp() !== $end->getTimestamp()) {
			$range['endTimestamp'] = $end->getTimestamp();
		}
		return $range;
	}

	/**
	 * 既存の予定に、変更された項目だけをマージする。
	 *
	 * - start だけ変更 → 元の長さを保って end もずらす（終了のない、長さ 0 の予定もそのまま動かせる）
	 * - end だけ変更 → start はそのまま
	 * - 時刻ありの予定に日付だけ渡された → 元の時刻を保つ
	 * - 終日の予定に時刻が渡された → 時刻ありに切り替える（開始時刻が必要）
	 * - 文字の項目が今と同じ値なら変更しない（整形し直して切り詰めたりしない）
	 *
	 * @param array{title: string, start: string, end: string, allDay: bool, location: string, description: string} $current
	 * @param array<string, mixed> $patch
	 * @param list<string> $changedFields
	 * @return array{event: array{title: string, start: string, end: string, allDay: bool, location: string, description: string}, changes: list<array{field: string, from: mixed, to: mixed}>}
	 * @throws ServiceException
	 */
	public static function merge(array $current, array $patch, array $changedFields, ?DateTimeZone $tz = null): array {
		$changed = array_values(array_intersect(self::FIELDS, $changedFields));
		// 送られてきた値が今の値とまったく同じ文字項目は触らない。フォームは開いた時点の値を
		// そのまま送り返すので、長すぎる説明や連続した空白を含む件名が勝手に整形されるのを防ぐ。
		$changed = array_values(array_filter($changed, static fn (string $field): bool =>
			!in_array($field, ['title', 'location', 'description'], true)
			|| ($patch[$field] ?? null) !== ($current[$field] ?? null)));
		$new = $current;

		if (in_array('title', $changed, true)) {
			$title = self::cleanText($patch['title'] ?? '', self::MAX_TITLE, true);
			if ($title === '') {
				throw ServiceException::invalid('件名を空にはできません。');
			}
			$new['title'] = $title;
		}
		foreach (['location' => self::MAX_LOCATION, 'description' => self::MAX_DESCRIPTION] as $field => $limit) {
			if (in_array($field, $changed, true)) {
				if (!is_string($patch[$field] ?? null)) {
					throw ServiceException::invalid('場所と説明は文字列で指定してください。');
				}
				$new[$field] = self::cleanText($patch[$field], $limit, $field === 'location');
			}
		}

		$timeChanged = array_filter(array_intersect(['start', 'end', 'allDay'], $changed),
			static fn (string $field): bool => ($patch[$field] ?? null) !== $current[$field]);
		if ($timeChanged !== []) {
			$patchStart = in_array('start', $changed, true) ? self::parseOrFail($patch['start'] ?? null) : null;
			$patchEnd = in_array('end', $changed, true) ? self::parseOrFail($patch['end'] ?? null) : null;
			$patchAllDay = in_array('allDay', $changed, true) && is_bool($patch['allDay'] ?? null) ? $patch['allDay'] : null;

			if ($patchStart !== null || $patchEnd !== null || $patchAllDay !== null) {
				$new = array_merge($new, self::mergeTime($current, $patchStart, $patchEnd, $patchAllDay, $tz));
			}
		}

		return ['event' => $new, 'changes' => self::diff($current, $new)];
	}

	/**
	 * @param array{dt: DateTimeImmutable, hasTime: bool}|null $patchStart
	 * @param array{dt: DateTimeImmutable, hasTime: bool}|null $patchEnd
	 * @return array{start: string, end: string, allDay: bool}
	 */
	private static function mergeTime(array $current, ?array $patchStart, ?array $patchEnd, ?bool $patchAllDay, ?DateTimeZone $tz): array {
		$curStart = self::parseLocal($current['start']);
		$curEnd = self::parseLocal($current['end']);
		if ($curStart === null || $curEnd === null) {
			throw ServiceException::invalid('元の予定の日時を読み取れませんでした。');
		}
		$wasAllDay = (bool)$current['allDay'];

		if ($patchAllDay !== null) {
			$allDay = $patchAllDay;
		} elseif ($wasAllDay && (($patchStart !== null && $patchStart['hasTime']) || ($patchEnd !== null && $patchEnd['hasTime']))) {
			// 終日の予定に「10時から」「17時まで」と言われた → 時刻ありへ切り替え（開始時刻がなければ下でエラー）
			$allDay = false;
		} else {
			$allDay = $wasAllDay;
		}

		if ($allDay) {
			$curStartDate = $curStart['dt']->setTime(0, 0);
			$curEndDate = $curEnd['dt']->setTime(0, 0);
			$start = $patchStart !== null ? $patchStart['dt']->setTime(0, 0) : $curStartDate;
			if ($patchEnd !== null) {
				$end = $patchEnd['dt']->setTime(0, 0);
			} else {
				// 日数を保つ
				$days = (int)$curStartDate->diff($curEndDate)->days;
				$end = $start->modify('+' . $days . ' days');
			}
			if ($end < $start) {
				throw ServiceException::invalid('終了日が開始日より前になっています。');
			}
			return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'allDay' => true];
		}

		if ($wasAllDay) {
			// 終日 → 時刻あり: 開始時刻が必要
			if ($patchStart === null || !$patchStart['hasTime']) {
				throw ServiceException::invalid('開始時刻を指定してください。');
			}
			$start = $patchStart['dt'];
			if ($patchEnd === null || !$patchEnd['hasTime']) {
				return self::timedRange($start, $tz);
			}
			$end = $patchEnd['dt'];
		} else {
			if ($patchStart === null) {
				$start = $curStart['dt'];
			} elseif ($patchStart['hasTime']) {
				$start = $patchStart['dt'];
			} else {
				// 日付だけ変更 → 元の時刻を保つ
				$start = $patchStart['dt']->setTime((int)$curStart['dt']->format('H'), (int)$curStart['dt']->format('i'));
			}

			if ($patchEnd === null) {
				$seconds = $current['durationSeconds'] ?? ($curEnd['dt']->getTimestamp() - $curStart['dt']->getTimestamp());
				return self::timedRange($start, $tz, max(0, $seconds));
			} elseif ($patchEnd['hasTime']) {
				$end = $patchEnd['dt'];
			} else {
				$end = $patchEnd['dt']->setTime((int)$curEnd['dt']->format('H'), (int)$curEnd['dt']->format('i'));
			}
		}

		if ($end <= $start) {
			throw ServiceException::invalid('終了は開始より後にしてください。');
		}
		return ['start' => $start->format('Y-m-d\TH:i'), 'end' => $end->format('Y-m-d\TH:i'), 'allDay' => false];
	}

	/**
	 * @return list<array{field: string, from: mixed, to: mixed}>
	 */
	public static function diff(array $old, array $new): array {
		$changes = [];
		foreach (self::FIELDS as $field) {
			$from = $old[$field] ?? null;
			$to = $new[$field] ?? null;
			if ($from !== $to) {
				$changes[] = ['field' => $field, 'from' => $from, 'to' => $to];
			}
		}
		return $changes;
	}

	/**
	 * 値がなければ null、値があるのに日付として読めなければエラーにする。
	 *
	 * @return array{dt: DateTimeImmutable, hasTime: bool}|null
	 * @throws ServiceException
	 */
	private static function parseOrFail(mixed $value): ?array {
		if ($value === null || (is_string($value) && trim($value) === '')) {
			return null;
		}
		$parsed = self::parseLocal($value);
		if ($parsed === null) {
			throw ServiceException::invalid('日時として正しくない値がありました。日付と時刻を確かめてもう一度お試しください。');
		}
		return $parsed;
	}

	/**
	 * 現地時刻の文字列を厳密に読む。「2月30日」のような存在しない日付は null。
	 * 計算用に UTC として扱う（タイムゾーンの意味は持たせない）。
	 *
	 * @return array{dt: DateTimeImmutable, hasTime: bool}|null
	 */
	public static function parseLocal(mixed $value): ?array {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		if ($value === '') {
			return null;
		}
		$utc = new DateTimeZone('UTC');
		$formats = [
			'Y-m-d\TH:i' => true,
			'Y-m-d\TH:i:s' => true,
			'Y-m-d H:i' => true,
			'Y-m-d H:i:s' => true,
			'Y-m-d' => false,
		];
		foreach ($formats as $format => $hasTime) {
			$dt = DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
			if ($dt === false || $dt->format($format) !== $value) {
				continue;
			}
			$year = (int)$dt->format('Y');
			if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
				return null;
			}
			if ($hasTime) {
				// 秒は持たない
				$dt = $dt->setTime((int)$dt->format('H'), (int)$dt->format('i'));
			}
			return ['dt' => $dt, 'hasTime' => $hasTime];
		}
		return null;
	}

	/**
	 * 文字列を整えて長さを制限する。制御文字は取り除く。
	 */
	public static function cleanText(mixed $value, int $maxLength, bool $singleLine): string {
		if (!is_string($value)) {
			return '';
		}
		$value = str_replace(["\r\n", "\r"], "\n", $value);
		if ($singleLine) {
			$value = preg_replace('/\s+/u', ' ', $value) ?? '';
		}
		// 改行とタブ以外の制御文字を除く
		$value = preg_replace('/[^\P{Cc}\n\t]/u', '', $value) ?? '';
		$value = trim($value);
		if (mb_strlen($value) > $maxLength) {
			$value = mb_substr($value, 0, $maxLength);
		}
		return $value;
	}
}
