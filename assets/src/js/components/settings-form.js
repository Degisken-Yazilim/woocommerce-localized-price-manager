/**
 * Turns the server rendered settings form into an independent REST save.
 */

import { request, ApiError } from '../core/api.js';
import { text } from '../core/config.js';
import { success, failure, clear } from '../core/notices.js';
import serializeForm from '../core/serialize.js';

const FORM_SELECTOR = '.lpm-settings-form';
const STRATEGY_SELECTOR = 'input[name="ip_detection_strategy"]';
const CDN_STRATEGY = 'trusted_cdn';
const CDN_ROW_SELECTORS = [
	'[data-lpm-field="trusted_headers"]',
	'[data-lpm-field="trusted_country_headers"]',
	'[data-lpm-field="trusted_proxy_ranges"]',
];

/**
 * Show or hide the three rows that only the Trusted CDN strategy uses.
 *
 * @param {HTMLFormElement} form Form element.
 * @returns {void}
 */
function syncCdnRowVisibility(form) {
	const selected = form.querySelector(`${STRATEGY_SELECTOR}:checked`);
	const isCdn = Boolean(selected) && selected.value === CDN_STRATEGY;

	CDN_ROW_SELECTORS.forEach((selector) => {
		const row = form.querySelector(selector);

		if (!row) {
			return;
		}

		row.hidden = !isCdn;
		row.classList.toggle('lpm-hidden', !isCdn);
	});
}

/**
 * Bind the conditional visibility of every field that depends on another one.
 *
 * @param {HTMLFormElement} form Form element.
 * @returns {void}
 */
function bindConditionalFields(form) {
	syncCdnRowVisibility(form);

	form.querySelectorAll(STRATEGY_SELECTOR).forEach((radio) => {
		radio.addEventListener('change', () => {
			syncCdnRowVisibility(form);
		});
	});
}

/**
 * Toggle the busy state of the submit button.
 *
 * @param {HTMLFormElement} form   Form element.
 * @param {boolean}         isBusy Whether a request is in flight.
 * @returns {void}
 */
function setBusy(form, isBusy) {
	const button = form.querySelector('[type="submit"]');

	if (!button) {
		return;
	}

	button.disabled = isBusy;
	button.classList.toggle('lpm-btn-loading', isBusy);

	if (isBusy) {
		button.dataset.lpmLabel = button.textContent;
		button.textContent = text('saving', 'Saving…');
	} else if (button.dataset.lpmLabel) {
		button.textContent = button.dataset.lpmLabel;
	}
}

/**
 * Submit one settings form.
 *
 * @param {HTMLFormElement} form Form element.
 * @returns {Promise<void>} Resolves once the response has been rendered.
 */
async function submit(form) {
	const route = form.dataset.lpmRoute;

	if (!route) {
		return;
	}

	clear();
	setBusy(form, true);

	try {
		const result = await request(route, {
			method: 'POST',
			data: serializeForm(form),
		});

		if (Array.isArray(result.rejected) && result.rejected.length > 0) {
			failure(
				result.message ||
					text('saveFailed', 'Some values were rejected.')
			);
		} else {
			success(result.message || text('saved', 'Settings saved.'));
		}
	} catch (error) {
		const message =
			error instanceof ApiError
				? error.message
				: text('saveFailed', 'The settings could not be saved.');
		failure(message);
	} finally {
		setBusy(form, false);
	}
}

/**
 * Bind every settings form on the page.
 *
 * @returns {void}
 */
export default function initSettingsForm() {
	document.querySelectorAll(FORM_SELECTOR).forEach((form) => {
		bindConditionalFields(form);

		form.addEventListener('submit', (event) => {
			event.preventDefault();
			submit(form);
		});
	});
}
