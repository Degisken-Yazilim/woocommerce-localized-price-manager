/**
 * General settings tab bundle.
 */

import initSettingsForm from '../components/settings-form.js';
import initHelpPopovers from '../components/help-popover.js';

document.addEventListener('DOMContentLoaded', () => {
	initSettingsForm();
	initHelpPopovers();
});
