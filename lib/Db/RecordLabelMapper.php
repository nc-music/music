<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Db;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Type hint a base class method to help Scrutinizer
 * @method RecordLabel findEntity(string $sql, array $params=[], ?int $limit=null, ?int $offset=null)
 * @extends BaseMapper<RecordLabel>
 */
class RecordLabelMapper extends BaseMapper {
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct($db, $config, 'music_record_labels', RecordLabel::class, 'name', ['hash', 'user_id']);
	}

	/**
	 * Override the base implementation to include data from multiple tables
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::selectEntities()
	 */
	protected function selectEntities(string $condition, ?string $extension = null) : string {
		return "SELECT
					`*PREFIX*music_record_labels`.*,
					{$this->sqlCoalesce('`artistCount`', '0')} AS `artistCount`
				FROM `*PREFIX*music_record_labels`
				LEFT JOIN (
					SELECT `record_label_id`, COUNT(DISTINCT(`artist_id`)) AS `artistCount`
					FROM `*PREFIX*music_tracks`
					GROUP BY `record_label_id`
				) `artist_counts`
				ON `*PREFIX*music_record_labels`.`id` = `artist_counts`.`record_label_id`
				WHERE $condition
				$extension";
	}
}
