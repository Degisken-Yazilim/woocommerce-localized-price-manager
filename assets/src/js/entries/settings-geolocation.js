/**
 * Geolocation settings tab bundle.
 */

import initSettingsForm from '../components/settings-form.js';
import initHelpPopovers from '../components/help-popover.js';
import initOrderedLists from '../components/ordered-list.js';

document.addEventListener('DOMContentLoaded', () => {
	initSettingsForm();
	initHelpPopovers();
	initOrderedLists();
});
