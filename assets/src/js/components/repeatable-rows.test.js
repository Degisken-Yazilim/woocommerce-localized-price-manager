/**
 * Repeatable metabox row tests.
 */

import initRepeatableRows from './repeatable-rows.js';

const OPTIONS = {
	body: '#rows',
	template: '#row-template',
	addButton: '.add-row',
	removeButton: '.lpm-remove-row-btn',
};

/**
 * Render a table with a template and an add button.
 *
 * @param {string} rows Existing `<tr>` markup.
 * @returns {void}
 */
function render(rows = '') {
	document.body.innerHTML = `
		<form>
			<table><tbody id="rows">${rows}</tbody></table>
			<button class="add-row">Add</button>
			<template id="row-template">
				<tr class="lpm-row">
					<td><select name="country[]"><option value="TR">TR</option></select></td>
					<td><button class="lpm-remove-row-btn">Remove</button></td>
				</tr>
			</template>
		</form>
	`;
}

/**
 * Count the rows currently in the body.
 *
 * @returns {number} Row count.
 */
function rowCount() {
	return document.querySelectorAll('#rows tr').length;
}

describe('initRepeatableRows', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('does nothing when the table body is absent', () => {
		document.body.innerHTML = '<template id="row-template"></template>';

		// A metabox is rendered only on some screens; the bundle loads on all of
		// them and must stay silent where its markup is not present.
		expect(() => initRepeatableRows(OPTIONS)).not.toThrow();
	});

	it('does nothing when the template is absent', () => {
		document.body.innerHTML = '<table><tbody id="rows"></tbody></table>';

		expect(() => initRepeatableRows(OPTIONS)).not.toThrow();
	});

	it('appends a blank row from the template', () => {
		render();
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();

		expect(rowCount()).toBe(1);
		expect(document.querySelector('#rows select')).toHaveAttribute(
			'name',
			'country[]'
		);
	});

	it('focuses the first field of the row it just added', () => {
		render();
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();

		expect(document.querySelector('#rows select')).toHaveFocus();
	});

	it('keeps the rows already on the page', () => {
		render('<tr class="lpm-row"><td>existing</td></tr>');
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();

		expect(rowCount()).toBe(2);
	});

	it('suppresses the default action so the metabox form is not submitted', () => {
		render();
		initRepeatableRows(OPTIONS);

		const event = new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
		});
		document.querySelector('.add-row').dispatchEvent(event);

		expect(event.defaultPrevented).toBe(true);
	});

	it('removes the row whose remove button was clicked', () => {
		render();
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();
		document.querySelector('.add-row').click();
		expect(rowCount()).toBe(2);

		document.querySelectorAll('.lpm-remove-row-btn')[0].click();

		expect(rowCount()).toBe(1);
	});

	it('binds removal to the body, so a row added later is still removable', () => {
		// Delegation rather than per-row listeners: rows arrive after init.
		render();
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();
		document.querySelector('#rows .lpm-remove-row-btn').click();

		expect(rowCount()).toBe(0);
	});

	it('ignores a click that is not on a remove button', () => {
		render('<tr class="lpm-row"><td><span id="label">TR</span></td></tr>');
		initRepeatableRows(OPTIONS);

		document.getElementById('label').click();

		expect(rowCount()).toBe(1);
	});

	it('adds nothing when the template element carries no content', () => {
		document.body.innerHTML = `
			<table><tbody id="rows"></tbody></table>
			<button class="add-row">Add</button>
			<div id="row-template"></div>
		`;
		initRepeatableRows(OPTIONS);

		document.querySelector('.add-row').click();

		expect(rowCount()).toBe(0);
	});
});
