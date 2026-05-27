<div id="favorite-toggle" ng-show="entity">
	<span class="fav-button icon-star" ng-click="setFavorite(1)" ng-if="!entity.favorite && !busy"
		title="{{ 'Set favorite' | translate }}" aria-label="{{ 'Set favorite' | translate }}"></span>
	<span class="fav-button icon-starred" ng-click="setFavorite(0)" ng-if="entity.favorite && !busy"
		title="{{ 'Unset favorite' | translate }}" aria-label="{{ 'Unset favorite' | translate }}"></span>
	<span class="icon-loading inline" ng-if="busy"></span>
</div>