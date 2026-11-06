/**
 * Product category term screen bundle.
 */

import initFlagSwitches from '../components/flag-switch.js';
import initHelpPopovers from '../components/help-popover.js';
import initRepeatableRows from '../components/repeatable-rows.js';

document.addEventListener('DOMContentLoaded', () => {
	initRepeatableRows({
		body: '.lpm-category-pricing-rows',
		template: '.lpm-category-row-template',
		addButton: '.lpm-add-category-row-btn',
	});

	initFlagSwitches();
	initHelpPopovers();
});
