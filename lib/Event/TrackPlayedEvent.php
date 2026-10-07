<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author badscooter
 * @copyright badscooter 2026
 */

namespace OCA\Music\Event;

use OCP\EventDispatcher\Event;

/**
 * Dispatched after a play of a track has been recorded for a user.
 */
class TrackPlayedEvent extends Event {

	public function __construct(
		private string $userId,
		private int $trackId,
		private \DateTimeImmutable $timeOfPlay,
	) {
		parent::__construct();
	}

	public function getUserId() : string {
		return $this->userId;
	}

	public function getTrackId() : int {
		return $this->trackId;
	}

	/**
	 * Time of the play as reported by the client, or the time of recording if the client didn't report it
	 */
	public function getTimeOfPlay() : \DateTimeImmutable {
		return $this->timeOfPlay;
	}
}
