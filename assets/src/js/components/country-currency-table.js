/**
 * CRUD wiring for the "Countries & Groups" tab.
 */

import { request, ApiError } from '../core/api.js';
import { text } from '../core/config.js';
import { success, failure, clear } from '../core/notices.js';
import createDataTable from './datatable.js';

const CONTAINER = '#lpm-country-currency';
const GROUP_TABLE = '#lpm-country-group-table';
const GROUP_FORM = '#lpm-country-group-form';
const ROW_TEMPLATE = '#lpm-cc-row-template';
const CURRENCY_OPTIONS = '#lpm-cc-currency-options';
const GROUP_OPTIONS = '#lpm-cc-group-options';
const SAMPLE_AMOUNT = 1234.5678;

/**
 * Render one amount the way a stored number format would.
 *
 * @param {number} amount    Amount to format.
 * @param {number} decimals  Decimal places.
 * @param {string} decimal   Decimal separator.
 * @param {string} thousands Thousand separator.
 * @returns {string} Formatted amount.
 */
function formatSample(amount, decimals, decimal, thousands) {
	const places = Math.max(0, Number(decimals) || 0);
	const fixed = Math.abs(Number(amount) || 0).toFixed(places);
	const [whole, fraction] = fixed.split('.');
	const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);

	return fraction ? `${grouped}${decimal}${fraction}` : grouped;
}

/**
 * Report whether one mapping matches a search term.
 *
 * @param {object} item Mapping row.
 * @param {string} term Lower-case search term.
 * @returns {boolean} True when the row should stay visible.
 */
function matchesSearch(item, term) {
	return [
		item.country_name,
		item.country_code,
		item.currency_code,
		item.currency_symbol,
		item.native_currency_code,
	].some((value) =>
		String(value || '')
			.toLowerCase()
			.includes(term)
	);
}

/**
 * Get the value one column sorts on.
 *
 * @param {object} item   Mapping row.
 * @param {string} column Column name.
 * @returns {string|number} Sort key.
 */
function sortKeyOf(item, column) {
	if (column === 'country_code') {
		return String(item.country_name || item.country_code || '');
	}

	return item[column] === undefined || item[column] === null
		? ''
		: item[column];
}

/**
 * Run one REST call and render its outcome.
 *
 * @param {string} path    Route path.
 * @param {object} options Request options.
 * @returns {Promise<object|null>} Payload, or null when the call failed.
 */
async function call(path, options) {
	clear();

	try {
		const result = await request(path, options);
		success(result.message || text('saved', 'Saved.'));

		return result;
	} catch (error) {
		failure(
			error instanceof ApiError
				? error.message
				: text('saveFailed', 'The request failed.')
		);

		return null;
	}
}

/**
 * Copy an option list out of a `<template>` into a select.
 *
 * @param {HTMLSelectElement} select     Target select.
 * @param {string}            templateId Template selector.
 * @param {string}            value      Value to select afterwards.
 * @returns {void}
 */
function fillOptions(select, templateId, value) {
	const template = document.querySelector(templateId);

	if (!template) {
		return;
	}

	select.appendChild(template.content.cloneNode(true));
	select.value = value;

	if (select.value !== value && value !== '') {
		const option = document.createElement('option');

		option.value = value;
		option.textContent = value;
		select.appendChild(option);
		select.value = value;
	}
}

/**
 * Build one country row from the server rendered template.
 *
 * @param {object} item Mapping row.
 * @returns {HTMLTableRowElement|null} Row, or null when the template is missing.
 */
function buildRow(item) {
	const template = document.querySelector(ROW_TEMPLATE);

	if (!template) {
		return null;
	}

	const row = template.content.firstElementChild.cloneNode(true);
	const code = String(item.country_code || '');
	const name = String(item.country_name || code);

	row.dataset.lpmCountry = code;

	const checkbox = row.querySelector('.lpm-cc-select');
	checkbox.value = code;
	checkbox.setAttribute('aria-label', name);

	row.querySelector('.lpm-cc-country-name').textContent = name;
	row.querySelector('.lpm-cc-country-code').textContent = code;
	const nativeCode = String(item.native_currency_code || '');
	const nativeSymbol = String(item.native_currency_symbol || '');

	row.querySelector('.lpm-cc-native').textContent =
		nativeCode && nativeSymbol ? `${nativeCode} - ${nativeSymbol}` : nativeCode;
	row.querySelector('.lpm-cc-format code').textContent = formatSample(
		SAMPLE_AMOUNT,
		item.decimals,
		String(item.decimal_separator || '.'),
		String(item.thousand_separator || ',')
	);

	fillOptions(
		row.querySelector('.lpm-cc-edit-currency'),
		CURRENCY_OPTIONS,
		String(item.currency_code || '')
	);
	fillOptions(
		row.querySelector('.lpm-cc-edit-group'),
		GROUP_OPTIONS,
		String(item.group_id || 0)
	);

	const active = item.is_active !== false;
	const toggle = row.querySelector('.lpm-cc-toggle-active');

	toggle.checked = active;
	toggle.setAttribute('aria-label', name);
	row.classList.toggle('lpm-cc-inactive', !active);

	return row;
}

/**
 * Read the pending edits of one row.
 *
 * @param {HTMLTableRowElement} row Row element.
 * @returns {object} Payload for the mapping write.
 */
function rowPayload(row) {
	const currency = row.querySelector('.lpm-cc-edit-currency');
	const group = row.querySelector('.lpm-cc-edit-group');

	return {
		country_code: row.dataset.lpmCountry,
		currency_code: currency ? currency.value.toUpperCase() : '',
		group_id: group ? Number(group.value) || 0 : 0,
	};
}

/**
 * Bind the country table.
 *
 * @returns {object|null} Table controller, or null when the tab is not on screen.
 */
function initCountryTable() {
	const root = document.querySelector(CONTAINER);

	if (!root) {
		return null;
	}

	const filters = Array.from(root.querySelectorAll('.lpm-cc-filter'));

	/**
	 * Read one filter's current value.
	 *
	 * @param {string} name Filter name, as `data-lpm-filter` carries it.
	 * @returns {string} Selected value, empty when the filter is on "all".
	 */
	const filterValue = (name) => {
		const select = filters.find(
			(node) => node.dataset.lpmFilter === name
		);

		return select ? select.value : '';
	};

	/**
	 * Report whether one mapping survives the status, currency and group filters.
	 *
	 * @param {object} item Mapping row.
	 * @returns {boolean} True when the row stays visible.
	 */
	const matchesFilters = (item) => {
		const status = filterValue('status');

		if (status !== '') {
			const active = Boolean(item.is_active);

			if ((status === 'enabled') !== active) {
				return false;
			}
		}

		const currency = filterValue('currency');

		if (currency !== '' && String(item.currency_code) !== currency) {
			return false;
		}

		const group = filterValue('group');

		if (group !== '') {
			const groupId = Number(item.group_id) || 0;

			if (groupId !== Number(group)) {
				return false;
			}
		}

		return true;
	};

	const table = createDataTable({
		root,
		orderBy: 'country_code',
		matches: matchesSearch,
		filter: matchesFilters,
		sortKey: sortKeyOf,
		fetchAll: async () => {
			const payload = await request(root.dataset.lpmRoute);

			return Array.isArray(payload.items) ? payload.items : [];
		},
		renderRows: (items) => items.map(buildRow).filter(Boolean),
	});

	if (!table) {
		return null;
	}

	/**
	 * Refill the currency filter from the rows now in memory.
	 *
	 * @returns {void}
	 */
	const fillCurrencyFilter = () => {
		const select = filters.find(
			(node) => node.dataset.lpmFilter === 'currency'
		);

		if (!select) {
			return;
		}

		const labels = new Map();
		const template = document.querySelector(CURRENCY_OPTIONS);

		if (template) {
			Array.from(template.content.querySelectorAll('option')).forEach(
				(option) => labels.set(option.value, option.textContent)
			);
		}

		const codes = Array.from(
			new Set(
				table.state.items
					.map((item) => String(item.currency_code || ''))
					.filter((code) => code !== '')
			)
		).sort();

		const current = select.value;
		const all = select.options[0];

		select.textContent = '';

		if (all) {
			select.append(all);
		}

		codes.forEach((code) => {
			const option = document.createElement('option');

			option.value = code;
			option.textContent = labels.get(code) || code;
			select.append(option);
		});

		select.value = codes.includes(current) ? current : '';
	};

	const load = async () => {
		try {
			await table.reload();
			fillCurrencyFilter();
		} catch (error) {
			failure(
				error instanceof ApiError
					? error.message
					: text('loadFailed', 'The table could not be loaded.')
			);
		}
	};

	const onRowAction = async (event) => {
		const button = event.target.closest('tbody [data-lpm-action]');

		if (!button) {
			return;
		}

		if ('checkbox' !== button.type) {
			event.preventDefault();
		}

		const row = button.closest('tr');
		const country = row ? row.dataset.lpmCountry : '';

		if (!country) {
			return;
		}

		const action = button.dataset.lpmAction;

		if (action === 'save') {
			const result = await call('country-currency', {
				method: 'POST',
				data: rowPayload(row),
			});

			if (result) {
				await load();
			}

			return;
		}

		if (action === 'reset') {
			const result = await call(`country-currency/${country}/reset`, {
				method: 'POST',
			});

			if (result) {
				await load();
			}

			return;
		}

		if (action === 'toggle-active') {
			const result = await call(`country-currency/${country}/active`, {
				method: 'POST',
				data: { active: button.checked },
			});

			if (result) {
				await load();

				return;
			}

			button.checked = !button.checked;
		}
	};

	root.addEventListener('click', (event) => {
		const control = event.target.closest('tbody [data-lpm-action]');

		if (control && 'checkbox' === control.type) {
			return;
		}

		onRowAction(event);
	});
	root.addEventListener('change', (event) => {
		if (event.target.matches('.lpm-cc-toggle-active')) {
			onRowAction(event);
		}
	});

	const selectAll = root.querySelector('.lpm-cc-select-all');

	if (selectAll) {
		selectAll.addEventListener('change', () => {
			root.querySelectorAll('tbody .lpm-cc-select').forEach((box) => {
				box.checked = selectAll.checked;
			});
		});
	}

	filters.forEach((select) => {
		select.addEventListener('change', () => table.refilter());
	});

	const bulkAction = root.querySelector('.lpm-cc-bulk-action');
	const bulkApply = root.querySelector('.lpm-cc-bulk-apply');
	const bulkGroup = root.querySelector('.lpm-cc-bulk-group');

	if (bulkAction && bulkGroup) {
		bulkAction.addEventListener('change', () => {
			bulkGroup.hidden = bulkAction.value !== 'assign_group';
		});
	}

	if (bulkAction && bulkApply) {
		bulkApply.addEventListener('click', async (event) => {
			event.preventDefault();
			clear();

			if (bulkAction.value === '') {
				failure(text('noBulkAction', 'Choose a bulk action first.'));

				return;
			}

			const items = Array.from(
				root.querySelectorAll('tbody .lpm-cc-select:checked')
			).map((box) => rowPayload(box.closest('tr')));

			if (items.length === 0) {
				failure(
					text('noneSelected', 'Select at least one country first.')
				);

				return;
			}

			if (!window.confirm(text('confirmBulk', 'Are you sure?'))) {
				return;
			}

			bulkApply.disabled = true;

			try {
				const payload = { action: bulkAction.value, items };

				if (bulkAction.value === 'assign_group') {
					payload.group_id = bulkGroup
						? Number(bulkGroup.value) || 0
						: 0;
				}

				const result = await call('country-currency/bulk', {
					method: 'POST',
					data: payload,
				});

				if (result) {
					if (selectAll) {
						selectAll.checked = false;
					}

					await load();
				}
			} finally {
				bulkApply.disabled = false;
			}
		});
	}

	load();

	return table;
}

/**
 * Fill the group form from one group row and switch it into edit mode.
 *
 * @param {HTMLFormElement}     form Group form.
 * @param {HTMLTableRowElement} row  Group row.
 * @returns {void}
 */
function startGroupEdit(form, row) {
	const title = document.getElementById('lpm-group-form-title');
	const cancel = document.getElementById('lpm-group-cancel');

	form.elements.id.value = row.dataset.lpmGroupId || '';
	form.elements.name.value = row.dataset.lpmGroupName || '';
	form.elements.currency_code.value = row.dataset.lpmGroupCurrency || '';
	form.elements.exchange_rate.value = row.dataset.lpmGroupRate || '';

	if (title) {
		title.textContent = text('editGroup', 'Edit group');
	}

	if (cancel) {
		cancel.hidden = false;
	}

	form.elements.name.focus();
	form.scrollIntoView({ block: 'nearest' });
}

/**
 * Put the group form back into "add" mode.
 *
 * @param {HTMLFormElement} form Group form.
 * @returns {void}
 */
function resetGroupForm(form) {
	const title = document.getElementById('lpm-group-form-title');
	const cancel = document.getElementById('lpm-group-cancel');

	form.reset();
	form.elements.id.value = '';

	if (title) {
		title.textContent = text('addGroup', 'Add a group');
	}

	if (cancel) {
		cancel.hidden = true;
	}
}

/**
 * Bind the group table and its create / edit form.
 *
 * @returns {void}
 */
function initGroups() {
	const table = document.querySelector(GROUP_TABLE);
	const form = document.querySelector(GROUP_FORM);

	if (table && form) {
		table.addEventListener('click', async (event) => {
			const button = event.target.closest('[data-lpm-action]');

			if (!button) {
				return;
			}

			event.preventDefault();

			const row = button.closest('tr');
			const id = row ? row.dataset.lpmGroupId : '';

			if (!id) {
				return;
			}

			if (button.dataset.lpmAction === 'edit-group') {
				startGroupEdit(form, row);

				return;
			}

			if (!window.confirm(text('confirmDelete', 'Are you sure?'))) {
				return;
			}

			const result = await call(`country-groups/${id}`, {
				method: 'DELETE',
			});

			if (result) {
				window.location.reload();
			}
		});
	}

	if (!form) {
		return;
	}

	const cancel = document.getElementById('lpm-group-cancel');

	if (cancel) {
		cancel.addEventListener('click', () => resetGroupForm(form));
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault();

		const id = String(form.elements.id.value || '').trim();
		const rate = String(form.elements.exchange_rate.value || '').trim();

		const result = await call(
			id === '' ? 'country-groups' : `country-groups/${id}`,
			{
				method: id === '' ? 'POST' : 'PUT',
				data: {
					name: String(form.elements.name.value || '').trim(),
					currency_code: String(
						form.elements.currency_code.value || ''
					)
						.trim()
						.toUpperCase(),
					exchange_rate: rate === '' ? null : rate,
				},
			}
		);

		if (result) {
			window.location.reload();
		}
	});
}

/**
 * Bind the whole tab.
 *
 * @returns {void}
 */
export default function initCountryCurrencyTable() {
	initCountryTable();
	initGroups();
}
