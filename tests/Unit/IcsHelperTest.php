<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\IcsHelper;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

class IcsHelperTest extends TestCase {
	private const TIMED = [
		'title' => '田中さんと打合せ',
		'start' => '2026-11-24T15:00',
		'end' => '2026-11-24T16:00',
		'allDay' => false,
		'location' => '会議室A',
		'description' => '',
	];

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-16T05:00:00Z');
	}

	public function testBuildTimedEventUsesTzidAndRoundTrips(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$ics = IcsHelper::build('uid-1', self::TIMED, $tz, self::now());

		$this->assertStringContainsString('DTSTART;TZID=Asia/Tokyo:20261124T150000', $ics);
		$this->assertStringContainsString('DTEND;TZID=Asia/Tokyo:20261124T160000', $ics);
		$this->assertStringContainsString('BEGIN:VTIMEZONE', $ics);
		$this->assertStringContainsString('TZOFFSETTO:+0900', $ics);
		$this->assertStringContainsString('UID:uid-1', $ics);
		$this->assertStringNotContainsString('DESCRIPTION', $ics);
		// 妥当な iCalendar であること
		$this->assertSame([], Reader::read($ics)->validate());

		$parsed = IcsHelper::parse($ics, $tz);
		$this->assertSame(self::TIMED + ['uid' => 'uid-1', 'hasAttendees' => false], $parsed);
	}

	public function testLongUnicodeTitleSurvivesCalendarSerializationAndEditing(): void {
		$timezone = new DateTimeZone('Asia/Tokyo');
		$title = str_repeat('会議📅', 20);
		$event = ['title' => $title] + self::TIMED;
		$ics = IcsHelper::build('folded-title', $event, $timezone, self::now());
		$updated = IcsHelper::apply($ics, ['location' => 'Room B'], ['location'], $timezone, self::now());

		$this->assertNotNull($updated);
		$this->assertTrue(mb_check_encoding($updated, 'UTF-8'));
		$parsed = IcsHelper::parse($updated, $timezone);
		$this->assertSame($title, $parsed['title']);
		$this->assertSame('Room B', $parsed['location']);
	}

	public function testBuildAllDayEventUsesExclusiveEndDate(): void {
		$event = ['title' => '出張', 'start' => '2026-11-20', 'end' => '2026-11-21', 'allDay' => true, 'location' => '', 'description' => ''];
		$ics = IcsHelper::build('uid-2', $event, new DateTimeZone('Asia/Tokyo'), self::now());

		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261120', $ics);
		// 21日まで（21日を含む）なので、DTEND は22日
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261122', $ics);
		$this->assertStringNotContainsString('VTIMEZONE', $ics);
		$this->assertSame([], Reader::read($ics)->validate());

		$parsed = IcsHelper::parse($ics, new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('2026-11-20', $parsed['start']);
		$this->assertSame('2026-11-21', $parsed['end']);
		$this->assertTrue($parsed['allDay']);
	}

	public function testNonexistentLocalTimeIsRejected(): void {
		$this->expectException(ServiceException::class);
		$event = ['start' => '2026-03-08T02:30', 'end' => '2026-03-08T03:30'] + self::TIMED;
		IcsHelper::build('uid-gap', $event, new DateTimeZone('America/New_York'), self::now());
	}

	public function testBuildWithDaylightSavingTimezoneWritesBothRules(): void {
		$event = ['title' => 'call', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00', 'allDay' => false, 'location' => '', 'description' => ''];
		$ics = IcsHelper::build('uid-3', $event, new DateTimeZone('America/New_York'), self::now());

		$this->assertStringContainsString('BEGIN:DAYLIGHT', $ics);
		$this->assertStringContainsString('BEGIN:STANDARD', $ics);
		$this->assertStringContainsString('TZOFFSETTO:-0400', $ics);
		$this->assertSame([], Reader::read($ics)->validate());
	}

	public function testSpecialCharactersAreEscapedNotInjected(): void {
		$event = self::TIMED;
		$event['title'] = "打合せ; 資料, 持参\\";
		$event['description'] = "1行目\n2行目\nBEGIN:VALARM";
		$ics = IcsHelper::build('uid-4', $event, new DateTimeZone('Asia/Tokyo'), self::now());

		$this->assertSame(0, substr_count($ics, "\nBEGIN:VALARM"));
		$parsed = IcsHelper::parse($ics, new DateTimeZone('Asia/Tokyo'));
		$this->assertSame($event['title'], $parsed['title']);
		$this->assertSame($event['description'], $parsed['description']);
	}

	public function testParseConvertsToRequestedTimezone(): void {
		$ics = IcsHelper::build('uid-5', self::TIMED, new DateTimeZone('Asia/Tokyo'), self::now());
		$parsed = IcsHelper::parse($ics, new DateTimeZone('UTC'));
		$this->assertSame('2026-11-24T06:00', $parsed['start']);
		$this->assertSame('2026-11-24T07:00', $parsed['end']);
	}

	public function testApplyChangesOnlyListedFieldsAndKeepsEverythingElse(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$original = IcsHelper::build('uid-6', self::TIMED, $tz, self::now());
		// 外部のアプリが付けたアラームと独自プロパティ
		$original = str_replace(
			"END:VEVENT",
			"X-CUSTOM:keep-me\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:r\r\nTRIGGER:-PT15M\r\nEND:VALARM\r\nEND:VEVENT",
			$original,
		);

		$new = self::TIMED;
		$new['start'] = '2026-11-24T16:00';
		$new['end'] = '2026-11-24T17:00';
		$new['title'] = '無視されるはずの件名';
		$later = new DateTimeImmutable('2026-11-16T06:00:00Z');
		$updated = IcsHelper::apply($original, $new, ['start', 'end'], $tz, $later);

		$this->assertNotNull($updated);
		$this->assertStringContainsString('DTSTART;TZID=Asia/Tokyo:20261124T160000', $updated);
		$this->assertStringContainsString('SUMMARY:田中さんと打合せ', $updated);
		$this->assertStringContainsString('X-CUSTOM:keep-me', $updated);
		$this->assertStringContainsString('TRIGGER:-PT15M', $updated);
		$this->assertStringContainsString('SEQUENCE:1', $updated);
		$this->assertStringContainsString('LAST-MODIFIED:20261116T060000Z', $updated);
		$this->assertSame(1, substr_count($updated, 'BEGIN:VTIMEZONE'));
		$this->assertSame([], Reader::read($updated)->validate());
	}

	public function testApplyCanSwitchToAllDayAndClearLocation(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$original = IcsHelper::build('uid-7', self::TIMED, $tz, self::now());
		$new = ['title' => '田中さんと打合せ', 'start' => '2026-11-24', 'end' => '2026-11-24', 'allDay' => true, 'location' => '', 'description' => ''];
		$updated = IcsHelper::apply($original, $new, ['start', 'end', 'allDay', 'location'], $tz, self::now());

		$this->assertStringContainsString('DTSTART;VALUE=DATE:20261124', $updated);
		$this->assertStringContainsString('DTEND;VALUE=DATE:20261125', $updated);
		$this->assertStringNotContainsString('LOCATION', $updated);
		$this->assertSame($new + ['uid' => 'uid-7', 'hasAttendees' => false], IcsHelper::parse($updated, $tz));
	}

	public function testRestoreBringsBackOldContentWithNewerSequence(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$v0 = IcsHelper::build('uid-8', self::TIMED, $tz, self::now());
		$changed = self::TIMED;
		$changed['start'] = '2026-11-24T16:00';
		$changed['end'] = '2026-11-24T17:00';
		$v1 = IcsHelper::apply($v0, $changed, ['start', 'end'], $tz, self::now());

		$restored = IcsHelper::restore($v0, $v1, new DateTimeImmutable('2026-11-16T07:00:00Z'));
		$this->assertSame(self::TIMED + ['uid' => 'uid-8', 'hasAttendees' => false], IcsHelper::parse($restored, $tz));
		// 同期クライアントが「古い版」と見なさないよう、SEQUENCE は進める
		$this->assertStringContainsString('SEQUENCE:2', $restored);
	}

	public function testParseReadsEventsWrittenByOtherClients(): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Other//EN\r\nBEGIN:VEVENT\r\nUID:ext-1\r\nDTSTAMP:20261116T000000Z\r\n"
			. "DTSTART:20261117T010000Z\r\nDURATION:PT90M\r\nSUMMARY:UTC event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$parsed = IcsHelper::parse($ics, new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('2026-11-17T10:00', $parsed['start']);
		$this->assertSame('2026-11-17T11:30', $parsed['end']);
		$this->assertFalse($parsed['allDay']);
	}

	public function testZeroLengthEventStaysWithoutEndWhenItsStartMoves(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Other//EN\r\nBEGIN:VEVENT\r\nUID:ext-2\r\nDTSTAMP:20261116T000000Z\r\n"
			. "DTSTART:20261117T010000Z\r\nSUMMARY:締め切り\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$parsed = IcsHelper::parse($ics, $tz);
		$this->assertSame('2026-11-17T10:00', $parsed['start']);
		$this->assertSame('2026-11-17T10:00', $parsed['end']);

		$moved = ['start' => '2026-11-17T11:00', 'end' => '2026-11-17T11:00'] + $parsed;
		$updated = IcsHelper::apply($ics, $moved, ['start', 'end'], $tz, self::now());
		$this->assertStringContainsString('DTSTART;TZID=Asia/Tokyo:20261117T110000', $updated);
		$this->assertStringNotContainsString('DTEND', $updated);
		$this->assertSame([], Reader::read($updated)->validate());

		$again = IcsHelper::parse($updated, $tz);
		$this->assertSame('2026-11-17T11:00', $again['start']);
		$this->assertSame('2026-11-17T11:00', $again['end']);
	}

	public function testAttendeesAreDetected(): void {
		$tz = new DateTimeZone('Asia/Tokyo');
		$ics = IcsHelper::build('uid-1', self::TIMED, $tz, self::now());
		$this->assertFalse(IcsHelper::hasAttendees($ics));
		$this->assertFalse(IcsHelper::parse($ics, $tz)['hasAttendees']);

		$invited = str_replace('END:VEVENT', "ORGANIZER:mailto:a@example.com\r\nATTENDEE;CN=Guest:mailto:guest@example.com\r\nEND:VEVENT", $ics);
		$this->assertTrue(IcsHelper::hasAttendees($invited));
		$this->assertTrue(IcsHelper::parse($invited, $tz)['hasAttendees']);
		$this->assertFalse(IcsHelper::hasAttendees('not an ics'));
	}

	public function testBrokenOrEventlessDataGivesNull(): void {
		$tz = new DateTimeZone('UTC');
		$this->assertNull(IcsHelper::parse('not an ics', $tz));
		$this->assertNull(IcsHelper::parse("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20261116T000000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n", $tz));
		$this->assertNull(IcsHelper::apply('not an ics', self::TIMED, ['title'], $tz, self::now()));
	}
}
