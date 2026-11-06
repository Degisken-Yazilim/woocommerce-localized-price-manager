/**
 * Product pricing table component tests.
 */

import initProductPricingTable from './product-pricing-table.js';

/**
 * Render the product metabox markup for testing.
 *
 * @param {object} [config] Configuration options.
 * @param {string} [config.mainRegularPrice] Main product regular price value.
 * @param {string} [config.mainSalePrice] Main product sale price value.
 * @param {Array<{price: string, salePrice: string, rate: string}>} [config.rows] Initial row data.
 * @returns {void}
 */
function render({
	mainRegularPrice = '100',
	mainSalePrice = '',
	rows = [
		{ price: '100', salePrice: '80', rate: '' },
	],
} = {}) {
	const rowsHtml = rows
		.map(
			(r) => `
		<tr class="lpm-country-pricing-row">
			<td class="lpm-cell-active">
				<label class="lpm-switch">
					<input type="hidden" class="lpm-flag-value" name="lpm_is_active[]" value="1" />
					<input type="checkbox" class="lpm-flag-toggle" checked />
				</label>
			</td>
			<td class="lpm-cell-country">
				<select name="lpm_country_codes[]"><option value="TR">Turkey</option></select>
			</td>
			<td class="lpm-cell-currency">
				<select name="lpm_local_currencies[]"><option value="EUR">EUR</option></select>
			</td>
			<td class="lpm-cell-price">
				<input type="number" name="lpm_local_prices[]" value="${r.price}" class="lpm-number" />
			</td>
			<td class="lpm-cell-sale-price">
				<input type="number" name="lpm_local_sale_prices[]" value="${r.salePrice}" class="lpm-number" />
			</td>
			<td class="lpm-cell-rate">
				<input type="number" name="lpm_exchange_rates[]" value="${r.rate}" class="lpm-number" />
			</td>
		</tr>
	`
		)
		.join('');

	document.body.innerHTML = `
		<form id="post">
			<input type="text" id="_regular_price" value="${mainRegularPrice}" />
			<input type="text" id="_sale_price" value="${mainSalePrice}" />
			<table id="lpm_country_pricing_table">
				<tbody id="lpm_country_pricing_rows">
					${rowsHtml}
				</tbody>
			</table>
		</form>
	`;
}

/**
 * The three amount inputs of the first row, or of the given row.
 *
 * @param {Element} [row] Row element.
 * @returns {{price: HTMLInputElement, salePrice: HTMLInputElement, rate: HTMLInputElement}} Inputs.
 */
function inputs(row = document) {
	return {
		price: row.querySelector('input[name="lpm_local_prices[]"]'),
		salePrice: row.querySelector('input[name="lpm_local_sale_prices[]"]'),
		rate: row.querySelector('input[name="lpm_exchange_rates[]"]'),
	};
}

/**
 * Type a value into an input the way the browser reports it.
 *
 * @param {HTMLInputElement} input Input element.
 * @param {string} value New value.
 * @returns {void}
 */
function type(input, value) {
	input.value = value;
	input.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('initProductPricingTable', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('does nothing when the table is absent', () => {
		document.body.innerHTML = '<input type="text" id="_sale_price" />';
		expect(() => initProductPricingTable()).not.toThrow();
	});

	it('disables the rate of a row that carries a local price', () => {
		render({ mainSalePrice: '80', rows: [{ price: '50', salePrice: '40', rate: '' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(price.disabled).toBe(false);
		expect(salePrice.disabled).toBe(false);
		expect(rate.disabled).toBe(true);
	});

	it('disables the price and sale price of a row that carries a rate', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '', rate: '1.25' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(price.disabled).toBe(true);
		expect(salePrice.disabled).toBe(true);
		expect(rate.disabled).toBe(false);
	});

	it('leaves every input of an empty row enabled', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(price.disabled).toBe(false);
		expect(salePrice.disabled).toBe(false);
		expect(rate.disabled).toBe(false);
	});

	it('leaves a stored row carrying both a price and a rate editable on load', () => {
		render({ mainSalePrice: '80', rows: [{ price: '50', salePrice: '40', rate: '1.25' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(price.value).toBe('50');
		expect(rate.value).toBe('1.25');
		expect(price.disabled).toBe(false);
		expect(salePrice.disabled).toBe(false);
		expect(rate.disabled).toBe(false);
	});

	it('clears and disables the price and sale price when a rate is typed', () => {
		render({ mainSalePrice: '80', rows: [{ price: '100', salePrice: '80', rate: '' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		type(rate, '2.5');

		expect(price.value).toBe('');
		expect(price.disabled).toBe(true);
		expect(salePrice.value).toBe('');
		expect(salePrice.disabled).toBe(true);
		expect(rate.disabled).toBe(false);
	});

	it('enables the price and sale price again once the rate is emptied', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '', rate: '2.5' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(price.disabled).toBe(true);
		expect(salePrice.disabled).toBe(true);

		type(rate, '');

		expect(price.disabled).toBe(false);
		expect(salePrice.disabled).toBe(false);
		expect(rate.disabled).toBe(false);
	});

	it('clears and disables the rate when a price is typed', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { price, rate } = inputs();
		type(price, '49.99');

		expect(rate.value).toBe('');
		expect(rate.disabled).toBe(true);
	});

	it('clears and disables the rate when a sale price is typed', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { salePrice, rate } = inputs();
		type(salePrice, '39.99');

		expect(rate.value).toBe('');
		expect(rate.disabled).toBe(true);
	});

	it('enables the rate again once both the price and the sale price are emptied', () => {
		render({ mainSalePrice: '80', rows: [{ price: '50', salePrice: '40', rate: '' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		expect(rate.disabled).toBe(true);

		type(price, '');
		expect(rate.disabled).toBe(true);

		type(salePrice, '');
		expect(rate.disabled).toBe(false);
	});

	it('disables the price while the base product has no regular price', () => {
		render({ mainRegularPrice: '', rows: [{ price: '', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { price, rate } = inputs();
		expect(price.disabled).toBe(true);
		expect(rate.disabled).toBe(false);
	});

	it('follows the base regular price as it is typed and emptied', () => {
		render({ mainRegularPrice: '', rows: [{ price: '', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const mainRegular = document.getElementById('_regular_price');
		const { price } = inputs();

		type(mainRegular, '120');
		expect(price.disabled).toBe(false);

		type(mainRegular, '  ');
		expect(price.disabled).toBe(true);
	});

	it('disables the sale price while the base product has no sale price', () => {
		render({ mainSalePrice: '', rows: [{ price: '50', salePrice: '', rate: '' }] });

		initProductPricingTable();

		expect(inputs().salePrice.disabled).toBe(true);
	});

	it('enables the sale price once a base sale price is typed', () => {
		render({ mainSalePrice: '', rows: [{ price: '50', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { salePrice } = inputs();
		expect(salePrice.disabled).toBe(true);

		type(document.getElementById('_sale_price'), '75');

		expect(salePrice.disabled).toBe(false);
	});

	it('clears and disables every row sale price when the base sale price is cleared', () => {
		render({
			mainSalePrice: '80',
			rows: [
				{ price: '50', salePrice: '40', rate: '' },
				{ price: '60', salePrice: '45', rate: '' },
			],
		});

		initProductPricingTable();

		type(document.getElementById('_sale_price'), '   ');

		const salePrices = document.querySelectorAll('input[name="lpm_local_sale_prices[]"]');
		expect(Array.from(salePrices, (input) => input.value)).toEqual(['', '']);
		expect(Array.from(salePrices, (input) => input.disabled)).toEqual([true, true]);
	});

	it('enables the rate of a row whose only local amount was the cleared sale price', () => {
		render({ mainSalePrice: '80', rows: [{ price: '', salePrice: '40', rate: '' }] });

		initProductPricingTable();

		const { rate } = inputs();
		expect(rate.disabled).toBe(true);

		type(document.getElementById('_sale_price'), '');

		expect(rate.disabled).toBe(false);
	});

	it('updates newly added rows via MutationObserver', async () => {
		render({ rows: [] });

		initProductPricingTable();

		const tbody = document.getElementById('lpm_country_pricing_rows');
		const newRow = document.createElement('tr');
		newRow.className = 'lpm-country-pricing-row';
		newRow.innerHTML = `
			<td class="lpm-cell-price">
				<input type="number" name="lpm_local_prices[]" value="" />
			</td>
			<td class="lpm-cell-sale-price">
				<input type="number" name="lpm_local_sale_prices[]" value="" />
			</td>
			<td class="lpm-cell-rate">
				<input type="number" name="lpm_exchange_rates[]" value="1.5" />
			</td>
		`;

		tbody.appendChild(newRow);

		await new Promise((resolve) => {
			setTimeout(resolve, 0);
		});

		const { price, rate } = inputs(newRow);
		expect(price.disabled).toBe(true);
		expect(rate.disabled).toBe(false);
	});

	it('re-enables disabled inputs before form submit so POST array keys stay aligned', () => {
		render({ mainSalePrice: '', rows: [{ price: '50', salePrice: '', rate: '' }] });

		initProductPricingTable();

		const { salePrice, rate } = inputs();
		expect(salePrice.disabled).toBe(true);
		expect(rate.disabled).toBe(true);

		document.getElementById('post').dispatchEvent(new Event('submit'));

		expect(salePrice.disabled).toBe(false);
		expect(rate.disabled).toBe(false);
	});

	it('submits a row as a price or a rate, never both', () => {
		render({ mainSalePrice: '80', rows: [{ price: '100', salePrice: '80', rate: '' }] });

		initProductPricingTable();

		const { price, salePrice, rate } = inputs();
		type(rate, '2.5');

		document.getElementById('post').dispatchEvent(new Event('submit'));

		expect([price.value, salePrice.value, rate.value]).toEqual(['', '', '2.5']);
	});

	it('submits every sale price empty once the base sale price was cleared', () => {
		render({ mainSalePrice: '80', rows: [{ price: '50', salePrice: '40', rate: '' }] });

		initProductPricingTable();

		type(document.getElementById('_sale_price'), '');
		document.getElementById('post').dispatchEvent(new Event('submit'));

		const { salePrice } = inputs();
		expect(salePrice.disabled).toBe(false);
		expect(salePrice.value).toBe('');
	});

	it('ignores input events fired directly on the table outside a tr', () => {
		render();
		initProductPricingTable();

		const table = document.getElementById('lpm_country_pricing_table');
		expect(() => {
			table.dispatchEvent(new Event('input'));
		}).not.toThrow();
	});

	it('ignores input events fired on the body itself', () => {
		render();
		initProductPricingTable();

		const tbody = document.getElementById('lpm_country_pricing_rows');
		const before = inputs().price ? inputs().price.disabled : null;

		tbody.dispatchEvent(new Event('input'));

		expect(inputs().price ? inputs().price.disabled : null).toBe(before);
	});

	it('ignores input events on a row without price or rate inputs', () => {
		render();
		initProductPricingTable();

		const tbody = document.getElementById('lpm_country_pricing_rows');
		const emptyRow = document.createElement('tr');
		const dummyInput = document.createElement('input');
		emptyRow.appendChild(dummyInput);
		tbody.appendChild(emptyRow);

		expect(() => {
			dummyInput.dispatchEvent(new Event('input', { bubbles: true }));
		}).not.toThrow();
	});

	it('observes container nodes containing multiple tr elements added to tbody', async () => {
		render();
		initProductPricingTable();

		const tbody = document.getElementById('lpm_country_pricing_rows');
		const wrapper = document.createElement('tbody');
		const nestedRow = document.createElement('tr');
		nestedRow.innerHTML = `
			<td><input type="number" name="lpm_local_prices[]" value="" /></td>
			<td><input type="number" name="lpm_local_sale_prices[]" value="" /></td>
			<td><input type="number" name="lpm_exchange_rates[]" value="2.5" /></td>
		`;
		wrapper.appendChild(nestedRow);
		tbody.appendChild(wrapper);

		await new Promise((resolve) => {
			setTimeout(resolve, 0);
		});

		expect(inputs(nestedRow).price.disabled).toBe(true);
	});
});
