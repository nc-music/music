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

namespace OCA\Music\Settings;

use OCA\Music\Service\Scrobbling\ExternalScrobbler;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
	/**
	 * @param array<int, ExternalScrobbler> $externalScrobblers
	 */
	public function __construct(
		private IL10N $l10n,
		private array $externalScrobblers,
		private \OCP\AppFramework\Services\IInitialState $initialState
	) {}

	public function getForm()
	{
		$this->initialState->provideInitialState('scrobblers', \array_map(
			fn (ExternalScrobbler $scrobbler) => [
				'identifier' => $scrobbler->getIdentifier(),
				'name' => $scrobbler->getName(),
				'api_key' => $scrobbler->getApiKey(),
				'api_secret' => $scrobbler->getApiSecret()
			],
			$this->externalScrobblers
		));

		return new TemplateResponse('music', 'admin', [
			'lang' => $this->l10n->getLanguageCode()
		]);
	}

	public function getSection()
	{
		return 'music';
	}

	public function getPriority()
	{
		return 41;
	}
}