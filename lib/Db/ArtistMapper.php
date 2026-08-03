<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2016 - 2026
 */

namespace OCA\Music\Db;

use OCA\Music\Utility\ArrayUtil;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * @method Artist findEntity(string $sql, array $params)
 * @method Artist[] findEntities(string $sql, array $params, ?int $limit=null, ?int $offset=null)
 * @extends BaseMapper<Artist>
 */
class ArtistMapper extends BaseMapper {
	public function __construct(IDBConnection $db, IConfig $config) {
		parent::__construct($db, $config, 'music_artists', Artist::class, 'name', ['user_id', 'hash', 'mbid']);
	}

	/**
	 * Override the base implementation to include data from multiple tables
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::selectEntities()
	 */
	protected function selectEntities(string $condition, ?string $extension = null) : string {
		return "SELECT
					`*PREFIX*music_artists`.*,
					{$this->sqlCoalesce('`trackCount`', '0')} AS `trackCount`,
					{$this->sqlCoalesce('`compositionCount`', '0')} AS `compositionCount`,
					{$this->sqlCoalesce('`ownAlbumCount`', '0')} AS `ownAlbumCount`
				FROM `*PREFIX*music_artists`
				LEFT JOIN (
					SELECT `artist_id`, COUNT(`id`) AS `trackCount`
					FROM `*PREFIX*music_tracks`
					GROUP BY `artist_id`
				) `track_counts`
				ON `*PREFIX*music_artists`.`id` = `track_counts`.`artist_id`
				LEFT JOIN (
					SELECT `composer_id`, COUNT(`id`) AS `compositionCount`
					FROM `*PREFIX*music_tracks`
					GROUP BY `composer_id`
				) `composition_counts`
				ON `*PREFIX*music_artists`.`id` = `composition_counts`.`composer_id`
				LEFT JOIN (
					SELECT `album_artist_id`, COUNT(`id`) AS `ownAlbumCount`
					FROM `*PREFIX*music_albums`
					GROUP BY `album_artist_id`
				) `album_counts`
				ON `*PREFIX*music_artists`.`id` = `album_counts`.`album_artist_id`
				WHERE $condition
				$extension";
	}

	/**
	 * @return Artist[]
	 */
	public function findAllHavingAlbums(string $userId, int $sortBy = SortBy::Name,
			?int $limit = null, ?int $offset = null, ?string $name = null, int $matchMode = MatchMode::Exact,
			?string $createdMin = null, ?string $createdMax = null, ?string $updatedMin = null, ?string $updatedMax = null) : array {

		return $this->findAllWithCondition(
			'`ownAlbumCount` > 0', $userId, $sortBy, $limit, $offset, $name, $matchMode, $createdMin, $createdMax, $updatedMin, $updatedMax);
	}

	/**
	 * @return Artist[]
	 */
	public function findAllHavingTracks(string $userId, int $sortBy = SortBy::Name,
			?int $limit = null, ?int $offset = null, ?string $name = null, int $matchMode = MatchMode::Exact,
			?string $createdMin = null, ?string $createdMax = null, ?string $updatedMin = null, ?string $updatedMax = null) : array {

		return $this->findAllWithCondition(
			'`trackCount` > 0', $userId, $sortBy, $limit, $offset, $name, $matchMode, $createdMin, $createdMax, $updatedMin, $updatedMax);
	}

	/**
	 * @return Artist[]
	 */
	private function findAllWithCondition(
			string $condition, string $userId, int $sortBy, ?int $limit, ?int $offset,
			?string $name, int $matchMode, ?string $createdMin, ?string $createdMax,
			?string $updatedMin, ?string $updatedMax) : array {
		$params = [$userId];

		if ($name !== null) {
			[$nameCond, $nameParams] = $this->formatNameConditions($name, $matchMode);
			$condition .= " AND $nameCond";
			$params = \array_merge($params, $nameParams);
		}

		[$timestampConds, $timestampParams] = $this->formatTimestampConditions($createdMin, $createdMax, $updatedMin, $updatedMax);
		if (!empty($timestampConds)) {
			$condition .= " AND $timestampConds";
			$params = \array_merge($params, $timestampParams);
		}

		$sql = $this->selectUserEntities($condition, $this->formatSortingClause($sortBy));

		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * @return Artist[]
	 */
	public function findAllByGenre(int $genreId, string $userId, ?int $limit = null, ?int $offset = null) : array {
		$sql = $this->selectUserEntities('EXISTS '
				. '(SELECT 1 FROM `*PREFIX*music_tracks` `track`
				  WHERE `*PREFIX*music_artists`.`id` = `track`.`artist_id`
				  AND `track`.`genre_id` = ?)');

		$params = [$userId, $genreId];
		return $this->findEntities($sql, $params, $limit, $offset);
	}

	/**
	 * returns summed track play counts of each artist of the user, omitting artists which have never been played
	 *
	 * @return array<int, int> keys are artist IDs and values are play count sums; ordered largest counts first
	 */
	public function getArtistTracksPlayCount(string $userId, ?int $limit = null, ?int $offset = null) : array {
		$sql = 'SELECT `artist_id`, SUM(`play_count`) AS `sum_count`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ? AND `play_count` > 0
				GROUP BY `artist_id`
				ORDER BY `sum_count` DESC, `artist_id`'; // the second criterion is just to make the order predictable on even counts

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$playCountByArtist = [];
		while ($row = $result->fetch()) {
			$playCountByArtist[$row['artist_id']] = (int)$row['sum_count'];
		}
		$result->closeCursor();
		return $playCountByArtist;
	}

	/**
	 * returns the latest play time of each artist of the user, omitting artists which have never been played
	 *
	 * @return array<int, string> keys are artist IDs and values are date-times; ordered latest times first
	 */
	public function getLatestArtistPlayTimes(string $userId, ?int $limit = null, ?int $offset = null) : array {
		$sql = 'SELECT `artist_id`, MAX(`last_played`) AS `latest_time`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ? AND `last_played` IS NOT NULL
				GROUP BY `artist_id`
				ORDER BY `latest_time` DESC';

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$latestTimeByArtist = [];
		while ($row = $result->fetch()) {
			$latestTimeByArtist[$row['artist_id']] = $row['latest_time'];
		}
		$result->closeCursor();
		return $latestTimeByArtist;
	}

	/**
	 * returns the latest play time of each artist of the user, including artists which have never been played
	 *
	 * @return array<int, ?string> keys are artist IDs and values are date-times (or null for never played);
	 *                             ordered furthest times first
	 */
	public function getFurthestArtistPlayTimes(string $userId, ?int $limit = null, ?int $offset = null) : array {
		$sql = 'SELECT `artist_id`, MAX(`last_played`) AS `latest_time`
				FROM `*PREFIX*music_tracks`
				WHERE `user_id` = ?
				GROUP BY `artist_id`
				ORDER BY `latest_time` ASC';

		$result = $this->execute($sql, [$userId], $limit, $offset);
		$latestTimeByArtist = [];
		while ($row = $result->fetch()) {
			$latestTimeByArtist[$row['artist_id']] = $row['latest_time'];
		}
		$result->closeCursor();
		return $latestTimeByArtist;
	}

	/**
	 * @param integer[] $coverFileIds
	 * @param string[]|null $userIds the users whose music library is targeted; all users are targeted if omitted
	 * @return Artist[] artists which got modified (with incomplete data, only id and user are valid),
	 *                  empty array if none
	 */
	public function removeCovers(array $coverFileIds, ?array $userIds = null) : array {
		// find albums using the given file as cover
		$sql = 'SELECT `id`, `user_id` FROM `*PREFIX*music_artists` WHERE `cover_file_id` IN '
		. $this->questionMarks(\count($coverFileIds));
		$params = $coverFileIds;
		if ($userIds !== null) {
			$sql .= ' AND `user_id` IN ' . $this->questionMarks(\count($userIds));
			$params = \array_merge($params, $userIds);
		}
		$artists = $this->findEntities($sql, $params);

		// if any artists found, remove the cover from those
		$count = \count($artists);
		if ($count) {
			$sql = 'UPDATE `*PREFIX*music_artists`
					SET `cover_file_id` = NULL
					WHERE `id` IN ' . $this->questionMarks($count);
			$params = ArrayUtil::extractIds($artists);
			$result = $this->execute($sql, $params);
			$result->closeCursor();
		}

		return $artists;
	}

	/**
	 * Overridden from the base implementation
	 *
	 * @see BaseMapper::formatExcludeChildlessCondition()
	 */
	protected function formatExcludeChildlessCondition() : string {
		return 'EXISTS (SELECT 1 FROM `*PREFIX*music_albums` `album` WHERE `*PREFIX*music_artists`.`id` = `album`.`album_artist_id`)';
	}

	/**
	 * Overridden from the base implementation to provide support for table-specific rules
	 *
	 * {@inheritdoc}
	 * @see BaseMapper::advFormatSqlCondition()
	 */
	protected function advFormatSqlCondition(string $rule, string $sqlOp, string $conv) : string {
		// The extra subquery "mysqlhack" seen around some nested queries is needed in order for these to not be insanely slow on MySQL.
		// In case of 'recent_played', the MySQL 5.5.62 errored with "1235 This version of MySQL doesn't yet support 'LIMIT & IN/ALL/ANY/SOME subquery'" without the extra subquery.
		$condForRule = [
			'album'            => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_albums` `a` ON `t`.`album_id` = `a`.`id` WHERE $conv(`a`.`name`) $sqlOp $conv(?))",
			'song'             => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE $conv(`t`.`title`) $sqlOp $conv(?))",
			'songrating'       => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE `t`.`rating` $sqlOp ?)",
			'albumrating'      => "`*PREFIX*music_artists`.`id` IN (SELECT `album_artist_id` from `*PREFIX*music_albums` `al` WHERE `al`.`rating` $sqlOp ?)",
			'played_times'     => "`*PREFIX*music_artists`.`id` IN (SELECT * FROM (SELECT `artist_id` from `*PREFIX*music_tracks` GROUP BY `artist_id` HAVING SUM(`play_count`) $sqlOp ?) mysqlhack)",
			'last_play'        => "`*PREFIX*music_artists`.`id` IN (SELECT * FROM (SELECT `artist_id` from `*PREFIX*music_tracks` GROUP BY `artist_id` HAVING MAX(`last_played`) $sqlOp ?) mysqlhack)",
			'myplayed'         => "`*PREFIX*music_artists`.`id` IN (SELECT * FROM (SELECT `artist_id` from `*PREFIX*music_tracks` GROUP BY `artist_id` HAVING MAX(`last_played`) $sqlOp) mysqlhack)", // operator "IS NULL" or "IS NOT NULL"
			'album_count'      => "{$this->sqlCoalesce('`ownAlbumCount`', '0')} $sqlOp ?",
			'song_count'       => "{$this->sqlCoalesce('`trackCount`', '0')} $sqlOp ?",
			'time'             => "`*PREFIX*music_artists`.`id` IN (SELECT * FROM (SELECT `artist_id` FROM `*PREFIX*music_tracks` GROUP BY `artist_id` HAVING SUM(`length`) $sqlOp ?) mysqlhack)",
			'artist_genre'     => "`*PREFIX*music_artists`.`id` IN (SELECT * FROM (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` GROUP BY `artist_id` HAVING $conv(" . $this->sqlGroupConcat('`g`.`name`') . ") $sqlOp $conv(?)) mysqlhack)",
			'song_genre'       => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` WHERE $conv(`g`.`name`) $sqlOp $conv(?))",
			'no_genre'         => '`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*music_genres` `g` ON `t`.`genre_id` = `g`.`id` WHERE `g`.`name` ' . (($sqlOp == 'IS NOT NULL') ? '=' : '!=') . ' "")',
			'playlist'         => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE $sqlOp EXISTS (SELECT 1 from `*PREFIX*music_playlists` `p` WHERE `p`.`id` = ? AND `p`.`track_ids` LIKE " . $this->sqlConcat("'%|'", '`t`.`id`', "'|%'") . '))',
			'playlist_name'    => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE EXISTS (SELECT 1 from `*PREFIX*music_playlists` `p` WHERE $conv(`p`.`name`) $sqlOp $conv(?) AND `p`.`track_ids` LIKE " . $this->sqlConcat("'%|'", '`t`.`id`', "'|%'") . '))',
			'file'             => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` JOIN `*PREFIX*filecache` `f` ON `t`.`file_id` = `f`.`fileid` WHERE $conv(`f`.`name`) $sqlOp $conv(?))",
			'recent_played'    => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM (SELECT `artist_id`, MAX(`last_played`) FROM `*PREFIX*music_tracks` WHERE `user_id` = ? GROUP BY `artist_id` ORDER BY MAX(`last_played`) DESC LIMIT $sqlOp) mysqlhack)",
			'mbid_song'        => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE $conv(`t`.`mbid`) $sqlOp $conv(?))",
			'mbid_rel_track'   => "`*PREFIX*music_artists`.`id` IN (SELECT `artist_id` FROM `*PREFIX*music_tracks` `t` WHERE $conv(`t`.`mbid_rel_track`) $sqlOp $conv(?))",
			'mbid_album'       => "`*PREFIX*music_artists`.`id` IN (SELECT `album_artist_id` from `*PREFIX*music_albums` `al` WHERE $conv(`al`.`mbid`) $sqlOp (?))",
			'mbid_album_group' => "`*PREFIX*music_artists`.`id` IN (SELECT `album_artist_id` from `*PREFIX*music_albums` `al` WHERE $conv(`al`.`mbid_group`) $sqlOp (?))",
			'has_image'        => "`cover_file_id` $sqlOp" // operator "IS NULL" or "IS NOT NULL"
		];

		// Add alias rules
		$condForRule['played'] = $condForRule['myplayed']; // we give no access to other people's data; not part of the API spec but Ample uses this
		$condForRule['genre'] = $condForRule['artist_genre'];
		$condForRule['artist'] = parent::advFormatSqlCondition('title', $sqlOp, $conv);
		$condForRule['mbid_artist'] = parent::advFormatSqlCondition('mbid', $sqlOp, $conv);

		return $condForRule[$rule] ?? parent::advFormatSqlCondition($rule, $sqlOp, $conv);
	}

	/** @return array<array{id: int, mbid: ?int}> */
	public function findIdsAndMbidsByHash(string $hash, string $userId) : array {
		$sql = 'SELECT `id`, `mbid` FROM `*PREFIX*music_artists` WHERE `hash` = ? AND `user_id` = ?';
		$result = $this->execute($sql, [$hash, $userId]);
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}

	public const VARIOUS_ARTISTS_MBID = '89ad4ac3-39f7-470e-963a-56509c546377';

	/**
	 * Get ID of the artificial artist "Various Artists". The artist is created if not already present in the DB.
	 * Note that a few bands actually named "Various Artists" have existed. The returned artist is not one of those
	 * if this can be detected from the MBID.
	 */
	public function getVariousArtistsId(string $userId) : int {
		$name = 'Various Artists';
		$hash = \hash('md5', \mb_strtolower($name));

		$nameMatches = $this->findIdsAndMbidsByHash($hash, $userId);
		if ($mbidMatch = ArrayUtil::find($nameMatches, fn ($row) => $row['mbid'] === self::VARIOUS_ARTISTS_MBID)) {
			// found the correct artist
			return $mbidMatch['id'];
		} else {
			$artist = new Artist();
			$artist->setName($name);
			$artist->setUserId($userId);
			$artist->setMbid(self::VARIOUS_ARTISTS_MBID);
			$artist->setHash($hash);

			if ($nullMbidMatch = ArrayUtil::find($nameMatches, fn ($row) => $row['mbid'] === null)) {
				// found an artist with the same name but no MBID; update it to have the correct MBID
				$artist->setId($nullMbidMatch['id']);
				$artist = $this->update($artist);
			} else {
				// no artist with the same name; insert a new one
				$artist = $this->insertOrUpdate($artist);
			}
			return $artist->getId();
		}
	}
}
