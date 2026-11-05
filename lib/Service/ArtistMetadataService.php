<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\Music\Service;

use OCA\Music\Utility\AppInfo;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Shared artist metadata by MusicBrainz artist ID. Artist names are only used
 * as search hints; every TheAudioDB match must have the requested MBID.
 */
class ArtistMetadataService {
	private const COMPLETE_TTL = 30 * 86400;
	private const MISSING_TTL = 86400;
	private const MBID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
	private ?array $imageUrls = null;

	public function __construct(
		private IDBConnection $db,
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	/**
	 * Fetch fresh or cached info. Incomplete entries are retried after one day;
	 * --retry-missing bypasses that interval but leaves complete entries alone.
	 * Normal Subsonic requests never wait for a rate-limit reservation.
	 *
	 * @return array{biography: ?string, image_url: ?string}|array{}
	 */
	public function getInfo(?string $mbid, ?string $artistName = null, bool $waitForSlot = false, bool $retryMissing = false) : array {
		if ($mbid === null || !\preg_match(self::MBID_REGEX, $mbid)) {
			return [];
		}
		$mbid = \strtolower($mbid);
		$cached = $this->readCached($mbid);
		if (!self::shouldRefresh($cached, $retryMissing, \time())) {
			return self::visibleInfo($cached);
		}

		$checked = false;
		$audioDb = $this->lookupAudioDb($mbid, $artistName, $cached['audiodb_id'] ?? null, $waitForSlot, $checked);
		$fallback = [];
		if (empty($audioDb['biography']) || empty($audioDb['image_url'])) {
			$fallback = $this->lookupMusicBrainzAndWikimedia($mbid, $waitForSlot, $checked);
		}

		$info = [
			'biography' => $audioDb['biography'] ?? $cached['biography'] ?? $fallback['biography'] ?? null,
			'image_url' => $audioDb['image_url'] ?? $cached['image_url'] ?? $fallback['image_url'] ?? null,
			'audiodb_id' => $audioDb['audiodb_id'] ?? $cached['audiodb_id'] ?? null,
		];

		// Do not turn an API outage or a busy rate slot into a fresh negative cache entry.
		if ($checked) {
			$this->writeCached($mbid, $info, $cached !== null);
			if ($this->imageUrls !== null) {
				$this->imageUrls[$mbid] = $info['image_url'];
			}
		}
		return self::visibleInfo($info);
	}

	/**
	 * Read-only hot path for getArtists. A large response performs one database
	 * query rather than making a remote request or querying per artist.
	 */
	public function getCachedImageUrl(?string $mbid) : ?string {
		if ($mbid === null || !\preg_match(self::MBID_REGEX, $mbid)) {
			return null;
		}
		if ($this->imageUrls === null) {
			$this->imageUrls = [];
			$result = $this->db->executeQuery('SELECT `mbid`, `image_url` FROM `*PREFIX*music_artist_info`');
			foreach ($result->fetchAll() as $row) {
				$this->imageUrls[$row['mbid']] = $row['image_url'];
			}
			$result->closeCursor();
		}
		return $this->imageUrls[\strtolower($mbid)] ?? null;
	}

	/** @param ?array{biography: ?string, image_url: ?string, audiodb_id: ?string, fetched_at: int} $cached */
	public static function shouldRefresh(?array $cached, bool $retryMissing, int $now) : bool {
		if ($cached === null) {
			return true;
		}
		$missing = empty($cached['image_url']) || empty($cached['biography']);
		return ($missing && $retryMissing)
			|| ($now - $cached['fetched_at'] >= ($missing ? self::MISSING_TTL : self::COMPLETE_TTL));
	}

	/** @param ?array<string, mixed> $info
	 * @return array{biography: ?string, image_url: ?string}|array{}
	 */
	private static function visibleInfo(?array $info) : array {
		if ($info === null) {
			return [];
		}
		return ['biography' => $info['biography'], 'image_url' => $info['image_url']];
	}

	/**
	 * @param ?string $numericId A previously verified TheAudioDB idArtist.
	 * @return array{biography: ?string, image_url: ?string, audiodb_id: string}|array{}
	 */
	private function lookupAudioDb(string $mbid, ?string $name, ?string $numericId, bool $wait, bool &$checked) : array {
		$key = (string)$this->config->getSystemValue('music.audiodb_api_key', '123');
		if ($key === '' || !\ctype_digit($key)) {
			return [];
		}
		$prefix = 'https://www.theaudiodb.com/api/v1/json/' . $key . '/';
		$endpoints = [];
		if ($numericId !== null && self::validNumericId($numericId)) {
			$endpoints[] = 'artist.php?i=' . $numericId;
		}
		$endpoints[] = 'artist-mb.php?i=' . $mbid;
		if ($name !== null && \trim($name) !== '') {
			$endpoints[] = 'search.php?s=' . \rawurlencode(\trim($name));
		}

		foreach ($endpoints as $endpoint) {
			if (!$this->claimSlot(2, 3, $wait)) {
				break;
			}
			$response = $this->readJson($prefix . $endpoint);
			$checked = $checked || ($response !== null);
			$match = self::selectAudioDbArtist($response ?? [], $mbid);
			if ($match !== null) {
				return self::extractAudioDbMetadata($match);
			}
		}
		return [];
	}

	private static function validNumericId(string $id) : bool {
		return $id !== '' && \strlen($id) <= 32 && \ctype_digit($id);
	}

	/**
	 * Never accept artist-name matches without a confirmed MusicBrainz ID.
	 * @param array<string, mixed> $response
	 * @return ?array<string, mixed>
	 */
	public static function selectAudioDbArtist(array $response, string $mbid) : ?array {
		$artists = $response['artists'] ?? null;
		if (!\is_array($artists)) {
			return null;
		}
		foreach ($artists as $artist) {
			if (!\is_array($artist)) {
				continue;
			}
			$matchId = $artist['strMusicBrainzID'] ?? null;
			$id = $artist['idArtist'] ?? null;
			if (\is_string($matchId) && \strcasecmp(\trim($matchId), $mbid) === 0
				&& (\is_string($id) || \is_int($id)) && self::validNumericId((string)$id)) {
				return $artist;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $artist
	 * @return array{biography: ?string, image_url: ?string, audiodb_id: string}
	 */
	public static function extractAudioDbMetadata(array $artist) : array {
		$bio = self::shortText($artist['strBiographyEN'] ?? $artist['strBiography'] ?? null);
		$image = null;
		foreach (['strArtistThumb', 'strArtistWideThumb', 'strArtistFanart'] as $key) {
			if (self::isTrustedImageUrl($artist[$key] ?? null, ['www.theaudiodb.com', 'theaudiodb.com'])) {
				$image = $artist[$key];
				break;
			}
		}
		return ['biography' => $bio, 'image_url' => $image, 'audiodb_id' => (string)$artist['idArtist']];
	}

	/** @return array{biography: ?string, image_url: ?string}|array{} */
	private function lookupMusicBrainzAndWikimedia(string $mbid, bool $wait, bool &$checked) : array {
		if (!$this->claimSlot(1, 2, $wait)) {
			return [];
		}
		$musicBrainz = $this->readJson('https://musicbrainz.org/ws/2/artist/' . $mbid . '?inc=url-rels+annotation&fmt=json');
		$checked = $checked || ($musicBrainz !== null);
		if (($musicBrainz['id'] ?? null) !== $mbid) {
			return [];
		}
		$wikidataId = self::wikidataId($musicBrainz);
		$wikidata = $wikidataId === null ? null : $this->readJson(
			'https://www.wikidata.org/wiki/Special:EntityData/' . $wikidataId . '.json'
		);
		$entity = $wikidataId === null ? [] : ($wikidata['entities'][$wikidataId] ?? []);
		$title = $entity['sitelinks']['enwiki']['title'] ?? self::wikipediaTitle($musicBrainz);
		$wikipedia = \is_string($title) && $title !== '' ? $this->readJson(
			'https://en.wikipedia.org/api/rest_v1/page/summary/' . \rawurlencode(\str_replace(' ', '_', $title))
		) : null;
		return self::extractWikimediaMetadata($musicBrainz, $entity, $wikipedia);
	}

	/** @param array<string, mixed> $musicBrainz */
	private static function wikidataId(array $musicBrainz) : ?string {
		foreach ($musicBrainz['relations'] ?? [] as $relation) {
			$url = $relation['url']['resource'] ?? '';
			if (($relation['type'] ?? '') === 'wikidata'
				&& \preg_match('~^https?://(?:www\.)?wikidata\.org/wiki/(Q[1-9][0-9]*)$~', $url, $match)) {
				return $match[1];
			}
		}
		return null;
	}

	/** @param array<string, mixed> $musicBrainz */
	private static function wikipediaTitle(array $musicBrainz) : ?string {
		foreach ($musicBrainz['relations'] ?? [] as $relation) {
			$url = $relation['url']['resource'] ?? '';
			if (($relation['type'] ?? '') === 'wikipedia'
				&& \preg_match('~^https?://en\.wikipedia\.org/wiki/([^?#]+)$~', $url, $match)) {
				return \rawurldecode($match[1]);
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $musicBrainz
	 * @param array<string, mixed> $entity
	 * @param ?array<string, mixed> $wikipedia
	 * @return array{biography: ?string, image_url: ?string}
	 */
	public static function extractWikimediaMetadata(array $musicBrainz, array $entity, ?array $wikipedia) : array {
		if (($wikipedia['type'] ?? '') === 'disambiguation') {
			$wikipedia = null;
		}
		$bio = self::shortText($wikipedia['extract'] ?? null)
			?? self::shortText($musicBrainz['annotation'] ?? null)
			?? self::shortText($entity['descriptions']['en']['value'] ?? null);
		$image = $wikipedia['thumbnail']['source'] ?? $wikipedia['originalimage']['source'] ?? null;
		if (!self::isTrustedImageUrl($image, ['upload.wikimedia.org'])) {
			$image = null;
		}
		if ($image === null) {
			$filename = $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
			if (\is_string($filename) && $filename !== '') {
				$image = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . \rawurlencode($filename) . '?width=640';
			}
		}
		return ['biography' => $bio, 'image_url' => $image];
	}

	private static function shortText(mixed $text) : ?string {
		return \is_string($text) && \trim($text) !== '' ? \mb_substr(\trim($text), 0, 6000) : null;
	}

	/** @param string[] $hosts */
	private static function isTrustedImageUrl(mixed $url, array $hosts) : bool {
		if (!\is_string($url) || !\filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}
		$parsed = \parse_url($url);
		return ($parsed['scheme'] ?? '') === 'https' && \in_array($parsed['host'] ?? '', $hosts, true);
	}

	/** @return ?array{biography: ?string, image_url: ?string, audiodb_id: ?string, fetched_at: int} */
	private function readCached(string $mbid) : ?array {
		$result = $this->db->executeQuery(
			'SELECT `biography`, `image_url`, `audiodb_id`, `fetched_at` FROM `*PREFIX*music_artist_info` WHERE `mbid` = ?',
			[$mbid]
		);
		$rows = $result->fetchAll();
		$result->closeCursor();
		if (!$rows) {
			return null;
		}
		return [
			'biography' => $rows[0]['biography'],
			'image_url' => $rows[0]['image_url'],
			'audiodb_id' => $rows[0]['audiodb_id'],
			'fetched_at' => (int)$rows[0]['fetched_at'],
		];
	}

	/** Reserve a DB-backed rate slot shared across concurrent PHP workers. */
	private function claimSlot(int $id, int $spacingSeconds, bool $wait) : bool {
		do {
			$now = \time();
			$claimed = $this->db->executeStatement(
				'UPDATE `*PREFIX*music_artist_info_rate` SET `next_at` = ? WHERE `id` = ? AND `next_at` <= ?',
				[$now + $spacingSeconds, $id, $now]
			) === 1;
			if ($claimed || !$wait) {
				return $claimed;
			}
			\sleep(1);
		} while (true);
	}

	/** @param array{biography: ?string, image_url: ?string, audiodb_id: ?string} $info */
	private function writeCached(string $mbid, array $info, bool $probablyExists) : void {
		$values = [$info['biography'], $info['image_url'], $info['audiodb_id'], \time(), $mbid];
		if ($probablyExists) {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*music_artist_info` SET `biography` = ?, `image_url` = ?, `audiodb_id` = ?, `fetched_at` = ? WHERE `mbid` = ?',
				$values
			);
		} else {
			try {
				$this->db->executeStatement(
					'INSERT INTO `*PREFIX*music_artist_info` (`mbid`, `biography`, `image_url`, `audiodb_id`, `fetched_at`) VALUES (?, ?, ?, ?, ?)',
					[$mbid, $info['biography'], $info['image_url'], $info['audiodb_id'], \time()]
				);
			} catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
				$this->db->executeStatement(
					'UPDATE `*PREFIX*music_artist_info` SET `biography` = ?, `image_url` = ?, `audiodb_id` = ?, `fetched_at` = ? WHERE `mbid` = ?',
					$values
				);
			}
		}
	}

	/** @return ?array<string, mixed> */
	private function readJson(string $url) : ?array {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => 5,
				'connect_timeout' => 3,
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'nc-music/' . AppInfo::getVersion() . ' (https://github.com/nc-music/music)',
				],
			]);
			if ($response->getStatusCode() !== 200) {
				return null;
			}
			$data = \json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
			return \is_array($data) ? $data : null;
		} catch (\Throwable $e) {
			return null; // Optional public metadata must never interrupt playback.
		}
	}
}
