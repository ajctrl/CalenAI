<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Db\CreatedEvent;
use OCA\CalenAI\Db\CreatedEventMapper;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\CalendarService;
use OCA\CalenAI\Service\EventManager;
use OCA\CalenAI\Service\IcsHelper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;

/**
 * 履歴とカレンダーの実物の突き合わせ（移された予定、ごみ箱の予定、無くなった予定）と、移動の失敗。
 * カレンダーと履歴テーブルは、メモリ上の簡単な代わりを使う。
 */
class EventManagerTest extends TestCase {
	private const USER = 'alice';
	private const PERSONAL = 1;
	private const WORK = 2;

	/** @var array<string, array{etag: string, ics: string, objectId: int}> 「カレンダーID/URI」=> 予定 */
	private array $objects = [];
	/** @var array<string, array> ごみ箱にある予定（「カレンダーID/URI」は元の場所） */
	private array $trashed = [];
	/** @var array<int, CreatedEvent> 履歴テーブル */
	private array $rows = [];
	private bool $moveFails = false;
	private int $nextEtag = 1;

	private function tz(): DateTimeZone {
		return new DateTimeZone('Asia/Tokyo');
	}

	private function manager(?DateTimeImmutable $now = null): EventManager {
		$calendar = $this->createStub(CalendarService::class);
		$calendar->method('listWritableCalendars')->willReturn([
			['id' => self::PERSONAL, 'uri' => 'personal', 'name' => 'Personal', 'color' => null],
			['id' => self::WORK, 'uri' => 'work', 'name' => '仕事', 'color' => null],
		]);
		$calendar->method('getCalendarName')->willReturnCallback(fn (int $id): string => $id === self::WORK ? '仕事' : 'Personal');
		$calendar->method('getTrackedObject')->willReturnCallback(function (string $userId, int $id): ?array {
			foreach ([$this->objects, $this->trashed] as $trashed => $objects) {
				foreach ($objects as $key => $object) {
					if ($object['objectId'] === $id) {
						[$calendarId, $uri] = explode('/', $key, 2);
						return $object + ['calendarId' => (int)$calendarId, 'uri' => $uri, 'trashed' => (bool)$trashed];
					}
				}
			}
			return null;
		});
		$calendar->method('updateObject')->willReturnCallback(function (string $userId, int $calendarId, string $uri, string $ics): string {
			$key = $calendarId . '/' . $uri;
			$this->objects[$key] = ['etag' => 'etag-' . $this->nextEtag++, 'ics' => $ics] + $this->objects[$key];
			return $this->objects[$key]['etag'];
		});
		$calendar->method('moveObject')->willReturnCallback(function (string $userId, int $from, string $uri, int $to): string {
			if ($this->moveFails) {
				throw new ServiceException('別のカレンダーへ移動できませんでした。', 500);
			}
			$this->objects[$to . '/' . $uri] = $this->objects[$from . '/' . $uri];
			unset($this->objects[$from . '/' . $uri]);
			return $this->objects[$to . '/' . $uri]['etag'];
		});
		$calendar->method('deleteObject')->willReturnCallback(function (string $userId, int $calendarId, string $uri): bool {
			$existed = isset($this->objects[$calendarId . '/' . $uri]);
			unset($this->objects[$calendarId . '/' . $uri]);
			return $existed;
		});

		$mapper = $this->createStub(CreatedEventMapper::class);
		$mapper->method('transaction')->willReturnCallback(fn (callable $operation) => $operation());
		$mapper->method('findRecent')->willReturnCallback(function (string $userId, int $limit, int $offset = 0): array {
			$rows = array_values($this->rows);
			usort($rows, static fn (CreatedEvent $a, CreatedEvent $b): int => [$b->getUpdatedAt(), $b->getUpdatedAtUsec(), $b->getId()] <=> [$a->getUpdatedAt(), $a->getUpdatedAtUsec(), $a->getId()]);
			return array_slice($rows, $offset, $limit);
		});
		$mapper->method('findForUser')->willReturnCallback(function (int $id, string $userId): CreatedEvent {
			return $this->rows[$id] ?? throw new DoesNotExistException('not found');
		});
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('delete')->willReturnCallback(function (CreatedEvent $row): CreatedEvent {
			unset($this->rows[$row->getId()]);
			return $row;
		});

		$time = $this->createStub(ITimeFactory::class);
		$time->method('now')->willReturn($now ?? new DateTimeImmutable('2026-11-17T03:00:00Z'));

		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnArgument(3);

		return new EventManager($mapper, $calendar, $userConfig, $time);
	}

	/** 履歴の行と、その実物を作る */
	private function addEvent(int $id, string $title, int $calendarId = self::PERSONAL): CreatedEvent {
		$uid = 'uid-' . $id;
		$uri = $uid . '.ics';
		$event = ['title' => $title, 'start' => '2026-11-18T10:00', 'end' => '2026-11-18T11:00', 'allDay' => false, 'location' => '', 'description' => ''];
		$this->objects[$calendarId . '/' . $uri] = [
			'etag' => 'etag-' . $this->nextEtag++,
			'ics' => IcsHelper::build($uid, $event, $this->tz(), new DateTimeImmutable('2026-11-16T00:00:00Z')),
			'objectId' => $id,
			'uid' => $uid,
		];

		$row = new CreatedEvent();
		$row->setId($id);
		$row->setObjectId($id);
		$row->setUserId(self::USER);
		$row->setCalendarId($calendarId);
		$row->setObjectUri($uri);
		$row->setUid($uid);
		$row->setEtag($this->objects[$calendarId . '/' . $uri]['etag']);
		$row->setUpdatedAt(1000 + $id);
		return $this->rows[$id] = $row;
	}

	/** Calendar アプリなどで、別のカレンダーへ移す */
	private function moveOutside(CreatedEvent $row, int $to): void {
		$from = $row->getCalendarId() . '/' . $row->getObjectUri();
		$this->objects[$to . '/' . $row->getObjectUri()] = $this->objects[$from];
		unset($this->objects[$from]);
	}

	/** Calendar アプリなどで、ごみ箱へ移す（復元は逆の操作） */
	private function trashOutside(CreatedEvent $row): array {
		$key = $row->getCalendarId() . '/' . $row->getObjectUri();
		$object = $this->objects[$key];
		unset($this->objects[$key]);
		$this->trashed[$key] = $object;
		return [$key, $object];
	}

	private function titles(EventManager $manager, int $limit = EventManager::HISTORY_LIMIT): array {
		return array_column($manager->listRecent(self::USER, $this->tz(), $limit), 'title');
	}

	public function testLastEditedEventIsFirstEvenWithinTheSameSecond(): void {
		$this->addEvent(1, '古い予定');
		$row = $this->addEvent(2, '新しい予定');
		$row->markUpdated(new DateTimeImmutable('2026-11-17T03:00:00.100000Z'));
		$manager = $this->manager(new DateTimeImmutable('2026-11-17T03:00:00.900000Z'));
		$manager->update(self::USER, 1, ['title' => '最後に編集'], ['title'], $this->tz());
		$this->assertSame(['最後に編集', '新しい予定'], $this->titles($manager));
	}

	public function testEventMovedToAnotherOwnCalendarIsFollowed(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$row->setPrevIcs('old ics');
		$this->moveOutside($row, self::WORK);

		$events = $manager->listRecent(self::USER, $this->tz());
		$this->assertSame(['歯医者'], array_column($events, 'title'));
		$this->assertSame(self::WORK, $events[0]['calendarId']);
		$this->assertSame('仕事', $events[0]['calendarName']);
		// 外部での変更なので、古い「元に戻す」は使えない
		$this->assertFalse($events[0]['canUndo']);
		$this->assertSame(self::WORK, $this->rows[1]->getCalendarId());

		// 移動先の実物を修正できる
		$result = $manager->update(self::USER, 1, ['title' => '歯科検診'], ['title'], $this->tz());
		$this->assertSame('歯科検診', $result['event']['title']);
		$this->assertStringContainsString('SUMMARY:歯科検診', $this->objects[self::WORK . '/uid-1.ics']['ics']);
	}

	public function testReusedUriNeverTargetsTheReplacementEvent(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$this->moveOutside($row, self::WORK);
		$other = $this->addEvent(2, '別の予定');
		$key = self::PERSONAL . '/' . $row->getObjectUri();
		$this->objects[$key] = $this->objects[self::PERSONAL . '/' . $other->getObjectUri()];

		$displayed = array_column($manager->listRecent(self::USER, $this->tz()), null, 'id');
		$manager->delete(self::USER, 1, $this->tz(), $displayed[1]['revision']);
		$this->assertSame($other->getUid(), $this->objects[$key]['uid']);
		$this->assertArrayNotHasKey(self::WORK . '/' . $row->getObjectUri(), $this->objects);
	}

	public function testUpdateWithStaleCandidateVersionPreservesTheExternalEdit(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$candidate = $manager->listRecent(self::USER, $this->tz())[0];
		$key = self::PERSONAL . '/' . $row->getObjectUri();
		$this->objects[$key]['ics'] = str_replace('SUMMARY:歯医者', 'SUMMARY:外部で変更', $this->objects[$key]['ics']);
		$this->objects[$key]['etag'] = 'external';
		try {
			$manager->update(self::USER, 1, ['title' => '修正'], ['title'], $this->tz(), $candidate['etag']);
			$this->fail('expected conflict');
		} catch (ServiceException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('外部で変更', $e->getData()['event']['title']);
		}
		$this->assertStringContainsString('SUMMARY:外部で変更', $this->objects[$key]['ics']);
	}

	public function testDeleteRemovesTheEventFromTheCalendarItWasMovedTo(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$this->moveOutside($row, self::WORK);

		$displayed = array_column($manager->listRecent(self::USER, $this->tz()), null, 'id');
		$manager->delete(self::USER, 1, $this->tz(), $displayed[1]['revision']);
		$this->assertSame([], $this->objects);
		$this->assertSame([], $this->rows);
	}

	public function testEventInTheTrashbinIsHiddenButComesBackWhenRestored(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$this->addEvent(2, '打合せ');
		[$key, $object] = $this->trashOutside($row);

		$this->assertSame(['打合せ'], $this->titles($manager));
		$this->assertArrayHasKey(1, $this->rows, 'ごみ箱にある間は履歴を残す');
		try {
			$manager->update(self::USER, 1, ['title' => 'x'], ['title'], $this->tz());
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(404, $e->getStatus());
		}
		$this->assertArrayHasKey(1, $this->rows);

		// ごみ箱から復元
		unset($this->trashed[$key]);
		$this->objects[$key] = $object;
		$this->assertSame(['打合せ', '歯医者'], $this->titles($manager));
		$result = $manager->update(self::USER, 1, ['title' => '歯科検診'], ['title'], $this->tz());
		$this->assertSame('歯科検診', $result['event']['title']);
	}

	public function testEventThatIsGoneForGoodIsRemovedFromHistory(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$this->addEvent(2, '打合せ');
		unset($this->objects[$row->getCalendarId() . '/' . $row->getObjectUri()]);

		$this->assertSame(['打合せ'], $this->titles($manager));
		$this->assertSame([2], array_keys($this->rows));
	}

	public function testPagingSkipsHiddenAndRemovedRowsWithoutRepeatingOrLosingEvents(): void {
		$manager = $this->manager();
		foreach ([1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D', 5 => 'E', 6 => 'F'] as $id => $title) {
			$this->addEvent($id, $title);
		}
		// 新しい順は F E D C B A。F はごみ箱、E は無くなった
		$this->trashOutside($this->rows[6]);
		unset($this->objects[self::PERSONAL . '/uid-5.ics']);

		$this->assertSame(['D', 'C', 'B'], $this->titles($manager, 3));
		$this->assertSame([1, 2, 3, 4, 6], array_keys($this->rows));
	}

	public function testRetainedTrashDoesNotLimitVisibleHistory(): void {
		$manager = $this->manager();
		$this->addEvent(1, '見える予定');
		for ($id = 2; $id <= 62; $id++) {
			$this->trashOutside($this->addEvent($id, 'ごみ箱'));
		}
		$this->assertSame(['見える予定'], $this->titles($manager));
	}

	public function testSameUidCopyNeverBecomesTheTrackedObject(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '元の予定');
		[$key, $original] = $this->trashOutside($row);
		$copyKey = self::WORK . '/copy.ics';
		$this->objects[$copyKey] = ['objectId' => 99] + $original;
		$this->assertSame([], $this->titles($manager));
		$this->assertArrayHasKey(1, $this->rows);
		unset($this->trashed[$key]);
		$this->assertSame([], $this->titles($manager));
		$this->assertSame([], $this->rows);
		$this->assertArrayHasKey($copyKey, $this->objects);
	}

	public function testStaleDeleteAndUndoCannotReverseANewerMoveWithTheSameEtag(): void {
		$manager = $this->manager();
		$this->addEvent(1, '予定');
		$edited = $manager->update(self::USER, 1, ['title' => '変更'], ['title'], $this->tz())['event'];
		$moved = $manager->update(self::USER, 1, [], [], $this->tz(), null, self::WORK)['event'];
		$this->assertSame($edited['etag'], $moved['etag']);
		foreach (['undo', 'delete'] as $method) {
			try {
				$manager->$method(self::USER, 1, $this->tz(), $edited['revision']);
				$this->fail('expected a conflict');
			} catch (ServiceException $e) {
				$this->assertSame(409, $e->getStatus());
				$this->assertSame($moved, $e->getData()['event']);
			}
		}
		$this->assertArrayHasKey(self::WORK . '/uid-1.ics', $this->objects);
	}

	public function testMoveFailureAfterSavingContentReportsTheSavedEvent(): void {
		$manager = $this->manager();
		$this->addEvent(1, '歯医者');
		$this->moveFails = true;

		try {
			$manager->update(self::USER, 1, ['title' => '歯科検診'], ['title'], $this->tz(), null, self::WORK);
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(500, $e->getStatus());
			$this->assertStringContainsString('内容は保存しました', $e->getMessage());
			$data = $e->getData();
			$this->assertTrue($data['partial']);
			$this->assertSame('歯科検診', $data['event']['title']);
			$this->assertSame(self::PERSONAL, $data['event']['calendarId']);
			$this->assertSame($this->objects[self::PERSONAL . '/uid-1.ics']['etag'], $data['event']['etag']);
			// 保存した内容は元に戻せる
			$this->assertTrue($data['event']['canUndo']);
		}

		$this->moveFails = false;
		$undone = $manager->undo(self::USER, 1, $this->tz(), $this->rows[1]->getRevision());
		$this->assertSame('歯医者', $undone['event']['title']);
	}

	public function testEventWithAttendeesCannotBeChangedMovedUndoneOrDeleted(): void {
		$manager = $this->manager();
		$row = $this->addEvent(1, '歯医者');
		$manager->update(self::USER, 1, ['title' => '歯科検診'], ['title'], $this->tz());
		// Calendar アプリで出席者が足された（「元に戻す」が残っている状態をあえて作る）
		$key = self::PERSONAL . '/uid-1.ics';
		$this->objects[$key]['ics'] = str_replace('END:VEVENT', "ATTENDEE;CN=Guest:mailto:guest@example.com\r\nEND:VEVENT", $this->objects[$key]['ics']);
		$row->setEtag($this->objects[$key]['etag']);
		$before = $this->objects;

		$events = $manager->listRecent(self::USER, $this->tz());
		$this->assertTrue($events[0]['hasAttendees']);

		$attempts = [
			fn () => $manager->update(self::USER, 1, ['title' => 'x'], ['title'], $this->tz()),
			fn () => $manager->update(self::USER, 1, [], [], $this->tz(), null, self::WORK),
			fn () => $manager->undo(self::USER, 1, $this->tz(), $this->rows[1]->getRevision()),
			fn () => $manager->delete(self::USER, 1, $this->tz(), null),
		];
		foreach ($attempts as $attempt) {
			try {
				$attempt();
				$this->fail('expected exception');
			} catch (ServiceException $e) {
				$this->assertSame(422, $e->getStatus());
				$this->assertSame(EventManager::HAS_ATTENDEES_MESSAGE, $e->getMessage());
			}
		}
		$this->assertSame($before, $this->objects);
		$this->assertArrayHasKey(1, $this->rows);
	}

	public function testMoveOnlyFailureIsReportedAsIs(): void {
		$manager = $this->manager();
		$this->addEvent(1, '歯医者');
		$this->moveFails = true;

		try {
			$manager->update(self::USER, 1, [], [], $this->tz(), null, self::WORK);
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame([], $e->getData());
		}
	}

	public function testUndoOfContentAndMoveReportsWhatWasRestoredWhenTheMoveFails(): void {
		$manager = $this->manager();
		$this->addEvent(1, '歯医者');
		$manager->update(self::USER, 1, ['title' => '歯科検診'], ['title'], $this->tz(), null, self::WORK);
		$this->moveFails = true;

		try {
			$manager->undo(self::USER, 1, $this->tz(), $this->rows[1]->getRevision());
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$data = $e->getData();
			$this->assertTrue($data['partial']);
			$this->assertSame('歯医者', $data['event']['title']);
			$this->assertSame(self::WORK, $data['event']['calendarId']);
			// カレンダーを戻す分が残っている
			$this->assertTrue($data['event']['canUndo']);
		}

		$this->moveFails = false;
		$undone = $manager->undo(self::USER, 1, $this->tz(), $this->rows[1]->getRevision());
		$this->assertSame(self::PERSONAL, $undone['event']['calendarId']);
		$this->assertSame([['field' => 'calendar', 'from' => '仕事', 'to' => 'Personal']], $undone['changes']);
	}
}
