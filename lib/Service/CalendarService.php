<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use OCA\CalenAI\Exception\ServiceException;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\AppFramework\Db\TTransactional;
use OCP\Calendar\Exceptions\CalendarException;
use OCP\Calendar\ICalendarIsShared;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Nextcloud のカレンダーへの読み書き。
 *
 * - 作成: 公開 API（OCP\Calendar）の createFromString を使う。
 * - 更新・削除: Nextcloud 35 の公開 API には無いので、dav アプリの CalDavBackend を直接使う。
 *   CalDavBackend は権限を確認しないため、必ず先に「カレンダーの所有者がそのユーザーか」を確かめる。
 *
 * 保存先にできるのは、本人が所有する書き込み可能なカレンダーだけ（共有されたカレンダーは対象外）。
 */
class CalendarService {
	use TTransactional;

	private const COMPONENT_SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	/** @var array<int, array<string, mixed>|null> 1リクエスト内のカレンダー行のキャッシュ */
	private array $calendarRows = [];
	/** @var array<string, list<array{calendar: ICreateFromString, info: array{id: int, uri: string, name: string, color: ?string}}>> */
	private array $writable = [];

	public function __construct(
		private IManager $calendarManager,
		private CalDavBackend $backend,
		private IDBConnection $db,
	) {
	}

	/**
	 * 本人が所有していて、予定を書き込めるカレンダーの一覧。
	 *
	 * @return list<array{id: int, uri: string, name: string, color: ?string}>
	 */
	public function listWritableCalendars(string $userId): array {
		$result = [];
		foreach ($this->findWritable($userId) as $entry) {
			$result[] = $entry['info'];
		}
		return $result;
	}

	/**
	 * 予定を作る。
	 *
	 * @return array{calendarId: int, uri: string, etag: string, ics: string, objectId: int, uid: string, trashed: bool}
	 * @throws ServiceException
	 */
	public function createObject(string $userId, int $calendarId, string $objectUri, string $ics): array {
		$calendar = null;
		foreach ($this->findWritable($userId) as $entry) {
			if ($entry['info']['id'] === $calendarId) {
				$calendar = $entry['calendar'];
				break;
			}
		}
		if ($calendar === null) {
			throw ServiceException::invalid('保存先のカレンダーに書き込めません。保存先を選び直してください。');
		}

		try {
			$calendar->createFromString($objectUri, $ics);
		} catch (CalendarException $e) {
			throw new ServiceException('カレンダーに保存できませんでした。', 500, [], $e);
		}

		$object = $this->getObject($userId, $calendarId, $objectUri);
		if ($object === null) {
			throw new ServiceException('カレンダーに保存できませんでした。', 500);
		}
		return $object;
	}

	/**
	 * 予定を読む。無い・ごみ箱にある・本人のカレンダーでない場合は null。
	 *
	 * @return array{etag: string, ics: string, objectId: int, uid: string}|null
	 */
	public function getObject(string $userId, int $calendarId, string $objectUri, bool $forUpdate = false): ?array {
		$qb = $this->ownedObjects($userId);
		$qb->andWhere($qb->expr()->eq('c.id', $qb->createNamedParameter($calendarId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('o.uri', $qb->createNamedParameter($objectUri)))
			->andWhere($qb->expr()->isNull('o.deleted_at'))
			->andWhere($qb->expr()->isNull('c.deleted_at'));
		return $this->readObject($qb, $userId, $forUpdate);
	}

	/** UID が同じ複製が存在しても、履歴に記録した DAV オブジェクトだけを追う。 */
	public function getTrackedObject(string $userId, int $objectId, bool $forUpdate): ?array {
		$qb = $this->ownedObjects($userId);
		$qb->andWhere($qb->expr()->eq('o.id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)));
		return $this->readObject($qb, $userId, $forUpdate);
	}

	private function ownedObjects(string $userId): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		return $qb->selectAlias('c.deleted_at', 'calendar_deleted')
			->addSelect('o.id', 'o.uid', 'o.etag', 'o.calendardata', 'o.calendarid', 'o.uri', 'o.deleted_at', 'c.principaluri')
			->from('calendarobjects', 'o')
			->innerJoin('o', 'calendars', 'c', $qb->expr()->eq('o.calendarid', 'c.id'))
			->where($qb->expr()->eq('c.principaluri', $qb->createNamedParameter(self::principal($userId))))
			->andWhere($qb->expr()->eq('o.calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR, IQueryBuilder::PARAM_INT)));
	}

	private function readObject(IQueryBuilder $qb, string $userId, bool $forUpdate): ?array {
		// SQLite は行ロックを持たず、トランザクションの読み取り後に他の書き込みが先行すると書き込みに失敗する。
		if ($forUpdate && $this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			$qb->forUpdate();
		}
		$result = $qb->executeQuery();
		$object = $result->fetchAssociative();
		$result->closeCursor();
		if ($object === false || $object['principaluri'] !== self::principal($userId)) {
			return null;
		}
		$ics = $object['calendardata'] ?? '';
		if (is_resource($ics)) {
			$ics = (string)stream_get_contents($ics);
		}
		return [
			'etag' => trim((string)($object['etag'] ?? ''), '"'),
			'ics' => (string)$ics,
			'objectId' => (int)$object['id'],
			'calendarId' => (int)$object['calendarid'],
			'uri' => (string)$object['uri'],
			'trashed' => $object['deleted_at'] !== null || $object['calendar_deleted'] !== null,
			'uid' => (string)$object['uid'],
		];
	}

	/**
	 * 移動できるかを、何も書き換えずに確かめる。内容の更新と移動を続けて行う前に呼ぶ。
	 *
	 * @throws ServiceException 移動先に書き込めない、移動先に同じ予定がすでにある
	 */
	public function assertCanMove(string $userId, string $objectUri, int $targetCalendarId, string $uid): void {
		// 移動先も、本人が所有する書き込み可能なカレンダーに限る
		$writable = array_column($this->listWritableCalendars($userId), 'id');
		if (!in_array($targetCalendarId, $writable, true)) {
			throw ServiceException::invalid('移動先のカレンダーに書き込めません。カレンダーを選び直してください。');
		}
		if ($this->backend->getCalendarObject($targetCalendarId, $objectUri) !== null
			|| $this->backend->findCalendarObjectByUid($targetCalendarId, $uid, deleted: null) !== null) {
			throw ServiceException::conflict('移動先のカレンダーに同じ予定がすでにあるため、移動できませんでした。');
		}
	}

	/**
	 * 予定を本人の別のカレンダーへ移す。予定そのもの（UID・内容）は変わらない。
	 *
	 * @return string 移動後の ETag
	 * @throws ServiceException 予定が無い、移動先に書き込めない、移動先に同じ予定がすでにある
	 */
	public function moveObject(string $userId, int $calendarId, string $objectUri, int $targetCalendarId, string $uid, string $etag): string {
		return $this->atomic(function () use ($userId, $calendarId, $objectUri, $targetCalendarId, $uid, $etag) {
			$object = $this->lockVersion($userId, $calendarId, $objectUri, $uid, $etag);
			if ($targetCalendarId === $calendarId) {
				return $etag;
			}
			$this->assertCanMove($userId, $objectUri, $targetCalendarId, $uid);
			$principal = self::principal($userId);
			try {
				$moved = $this->backend->moveCalendarObject($principal, $object['objectId'], $principal, $targetCalendarId, $objectUri);
			} catch (\Throwable $e) {
				throw new ServiceException('別のカレンダーへ移動できませんでした。', 500, [], $e);
			}
			$movedObject = $moved ? $this->getObject($userId, $targetCalendarId, $objectUri) : null;
			if ($movedObject === null) {
				throw ServiceException::notFound();
			}
			return $movedObject['etag'];
		}, $this->db);
	}

	/** トランザクション内で、読み取った予定と版の一致を確認して書き込みまでロックする。 */
	private function lockVersion(string $userId, int $calendarId, string $objectUri, string $uid, string $etag): array {
		$object = $this->getObject($userId, $calendarId, $objectUri, true);
		if ($object === null || $object['uid'] !== $uid) {
			throw ServiceException::notFound();
		}
		if ($object['etag'] !== $etag) {
			throw ServiceException::conflict('この予定は別の場所で変更されています。最新の内容を確認してください。');
		}
		return $object;
	}

	/**
	 * 予定を書き換える。
	 *
	 * @return string 新しい ETag
	 * @throws ServiceException 予定が無い、または本人のカレンダーでない
	 */
	public function updateObject(string $userId, int $calendarId, string $objectUri, string $ics, string $uid, string $etag): string {
		return $this->atomic(function () use ($userId, $calendarId, $objectUri, $ics, $uid, $etag) {
			$this->lockVersion($userId, $calendarId, $objectUri, $uid, $etag);
			return trim($this->backend->updateCalendarObject($calendarId, $objectUri, $ics), '"');
		}, $this->db);
	}

	/**
	 * 予定を削除する（Nextcloud のごみ箱が有効なら、ごみ箱へ移動される）。
	 *
	 * @throws ServiceException 予定が無い、または別の版に変わった
	 */
	public function deleteObject(string $userId, int $calendarId, string $objectUri, string $uid, string $etag): void {
		$this->atomic(function () use ($userId, $calendarId, $objectUri, $uid, $etag) {
			$this->lockVersion($userId, $calendarId, $objectUri, $uid, $etag);
			$this->backend->deleteCalendarObject($calendarId, $objectUri);
		}, $this->db);
	}

	public function getCalendarName(int $calendarId): ?string {
		$row = $this->calendarRow($calendarId);
		if (!is_array($row)) {
			return null;
		}
		$name = $row['{DAV:}displayname'] ?? null;
		return is_string($name) && $name !== '' ? $name : (string)($row['uri'] ?? '');
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function calendarRow(int $calendarId): ?array {
		if (!array_key_exists($calendarId, $this->calendarRows)) {
			$row = $this->backend->getCalendarById($calendarId);
			$this->calendarRows[$calendarId] = is_array($row) ? $row : null;
		}
		return $this->calendarRows[$calendarId];
	}

	/**
	 * @return list<array{calendar: ICreateFromString, info: array{id: int, uri: string, name: string, color: ?string}}>
	 */
	private function findWritable(string $userId): array {
		if (isset($this->writable[$userId])) {
			return $this->writable[$userId];
		}
		$result = [];
		foreach ($this->calendarManager->getCalendarsForPrincipal(self::principal($userId)) as $calendar) {
			if (!$calendar instanceof ICreateFromString || $calendar->isDeleted()) {
				continue;
			}
			if ($calendar instanceof ICalendarIsShared && $calendar->isShared()) {
				continue;
			}
			if ($calendar instanceof ICalendarIsWritable && !$calendar->isWritable()) {
				continue;
			}
			if (($calendar->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
				continue;
			}

			// dav のカレンダーで、本人の所有であり、予定（VEVENT）を入れられることを確かめる
			$key = $calendar->getKey();
			if (!ctype_digit($key)) {
				continue;
			}
			$row = $this->calendarRow((int)$key);
			if (!is_array($row)
				|| ($row['principaluri'] ?? null) !== self::principal($userId)
				|| ($row['uri'] ?? null) !== $calendar->getUri()) {
				continue;
			}
			$components = $row[self::COMPONENT_SET] ?? null;
			if (is_object($components) && method_exists($components, 'getValue')
				&& !in_array('VEVENT', (array)$components->getValue(), true)) {
				continue;
			}

			$name = $calendar->getDisplayName();
			$result[] = [
				'calendar' => $calendar,
				'info' => [
					'id' => (int)$key,
					'uri' => $calendar->getUri(),
					'name' => $name !== null && $name !== '' ? $name : $calendar->getUri(),
					'color' => $calendar->getDisplayColor(),
				],
			];
		}
		return $this->writable[$userId] = $result;
	}

	private static function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}
}
