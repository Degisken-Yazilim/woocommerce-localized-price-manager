/**
 * "Countries & Groups" tab tests.
 *
 * Every helper in the module is private, so the whole tab is driven through its
 * one export. Only the REST client is doubled: the data table, the row template
 * cloning, the serializer and the notice renderer all run for real.
 */

jest.mock('../core/api.js', () => {
	const actual = jest.requireActual('../core/api.js');

	return { ...actual, request: jest.fn() };
});

import initCountryCurrencyTable from './country-currency-table.js';
import { request, ApiError } from '../core/api.js';

const MAPPINGS = [
	{
		country_code: 'TR',
		country_name: 'Türkiye',
		currency_code: 'TRY',
		currency_symbol: '₺',
		native_currency_code: 'TRY',
		native_currency_symbol: '₺',
		decimal_separator: ',',
		thousand_separator: '.',
		decimals: 2,
		group_id: 0,
	},
	{
		country_code: 'JP',
		country_name: 'Japan',
		currency_code: 'JPY',
		currency_symbol: '¥',
		native_currency_code: 'JPY',
		native_currency_symbol: '¥',
		decimal_separator: '.',
		thousand_separator: ',',
		decimals: 0,
		group_id: 2,
	},
];

/**
 * Render the whole tab as PHP prints it.
 *
 * @returns {void}
 */
function render() {
	document.body.innerHTML = `
		<div class="wrap">
			<div id="lpm-country-currency" data-lpm-route="country-currency" data-lpm-per-page="10">
				<input type="search" class="lpm-dt-search">
				<select class="lpm-cc-bulk-action">
					<option value="">—</option>
					<option value="assign_group">Assign group</option>
				</select>
				<select class="lpm-cc-bulk-group" hidden>
					<option value="0">— No group —</option>
					<option value="2">Eurozone</option>
				</select>
				<button class="lpm-cc-bulk-apply" type="button">Apply</button>
				<select class="lpm-cc-filter" data-lpm-filter="status">
					<option value="">All</option>
					<option value="enabled">Enabled</option>
					<option value="disabled">Disabled</option>
				</select>
				<select class="lpm-cc-filter" data-lpm-filter="currency">
					<option value="">All</option>
				</select>
				<select class="lpm-cc-filter" data-lpm-filter="group">
					<option value="">All</option>
					<option value="0">— No group —</option>
					<option value="2">Eurozone</option>
				</select>
				<div class="lpm-dt-overlay"></div>
				<table>
					<thead>
						<tr>
							<th><input type="checkbox" class="lpm-cc-select-all"></th>
							<th data-lpm-sort="country_code">Country</th>
							<th data-lpm-sort="currency_code">Currency</th>
						</tr>
					</thead>
					<tbody class="lpm-dt-body"></tbody>
				</table>
				<p class="lpm-dt-info"></p>
				<div class="lpm-dt-pagination"></div>
			</div>

			<table id="lpm-country-group-table">
				<tbody>
					<tr data-lpm-group-id="2" data-lpm-group-name="Eurozone"
						data-lpm-group-currency="EUR" data-lpm-group-rate="1.08">
						<td>Eurozone</td>
						<td>
							<button type="button" data-lpm-action="edit-group">Edit</button>
							<button type="button" data-lpm-action="delete-group">Delete</button>
						</td>
					</tr>
				</tbody>
			</table>

			<h2 id="lpm-group-form-title">Add a group</h2>
			<form id="lpm-country-group-form">
				<input name="id" value="">
				<input name="name" value="">
				<input name="currency_code" value="">
				<input name="exchange_rate" value="">
				<button type="submit">Save</button>
			</form>
			<button id="lpm-group-cancel" hidden>Cancel</button>

			<template id="lpm-cc-currency-options">
				<option value="TRY">TRY</option>
				<option value="JPY">JPY</option>
				<option value="EUR">EUR</option>
			</template>
			<template id="lpm-cc-group-options">
				<option value="0">—</option>
				<option value="2">Eurozone</option>
			</template>
			<template id="lpm-cc-row-template">
				<tr>
					<td><input type="checkbox" class="lpm-cc-select"></td>
					<td>
						<span class="lpm-cc-country-name"></span>
						<span class="lpm-cc-country-code"></span>
					</td>
					<td>
						<select class="lpm-cc-edit-currency"></select>
						<select class="lpm-cc-edit-group"></select>
						<span class="lpm-cc-native"></span>
						<span class="lpm-cc-format"><code></code></span>
						<button type="button" data-lpm-action="save">Save</button>
						<button type="button" data-lpm-action="reset">Reset</button>
						<input type="checkbox" class="lpm-cc-toggle-active" data-lpm-action="toggle-active">
					</td>
				</tr>
			</template>
		</div>
	`;
}

/**
 * Let the module's promise chains settle.
 *
 * @returns {Promise<void>} Resolves after the microtask queue drains.
 */
async function flush() {
	for (let i = 0; i < 6; i += 1) {
		await Promise.resolve();
	}
}

/**
 * Boot the tab with the country list already stubbed.
 *
 * @returns {Promise<void>} Resolves once the first draw is done.
 */
async function boot() {
	request.mockResolvedValue({ items: MAPPINGS });
	initCountryCurrencyTable();
	await flush();
}

/**
 * Read one rendered country row.
 *
 * @param {string} code Country code.
 * @returns {HTMLTableRowElement} Row.
 */
function row(code) {
	return document.querySelector(`tbody tr[data-lpm-country="${code}"]`);
}

/**
 * Press a row action button.
 *
 * @param {string} code   Country code.
 * @param {string} action Action name.
 * @returns {Promise<void>} Resolves once the handler settled.
 */
async function act(code, action) {
	const control = row(code).querySelector(`[data-lpm-action="${action}"]`);

	// The active switch is a checkbox and answers to `change`, the way a keyboard
	// reaches it; the row buttons answer to a click.
	if (control.type === 'checkbox') {
		control.checked = !control.checked;
		control.dispatchEvent(new Event('change', { bubbles: true }));
	} else {
		control.dispatchEvent(
			new MouseEvent('click', { bubbles: true, cancelable: true })
		);
	}

	await flush();
}

/**
 * Read the rendered notice.
 *
 * @returns {HTMLElement|null} Notice box.
 */
function notice() {
	return document.querySelector('#lpm-notices .notice');
}

beforeEach(() => {
	jest.clearAllMocks();

	jest.spyOn(window, 'confirm').mockReturnValue(true);
	Element.prototype.scrollIntoView = jest.fn();

	render();
});

afterEach(() => {
	jest.restoreAllMocks();
});

describe('the country table', () => {
	it('fetches from the route the markup declares', async () => {
		await boot();

		expect(request).toHaveBeenCalledWith('country-currency');
	});

	it('builds each row from the server rendered template', async () => {
		await boot();

		expect(row('TR')).not.toBeNull();
		expect(row('TR').querySelector('.lpm-cc-country-name')).toHaveTextContent(
			'Türkiye'
		);
		// Code and symbol in one cell now; the configured currency's own symbol
		// rides in the select instead of a column of its own.
		expect(row('TR').querySelector('.lpm-cc-native')).toHaveTextContent('TRY - ₺');
	});

	it('labels the row checkbox with the country name', async () => {
		// The checkbox is the only control in its column, so without this a
		// screen reader announces an unlabelled tick box on every row.
		await boot();

		const box = row('TR').querySelector('.lpm-cc-select');

		expect(box).toHaveAttribute('aria-label', 'Türkiye');
		// The bulk payload is built from these values, so a row that carries the
		// wrong one would send somebody else's country.
		expect(box).toHaveAttribute('value', 'TR');
	});

	it('shows the stored number format as a real formatted amount', async () => {
		// The column used to print the three raw separator values, which read as
		// ", . 2" on every row and told an operator nothing.
		await boot();

		expect(row('TR').querySelector('.lpm-cc-format code')).toHaveTextContent(
			'1.234,57'
		);
	});

	it('honours a zero-decimal currency in the sample', async () => {
		await boot();

		expect(row('JP').querySelector('.lpm-cc-format code')).toHaveTextContent(
			'1,235'
		);
	});

	it('fills the currency and group selects from their shared templates', async () => {
		// One option list per document rather than per row: ~160 currencies
		// duplicated across 250 rows is what this avoids.
		await boot();

		expect(row('JP').querySelector('.lpm-cc-edit-currency')).toHaveValue('JPY');
		expect(row('JP').querySelector('.lpm-cc-edit-group')).toHaveValue('2');
	});

	it('keeps a stored currency the store no longer offers', async () => {
		// Silently selecting the first option would misreport what is stored.
		request.mockResolvedValue({
			items: [{ ...MAPPINGS[0], currency_code: 'XXX' }],
		});
		initCountryCurrencyTable();
		await flush();

		expect(row('TR').querySelector('.lpm-cc-edit-currency')).toHaveValue('XXX');
	});

	it('treats a payload without an items array as empty', async () => {
		request.mockResolvedValue({});
		initCountryCurrencyTable();
		await flush();

		expect(document.querySelectorAll('tbody tr[data-lpm-country]')).toHaveLength(
			0
		);
	});

	it('reports a failed load where the operator will see it', async () => {
		request.mockRejectedValue(new ApiError('Nope.', 500, 'x'));
		initCountryCurrencyTable();
		await flush();

		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent('Nope.');
	});
});

describe('row actions', () => {
	it('saves the pending currency and group of that row', async () => {
		await boot();

		row('TR').querySelector('.lpm-cc-edit-currency').value = 'EUR';
		row('TR').querySelector('.lpm-cc-edit-group').value = '2';
		request.mockResolvedValue({ message: 'Saved.' });

		await act('TR', 'save');

		expect(request).toHaveBeenCalledWith('country-currency', {
			method: 'POST',
			data: { country_code: 'TR', currency_code: 'EUR', group_id: 2 },
		});
		expect(notice()).toHaveClass('notice-success');
	});

	it('resets one country to its seeded native currency', async () => {
		await boot();
		request.mockResolvedValue({ message: 'Reset.' });

		await act('TR', 'reset');

		expect(request).toHaveBeenCalledWith('country-currency/TR/reset', {
			method: 'POST',
		});
	});

	it('writes once per switch, not once per click and once per change', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Disabled.' });

		// One click, the way an operator gives one. Clicking a checkbox runs the
		// browser's own activation behaviour, which flips `checked` and fires
		// `change`, so the click arrives at the delegated listener *as well*.
		// Handling the switch in both listeners wrote twice — two audit rows per
		// toggle, and two requests racing on the cache version stamp.
		row('TR')
			.querySelector('.lpm-cc-toggle-active')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

		await flush();

		const writes = request.mock.calls.filter(
			([route]) => route === 'country-currency/TR/active'
		);

		expect(writes).toHaveLength(1);
	});

	it('switches a country off without asking, because the switch is the undo', async () => {
		await boot();
		request.mockResolvedValue({ message: 'Disabled.' });

		await act('TR', 'toggle-active');

		expect(window.confirm).not.toHaveBeenCalled();
		expect(request).toHaveBeenCalledWith('country-currency/TR/active', {
			method: 'POST',
			data: { active: false },
		});
	});

	it('switches a country back on through the same route', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Enabled.' });

		// Stand the row down first, the way one arrives from the server when the
		// country is off. `act()` then flips it up, which is the operator's undo.
		row('TR').querySelector('.lpm-cc-toggle-active').checked = false;

		await act('TR', 'toggle-active');

		expect(request).toHaveBeenCalledWith('country-currency/TR/active', {
			method: 'POST',
			data: { active: true },
		});
	});

	it('puts the switch back when the write failed', async () => {
		await boot();
		request.mockClear();
		request.mockRejectedValue(new ApiError('Nope.', 500));

		const toggle = row('TR').querySelector('.lpm-cc-toggle-active');
		const before = toggle.checked;

		await act('TR', 'toggle-active');

		// Left flipped it would claim a state the server never accepted.
		expect(row('TR').querySelector('.lpm-cc-toggle-active').checked).toBe(before);
	});

	it('reloads the table after a successful write', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Saved.' });

		await act('TR', 'save');

		// The write, then the re-fetch.
		expect(request).toHaveBeenCalledTimes(2);
		expect(request).toHaveBeenLastCalledWith('country-currency');
	});

	it('leaves the table alone when the write failed', async () => {
		await boot();
		request.mockClear();
		request.mockRejectedValue(new ApiError('Refused.', 400, 'x'));

		await act('TR', 'save');

		expect(request).toHaveBeenCalledTimes(1);
		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent('Refused.');
	});

	it('suppresses the default action so the surrounding form is not submitted', async () => {
		await boot();

		const event = new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
		});
		row('TR').querySelector('[data-lpm-action="save"]').dispatchEvent(event);

		expect(event.defaultPrevented).toBe(true);
	});
});

describe('the table filters', () => {
	/**
	 * Read the country codes the table is currently showing.
	 *
	 * @returns {Array<string>} Codes, in render order.
	 */
	const visible = () =>
		Array.from(document.querySelectorAll('tbody tr[data-lpm-country]')).map(
			(node) => node.dataset.lpmCountry
		);

	/**
	 * Move one filter and fire the event the page listens for.
	 *
	 * @param {string} name  Filter name.
	 * @param {string} value Value to select.
	 * @returns {void}
	 */
	const choose = (name, value) => {
		const select = document.querySelector(
			`.lpm-cc-filter[data-lpm-filter="${name}"]`
		);

		select.value = value;
		select.dispatchEvent(new Event('change'));
	};

	// Each of these asserts straight after the change event and never touches
	// the search box: narrowing used to need a search keystroke to take hold,
	// because paging the already-computed set is not the same as recomputing it.
	it('narrows by currency without a search keystroke', async () => {
		await boot();
		expect(visible()).toEqual(['JP', 'TR']);

		choose('currency', 'TRY');

		expect(visible()).toEqual(['TR']);
	});

	it('narrows by group, and by having no group at all', async () => {
		await boot();

		choose('group', '2');
		expect(visible()).toEqual(['JP']);

		choose('group', '0');
		expect(visible()).toEqual(['TR']);
	});

	it('narrows by status', async () => {
		request.mockResolvedValue({
			items: [
				{ ...MAPPINGS[0], is_active: true },
				{ ...MAPPINGS[1], is_active: false },
			],
		});
		initCountryCurrencyTable();
		await flush();

		choose('status', 'enabled');
		expect(visible()).toEqual(['TR']);

		choose('status', 'disabled');
		expect(visible()).toEqual(['JP']);
	});

	it('offers only the currencies the loaded rows use', async () => {
		await boot();

		const options = Array.from(
			document.querySelectorAll(
				'.lpm-cc-filter[data-lpm-filter="currency"] option'
			)
		).map((option) => option.value);

		expect(options).toEqual(['', 'JPY', 'TRY']);
	});
});

describe('bulk actions', () => {
	it('refuses to run without an action chosen', async () => {
		await boot();
		request.mockClear();

		document.querySelector('.lpm-cc-bulk-apply').click();
		await flush();

		expect(notice()).toHaveTextContent('Choose a bulk action first.');
		expect(request).not.toHaveBeenCalled();
	});

	it('refuses to run with nothing selected', async () => {
		await boot();
		request.mockClear();
		document.querySelector('.lpm-cc-bulk-action').value = 'assign_group';

		document.querySelector('.lpm-cc-bulk-apply').click();
		await flush();

		expect(notice()).toHaveTextContent('Select at least one country first.');
		expect(request).not.toHaveBeenCalled();
	});

	it('sends the payload of every ticked row', async () => {
		await boot();
		document.querySelector('.lpm-cc-bulk-action').value = 'assign_group';
		row('TR').querySelector('.lpm-cc-select').checked = true;
		request.mockClear();
		request.mockResolvedValue({ message: 'Done.' });

		document.querySelector('.lpm-cc-bulk-apply').click();
		await flush();

		expect(request).toHaveBeenCalledWith('country-currency/bulk', {
			method: 'POST',
			data: {
				action: 'assign_group',
				group_id: 0,
				items: [
					{ country_code: 'TR', currency_code: 'TRY', group_id: 0 },
				],
			},
		});
	});

	it('reveals the group picker only for the group action', async () => {
		await boot();
		const action = document.querySelector('.lpm-cc-bulk-action');
		const picker = document.querySelector('.lpm-cc-bulk-group');

		expect(picker.hidden).toBe(true);

		action.value = 'assign_group';
		action.dispatchEvent(new Event('change'));

		expect(picker.hidden).toBe(false);

		action.value = '';
		action.dispatchEvent(new Event('change'));

		expect(picker.hidden).toBe(true);
	});

	it('carries the chosen group with the bulk request', async () => {
		await boot();
		document.querySelector('.lpm-cc-bulk-action').value = 'assign_group';
		document.querySelector('.lpm-cc-bulk-group').value = '2';
		row('TR').querySelector('.lpm-cc-select').checked = true;
		request.mockClear();
		request.mockResolvedValue({ message: 'Done.' });

		document.querySelector('.lpm-cc-bulk-apply').click();
		await flush();

		expect(request).toHaveBeenCalledWith(
			'country-currency/bulk',
			expect.objectContaining({
				data: expect.objectContaining({
					action: 'assign_group',
					group_id: 2,
				}),
			})
		);
	});

	it('ticks and unticks every row from the header box', async () => {
		await boot();
		const all = document.querySelector('.lpm-cc-select-all');

		all.checked = true;
		all.dispatchEvent(new Event('change'));

		expect(
			document.querySelectorAll('tbody .lpm-cc-select:checked')
		).toHaveLength(2);

		all.checked = false;
		all.dispatchEvent(new Event('change'));

		expect(
			document.querySelectorAll('tbody .lpm-cc-select:checked')
		).toHaveLength(0);
	});

	it('re-enables the apply button even when the call failed', async () => {
		await boot();
		document.querySelector('.lpm-cc-bulk-action').value = 'assign_group';
		row('TR').querySelector('.lpm-cc-select').checked = true;
		request.mockRejectedValue(new ApiError('Refused.', 400, 'x'));

		const apply = document.querySelector('.lpm-cc-bulk-apply');
		apply.click();
		await flush();

		expect(apply).toBeEnabled();
	});
});

describe('the group form', () => {
	/**
	 * Read the form.
	 *
	 * @returns {HTMLFormElement} Group form.
	 */
	function form() {
		return document.getElementById('lpm-country-group-form');
	}

	it('creates a group with POST when no id is present', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Saved.' });

		form().elements.name.value = 'Nordics';
		form().elements.currency_code.value = 'sek';
		form().elements.exchange_rate.value = '11.5';

		form().dispatchEvent(new Event('submit', { cancelable: true }));
		await flush();

		expect(request).toHaveBeenCalledWith('country-groups', {
			method: 'POST',
			data: {
				name: 'Nordics',
				currency_code: 'SEK',
				exchange_rate: '11.5',
			},
		});
	});

	it('updates with PUT once the form carries an id', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Saved.' });

		form().elements.id.value = '2';
		form().elements.name.value = 'Eurozone';
		form().elements.currency_code.value = 'EUR';

		form().dispatchEvent(new Event('submit', { cancelable: true }));
		await flush();

		expect(request).toHaveBeenCalledWith('country-groups/2', {
			method: 'PUT',
			data: { name: 'Eurozone', currency_code: 'EUR', exchange_rate: null },
		});
	});

	it('sends a blank rate as null rather than zero', async () => {
		// Zero would be a real rate and would price every product in the group
		// at nothing.
		await boot();
		request.mockClear();
		request.mockResolvedValue({});

		form().elements.name.value = 'Nordics';
		form().dispatchEvent(new Event('submit', { cancelable: true }));
		await flush();

		expect(request.mock.calls[0][1].data.exchange_rate).toBeNull();
	});

	it('confirms a successful write before it reloads the page', async () => {
		// The reload itself is not asserted: jsdom defines `location.reload` as
		// non-writable and non-configurable, so it cannot be doubled, and
		// reshaping the module to make it observable would be changing source to
		// suit a test. What is checked is everything up to that point -- the
		// write went out and was reported as successful, which is the branch the
		// reload hangs off.
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Saved.' });

		form().elements.name.value = 'Nordics';
		form().dispatchEvent(new Event('submit', { cancelable: true }));
		await flush();

		expect(request).toHaveBeenCalledTimes(1);
		expect(notice()).toHaveClass('notice-success');
	});

	it('reports a failed write instead of moving on', async () => {
		await boot();
		request.mockRejectedValue(new ApiError('Refused.', 400, 'x'));

		form().elements.name.value = 'Nordics';
		form().dispatchEvent(new Event('submit', { cancelable: true }));
		await flush();

		expect(notice()).toHaveClass('notice-error');
		expect(notice()).toHaveTextContent('Refused.');
	});

	it('fills itself from the row being edited', async () => {
		await boot();

		document
			.querySelector('[data-lpm-action="edit-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(form().elements.id).toHaveValue('2');
		expect(form().elements.name).toHaveValue('Eurozone');
		expect(form().elements.currency_code).toHaveValue('EUR');
		expect(form().elements.exchange_rate).toHaveValue('1.08');
		expect(document.getElementById('lpm-group-form-title')).toHaveTextContent(
			'Edit group'
		);
		expect(document.getElementById('lpm-group-cancel')).not.toHaveAttribute(
			'hidden'
		);
	});

	it('moves focus into the form so a keyboard user is not stranded', async () => {
		await boot();

		document
			.querySelector('[data-lpm-action="edit-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(form().elements.name).toHaveFocus();
	});

	it('returns to add mode on cancel', async () => {
		await boot();

		document
			.querySelector('[data-lpm-action="edit-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		document.getElementById('lpm-group-cancel').click();

		expect(form().elements.id).toHaveValue('');
		expect(document.getElementById('lpm-group-form-title')).toHaveTextContent(
			'Add a group'
		);
		expect(document.getElementById('lpm-group-cancel')).toHaveAttribute(
			'hidden'
		);
	});

	it('asks before deleting a group', async () => {
		await boot();
		request.mockClear();
		request.mockResolvedValue({ message: 'Deleted.' });

		document
			.querySelector('[data-lpm-action="delete-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(window.confirm).toHaveBeenCalled();
		expect(request).toHaveBeenCalledWith('country-groups/2', {
			method: 'DELETE',
		});
	});

	it('deletes nothing when the confirmation is declined', async () => {
		await boot();
		window.confirm.mockReturnValue(false);
		request.mockClear();

		document
			.querySelector('[data-lpm-action="delete-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(request).not.toHaveBeenCalled();
	});
});

describe('markup that is only partly on screen', () => {
	it('stays silent on a screen without the tab', () => {
		document.body.innerHTML = '';

		expect(() => initCountryCurrencyTable()).not.toThrow();
		expect(request).not.toHaveBeenCalled();
	});

	it('stays silent when the table has no body to draw into', () => {
		document.querySelector('tbody.lpm-dt-body').remove();

		expect(() => initCountryCurrencyTable()).not.toThrow();
		expect(request).not.toHaveBeenCalled();
	});

	it('draws no rows when the row template is missing', async () => {
		document.getElementById('lpm-cc-row-template').remove();
		await boot();

		expect(document.querySelectorAll('tbody tr[data-lpm-country]')).toHaveLength(0);
	});

	it('draws the rows when the option templates are missing', async () => {
		document.getElementById('lpm-cc-currency-options').remove();
		document.getElementById('lpm-cc-group-options').remove();
		await boot();

		expect(row('TR')).not.toBeNull();
	});

	it('loads the rows when there is no currency filter', async () => {
		document.querySelector('[data-lpm-filter="currency"]').remove();
		await boot();

		expect(row('TR')).not.toBeNull();
	});

	it('loads the rows and binds no group form when the form is absent', async () => {
		document.getElementById('lpm-country-group-form').remove();
		await boot();

		expect(row('TR')).not.toBeNull();
	});

	it('ignores a click inside the group table that is not an action', async () => {
		await boot();
		request.mockClear();

		document
			.querySelector('#lpm-country-group-table td')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(request).not.toHaveBeenCalled();
	});

	it('ignores a group action on a row that carries no id', async () => {
		await boot();
		request.mockClear();
		document
			.querySelector('#lpm-country-group-table tr')
			.removeAttribute('data-lpm-group-id');

		document
			.querySelector('[data-lpm-action="delete-group"]')
			.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(window.confirm).not.toHaveBeenCalled();
		expect(request).not.toHaveBeenCalled();
	});
});

describe('searching and sorting the countries', () => {
	const codes = () =>
		Array.from(document.querySelectorAll('tbody tr[data-lpm-country]')).map(
			(node) => node.dataset.lpmCountry
		);

	it('narrows the rows to the ones matching the search term', async () => {
		jest.useFakeTimers();

		try {
			await boot();

			const input = document.querySelector('.lpm-dt-search');
			input.value = 'rkiye';
			input.dispatchEvent(new Event('input'));
			jest.advanceTimersByTime(300);

			expect(codes()).toEqual(['TR']);
		} finally {
			jest.useRealTimers();
		}
	});

	it('sorts on the currency column, descending on a second press', async () => {
		await boot();

		const header = document.querySelector('[data-lpm-sort="currency_code"]');
		header.click();
		expect(codes()).toEqual(['JP', 'TR']);

		header.click();
		expect(codes()).toEqual(['TR', 'JP']);
	});

	it('sorts a row missing the column as an empty key', async () => {
		request.mockResolvedValue({
			items: [
				{ country_code: 'TR', country_name: 'Türkiye', currency_code: 'TRY' },
				{ country_code: 'XX', country_name: 'Nowhere' },
			],
		});
		initCountryCurrencyTable();
		await flush();

		document.querySelector('[data-lpm-sort="currency_code"]').click();

		expect(codes()).toEqual(['XX', 'TR']);
	});
});

describe('rows and bulk actions that cannot proceed', () => {
	it('ignores an action on a row that carries no country', async () => {
		await boot();
		request.mockClear();
		const save = row('TR').querySelector('[data-lpm-action="save"]');
		row('TR').removeAttribute('data-lpm-country');

		save.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		await flush();

		expect(request).not.toHaveBeenCalled();
	});

	it('sends nothing when the bulk confirmation is declined', async () => {
		await boot();
		document.querySelector('.lpm-cc-bulk-action').value = 'assign_group';
		row('TR').querySelector('.lpm-cc-select').checked = true;
		window.confirm.mockReturnValue(false);
		request.mockClear();

		document.querySelector('.lpm-cc-bulk-apply').click();
		await flush();

		expect(request).not.toHaveBeenCalled();
	});
});
