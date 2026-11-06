/**
 * Countries & groups tab bundle.
 */

import initCountryCurrencyTable from '../components/country-currency-table.js';
import initHelpPopovers from '../components/help-popover.js';

document.addEventListener('DOMContentLoaded', () => {
	initCountryCurrencyTable();
	initHelpPopovers();
});
