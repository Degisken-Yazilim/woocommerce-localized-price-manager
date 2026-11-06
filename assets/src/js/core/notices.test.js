/**
 * Notice rendering tests.
 */

import { notice, success, failure, clear } from './notices.js';

/**
 * Read the lazily created container.
 *
 * @returns {HTMLElement|null} Container element.
 */
function container() {
	return document.getElementById('lpm-notices');
}

describe('notices', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('creates the container as a polite live region', () => {
		notice('Saved.');

		// Without role and aria-live a screen reader announces nothing: the
		// notice appears silently for anyone not looking at that corner.
		expect(container()).toHaveAttribute('role', 'status');
		expect(container()).toHaveAttribute('aria-live', 'polite');
	});

	it('inserts itself at the top of .wrap so the notice is the first thing on the page', () => {
		document.body.innerHTML = '<div class="wrap"><h1>Settings</h1></div>';

		notice('Saved.');

		const wrap = document.querySelector('.wrap');

		expect(wrap.firstChild).toBe(container());
	});

	it('falls back to the body when the screen has no .wrap', () => {
		notice('Saved.');

		expect(container().parentElement).toBe(document.body);
	});

	it('renders the message inside core notice markup', () => {
		notice('Saved.', 'warning');

		const box = container().querySelector('div');

		expect(box).toHaveClass('notice', 'notice-warning', 'is-dismissible');
		expect(box.querySelector('p')).toHaveTextContent('Saved.');
	});

	it('replaces the previous notice rather than stacking', () => {
		notice('First.');
		notice('Second.');

		expect(container().querySelectorAll('div.notice')).toHaveLength(1);
		expect(container()).toHaveTextContent('Second.');
		expect(container()).not.toHaveTextContent('First.');
	});

	it('treats the message as text, not markup', () => {
		notice('<img src=x onerror=alert(1)>');

		// textContent rather than innerHTML is the whole defence here: a message
		// can carry a REST error string that originated outside the plugin.
		expect(container().querySelector('img')).toBeNull();
		expect(container().querySelector('p')).toHaveTextContent(
			'<img src=x onerror=alert(1)>'
		);
	});

	it('labels the dismiss button with the published screen reader text', async () => {
		// Core only labels the notices that were in the HTML at load time, so an
		// unlabelled button here is announced as "button" and nothing else. The
		// text comes from PHP, which is what keeps it translatable.
		jest.resetModules();
		window.d6nLpmAdmin = { i18n: { dismissNotice: 'Bu bildirimi kapat.' } };

		const { notice: localized } = await import('./notices.js');

		localized('Saved.');

		const label = container().querySelector(
			'button.notice-dismiss .screen-reader-text'
		);

		expect(label).toHaveTextContent('Bu bildirimi kapat.');

		delete window.d6nLpmAdmin;
		jest.resetModules();
	});

	it('removes only the dismissed notice when its button is clicked', () => {
		notice('Saved.');

		container().querySelector('button.notice-dismiss').click();

		expect(container().querySelector('div.notice')).toBeNull();
		expect(container()).not.toBeNull();
	});

	it('labels success and error notices with their own class', () => {
		success('Done.');
		expect(container().querySelector('div')).toHaveClass('notice-success');

		failure('Broken.');
		expect(container().querySelector('div')).toHaveClass('notice-error');
	});

	it('empties the container on clear', () => {
		notice('Saved.');

		clear();

		expect(container()).toBeEmptyDOMElement();
	});
});
