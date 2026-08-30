<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

namespace OCA\Music\Controller;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\GenreBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Maintenance;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Http\FileStreamResponse;
use OCA\Music\Service\CollectionService;
use OCA\Music\Service\DetailsService;
use OCA\Music\Service\FileSystemService;
use OCA\Music\Service\LastfmService;
use OCA\Music\Service\LibrarySettings;
use OCA\Music\Service\Scanner;
use OCA\Music\Service\Scrobbling\IScrobbler;
use OCA\Music\Utility\HttpUtil;
use OCA\Music\Utility\Util;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class MusicApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private TrackBusinessLayer $trackBusinessLayer,
		private GenreBusinessLayer $genreBusinessLayer,
		private Scanner $scanner,
		private CollectionService $collectionService,
		private DetailsService $detailsService,
		private FileSystemService $fileSystemService,
		private LastfmService $lastfmService,
		private Maintenance $maintenance,
		private LibrarySettings $librarySettings,
		private ?string $userId, // null case should happen only when the user has already logged out
		private Logger $logger,
		private IScrobbler $scrobbler,
	) {
		parent::__construct($appName, $request);
	}

	private function user() : string {
		// The $userId may be null in constructor (if user session has been terminated) but none of our
		// public methods should get called in such case. Assure this also for Scrutinizer.
		\assert($this->userId !== null);
		return $this->userId;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function prepareCollection() : JSONResponse {
		$hash = $this->collectionService->getCachedJsonHash($this->user());
		if ($hash === null) {
			// build the collection but ignore the data for now
			$this->collectionService->getJson($this->user());
			$hash = $this->collectionService->getCachedJsonHash($this->user());
		}

		return new JSONResponse([
			'hash' => $hash,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function collection() : DataDisplayResponse {
		$collectionJson = $this->collectionService->getJson($this->user());
		$response = new DataDisplayResponse($collectionJson);
		$response->addHeader('Content-Type', 'application/json; charset=utf-8');

		// Instruct the client to cache the result in case it requested the collection with
		// the correct hash. The hash could be incorrect if the collection would have changed
		// between calls to prepareCollection() and collection().
		$requestHash = $this->request->getParam('hash');
		$actualHash = $this->collectionService->getCachedJsonHash($this->user());
		if (!empty($actualHash) && $requestHash === $actualHash) {
			HttpUtil::setClientCachingDays($response, 90);
		}

		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function folders() : JSONResponse {
		$musicFolder = $this->librarySettings->getFolder($this->user());
		$folders = $this->fileSystemService->findAllFolders($this->user(), $musicFolder);
		return new JSONResponse($folders);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function genres() : JSONResponse {
		$genres = $this->genreBusinessLayer->findAllWithTrackIds($this->user());
		return new JSONResponse($genres);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function trackByFileId(int $fileId) : JSONResponse {
		$track = $this->trackBusinessLayer->findByFileId($fileId, $this->user());
		if ($track !== null) {
			return new JSONResponse($track->toCollection());
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession] // keep the session reserved to serialize with the other scan-related endpoints
	public function getScanState() : JSONResponse {
		return new JSONResponse($this->scanner->getStatusOfLibraryFiles($this->user()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession] // keep the session reserved to serialize with the other scan-related endpoints
	public function scan(string $files, string|int|bool|null $finalize) : JSONResponse {
		// extract the parameters
		$fileIds = \array_map('intval', \explode(',', $files));
		$finalize = \filter_var($finalize, FILTER_VALIDATE_BOOLEAN);

		['count' => $filesScanned] = $this->scanner->scanFiles($this->user(), $fileIds);

		$albumCoversUpdated = false;
		if ($finalize) {
			$albumCoversUpdated = $this->scanner->findAlbumCovers($this->user());
			$this->scanner->findArtistCovers($this->user());
			$totalCount = $this->trackBusinessLayer->count($this->user());
			$this->logger->info("Scanning finished, user {$this->user()} has $totalCount scanned tracks in total");
		}

		return new JSONResponse([
			'filesScanned'       => $filesScanned,
			'albumCoversUpdated' => $albumCoversUpdated
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession] // keep the session reserved to serialize with the other scan-related endpoints
	public function removeScanned(string $files) : JSONResponse {
		$fileIds = \array_map('intval', \explode(',', $files));
		$anythingRemoved = $this->scanner->deleteAudio($fileIds, [$this->user()]);
		return new JSONResponse(['filesRemoved' => $anythingRemoved]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UseSession] // keep the session reserved to serialize with the other scan-related endpoints
	public function resetScanned() : JSONResponse {
		$this->maintenance->resetLibrary($this->user());
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function download(int $fileId) : Response {
		$nodes = $this->scanner->resolveUserFolder($this->user())->getById($fileId);
		$node = $nodes[0] ?? null;
		if ($node instanceof \OCP\Files\File) {
			return new FileStreamResponse($node);
		}

		return new ErrorResponse(Http::STATUS_NOT_FOUND, 'file not found');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function filePath(int $fileId) : JSONResponse {
		$userFolder = $this->scanner->resolveUserFolder($this->user());
		$nodes = $userFolder->getById($fileId);
		if (\count($nodes) == 0) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		} else {
			$node = $nodes[0];
			$path = $userFolder->getRelativePath($node->getPath());
			return new JSONResponse(['path' => Util::urlEncodePath($path)]);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fileInfo(int $fileId) : JSONResponse {
		$userFolder = $this->scanner->resolveUserFolder($this->user());
		$info = $this->scanner->getFileInfo($fileId, $this->user(), $userFolder);
		if ($info) {
			return new JSONResponse($info);
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fileDetails(int $fileId) : JSONResponse {
		$userFolder = $this->scanner->resolveUserFolder($this->user());
		$details = $this->detailsService->getDetails($fileId, $userFolder);
		if ($details) {
			// metadata extracted, attempt to include also the data from Last.fm
			$track = $this->trackBusinessLayer->findByFileId($fileId, $this->user());
			if ($track) {
				$details['lastfm'] = $this->lastfmService->getTrackInfo($track->getId(), $this->user());
			} else {
				$this->logger->warning("Track with file ID $fileId was not found => can't fetch info from Last.fm");
			}

			return new JSONResponse($details);
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function findDetails(?string $song, ?string $artist) : JSONResponse {
		if (empty($song) || empty($artist)) {
			return new ErrorResponse(Http::STATUS_BAD_REQUEST, 'Song or artist name argument missing');
		} else {
			return new JSONResponse(['lastfm' => $this->lastfmService->findTrackInfo($song, $artist)]);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function fileLyrics(int $fileId, ?string $format) : Response {
		$userFolder = $this->scanner->resolveUserFolder($this->user());
		if ($format == 'plaintext') {
			$lyrics = $this->detailsService->getLyricsAsPlainText($fileId, $userFolder);
			if (!empty($lyrics)) {
				return new DataDisplayResponse($lyrics, Http::STATUS_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
			}
		} else {
			$lyrics = $this->detailsService->getLyricsAsStructured($fileId, $userFolder);
			if (!empty($lyrics)) {
				return new JSONResponse($lyrics);
			}
		}
		return new ErrorResponse(Http::STATUS_NOT_FOUND);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function scrobble(int $trackId) : JSONResponse {
		try {
			$track = $this->trackBusinessLayer->find($trackId, $this->user());
			$this->scrobbler->recordTrackPlayed($track);
			return new JSONResponse(['success' => true]);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setPlayingTrack(int $trackId) : JSONResponse {
		try {
			$track = $this->trackBusinessLayer->find($trackId, $this->user());
			$this->scrobbler->setNowPlaying($track, null, 'nc-music');
			return new JSONResponse(['success' => true]);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function albumDetails(int $albumId, string|int|bool|null $embedCoverArt = false) : JSONResponse {
		$embedCoverArt = \filter_var($embedCoverArt, FILTER_VALIDATE_BOOLEAN);
		try {
			$info = $this->lastfmService->getAlbumInfo($albumId, $this->user());
			if ($embedCoverArt && isset($info['album']['image'])) {
				$lastImage = \end($info['album']['image']);
				$imageSrc = $lastImage['#text'] ?? null;
				if (\is_string($imageSrc)) {
					$image = HttpUtil::loadFromUrl($imageSrc);
					if ($image['content']) {
						$info['album']['imageData'] = 'data:' . $image['content_type'] . ';base64,' . \base64_encode($image['content']);
					}
				}
			}
			return new JSONResponse($info);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function artistDetails(int $artistId) : JSONResponse {
		try {
			$info = $this->lastfmService->getArtistInfo($artistId, $this->user());
			return new JSONResponse($info);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function similarArtists(int $artistId) : JSONResponse {
		try {
			$similar = $this->lastfmService->getSimilarArtists($artistId, $this->user(), /*includeNotPresent=*/true);
			return new JSONResponse(\array_map(fn ($artist) => [
				'id'   => $artist->getId(),
				'name' => $artist->getName(),
				'url'  => $artist->getLastfmUrl()
			], $similar));
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}
}
