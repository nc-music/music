/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2017 - 2026
 */

(function() {
	let mPlayer = new OCA.Music.EmbeddedPlayer();

	const mAudioMimes = _.filter([
		'audio/aac',
		'audio/aiff',
		'audio/basic',
		'audio/flac',
		'audio/mp4',
		'audio/m4b',
		'audio/mpeg',
		'audio/ogg',
		'audio/wav',
		'audio/x-aiff',
		'audio/x-caf',
	], (mime) => mPlayer.canPlayMime(mime));

	const mPlaylistMimes = [
		'audio/mpegurl',
		'audio/x-scpls',
		'application/vnd.ms-wpl'
	];

	function register() {
		OCA.Music.folderView = new OCA.Music.FolderView(mPlayer, mAudioMimes, mPlaylistMimes);

		// First, try to load the Nextcloud Files API. This works on NC28+ within the Files app but only on NC31+
		// within a link-shared public folder. Note that we can't wait for the page load to be finished before doing
		// this because that would be too late for the registration and cause the issue https://github.com/owncloud/music/issues/1126.
		import('@nextcloud/sharing/public').then(ncSharingPublic => {
			const sharingToken = ncSharingPublic.isPublicShare() ? ncSharingPublic.getSharingToken() : null;
			// @nextcloud/files 4.x supports only NC33+ while @nextcloud/files 3.x supports NC 26-32
			if (OCA.Music.Utils.ncMajorVersion() >= 33) {
				import('@nextcloud/files-4').then(ncFiles => {
					OCA.Music.folderView.registerToNcFiles4(ncFiles, sharingToken);
				});
			} else {
				import('@nextcloud/files-3').then(ncFiles => {
					OCA.Music.folderView.registerToNcFiles3(ncFiles, sharingToken);
				});
			}
		}).catch(e => console.error('Failed to load the NC API library: ', e));

		// The older fileActions API is used in NC28..30 when operating within a link-shared folder
		window.addEventListener('DOMContentLoaded', () => {
			const sharingToken = $('#sharingToken').val(); // undefined if not on a public share or on NC31+

			// Files app or a link shared folder
			if (OCA.Files?.fileActions) {
				OCA.Music.folderView.registerToFileActions(OCA.Files.fileActions, sharingToken);
			}

			OCA.Music.initPlaylistTabView(mPlaylistMimes);
			connectPlaylistTabViewEvents(OCA.Music.folderView);
		});
	}

	function connectPlaylistTabViewEvents(folderView) {
		if (OCA.Music.playlistTabView) {
			OCA.Music.playlistTabView.on('playlistItemClick', (file, index) => folderView.onPlaylistItemClick(file, index));

			OCA.Music.playlistTabView.on('rendered', () => {
				const plState = folderView.playlistFileState();
				if (plState !== null) {
					OCA.Music.playlistTabView.setCurrentTrack(plState.fileId, plState.index);
				} else {
					OCA.Music.playlistTabView.setCurrentTrack(null);
				}
			});
		}
	}

	register();
})();
