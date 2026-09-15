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

namespace OCA\Music\Http;

use OCA\Music\AppFramework\Core\Logger;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;

/**
 * A renderer for files
 */
class FileStreamResponse extends Response implements ICallbackResponse {
	private const COPY_CHUNK_SIZE = 65536;

	private File $file;
	private Logger $logger;
	private int $start;
	private int $end;
	/** True when the size of the file is not known in advance and can't be declared in the headers */
	private bool $unknownSize;

	public function __construct(File $file, Logger $logger) {

		$this->file = $file;
		$this->logger = $logger;
		$mime = $file->getMimetype();
		$size = $file->getSize();
		$this->start = 0;
		$this->end = $size - 1;
		$this->unknownSize = ($size <= 0);

		$this->addHeader('Content-type', "$mime; charset=utf-8");

		if ($this->unknownSize) {
			// The size may be missing from the file cache e.g. on an encrypted storage which has not recorded
			// the unencrypted size. Streaming without declaring the length is better than refusing the request,
			// which is what the range arithmetic below would end up doing with a non-positive size.
			$this->logger->warning("Unknown size for the file {$file->getId()}, streaming without Content-Length");
			$this->setStatus(Http::STATUS_OK);
		} elseif (isset($_SERVER['HTTP_RANGE'])) {
			// Note that we do not support Range Header of the type
			// bytes=x-y,z-w
			if (!\preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE'])) {
				$this->addHeader('Content-Range', 'bytes */' . $size);
				$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
			} else {
				$parts = \explode('-', \substr($_SERVER['HTTP_RANGE'], 6));
				$this->start = ($parts[0] != '') ? (int)$parts[0] : 0;
				$this->end = ($parts[1] != '') ? (int)$parts[1] : $size - 1;
				$this->end = \min($this->end, $size - 1);

				if ($this->start > $this->end) {
					$this->addHeader('Content-Range', "bytes */$size");
					$this->setStatus(Http::STATUS_REQUEST_RANGE_NOT_SATISFIABLE);
				} else {
					$this->addHeader('Accept-Ranges', 'bytes');
					$this->addHeader('Content-Range', "bytes {$this->start}-{$this->end}/$size");
					$this->addHeader('Content-Length', (string)($this->end - $this->start + 1));
					$this->setStatus(Http::STATUS_PARTIAL_CONTENT);
				}
			}
		} else {
			$this->addHeader('Content-Length', (string)$size);
			$this->setStatus(Http::STATUS_OK);
		}
	}

	/**
	 * @return void
	 */
	public function callback(IOutput $output) {
		$status = $this->getStatus();

		if ($status === Http::STATUS_OK || $status === Http::STATUS_PARTIAL_CONTENT) {
			try {
				$fp = $this->file->fopen('r');
			} catch (\Throwable $e) {
				// With the per-user-key server-side encryption, the file can be decrypted only within a cloud
				// login session which has unlocked the private key of the user. The Ampache and Subsonic clients
				// authenticate with an API key and have no such session, so the decryption fails for them.
				$this->logger->error("Failed to open the file {$this->file->getId()} for reading: {$e->getMessage()}");
				$output->setHttpResponseCode(Http::STATUS_FORBIDDEN);
				return;
			}

			if (!\is_resource($fp)) {
				$output->setHttpResponseCode(Http::STATUS_NOT_FOUND);
			} else {
				if ($this->streamDataToOutput($fp) === false) {
					$output->setHttpResponseCode(Http::STATUS_BAD_REQUEST);
				}
				\fclose($fp);
			}
		}
	}

	/**
	 * @param resource $fp File handle
	 */
	private function streamDataToOutput($fp) : bool {
		// Request Range Not Satisfiable
		if (!$this->unknownSize && $this->start > $this->end) {
			return false;
		}

		$outputStream = \fopen('php://output', 'w');
		if (!\is_resource($outputStream)) {
			return false;
		}

		if ($this->unknownSize) {
			$bytesCopied = \stream_copy_to_stream($fp, $outputStream);
		} else {
			$length = $this->end - $this->start + 1;

			// Don't let stream_copy_to_stream() do the seeking. On the wrapped streams of the encrypted and the
			// SMB storages, the seek may fail, and the copying would then silently start from the wrong offset.
			if (!self::seekTo($fp, $this->start)) {
				\fclose($outputStream);
				$this->logger->error("Failed to seek to the offset {$this->start} of the file {$this->file->getId()}");
				return false;
			}

			$bytesCopied = \stream_copy_to_stream($fp, $outputStream, $length);

			if ($bytesCopied !== $length) {
				// The declared size comes from the file cache and may not match the actual decrypted content,
				// in which case the client gets a truncated or a stalled stream.
				$this->logger->warning("Streamed $bytesCopied bytes of the file {$this->file->getId()} while "
									. "$length bytes were declared in the response headers");
			}
		}

		\fclose($outputStream);
		return ($bytesCopied > 0);
	}

	/**
	 * Move the file handle to the given offset. Streams which don't support seeking are advanced by reading
	 * and discarding the leading bytes.
	 * @param resource $fp File handle
	 */
	private static function seekTo($fp, int $offset) : bool {
		if ($offset === 0 || \fseek($fp, $offset) === 0) {
			return true;
		}

		$remaining = $offset;
		while ($remaining > 0 && !\feof($fp)) {
			$chunk = \fread($fp, \min($remaining, self::COPY_CHUNK_SIZE));
			if ($chunk === false || $chunk === '') {
				return false;
			}
			$remaining -= \strlen($chunk);
		}

		return ($remaining <= 0);
	}

}
