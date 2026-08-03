<?php

declare(strict_types=1);

namespace OCA\Music\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migrate the DB schema to Music v3.2.0 level from the v2.1.0 level
 */
class Version030200Date20260803233000 extends SimpleMigrationStep {

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

		self::addColumnIfMissing($tracks, 'bpm', 'integer', ['notnull' => false, 'unsigned' => true]);
		self::addColumnIfMissing($tracks, 'composer_id', 'integer', ['notnull' => false, 'unsigned' => true]);
		self::addColumnIfMissing($tracks, 'comment', 'text', ['notnull' => false]);
		self::addColumnIfMissing($tracks, 'mbid_rel_track', 'string', ['notnull' => false, 'length' => 36]);
		self::addIndexIfMissing($tracks, 'music_tracks_composer_id_idx', ['composer_id']);

		$artists = $schema->getTable('music_artists');
		// replace unique index for user_id/hash with one for user_id/hash/mbid
		self::dropObsoleteIndex($artists, 'user_id_hash_idx');
		self::addUniqueIndexIfMissing($artists, 'user_hash_mbid_idx', ['user_id', 'hash', 'mbid']);

		$albums = $schema->getTable('music_albums');
		self::dropObsoleteColumn($albums, 'disk'); // migrated to oc_music_tracks in v0.13.1 in year 2020
		self::addColumnIfMissing($albums, 'album_artist_uncertain', 'boolean', ['notnull' => false]);
		self::addColumnIfMissing($albums, 'compilation', 'boolean', ['notnull' => false]);
		// replace unique index for user_id/hash with one for user_id/hash/album_artist_id/mbid (the hash will no longer involve album_artist_id)
		self::dropObsoleteIndex($albums, 'ma_user_id_hash_idx');
		self::addUniqueIndexIfMissing($albums, 'user_hash_artist_mbid_idx', ['user_id', 'hash', 'album_artist_id', 'mbid']);

		return $schema;
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Table $table
	 */
	private static function addColumnIfMissing($table, string $name, string $type, array $args) : void {
		if (!$table->hasColumn($name)) {
			$table->addColumn($name, $type, $args);
		}
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Table $table
	 */
	private static function addIndexIfMissing($table, string $name, array $columns) : void {
		if (!$table->hasIndex($name)) {
			$table->addIndex($columns, $name);
		}
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Table $table
	 */
	private static function addUniqueIndexIfMissing($table, string $name, array $columns) : void {
		if (!$table->hasIndex($name)) {
			$table->addUniqueIndex($columns, $name);
		}
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Table $table
	 */
	private static function dropObsoleteIndex($table, string $name) : void {
		if ($table->hasIndex($name)) {
			$table->dropIndex($name);
		}
	}

	/**
	 * @param \Doctrine\DBAL\Schema\Table $table
	 */
	private static function dropObsoleteColumn($table, string $name) : void {
		if ($table->hasColumn($name)) {
			$table->dropColumn($name);
		}
	}
}
