<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * @author ipoupaille
 * @copyright MIT
 */

namespace OCA\Music\Http;

use OCA\Music\AppFramework\Core\Logger;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use RuntimeException;

opcache_invalidate(__FILE__, true);

/**
 * A renderer for files with ffmpeg transcoding
 */
final class AudioTranscodeResponse extends Response implements ICallbackResponse
{
	// Formats
	public const MP3 = "mp3";
	public const OGG = "ogg";
	public const OPUS = "opus";
	public const AAC = "aac";
	public const M4A = "m4a";

	public static function getMimetype(string $format): ?string
	{
		return match ($format) {
			self::OGG => "audio/ogg",
			self::OPUS => "audio/ogg; codecs=opus",
			self::AAC => "audio/aac",
			self::M4A => "audio/mp4",
			self::MP3 => "audio/mpeg",
			default => null,
		};
	}

	public function __construct(
		private readonly string $ffmpegPath,
		private readonly Logger $logger,
		private readonly File $file,
		private readonly string $outputFormat,
		private readonly ?int $bitrate,
	) {
		parent::__construct();
		if (
			isset($_SERVER["HTTP_RANGE"]) &&
			$_SERVER["HTTP_RANGE"] != "bytes=0-"
		) {
			$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
		} else {
			$contentType = self::getMimetype($outputFormat);
			$this->addHeader("Content-Type", $contentType);
			$this->setStatus(Http::STATUS_OK);
		}
	}

	public function callback(IOutput $output): void
	{
		$status = $this->getStatus();
		if ($status != Http::STATUS_OK) {
			return;
		}
		$command = $this->buildCommand();

		$descriptorSpec = [
			0 => ["pipe", "r"],
			1 => ["pipe", "w"],
			2 => ["pipe", "w"],
		];

		ignore_user_abort(true);
		$process = proc_open($command, $descriptorSpec, $pipes, null, null, [
			"bypass_shell" => true,
		]);

		if (!is_resource($process)) {
			throw new RuntimeException("Unable to start ffmpeg process");
		}

		fclose($pipes[0]);

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stderr = "";

		try {
			while (true) {
				$read = [];

				if (!feof($pipes[1])) {
					$read[] = $pipes[1];
				}

				if (!feof($pipes[2])) {
					$read[] = $pipes[2];
				}

				if ($read === []) {
					break;
				}

				$write = null;
				$except = null;

				$selected = stream_select($read, $write, $except, 1, 0);

				if ($selected === false) {
					throw new RuntimeException(
						"Error while reading FFmpeg output",
					);
				}

				if ($selected === 0) {
					if (connection_aborted()) {
						proc_terminate($process);
						break;
					}
					continue;
				}

				foreach ($read as $stream) {
					$data = fread($stream, 65536);

					if ($data === false || $data === "") {
						continue;
					}

					if ($stream === $pipes[1]) {
						echo $data;

						if (ob_get_level() > 0) {
							ob_flush();
						}

						flush();
					} else {
						$stderr .= $data;

						if (strlen($stderr) > 65536) {
							$stderr = substr($stderr, -65536);
						}
					}
				}

				if (connection_aborted()) {
					proc_terminate($process);
					break;
				}
			}
		} finally {
			if (is_resource($pipes[1])) {
				fclose($pipes[1]);
			}

			if (is_resource($pipes[2])) {
				$remainingStderr = stream_get_contents($pipes[2]);

				if (is_string($remainingStderr)) {
					$stderr .= $remainingStderr;
				}
				fclose($pipes[2]);
			}

			$exitCode = proc_close($process);

			if ($exitCode !== 0 && !connection_aborted()) {
				$this->logger->error(
					"FFmpeg audio transcoding failed with code $exitCode",
				);
			}
			ignore_user_abort(false);
		}
	}

	private function buildCommand(): array
	{
		[$codec, $container, $extra] = match ($this->outputFormat) {
			self::OGG => ["libvorbis", "ogg", []],
			self::OPUS => ["libopus", "ogg", []],
			self::AAC => ["aac", "adts", []],
			self::M4A => [
				"aac",
				"mp4",
				["-movflags", "frag_keyframe+empty_moov+default_base_moof"],
			],
			self::MP3 => ["libmp3lame", "mp3", []],
		};

		$filePath = $this->file
			->getStorage()
			->getLocalFile($this->file->getInternalPath());

		return [
			$this->ffmpegPath,
			"-nostdin",
			"-hide_banner",
			"-loglevel",
			"error",
			"-i",
			$filePath,
			"-vn",
			"-map",
			"0:a:0",
			"-map_metadata",
			"-1",
			"-c:a",
			$codec,
			...$this->bitrate == null || $this->bitrate == 0
				? []
				: ["-b:a", $this->bitrate . "k"],
			...$extra,
			"-f",
			$container,
			"pipe:1",
		];
	}
}
