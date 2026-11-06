/**
 * Client side data table.
 */

import { text } from '../core/config.js';

const SEARCH_DELAY = 200;
const PAGE_WINDOW = 5;

/**
 * Replace the printf-style placeholders PHP handed over.
 *
 * @param {string} template Message template.
 * @param {Array}  values   Replacement values, in order.
 * @returns {string} Filled message.
 */
function format(template, values) {
	return values.reduce(
		(carry, value, index) =>
			carry
				.split(`%${index + 1}$d`)
				.join(String(value))
				.split(`%${index + 1}$s`)
				.join(String(value)),
		String(template).split('%d').join(String(values[0]))
	);
}

/**
 * Build the page numbers a pager shows around the current page.
 *
 * @param {number} current Current page.
 * @param {number} pages   Total pages.
 * @returns {Array<number>} Page numbers, in order.
 */
function pageWindow(current, pages) {
	if (pages < 1) {
		return [];
	}

	const first = Math.max(1, Math.min(current - 2, pages - (PAGE_WINDOW - 1)));
	const last = Math.min(pages, first + (PAGE_WINDOW - 1));
	const numbers = [];

	for (let page = first; page <= last; page += 1) {
		numbers.push(page);
	}

	return numbers;
}

/**
 * Compare two sort keys, numerically when both look like numbers.
 *
 * @param {*} left  First key.
 * @param {*} right Second key.
 * @returns {number} Negative, zero or positive.
 */
function compareKeys(left, right) {
	const leftNumber = Number(left);
	const rightNumber = Number(right);

	if (
		left !== '' &&
		right !== '' &&
		!Number.isNaN(leftNumber) &&
		!Number.isNaN(rightNumber)
	) {
		return leftNumber - rightNumber;
	}

	return String(left).localeCompare(String(right), undefined, {
		sensitivity: 'base',
	});
}

/**
 * Upgrade one PHP rendered table shell into a paged, searchable, sortable one.
 *
 * @param {object}   options            Component options.
 * @param {Element}  options.root       Container carrying the toolbars and the table.
 * @param {Function} options.fetchAll   Returns a promise for every record.
 * @param {Function} options.renderRows Builds the `<tr>` elements of one page.
 * @param {Function} options.matches    Search predicate, `(item, term) => boolean`.
 * @param {Function} [options.filter]    Row predicate applied before the search,
 *                                       `(item) => boolean`. Runs whether or not
 *                                       a search term is present, which is what
 *                                       the search predicate cannot do.
 * @param {Function} options.sortKey    Sort key accessor, `(item, column) => value`.
 * @param {string}   options.orderBy    Initial sort column.
 * @returns {object|null} Controller, or null when the markup is not there.
 */
export default function createDataTable({
	root,
	fetchAll,
	renderRows,
	matches,
	filter,
	sortKey,
	orderBy = '',
}) {
	if (!root) {
		return null;
	}

	const table = root.querySelector('table');
	const body = root.querySelector('.lpm-dt-body');
	const overlay = root.querySelector('.lpm-dt-overlay');
	const info = root.querySelector('.lpm-dt-info');
	const pagination = root.querySelector('.lpm-dt-pagination');
	const search = root.querySelector('.lpm-dt-search');
	const perPageSelect = root.querySelector('.lpm-dt-per-page-select');

	if (!table || !body) {
		return null;
	}

	const state = {
		items: [],
		visible: [],
		page: 1,
		perPage: Number(root.dataset.lpmPerPage) || 25,
		search: '',
		orderBy,
		order: 'ASC',
		loading: false,
	};

	let searchTimer = 0;

	/**
	 * Count the columns the head declares, so a state row can span them all.
	 *
	 * @returns {number} Column count.
	 */
	function columnCount() {
		return table.querySelectorAll('thead th, thead td').length || 1;
	}

	/**
	 * Show or hide the loading state.
	 *
	 * @param {boolean} loading Whether the fetch is in flight.
	 * @returns {void}
	 */
	function setLoading(loading) {
		state.loading = loading;

		if (overlay) {
			overlay.classList.toggle('is-visible', loading);
			overlay.setAttribute('aria-hidden', loading ? 'false' : 'true');
		}

		table.setAttribute('aria-busy', loading ? 'true' : 'false');
	}

	/**
	 * Render a single full width message row.
	 *
	 * @param {string} message   Message text.
	 * @param {string} className Row class.
	 * @returns {void}
	 */
	function renderState(message, className) {
		const row = document.createElement('tr');
		const cell = document.createElement('td');

		row.className = className;
		cell.colSpan = columnCount();
		cell.textContent = message;
		row.appendChild(cell);

		body.textContent = '';
		body.appendChild(row);
	}

	/**
	 * Update the "showing x to y of z" line.
	 *
	 * @param {number} first First row number on the page, 1 based.
	 * @param {number} shown Rows on the current page.
	 * @returns {void}
	 */
	function renderInfo(first, shown) {
		if (!info) {
			return;
		}

		info.textContent =
			shown === 0
				? text('noRows', 'No rows to show.')
				: format(text('showing', 'Showing %1$d to %2$d of %3$d.'), [
					first,
					first + shown - 1,
					state.visible.length,
				]);
	}

	/**
	 * Build one pager button.
	 *
	 * @param {string}  label     Button label.
	 * @param {number}  page      Page the button leads to.
	 * @param {boolean} disabled  Whether the button can be pressed.
	 * @param {boolean} active    Whether the button is the current page.
	 * @param {string}  ariaLabel Accessible label.
	 * @returns {HTMLButtonElement} Button.
	 */
	function pageButton(label, page, disabled, active, ariaLabel) {
		const button = document.createElement('button');

		button.type = 'button';
		button.className = `lpm-page-btn${active ? ' is-active' : ''}`;
		button.textContent = label;
		button.dataset.lpmPage = String(page);
		button.disabled = disabled;
		button.setAttribute('aria-label', ariaLabel);

		if (active) {
			button.setAttribute('aria-current', 'page');
		}

		return button;
	}

	/**
	 * Rebuild the pager.
	 *
	 * @param {number} pages Total pages.
	 * @returns {void}
	 */
	function renderPagination(pages) {
		if (!pagination) {
			return;
		}

		pagination.textContent = '';

		if (pages <= 1) {
			return;
		}

		pagination.appendChild(
			pageButton(
				'‹',
				state.page - 1,
				state.page <= 1,
				false,
				text('previousPage', 'Previous page')
			)
		);

		pageWindow(state.page, pages).forEach((page) => {
			pagination.appendChild(
				pageButton(
					String(page),
					page,
					false,
					page === state.page,
					format(text('goToPage', 'Go to page %d'), [page])
				)
			);
		});

		pagination.appendChild(
			pageButton(
				'›',
				state.page + 1,
				state.page >= pages,
				false,
				text('nextPage', 'Next page')
			)
		);
	}

	/**
	 * Mark the sorted column in the head.
	 *
	 * @returns {void}
	 */
	function renderSortState() {
		table.querySelectorAll('[data-lpm-sort]').forEach((cell) => {
			const sorted = cell.dataset.lpmSort === state.orderBy;
			const ascending = sorted && state.order === 'ASC';

			cell.classList.toggle('lpm-dt-sort-asc', ascending);
			cell.classList.toggle('lpm-dt-sort-desc', sorted && !ascending);

			if (!sorted) {
				cell.setAttribute('aria-sort', 'none');
			} else {
				cell.setAttribute(
					'aria-sort',
					ascending ? 'ascending' : 'descending'
				);
			}
		});
	}

	/**
	 * Narrow the record set to the current search term, then sort it.
	 *
	 * @returns {void}
	 */
	function applyFilters() {
		const term = state.search.toLowerCase();

		const kept =
			typeof filter === 'function'
				? state.items.filter((item) => filter(item))
				: state.items;

		state.visible =
			term === ''
				? kept.slice()
				: kept.filter((item) => matches(item, term));

		if (state.orderBy !== '') {
			const direction = state.order === 'DESC' ? -1 : 1;

			state.visible.sort(
				(left, right) =>
					direction *
					compareKeys(
						sortKey(left, state.orderBy),
						sortKey(right, state.orderBy)
					)
			);
		}
	}

	/**
	 * Draw the current page.
	 *
	 * @returns {void}
	 */
	function render() {
		const pages = Math.max(
			1,
			Math.ceil(state.visible.length / state.perPage)
		);

		state.page = Math.min(Math.max(1, state.page), pages);

		const offset = (state.page - 1) * state.perPage;
		const page = state.visible.slice(offset, offset + state.perPage);

		if (page.length === 0) {
			renderState(
				state.search === ''
					? text('noRows', 'No rows to show.')
					: text('noResults', 'Nothing matches this search.'),
				'lpm-dt-state-row'
			);
		} else {
			body.textContent = '';
			renderRows(page).forEach((row) => body.appendChild(row));
		}

		renderInfo(offset + 1, page.length);
		renderPagination(pages);
		renderSortState();
	}

	/**
	 * Fetch every record and draw the first page.
	 *
	 * @returns {Promise<void>} Resolves once the table has been drawn.
	 */
	async function reload() {
		setLoading(true);

		try {
			state.items = await fetchAll();
			applyFilters();
			render();
		} catch (error) {
			state.items = [];
			state.visible = [];
			renderState(
				error && error.message
					? error.message
					: text('loadFailed', 'The table could not be loaded.'),
				'lpm-dt-error-row'
			);
			renderInfo(0, 0);
			renderPagination(0);

			throw error;
		} finally {
			setLoading(false);
		}
	}

	/**
	 * Go to one page.
	 *
	 * @param {number} page Page number.
	 * @returns {void}
	 */
	function goTo(page) {
		state.page = page;
		render();
	}

	/**
	 * Sort by one column, flipping the direction when it is already sorted.
	 *
	 * @param {string} column Column name.
	 * @returns {void}
	 */
	function sortBy(column) {
		if (state.orderBy === column) {
			state.order = state.order === 'ASC' ? 'DESC' : 'ASC';
		} else {
			state.orderBy = column;
			state.order = 'ASC';
		}

		state.page = 1;
		applyFilters();
		render();
	}

	if (pagination) {
		pagination.addEventListener('click', (event) => {
			const button = event.target.closest('[data-lpm-page]');

			if (button && !button.disabled) {
				goTo(Number(button.dataset.lpmPage));
			}
		});
	}

	table.querySelectorAll('[data-lpm-sort]').forEach((cell) => {
		cell.addEventListener('click', () => sortBy(cell.dataset.lpmSort));
		cell.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				sortBy(cell.dataset.lpmSort);
			}
		});
	});

	if (search) {
		search.addEventListener('input', () => {
			window.clearTimeout(searchTimer);
			searchTimer = window.setTimeout(() => {
				state.search = search.value.trim();
				state.page = 1;
				applyFilters();
				render();
			}, SEARCH_DELAY);
		});
	}

	if (perPageSelect) {
		perPageSelect.addEventListener('change', () => {
			state.perPage = Number(perPageSelect.value) || state.perPage;
			state.page = 1;
			render();
		});
	}

	/**
	 * Re-apply the filters and redraw from the first page.
	 *
	 * @returns {void}
	 */
	function refilter() {
		state.page = 1;
		applyFilters();
		render();
	}

	renderSortState();

	return { state, reload, goTo, render, refilter };
}
