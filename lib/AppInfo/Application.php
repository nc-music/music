<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Sharing\Event\BeforeTemplateRenderedEvent;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Dashboard\MusicWidget;
use OCA\Music\Hooks\FileHooks;
use OCA\Music\Hooks\ShareHooks;
use OCA\Music\Hooks\UserHooks;
use OCA\Music\Middleware\AmpacheMiddleware;
use OCA\Music\Middleware\SubsonicMiddleware;
use OCA\Music\Service\CoverService;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\Scrobbling\AggregateScrobbler;
use OCA\Music\Service\Scrobbling\ExternalScrobbler;
use OCA\Music\Service\Scrobbling\IScrobbler;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Security\IContentSecurityPolicyManager;
use OCP\Security\ICrypto;

class Application extends App implements IBootstrap {
	public function __construct(array $urlParams = []) {
		parent::__construct('music', $urlParams);

		\mb_internal_encoding('UTF-8');
	}

	private function registerMiddleWares(IRegistrationContext $context) : void {
		$context->registerMiddleWare(AmpacheMiddleware::class);
		$context->registerMiddleWare(SubsonicMiddleware::class);
	}

	public function register(IRegistrationContext $context) : void {
		$this->registerMiddleWares($context);
		$this->registerScrobblers($context);
		$context->registerDashboardWidget(MusicWidget::class);
	}

	public function boot(IBootContext $context) : void {
		$this->init();
		$this->registerEmbeddedPlayer();
		$this->provideInitialState();
	}

	private function provideInitialState() : void {
		$initialState = $this->get(IInitialState::class);
		$userId = $this->get('userId');

		// Use the lazy variant of the state providing to not provide the state for webdav requests, for example.
		$initialState->provideLazyInitialState('default_volume', fn () => $this->get(IConfig::class)->getSystemValue('music.default_volume', 50));

		if ($userId !== null) {
			$libSettings = $this->get(LibrarySettings::class);
			$coverService = $this->get(CoverService::class);
			$session = $this->get(ISession::class);

			$initialState->provideLazyInitialState('ignored_articles', fn () => $libSettings->getIgnoredArticles($userId));
			$initialState->provideLazyInitialState('cover_access_token', fn () => $coverService->getAccessToken($userId, $session));
		}
	}

	public function init() : void {
		$this->registerHooks();

		// Adjust the CSP if loading the Music app proper or the NC dashboard
		$url = $this->getRequestUrl();
		if (\preg_match('%/apps/music/?$%', $url) || \preg_match('%/apps/dashboard/?$%', $url)) {
			$this->adjustCsp();
		}
	}

	/**
	 * Load embedded music player for Files and Sharing apps
	 */
	public function loadEmbeddedMusicPlayer() : void {
		\OCA\Music\Utility\HtmlUtil::addWebpackScript('files_music_player');
		\OCA\Music\Utility\HtmlUtil::addWebpackStyle('files_music_player');
		$this->adjustCsp();
	}

	/**
	 * Wrapper to get a service from the container, hiding the differences between the cloud versions.
	 * @param string $id A fully-qualified class name of an autoloadable class or other registered service ID
	 */
	public function get(string $id) : mixed {
		$container = $this->getContainer();
		return $container->get($id);
	}

	private function getRequestUrl() : string {
		$request = $this->get(IRequest::class);
		$url = $request->server['REQUEST_URI'] ?? '';
		$url = \explode('?', $url)[0]; // get rid of any query args
		$url = \explode('#', $url)[0]; // get rid of any hash part
		return $url;
	}

	private function registerHooks() : void {
		$dispatcher = $this->get(IEventDispatcher::class);

		FileHooks::register($dispatcher);
		ShareHooks::register($dispatcher);
		UserHooks::register($dispatcher);
	}

	private function registerEmbeddedPlayer() : void {
		$dispatcher = $this->get(IEventDispatcher::class);

		// Files app
		$dispatcher->addListener(LoadAdditionalScriptsEvent::class, function () {
			$this->loadEmbeddedMusicPlayer();
		});

		// Files_Sharing app
		$dispatcher->addListener(BeforeTemplateRenderedEvent::class, function (BeforeTemplateRenderedEvent $event) {
			// don't load the embedded player on the authentication page of password-protected share, and only load it for shared folders (not individual files)
			if ($event->getScope() != BeforeTemplateRenderedEvent::SCOPE_PUBLIC_SHARE_AUTH
					&& $event->getShare()->getNodeType() == 'folder') {
				$this->loadEmbeddedMusicPlayer();
			}
		});
	}

	/**
	 * Set content security policy to allow streaming media from the configured external sources
	 */
	private function adjustCsp() : void {
		/** @var IConfig $config */
		$config = $this->get(IConfig::class);
		$radioSources = $config->getSystemValue('music.allowed_stream_src', []);

		if (\is_string($radioSources)) {
			$radioSources = [$radioSources];
		}

		$policy = new \OCP\AppFramework\Http\ContentSecurityPolicy();

		foreach ($radioSources as $source) {
			$policy->addAllowedMediaDomain($source);
		}

		// The media sources 'data:' and 'blob:' are needed for HLS streaming
		if (self::hlsEnabled($config, $this->get('userId'))) {
			$policy->addAllowedMediaDomain('data:');
			$policy->addAllowedMediaDomain('blob:');
		}

		$this->get(IContentSecurityPolicyManager::class)->addDefaultPolicy($policy);
	}

	private static function hlsEnabled(IConfig $config, ?string $userId) : bool {
		$enabled = $config->getSystemValue('music.enable_radio_hls', true);
		if (empty($userId)) {
			$enabled = (bool)$config->getSystemValue('music.enable_radio_hls_on_share', $enabled);
		}
		return $enabled;
	}

	private function registerScrobblers(IRegistrationContext $context) : void {
		$context->registerService('externalScrobblers', function () {
			return [
				new ExternalScrobbler(
					$this->get(IConfig::class),
					$this->get(Logger::class),
					$this->get(IURLGenerator::class),
					$this->get(AlbumBusinessLayer::class),
					$this->get(ICrypto::class),
					'Last.fm',
					'lastfm',
					'http://ws.audioscrobbler.com/2.0/',
					'http://www.last.fm/api/auth/',
					$this->get('appName')
				)
			];
		});

		$context->registerService(IScrobbler::class, function () {
			$scrobblers = $this->get('externalScrobblers');
			$scrobblers[] = $this->get(TrackBusinessLayer::class);
			return new AggregateScrobbler($scrobblers);
		});
	}
}
