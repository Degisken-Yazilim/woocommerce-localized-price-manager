/**
 * Runtime configuration handed over by `wp_localize_script()`.
 */

const FALLBACK = {
	root: '',
	namespace: 'd6n-lpm/v1',
	nonce: '',
	bundle: '',
	tab: '',
	i18n: {},
};

const config = { ...FALLBACK, ...(window.d6nLpmAdmin || {}) };

export default config;

/**
 * Look up a localized string, falling back to the supplied default.
 *
 * @param {string} key      Message key.
 * @param {string} fallback Text used when PHP did not provide the key.
 * @returns {string} Message.
 */
export function text(key, fallback = '') {
	return config.i18n && config.i18n[key] ? config.i18n[key] : fallback;
}

/**
 * Report whether the page was booted with a usable REST configuration.
 *
 * @returns {boolean} True when requests can be made.
 */
export function isConfigured() {
	return Boolean(config.root && config.nonce);
}
