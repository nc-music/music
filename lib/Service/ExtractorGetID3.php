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
 * @copyright Pauli Järvinen 2016 - 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\Core\Logger;
use OCP\Files\File;
use OCP\IConfig;

/**
 * an extractor class for getID3
 */
class ExtractorGetID3 {

	private ?\getID3 $getID3 = null; // lazy-loaded

	public function __construct(
		private IConfig $config,
		private Logger $logger,
	) {
	}

	/**
	 * Second stage constructor used to lazy-load the getID3 library once it's needed.
	 * This is to prevent polluting the namespace of occ when the user is not running
	 * Music app commands.
	 * See https://github.com/nextcloud/server/issues/17027.
	 */
	private function initGetID3() : void {
		if ($this->getID3 === null) {
			require_once __DIR__ . '/../../3rdparty/getID3/getid3/getid3.php';
			$this->getID3 = new \getID3();
			$this->getID3->encoding = 'UTF-8';
			$this->getID3->option_tags_html = false; // HTML-encoded tags are not needed
			// On 32-bit systems, getid3 tries to make a 2GB size check,
			// which does not work with fopen. Disable it.
			// Therefore the filesize (determined by getID3) could be wrong
			// (for files over ~2 GB) but this isn't used in any way.
			$this->getID3->option_max_2gb_check = false;

			// Supported tag types may be configured in config.php. ID3v1 and Lyrics3 are disabled by default because
			// they are ancient and often produces incorrect results, especially with non-Latin scripts (because these
			// tags are supposed to be always in ISO-8859-1 but they have often been abused). Also, it may be cumbersome
			// to remove incorrect Lyrics3 tags from files because they are not supported by most tag editors.
			$this->getID3->option_tag_id3v1 = $this->getBooleanConfig('music.tag_enabled_id3v1', false);
			$this->getID3->option_tag_id3v2 = $this->getBooleanConfig('music.tag_enabled_id3v2', true);
			$this->getID3->option_tag_lyrics3 = $this->getBooleanConfig('music.tag_enabled_lyrics3', false);
			$this->getID3->option_tag_apetag = $this->getBooleanConfig('music.tag_enabled_ape', true);
		}
	}

	private function getBooleanConfig(string $key, bool $default) : bool {
		$value = $this->config->getSystemValue($key, $default);
		if (\is_string($value)) {
			return \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
		}
		return (bool)$value;
	}

	/**
	 * get metadata info for a media file
	 *
	 * @param File $file the file
	 * @return array extracted data
	 */
	public function extract(File $file) : array {
		$this->initGetID3();
		$metadata = [];

		try {
			// It would be pointless to try to analyze 0-byte files and it may cause problems when
			// the file is stored on a SMB share, see https://github.com/nc-music/oc-music/issues/600
			if ($file->getSize() > 0) {
				$metadata = $this->doExtract($file);
			}
		} catch (\Throwable $e) {
			$eClass = \get_class($e);
			$this->logger->error("Exception/Error $eClass when analyzing file {$file->getPath()}\n"
						. "Message: {$e->getMessage()}, Stack trace: {$e->getTraceAsString()}");
		}

		return $metadata;
	}

	private function doExtract(File $file) : array {
		\assert($this->getID3 !== null, 'initGetID3 must be called first');
		/** @var ?resource $fp */ // null value has been seen at least on some cloud versions although phpdoc of File::fopen doesn't allow it
		$fp = $file->fopen('r');

		if (empty($fp)) {
			// note: some of the file opening errors throw and others return a null fp
			$this->logger->error("Failed to open file {$file->getPath()} for metadata extraction");
			$metadata = [];
		} else {
			\mb_substitute_character(0x3F);
			$metadata = $this->getID3->analyze($file->getPath(), $file->getSize(), '', $fp);

			$this->getID3->CopyTagsToComments($metadata);

			// getID3 does not automatically fill the 'MusicBrainz Recording Id' comment tag from the UFID frame
			if (!isset($metadata['comments']['MusicBrainz Recording Id'])) {
				foreach ($metadata['id3v2']['UFID'] ?? [] as $ufid) {
					if (($ufid['ownerid'] ?? null) === 'http://musicbrainz.org') {
						$metadata['comments']['MusicBrainz Recording Id'] = [$ufid['data']];
						break;
					}
				}
			}

			// GetID3 copies incorrectly the multi-valued id3v2 tags involved_people_list and musician_credits_list; these have a structure
			// like [role1, name1, role2, name2, ...] where the order and possibly repeated roles and names are important but CopyTagsToComments
			// discards any duplicates. Reorganize the lists into associative arrays using the original tags as source.
			if (isset($metadata['tags']['id3v2']['involved_people_list'])) {
				$metadata['comments']['involved_people_list'] = self::parseId3ContributorList($metadata['tags']['id3v2']['involved_people_list']);
			}
			if (isset($metadata['tags']['id3v2']['musician_credits_list'])) {
				$metadata['comments']['musician_credits_list'] = self::parseId3ContributorList($metadata['tags']['id3v2']['musician_credits_list']);
			}

			// GetID3 doesn't split up null-delimited strings inside ID3v2.4 TXXX frames to multiple values while it apparently does it on
			// (at least some) other frames. Do that on our own and move the TXXX tags among the other tags from the `text` container.
			if (isset($metadata['tags']['id3v2']['text'])) {
				foreach ($metadata['tags']['id3v2']['text'] as $txxxKey => $txxxValue) {
					$metadata['comments'][$txxxKey] = \explode("\0", $txxxValue);
				}
				unset($metadata['comments']['text']);
			}

			if (isset($metadata['error'])) {
				foreach ($metadata['error'] as $error) {
					$this->logger->debug('getID3 error occurred');
					// sometimes $error is string but can't be concatenated to another string and weirdly just hide the log message
					$this->logger->debug('getID3 error message: ' . $error);
				}
			}
		}

		return $metadata;
	}

	/**
	 * extract embedded cover art image from media file
	 *
	 * @param File $file the media file
	 * @return ?array{image_mime: string, data: string}
	 */
	public function parseEmbeddedCoverArt(File $file) : ?array {
		$fileInfo = $this->extract($file);
		$pic = self::getTag($fileInfo, 'picture', true);
		\assert($pic === null || \is_array($pic));
		return $pic;
	}

	public static function getTag(array $fileInfo, string $tag, bool $binaryValued = false) : string|int|array|null {
		$value = $fileInfo['comments'][$tag][0] ?? null; // TODO: better handling for multi-valued tags

		if (\is_string($value) && !$binaryValued) {
			// Ensure that the tag contains only valid utf-8 characters.
			// Illegal characters may result, if the file metadata has a mismatch
			// between claimed and actual encoding. Invalid characters could break
			// the database update.
			\mb_substitute_character(0xFFFD); // Use the Unicode REPLACEMENT CHARACTER (U+FFFD)
			$value = \mb_convert_encoding($value, 'UTF-8', 'UTF-8');
		}

		return $value;
	}

	/**
	 * @param string[] $tags
	 */
	public static function getFirstOfTags(array $fileInfo, array $tags, string|array|null $defaultValue = null) : string|int|array|null {
		foreach ($tags as $tag) {
			$value = self::getTag($fileInfo, $tag);
			if ($value !== null && $value !== '') {
				return $value;
			}
		}
		return $defaultValue;
	}

	/**
	 * Given an array of tag names, return an associative array of those
	 * tag names and values which can be found.
	 */
	public static function getTags(array $fileInfo, array $tags) : array {
		$result = [];
		foreach ($tags as $tag) {
			$value = self::getTag($fileInfo, $tag);
			if ($value !== null && $value !== '') {
				$result[$tag] = $value;
			}
		}
		return $result;
	}

	/**
	 * @param array $contributors Flat list ['role1', 'name1a', 'role1', 'name1b', ...] from the ID3v2.4 frames
	 *                            TIPL and TMCL, or nested [[['position' => 'role1', 'person' => 'name1a'], ...]]
	 *                            from the ID3v2.3 frame IPLS, which GetID3 pairs up itself.
	 * @return array<string, string[]> E.g. ['role1' => ['name1a', 'name1b'], 'role2' => ['name2']]
	 */
	private static function parseId3ContributorList(array $contributors) : array {
		$result = [];

		// Any IPLS frame arrives nested and already paired up; the flat parsing below would use an array as an array key, fatal on PHP 8.
		if (\is_array($contributors[0] ?? null)) {
			foreach ($contributors as $frame) {
				foreach ($frame as $pair) {
					if (\is_array($pair)) {
						// a frame with an odd number of null-delimited parts yields role-less entries like ['name']
						$role = $pair['position'] ?? '';
						$name = $pair['person'] ?? $pair[0] ?? null;
					} else {
						// a frame with no null delimiters at all is split to bare names on the punctuation
						$role = '';
						$name = $pair;
					}
					if (\is_string($name) && $name !== '') {
						$result[$role][] = $name;
					}
				}
			}
			return $result;
		}

		while (\count($contributors)) {
			$role = \array_shift($contributors);
			$name = \array_shift($contributors);
			$result[$role][] = $name;
		}
		return $result;
	}
}
