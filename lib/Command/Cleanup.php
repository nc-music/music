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

namespace OCA\Music\Command;

use OCA\Music\Db\Maintenance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Cleanup extends Command {

	public function __construct(
		private Maintenance $maintenance,
	) {
		parent::__construct();
	}

	protected function configure() : void {
		$this
			->setName('music:cleanup')
			->setDescription('clean up orphaned DB entries (this happens also periodically on the background)')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output) : int {
		$output->writeln('Running cleanup task...');
		$result = $this->maintenance->cleanUp();

		if ($result === null) {
			$output->writeln('Cleanup was skipped because of an ongoing scan job');
		} else {
			foreach ($result as $entityType => $entityStats) {
				$output->writeln("Cleaned {$entityStats['count']} $entityType in {$entityStats['time_ms']} ms");
			}
		}
		return 0;
	}
}
