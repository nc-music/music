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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Artist metadata cache shared by all music users, keyed by canonical MBID. */
class Version030302Date20261104000100 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('music_artist_info')) {
			$table = $schema->createTable('music_artist_info');
			$table->addColumn('mbid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('biography', Types::TEXT, ['notnull' => false]);
			$table->addColumn('image_url', Types::STRING, ['notnull' => false, 'length' => 1024]);
			$table->addColumn('audiodb_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			$table->addColumn('fetched_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['mbid']);
		} else {
			$table = $schema->getTable('music_artist_info');
			if (!$table->hasColumn('audiodb_id')) {
				$table->addColumn('audiodb_id', Types::STRING, ['notnull' => false, 'length' => 32]);
			}
		}

		if (!$schema->hasTable('music_artist_info_rate')) {
			$table = $schema->createTable('music_artist_info_rate');
			$table->addColumn('id', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('next_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->setPrimaryKey(['id']);
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) : void {
		// MusicBrainz=1, TheAudioDB=2; atomic reservation is shared by all PHP workers.
		foreach ([1, 2] as $id) {
			$result = $this->db->executeQuery('SELECT `id` FROM `*PREFIX*music_artist_info_rate` WHERE `id` = ?', [$id]);
			$exists = (bool)$result->fetchAll();
			$result->closeCursor();
			if (!$exists) {
				try {
					$this->db->executeStatement(
						'INSERT INTO `*PREFIX*music_artist_info_rate` (`id`, `next_at`) VALUES (?, 0)',
						[$id]
					);
				} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
					// A concurrent worker inserted this singleton.
				}
			}
		}
	}
}
