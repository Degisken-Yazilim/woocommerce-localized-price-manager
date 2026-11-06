/**
 * Product edit screen bundle.
 */

import initFlagSwitches from '../components/flag-switch.js';
import initHelpPopovers from '../components/help-popover.js';
import initProductPricingTable from '../components/product-pricing-table.js';
import initRepeatableRows from '../components/repeatable-rows.js';

document.addEventListener('DOMContentLoaded', () => {
	initRepeatableRows({
		body: '#lpm_country_pricing_rows',
		template: '#lpm-country-row-template',
		addButton: '#lpm_add_country_row_btn',
	});

	initFlagSwitches();
	initHelpPopovers();
	initProductPricingTable();
});
