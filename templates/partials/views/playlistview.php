<div id="playlist-view" class="view-container playlist-area" ng-show="!loading">
	<h1>
		<span ng-click="onHeaderClick()">
			<span>{{ playlist.name }}</span>
			<img class="play svg" alt="{{ 'Play' | translate }}" src="<?php \OCA\Music\Utility\HtmlUtil::printSvgPath('play-small') ?>"/>
		</span>
	</h1>
	<ul class="track-list" ng-if="tracks" vs-repeat="{scrollParent: '#app-content', scrollMargin: 3000}" vs-repeat-options="{latch: draggedIndex >= 0}">
		<li ng-repeat="entry in tracks | filter:entryNotHidden"
			ng-init="song = entry.track"
			id="{{ 'playlist-track-' + entry.index }}"
			data-track-id="{{ ::song.id }}"
			ui-on-drop="reorderDrop($data, entry.index)"
			ui-on-drag-enter="updateHoverStyle(entry.index)"
			drop-validate="allowDrop($data, entry.index)"
			drag-hover-class="drag-hover"
		>
			<div class="playlist-item-info" ng-click="onTrackClick(entry)" ui-draggable="true" drag="getDraggable(entry.index)"
				ng-class="{current: getCurrentTrackIndex() === entry.index, playing: playing}"
			>
				<span class="ordinal muted">{{ entry.index + 1 }}.</span>
				<div class="albumart" albumart="::song.album"></div>
				<div class="play-pause overlay"></div>
				<div class="title-lines">
					<div>{{ ::song.title }}</div>
					<div class="muted">{{ ::song.artist.name }}</div>
				</div>
			</div>
			<button class="action icon-details" ng-click="showTrackDetails(song.id)"
				aria-label="{{ 'Details' | translate }}" title="{{ 'Details' | translate }}"
				ng-if="song.type != 'error'"></button>
			<button class="action icon-close" ng-click="removeTrack(entry)"
				aria-label="{{ 'Remove' | translate }}" title="{{ 'Remove track from playlist' | translate }}"></button>
		</li>
	</ul>

	<div class="emptycontent" ng-show="playlist.tracks.length == 0 && !scanning && !toScan && !noMusicAvailable">
		<div class="icon-audio svg"></div>
		<div>
			<h2 translate>No tracks</h2>
			<p translate>Add tracks with drag and drop from Albums or other playlists</p>
		</div>
	</div>

</div>
