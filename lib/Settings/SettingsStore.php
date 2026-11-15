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

namespace OCA\Music\Settings;

use OCP\IConfig;

/**
 * Stores and retrieves the Music app's settings.
 */
class SettingsStore {

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * Allow servers with private IP addresses to be used as the source of podcast feeds and podcast/radio streams.
	 * This may have a security impact, as the Nextcloud server may be tricked into sending requests to internal servers that
	 * are not meant to be accessed from the outside. To reduce the risk, the stream relaying is never used for the local servers
	 * even if the feature is enabled.
	 */
	public function localRemoteServersAllowed() : bool {
		$default = $this->getBooleanConfig('allow_local_remote_servers', false); // generic setting from Nextcloud core
		return $this->getBooleanConfig('music.allow_local_remote_servers', $default);
	}

	/**
	 * Relay the podcast streams via the Nextcloud server, instead of letting the client stream them directly from the source.
	 */
	public function podcastStreamRelayEnabled() : bool {
		return $this->getBooleanConfig('music.relay_podcast_stream', true);
	}

	/**
	 * Relaying the podcasts to the API clients is a separate decision from relaying them to the web UI,
	 * as the API clients are not bound by the content security policy which is the main reason for the relay.
	 */
	public function podcastStreamRelayOnApiEnabled() : bool {
		return $this->getBooleanConfig('music.relay_podcast_stream_on_api', $this->podcastStreamRelayEnabled());
	}

	/**
	 * Relay the radio streams via the Nextcloud server, instead of letting the client stream them directly from the source.
	 */
	public function radioStreamRelayEnabled() : bool {
		return $this->getBooleanConfig('music.relay_radio_stream', true);
	}

	/**
	 * Relaying the radio streams found from playlists files within public shares is a separate decision from relaying
	 * for the authenticated users.
	 * @see radioStreamRelayEnabled()
	 */
	public function radioStreamRelayOnShareEnabled() : bool {
		return $this->getBooleanConfig('music.relay_radio_stream_on_share', $this->radioStreamRelayEnabled());
	}

	/**
	 * Relaying the radio streams to the API clients is a separate decision from relaying them to the web UI,
	 * as the API clients are not bound by the content security policy which is the main reason for the relay.
	 * @see radioStreamRelayEnabled()
	 */
	public function radioStreamRelayOnApiEnabled() : bool {
		return $this->getBooleanConfig('music.relay_radio_stream_on_api', $this->radioStreamRelayEnabled());
	}

	/**
	 * Enable relaying of the HLS-type radio streams to the web UI. These streams cannot be listened directly by the web UI.
	 */
	public function radioHlsEnabled() : bool {
		return $this->getBooleanConfig('music.enable_radio_hls', true);
	}

	/**
	 * Enable relaying of the HLS-type radio streams to the web UI when the radio station is accessed via a public share.
	 * These streams cannot be listened directly by the web UI.
	 */
	public function radioHlsOnShareEnabled() : bool {
		return $this->getBooleanConfig('music.enable_radio_hls_on_share', $this->radioHlsEnabled());
	}

	/**
	 * Get the default version of the Ampache API to be used when the client doesn't specify one.
	 */
	public function ampacheApiDefaultVersion() : int {
		return (int)$this->config->getSystemValue('music.ampache_api_default_ver', 6);
	}

	public function tagEnabledId3v1() : bool {
		return $this->getBooleanConfig('music.tag_enabled_id3v1', false);
	}

	public function tagEnabledId3v2() : bool {
		return $this->getBooleanConfig('music.tag_enabled_id3v2', true);
	}

	public function tagEnabledLyrics3() : bool {
		return $this->getBooleanConfig('music.tag_enabled_lyrics3', false);
	}

	public function tagEnabledApe() : bool {
		return $this->getBooleanConfig('music.tag_enabled_ape', true);
	}

	private function getBooleanConfig(string $key, bool $default) : bool {
		$value = $this->config->getSystemValue($key, $default);
		if (\is_string($value)) {
			return \filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
		}
		return (bool)$value;
	}
}
