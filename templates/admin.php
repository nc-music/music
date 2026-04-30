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

use OCA\Music\Utility\HtmlUtil;

/**
 * @var array $_
 * @var \OCP\IL10N $l
 */

HtmlUtil::addWebpackScript('admin_settings');
HtmlUtil::addWebpackStyle('app');
HtmlUtil::addWebpackStyle('admin_settings');

?>
<div id="admin-music"></div>