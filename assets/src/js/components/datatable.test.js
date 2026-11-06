/**
 * Client side data table tests.
 *
 * The component owns state and chrome only; the caller supplies the fetch, the
 * row builder, the search predicate and the sort key. These cases drive it
 * through a small country-like record set.
 */

import createDataTable from './datatable.js';

const RECORDS = [
	{ code: 'TR', name: 'Türkiye', rate: 34 },
	{ code: 'de', name: 'Germany', rate: 1 },
	{ code: 'US', name: 'United States', rate: 2 },
	{ code: 'JP', name: 'Japan', rate: 155 },
	{ code: 'GB', name: 'United Kingdom', rate: 10 },
];

/**
 * Render the table shell PHP would have printed.
 *
 * @param {number} perPage Value for `data-lpm-per-page`.
 * @returns {Element} Root element.
 */
function shell(perPage = 2) {
	document.body.innerHTML = `
		<div class="lpm-dt" data-lpm-per-page="${perPage}">
			<input type="search" class="lpm-dt-search">
			<select class="lpm-dt-per-page-select">
				<option value="2">2</option>
				<option value="10">10</option>
			</select>
			<div class="lpm-dt-overlay"></div>
			<table>
				<thead>
					<tr>
						<th data-lpm-sort="code" tabindex="0">Code</th>
						<th data-lpm-sort="rate" tabindex="0">Rate</th>
					</tr>
				</thead>
				<tbody class="lpm-dt-body"></tbody>
			</table>
			<p class="lpm-dt-info"></p>
			<div class="lpm-dt-pagination"></div>
		</div>
	`;

	return document.querySelector('.lpm-dt');
}

/**
 * Build a table over the given records.
 *
 * @param {object} overrides Option overrides.
 * @returns {object} Controller.
 */
function build(overrides = {}) {
	return createDataTable({
		root: document.querySelector('.lpm-dt'),
		fetchAll: jest.fn().mockResolvedValue(RECORDS),
		renderRows: (page) =>
			page.map((item) => {
				const row = document.createElement('tr');
				row.dataset.code = item.code;
				row.textContent = item.name;

				return row;
			}),
		matches: (item, term) => item.name.toLowerCase().includes(term),
		sortKey: (item, column) => item[column],
		orderBy: 'code',
		...overrides,
	});
}

/**
 * Read the codes currently rendered, in order.
 *
 * @returns {Array<string>} Row codes.
 */
function rows() {
	return Array.from(document.querySelectorAll('.lpm-dt-body tr')).map(
		(row) => row.dataset.code
	);
}

/**
 * Read the pager button labels.
 *
 * @returns {Array<string>} Labels.
 */
function pagerLabels() {
	return Array.from(
		document.querySelectorAll('.lpm-dt-pagination button')
	).map((button) => button.textContent);
}

describe('createDataTable', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	describe('wiring', () => {
		it('returns null without a root', () => {
			expect(createDataTable({ root: null })).toBeNull();
		});

		it('returns null when the shell has no table body', () => {
			document.body.innerHTML = '<div class="lpm-dt"><table></table></div>';

			expect(build()).toBeNull();
		});

		it('takes the page size from the markup', () => {
			shell(3);

			expect(build().state.perPage).toBe(3);
		});

		it('falls back to 25 when the markup declares none', () => {
			shell();
			document.querySelector('.lpm-dt').removeAttribute('data-lpm-per-page');

			expect(build().state.perPage).toBe(25);
		});
	});

	describe('reload', () => {
		it('draws the first page from the fetched records', async () => {
			shell();
			await build().reload();

			// Sorted by code ascending: de, GB, JP, TR, US.
			expect(rows()).toEqual(['de', 'GB']);
		});

		it('draws the rows when the shell has no info line or pager', async () => {
			shell();
			document.querySelector('.lpm-dt-info').remove();
			document.querySelector('.lpm-dt-pagination').remove();
			await build().reload();

			expect(rows()).toEqual(['de', 'GB']);
		});

		it('marks the table busy while the fetch is in flight', async () => {
			shell();
			let release;
			const table = build({
				fetchAll: () =>
					new Promise((resolve) => {
						release = resolve;
					}),
			});

			const pending = table.reload();

			expect(document.querySelector('table')).toHaveAttribute(
				'aria-busy',
				'true'
			);
			expect(document.querySelector('.lpm-dt-overlay')).toHaveClass(
				'is-visible'
			);

			release(RECORDS);
			await pending;

			expect(document.querySelector('table')).toHaveAttribute(
				'aria-busy',
				'false'
			);
			expect(document.querySelector('.lpm-dt-overlay')).not.toHaveClass(
				'is-visible'
			);
		});

		it('shows the failure in the table instead of an empty body', async () => {
			// An empty body would read as "there are no countries", which is a
			// different and much more alarming statement than "loading failed".
			shell();
			const table = build({
				fetchAll: jest.fn().mockRejectedValue(new Error('Network down.')),
			});

			await expect(table.reload()).rejects.toThrow('Network down.');

			const row = document.querySelector('.lpm-dt-body tr');

			expect(row).toHaveClass('lpm-dt-error-row');
			expect(row).toHaveTextContent('Network down.');
		});

		it('clears the busy state after a failure too', async () => {
			shell();
			const table = build({
				fetchAll: jest.fn().mockRejectedValue(new Error('Network down.')),
			});

			await expect(table.reload()).rejects.toThrow();

			expect(document.querySelector('table')).toHaveAttribute(
				'aria-busy',
				'false'
			);
		});

		it('spans the error row across every column', async () => {
			shell();
			const table = build({
				fetchAll: jest.fn().mockRejectedValue(new Error('Nope.')),
			});

			await expect(table.reload()).rejects.toThrow();

			expect(document.querySelector('.lpm-dt-body td').colSpan).toBe(2);
		});

		it('reports an empty record set as having no rows', async () => {
			shell();
			await build({ fetchAll: jest.fn().mockResolvedValue([]) }).reload();

			expect(document.querySelector('.lpm-dt-body tr')).toHaveClass(
				'lpm-dt-state-row'
			);
			expect(document.querySelector('.lpm-dt-info')).toHaveTextContent(
				'No rows to show.'
			);
		});
	});

	describe('sorting', () => {
		it('sorts strings case-insensitively', async () => {
			// Sensitivity "base": a lowercase "de" must not sort after "US".
			shell(10);
			await build().reload();

			expect(rows()).toEqual(['de', 'GB', 'JP', 'TR', 'US']);
		});

		it('compares numeric columns as numbers, not as text', async () => {
			// String order would put 10 and 155 before 2.
			shell(10);
			const table = build();
			await table.reload();

			document.querySelector('[data-lpm-sort="rate"]').click();

			expect(rows()).toEqual(['de', 'US', 'GB', 'TR', 'JP']);
		});

		it('flips the direction when the sorted column is clicked again', async () => {
			shell(10);
			await build().reload();

			document.querySelector('[data-lpm-sort="code"]').click();

			expect(rows()).toEqual(['US', 'TR', 'JP', 'GB', 'de']);
		});

		it('starts a newly chosen column ascending', async () => {
			shell(10);
			await build().reload();

			document.querySelector('[data-lpm-sort="code"]').click();
			document.querySelector('[data-lpm-sort="rate"]').click();

			expect(rows()).toEqual(['de', 'US', 'GB', 'TR', 'JP']);
		});

		it('publishes the sort direction to assistive technology', async () => {
			shell(10);
			await build().reload();

			const code = document.querySelector('[data-lpm-sort="code"]');
			const rate = document.querySelector('[data-lpm-sort="rate"]');

			expect(code).toHaveAttribute('aria-sort', 'ascending');
			expect(code).toHaveClass('lpm-dt-sort-asc');
			expect(rate).toHaveAttribute('aria-sort', 'none');

			code.click();

			expect(code).toHaveAttribute('aria-sort', 'descending');
			expect(code).toHaveClass('lpm-dt-sort-desc');
		});

		it('sorts from the keyboard on Enter and Space', async () => {
			shell(10);
			await build().reload();
			const code = document.querySelector('[data-lpm-sort="code"]');

			const enter = new KeyboardEvent('keydown', {
				key: 'Enter',
				cancelable: true,
			});
			code.dispatchEvent(enter);

			expect(enter.defaultPrevented).toBe(true);
			expect(rows()[0]).toBe('US');

			code.dispatchEvent(new KeyboardEvent('keydown', { key: ' ' }));

			expect(rows()[0]).toBe('de');
		});

		it('ignores other keys on a sortable header', async () => {
			shell(10);
			await build().reload();
			const code = document.querySelector('[data-lpm-sort="code"]');

			code.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));

			expect(rows()[0]).toBe('de');
		});

		it('returns to the first page when the sort changes', async () => {
			shell();
			const table = build();
			await table.reload();
			table.goTo(3);

			document.querySelector('[data-lpm-sort="rate"]').click();

			expect(table.state.page).toBe(1);
		});
	});

	describe('pagination', () => {
		it('renders no pager when everything fits on one page', async () => {
			shell(10);
			await build().reload();

			expect(pagerLabels()).toEqual([]);
		});

		it('renders previous, the page window and next', async () => {
			shell();
			await build().reload();

			expect(pagerLabels()).toEqual(['‹', '1', '2', '3', '›']);
		});

		it('disables previous on the first page and next on the last', async () => {
			shell();
			const table = build();
			await table.reload();

			const buttons = () =>
				Array.from(document.querySelectorAll('.lpm-dt-pagination button'));

			expect(buttons()[0]).toBeDisabled();
			expect(buttons().at(-1)).toBeEnabled();

			table.goTo(3);

			expect(buttons()[0]).toBeEnabled();
			expect(buttons().at(-1)).toBeDisabled();
		});

		it('marks the current page for assistive technology', async () => {
			shell();
			await build().reload();

			const current = document.querySelector(
				'.lpm-dt-pagination [aria-current="page"]'
			);

			expect(current).toHaveTextContent('1');
			expect(current).toHaveClass('is-active');
		});

		it('goes to the page whose button was pressed', async () => {
			shell();
			await build().reload();

			document
				.querySelectorAll('.lpm-dt-pagination button')[2]
				.dispatchEvent(new MouseEvent('click', { bubbles: true }));

			expect(rows()).toEqual(['JP', 'TR']);
		});

		it('ignores a press on a disabled pager button', async () => {
			shell();
			await build().reload();

			document
				.querySelectorAll('.lpm-dt-pagination button')[0]
				.dispatchEvent(new MouseEvent('click', { bubbles: true }));

			expect(rows()).toEqual(['de', 'GB']);
		});

		it('clamps a page number beyond the end', async () => {
			shell();
			const table = build();
			await table.reload();

			table.goTo(99);

			expect(table.state.page).toBe(3);
			expect(rows()).toEqual(['US']);
		});

		it('reports the range on the current page', async () => {
			shell();
			const table = build();
			await table.reload();

			expect(document.querySelector('.lpm-dt-info')).toHaveTextContent(
				'Showing 1 to 2 of 5.'
			);

			table.goTo(3);

			expect(document.querySelector('.lpm-dt-info')).toHaveTextContent(
				'Showing 5 to 5 of 5.'
			);
		});
	});

	describe('search', () => {
		beforeEach(() => {
			jest.useFakeTimers();
		});

		afterEach(() => {
			jest.useRealTimers();
		});

		/**
		 * Type into the search box and let the debounce elapse.
		 *
		 * @param {string} term Search term.
		 * @returns {void}
		 */
		function type(term) {
			const input = document.querySelector('.lpm-dt-search');

			input.value = term;
			input.dispatchEvent(new Event('input'));
			jest.advanceTimersByTime(200);
		}

		it('filters through the caller-supplied predicate', async () => {
			shell(10);
			const table = build();
			await table.reload();

			type('united');

			expect(rows()).toEqual(['GB', 'US']);
		});

		it('waits for the typing to settle before filtering', async () => {
			shell(10);
			const table = build();
			await table.reload();

			const input = document.querySelector('.lpm-dt-search');
			input.value = 'uni';
			input.dispatchEvent(new Event('input'));
			jest.advanceTimersByTime(150);

			// Still the full set: the debounce has not elapsed.
			expect(rows()).toHaveLength(5);

			jest.advanceTimersByTime(50);

			expect(rows()).toEqual(['GB', 'US']);
		});

		it('says nothing matched rather than showing the empty-table wording', async () => {
			// "No rows to show" during an active search would suggest the table
			// itself is empty.
			shell(10);
			const table = build();
			await table.reload();

			type('zzz');

			expect(document.querySelector('.lpm-dt-body tr')).toHaveTextContent(
				'Nothing matches this search.'
			);
		});

		it('returns to the first page when the term changes', async () => {
			shell();
			const table = build();
			await table.reload();
			table.goTo(3);

			type('united');

			expect(table.state.page).toBe(1);
		});

		it('restores the full set when the box is cleared', async () => {
			shell(10);
			const table = build();
			await table.reload();

			type('united');
			type('');

			expect(rows()).toHaveLength(5);
		});
	});

	describe('page size', () => {
		it('redraws with the chosen size', async () => {
			shell();
			const table = build();
			await table.reload();

			const select = document.querySelector('.lpm-dt-per-page-select');
			select.value = '10';
			select.dispatchEvent(new Event('change'));

			expect(rows()).toHaveLength(5);
			expect(table.state.page).toBe(1);
		});
	});
});
