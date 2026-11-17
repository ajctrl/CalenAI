<?php

declare(strict_types=1);

namespace OCA\CalenAI\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CreatedEvent>
 */
class CreatedEventMapper extends QBMapper {
	use TTransactional;

	public const TABLE_NAME = 'calenai_events';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, CreatedEvent::class);
	}

	/** カレンダーの書き込みと履歴の保存に、同じ DB 接続のトランザクションを使う。 */
	public function transaction(callable $operation): mixed {
		return $this->atomic($operation, $this->db);
	}

	/**
	 * 自分の履歴だけを返す。他ユーザーの ID を指定しても見つからない扱いになる。
	 *
	 * @throws DoesNotExistException
	 */
	public function findForUser(int $id, string $userId): CreatedEvent {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			$qb->forUpdate();
		}
		return $this->findEntity($qb);
	}

	/**
	 * 最近さわった順（1件目が「直前の予定」）。
	 *
	 * @return list<CreatedEvent>
	 */
	public function findRecent(string $userId, int $limit, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$hidden = $this->db->getQueryBuilder();
		$hidden->select('o.id')->from('calendarobjects', 'o')
			->innerJoin('o', 'calendars', 'c', $hidden->expr()->eq('o.calendarid', 'c.id'))
			->where($hidden->expr()->eq('o.id', 'h.object_id'))
			->andWhere($hidden->expr()->orX($hidden->expr()->isNotNull('o.deleted_at'), $hidden->expr()->isNotNull('c.deleted_at')));
		$qb->select('h.*')
			->from($this->getTableName(), 'h')
			->where($qb->expr()->eq('h.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('h.object_id'))
			->andWhere($qb->expr()->notExists($hidden))
			->orderBy('h.updated_at', 'DESC')
			->addOrderBy('h.updated_at_usec', 'DESC')
			->addOrderBy('h.id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_SQLITE) {
			$qb->forUpdate();
		}
		return $this->findEntities($qb);
	}
}
