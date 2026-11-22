<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Db;

use OCA\Music\AppFramework\Db\UniqueConstraintViolationException;
use OCP\IDBConnection;

class Cache {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 * @return int ID of the added row
	 * @throws UniqueConstraintViolationException
	 */
	public function add(string $userId, string $key, string $data) : int {
		$sql = 'INSERT INTO `*PREFIX*music_cache`
				(`user_id`, `key`, `data`) VALUES (?, ?, ?)';
		$this->executeStatement($sql, [$userId, $key, $data]);
		return $this->db->lastInsertId('*PREFIX*music_cache');
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 * @return int number of updated rows
	 */
	public function update(string $userId, string $key, string $data) : int {
		$sql = 'UPDATE `*PREFIX*music_cache` SET `data` = ?
				WHERE `user_id` = ? AND `key` = ?';
		return $this->executeStatement($sql, [$data, $userId, $key]);
	}

	/**
	 * Add an entry or update it if it already exists
	 * @param string $userId
	 * @param string $key
	 * @param string $data
	 * @param bool $probablyExists True if the caller thinks that a matching entry already exists. This is just a hint to
	 *                             optimize the number of queries but the method will work correctly regardless of the value.
	 */
	public function set(string $userId, string $key, string $data, bool $probablyExists = false) : void {
		$updated = 0;
		if ($probablyExists) {
			$updated = $this->update($userId, $key, $data);
		}

		if ($updated === 0) {
			try {
				$this->add($userId, $key, $data);
			} catch (UniqueConstraintViolationException $e) {
				$this->update($userId, $key, $data);
			}
		}
	}

	/**
	 * Remove one or several key-value pairs
	 *
	 * @param string $userId User to target, omit to target all users
	 * @param string $key Key to target, omit to target all keys
	 * @param string $data Data to target, omit to target all data values
	 */
	public function remove(?string $userId = null, ?string $key = null, ?string $data = null) : void {
		$sql = 'DELETE FROM `*PREFIX*music_cache`';
		$conditions = [];
		$params = [];

		if ($userId !== null) {
			$conditions[] = '`user_id` = ?';
			$params[] = $userId;
		}
		if ($key !== null) {
			$conditions[] = '`key` = ?';
			$params[] = $key;
		}
		if ($data !== null) {
			$conditions[] = '`data` = ?';
			$params[] = $data;
		}
		if (!empty($conditions)) {
			$sql .= ' WHERE ' . \implode(' AND ', $conditions);
		}

		$this->executeStatement($sql, $params);
	}

	/**
	 * @param string $userId
	 * @param string $key
	 * @return string|null
	 */
	public function get(string $userId, string $key) : ?string {
		$sql = 'SELECT `data` FROM `*PREFIX*music_cache`
				WHERE `user_id` = ? AND `key` = ?';
		$result = $this->db->executeQuery($sql, [$userId, $key]);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return \count($rows) ? $rows[0]['data'] : null;
	}

	/**
	 * Get all key-value pairs of one user, optionally limiting to keys with a given prefix.
	 * @param string $userId
	 * @param string|null $prefix
	 * @return array<array{key: string, data: string}>
	 */
	public function getAll(string $userId, ?string $prefix = null) : array {
		$sql = 'SELECT `key`, `data` FROM `*PREFIX*music_cache`
				WHERE `user_id` = ?';
		$params = [$userId];

		if (!empty($prefix)) {
			$sql .= ' AND `key` LIKE ?';
			$params[] = $prefix . '%';
		}

		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Given a cache key and its exact content, return the owning user
	 */
	public function getOwner(string $key, string $data) : ?string {
		$sql = 'SELECT `user_id` FROM `*PREFIX*music_cache`
				WHERE `key` = ? AND `data` = ?';
		$result = $this->db->executeQuery($sql, [$key, $data]);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return \count($rows) ? $rows[0]['user_id'] : null;
	}

	public function getId(string $userId, string $key) : ?int {
		$sql = 'SELECT `id` FROM `*PREFIX*music_cache`
				WHERE `user_id` = ? AND `key` = ?';
		$result = $this->db->executeQuery($sql, [$userId, $key]);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return \count($rows) ? (int)$rows[0]['id'] : null;
	}

	/**
	 * Get ID of an existing entry, creating a new empty entry if one doesn't exist
	 */
	public function forcedGetId(string $userId, string $key) : int {
		$id = $this->getId($userId, $key);
		if ($id === null) {
			try {
				$id = $this->add($userId, $key, '');
			} catch (UniqueConstraintViolationException $e) {
				// with a really bad luck, the item didn't exist an eyeblink ago but now it does
				$id = (int)$this->getId($userId, $key);
			}
		}
		return $id;
	}

	private function executeStatement(string $sql, array $params) : int {
		try {
			return $this->db->executeStatement($sql, $params);
		} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
			throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
		} catch (\OCP\DB\Exception $e) {
			// Nextcloud 21
			if ($e->getReason() == \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new UniqueConstraintViolationException($e->getMessage(), $e->getCode(), $e);
			} else {
				throw $e;
			}
		}
	}
}
