/**
 * Exchange rates settings tab bundle.
 */

import initSettingsForm from '../components/settings-form.js';
import initHelpPopovers from '../components/help-popover.js';
import initOrderedLists from '../components/ordered-list.js';
import { request, ApiError } from '../core/api.js';
import { text } from '../core/config.js';
import { success, failure, clear } from '../core/notices.js';

/**
 * Bind the manual refresh button, when the page renders one.
 *
 * @returns {void}
 */
function initRefreshButton() {
	const button = document.getElementById('lpm-refresh-rates');

	if (!button) {
		return;
	}

	button.addEventListener('click', async (event) => {
		event.preventDefault();
		clear();
		button.disabled = true;

		try {
			const result = await request('exchange-rates/refresh', {
				method: 'POST',
			});
			success(result.message || text('saved', 'Exchange rates updated.'));
		} catch (error) {
			failure(
				error instanceof ApiError
					? error.message
					: text('saveFailed', 'The refresh failed.')
			);
		} finally {
			button.disabled = false;
		}
	});
}

document.addEventListener('DOMContentLoaded', () => {
	initSettingsForm();
	initHelpPopovers();
	initOrderedLists();
	initRefreshButton();
});
