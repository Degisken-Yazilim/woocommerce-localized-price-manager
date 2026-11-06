/**
 * Minimal REST client for the `d6n-lpm/v1` namespace.
 */

import config, { text, isConfigured } from './config.js';

/**
 * Error carrying the HTTP status and the REST error code.
 */
export class ApiError extends Error {
	/**
	 * @param {string} message Human readable message.
	 * @param {number} status  HTTP status code.
	 * @param {string} code    REST error code.
	 */
	constructor(message, status = 0, code = '') {
		super(message);
		this.name = 'ApiError';
		this.status = status;
		this.code = code;
	}
}

/**
 * Build the absolute URL of a route inside the plugin namespace.
 *
 * @param {string} path Route path, without the namespace.
 * @returns {string} Absolute URL.
 */
export function url(path) {
	const base = config.root.endsWith('/') ? config.root : `${config.root}/`;
	return base + String(path).replace(/^\/+/, '');
}

/**
 * Send a request to the plugin REST namespace.
 *
 * @param {string} path            Route path, without the namespace.
 * @param {object} options         Request options.
 * @param {string} options.method  HTTP method.
 * @param {object} options.data    JSON body, omitted for GET.
 * @returns {Promise<object>} Parsed response payload.
 */
export async function request(path, { method = 'GET', data = null } = {}) {
	if (!isConfigured()) {
		throw new ApiError(text('networkError', 'Missing REST configuration.'));
	}

	const init = {
		method,
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'X-WP-Nonce': config.nonce,
		},
	};

	if (data !== null && method !== 'GET') {
		init.headers['Content-Type'] = 'application/json';
		init.body = JSON.stringify(data);
	}

	let response;

	try {
		response = await fetch(url(path), init);
	} catch (error) {
		throw new ApiError(
			text('networkError', error.message),
			0,
			'lpm_network_error'
		);
	}

	let payload = null;

	try {
		payload = await response.json();
	} catch (_error) {
		payload = null;
	}

	if (!response.ok) {
		const message =
			(payload && payload.message) ||
			text('saveFailed', 'Request failed.');
		const code = (payload && payload.code) || 'lpm_request_failed';
		throw new ApiError(message, response.status, code);
	}

	return payload === null ? {} : payload;
}

export default { request, url, ApiError };
