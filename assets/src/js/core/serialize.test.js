/**
 * Serializer tests.
 *
 * These double as the smoke test for the whole frontend test chain: a passing
 * run proves babel-jest transforms the ES modules, jsdom provides a real DOM,
 * and jest-dom's matchers are loaded from jest.setup.js.
 */

import serializeForm from './serialize.js';

/**
 * Build a form from markup.
 *
 * @param {string} markup Inner HTML of the form.
 * @returns {HTMLFormElement} Form element attached to the document.
 */
function formWith(markup) {
	document.body.innerHTML = `<form>${markup}</form>`;

	return document.body.querySelector('form');
}

describe('serializeForm', () => {
	it('reports a text control by its value', () => {
		const form = formWith('<input name="global_currency" value="EUR">');

		expect(serializeForm(form)).toEqual({ global_currency: 'EUR' });
	});

	it('reports an unchecked single checkbox rather than omitting it', () => {
		// The whole point of the rule: switching a setting off has to store
		// "off", not silently leave the previous value in place.
		const form = formWith('<input type="checkbox" name="enabled">');

		expect(serializeForm(form)).toEqual({ enabled: false });
	});

	it('keeps a name[] group in DOM order, which is what makes it a queue', () => {
		const form = formWith(`
			<input type="checkbox" name="providers[]" value="frankfurter" checked>
			<input type="checkbox" name="providers[]" value="fixer" checked>
		`);

		expect(serializeForm(form)).toEqual({
			providers: ['frankfurter', 'fixer'],
		});
	});

	it('sends an emptied group as an empty array', () => {
		// Without this an operator could never clear a list: an absent key would
		// leave the stored one untouched.
		const form = formWith(
			'<input type="checkbox" name="providers[]" value="fixer">'
		);

		expect(serializeForm(form)).toEqual({ providers: [] });
	});

	it('skips a disabled control', () => {
		const form = formWith(
			'<input name="global_currency" value="EUR" disabled>'
		);

		expect(serializeForm(form)).toEqual({});
	});

	it('reports the selected radio and defaults an unselected group to empty', () => {
		const form = formWith(`
			<input type="radio" name="fallback_mode" value="direct">
			<input type="radio" name="fallback_mode" value="hide" checked>
			<input type="radio" name="rounding" value="none">
		`);

		expect(serializeForm(form)).toEqual({
			fallback_mode: 'hide',
			rounding: '',
		});
	});

	it('collects the value of a non checkbox name[] control', () => {
		const form = formWith(`
			<input name="countries[]" value="TR">
			<input name="countries[]" value="DE">
		`);

		expect(serializeForm(form)).toEqual({ countries: ['TR', 'DE'] });
	});

	it('restores a group a later plain control of the same name overwrote', () => {
		const form = formWith(`
			<input name="countries[]" value="TR">
			<input name="countries" value="DE">
		`);

		expect(serializeForm(form)).toEqual({ countries: [] });
	});
});
