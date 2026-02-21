/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */

import musicIconSvgData from '../../img/music-dark.svg?raw';

OCA.Music = OCA.Music || {};

let sidebarNode = null; // for ncFiles 4

class PlaylistTabView extends HTMLElement {

	constructor() {
		super();
		OCA.Music.playlistTabView = this;
	}

	connectedCallback() {
		const n = sidebarNode;
		if (n) {
			const fileInfo = {id: n.fileid, name: n.basename, mimetype: n.mime, path: n.dirname};
			this.populate(fileInfo);
		}
	}

	populate(fileInfo) {
		$(this).empty(); // erase any previous content
		$(this).addClass('musicPlaylistTabView');
		this.fileInfo = fileInfo;

		if (fileInfo) {

			let loadIndicator = $('<div>').attr('class', 'loading');
			$(this).append(loadIndicator);

			let onPlaylistLoaded = (data) => {
				loadIndicator.hide();

				let list = $('<ol>');
				$(this).append(list);

				let titleForFile = function(file) {
					return file.caption || OCA.Music.Utils.titleFromFilename(file.name ?? '') || file.url;
				};

				let tooltipForFile = function(file) {
					return file.path ? `${file.path}/${file.name}` : file.url;
				};

				for (let i = 0; i < data.files.length; ++i) {
					list.append($('<li>')
								.attr('id', 'music-playlist-item-' + i)
								.text(titleForFile(data.files[i]))
								.prop('title', tooltipForFile(data.files[i])));
				}

				// click handler
				list.on('click', 'li', (event) => {
					const idx = parseInt(event.target.id.split('-').pop());
					this.trigger('playlistItemClick', fileInfo, idx);
				});

				if (data.invalid_paths.length > 0) {
					$(this).append($('<p>').text(t('music', 'Some files on the playlist were not found') + ':'));
					let failList = $('<ul>');
					$(this).append(failList);

					for (let i = 0; i < data.invalid_paths.length; ++i) {
						failList.append($('<li>').text(data.invalid_paths[i]));
					}
				}

				this.trigger('rendered');
			};

			let onError = function(_error) {
				loadIndicator.hide();
				$(this).append($('<p>').text(t('music', 'Error reading playlist file')));
			};

			OCA.Music.PlaylistFileService.readFile(fileInfo.id, onPlaylistLoaded, onError);
		}
	}

	setCurrentTrack(playlistId, trackIndex) {
		$(this).find('ol li.current').removeClass('current');
		if (this.fileInfo && this.fileInfo.id == playlistId) {
			$(this).find('ol li#music-playlist-item-' + trackIndex).addClass('current');
		}
	}
}
_.extend(PlaylistTabView.prototype, OC.Backbone.Events);

// Registration for NC versions 28 ... 32
export function initLegacy(playlistMimes) {
	if (OCA.Files?.Sidebar) {
		window.customElements.define('music-playlist-tab-view', PlaylistTabView);
		const playlistTabView = new PlaylistTabView();

		OCA.Files.Sidebar.registerTab(new OCA.Files.Sidebar.Tab({
			id: 'musicPlaylistTabView',
			name: t('music', 'Playlist'),
			icon: 'icon-music',

			async mount(el, fileInfo, _context) {
				el.appendChild(playlistTabView);
				playlistTabView.populate(fileInfo);
			},
			update(fileInfo) {
				playlistTabView.populate(fileInfo);
			},
			destroy() {
			},
			enabled(fileInfo) {
				return playlistMimes.includes(fileInfo.mimetype);
			},
		}));
	}
}

// Registration for NC version 33 and later
export function initForNcFiles4(ncFiles, playlistMimes) {
	ncFiles.getSidebar().registerTab({
		id: 'music_playlist',
		displayName: t('music', 'Playlist'),
		iconSvgInline: musicIconSvgData,
		order: 50,
		tagName: 'music_playlist-files_sidebar_tab',

		enabled: ({ node, _folder, _view }) => {
			// No sane way to get the active node on the custom element activation was found;
			// the property ncFiles.getSidebar().node seems to be always undefined.
			// Hijack the node from this callback.
			sidebarNode = node;
			return playlistMimes.includes(node.mime);
		},

		onInit: () => customElements.define('music_playlist-files_sidebar_tab', PlaylistTabView),
	});
}
