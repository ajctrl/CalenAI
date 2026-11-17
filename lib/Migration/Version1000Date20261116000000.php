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
 * 履歴テーブル: このアプリで作った予定だけを記録する。
 * 修正・削除の対象はこのテーブルにある予定に限られる。
 */
class Version1000Date20261116000000 extends SimpleMigrationStep {
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(CreatedEventMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(CreatedEventMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('calendar_id', Types::BIGINT, [
			'notnull' => true,
		]);
		$table->addColumn('object_uri', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('title', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		// 現地時刻の文字列（時刻あり: YYYY-MM-DDTHH:MM / 終日: YYYY-MM-DD）
		$table->addColumn('event_start', Types::STRING, [
			'notnull' => true,
			'length' => 32,
			'default' => '',
		]);
		$table->addColumn('event_end', Types::STRING, [
			'notnull' => true,
			'length' => 32,
			'default' => '',
		]);
		// このアプリが最後に書き込んだときの ETag。外部での編集を検出する。
		$table->addColumn('etag', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		// 直前の修正より前の ICS。「元に戻す」で使う（1段階だけ）。
		$table->addColumn('prev_ics', Types::TEXT, [
			'notnull' => false,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('updated_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id', 'updated_at'], 'calenai_ev_user_upd');

		return $schema;
	}
}
