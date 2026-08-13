<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Db;

use OCA\Music\AppFramework\Core\Logger;
use OCP\IDBConnection;

class Maintenance {

	public function __construct(
		private IDBConnection $db,
		private Logger $logger,
	) {
	}

	/**
	 * Remove 'scanning' flags with timestamp older than one minute. These have been probably left over
	 * when the scanning of some file has terminated unexpectedly.
	 */
	private function removeStrayScanningStatus() : int {
		$sql = 'SELECT `user_id`, `data` FROM `*PREFIX*music_cache`
				WHERE `key` = \'scanning\'';
		$result = $this->db->executeQuery($sql);
		$rows = $result->fetchAll();
		$result->closeCursor();

		$now = \microtime(true);
		$modRows = 0;
		foreach ($rows as $row) {
			$timestamp = (float)$row['data'];
			if ($now - $timestamp > 60) {
				$modRows += $this->db->executeStatement(
					'DELETE FROM `*PREFIX*music_cache` WHERE `key` = \'scanning\' AND `user_id` = ?',
					[$row['user_id']]
				);
			}
		}

		return $modRows;
	}

	/**
	 * @return bool true if at least one user has an ongoing scanning job
	 */
	private function scanningInProgress() : bool {
		$sql = 'SELECT 1 FROM `*PREFIX*music_cache`	WHERE `key` = \'scanning\'';
		$result = $this->db->executeQuery($sql);
		$row = $result->fetch();
		return (bool)$row;
	}

	/**
	 * Remove cover_file_id from album if the corresponding file does not exist
	 */
	private function removeObsoleteCoverImagesFromTable(string $table) : int {
		return $this->db->executeStatement(
			"UPDATE `*PREFIX*$table` SET `cover_file_id` = NULL
			WHERE `cover_file_id` IS NOT NULL AND `cover_file_id` IN (
				SELECT `cover_file_id` FROM (
					SELECT `cover_file_id` FROM `*PREFIX*$table`
					LEFT JOIN `*PREFIX*filecache`
						ON `cover_file_id`=`fileid`
					WHERE `fileid` IS NULL
				) mysqlhack
			)"
		);
	}

	/**
	 * Remove cover_file_id from album if the corresponding file does not exist
	 */
	private function removeObsoleteAlbumCoverImages() : int {
		return $this->removeObsoleteCoverImagesFromTable('music_albums');
	}

	/**
	 * Remove cover_file_id from artist if the corresponding file does not exist
	 */
	private function removeObsoleteArtistCoverImages() : int {
		return $this->removeObsoleteCoverImagesFromTable('music_artists');
	}

	/**
	 * Remove all such rows from $tgtTable which don't have corresponding rows in $refTable
	 * so that $tgtTableKey = $refTableKey.
	 * @param string $tgtTable
	 * @param string $refTable
	 * @param string $tgtTableKey
	 * @param string $refTableKey
	 * @param string|null $extraCond
	 * @return int Number of removed rows
	 */
	private function removeUnreferencedDbRows(string $tgtTable, string $refTable, string $tgtTableKey, string $refTableKey, ?string $extraCond = null) : int {
		$tgtTable = '*PREFIX*' . $tgtTable;
		$refTable = '*PREFIX*' . $refTable;

		return $this->db->executeStatement(
			"DELETE FROM `$tgtTable` WHERE `id` IN (
				SELECT `id` FROM (
					SELECT `$tgtTable`.`id`
					FROM `$tgtTable` LEFT JOIN `$refTable`
					ON `$tgtTable`.`$tgtTableKey` = `$refTable`.`$refTableKey`
					WHERE `$refTable`.`$refTableKey` IS NULL
				) mysqlhack
			)"
			. (empty($extraCond) ? '' : " AND $extraCond")
		);
	}

	/**
	 * Remove tracks which do not have corresponding file in the file system
	 * @return int Number of removed tracks
	 */
	private function removeObsoleteTracks() : int {
		return $this->removeUnreferencedDbRows('music_tracks', 'filecache', 'file_id', 'fileid');
	}

	/**
	 * Remove tracks which belong to non-existing album
	 * @return int Number of removed tracks
	 */
	private function removeTracksWithNoAlbum() : int {
		return $this->removeUnreferencedDbRows('music_tracks', 'music_albums', 'album_id', 'id');
	}

	/**
	 * Remove tracks which are performed by non-existing artist
	 * @return int Number of removed tracks
	 */
	private function removeTracksWithNoArtist() : int {
		return $this->removeUnreferencedDbRows('music_tracks', 'music_artists', 'artist_id', 'id');
	}

	/**
	 * Remove albums which have no tracks
	 * @return int Number of removed albums
	 */
	private function removeObsoleteAlbums() : int {
		return $this->removeUnreferencedDbRows('music_albums', 'music_tracks', 'id', 'album_id');
	}

	/**
	 * Remove albums which have a non-existing album artist
	 * @return int Number of removed albums
	 */
	private function removeAlbumsWithNoArtist() : int {
		return $this->removeUnreferencedDbRows('music_albums', 'music_artists', 'album_artist_id', 'id');
	}

	/**
	 * Remove artists which have no albums and no tracks
	 * @return int Number of removed artists
	 */
	private function removeObsoleteArtists() : int {
		// Note: This originally used the NOT IN operation but that was terribly inefficient on PostgreSQL,
		// see https://github.com/nc-music/oc-music/issues/997
		return $this->db->executeStatement(
			'DELETE FROM `*PREFIX*music_artists`
				WHERE NOT EXISTS (SELECT 1 FROM `*PREFIX*music_albums` WHERE `*PREFIX*music_artists`.`id` = `album_artist_id` LIMIT 1)
				AND   NOT EXISTS (SELECT 1 FROM `*PREFIX*music_tracks` WHERE `*PREFIX*music_artists`.`id` = `artist_id` LIMIT 1)
				AND   NOT EXISTS (SELECT 1 FROM `*PREFIX*music_tracks` WHERE `*PREFIX*music_artists`.`id` = `composer_id` LIMIT 1)'
		);
	}

	private function removeObsoleteGenres() : int {
		return $this->removeUnreferencedDbRows('music_genres', 'music_tracks', 'id', 'genre_id');
	}

	/**
	 * Remove bookmarks referring tracks which do not exist
	 * @return int Number of removed bookmarks
	 */
	private function removeObsoleteBookmarks() : int {
		return $this->removeUnreferencedDbRows('music_bookmarks', 'music_tracks', 'entry_id', 'id', '`type` = 1')
			+ $this->removeUnreferencedDbRows('music_bookmarks', 'music_podcast_episodes', 'entry_id', 'id', '`type` = 2');
	}

	/**
	 * Remove podcast episodes which have a non-existing podcast channel
	 * @return int Number of removed albums
	 */
	private function removeObsoletePodcastEpisodes() : int {
		return $this->removeUnreferencedDbRows('music_podcast_episodes', 'music_podcast_channels', 'channel_id', 'id');
	}

	/**
	 * @param callable():int $func Function returning a count
	 * @return array{count: int, time_ms: int}
	 */
	private static function timedExecute(callable $func) : array {
		$startTime = \hrtime(true);
		$result = $func();
		$elapsedTime = (int)((\hrtime(true) - $startTime) / 1000000);
		return ['count' => $result, 'time_ms' => $elapsedTime];
	}

	/**
	 * Removes orphaned data from the database
	 * @return ?array<string, array{count: int, time_ms: int}> For each handled entity type (keys), the value contains the number of elements
	 *         removed and the time taken on the operation in milliseconds; null if the cleanup was skipped because of an ongoing scan job
	 */
	public function cleanUp() : ?array {
		$scanFlagResult = ['scan_flags' => self::timedExecute(fn () => $this->removeStrayScanningStatus())];

		// Don't clean during an ongoing scan. This may cause the scanning to fail with a deadlock error on MariaDB,
		// see https://github.com/nc-music/oc-music/issues/918. It could also remove a just scanned album row before the
		// contained track rows have been added to the DB, which would have happened a few milliseconds later.
		if ($this->scanningInProgress()) {
			return null;
		}

		$handlers = [
			['covers',           fn () => $this->removeObsoleteAlbumCoverImages() + $this->removeObsoleteArtistCoverImages()],
			['tracks',           fn () => $this->removeObsoleteTracks() + $this->removeTracksWithNoAlbum() + $this->removeTracksWithNoArtist()],
			['albums',           fn () => $this->removeObsoleteAlbums() + $this->removeAlbumsWithNoArtist()],
			['artists',          fn () => $this->removeObsoleteArtists()],
			['genres',           fn () => $this->removeObsoleteGenres()],
			['bookmarks',        fn () => $this->removeObsoleteBookmarks()],
			['podcast_episodes', fn () => $this->removeObsoletePodcastEpisodes()],
		];

		return $scanFlagResult + \array_combine(
			\array_column($handlers, 0),
			\array_map(fn($cleanFunc) => self::timedExecute($cleanFunc), \array_column($handlers, 1))
		);
	}

	/**
	 * Wipe clean the given table, either targeting a specific user all users
	 * @param string $table Name of the table, _excluding_ the prefix *PREFIX*music_
	 * @param ?string $userId
	 * @param bool $allUsers
	 * @throws \InvalidArgumentException
	 */
	private function resetTable(string $table, ?string $userId, bool $allUsers = false) : void {
		if ($userId && $allUsers) {
			throw new \InvalidArgumentException('userId should be null if allUsers targeted');
		}

		$params = [];
		$sql = "DELETE FROM `*PREFIX*music_$table`";
		if (!$allUsers) {
			$sql .= ' WHERE `user_id` = ?';
			$params[] = $userId;
		}
		$this->db->executeStatement($sql, $params);
	}

	/**
	 * Wipe clean the music library of the given user, or all users
	 */
	public function resetLibrary(?string $userId, bool $allUsers = false) : void {
		$tables = [
			'tracks',
			'albums',
			'artists',
			'playlists',
			'genres',
			'bookmarks',
			'cache'
		];

		foreach ($tables as $table) {
			$this->resetTable($table, $userId, $allUsers);
		}

		if ($allUsers) {
			$this->logger->info('Erased music databases of all users');
		} else {
			$this->logger->info("Erased music database of user $userId");
		}
	}

	/**
	 * Wipe clean all the music of the given user, including the library, podcasts, radio, Ampache/Subsonic keys
	 */
	public function resetAllData(string $userId) : void {
		$this->resetLibrary($userId);

		$tables = [
			'ampache_sessions',
			'ampache_users',
			'podcast_channels',
			'podcast_episodes',
			'radio_stations'
		];

		foreach ($tables as $table) {
			$this->resetTable($table, $userId);
		}
	}
}
