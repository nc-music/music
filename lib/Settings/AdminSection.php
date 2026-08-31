<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Matthew Wells 2026
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Settings;

use OCA\Music\Utility\HtmlUtil;
use OCP\IL10N;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
	) {
	}

	/**
	 * @return string
	 */
	public function getID() {
		return 'music';
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->l10n->t('Music');
	}

	/**
	 * @return int
	 */
	public function getPriority() {
		return 15;
	}

	/**
	 * @return string
	 */
	public function getIcon() {
		return HtmlUtil::getSvgPath('music-dark');
	}
}
