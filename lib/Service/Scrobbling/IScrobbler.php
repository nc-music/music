<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Matthew Wells 2025
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Scrobbling;

use OCA\Music\Db\Track;

interface IScrobbler {
	/**
	 * @param ?\DateTime $timeOfPlay When the track was played, default to "now".
	 * @param string $client Name of the application reporting the play. It's up to each scrobbler if it uses this for anything.
	 */
	public function recordTrackPlayed(Track $track, string $client, ?\DateTime $timeOfPlay = null) : void;

	/** @see self::recordTrackPlayed for the parameters */
	public function setNowPlaying(Track $track, string $client, ?\DateTime $timeOfPlay = null) : void;
}
