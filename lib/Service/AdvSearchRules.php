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

use OCP\IL10N;

class AdvSearchRules {

	public function __construct(private IL10N $l10n) {
	}

	public function getRules(): array {
		$l10n = $this->l10n;

		return [
			'track' => [
				'' => [
					'anywhere' => $l10n->t('Any searchable text')
				],
				$l10n->t('Track metadata') => [
					'title'			=> $l10n->t('Name'),
					'album'			=> $l10n->t('Album name'),
					'artist'		=> $l10n->t('Artist name'),
					'album_artist'	=> $l10n->t('Album artist name'),
					'track'			=> $l10n->t('Track number'),
					'year'			=> $l10n->t('Year'),
					'time'			=> $l10n->t('Duration (minutes)'),
					'bitrate'		=> $l10n->t('Bit rate'),
					'song_genre'	=> $l10n->t('Track genre'),
					'album_genre'	=> $l10n->t('Album genre'),
					'artist_genre'	=> $l10n->t('Artist genre'),
					'no_genre'		=> $l10n->t('Has no genre'),
				],
				$l10n->t('File data') => [
					'file'				=> $l10n->t('File name'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
				$l10n->t('Rating') => [
					'favorite'			=> $l10n->t('Favorite'),
					'favorite_album'	=> $l10n->t('Favorite album'),
					'favorite_artist'	=> $l10n->t('Favorite artist'),
					'rating'			=> $l10n->t('Rating'),
					'albumrating'		=> $l10n->t('Album rating'),
					'artistrating'		=> $l10n->t('Artist rating'),
				],
				$l10n->t('Play history') => [
					'played_times'		=> $l10n->t('Played times'),
					'last_play'			=> $l10n->t('Last played'),
					'recent_played'		=> $l10n->t('Recently played'),
					'myplayed'			=> $l10n->t('Is played'),
					'myplayedalbum'		=> $l10n->t('Is played album'),
					'myplayedartist'	=> $l10n->t('Is played artist'),
				],
				$l10n->t('Playlist') => [
					'playlist' 		=> $l10n->t('Playlist'),
					'playlist_name'	=> $l10n->t('Playlist name'),
				]
			],
			'album' => [
				$l10n->t('Album metadata') => [
					'title'			=> $l10n->t('Name'),
					'artist'		=> $l10n->t('Album artist name'),
					'song_artist'	=> $l10n->t('Track artist name'),
					'song'			=> $l10n->t('Track name'),
					'year'			=> $l10n->t('Year'),
					'time'			=> $l10n->t('Duration (minutes)'),
					'song_count'	=> $l10n->t('Track count'),
					'disk_count'	=> $l10n->t('Disk count'),
					'album_genre'	=> $l10n->t('Album genre'),
					'song_genre'	=> $l10n->t('Track genre'),
					'no_genre'		=> $l10n->t('Has no genre'),
					'has_image'		=> $l10n->t('Has image'),
				],
				$l10n->t('File data') => [
					'file'			 	=> $l10n->t('File name'),
					'added'			 	=> $l10n->t('Add date'),
					'updated'		 	=> $l10n->t('Update date'),
					'recent_added'	 	=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
				$l10n->t('Rating') => [
					'favorite'		=> $l10n->t('Favorite'),
					'rating'		=> $l10n->t('Rating'),
					'songrating'	=> $l10n->t('Track rating'),
					'artistrating'	=> $l10n->t('Artist rating'),
				],
				$l10n->t('Play history') => [
					'played_times'		=> $l10n->t('Played times'),
					'last_play'			=> $l10n->t('Last played'),
					'recent_played'		=> $l10n->t('Recently played'),
					'myplayed'			=> $l10n->t('Is played'),
					'myplayedartist'	=> $l10n->t('Is played artist'),
				],
				$l10n->t('Playlist') => [
					'playlist'		=> $l10n->t('Playlist'),
					'playlist_name'	=> $l10n->t('Playlist name'),
				]
			],
			'artist' => [
				$l10n->t('Artist metadata') => [
					'title'			=> $l10n->t('Name'),
					'album'			=> $l10n->t('Album name'),
					'song'			=> $l10n->t('Track name'),
					'time'			=> $l10n->t('Duration (minutes)'),
					'album_count'	=> $l10n->t('Album count'),
					'song_count'	=> $l10n->t('Track count'),
					'genre'			=> $l10n->t('Artist genre'),
					'song_genre'	=> $l10n->t('Track genre'),
					'no_genre'		=> $l10n->t('Has no genre'),
					'has_image'		=> $l10n->t('Has image'),
				],
				$l10n->t('File data') => [
					'file'				=> $l10n->t('File name'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
				$l10n->t('Rating') => [
					'favorite'		=> $l10n->t('Favorite'),
					'rating'		=> $l10n->t('Rating'),
					'songrating'	=> $l10n->t('Track rating'),
					'albumrating'	=> $l10n->t('Album rating'),
				],
				$l10n->t('Play history') => [
					'played_times'	=> $l10n->t('Played times'),
					'last_play'		=> $l10n->t('Last played'),
					'recent_played'	=> $l10n->t('Recently played'),
					'myplayed'		=> $l10n->t('Is played'),
				],
				$l10n->t('Playlist') => [
					'playlist'		=> $l10n->t('Playlist'),
					'playlist_name'	=> $l10n->t('Playlist name'),
				]
			],
			'playlist' => [
				'' => [
					'title'				=> $l10n->t('Name'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
					'favorite'			=> $l10n->t('Favorite'),
				]
			],
			'genre' => [
				'' => [
					'title'				=> $l10n->t('Name'),
					'album_count'		=> $l10n->t('Album count'),
					'song_count'		=> $l10n->t('Track count'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				]
			],
			'podcast_episode' => [
				$l10n->t('Podcast metadata') => [
					'title'		=> $l10n->t('Name'),
					'podcast'	=> $l10n->t('Podcast channel'),
					'time'		=> $l10n->t('Duration (minutes)'),
				],
				$l10n->t('History') => [
					'pubdate'			=> $l10n->t('Date published'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
				$l10n->t('Rating') => [
					'favorite'	=> $l10n->t('Favorite'),
					'rating'	=> $l10n->t('Rating'),
				],
			],
			'podcast_channel' => [
				$l10n->t('Podcast metadata') => [
					'title'				=> $l10n->t('Name'),
					'podcast_episode'	=> $l10n->t('Podcast episode'),
					'time'				=> $l10n->t('Duration (minutes)'),
				],
				$l10n->t('History') => [
					'pubdate'			=> $l10n->t('Date published'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
				$l10n->t('Rating') => [
					'favorite'	=> $l10n->t('Favorite'),
					'rating'	=> $l10n->t('Rating'),
				],
			],
			'radio_station' /* not part of the Ampache specification */ => [
				'' => [
					'title'				=> $l10n->t('Name'),
					'stream_url'		=> $l10n->t('Stream URL'),
					'added'				=> $l10n->t('Add date'),
					'updated'			=> $l10n->t('Update date'),
					'recent_added'		=> $l10n->t('Recently added'),
					'recent_updated'	=> $l10n->t('Recently updated'),
				],
			],
		];
	}

	public static function typeForRule(string $rule) : ?string {
		$rulesPerType = [
			'text' => [
				'anywhere', 'title', 'song', 'album', 'artist', 'podcast', 'podcast_episode', 'album_artist', 'song_artist',
				'favorite', 'favorite_album', 'favorite_artist', 'genre', 'song_genre', 'album_genre', 'artist_genre',
				'playlist_name', 'type', 'file', 'mbid', 'mbid_album', 'mbid_artist', 'mbid_song', 'stream_url' /* not in Ampache spec */
			],
			// text but not supported: 'composer', 'summary', 'placeformed', 'release_type', 'release_status', 'barcode',
			// 'catalog_number', 'label', 'comment', 'lyrics', 'username', 'category'

			'numeric' => [
				'track', 'year', 'original_year', 'played_times', 'album_count', 'song_count', 'disk_count', 'time', 'bitrate'
			],
			// numeric but not supported: 'yearformed', 'skipped_times', 'play_skip_ratio', 'image_height', 'image_width'

			// in the Ampache API, these are just 'numeric'
			'numeric_rating' => ['myrating', 'rating', 'songrating', 'albumrating', 'artistrating'],

			'numeric_limit' => ['recent_played', 'recent_added', 'recent_updated'],

			'date' => ['added', 'updated', 'pubdate'],

			'days' => ['last_play'],
			// days but not supported: 'last_play_or_skip'

			'boolean' => [
				'played', 'myplayed', 'myplayedalbum', 'myplayedartist', 'has_image', 'no_genre',
				'my_flagged', 'my_flagged_album', 'my_flagged_artist'
			],
			// boolean but not supported: 'smartplaylist', 'possible_duplicate', 'possible_duplicate_album'

			'boolean_numeric' => ['album_artist_id' /* not in Ampache spec */],
			// boolean numeric but not supported: 'license', 'state', 'catalog'

			'playlist' => ['playlist'], // in the Ampache API, this is just 'boolean_numeric'
		];

		foreach ($rulesPerType as $type => $rules) {
			if (\in_array($rule, $rules)) {
				return $type;
			}
		}

		return null;
	}

}