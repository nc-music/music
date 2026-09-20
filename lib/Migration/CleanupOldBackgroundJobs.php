<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2023 - 2026
 */

namespace OCA\Music\Migration;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class CleanupOldBackgroundJobs implements IRepairStep {

	public function getName() {
		return 'Remove legacy background job registrations';
	}

	/**
	 * @inheritdoc
	 * @return void
	 */
	public function run(IOutput $output) {
		// remove legacy job registrations possibly made by older versions of the Music app
		$jobList = \OC::$server->query(IJobList::class);
		$jobList->remove('OC\BackgroundJob\Legacy\RegularJob', ['OCA\Music\Backgroundjob\Cleanup', 'run']);
		$jobList->remove('OC\BackgroundJob\Legacy\RegularJob', ['OCA\Music\Backgroundjob\CleanUp', 'run']);
		$jobList->remove('OC\BackgroundJob\Legacy\RegularJob', ['OCA\Music\Backgroundjob\PodcastUpdateCheck', 'run']);
	}

}
