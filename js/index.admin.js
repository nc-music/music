/**
 * ownCloud - Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Matthew Wells
 * @copyright Matthew Wells 2026
 */

import MusicAdmin from './admin/MusicAdmin';
import ScrobbleSettings from './admin/Scrobbler';

const app = new MusicAdmin([ScrobbleSettings]);
app.mount(document.querySelector('#admin-music'));
/**
 * `require` all modules in the given webpack context
 */
function requireAll(context) {
	context.keys().forEach(context);
}


requireAll(require.context('../css/admin', false));