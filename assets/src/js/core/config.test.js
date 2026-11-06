/**
 * Runtime configuration tests.
 *
 * The module snapshots `window.d6nLpmAdmin` at import time, so every case
 * resets the registry and imports again rather than mutating the export.
 */

/**
 * Import the module against a given global.
 *
 * @param {object|undefined} admin Value for `window.d6nLpmAdmin`.
 * @returns {Promise<object>} Module namespace.
 */
async function load(admin) {
	jest.resetModules();

	if (admin === undefined) {
		delete window.d6nLpmAdmin;
	} else {
		window.d6nLpmAdmin = admin;
	}

	return import('./config.js');
}

afterEach(() => {
	delete window.d6nLpmAdmin;
});

describe('config', () => {
	it('falls back to a usable shape when PHP published nothing', async () => {
		// The bundle must not throw on a screen that failed to localize; it just
		// reports itself unconfigured.
		const { default: config, isConfigured } = await load(undefined);

		expect(config).toEqual({
			root: '',
			namespace: 'd6n-lpm/v1',
			nonce: '',
			bundle: '',
			tab: '',
			i18n: {},
		});
		expect(isConfigured()).toBe(false);
	});

	it('takes what PHP published over the fallback', async () => {
		const { default: config } = await load({
			root: 'http://example.com/wp-json/d6n-lpm/v1/',
			nonce: 'abc123',
			bundle: 'settings-general',
		});

		expect(config.root).toBe('http://example.com/wp-json/d6n-lpm/v1/');
		expect(config.nonce).toBe('abc123');
		expect(config.bundle).toBe('settings-general');
		// Untouched keys keep their fallback.
		expect(config.namespace).toBe('d6n-lpm/v1');
	});

	it('is configured only when both the root and the nonce are present', async () => {
		// Either half alone cannot authenticate a request, so neither counts.
		const withoutNonce = await load({ root: 'http://example.com/', nonce: '' });
		expect(withoutNonce.isConfigured()).toBe(false);

		const withoutRoot = await load({ root: '', nonce: 'abc123' });
		expect(withoutRoot.isConfigured()).toBe(false);

		const both = await load({ root: 'http://example.com/', nonce: 'abc123' });
		expect(both.isConfigured()).toBe(true);
	});

	it('returns a localized string when PHP supplied one', async () => {
		const { text } = await load({ i18n: { saveFailed: 'Kaydedilemedi.' } });

		expect(text('saveFailed', 'Save failed.')).toBe('Kaydedilemedi.');
	});

	it('falls back to the supplied English when the key is missing or empty', async () => {
		const { text } = await load({ i18n: { saveFailed: '' } });

		expect(text('saveFailed', 'Save failed.')).toBe('Save failed.');
		expect(text('neverPublished', 'Default.')).toBe('Default.');
		expect(text('neverPublished')).toBe('');
	});
});
