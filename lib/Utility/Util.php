<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */

namespace OCA\Music\Utility;

/**
 * Miscellaneous static utility functions
 */
class Util {

	public const UINT32_MAX = 0xFFFFFFFF;
	public const SINT32_MAX = 0x7FFFFFFF;
	public const SINT32_MIN = -self::SINT32_MAX - 1;

	/**
	 * Like the built-in \explode(...) function but this one can be safely called with
	 * null string, and no warning will be emitted. Also, this returns an empty array from
	 * null and '' inputs while the built-in alternative returns a 1-item array containing
	 * an empty string.
	 * @param string $delimiter
	 * @param string|null $string
	 * @return string[]
	 */
	public static function explode(string $delimiter, ?string $string) : array {
		if ($delimiter === '') {
			throw new \UnexpectedValueException();
		} elseif ($string === null || $string === '') {
			return [];
		} else {
			return \explode($delimiter, $string);
		}
	}

	/**
	 * Convert file size given in bytes to human-readable format
	 */
	public static function formatFileSize(?int $bytes, int $decimals = 1) : ?string {
		if ($bytes === null) {
			return null;
		} else {
			$units = 'BKMGTP';
			$factor = \floor((\strlen((string)$bytes) - 1) / 3);
			return \sprintf("%.{$decimals}f", $bytes / \pow(1024, $factor)) . @$units[(int)$factor];
		}
	}

	/**
	 * Convert time given as seconds to the HH:MM:SS format
	 */
	public static function formatTime(?int $seconds) : ?string {
		if ($seconds === null) {
			return null;
		} else {
			return \sprintf('%02d:%02d:%02d', ($seconds / 3600), ($seconds / 60 % 60), $seconds % 60);
		}
	}

	/**
	 * Convert date and time to the ISO UTC "Zulu format" e.g. "2021-08-19T19:33:15Z". The date and time may be passed
	 * as a DateTime object or any string format accepted by the DateTime constructor.
	 */
	public static function formatZuluDateTime(string|\DateTime|null $dateTime) : ?string {
		if ($dateTime === null) {
			return null;
		} else {
			if (\is_string($dateTime)) {
				$dateTime = new \DateTime($dateTime);
			}
			return $dateTime->format('Y-m-d\TH:i:s.v\Z');
		}
	}

	/**
	 * Convert date and time given in the SQL format to the ISO UTC "offset format" e.g. "2021-08-19T19:33:15+00:00"
	 */
	public static function formatDateTimeUtcOffset(?string $dbDateString) : ?string {
		if ($dbDateString === null) {
			return null;
		} else {
			$dateTime = new \DateTime($dbDateString);
			return $dateTime->format('c');
		}
	}

	/**
	 * Encode a file path so that it can be used as part of a WebDAV URL
	 */
	public static function urlEncodePath(string $path) : string {
		// URL encode each part of the file path
		return \join('/', \array_map('rawurlencode', \explode('/', $path)));
	}

	/**
	 * Compose URL from parts as returned by the system function parse_url.
	 * Based on https://stackoverflow.com/a/35207936
	 * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, query?: string, path?: string, fragment?: string} $parts
	 */
	public static function buildUrl(array $parts) : string {
		return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '')
			. ((isset($parts['user']) || isset($parts['host'])) ? '//' : '')
			. ($parts['user'] ?? '')
			. (isset($parts['pass']) ? ":{$parts['pass']}" : '')
			. (isset($parts['user']) ? '@' : '')
			. ($parts['host'] ?? '')
			. (isset($parts['port']) ? ":{$parts['port']}" : '')
			. ($parts['path'] ?? '')
			. (isset($parts['query']) ? "?{$parts['query']}" : '')
			. (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
	}

	/**
	 * Given a potentially relative target URL and the current URL, return the absolute URL to the target.
	 * If the target URL is already absolute, it is returned as-is.
	 */
	public static function urlToAbsolute(string $targetUrl, string $currentUrl) : string {
		if (\parse_url($targetUrl, PHP_URL_SCHEME) === null) {
			$urlParts = \parse_url($currentUrl);

			if ($targetUrl[0] == '/') {
				// the target URL is absolute to the server root => keep only the scheme and host from the current URL
				$urlParts['path'] = $targetUrl;
			} else {
				// the target URL is relative to the current URL => keep the path up to the last '/' and append the target URL
				$path = $urlParts['path'] ?? '/';
				$lastSlash = \strrpos($path, '/');
				$urlParts['path'] = \substr($path, 0, $lastSlash + 1) . $targetUrl;
			}
			unset($urlParts['query'], $urlParts['fragment']);

			$targetUrl = self::buildUrl($urlParts);
		}
		return $targetUrl;
	}

	/**
	 * Swap values of two variables in place
	 */
	public static function swap(mixed &$a, mixed &$b) : void {
		$temp = $a;
		$a = $b;
		$b = $temp;
	}

	/**
	 * Limit an integer value between the specified minimum and maximum.
	 * A null value is a valid input and will produce a null output.
	 */
	public static function limit(int|float|null $input, int|float $min, int|float $max) : int|float|null {
		if ($input === null) {
			return null;
		} else {
			return \max($min, \min($input, $max));
		}
	}

	/**
	 * Encode an application version string into a single integer for easier comparison and storage.
	 * The encoding is done as follows:
	 * - major version is multiplied by 1,000,000
	 * - minor version is multiplied by 10,000
	 * - patch version is multiplied by 100
	 * - pre-release versions (alpha, beta, rc) are encoded as negative offsets:
	 *   - alpha: -90 + pre-release version number
	 *   - beta: -60 + pre-release version number
	 *   - rc: -30 + pre-release version number
	 *   - no pre-release: 0
	 * The final encoded version is the sum of these components.
	 * There is room for 100 major versions, 100 minor versions, 100 patch versions, and 30+30+30 pre-release versions (alpha + beta + rc).
	 *
	 * Examples of the encoded version format:
	 * 0.0.1-alpha0 => 00000010
	 * 0.0.1-beta5 => 00000045
	 * 0.0.1-rc2 => 00000072
	 * 0.0.1 => 00000100
	 * 0.1.0 => 00010000
	 * 1.0.0 => 01000000
	 * 1.2.3 => 01020300
	 * 1.2.3-rc1 => 01020271
	 */
	public static function encodeVersionString(string $versionString) : int {
		$versionParts = \explode('.', $versionString);
		$major = (int)($versionParts[0] ?? 0);
		$minor = (int)($versionParts[1] ?? 0);
		$patch = (int)($versionParts[2] ?? 0);

		if (\str_contains($versionString, 'alpha')) {
			$preReleaseOffset = -90;
		} elseif (\str_contains($versionString, 'beta')) {
			$preReleaseOffset = -60;
		} elseif (\str_contains($versionString, 'rc')) {
			$preReleaseOffset = -30;
		} else {
			$preReleaseOffset = 0;
		}
		$preReleaseVersion = \preg_match('/(alpha|beta|rc)[.-]?(\d+)$/', $versionString, $matches) ? (int)$matches[2] : 0;
		return ($major * 1000000) + ($minor * 10000) + ($patch * 100) + $preReleaseOffset + $preReleaseVersion;
	}

	/**
	 * Decode an encoded application version integer back into a version string.
	 * @see self::encodeVersionString for the encoding scheme
	 */
	public static function decodeVersionInt(int $versionInt) : string {
		$major = (int)($versionInt / 1000000);
		$minor = (int)(($versionInt % 1000000) / 10000);
		$patch = (int)(($versionInt % 10000) / 100);

		$preReleaseVer = $versionInt % 100;
		if ($preReleaseVer === 0) {
			return "$major.$minor.$patch";
		} else {
			$patch += 1;
			if ($preReleaseVer < 40) {
				$preReleaseType = 'alpha';
				$preReleaseVer -= 10;
			} elseif ($preReleaseVer < 70) {
				$preReleaseType = 'beta';
				$preReleaseVer -= 40;
			} else {
				$preReleaseType = 'rc';
				$preReleaseVer -= 70;
			}
			return "$major.$minor.$patch-$preReleaseType$preReleaseVer";
		}
	}
}
