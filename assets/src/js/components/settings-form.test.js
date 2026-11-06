/**
 * Settings form submission tests.
 *
 * Only the REST client is doubled. The serializer and the notice renderer run
 * for real, so a change that breaks the payload shape or stops rendering the
 * response surfaces here rather than passing against a mock of itself.
 */

jest.mock('../core/api.js', () => {
	const actual = jest.requireActual('../core/api.js');

	return { ...actual, request: jest.fn() };
});

import initSettingsForm from './settings-form.js';
import { request, ApiError } from '../core/api.js';

/**
 * Render one settings form.
 *
 * @param {string} route  Value for `data-lpm-route`, omitted when empty.
 * @param {string} fields Extra control markup.
 * @returns {HTMLFormElement} The form.
 */
function render(route = 'settings/general', fields = '') {
	const attribute = route ? ` data-lpm-route="${route}"` : '';

	document.body.innerHTML = `
		<div class="wrap">
			<form class="lpm-settings-form"${attribute}>
				<input name="global_currency" value="EUR">
				${fields}
				<button type="submit">Save changes</button>
			</form>
		</div>
	`;

	return document.querySelector('form');
}

/**
 * Let the submit handler's promise chain settle.
 *
 * @returns {Promise<void>} Resolves after the microtask queue drains.
 */
async function flush() {
	await Promise.resolve();
	await Promise.resolve();
	await Promise.resolve();
}

/**
 * Submit the form and let the handler's promise settle.
 *
 * @param {HTMLFormElement} form Form element.
 * @returns {Promise<void>} Resolves once the response has been rendered.
 */
async function submit(form) {
	form.dispatchEvent(
		new Event('submit', { bubbles: true, cancelable: true })
	);

	await flush();
}

/**
 * Read the rendered notice element.
 *
 * @returns {HTMLElement|null} Notice box.
 */
function notice() {
	return document.querySelector('#lpm-notices .notice');
}

describe('initSettingsForm', () => {
	beforeEach(() => {
		jest.clearAllMocks();
		request.mockResolvedValue({});
		document.body.innerHTML = '';
	});

	it('posts the serialized form to the route the tab declared', async () => {
		// Each tab carries its own route so saving one cannot touch another's
		// values.
		const form = render('settings/geolocation');
		initSettingsForm();

		await submit(form);

		expect(request).toHaveBeenCalledWith('settings/geolocation', {
			method: 'POST',
			data: { global_currency: 'EUR' },
		});
	});

	it('suppresses the native submit so the page does not reload', async () => {
		const form = render();
		initSettingsForm();

		const event = new Event('submit', { bubbles: true, cancelable: true });
		form.dispatchEvent(event);

		expect(event.defaultPrevented).toBe(true);
	});

	it('sends nothing when the form declares no route', async () => {
		const form = render('');
		initSettingsForm();

		await submit(form);

		expect(request).not.toHaveBeenCalled();
	});

	it('reports success with the message the server returned', async () => {
		request.mockResolvedValue({ message: 'Ayarlar kaydedildi.' });
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-success');
		expect(notice()).toHaveTextContent('Ayarlar kaydedildi.');
	});

	it('falls back to its own wording when the server sent no message', async () => {
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-success');
		expect(notice()).toHaveTextContent('Settings saved.');
	});

	it('treats a partially rejected save as a failure', async () => {
		// A 200 carrying rejected keys means some values did not store. Showing
		// "saved" there would tell the operator the opposite of the truth.
		request.mockResolvedValue({
			rejected: ['global_currency'],
			message: 'One value was rejected.',
		});
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent('One value was rejected.');
	});

	it('treats an empty rejected list as a clean save', async () => {
		request.mockResolvedValue({ rejected: [], message: 'Saved.' });
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-success');
	});

	it('surfaces the REST error message', async () => {
		request.mockRejectedValue(
			new ApiError('Sorry, you are not allowed to do that.', 403, 'rest_forbidden')
		);
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent(
			'Sorry, you are not allowed to do that.'
		);
	});

	it('hides an unexpected internal error behind its own wording', async () => {
		// A TypeError from a bug in this bundle is not something to print at an
		// operator; the generic failure is the honest thing to show.
		request.mockRejectedValue(new TypeError('x is not a function'));
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent('The settings could not be saved.');
	});

	it('disables the button and swaps its label while the request is in flight', async () => {
		let release;
		request.mockReturnValue(
			new Promise((resolve) => {
				release = resolve;
			})
		);

		const form = render();
		initSettingsForm();
		const button = form.querySelector('[type="submit"]');

		form.dispatchEvent(
			new Event('submit', { bubbles: true, cancelable: true })
		);
		await Promise.resolve();

		expect(button).toBeDisabled();
		expect(button).toHaveClass('lpm-btn-loading');
		expect(button).toHaveTextContent('Saving…');

		release({});
		await flush();

		expect(button).toBeEnabled();
		expect(button).not.toHaveClass('lpm-btn-loading');
		expect(button).toHaveTextContent('Save changes');
	});

	it('restores the button after a failure too', async () => {
		request.mockRejectedValue(new ApiError('Nope.', 500, 'x'));
		const form = render();
		initSettingsForm();

		await submit(form);

		expect(form.querySelector('[type="submit"]')).toBeEnabled();
	});

	it('saves a form that has no submit button', async () => {
		request.mockResolvedValue({ message: 'Saved without a button.' });
		const form = render();
		form.querySelector('[type="submit"]').remove();
		initSettingsForm();

		await submit(form);

		expect(notice()).toHaveTextContent('Saved without a button.');
	});

	it('clears the previous notice before each save', async () => {
		request.mockRejectedValue(new ApiError('First failure.', 500, 'x'));
		const form = render();
		initSettingsForm();
		await submit(form);

		request.mockResolvedValue({ message: 'Now saved.' });
		await submit(form);

		expect(document.querySelectorAll('#lpm-notices .notice')).toHaveLength(1);
		expect(notice()).toHaveTextContent('Now saved.');
	});

	describe('trusted CDN rows visibility', () => {
		const STRATEGY = `
			<div class="lpm-row" data-lpm-field="ip_detection_strategy">
				<label><input type="radio" name="ip_detection_strategy" value="strict" checked>Strict</label>
				<label><input type="radio" name="ip_detection_strategy" value="trusted_cdn">Trusted CDN</label>
			</div>
		`;
		const CDN_ROWS = `
			<div class="lpm-row" data-lpm-field="trusted_headers">
				<textarea name="trusted_headers">HTTP_CF_CONNECTING_IP</textarea>
			</div>
			<div class="lpm-row" data-lpm-field="trusted_country_headers">
				<textarea name="trusted_country_headers">HTTP_CF_IPCOUNTRY</textarea>
			</div>
			<div class="lpm-row" data-lpm-field="trusted_proxy_ranges">
				<textarea name="trusted_proxy_ranges">203.0.113.0/24</textarea>
			</div>
		`;
		const FIELDS = ['trusted_headers', 'trusted_country_headers', 'trusted_proxy_ranges'];

		function renderGeolocation() {
			return render('settings/geolocation', `${STRATEGY}${CDN_ROWS}`);
		}

		function rows() {
			return FIELDS.map((field) => document.querySelector(`[data-lpm-field="${field}"]`));
		}

		function choose(form, value) {
			const radio = form.querySelector(`input[value="${value}"]`);

			radio.checked = true;
			radio.dispatchEvent(new Event('change', { bubbles: true }));
		}

		it('hides all three rows on the strict strategy', () => {
			renderGeolocation();
			initSettingsForm();

			rows().forEach((row) => {
				expect(row.hidden).toBe(true);
				expect(row).toHaveClass('lpm-hidden');
			});
		});

		it('reveals all three rows once the trusted CDN strategy is chosen', () => {
			const form = renderGeolocation();
			initSettingsForm();

			choose(form, 'trusted_cdn');

			rows().forEach((row) => {
				expect(row.hidden).toBe(false);
				expect(row).not.toHaveClass('lpm-hidden');
			});
		});

		it('hides them again on going back to strict', () => {
			const form = renderGeolocation();

			form.querySelector('input[value="trusted_cdn"]').checked = true;
			initSettingsForm();

			rows().forEach((row) => expect(row.hidden).toBe(false));

			choose(form, 'strict');

			rows().forEach((row) => expect(row.hidden).toBe(true));
		});

		it('keeps the hidden values so switching back does not retype them', () => {
			const form = renderGeolocation();
			initSettingsForm();

			expect(form.querySelector('[name="trusted_headers"]').value).toBe('HTTP_CF_CONNECTING_IP');

			choose(form, 'trusted_cdn');

			expect(form.querySelector('[name="trusted_headers"]').value).toBe('HTTP_CF_CONNECTING_IP');
		});
	});
});
