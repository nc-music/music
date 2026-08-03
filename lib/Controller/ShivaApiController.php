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

use OCA\Music\AppFramework\BusinessLayer\BusinessLayer;
use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Album;
use OCA\Music\Db\Artist;
use OCA\Music\Db\BaseMapper;
use OCA\Music\Db\SortBy;
use OCA\Music\Http\ErrorResponse;
use OCA\Music\Service\DetailsService;
use OCA\Music\Service\Scanner;
use OCA\Music\Utility\Random;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

class ShivaApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
		private TrackBusinessLayer $trackBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private AlbumBusinessLayer $albumBusinessLayer,
		private DetailsService $detailsService,
		private Scanner $scanner,
		private ?string $userId,
		private IL10N $l10n,
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

	private static function shivaPageToLimits(?int $pageSize, ?int $page) : array {
		if (\is_int($page) && \is_int($pageSize) && $page > 0 && $pageSize > 0) {
			$limit = $pageSize;
			$offset = ($page - 1) * $pageSize;
		} else {
			$limit = $offset = null;
		}
		return [$limit, $offset];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function artists(string|int|bool|null $fulltree, string|int|bool|null $albums, ?int $page_size = null, ?int $page = null) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		$includeAlbums = \filter_var($albums, FILTER_VALIDATE_BOOLEAN);
		[$limit, $offset] = self::shivaPageToLimits($page_size, $page);

		/** @var Artist[] $artists */
		$artists = $this->artistBusinessLayer->findAll($this->user(), SortBy::Name, $limit, $offset);

		$artists = \array_map(fn ($a) => $this->artistToApi($a, $includeAlbums || $fulltree, $fulltree), $artists);

		return new JSONResponse($artists);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function artist(int $id, string|int|bool|null $fulltree) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		try {
			/** @var Artist $artist */
			$artist = $this->artistBusinessLayer->find($id, $this->user());
			$artist = $this->artistToApi($artist, $fulltree, $fulltree);
			return new JSONResponse($artist);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Return given artist in Shia API format
	 * @param Artist $artist
	 * @param boolean $includeAlbums
	 * @param boolean $includeTracks (ignored if $includeAlbums==false)
	 * @return array
	 */
	private function artistToApi(Artist $artist, bool $includeAlbums, bool $includeTracks) : array {
		$artistInApi = $artist->toShivaApi($this->urlGenerator, $this->l10n);
		if ($includeAlbums) {
			$artistId = $artist->getId();
			$albums = $this->albumBusinessLayer->findAllByArtist($artistId, $this->user());

			$artistInApi['albums'] = \array_map(fn ($a) => $this->albumToApi($a, $includeTracks, false), $albums);
		}
		return $artistInApi;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function albums(?int $artist = null, string|int|bool|null $fulltree = null, ?int $page_size = null, ?int $page = null) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		[$limit, $offset] = self::shivaPageToLimits($page_size, $page);

		if ($artist !== null) {
			$albums = $this->albumBusinessLayer->findAllByArtist($artist, $this->user(), $limit, $offset);
		} else {
			$albums = $this->albumBusinessLayer->findAll($this->user(), SortBy::Name, $limit, $offset);
		}

		$albums = \array_map(fn ($a) => $this->albumToApi($a, $fulltree, $fulltree), $albums);

		return new JSONResponse($albums);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function album(int $id, string|int|bool|null $fulltree) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		try {
			$album = $this->albumBusinessLayer->find($id, $this->user());
			$album = $this->albumToApi($album, $fulltree, $fulltree);
			return new JSONResponse($album);
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Return given album in the Shiva API format
	 */
	private function albumToApi(Album $album, bool $includeTracks, bool $includeArtists) : array {
		$albumInApi = $album->toShivaApi($this->urlGenerator, $this->l10n);

		if ($includeTracks) {
			$albumId = $album->getId();
			$tracks = $this->trackBusinessLayer->findAllByAlbum($albumId, $this->user());
			$albumInApi['tracks'] = \array_map(fn ($t) => $t->toShivaApi($this->urlGenerator, $this->l10n), $tracks);
		}

		if ($includeArtists) {
			$artists = $album->getArtists() ?? [];
			$albumInApi['artists'] = \array_map(fn ($a) => $a->toShivaApi($this->urlGenerator, $this->l10n), $artists);
		}

		return $albumInApi;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function tracks(?int $artist = null, ?int $album = null, string|int|bool|null $fulltree = null, ?int $page_size = null, ?int $page = null) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		[$limit, $offset] = self::shivaPageToLimits($page_size, $page);

		if ($album !== null) {
			$tracks = $this->trackBusinessLayer->findAllByAlbum($album, $this->user(), $artist, $limit, $offset);
		} elseif ($artist !== null) {
			$tracks = $this->trackBusinessLayer->findAllByArtist($artist, $this->user(), $limit, $offset);
		} else {
			$tracks = $this->trackBusinessLayer->findAll($this->user(), SortBy::Name, $limit, $offset);
		}
		foreach ($tracks as &$track) {
			if ($fulltree) {
				$track->setArtist($this->artistBusinessLayer->find($track->getArtistId(), $this->user()));
				$track->setAlbum($this->albumBusinessLayer->find($track->getAlbumId(), $this->user()));
			}
			$track = $track->toShivaApi($this->urlGenerator, $this->l10n);
		}
		return new JSONResponse($tracks);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function track(int $id, string|int|bool|null $fulltree = null) : JSONResponse {
		$fulltree = \filter_var($fulltree, FILTER_VALIDATE_BOOLEAN);
		try {
			$track = $this->trackBusinessLayer->find($id, $this->user());

			if ($fulltree) {
				$track->setArtist($this->artistBusinessLayer->find($track->getArtistId(), $this->user()));
				$track->setAlbum($this->albumBusinessLayer->find($track->getAlbumId(), $this->user()));
			}

			return new JSONResponse($track->toShivaApi($this->urlGenerator, $this->l10n));
		} catch (BusinessLayerException $e) {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function trackLyrics(int $id) : JSONResponse {
		try {
			$track = $this->trackBusinessLayer->find($id, $this->user());
			$fileId = $track->getFileId();
			$userFolder = $this->scanner->resolveUserFolder($this->user());
			if ($this->detailsService->hasLyrics($fileId, $userFolder)) {
				/**
				 * The Shiva API has been designed around the idea that lyrics would be scraped from an external
				 * source and never stored on the Shiva server. We, on the other hand, support only lyrics embedded
				 * in the audio file tags and this makes the Shiva lyrics API quite a poor fit. Here we anyway
				 * create a result which is compatible with the Shiva API specification.
				 */
				return new JSONResponse([
					'track'      => $this->entityIdAndUri($id, 'track'),
					'source_uri' => '',
					'id'         => $fileId,
					'uri'        => $this->urlGenerator->linkToRoute(
						'music.musicApi.fileLyrics',
						['fileId' => $fileId, 'format' => 'plaintext']
					)
				]);
			}
		} catch (BusinessLayerException $e) {
			// nothing
		}
		return new ErrorResponse(Http::STATUS_NOT_FOUND);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function randomArtist() : JSONResponse {
		return $this->randomItem($this->artistBusinessLayer, 'artist');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function randomAlbum() : JSONResponse {
		return $this->randomItem($this->albumBusinessLayer, 'album');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function randomTrack() : JSONResponse {
		return $this->randomItem($this->trackBusinessLayer, 'track');
	}

	/** @phpstan-param BusinessLayer<*> $businessLayer */
	private function randomItem(BusinessLayer $businessLayer, string $type) : JSONResponse {
		$ids = $businessLayer->findAllIds($this->user());
		$id = Random::pickItem($ids);

		if ($id !== null) {
			return new JSONResponse($this->entityIdAndUri($id, $type));
		} else {
			return new ErrorResponse(Http::STATUS_NOT_FOUND);
		}
	}

	private function entityIdAndUri(int $id, string $type) : array {
		return [
			'id'  => $id,
			'uri' => $this->urlGenerator->linkToRoute("music.shivaApi.$type", ['id' => $id])
		];
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function latestItems(?string $since) : JSONResponse {
		if ($since === null) {
			$dateTime = new \DateTime('7 days ago');
			$since = $dateTime->format(BaseMapper::SQL_DATE_FORMAT);
		}

		$searchRules = [['rule' => 'added', 'operator' => 'after', 'input' => $since]];

		$artists = $this->artistBusinessLayer->findAllAdvanced('and', $searchRules, $this->user());
		$albums = $this->albumBusinessLayer->findAllAdvanced('and', $searchRules, $this->user());
		$tracks = $this->trackBusinessLayer->findAllAdvanced('and', $searchRules, $this->user());

		return new JSONResponse([
			'artists' => \array_map(fn ($a) => $this->entityIdAndUri($a->getId(), 'artist'), $artists),
			'albums'  => \array_map(fn ($a) => $this->entityIdAndUri($a->getId(), 'album'), $albums),
			'tracks'  => \array_map(fn ($t) => $this->entityIdAndUri($t->getId(), 'track'), $tracks)
		]);
	}
}
