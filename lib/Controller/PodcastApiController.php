<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2021 - 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\AppFramework\Utility\FileExistsException;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\RelayStreamResponse;
use OCA\Music\Service\PodcastService;
use OCA\Music\Service\RadioService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;

class PodcastApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private IRootFolder $rootFolder,
		private PodcastService $podcastService,
		private RadioService $radioService,
		private ?string $userId,
		private Logger $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function user() : string {
		// The $userId may be null in constructor (if user session has been terminated) but none of our
		// public methods should get called in such case. Assure this also for Scrutinizer.
		\assert($this->userId !== null);
		return $this->userId;
	}

	/**
	 * lists all podcasts
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getAll() : JSONResponse {
		$channels = $this->podcastService->getAllChannels($this->user(), /*$includeEpisodes=*/ true);
		return new JSONResponse(
			\array_map(fn ($c) => $c->toApi($this->urlGenerator), $channels)
		);
	}

	/**
	 * adds a followed podcast channel
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function subscribe(?string $url) : JSONResponse {
		if ($url === null) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, "Mandatory argument 'url' not given");
		}

		$result = $this->podcastService->subscribe($url, $this->user());

		switch ($result['status']) {
			case PodcastService::STATUS_OK:
				return new JSONResponse($result['channel']->toApi($this->urlGenerator));
			case PodcastService::STATUS_INVALID_URL:
				return new ErrorResponse(Http::STATUS_BAD_REQUEST, $result['message']);
			case PodcastService::STATUS_INVALID_RSS:
				return new ErrorResponse(Http::STATUS_BAD_REQUEST, $result['message']);
			case PodcastService::STATUS_ALREADY_EXISTS:
				return new ErrorResponse(Http::STATUS_CONFLICT, $result['message']);
			default:
				return new ErrorResponse(Http::STATUS_INTERNAL_SERVER_ERROR, "Unexpected status code {$result['status']}");
		}
	}

	/**
	 * removes a followed podcast channel
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function unsubscribe(int $id) : JSONResponse {
		$status = $this->podcastService->unsubscribe($id, $this->user());

		switch ($status) {
			case PodcastService::STATUS_OK:
				return new JSONResponse(['success' => true]);
			case PodcastService::STATUS_NOT_FOUND:
				return new ErrorResponse(Http::STATUS_NOT_FOUND);
			default:
				return new ErrorResponse(Http::STATUS_INTERNAL_SERVER_ERROR, "Unexpected status code $status");
		}
	}

	/**
	 * get a single podcast channel
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(int $id) : JSONResponse {
		$channel = $this->podcastService->getChannel($id, $this->user(), /*includeEpisodes=*/ true);

		if ($channel !== null) {
			return new JSONResponse($channel->toApi($this->urlGenerator));
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * get details for a podcast channel
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function channelDetails(int $id) : JSONResponse {
		$channel = $this->podcastService->getChannel($id, $this->user(), /*includeEpisodes=*/ false);

		if ($channel !== null) {
			return new JSONResponse($channel->detailsToApi($this->urlGenerator));
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * get details for a podcast episode
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function episodeDetails(int $id) : JSONResponse {
		$episode = $this->podcastService->getEpisode($id, $this->user());

		if ($episode !== null) {
			return new JSONResponse($episode->detailsToApi());
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * get audio stream for a podcast episode
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function episodeStream(int $id) : Response {
		$episode = $this->podcastService->getEpisode($id, $this->user());

		if ($episode !== null) {
			$streamUrl = $episode->getStreamUrl();
			if ($streamUrl === null) {
				return new ErrorResponse(Http::STATUS_NOT_FOUND, "The podcast episode $id has no stream URL");
			} else {
				$resolved = $this->radioService->resolveStreamUrl($streamUrl);
				if ($resolved['url'] === null) {
					return new ErrorResponse(Http::STATUS_NOT_FOUND, "The podcast episode $id has an invalid stream URL");
				} else if ($this->config->getSystemValue('music.relay_podcast_stream', true) && !$resolved['is_private_ip']) {
					return new RelayStreamResponse($resolved['url']);
				} else {
					return new RedirectResponse($resolved['url']);
				}
			}
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * check a single channel for updates
	 * @param int $id Channel ID
	 * @param string|null $prevHash Previous content hash known by the client. If given, the result will tell
	 *                              if the channel content has updated from this state. If omitted, the result
	 *                              will tell if the channel changed from its previous server-known state.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function updateChannel(int $id, ?string $prevHash) : JSONResponse {
		$updateResult = $this->podcastService->updateChannel($id, $this->user(), $prevHash);

		$response = [
			'success' => ($updateResult['status'] === PodcastService::STATUS_OK),
			'updated' => $updateResult['updated']
		];
		if ($updateResult['updated'] && $updateResult['channel']) {
			$response['channel'] = $updateResult['channel']->toApi($this->urlGenerator);
		}

		return new JSONResponse($response);
	}

	/**
	 * reset all the subscribed podcasts of the user
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resetAll() : JSONResponse {
		$this->podcastService->resetAll($this->user());
		return new JSONResponse(['success' => true]);
	}

	/**
	 * export all podcast channels to an OPML file
	 *
	 * @param string $name target file name
	 * @param string $path parent folder path
	 * @param string $oncollision action to take on file name collision,
	 *                            supported values:
	 *                            - 'overwrite' The existing file will be overwritten
	 *                            - 'keepboth' The new file is named with a suffix to make it unique
	 *                            - 'abort' (default) The operation will fail
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportAllToFile(string $name, string $path, string $oncollision) : JSONResponse {
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->user());
			$exportedFilePath = $this->podcastService->exportToFile(
					$this->user(), $userFolder, $path, $name, $oncollision);
			return new JSONResponse(['wrote_to_file' => $exportedFilePath]);
		} catch (\OCP\Files\NotFoundException $ex) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND, 'folder not found');
		} catch (FileExistsException $ex) {
			return new ErrorResponse(Http::STATUS_CONFLICT, 'file already exists', ['path' => $ex->getPath(), 'suggested_name' => $ex->getAltName()]);
		} catch (\OCP\Files\NotPermittedException $ex) {
			return new ErrorResponse(Http::STATUS_FORBIDDEN, 'user is not allowed to write to the target file');
		}
	}

	/**
	 * parse an OPML file and return list of contained channels
	 *
	 * @param string $filePath path of the file to parse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function parseListFile(string $filePath) : JSONResponse {
		try {
			$userFolder = $this->rootFolder->getUserFolder($this->user());
			$list = $this->podcastService->parseOpml($userFolder, $filePath);
			return new JSONResponse($list);
		} catch (\UnexpectedValueException $ex) {
			return new ErrorResponse(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, $ex->getMessage());
		}
	}
}
