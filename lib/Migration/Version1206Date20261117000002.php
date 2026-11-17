<?php

declare(strict_types=1);

namespace OCA\CalenAI\Migration;

use Closure;
use OCA\CalenAI\Db\CreatedEventMapper;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1206Date20261117000002 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable(CreatedEventMapper::TABLE_NAME);
		if (!$table->hasColumn('object_id')) {
			$table->addColumn('object_id', Types::BIGINT, ['notnull' => false]);
		}
		if (!$table->hasColumn('revision')) {
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		}
		return $schema;
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$lastId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'calendar_id', 'object_uri', 'user_id', 'uid')->from(CreatedEventMapper::TABLE_NAME)
				->where($qb->expr()->isNull('object_id'))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('id')->setMaxResults(200);
			$result = $qb->executeQuery();
			$rows = $result->fetchAllAssociative();
			$result->closeCursor();
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$this->bindOriginal($row);
			}
		} while (count($rows) === 200);
	}

	private function bindOriginal(array $row): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('o.id', 'o.uid', 'o.uri', 'o.deleted_at', 'c.principaluri')->from('calendarobjects', 'o')
			->innerJoin('o', 'calendars', 'c', $qb->expr()->eq('o.calendarid', 'c.id'))
			->where($qb->expr()->eq('o.calendarid', $qb->createNamedParameter($row['calendar_id'], IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('o.uid', $qb->createNamedParameter($row['uid'])))
			->andWhere($qb->expr()->eq('o.calendartype', $qb->createNamedParameter(CalDavBackend::CALENDAR_TYPE_CALENDAR, IQueryBuilder::PARAM_INT)))
			->setMaxResults(2);
		$result = $qb->executeQuery();
		$matches = $result->fetchAllAssociative();
		$result->closeCursor();
		if (count($matches) !== 1) {
			return;
		}
		$object = $matches[0];
		$uri = $row['object_uri'];
		if ($object['deleted_at'] !== null) {
			$path = pathinfo($uri);
			$uri = $path['filename'] . '-deleted' . (isset($path['extension']) ? '.' . $path['extension'] : '');
		}
		if ($object['uid'] !== $row['uid'] || $object['uri'] !== $uri || $object['principaluri'] !== 'principals/users/' . $row['user_id']) {
			return;
		}
		$write = $this->db->getQueryBuilder();
		$write->update(CreatedEventMapper::TABLE_NAME)
			->set('object_id', $write->createNamedParameter($object['id'], IQueryBuilder::PARAM_INT))
			->where($write->expr()->eq('id', $write->createNamedParameter($row['id'], IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
