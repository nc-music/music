/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2020 - 2026
 */


angular.module('Music').controller('RadioViewController', [
	'$rootScope', '$scope', 'playQueueService', 'libraryService', 'libraryFactory', 'gettextCatalog', 'Restangular', '$timeout',
	function ($rootScope, $scope, playQueueService, libraryService, libraryFactory, gettextCatalog, Restangular, $timeout) {

		const THIS_VIEW_ID = $scope.getCurrentViewId();

		const INCREMENTAL_LOAD_STEP = 1000;
		$scope.incrementalLoadLimit = INCREMENTAL_LOAD_STEP;
		$scope.stations = null;

		$scope.getCurrentStationIndex = function() {
			return listIsPlaying() ? $scope.$parent.currentTrackIndex : null;
		};

		$scope.deleteStation = function(station) {
			OC.dialogs.confirm(
				gettextCatalog.getString('Are you sure to remove the radio station "{{ name }}"?', { name: station.name || station.stream_url }),
				gettextCatalog.getString('Remove radio station'),
				function(confirmed) {
					if (confirmed) {
						doDeleteStation(station);
					}
				},
				true
			);
		};

		function doDeleteStation(station) {
			station.busy = true;

			Restangular.one('radio', station.id).remove().then(
				function() {
					station.busy = false;
					let removedIndex = libraryService.removeRadioStation(station.id);
					// Remove also from the play queue if the radio is currently playing
					if (listIsPlaying()) {
						let playingIndex = $scope.getCurrentStationIndex();
						if (removedIndex <= playingIndex) {
							--playingIndex;
						}
						playQueueService.onPlaylistModified($scope.stations, playingIndex);
					}
					// Fire an event to tell the alphabet navigation about the change. This must happen asynchronously
					// to ensure that the alphabet navigation has up-to-date item count available when it handles the event.
					$timeout(() => $rootScope.$emit('viewContentChanged'));
				},
				function (error) {
					station.busy = false;
					const errMsg = gettextCatalog.getString('Failed to delete the radio station:');
					OCA.Music.Dialogs.showNotification(errMsg + ' ' + error.status);
				}
			);
		}

		function play(startIndex = null) {
			playQueueService.setPlaylist('radio', $scope.stations, startIndex);
			playQueueService.publish('play');
		}

		// Call playQueueService to play all songs in the current playlist from the beginning
		$scope.onHeaderClick = function() {
			play();
		};

		// Play the list, starting from a specific track
		$scope.onStationClick = function(stationIndex) {
			// play/pause if currently playing list item clicked
			if ($scope.getCurrentStationIndex() === stationIndex) {
				playQueueService.publish('togglePlayback');
			}
			// on any other list item, start playing the list from this item
			else {
				play(stationIndex);
			}
		};

		$rootScope.subscribe('scrollToStation', $scope, (_event, stationId) => {
			if ($scope.$parent) {
				$scope.$parent.scrollToItem('radio-station-' + stationId);
			}
		});

		// Reload the view if the stations got updated (by import from file or renaming a station)
		libraryService.subscribe('playlistUpdated', $scope, (_event, playlistId, onlyReorder) => {
			if (playlistId === 'radio') {
				if (onlyReorder !== true) {
					initView();
				}

				if (listIsPlaying()) {
					let playingIndex = _.findIndex($scope.stations, { track: $scope.$parent.currentTrack });
					playQueueService.onPlaylistModified($scope.stations, playingIndex);
				}

				// Fire an event to tell the alphabet navigation about the change. This must happen asynchronously
				// to ensure that the alphabet navigation has up-to-date item count available when it handles the event.
				$timeout(() => $rootScope.$emit('viewContentChanged'));
			}
		});

		function listIsPlaying() {
			return ($rootScope.playingView === $scope.getCurrentViewId());
		}

		/**
		 * Two functions for the alphabet-navigation directive integration
		 */
		$scope.getStationTitle = function(index) {
			let station = $scope.stations[index].track;
			return station.name;
		};
		$scope.getStationElementId = function(index) {
			return 'radio-station-' + $scope.stations[index].track.id;
		};

		libraryFactory.getRadioStations().then((_stations) => initView());

		function initView() {
			$scope.incrementalLoadLimit = 0;
			$scope.stations = libraryService.getAllRadioStations();
			$timeout(showMore);
		}

		/**
		 * Increase number of shown stations asynchronously step-by-step until
		 * they are all visible. This is to avoid script hanging up for too
		 * long on huge collections.
		 */
		function showMore() {
			// show more entries only if the view is not already (being) deactivated
			if ($scope.$parent) {
				$scope.incrementalLoadLimit += INCREMENTAL_LOAD_STEP;
				if ($scope.incrementalLoadLimit < $scope.stations.length) {
					$timeout(showMore);
				} else {
					$rootScope.$emit('viewActivated', THIS_VIEW_ID);
				}
			}
		}
	}
]);
