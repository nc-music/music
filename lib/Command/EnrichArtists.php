<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\Music\Command;

use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\Db\SortBy;
use OCA\Music\Service\ArtistMetadataService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnrichArtists extends BaseCommand {
	public function __construct(
		IUserManager $userManager,
		IGroupManager $groupManager,
		private ArtistBusinessLayer $artists,
		private ArtistMetadataService $metadata,
	) {
		parent::__construct($userManager, $groupManager);
	}

	protected function doConfigure() : void {
		$this->setName('music:enrich-artists')
			->setDescription('cache MusicBrainz-matched TheAudioDB/Wikimedia artist details')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'maximum unique MBIDs per user (0 for all)', '0')
			->addOption('retry-missing', null, InputOption::VALUE_NONE, 'recheck incomplete cached metadata immediately');
	}

	protected function doExecute(InputInterface $input, OutputInterface $output, array $users) : void {
		$limit = \filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
		if ($limit === false || $limit < 0) {
			throw new \InvalidArgumentException('--limit must be a non-negative integer');
		}
		$retryMissing = (bool)$input->getOption('retry-missing');
		if ($input->getOption('all')) {
			$users = \array_map(fn ($u) => $u->getUID(), $this->userManager->search(''));
		}

		foreach ($users as $userId) {
			$seen = [];
			$checked = 0;
			$withImages = 0;
			$offset = 0;
			do {
				$artists = $this->artists->findAll($userId, SortBy::Name, 100, $offset);
				$offset += \count($artists);
				foreach ($artists as $artist) {
					$mbid = $artist->getMbid();
					if ($mbid === null || isset($seen[$mbid])) {
						continue;
					}
					$seen[$mbid] = true;
					$info = $this->metadata->getInfo($mbid, $artist->getName(), /* waitForSlot = */ true, $retryMissing);
					$checked++;
					if (!empty($info['image_url'])) {
						$withImages++;
					}
					if ($limit !== 0 && $checked >= $limit) {
						break 2;
					}
				}
			} while (\count($artists) === 100);
			$output->writeln("$userId: checked $checked unique MBIDs, $withImages with images");
		}
	}
}
