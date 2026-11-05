<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\Music\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Preserve the explicit artist sort names extracted from music file tags. */
class Version030303Date20261105010000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('music_artists');
		if (!$table->hasColumn('sort_name')) {
			$table->addColumn('sort_name', Types::STRING, ['notnull' => false, 'length' => 256]);
		}
		return $schema;
	}
}
