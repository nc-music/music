<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

namespace OCA\Music\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
	public function __construct(
		private IURLGenerator $url,
		private IL10N $l
	) {}

	/**
	 * @return string
	 */
	public function getID()
	{
		return 'music';
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->l->t('Music');
	}

	/**
	 * @return int
	 */
	public function getPriority()
	{
		return 15;
	}

	/**
	 * @return string
	 */
	public function getIcon()
	{
		return $this->url->imagePath('music', 'music-dark.svg');
	}
}
