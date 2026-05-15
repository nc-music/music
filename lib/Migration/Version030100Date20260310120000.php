<?php

declare(strict_types=1);

namespace OCA\Music\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

/**
 * Migrate the DB schema to Music v3.1.0 level from the v3.0.0 level
 */
class Version030100Date20260310120000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$tracks = $schema->getTable('music_tracks');
		if (!$tracks->hasColumn('bpm')) {
			$tracks->addColumn('bpm', 'integer', ['notnull' => false, 'unsigned' => true]);
		}
		if (!$tracks->hasColumn('composer_id')) {
			$tracks->addColumn('composer_id', 'integer', ['notnull' => false, 'unsigned' => true]);
		}
		if (!$tracks->hasIndex('music_tracks_composer_id_idx')) {
			$tracks->addIndex(['composer_id'], 'music_tracks_composer_id_idx');
		}

		return $schema;
	}
}
