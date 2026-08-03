<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Lachlan de Waard <lachlan.00@gmail.com>
 * @copyright Lachlan de Waard 2026
 */

namespace OCA\Music\Service;

/**
 * Thrown when the music folder configured for the user cannot be resolved, e.g. because the folder
 * has been renamed or removed, or because it resides on an external storage which is not available.
 */
class LibraryFolderException extends \Exception {
}
