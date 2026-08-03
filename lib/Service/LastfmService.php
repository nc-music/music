<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\BusinessLayer\BusinessLayerException;
use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\BusinessLayer\AlbumBusinessLayer;
use OCA\Music\BusinessLayer\ArtistBusinessLayer;
use OCA\Music\BusinessLayer\TrackBusinessLayer;
use OCA\Music\Db\Artist;
use OCA\Music\Db\MatchMode;
use OCA\Music\Db\Track;
use OCA\Music\Utility\HttpUtil;
use OCA\Music\Utility\StringUtil;
use OCP\IConfig;

class LastfmService {

	private const LASTFM_URL = 'http://ws.audioscrobbler.com/2.0/';

	private string $apiKey;

	public function __construct(
		IConfig $config,
		private AlbumBusinessLayer $albumBusinessLayer,
		private ArtistBusinessLayer $artistBusinessLayer,
		private TrackBusinessLayer $trackBusinessLayer,
		private Logger $logger,
	) {
		$this->apiKey = $config->getSystemValue('music.lastfm_api_key');
	}

	/**
	 * @throws BusinessLayerException if artist with the given ID is not found
	 */
	public function getArtistInfo(int $artistId, string $userId) : array {
		$artist = $this->artistBusinessLayer->find($artistId, $userId);

		if ($artist->getName() === null) {
			return $this->errorResponse("Can't get details for an unknown artist");
		} else {
			$result = $this->getInfoFromLastFm([
				'method' => 'artist.getInfo',
				'artist' => $artist->getName(),
				'mbid'   => $artist->getMbid(),
			]);

			// add ID to those similar artists which can be found from the library
			$similar = $result['artist']['similar']['artist'] ?? null;
			if ($similar !== null) {
				$result['artist']['similar']['artist'] = \array_map(function ($lastfmArtist) use ($userId) {
					$matching = $this->artistBusinessLayer->findAllByName($lastfmArtist['name'], $userId);
					if (!empty($matching)) {
						$lastfmArtist['id'] = $matching[0]->getId();
					}
					return $lastfmArtist;
				}, $similar);
			}

			return $result;
		}
	}

	/**
	 * @throws BusinessLayerException if album with the given ID is not found
	 */
	public function getAlbumInfo(int $albumId, string $userId) : array {
		$album = $this->albumBusinessLayer->find($albumId, $userId);

		if ($album->getName() === null) {
			return $this->errorResponse("Can't get details for an unknown album");
		} else {
			return $this->getInfoFromLastFm([
				'method' => 'album.getInfo',
				'artist' => $album->getAlbumArtistName(),
				'album'  => $album->getName(),
				'mbid'   => $album->getMbid(),
			]);
		}
	}

	/**
	 * @throws BusinessLayerException if track with the given ID is not found
	 */
	public function getTrackInfo(int $trackId, string $userId) : array {
		$track = $this->trackBusinessLayer->find($trackId, $userId);
		return $this->findTrackInfo($track->getTitle(), $track->getArtistName() ?? '', $track->getMbidRelTrack());
	}

	public function findTrackInfo(string $trackTitle, string $artistName, ?string $mbid = null) : array {
		return $this->getInfoFromLastFm([
			'method'      => 'track.getInfo',
			'artist'      => $artistName,
			'track'       => $trackTitle,
			'mbid'        => $mbid,
			'autocorrect' => 1,
		]);
	}

	/**
	 * Get artists from the user's library similar to the given artist
	 * @param bool $includeNotPresent When true, the result may include also artists which
	 *                                are not found from the user's music library. Such
	 *                                artists have many fields including `id` set as null.
	 * @return Artist[]
	 * @throws BusinessLayerException if artist with the given ID is not found
	 */
	public function getSimilarArtists(int $artistId, string $userId, $includeNotPresent = false) : array {
		$artist = $this->artistBusinessLayer->find($artistId, $userId);

		$similarOnLastfm = $this->getInfoFromLastFm([
			'method' => 'artist.getSimilar',
			'artist' => $artist->getName(),
			'mbid'   => $artist->getMbid(),
		]);

		$result = [];
		$similarArr = $similarOnLastfm['similarartists']['artist'] ?? null;
		if ($similarArr !== null) {
			foreach ($similarArr as $lastfmArtist) {
				$matchingLibArtists = $this->artistBusinessLayer->findAllByName($lastfmArtist['name'], $userId);

				if (!empty($matchingLibArtists)) {
					foreach ($matchingLibArtists as $matchArtist) { // loop although there really shouldn't be more than one
						$matchArtist->setLastfmUrl($lastfmArtist['url']);
					}
					$result = \array_merge($result, $matchingLibArtists);
				} elseif ($includeNotPresent) {
					$unfoundArtist = new Artist();
					$unfoundArtist->setName($lastfmArtist['name'] ?? null);
					$unfoundArtist->setMbid($lastfmArtist['mbid'] ?? null);
					$unfoundArtist->setLastfmUrl($lastfmArtist['url'] ?? null);
					$result[] = $unfoundArtist;
				}
			}
		}

		return $result;
	}

	/**
	 * Get tracks from the user's library similar to the given track
	 * @return Track[]
	 * @throws BusinessLayerException if track with the given ID is not found
	 */
	public function getSimilarTracks(int $trackId, string $userId) : array {
		$track = $this->trackBusinessLayer->find($trackId, $userId);

		$similarOnLastfm = $this->getInfoFromLastFm([
			'method' => 'track.getSimilar',
			'track'  => $track->getTitle(),
			'artist' => $track->getArtistName(),
			'mbid'   => $track->getMbidRelTrack(),
		]);

		$result = [];
		$similarArr = $similarOnLastfm['similartracks']['track'] ?? null;
		if ($similarArr !== null) {
			foreach ($similarArr as $lastfmTrack) {
				$matchingLibTracks = $this->trackBusinessLayer->findAllByNameArtistOrAlbum(
					$lastfmTrack['name'], $lastfmTrack['artist']['name'], null, $userId);
				$result = \array_merge($result, $matchingLibTracks);
			}
		}

		return $result;
	}

	/**
	 * Get artist tracks from the user's library, sorted by their popularity on Last.fm
	 * @param int|string $artistIdOrName Either the ID of the artist or the artist's name written exactly
	 *                                   like in the DB. Any integer-typed value is treated as an ID and
	 *                                   string-typed value as a name.
	 * @param int $maxCount Number of tracks to request from Last.fm. Note that the function may return much
	 *                      less tracks if the top tracks from Last.fm are not present in the user's library.
	 * @return Track[]
	 */
	public function getTopTracks(int|string $artistIdOrName, string $userId, int $maxCount) : array {
		$foundTracks = [];

		if (\is_integer($artistIdOrName)) {
			$artist = $this->artistBusinessLayer->find($artistIdOrName, $userId);
		} else {
			$artist = $this->artistBusinessLayer->findAllByName($artistIdOrName, $userId, MatchMode::Exact, /*$limit=*/1)[0] ?? null;
		}

		if ($artist !== null) {
			$lastfmResult = $this->getInfoFromLastFm([
				'method' => 'artist.getTopTracks',
				'artist' => $artist->getName(),
				'mbid'   => $artist->getMbid(),
				'limit'  => (string)$maxCount,
			]);
			$topTracksOnLastfm = $lastfmResult['toptracks']['track'] ?? null;

			if ($topTracksOnLastfm !== null) {
				$libTracks = $this->trackBusinessLayer->findAllByArtist($artist->getId(), $userId);

				foreach ($topTracksOnLastfm as $lastfmTrack) {
					foreach ($libTracks as $libTrack) {
						if (\mb_strtolower($lastfmTrack['name']) === \mb_strtolower($libTrack->getTitle())) {
							$foundTracks[] = $libTrack;
							break;
						}
					}
				}
			}
		}

		return $foundTracks;
	}

	private function getInfoFromLastFm(array $args) : array {
		if (empty($this->apiKey)) {
			return ['api_key_set' => false];
		} else {
			// append the standard args
			$args['api_key'] = $this->apiKey;
			$args['format'] = 'json';

			// remove args with null or empty values
			$args = \array_filter($args, [StringUtil::class, 'isNonEmptyString']);

			// glue arg keys and values together ...
			$argStrings = \array_map(fn ($key, $value) => ($key . '=' . \urlencode($value)), \array_keys($args), $args);
			// ... and form the final query string
			$queryString = '?' . \implode('&', $argStrings);

			['content' => $info, 'status_code' => $statusCode, 'message' => $msg] = HttpUtil::loadFromUrl(self::LASTFM_URL . $queryString);

			if ($info === false) {
				// When an album is not found, Last.fm returns 404 but that is not a sign of broken connection.
				// Interestingly, not finding an artist is still responded with the code 200.
				$info = ['connection_ok' => ($statusCode === 404)];
			} else {
				$info = \json_decode($info, true);
				$info['connection_ok'] = true;
			}
			$info['status_code'] = $statusCode;
			$info['status_msg'] = $msg;
			$info['api_key_set'] = true;

			// Sometimes passing an MBID to Last.fm makes it unable to find the artist/album/track even if it is present in the library.
			// In such cases, we try again without the MBID.
			if (isset($args['mbid']) && ($statusCode === 404 || ($info['error'] === 6))) {
				$this->logger->info("Last.fm didn't find the item with MBID {$args['mbid']}. Retrying without MBID.");
				unset($args['mbid']);
				return $this->getInfoFromLastFm($args);
			} else {
				return $info;
			}
		}
	}

	private function errorResponse(string $message) : array {
		return [
			'api_key_set'   => !empty($this->apiKey),
			'connection_ok' => 'unknown',
			'status_code'   => -1,
			'status_msg'    => $message
		];
	}
}
