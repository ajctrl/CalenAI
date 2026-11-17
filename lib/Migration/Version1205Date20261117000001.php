<?php

declare(strict_types=1);

namespace OCA\CalenAI\Migration;

use Closure;
use OCA\CalenAI\Service\AiConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1205Date20261117000001 extends SimpleMigrationStep {
	public function __construct(private AiConfig $config) {
	}

	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->config->migrateLegacyCredential();
	}
}
