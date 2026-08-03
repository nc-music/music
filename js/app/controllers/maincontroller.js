/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013, 2014
 * @copyright Pauli Järvinen 2017 - 2026
 */

angular.module('Music').controller('MainController', [
'$rootScope', '$scope', '$document', '$timeout', '$window', 'gettextCatalog', 'Restangular',
'scanService', 'libraryFactory', 'playQueueService', 'inViewService',
function ($rootScope, $scope, $document, $timeout, $window, gettextCatalog, Restangular,
		scanService, libraryFactory, playQueueService, inViewService) {

	gettextCatalog.currentLanguage = OC.getLanguage();

	// Create a global rule to use themed icons for folders everywhere, the default icon-folder is not themed on NC 25 and later.
	// It happens sometimes (at least on Chrome), that OC.MimeType is not yet present when we come here (see 
	// https://github.com/nc-music/oc-music/issues/1137). In those cases, we need to postpone registering the folder style.

	// On NC33, it's possible that OC.MimeType is not ready to use even when it's available; it depends on window.OCA.Theming
	// which may still be absent. According to https://github.com/nc-music/music/issues/146, this happens always on Safari but
	// occasionally it has been seen on Firefox and Chrome, too.
	Promise.all([
		OCA.Music.Utils.getValueOnceAvailable(() => window.OC.MimeType),
		OCA.Music.Utils.getValueOnceAvailable(() => window.OCA.Theming)
	]).then(([ocMimeType, _ocaTheming]) => {
		const folderStyle = document.createElement('style');
		folderStyle.innerHTML = `#app-view .icon-folder { background-image: url(${ocMimeType.getIconUrl('dir')}) }`;
		document.head.appendChild(folderStyle);
	});

	// Wrapper for $rootScope.$on which provides automatic unsubscribing on scope destruction
	$rootScope.subscribe = function(eventName, listenerScope, listener) {
		const handle = $rootScope.$on(eventName, listener);
		listenerScope.$on('$destroy', handle);
	};

	$rootScope.loading = true;
	$rootScope.playing = false;
	$rootScope.playingView = null;
	$scope.currentTrack = null;

	playQueueService.subscribe('trackChanged', $scope, (listEntry) => {
		$scope.currentTrack = listEntry.track;
		$scope.currentTrackIndex = playQueueService.getCurrentIndex();
	});

	playQueueService.subscribe('play', $scope, (playingView) => {
		// assume that the play started from current view if no other view given
		$rootScope.playingView = playingView || $scope.getCurrentViewId();
	});

	playQueueService.subscribe('playlistEnded', $scope, () => {
		$rootScope.playingView = null;
		$scope.currentTrack = null;
		$scope.currentTrackIndex = -1;
	});

	// close all pop-up menus when clicking outside of them
	$document.on('click', function(_event) {
		$timeout(() => $rootScope.$emit('popup-menu:close'));
	});

	$scope.getCurrentViewId = function() {
		return window.location.hash.split('?')[0];
	};

	$scope.loadIndicatorVisible = function() {
		let contentNotReady = ($rootScope.searchInProgress || $scope.checkingUnscanned);
		return $rootScope.loading
			|| (contentNotReady && $scope.viewingLibrary());
	};

	$scope.viewingLibrary = function() {
		return !['#/settings', '#/radio', '#/podcasts'].includes($scope.getCurrentViewId());
	};

	$scope.updateCollection = () => libraryFactory.reloadCollection();

	libraryFactory.subscribe('collectionUpdating', $scope, () => {
		$scope.updateAvailable = false;

		if ($scope.viewingLibrary) {
			$rootScope.loading = true;
		}

		libraryFactory.getCollection().then((collection) => {
			// The "no content"/"click to scan"/"scanning" banner uses "collapsed" layout
			// if there are any tracks already visible
			const collapsiblePopups = $('#app-content .emptycontent:not(.no-collapse)');
			if (collection.length > 0) {
				collapsiblePopups.addClass('collapsed');
			} else {
				collapsiblePopups.removeClass('collapsed');
			}
		});
	});

	$scope.hideScanBar = function(event) {
		event.stopPropagation();
		// Acknowledge the scanning needs without taking any action. The page needs to be reloaded
		// to check them again.
		$scope.unscannedFiles = null;
		$scope.dirtyFiles = null;
		$scope.obsoleteFiles = null;
	};

	$scope.updateFilesToScan = function() {
		$scope.unscannedFiles = null;
		$scope.dirtyFiles = null;
		$scope.obsoleteFiles = null;
		$scope.checkingUnscanned = true;

		Restangular.one('scanstate').get().then(function(state) {
			$scope.checkingUnscanned = false;
			$scope.unscannedFiles = state.unscannedFiles;
			$scope.dirtyFiles = state.dirtyFiles;
			$scope.obsoleteFiles = state.obsoleteFiles;
			$scope.noMusicAvailable = (state.scannedCount + state.unscannedFiles.length === 0);
		},
		function(error) {
			$scope.checkingUnscanned = false;
			OCA.Music.Dialogs.showNotification(
					gettextCatalog.getString('Failed to check for new audio files (error {{ code }}); check the server logs for details', {code: error.status})
			);
		});
	};

	$scope.startScanning = function(fileIds) {
		scanService.scan(fileIds).then(
			() => { // done
				// Update the collection automatically. During the scanning, the user can also click the "update" button to update the collection.
				$scope.scanning = false;
				libraryFactory.reloadCollection();
			},
			(error) => {
				$scope.scanning = false;
				console.log('Scan aborted: ' + error);
			},
			(progress) => {
				$scope.scanning = true;
				$scope.scanningScanned = progress.scannedCount;
				$scope.scanningTotal = progress.totalCount;
				$scope.updateAvailable ||= (progress.scannedCount > 0);
			}
		);

		if (fileIds === $scope.unscannedFiles) {
			$scope.unscannedFiles = null;
		} else if (fileIds === $scope.dirtyFiles) {
			$scope.dirtyFiles = null;
		}
	};

	$scope.stopScanning = () => scanService.stopScan();

	$scope.removeObsolete = function() {
		const count = $scope.obsoleteFiles.length;
		OC.dialogs.confirm(
			gettextCatalog.getPlural(count,
				'{{ count }} previously scanned file is no longer available. Remove it from the collection?',
				'{{ count }} previously scanned files are no longer available. Remove them from the collection?',
				{ count: count }),
			gettextCatalog.getString('Remove unavailable files'),
			function(confirmed) {
				if (confirmed) {
					Restangular.all('removescanned').post({files: $scope.obsoleteFiles.join(',')}).then(_result => {
						$scope.obsoleteFiles = null;
						libraryFactory.reloadCollection();
					});
				}
			},
			true
		);
	};

	function showDetails(entityType, id) {
		const capType = OCA.Music.Utils.capitalize(entityType);
		const showDetailsEvent = 'show' + capType + 'Details';
		$rootScope.$emit(showDetailsEvent, id);

		if (id) {
			$timeout(function() {
				const scrollEvent = 'scrollTo' + capType;
				const elemId = _.kebabCase(entityType) + '-' + id;
				let elem = document.getElementById(elemId);
				if (elem !== null && !isElementInViewPort(elem)) {
					$rootScope.$emit(scrollEvent, id, 0);
				}
			}, 300);
		}
	}

	$scope.showTrackDetails = function(trackOrId) {
		showDetails('track', trackOrId.id ?? trackOrId);
	};

	$scope.showArtistDetails = function(artistOrId) {
		showDetails('artist', artistOrId.id ?? artistOrId);
	};

	$scope.showAlbumDetails = function(albumOrId) {
		showDetails('album', albumOrId.id ?? albumOrId);
	};

	$scope.showPlaylistDetails = function(playlistOrId) {
		showDetails('playlist', playlistOrId.id ?? playlistOrId);
	};

	$scope.showSmartListFilters = function() {
		$rootScope.$emit('showSmartListFilters');
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showRadioStationDetails = function(stationOrId) {
		showDetails('radioStation', stationOrId?.id ?? stationOrId);
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showRadioHint = function() {
		$rootScope.$emit('showRadioHint');
		$scope.collapseNavigationPaneOnMobile();
	};

	$scope.showPodcastChannelDetails = function(channelOrId) {
		showDetails('podcastChannel', channelOrId.id ?? channelOrId);
	};

	$scope.showPodcastEpisodeDetails = function(episodeOrId) {
		showDetails('podcastEpisode', episodeOrId.id ?? episodeOrId);
	};

	$scope.hideSidebar = function() {
		$rootScope.$emit('hideDetails');
	};

	$scope.scrollOffset = function() {
		let controls = document.getElementById('controls');
		let offset = controls?.offsetHeight ?? 0;
		if (OCA.Music.Utils.getScrollContainer()[0] !== document.getElementById('app-content')) {
			let header = document.getElementById('header');
			offset += header?.offsetHeight;
		}
		return offset;
	};

	$scope.scrollToItem = function(itemId, animationTime = 500) {
		if (itemId) {
			let container = OCA.Music.Utils.getScrollContainer();
			let element = $('#' + itemId);
			if (container && element) {
				container.scrollToElement(element, $scope.scrollOffset(), animationTime);
			}
		}
	};

	$scope.scrollToTop = function() {
		OCA.Music.Utils.getScrollContainer().scrollTo(0, 0);
	};

	// Navigate to a view selected from the navigation bar
	let navigationDestination = null;
	let afterNavigationCallback = null;
	$scope.navigateTo = function(destination, callback = null) {
		let curView = $scope.getCurrentViewId();
		if (curView != destination) {
			navigationDestination = destination;
			afterNavigationCallback = callback;
			$rootScope.loading = true;
			$rootScope.$emit('deactivateView');
			$timeout(() => window.location.hash = navigationDestination);
		}

		$scope.collapseNavigationPaneOnMobile();
	};

	$rootScope.subscribe('viewActivated', $scope, (_event, viewId) => {
		if (viewId === $scope.getCurrentViewId()) {
			$rootScope.loading = false;
		}
		// execute the callback after view activation if given
		if (afterNavigationCallback !== null) {
			$timeout(afterNavigationCallback);
			afterNavigationCallback = null;
		}
	});

	$rootScope.subscribe('viewBusy', $scope, (_event, viewId) => {
		if (viewId === $scope.getCurrentViewId()) {
			$rootScope.loading = true;
		}
	});

	// Compact/normal layout of the Albums view
	$scope.albumsCompactLayout = (OCA.Music.Storage.get('albums_compact') === 'true');
	$scope.toggleAlbumsCompactLayout = function(useCompact = !$scope.albumsCompactLayout) {
		if ($scope.getCurrentViewId() === '#/') {
			// albums view already active, change the layout in place
			$rootScope.loading = true;
			$timeout(() => {
				$scope.albumsCompactLayout = useCompact;
				$timeout(() => {
					// the active view could have changed by now...
					if ($scope.getCurrentViewId() === '#/') {
						$rootScope.loading = false;
						$timeout(() => $rootScope.$emit('albumsLayoutChanged'));
					}
				});
			});
		} else {
			// navigate to the albums view using the new layout
			$scope.albumsCompactLayout = useCompact;
			$scope.navigateTo('#/');
		}

		OCA.Music.Storage.set('albums_compact', useCompact.toString());
	};

	// Flat/tree layout of the Folders view
	$scope.foldersFlatLayout = (OCA.Music.Storage.get('folders_flat') === 'true');
	$scope.toggleFoldersFlatLayout = function(useFlat = !$scope.foldersFlatLayout) {
		if ($scope.getCurrentViewId() === '#/folders') {
			// folders view already active, change the layout in place
			$rootScope.loading = true;
			$timeout(() => {
				$scope.foldersFlatLayout = useFlat;
				$rootScope.$emit('foldersLayoutChanged');
				// foldersviewcontroller emits 'viewActivated' once it's ready
			});
		} else {
			// navigate to the folders view using the new layout
			$scope.foldersFlatLayout = useFlat;
			$scope.navigateTo('#/folders');
		}

		OCA.Music.Storage.set('folders_flat', useFlat.toString());
	};

	$scope.collapseNavigationPaneOnMobile = function() {
		$timeout(() => $rootScope.$emit('closeSnapper'));
	};

	// Test if element is at least partially within the view-port
	function isElementInViewPort(el) {
		return inViewService.isElementInViewPort(el, -$scope.scrollOffset());
	}

	function setMasterLayout(classes) {
		let missingClasses = _.difference(['tablet', 'mobile', 'portrait', 'extra-narrow', 'min-width'], classes);
		let appContent = $('#app-content');

		_.each(classes, function(cls) {
			appContent.addClass(cls);
		});
		_.each(missingClasses, function(cls) {
			appContent.removeClass(cls);
		});
	}

	$rootScope.subscribe('resize', $scope, (_event, appView) => {
		const appViewWidth = appView.outerWidth();

		// For some reason, there may be resize events with 0-width during view switching.
		// Reacting to those would cause UI flickering.
		if (appViewWidth == 0) {
			return;
		}

		// Adjust controls bar width to not overlap with the scroll bar.
		// Subtract one pixel from the width because outerWidth() seems to
		// return rounded integer value which may sometimes be slightly larger
		// than the actual width of the #app-view.
		const controlsWidth = appViewWidth - 1;
		$('#controls').css('width', controlsWidth);
		$('#controls').css('min-width', controlsWidth);

		// the "no content"/"click to scan"/"scanning" banner has the same width as controls,
		// subtracting the alphabet-navigation width
		const alphaNaviWidth = 50;
		$('#app-content .emptycontent').css('width', controlsWidth - alphaNaviWidth);
		$('#app-content .emptycontent').css('min-width', controlsWidth - alphaNaviWidth);

		// Set the app-content class according to window and view width. This has
		// impact on the overall layout of the app. See music-mobile.css and music-tablet.css.
		if (appViewWidth <= 280) {
			setMasterLayout(['mobile', 'portrait', 'extra-narrow', 'min-width']);
		}
		else if (appViewWidth <= 360) {
			setMasterLayout(['mobile', 'portrait', 'extra-narrow']);
		}
		else if (appViewWidth <= 400) {
			setMasterLayout(['mobile', 'portrait']);
		}
		else if (appViewWidth <= 500 && $window.innerWidth < 1024) {
			setMasterLayout(['mobile']);
		}
		else if (appViewWidth < 1024) {
			setMasterLayout(['tablet']);
		}
		else {
			setMasterLayout([]);
		}

		if (appViewWidth <= 768) {
			$('#controls').addClass('two-line');
		} else {
			$('#controls').removeClass('two-line');
		}

		// Work-around for NC14+: The sidebar width has been limited to 500px (normally 27%),
		// but it's not possible to make corresponding "max margin" definition for #app-content
		// in css. Hence, the margin width is limited here.
		const appContent = $('#app-content');
		if (appContent.hasClass('with-app-sidebar')) {
			let sidebarWidth = $('#app-sidebar').outerWidth();
			let viewWidth = $('#header').outerWidth();

			if (sidebarWidth < 0.27 * viewWidth) {
				appContent.css('margin-inline-end', sidebarWidth);
			} else {
				appContent.css('margin-inline-end', '');
			}
		}
		else {
			appContent.css('margin-inline-end', '');
		}
	});

	$scope.scanning = false;
	$scope.scanningScanned = 0;
	$scope.scanningTotal = 0;

	// initial loading of the library data
	libraryFactory.reloadCollection();
	libraryFactory.getRadioStations();
	libraryFactory.getPodcastChannels();
	libraryFactory.updateFavorites();
	$scope.updateFilesToScan();

	$('#app').addClass('loaded');
}]);
