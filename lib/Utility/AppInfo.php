<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2022 - 2026
 */

namespace OCA\Music\Utility;

use OCP\App\IAppManager;

class AppInfo {

	public const APP_ID = 'music';
	private static ?int $encodedVersion = null;

	public static function getVersion() : string {
		$appManager = \OC::$server->query(IAppManager::class);
		return $appManager->getAppVersion(self::APP_ID);
	}

	public static function getEncodedVersion() : int {
		// Make this so that also Scrutinizer understands that the return value is always non-null.
		$encodedVer = self::$encodedVersion ?? Util::encodeVersionString(self::getVersion());
		self::$encodedVersion = $encodedVer;
		return $encodedVer;
	}

	public static function getVendor() : string {
		return 'nextcloud';
	}

	public static function getFullName() : string {
		return self::getVendor() . ' ' . self::APP_ID;
	}
}
