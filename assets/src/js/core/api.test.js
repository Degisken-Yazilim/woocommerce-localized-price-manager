/**
 * REST client tests.
 *
 * `api.js` reads the config module, which snapshots `window.d6nLpmAdmin` at
 * import time, so each case sets the global and re-imports.
 */

const ROOT = 'http://example.com/wp-json/d6n-lpm/v1/';

/**
 * Import the client against a given global.
 *
 * @param {object} admin Value for `window.d6nLpmAdmin`.
 * @returns {Promise<object>} Module namespace.
 */
async function load(admin = { root: ROOT, nonce: 'abc123' }) {
	jest.resetModules();
	window.d6nLpmAdmin = admin;

	return import('./api.js');
}

/**
 * Install a fetch double resolving to the given response.
 *
 * @param {object} response Partial Response.
 * @returns {jest.Mock} The double.
 */
function stubFetch(response) {
	const fetchMock = jest.fn().mockResolvedValue({
		ok: true,
		status: 200,
		json: async () => ({}),
		...response,
	});

	global.fetch = fetchMock;

	return fetchMock;
}

afterEach(() => {
	delete window.d6nLpmAdmin;
	delete global.fetch;
});

describe('url', () => {
	it('joins the root and the path', async () => {
		const { url } = await load();

		expect(url('settings/general')).toBe(`${ROOT}settings/general`);
	});

	it('does not double the separator when either side carries one', async () => {
		const { url } = await load({ root: 'http://example.com/api', nonce: 'x' });

		expect(url('/settings/general')).toBe(
			'http://example.com/api/settings/general'
		);
	});
});

describe('request', () => {
	it('refuses to send anything when the config is incomplete', async () => {
		// Firing a request without a nonce would return 401 and surface as a
		// permissions problem, hiding the real cause: PHP never localized.
		const { request, ApiError } = await load({ root: '', nonce: '' });
		const fetchMock = stubFetch({});

		await expect(request('settings/general')).rejects.toThrow(ApiError);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('sends the nonce header and same-origin credentials', async () => {
		const { request } = await load();
		const fetchMock = stubFetch({ json: async () => ({ ok: true }) });

		await request('settings/general');

		const [, init] = fetchMock.mock.calls[0];

		expect(init.headers['X-WP-Nonce']).toBe('abc123');
		expect(init.credentials).toBe('same-origin');
	});

	it('sends no body on a GET even when data is supplied', async () => {
		const { request } = await load();
		const fetchMock = stubFetch({});

		await request('settings/general', { data: { enabled: true } });

		const [, init] = fetchMock.mock.calls[0];

		expect(init.body).toBeUndefined();
		expect(init.headers['Content-Type']).toBeUndefined();
	});

	it('serializes the body and declares JSON on a write', async () => {
		const { request } = await load();
		const fetchMock = stubFetch({});

		await request('settings/general', {
			method: 'POST',
			data: { enabled: true },
		});

		const [, init] = fetchMock.mock.calls[0];

		expect(init.method).toBe('POST');
		expect(init.headers['Content-Type']).toBe('application/json');
		expect(JSON.parse(init.body)).toEqual({ enabled: true });
	});

	it('returns the parsed payload', async () => {
		const { request } = await load();
		stubFetch({ json: async () => ({ global_currency: 'EUR' }) });

		await expect(request('settings/general')).resolves.toEqual({
			global_currency: 'EUR',
		});
	});

	it('returns an empty object when a successful response carries no JSON', async () => {
		const { request } = await load();
		stubFetch({
			json: async () => {
				throw new SyntaxError('Unexpected end of JSON input');
			},
		});

		await expect(request('settings/general')).resolves.toEqual({});
	});

	it('wraps a transport failure as a network ApiError', async () => {
		const { request } = await load();

		global.fetch = jest.fn().mockRejectedValue(new TypeError('Failed to fetch'));

		await expect(request('settings/general')).rejects.toMatchObject({
			name: 'ApiError',
			status: 0,
			code: 'lpm_network_error',
		});
	});

	it('carries the REST error message, status and code through', async () => {
		const { request } = await load();
		stubFetch({
			ok: false,
			status: 403,
			json: async () => ({
				message: 'Sorry, you are not allowed to do that.',
				code: 'rest_forbidden',
			}),
		});

		await expect(request('settings/general')).rejects.toMatchObject({
			message: 'Sorry, you are not allowed to do that.',
			status: 403,
			code: 'rest_forbidden',
		});
	});

	it('still reports a failure when the error response has no JSON body', async () => {
		// A 502 from a proxy arrives as HTML; the client must not swallow it.
		const { request } = await load();
		stubFetch({
			ok: false,
			status: 502,
			json: async () => {
				throw new SyntaxError('Unexpected token <');
			},
		});

		await expect(request('settings/general')).rejects.toMatchObject({
			status: 502,
			code: 'lpm_request_failed',
		});
	});
});
