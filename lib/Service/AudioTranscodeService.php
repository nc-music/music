<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service;

use OCA\Music\AppFramework\Core\Logger;
use OCA\Music\Db\Track;
use OCA\Music\Http\AudioTranscodeResponse;
use OCA\Music\Http\FileStreamResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IBinaryFinder;
use OCP\IConfig;

/**
 * Decides if a song needs to be transcoded to answer a `format`/`bitrate` request, and builds the
 * response for it, using ffmpeg if it's available. Shared between the Ampache and Subsonic APIs.
 */
class AudioTranscodeService {
	private string|false $ffmpegPath;

	public function __construct(
			private Logger $logger,
			IConfig $config,
			IBinaryFinder $binaryFinder) {
		$useFfmpeg = $config->getSystemValue('music.use_ffpmeg', true);
		if ($useFfmpeg === false) {
			$this->ffmpegPath = false;
		} elseif (\is_string($useFfmpeg)) {
			$this->ffmpegPath = $useFfmpeg;
		} else {
			$this->ffmpegPath = $binaryFinder->findBinaryPath('ffmpeg');
		}
	}

	public function canTranscode(Track $track, ?string $format, ?int $maxBitrate) : bool {
		// no ffmpeg or disabled
		if (!$this->ffmpegPath) {
			return false;
		}
		// raw format asked
		if ($format === 'raw') {
			return false;
		}
		// no format specified and no max bitrate => can use original
		if ($format === null && ($maxBitrate === 0 || $maxBitrate === null)) {
			return false;
		}
		// calculate if has same mimetype
		$sameMimetype = true;
		// if format is not defined, consider that mimetype is same
		if ($format !== null && $format !== '') {
			$mimeType = AudioTranscodeResponse::getMimetype($format);
			if ($mimeType !== null) {
				$sameMimetype = \str_starts_with($mimeType, $track->getMimetype());
			}
		}
		// if same format, and bitrate is compatible => no need to transcode
		if ($sameMimetype && ($maxBitrate === null || $maxBitrate === 0 || $track->getBitrate() <= $maxBitrate * 1000)) {
			return false;
		}
		return true;
	}

	/**
	 * Build the response to stream/download the given track, transcoding it on the fly with ffmpeg when
	 * needed and possible, or falling back to the original file otherwise.
	 */
	public function responseForTrack(Track $track, File $file, ?string $format, ?int $maxBitrate) : Response {
		if ($this->canTranscode($track, $format, $maxBitrate)) {
			\assert($this->ffmpegPath !== false);
			return new AudioTranscodeResponse($this->ffmpegPath, $this->logger, $file, $format, $maxBitrate);
		}
		return new FileStreamResponse($file);
	}
}
