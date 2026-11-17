<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use DateTimeZone;
use OCA\CalenAI\AppInfo\Application;
use OCA\CalenAI\Db\CreatedEvent;
use OCA\CalenAI\Db\CreatedEventMapper;
use OCA\CalenAI\Exception\ServiceException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use Sabre\VObject\UUIDUtil;

/**
 * このアプリで作った予定（履歴）の作成・修正・取り消し・削除。
 *
 * 修正・削除できるのは履歴テーブルにある本人の予定だけ。
 * 読むたびにカレンダーの実物と突き合わせる:
 * - 外部で編集された予定は、履歴を最新の内容に合わせる。
 * - 本人の別のカレンダーへ移された予定は、移動先を追いかける。
 * - ごみ箱にある予定は、一覧に出さないが履歴は残す（復元されたらまた使える）。
 * - どこにも無くなった予定は、履歴から外す。
 */
class EventManager {
	public const HISTORY_LIMIT = 20;
	public const MAX_HINT_LENGTH = 200;
	private const CONFIG_DEFAULT_CALENDAR = 'default_calendar';
	private const CONFIG_AUTO_CALENDAR = 'auto_calendar';
	private const CONFIG_CALENDAR_HINTS = 'calendar_hints';
	/**
	 * このアプリはカレンダーを直接書き換えるので、出席者への通知（変更・キャンセルの招待メール）が送られない。
	 * 出席者のいる予定を変えると、出席者の手元に古い招待が残ってしまうため、修正・削除を断る。
	 */
	public const HAS_ATTENDEES_MESSAGE = 'この予定には出席者がいるため、ここからは変更・削除できません。出席者に通知が届くよう、Calendar アプリで変更してください。';

	public function __construct(
		private CreatedEventMapper $mapper,
		private CalendarService $calendarService,
		private IUserConfig $userConfig,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * 書き込めるカレンダーの一覧（振り分けの説明つき）と、既定の保存先、自動振り分けを使うかどうか。
	 *
	 * @return array{calendars: list<array{id: int, uri: string, name: string, color: ?string, hint: string}>, defaultCalendarId: ?int, autoCalendar: bool}
	 */
	public function getCalendars(string $userId): array {
		$calendars = $this->calendarService->listWritableCalendars($userId);
		$hints = $this->getHints($userId);
		foreach ($calendars as &$calendar) {
			$calendar['hint'] = $hints[$calendar['uri']] ?? '';
		}
		unset($calendar);
		return [
			'calendars' => $calendars,
			'defaultCalendarId' => $this->pickDefaultCalendar($userId, $calendars),
			'autoCalendar' => $this->isAutoCalendarEnabled($userId),
		];
	}

	/**
	 * AI に見せる振り分け先の一覧。自動振り分けを使わない設定のとき、
	 * またはカレンダーが1つしかないときは空（＝カレンダー名を AI に送らない）。
	 *
	 * @return list<array{id: int, name: string, hint: string, isDefault: bool}>
	 */
	public function getRoutingCalendars(string $userId): array {
		if (!$this->isAutoCalendarEnabled($userId)) {
			return [];
		}
		$data = $this->getCalendars($userId);
		if (count($data['calendars']) < 2) {
			return [];
		}
		$result = [];
		foreach ($data['calendars'] as $calendar) {
			$result[] = [
				'id' => $calendar['id'],
				'name' => $calendar['name'],
				'hint' => $calendar['hint'],
				'isDefault' => $calendar['id'] === $data['defaultCalendarId'],
			];
		}
		return $result;
	}

	public function isAutoCalendarEnabled(string $userId): bool {
		return $this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_AUTO_CALENDAR, '1') !== '0';
	}

	public function setAutoCalendar(string $userId, bool $enabled): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_AUTO_CALENDAR, $enabled ? '1' : '0');
	}

	/**
	 * カレンダーごとの「ここに入れるもの」の説明を保存する。
	 *
	 * @param array<int|string, mixed> $hintsById カレンダー ID => 説明。本人のカレンダー以外の ID は無視する。
	 */
	public function setHints(string $userId, array $hintsById): void {
		$hints = [];
		foreach ($this->calendarService->listWritableCalendars($userId) as $calendar) {
			$text = EventMerger::cleanText($hintsById[$calendar['id']] ?? $hintsById[(string)$calendar['id']] ?? '', self::MAX_HINT_LENGTH, true);
			if ($text !== '') {
				$hints[$calendar['uri']] = $text;
			}
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_CALENDAR_HINTS, json_encode($hints, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	/**
	 * @return array<string, string> カレンダーの URI => 説明
	 */
	private function getHints(string $userId): array {
		$decoded = json_decode($this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_CALENDAR_HINTS, '{}'), true);
		$hints = [];
		foreach (is_array($decoded) ? $decoded : [] as $uri => $text) {
			if (is_string($uri) && is_string($text)) {
				$hints[$uri] = $text;
			}
		}
		return $hints;
	}

	/**
	 * @throws ServiceException そのカレンダーに書き込めないとき
	 */
	public function setDefaultCalendar(string $userId, int $calendarId): void {
		foreach ($this->calendarService->listWritableCalendars($userId) as $calendar) {
			if ($calendar['id'] === $calendarId) {
				$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_DEFAULT_CALENDAR, $calendar['uri']);
				return;
			}
		}
		throw ServiceException::invalid('そのカレンダーは保存先にできません。');
	}

	/**
	 * 最近の予定（1件目が「直前の予定」）。
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listRecent(string $userId, DateTimeZone $tz, int $limit = self::HISTORY_LIMIT): array {
		return $this->mapper->transaction(function () use ($userId, $tz, $limit) {
			$events = [];
			$offset = 0;
			// 無くなった予定やごみ箱の予定は読み飛ばすので、必要件数がそろうまで続きを読む
			while (count($events) < $limit) {
				$rows = $this->mapper->findRecent($userId, $limit, $offset);
				foreach ($rows as $row) {
					$gone = false;
					$synced = $this->sync($row, $tz, $gone);
					if (!$gone) {
						// 履歴から外した行は次の読み込みで詰まるので、残した行だけ数える
						$offset++;
					}
					if ($synced === null) {
						continue;
					}
					$events[] = $synced['event'];
					if (count($events) >= $limit) {
						break;
					}
				}
				if (count($rows) < $limit) {
					break;
				}
			}
			return $events;
		});
	}

	/**
	 * 確認なしで予定を作る。
	 *
	 * @param array{title: string, start: string, end: string, allDay: bool, location: string, description: string} $event 検証済みの予定データ
	 * @param int|null $preferredCalendarId 振り分け先。本人が書き込めるカレンダーでなければ既定の保存先を使う。
	 * @return array<string, mixed>
	 * @throws ServiceException
	 */
	public function create(string $userId, array $event, DateTimeZone $tz, ?int $preferredCalendarId = null): array {
		$calendars = $this->calendarService->listWritableCalendars($userId);
		$calendarId = $preferredCalendarId !== null && in_array($preferredCalendarId, array_column($calendars, 'id'), true)
			? $preferredCalendarId
			: $this->pickDefaultCalendar($userId, $calendars);
		if ($calendarId === null) {
			throw ServiceException::invalid('書き込めるカレンダーがありません。カレンダーを作成してからお試しください。');
		}

		$now = $this->timeFactory->now();
		$uid = UUIDUtil::getUUID();
		$ics = IcsHelper::build($uid, $event, $tz, $now);
		return $this->mapper->transaction(function () use ($userId, $calendarId, $uid, $ics, $event, $tz, $now) {
			$created = $this->calendarService->createObject($userId, $calendarId, $uid . '.ics', $ics);

			// 保存時にサーバーが内容を整えることがあるので、保存後の実物から読み直す
			$saved = IcsHelper::parse($created['ics'], $tz) ?? ($event + ['uid' => $uid]);

			$row = new CreatedEvent();
			$row->setUserId($userId);
			$row->setCalendarId($created['calendarId']);
			$row->setObjectUri($created['uri']);
			$row->setObjectId($created['objectId']);
			$row->setUid($uid);
			$row->setEtag($created['etag']);
			$row->setPrevIcs(null);
			$row->setPrevCalendarId(null);
			$row->setCreatedAt($now->getTimestamp());
			$row->markUpdated($now);
			$row = $this->mapper->insert($row);

			return $this->toArray($row, $saved, $tz);
		});
	}

	/**
	 * 変更された項目だけをマージして保存する。更新前の内容とカレンダーは「元に戻す」用に残す。
	 *
	 * 移動を伴うときは、書き換える前に移動できることを確かめる。内容を保存した後に移動が
	 * 失敗した場合も、保存済みの内容は履歴に記録してから例外を投げる（元に戻せるように）。
	 *
	 * @param array<string, mixed> $patch
	 * @param list<string> $changedFields
	 * @param string|null $expectedEtag 画面が見ていた版。違っていたら 409 を返す。
	 * @param int|null $moveToCalendarId 別のカレンダーへ移すときの移動先
	 * @return array{event: array<string, mixed>, changes: list<array{field: string, from: mixed, to: mixed}>}
	 * @throws ServiceException
	 */
	public function update(string $userId, int $id, array $patch, array $changedFields, DateTimeZone $tz, ?string $expectedEtag = null, ?int $moveToCalendarId = null, ?int $revision = null): array {
		return $this->mutate($userId, $id, $tz, function (CreatedEvent $row, array $synced) use ($userId, $patch, $changedFields, $tz, $moveToCalendarId) {
			return $this->applyUpdate($userId, $row, $synced, $patch, $changedFields, $tz, $moveToCalendarId);
		}, $revision, expectedEtag: $expectedEtag);
	}

	private function applyUpdate(string $userId, CreatedEvent $row, array $synced, array $patch, array $changedFields, DateTimeZone $tz, ?int $moveToCalendarId): array {
		$current = self::pickEventFields($synced['event']);
		$current['durationSeconds'] = $synced['durationSeconds'];
		$merged = EventMerger::merge($current, $patch, $changedFields, $tz);
		$changes = $merged['changes'];
		$move = $moveToCalendarId !== null && $moveToCalendarId !== $row->getCalendarId();
		if ($changes === [] && !$move) {
			return ['event' => $synced['event'], 'changes' => []];
		}
		if ($move) {
			$this->calendarService->assertCanMove($userId, $row->getObjectUri(), $moveToCalendarId, $row->getUid());
		}

		$now = $this->timeFactory->now();
		$row->setRevision($row->getRevision() + 1);
		$row->markUpdated($now);
		// 「元に戻す」は直前の修正1回分だけ。この修正で変えなかったものは戻す対象にしない。
		$row->setPrevIcs(null);
		$row->setPrevCalendarId(null);

		if ($changes !== []) {
			$effective = array_column($changes, 'field');
			$newIcs = IcsHelper::apply($synced['ics'], $merged['event'], $effective, $tz, $now);
			if ($newIcs === null) {
				throw ServiceException::notFound();
			}
			$row->setEtag($this->calendarService->updateObject($userId, $row->getCalendarId(), $row->getObjectUri(), $newIcs, $row->getUid(), $synced['etag']));
			$row->setPrevIcs($synced['ics']);
		}

		if ($move) {
			$fromId = $row->getCalendarId();
			$changes[] = $this->moveRow(
				$userId,
				$row,
				$moveToCalendarId,
				$changes !== [] ? $merged['event'] : null,
				'内容は保存しましたが、別のカレンダーへは移動できませんでした。',
				$tz,
			);
			$row->setPrevCalendarId($fromId);
		}

		$this->mapper->update($row);

		return [
			'event' => $this->toArray($row, $merged['event'], $tz),
			'changes' => $changes,
		];
	}

	/**
	 * 予定を別のカレンダーへ移し、履歴の行を移動先に合わせる（行の保存は呼び出し側）。
	 *
	 * 内容を保存した後で移動に失敗したときは、保存済みの状態を履歴に記録し、
	 * 画面が最新の内容に追いつけるよう、その予定を添えた例外を投げる。
	 *
	 * @param array<string, mixed>|null $savedEvent この操作ですでに保存した内容。保存していなければ null
	 * @return array{field: string, from: string, to: string} 差分の1行
	 * @throws ServiceException
	 */
	private function moveRow(string $userId, CreatedEvent $row, int $targetCalendarId, ?array $savedEvent, string $partialMessage, DateTimeZone $tz): array {
		$fromId = $row->getCalendarId();
		try {
			$row->setEtag($this->calendarService->moveObject($userId, $fromId, $row->getObjectUri(), $targetCalendarId, $row->getUid(), $row->getEtag()));
		} catch (ServiceException $e) {
			if ($savedEvent === null) {
				throw $e;
			}
			$this->mapper->update($row);
			throw new ServiceException($partialMessage, $e->getStatus(), ['event' => $this->toArray($row, $savedEvent, $tz), 'partial' => true], $e);
		}
		$row->setCalendarId($targetCalendarId);
		return [
			'field' => 'calendar',
			'from' => $this->calendarService->getCalendarName($fromId) ?? '',
			'to' => $this->calendarService->getCalendarName($targetCalendarId) ?? '',
		];
	}

	/**
	 * 直前の修正を取り消す（1段階だけ）。内容と、移したカレンダーの両方を戻す。
	 *
	 * @return array{event: array<string, mixed>, changes: list<array{field: string, from: mixed, to: mixed}>}
	 * @throws ServiceException
	 */
	public function undo(string $userId, int $id, DateTimeZone $tz, ?int $revision): array {
		return $this->mutate($userId, $id, $tz, fn (CreatedEvent $row, array $synced) => $this->restore($userId, $row, $synced, $tz), $revision, true);
	}

	private function restore(string $userId, CreatedEvent $row, array $synced, DateTimeZone $tz): array {
		$previousIcs = $row->getPrevIcs();
		$previousIcs = $previousIcs === '' ? null : $previousIcs;
		$previousCalendarId = $row->getPrevCalendarId();
		if ($previousCalendarId === $row->getCalendarId()) {
			$previousCalendarId = null;
		}
		if ($previousIcs === null && $previousCalendarId === null) {
			throw ServiceException::conflict(
				'元に戻せる変更がありません（別の場所で変更されたか、すでに元に戻しています）。',
				['event' => $synced['event']],
			);
		}

		// 書き換える前に、戻せることを確かめる
		$now = $this->timeFactory->now();
		$restoredIcs = null;
		$restored = $synced['event'];
		if ($previousIcs !== null) {
			$restoredIcs = IcsHelper::restore($previousIcs, $synced['ics'], $now);
			$parsed = $restoredIcs === null ? null : IcsHelper::parse($restoredIcs, $tz);
			if ($restoredIcs === null || $parsed === null) {
				throw new ServiceException('元の内容を読み取れなかったため、元に戻せませんでした。', 500);
			}
			$restored = $parsed;
		}
		if ($previousCalendarId !== null) {
			$this->calendarService->assertCanMove($userId, $row->getObjectUri(), $previousCalendarId, $row->getUid());
		}

		$row->setRevision($row->getRevision() + 1);
		$row->markUpdated($now);
		$changes = EventMerger::diff(self::pickEventFields($synced['event']), self::pickEventFields($restored));

		if ($restoredIcs !== null) {
			$row->setEtag($this->calendarService->updateObject($userId, $row->getCalendarId(), $row->getObjectUri(), $restoredIcs, $row->getUid(), $synced['etag']));
			$row->setPrevIcs(null);
		}

		if ($previousCalendarId !== null) {
			// 失敗しても、内容は戻せている。カレンダーの移動だけが「元に戻す」に残る
			$changes[] = $this->moveRow(
				$userId,
				$row,
				$previousCalendarId,
				$restoredIcs !== null ? $restored : null,
				'内容は元に戻しましたが、カレンダーは元に戻せませんでした。',
				$tz,
			);
		}
		$row->setPrevIcs(null);
		$row->setPrevCalendarId(null);
		$this->mapper->update($row);

		return [
			'event' => $this->toArray($row, $restored, $tz),
			'changes' => $changes,
		];
	}

	/**
	 * 予定を削除する。画面で確認した後にだけ呼ぶこと。
	 *
	 * @throws ServiceException
	 */
	public function delete(string $userId, int $id, DateTimeZone $tz, ?int $revision): void {
		$this->mutate($userId, $id, $tz, function (CreatedEvent $row, array $synced) use ($userId) {
			$this->calendarService->deleteObject($userId, $row->getCalendarId(), $row->getObjectUri(), $row->getUid(), $synced['etag']);
			$this->mapper->delete($row);
		}, $revision, true);
	}

	private function mutate(string $userId, int $id, DateTimeZone $tz, callable $operation, ?int $revision = null, bool $requireRevision = false, ?string $expectedEtag = null): mixed {
		$result = $this->mapper->transaction(function () use ($userId, $id, $tz, $operation, $revision, $requireRevision, $expectedEtag) {
			$row = $this->findRow($userId, $id);
			$synced = $this->sync($row, $tz, forUpdate: true);
			if ($synced === null) {
				return ServiceException::notFound();
			}
			self::assertNoAttendees($synced['event']);
			if ((($requireRevision || $revision !== null) && $revision !== $row->getRevision())
				|| ($expectedEtag !== null && $expectedEtag !== '' && $expectedEtag !== $synced['etag'])) {
				return ServiceException::conflict('この予定は変更されています。最新の内容を確認してから、もう一度操作してください。', ['event' => $synced['event']]);
			}
			try {
				return $operation($row, $synced);
			} catch (ServiceException $e) {
				if (($e->getData()['partial'] ?? false) === true) {
					// 内容とその取り消し履歴を確定し、移動の失敗を呼び出し元に返す。
					return $e;
				}
				throw $e;
			}
		});
		if ($result instanceof ServiceException) {
			throw $result;
		}
		return $result;
	}

	/**
	 * @param array<string, mixed> $event
	 * @throws ServiceException 出席者のいる予定
	 */
	public static function assertNoAttendees(array $event): void {
		if (!empty($event['hasAttendees'])) {
			throw ServiceException::invalid(self::HAS_ATTENDEES_MESSAGE);
		}
	}

	/**
	 * @throws ServiceException 自分の履歴に無い ID（他ユーザーの ID を含む）
	 */
	private function findRow(string $userId, int $id): CreatedEvent {
		try {
			return $this->mapper->findForUser($id, $userId);
		} catch (DoesNotExistException) {
			throw ServiceException::notFound();
		}
	}

	/**
	 * 履歴の行をカレンダーの実物と突き合わせる。
	 * 実物が今は使えない（ごみ箱にある、無くなった、予定として読めない）なら null を返す。
	 *
	 * @param bool $gone 履歴から外したら true
	 * @return array{event: array<string, mixed>, ics: string, etag: string, durationSeconds: ?int}|null
	 */
	private function sync(CreatedEvent $row, DateTimeZone $tz, bool &$gone = false, bool $forUpdate = false): ?array {
		$objectId = $row->getObjectId();
		if ($objectId === null) {
			return null;
		}
		$object = $this->calendarService->getTrackedObject($row->getUserId(), $objectId, $forUpdate);
		if ($object === null || $object['uid'] !== $row->getUid()) {
			$this->mapper->delete($row);
			$gone = true;
			return null;
		}
		if ($object['trashed'] || ($object['calendarId'] !== $row->getCalendarId()
			&& !in_array($object['calendarId'], array_column($this->calendarService->listWritableCalendars($row->getUserId()), 'id'), true))) {
			return null;
		}
		$parsed = IcsHelper::parse($object['ics'], $tz, $durationSeconds);
		if ($parsed === null) {
			return null;
		}

		if ($object['etag'] !== $row->getEtag() || $object['calendarId'] !== $row->getCalendarId() || $object['uri'] !== $row->getObjectUri()) {
			$row->setCalendarId($object['calendarId']);
			$row->setObjectUri($object['uri']);
			$row->setRevision($row->getRevision() + 1);
			// 外部で編集された: 履歴を合わせ、古い「元に戻す」は無効にする
			$row->setEtag($object['etag']);
			$row->setPrevIcs(null);
			$row->setPrevCalendarId(null);
			$this->mapper->update($row);
		}

		return [
			'event' => $this->toArray($row, $parsed, $tz),
			'ics' => $object['ics'],
			'durationSeconds' => $durationSeconds,
			'etag' => $object['etag'],
		];
	}

	/**
	 * @param array<string, mixed> $event
	 * @return array<string, mixed>
	 */
	private function toArray(CreatedEvent $row, array $event, DateTimeZone $tz): array {
		return [
			'id' => $row->getId(),
			'displayTimezone' => $tz->getName(),
			'title' => (string)$event['title'],
			'start' => (string)$event['start'],
			'end' => (string)$event['end'],
			'allDay' => (bool)$event['allDay'],
			'location' => (string)($event['location'] ?? ''),
			'description' => (string)($event['description'] ?? ''),
			'calendarId' => $row->getCalendarId(),
			'calendarName' => $this->calendarService->getCalendarName($row->getCalendarId()) ?? '',
			'etag' => $row->getEtag(),
			'revision' => $row->getRevision(),
			'canUndo' => ($row->getPrevIcs() !== null && $row->getPrevIcs() !== '') || $row->getPrevCalendarId() !== null,
			// true のとき、このアプリからは修正・削除できない（画面はボタンの代わりに案内を出す）
			'hasAttendees' => !empty($event['hasAttendees']),
			'updatedAt' => $row->getUpdatedAt(),
		];
	}

	/**
	 * @param array<string, mixed> $event
	 * @return array{title: string, start: string, end: string, allDay: bool, location: string, description: string}
	 */
	private static function pickEventFields(array $event): array {
		return [
			'title' => (string)$event['title'],
			'start' => (string)$event['start'],
			'end' => (string)$event['end'],
			'allDay' => (bool)$event['allDay'],
			'location' => (string)($event['location'] ?? ''),
			'description' => (string)($event['description'] ?? ''),
		];
	}

	/**
	 * @param list<array{id: int, uri: string, name: string, color: ?string}> $calendars
	 */
	private function pickDefaultCalendar(string $userId, array $calendars): ?int {
		if ($calendars === []) {
			return null;
		}
		$preferred = $this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_DEFAULT_CALENDAR, '');
		foreach ([$preferred, 'personal'] as $uri) {
			if ($uri === '') {
				continue;
			}
			foreach ($calendars as $calendar) {
				if ($calendar['uri'] === $uri) {
					return $calendar['id'];
				}
			}
		}
		return $calendars[0]['id'];
	}
}
