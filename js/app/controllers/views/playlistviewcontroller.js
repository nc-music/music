/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2013
 * @copyright Pauli Järvinen 2017 - 2026
 */


angular.module('Music').controller('PlaylistViewController', [
	'$rootScope', '$scope', '$routeParams', 'playQueueService', 'libraryService', 'libraryFactory', 'gettextCatalog', 'Restangular', '$timeout',
	function ($rootScope, $scope, $routeParams, playQueueService, libraryService, libraryFactory, gettextCatalog, Restangular, $timeout) {

		const THIS_VIEW_ID = $scope.getCurrentViewId();

		$scope.tracks = null;

		$scope.getCurrentTrackIndex = function() {
			return listIsPlaying() ? $scope.$parent.currentTrackIndex : null;
		};

		// Remove chosen track from the list
		$scope.removeTrack = function(entry) {
			let listId = $scope.playlist.id;

			// Remove the element first from our internal array, without recreating the whole array.
			// Doing this before the HTTP request improves the perceived performance.
			libraryService.removeFromPlaylist(listId, entry.index);

			if (listIsPlaying()) {
				let playingIndex = $scope.getCurrentTrackIndex();
				if (entry.index <= playingIndex) {
					--playingIndex;
				}
				playQueueService.onPlaylistModified($scope.tracks, playingIndex);
			}

			Restangular.one('playlists', listId).all('remove').post({index: entry.index}).then(function (result) {
				$scope.playlist.updated = result.updated;
			});
		};

		function play(startIndex = null) {
			let id = 'playlist-' + $scope.playlist.id;
			playQueueService.setPlaylist(id, $scope.tracks, startIndex);
			playQueueService.publish('play');
		}

		// Call playQueueService to play all songs in the current playlist from the beginning
		$scope.onHeaderClick = function() {
			play();
		};

		// Play the list, starting from a specific track
		$scope.onTrackClick = function(track) {
			// play/pause if currently playing list item clicked
			if ($scope.getCurrentTrackIndex() === track.index) {
				playQueueService.publish('togglePlayback');
			}
			// on any other list item, start playing the list from this item
			else {
				play(track.index);
			}
		};

		$scope.draggedIndex = -1;

		$scope.getDraggable = function(index) {
			$scope.draggedIndex = index;
			let track = $scope.tracks[index].track;
			return {
				track: track ? track.id : null,
				srcIndex: index
			};
		};

		$rootScope.subscribe('ANGULAR_DRAG_END', $scope, () => $scope.draggedIndex = -1);

		$scope.reorderDrop = function(draggable, dstIndex) {
			let listId = $scope.playlist.id;
			let srcIndex = draggable.srcIndex;

			libraryService.reorderPlaylist($scope.playlist.id, srcIndex, dstIndex);

			if (listIsPlaying()) {
				let playingIndex = $scope.getCurrentTrackIndex();
				if (playingIndex === srcIndex) {
					playingIndex = dstIndex;
				}
				else {
					if (playingIndex > srcIndex) {
						--playingIndex;
					}
					if (playingIndex >= dstIndex) {
						++playingIndex;
					}
				}
				playQueueService.onPlaylistModified($scope.tracks, playingIndex);
			}

			Restangular.one('playlists', listId).all('reorder').post({fromIndex: srcIndex, toIndex: dstIndex}).then(function (result) {
				$scope.playlist.updated = result.updated;
			});
		};

		$scope.allowDrop = function(draggable, dstIndex) {
			return ('srcIndex' in draggable) && (draggable.srcIndex != dstIndex);
		};

		$scope.updateHoverStyle = function(dstIndex) {
			let element = $('.playlist-area .track-list');
			if ($scope.draggedIndex > dstIndex) {
				element.removeClass('insert-below');
				element.addClass('insert-above');
			} else if ($scope.draggedIndex < dstIndex) {
				element.removeClass('insert-above');
				element.addClass('insert-below');
			} else {
				element.removeClass('insert-above');
				element.removeClass('insert-below');
			}
		};

		$rootScope.subscribe('scrollToTrack', $scope, (_event, trackId) => {
			if ($scope.$parent) {
				const currentIdx = $scope.getCurrentTrackIndex();
				let index;

				// There may be more than one playlist entry with the same track ID.
				// Prefer to scroll to the currently playing entry if the requested
				// track ID matches that. Otherwise scroll to the first match.
				if (currentIdx !== null && $scope.tracks[currentIdx].track.id == trackId) {
					index = currentIdx;
				} else {
					index = _.findIndex($scope.tracks, (entry) => entry.track.id == trackId);
				}

				// Because of the virtualization, the target element might not exist in the DOM tree yet.
				// Hence, we can't just scroll to the element but need to manually calculate, where that
				// element should be. The vs-repeat directive will instantiate the element once we are there.

				// While searching, the effective index within the visible items may differ from the original index
				if ($rootScope.searchMode) {
					for (let i = index; i >= 0; --i) {
						if (!$scope.tracks[i].searchMatched) {
							--index;
						}
					}
				}

				const itemHeight = $('.vs-repeat-repeated-element').outerHeight();
				const offset = itemHeight * index + $('.track-list').position().top - $scope.scrollOffset();
				const animationTime = 500;
				OCA.Music.Utils.getScrollContainer().scrollTo(0, offset, animationTime);
			}
		});

		$scope.entryNotHidden = function(entry) {
			return !$rootScope.searchMode || entry.searchMatched;
		};

		$timeout(initViewFromRoute);

		// Reload the view if the currently viewed playlist got updated (by import from file)
		libraryService.subscribe('playlistUpdated', $scope, (_event, playlistId) => {
			if ($scope.playlist?.id == playlistId) {
				initViewFromRoute();
			}
		});

		// Reload the view if the collection is updated (e.g. by change of the library path)
		libraryFactory.subscribe('collectionUpdating', $scope, initViewFromRoute);

		function listIsPlaying() {
			return ($rootScope.playingView === $scope.getCurrentViewId());
		}

		function initViewFromRoute() {
			$scope.tracks = null;
			libraryFactory.getPlaylists().then(() => {
				if ($routeParams.playlistId) {
					let playlist = libraryService.getPlaylist($routeParams.playlistId);
					if (playlist) {
						$scope.playlist = playlist;
						$scope.tracks = playlist.tracks;
					}
					else {
						OCA.Music.Dialogs.showNotification(gettextCatalog.getString('Requested entry was not found'));
						window.location.hash = '#/';
					}
				}
				$timeout(() => {
					$rootScope.$emit('viewActivated', THIS_VIEW_ID);
					$scope.$broadcast('vsRepeatTrigger'); // the virtual scroller often needs this manual trigger for the first draw
				});
			});
		}
	}
]);
