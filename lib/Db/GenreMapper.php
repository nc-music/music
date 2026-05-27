<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */

namespace OCA\Music\Db;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * @extends BaseMapper<Genre>
 */
class GenreMapper extends BaseMapper {
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct($db, $config, 'music_genres', Genre::class, 'name', ['user_id', 'lower_name']);
	}

	/**
	 * Create SQL query which selects genres
	 * @see \OCA\Music\Db\BaseMapper::selectEntities
	 * @return string SQL query
	 */
	protected function selectEntities(string $condition, ?string $extension=null) : string {
		return "SELECT
					`*PREFIX*music_genres`.*,
					{$this->sqlCoalesce('`trackCount`', '0')} AS `trackCount`,
					{$this->sqlCoalesce('`albumCount`', '0')} AS `albumCount`,
					{$this->sqlCoalesce('`artistCount`', '0')} AS `artistCount`
				FROM `*PREFIX*music_genres`
				LEFT JOIN (
					SELECT
						`genre_id`,
						COUNT(`track`.`id`) AS `trackCount`,
						COUNT(DISTINCT(`track`.`album_id`)) AS `albumCount`,
						COUNT(DISTINCT(`track`.`artist_id`)) AS `artistCount`
					FROM `*PREFIX*music_tracks` `track`
					GROUP BY `genre_id`
				) `counts`
				ON `*PREFIX*music_genres`.`id` = `counts`.`genre_id`
				WHERE $condition
				$extension";
	}

	/**
	 * Overridden from the base implementation to provide support for table-specific rules
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::advFormatSqlCondition()
	 */
	protected function advFormatSqlCondition(string $rule, string $sqlOp, string $conv): string
	{
		$condForRule = [
			'album_count'	=> "{$this->sqlCoalesce('`albumCount`', '0')} $sqlOp ?",
			'song_count'	=> "{$this->sqlCoalesce('`trackCount`', '0')} $sqlOp ?",
		];

		return $condForRule[$rule] ?? parent::advFormatSqlCondition($rule, $sqlOp, $conv);
	}
}
