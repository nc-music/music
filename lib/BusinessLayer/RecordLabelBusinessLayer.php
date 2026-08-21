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

namespace OCA\Music\BusinessLayer;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\RecordLabel;
use OCA\Music\Db\RecordLabelMapper;
use OCA\Music\Db\SortBy;
use OCA\Music\Db\TrackMapper;
use OCA\Music\Utility\LocalCacheTrait;
use OCA\Music\Utility\StringUtil;

/**
 * Base class functions with the actually used inherited types to help IDE and Scrutinizer:
 * @method RecordLabel find(int $genreId, string $userId)
 * @method RecordLabel[] findAll(string $userId, int $sortBy=SortBy::Name, ?int $limit=null, ?int $offset=null)
 * @method RecordLabel[] findAllByName(string $name, string $userId, int $matchMode=MatchMode::Exact, ?int $limit=null, ?int $offset=null)
 * @property RecordLabelMapper $mapper
 * @extends BusinessLayer<RecordLabel>
 */
class RecordLabelBusinessLayer extends BusinessLayer {
	/** @phpstan-use LocalCacheTrait<RecordLabel> */
	use LocalCacheTrait;

	public function __construct(
		RecordLabelMapper $mapper,
		private Logger $logger,
	) {
		parent::__construct($mapper);
	}

	/**
	 * Adds a record label if it does not exist already (in case insensitive sense) or updates an existing record label
	 */
	public function addOrUpdate(string $name, string $userId) : RecordLabel {
		$name = StringUtil::truncate($name, 256); // some DB setups can't truncate automatically to column max size
		$hash = \hash('md5', \mb_strtolower($name ?? ''));

		return $this->cachedGet($userId, $hash, function () use ($name, $userId, $hash) {
			$label = new RecordLabel();
			$label->setName($name);
			$label->setHash($hash);
			$label->setUserId($userId);
			return $this->mapper->updateOrInsert($label);
		});
	}
}
