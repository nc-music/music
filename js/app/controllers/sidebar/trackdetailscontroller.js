/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2018 - 2026
 */


angular.module('Music').controller('TrackDetailsController', [
	'$rootScope', '$scope', 'Restangular', 'libraryService',
	function ($rootScope, $scope, Restangular, libraryService) {

		$scope.selectedTab = 'general';

		function resetContents() {
			$scope.track = null;
			$scope.details = null;
			$scope.resetLastFmData();
		}
		resetContents();

		function getFileId() {
			let files = $scope.track.files;
			return files[Object.keys(files)[0]];
		}

		function toArray(obj) {
			return _.map(obj, (val, key) => {
				return {key: key.toLowerCase(), value: Array.isArray(val) ? val : [val]};
			});
		}

		function isFloat(n) {
			return typeof n === 'number' && Math.floor(n) !== n;
		}

		function showDetails(trackId) {
			if (!$scope.track || trackId != $scope.track.id) {
				resetContents();
				$scope.track = libraryService.getTrack(trackId);

				let albumart = $('#app-sidebar .albumart');
				albumart.css('background-image', '').css('height', '0');

				let fileId = getFileId();
				$('#path').attr('href', OC.generateUrl('/f/' + fileId));

				Restangular.one('files', fileId).one('details').get().then(function(result) {
					if (result.picture) {
						albumart.css('background-image', 'url("' + result.picture + '")');
						albumart.css('height', ''); // remove the inline height and use the one from the css file
					}

					result.tags = toArray(result.tags);
					result.fileinfo = toArray(result.fileinfo);
					$scope.details = result;

					if (($scope.selectedTab == 'lyrics' && !result.lyrics)
							|| ($scope.selectedTab == 'lastfm' && (!result.lastfm || !result.lastfm.track))) {
						// selected tab is not available on this track => select 'general' tab
						$scope.selectedTab = 'general';
					}

					if (result.lastfm) {
						$scope.setLastfmTrackInfo(result.lastfm);
					}

					$scope.$parent.adjustFixedPositions();
				});
			}
		}

		$scope.$watch('contentId', function(newId) {
			if (newId !== null) {
				showDetails(newId);
			} else {
				resetContents();
			}
		});

		$rootScope.subscribe('playerProgress', $scope, (_event, time) => {
			// check if we are viewing time-synced lyrics of the currently playing track
			if ($scope.details && $scope.details.lyrics && $scope.details.lyrics.synced
					&& $scope.$parent.currentTrack.id == $scope.track.id) {
				// Check if the highlighted row needs to change. First find the last row
				// which has been already reached by the playback.
				let allRows = $('#app-sidebar .lyrics');
				for (var i = allRows.length - 1; i >= 0; --i) {
					let curRow = $(allRows[i]);
					if (Number(curRow.attr('data-timestamp')) <= time) {
						if (!curRow.hasClass('highlight')) {
							// highlight actually needs to move
							allRows.removeClass('highlight');
							curRow.addClass('highlight');
						}
						break;
					}
				}
			}
		});

		$scope.$watch('selectedTab', $scope.$parent.adjustFixedPositions);

		$scope.formatDetailValue = function(value, key=null) {
			if (value instanceof Object) {
				return Object.entries(value).map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join('; ') : v}`).join('<br/>');
			} else if (key == 'sample_rate') {
				return (value/1000).toFixed(1) + ' kHz';
			} else if (key == 'bitrate') {
				return (value/1000).toFixed(0) + ' kbps';
			} else if (key?.match(/^musicbrainz.*id$/)) {
				return $scope.mbidLink(value);
			} else if (isFloat(value)) {
				// limit the number of shown digits on floating point numbers
				return Number(value.toPrecision(6));
			} else {
				return value;
			}
		};

		$scope.valueTooltip = function(value, key) {
			// Show the original value in the tooltip on those entries where some formatting is applied
			if (key == 'sample_rate' || key == 'bitrate' || isFloat(value)) {
				return value;
			} else {
				return '';
			}
		};

		$scope.formatDetailName = function(rawName) {
			// abbreviate "musicbrainz" and "replaygain" in tag names to avoid truncation of long names in the sidebar
			rawName = rawName.replace(/musicbrainz/, 'mb');
			rawName = rawName.replace(/replaygain/, 'rg');

			switch (rawName) {
			case 'band':			return 'album artist';
			case 'albumartist':		return 'album artist';
			case 'tracktotal':		return 'total tracks';
			case 'totaltracks':		return 'total tracks';
			case 'part_of_a_set':	return 'disc number';
			case 'discnumber':		return 'disc number';
			case 'dataformat':		return 'format';
			case 'channelmode':		return 'channel mode';
			default:				return rawName.replace(/_/g, ' ');
			}
		};

		$scope.tagRank = function(tag) {
			switch (tag.key) {
			case 'title':			return 1;
			case 'artist':			return 2;
			case 'album':			return 3;
			case 'albumartist':		return 4;
			case 'album_artist':	return 4;
			case 'band':			return 4;
			case 'composer':		return 5;
			case 'lyricist':		return 5;
			case 'writer':			return 5;
			case 'part_of_a_set':	return 6;
			case 'discnumber':		return 6;
			case 'disc_number':		return 6;
			case 'track_number':	return 7;
			case 'tracknumber':		return 7;
			case 'track':			return 7;
			case 'totaltracks':		return 8;
			case 'tracktotal':		return 8;
			case 'genre':			return 9;
			case 'year':			return 10;
			case 'publisher':		return 11;
			case 'comment':			return 12;
			default:
				if (tag.key.match(/^musicbrainz.*id$/)) {
					return 150;
				} else if (tag.key.match(/^musicbrainz/)) {
					return 149;
				} else if (tag.key.startsWith('replaygain')) {
					return 200;
				} else {
					return 100;
				}
			}
		};

		$scope.tagAlwaysShown = function(tag) {
			// Show only the most important tags by default and hide the rest behind a "show more" button
			return tag.value && $scope.tagRank(tag) < 100;
		};

		$scope.tagShownWhenExpanded = function(tag) {
			return tag.value && $scope.tagRank(tag) >= 100;
		};

		$scope.anyCollapsibleTags = function(tags) {
			return _.some(tags, (tag) => $scope.tagShownWhenExpanded(tag));
		};

		$scope.tagHasDetails = function(tagKey, tagValue) {
			switch (tagKey) {
			case 'album':
				return $scope.track.album.name == tagValue;
			case 'artist':
			case 'albumartist':
			case 'album_artist':
			case 'band':
			case 'composer':
			case 'lyricist':
			case 'writer':
				return (libraryService.findArtistByName(tagValue) !== null);
			default:
				return false;
			}
		};

		$scope.showTagDetails = function(tagKey, tagValue) {
			switch (tagKey) {
			case 'album':
				$rootScope.$emit('showAlbumDetails', $scope.track.album.id);
				break;
			case 'artist':
			case 'albumartist':
			case 'album_artist':
			case 'band':
			case 'composer':
			case 'lyricist':
			case 'writer': {
				const artist = libraryService.findArtistByName(tagValue);
				if (artist !== null) {
					$rootScope.$emit('showArtistDetails', artist.id);
				}
				break;
			}
			default:
				// nothing
			}
		};
	}
]);
