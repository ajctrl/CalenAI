<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\EventMerger;
use OCA\CalenAI\Service\IcsHelper;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

class EventMergerTest extends TestCase {
	private const DENTIST = [
		'title' => '歯医者',
		'start' => '2026-11-17T10:00',
		'end' => '2026-11-17T11:00',
		'allDay' => false,
		'location' => '',
		'description' => '',
	];

	private const TRIP = [
		'title' => '出張',
		'start' => '2026-11-20',
		'end' => '2026-11-21',
		'allDay' => true,
		'location' => '大阪',
		'description' => '',
	];

	// ---- 新規作成の検証

	public function testDefaultDurationAcrossDstTransitions(): void {
		$tz = new DateTimeZone('America/New_York');
		foreach (['2027-03-14' => '03:30', '2027-11-07' => '01:30'] as $date => $expectedEnd) {
			$event = EventMerger::normalizeNew(['start' => $date . 'T01:30'], $tz);
			$this->assertSame($date . 'T' . $expectedEnd, $event['end']);
			$ics = IcsHelper::build('dst', $event, $tz, new DateTimeImmutable());
			$vevent = Reader::read($ics)->VEVENT;
			$this->assertSame(3600, $vevent->DTEND->getDateTime()->getTimestamp() - $vevent->DTSTART->getDateTime()->getTimestamp());
			$current = IcsHelper::parse($ics, $tz, $duration);
			$current['durationSeconds'] = $duration;
			$moved = EventMerger::merge($current, ['start' => '2027-11-09T10:00'], ['start'], $tz);
			$this->assertSame('2027-11-09T11:00', $moved['event']['end']);
		}
	}

	public function testMalformedLocationCannotEraseTheExistingLocation(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::TRIP, ['location' => ['city' => '大阪']], ['location']);
	}

	public function testNewEventWithoutEndGetsOneHour(): void {
		$event = EventMerger::normalizeNew(['title' => '歯医者', 'start' => '2026-11-17T10:00', 'end' => null, 'allDay' => false]);
		$this->assertSame('2026-11-17T10:00', $event['start']);
		$this->assertSame('2026-11-17T11:00', $event['end']);
		$this->assertFalse($event['allDay']);
	}

	public function testEarlierDateOnlyEndIsRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::normalizeNew(['start' => '2026-11-17T10:00', 'end' => '2026-11-16']);
	}

	public function testNewEventKeepsGivenEnd(): void {
		$event = EventMerger::normalizeNew(['title' => '打合せ', 'start' => '2026-11-24T15:00', 'end' => '2026-11-24T16:30', 'allDay' => false, 'location' => ' 会議室A ']);
		$this->assertSame('2026-11-24T16:30', $event['end']);
		$this->assertSame('会議室A', $event['location']);
	}

	public function testNewEventCrossingMidnight(): void {
		$event = EventMerger::normalizeNew(['title' => '夜勤', 'start' => '2026-11-17T23:00', 'end' => '2026-11-18T01:00', 'allDay' => false]);
		$this->assertSame('2026-11-18T01:00', $event['end']);
	}

	public function testNewAllDayEventDefaultsToSingleDay(): void {
		$event = EventMerger::normalizeNew(['title' => '休み', 'start' => '2026-11-20', 'end' => null, 'allDay' => true]);
		$this->assertTrue($event['allDay']);
		$this->assertSame('2026-11-20', $event['start']);
		$this->assertSame('2026-11-20', $event['end']);
	}

	public function testDateOnlyStartBecomesAllDayEvenIfFlagSaysOtherwise(): void {
		$event = EventMerger::normalizeNew(['title' => '休み', 'start' => '2026-11-20', 'end' => null, 'allDay' => false]);
		$this->assertTrue($event['allDay']);
	}

	public function testNewEventRejectsEndBeforeStart(): void {
		$this->expectException(ServiceException::class);
		EventMerger::normalizeNew(['title' => 'x', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T09:00', 'allDay' => false]);
	}

	public function testNewEventRejectsMissingStart(): void {
		$this->expectException(ServiceException::class);
		EventMerger::normalizeNew(['title' => 'x', 'start' => null]);
	}

	public function testNewEventRejectsImpossibleDate(): void {
		$this->expectException(ServiceException::class);
		EventMerger::normalizeNew(['title' => 'x', 'start' => '2026-02-30T10:00']);
	}

	public function testNewEventRejectsImpossibleEndInsteadOfIgnoringIt(): void {
		$this->expectException(ServiceException::class);
		EventMerger::normalizeNew(['title' => 'x', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T25:00']);
	}

	public function testTitleIsTrimmedToLimitAndDefaulted(): void {
		$long = EventMerger::normalizeNew(['title' => str_repeat('あ', 300), 'start' => '2026-11-17T10:00']);
		$this->assertSame(255, mb_strlen($long['title']));

		$empty = EventMerger::normalizeNew(['title' => '  ', 'start' => '2026-11-17T10:00']);
		$this->assertSame('予定', $empty['title']);
	}

	public function testControlCharactersAndNewlinesAreRemovedFromSingleLineFields(): void {
		$event = EventMerger::normalizeNew(['title' => "歯医者\r\nBEGIN:VEVENT\x00", 'start' => '2026-11-17T10:00']);
		$this->assertSame('歯医者 BEGIN:VEVENT', $event['title']);
	}

	// ---- 差分マージ

	public function testChangingOnlyStartKeepsDuration(): void {
		// 「いや11時だった」
		$result = EventMerger::merge(self::DENTIST, ['start' => '2026-11-17T11:00'], ['start']);
		$this->assertSame('2026-11-17T11:00', $result['event']['start']);
		$this->assertSame('2026-11-17T12:00', $result['event']['end']);
		$this->assertSame(['start', 'end'], array_column($result['changes'], 'field'));
	}

	public function testStartOfAZeroLengthEventCanBeMoved(): void {
		// 終了のない予定（DTSTART だけ）は、開始と終了が同じ時刻として読まれる
		$reminder = ['start' => '2026-11-17T10:00', 'end' => '2026-11-17T10:00'] + self::DENTIST;
		$result = EventMerger::merge($reminder, ['start' => '2026-11-17T11:00'], ['start']);
		$this->assertSame('2026-11-17T11:00', $result['event']['start']);
		$this->assertSame('2026-11-17T11:00', $result['event']['end']);

		$result = EventMerger::merge($reminder, ['start' => '2026-11-19'], ['start']);
		$this->assertSame('2026-11-19T10:00', $result['event']['start']);
		$this->assertSame('2026-11-19T10:00', $result['event']['end']);

		// 終了を付けることもできる
		$result = EventMerger::merge($reminder, ['end' => '2026-11-17T10:30'], ['end']);
		$this->assertSame('2026-11-17T10:30', $result['event']['end']);
	}

	public function testExplicitEndEqualToStartIsStillRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::DENTIST, ['end' => '2026-11-17T10:00'], ['end']);
	}

	public function testChangingOnlyEndKeepsStart(): void {
		$result = EventMerger::merge(self::DENTIST, ['end' => '2026-11-17T12:30'], ['end']);
		$this->assertSame('2026-11-17T10:00', $result['event']['start']);
		$this->assertSame('2026-11-17T12:30', $result['event']['end']);
	}

	public function testOnlyFieldsListedAsChangedAreApplied(): void {
		// AI が余計な項目を埋めてきても、changedFields に無いものは無視する
		$result = EventMerger::merge(self::DENTIST, ['title' => '別の件名', 'location' => '駅前歯科', 'start' => '2026-12-01T09:00'], ['location']);
		$this->assertSame('歯医者', $result['event']['title']);
		$this->assertSame('2026-11-17T10:00', $result['event']['start']);
		$this->assertSame('駅前歯科', $result['event']['location']);
		$this->assertSame([['field' => 'location', 'from' => '', 'to' => '駅前歯科']], $result['changes']);
	}

	public function testDateOnlyChangeOnTimedEventKeepsTimeOfDay(): void {
		// 「明後日に変更」
		$result = EventMerger::merge(self::DENTIST, ['start' => '2026-11-19'], ['start']);
		$this->assertSame('2026-11-19T10:00', $result['event']['start']);
		$this->assertSame('2026-11-19T11:00', $result['event']['end']);
		$this->assertFalse($result['event']['allDay']);
	}

	public function testMovingAllDayEventKeepsNumberOfDays(): void {
		$result = EventMerger::merge(self::TRIP, ['start' => '2026-11-25'], ['start']);
		$this->assertSame('2026-11-25', $result['event']['start']);
		$this->assertSame('2026-11-26', $result['event']['end']);
		$this->assertTrue($result['event']['allDay']);
	}

	public function testGivingATimeToAllDayEventMakesItTimed(): void {
		$result = EventMerger::merge(self::TRIP, ['start' => '2026-11-20T14:00'], ['start']);
		$this->assertFalse($result['event']['allDay']);
		$this->assertSame('2026-11-20T14:00', $result['event']['start']);
		$this->assertSame('2026-11-20T15:00', $result['event']['end']);
	}

	public function testSwitchingTimedEventToAllDay(): void {
		$result = EventMerger::merge(self::DENTIST, ['allDay' => true], ['allDay']);
		$this->assertTrue($result['event']['allDay']);
		$this->assertSame('2026-11-17', $result['event']['start']);
		$this->assertSame('2026-11-17', $result['event']['end']);
	}

	public function testSwitchingAllDayToTimedWithoutTimeIsRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::TRIP, ['allDay' => false], ['allDay']);
	}

	public function testEndBeforeStartIsRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::DENTIST, ['end' => '2026-11-17T09:00'], ['end']);
	}

	public function testImpossibleDateInPatchIsRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::DENTIST, ['start' => '2026-13-01T10:00'], ['start']);
	}

	public function testEmptyTitleIsRejected(): void {
		$this->expectException(ServiceException::class);
		EventMerger::merge(self::DENTIST, ['title' => ' '], ['title']);
	}

	public function testLocationCanBeCleared(): void {
		$result = EventMerger::merge(self::TRIP, ['location' => ''], ['location']);
		$this->assertSame('', $result['event']['location']);
		$this->assertSame([['field' => 'location', 'from' => '大阪', 'to' => '']], $result['changes']);
	}

	public function testSameValuesProduceNoChanges(): void {
		$result = EventMerger::merge(self::DENTIST, self::DENTIST, EventMerger::FIELDS);
		$this->assertSame([], $result['changes']);
		$this->assertSame(self::DENTIST, $result['event']);
	}

	public function testFormEditWithAllFields(): void {
		$form = [
			'title' => '歯科検診',
			'start' => '2026-11-18T09:30',
			'end' => '2026-11-18T10:00',
			'allDay' => false,
			'location' => '駅前歯科',
			'description' => "保険証を持参\n診察券も",
		];
		$result = EventMerger::merge(self::DENTIST, $form, EventMerger::FIELDS);
		$this->assertSame($form, $result['event']);
		$this->assertSame(['title', 'start', 'end', 'location', 'description'], array_column($result['changes'], 'field'));
	}

	public function testUnknownFieldNamesAreIgnored(): void {
		$result = EventMerger::merge(self::DENTIST, ['uid' => 'x', 'title' => '新'], ['uid', 'calendarId']);
		$this->assertSame([], $result['changes']);
	}

	public function testUnchangedTextFieldsAreNotReformatted(): void {
		$long = str_repeat('あ', EventMerger::MAX_DESCRIPTION + 1000);
		$current = ['title' => '打合せ  A', 'location' => str_repeat('x', 300), 'description' => $long] + self::DENTIST;
		$form = ['title' => '打合せ  A', 'location' => '会議室B', 'description' => $long];
		$result = EventMerger::merge($current, $form, ['title', 'location', 'description']);
		$this->assertSame(['location'], array_column($result['changes'], 'field'));
		$this->assertSame($long, $result['event']['description']);
		$this->assertSame('打合せ  A', $result['event']['title']);
	}

	public function testTimedEndOnAllDayEventWithoutStartIsRejected(): void {
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('開始時刻を指定してください');
		EventMerger::merge(self::TRIP, ['end' => '2026-11-20T17:00'], ['end']);
	}

	public function testTimedEndAndStartOnAllDayEventMakesItTimed(): void {
		$result = EventMerger::merge(self::TRIP, ['start' => '2026-11-20T09:00', 'end' => '2026-11-20T17:00'], ['start', 'end']);
		$this->assertFalse($result['event']['allDay']);
		$this->assertSame('2026-11-20T17:00', $result['event']['end']);
	}
}
