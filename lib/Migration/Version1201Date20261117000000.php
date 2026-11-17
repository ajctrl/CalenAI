<?php

declare(strict_types=1);

namespace OCA\CalenAI\Migration;

use Closure;
use OCA\CalenAI\Db\CreatedEventMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * - prev_calendar_id: 直前の修正でカレンダーを移したときの移動元。「元に戻す」で使う。
 * - title / event_start / event_end: 毎回カレンダーの実物から読むので不要になった。
 */
class Version1201Date20261117000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(CreatedEventMapper::TABLE_NAME)) {
			return null;
		}
		$table = $schema->getTable(CreatedEventMapper::TABLE_NAME);
		$changed = false;

		if (!$table->hasColumn('prev_calendar_id')) {
			$table->addColumn('prev_calendar_id', Types::BIGINT, [
				'notnull' => false,
			]);
			$changed = true;
		}
		foreach (['title', 'event_start', 'event_end'] as $column) {
			if ($table->hasColumn($column)) {
				$table->dropColumn($column);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
