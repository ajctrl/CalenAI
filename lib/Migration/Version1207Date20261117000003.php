<?php

declare(strict_types=1);

namespace OCA\CalenAI\Migration;

use Closure;
use OCA\CalenAI\Db\CreatedEventMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1207Date20261117000003 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable(CreatedEventMapper::TABLE_NAME);
		if ($table->hasColumn('updated_at_usec')) {
			return null;
		}
		$table->addColumn('updated_at_usec', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		return $schema;
	}
}
