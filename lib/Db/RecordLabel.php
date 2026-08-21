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

/**
 * @method ?string getName()
 * @method void setName(?string $name)
 * @method string getHash()
 * @method void setHash(string $hash)
 * @method int getArtistCount()
 */
class RecordLabel extends Entity {
	public ?string $name = null;
	public string $hash = '';

	// not from the music_record_labels table but still part of the standard content of this entity:
	public int $artistCount = 0;

	public function __construct() {
	}

	public function toAmpacheApi() : array {
		return [
			'id' => (string)$this->getId(),
			'name' => $this->getName(),
			'artists' => $this->getArtistCount(),
			'summary' => null,
			'external_link' => null,
			'address' => null,
			'category' => 'tag_generated',
			'email' => null,
			'website' => null,
			'user' => $this->getUserId(),
		];
	}
}
