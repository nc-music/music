/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

import * as SnapJs from 'vendor/snapjs/snap';
import { isRTL } from '@nextcloud/l10n';

angular.module('Music').controller('SnapController', ['$rootScope', '$scope',
function ($rootScope, $scope) {
	const SNAPPER_OPEN = isRTL() ? 'right' : 'left';
	const SNAPPER_CLOSE = isRTL() ? 'left' : 'right';

	const snapper = new SnapJs.Snap({
		element: document.getElementById('app-content'),
		disable: SNAPPER_CLOSE,
		maxPosition: 300,
		minPosition: -300, // used for RTL
		minDragDistance: 100
	});

	$scope.toggle = () => {
		if (snapper.state().state == SNAPPER_OPEN) {
			snapper.close();
		} else {
			snapper.open(SNAPPER_OPEN);
		}
	};

	$rootScope.$on('closeSnapper', () => snapper.close());

	const toggleSnapperOnSize = () => {
		if ($(window).width() >= 1024) {
			snapper.close();
			snapper.disable();
		} else {
			snapper.enable();
		}
	};

	$(window).on('resize', _.debounce(toggleSnapperOnSize, 250));

	// initial call
	toggleSnapperOnSize();

}]);
