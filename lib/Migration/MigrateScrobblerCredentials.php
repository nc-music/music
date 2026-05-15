<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

namespace OCA\Music\Migration;

use OCA\Music\Service\Scrobbling\ExternalScrobbler;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class MigrateScrobblerCredentials implements IRepairStep {

	/**
	 * @param array<int, ExternalScrobbler> $externalScrobblers
	 */
	public function __construct(
		private IConfig $config,
		private array $externalScrobblers
	) {}

	public function getName() {
		return 'Move scrobbler credentials from config.php to appconfig database table';
	}

	/**
	 * @inheritdoc
	 * @return void
	 */
	public function run(IOutput $output) {
		foreach ($this->externalScrobblers as $externalScrobbler) {
			$identifier = $externalScrobbler->getIdentifier();
			$apiKeyName = "{$identifier}_api_key";
			$apiSecretName = "{$identifier}_api_secret";
			$key = $this->config->getSystemValue($apiKeyName, null);
			$secret = $this->config->getSystemValue($apiSecretName, null);
			if ($key) {
				$this->config->deleteSystemValue($apiKeyName);
				$this->config->setAppValue('music', $apiKeyName, $key);
			}
			if ($secret) {
				$this->config->deleteSystemValue($apiSecretName);
				$this->config->setAppValue('music', $apiSecretName, $secret);
			}
		}
	}
}
