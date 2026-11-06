/**
 * Product pricing table interactions on the WooCommerce product edit screen.
 */

const PRICE_FIELD = 'input[name="lpm_local_prices[]"]';
const SALE_PRICE_FIELD = 'input[name="lpm_local_sale_prices[]"]';
const RATE_FIELD = 'input[name="lpm_exchange_rates[]"]';

/**
 * Check whether an input holds a value.
 *
 * @param {HTMLInputElement|null} input Input element.
 * @returns {boolean} True when the input exists and is not blank.
 */
function hasValue(input) {
	return Boolean(input && input.value.trim() !== '');
}

/**
 * Wire the product pricing table behaviour.
 *
 * @param {object} [options] Options for DOM element selectors.
 * @param {string} [options.table] Selector of the table.
 * @param {string} [options.body] Selector of the table body holding the rows.
 * @param {string} [options.mainRegularPrice] Selector of the product main regular price input.
 * @param {string} [options.mainSalePrice] Selector of the product main sale price input.
 * @param {string} [options.form] Selector of the edit form.
 * @returns {void}
 */
export default function initProductPricingTable({
	table = '#lpm_country_pricing_table',
	body = '#lpm_country_pricing_rows',
	mainRegularPrice = '#_regular_price',
	mainSalePrice = '#_sale_price',
	form = 'form#post',
} = {}) {
	const tableElement = document.querySelector(table);
	const bodyElement = document.querySelector(body);

	if (!tableElement || !bodyElement) {
		return;
	}

	const mainRegularInput = document.querySelector(mainRegularPrice);
	const mainSaleInput = document.querySelector(mainSalePrice);

	/**
	 * Read the three amount inputs of one row.
	 *
	 * @param {Element} row Table row element.
	 * @returns {{price: HTMLInputElement|null, salePrice: HTMLInputElement|null, rate: HTMLInputElement|null}} Row inputs.
	 */
	function rowInputs(row) {
		return {
			price: row.querySelector(PRICE_FIELD),
			salePrice: row.querySelector(SALE_PRICE_FIELD),
			rate: row.querySelector(RATE_FIELD),
		};
	}

	/**
	 * Derive the enabled state of one row's amount inputs from their values and the base prices.
	 *
	 * @param {Element} row Table row element.
	 * @returns {void}
	 */
	function updateRow(row) {
		const { price, salePrice, rate } = rowInputs(row);
		const hasRate = hasValue(rate);
		const hasLocalAmount = hasValue(price) || hasValue(salePrice);
		const rateGoverns = hasRate && !hasLocalAmount;

		if (price) {
			price.disabled = rateGoverns || !hasValue(mainRegularInput);
		}

		if (salePrice) {
			salePrice.disabled = rateGoverns || !hasValue(mainSaleInput);
		}

		if (rate) {
			rate.disabled = hasLocalAmount && !hasRate;
		}
	}

	/**
	 * Re-derive every row.
	 *
	 * @returns {void}
	 */
	function updateAllRows() {
		bodyElement.querySelectorAll('tr').forEach(updateRow);
	}

	updateAllRows();

	const onTableInput = (event) => {
		const target = event.target;
		const row = target && target.closest ? target.closest('tr') : null;

		if (!row) {
			return;
		}

		const { price, salePrice, rate } = rowInputs(row);

		if (!price || !rate) {
			return;
		}

		if (target === rate && hasValue(rate)) {
			price.value = '';
			if (salePrice) {
				salePrice.value = '';
			}
		} else if ((target === price || target === salePrice) && hasValue(target)) {
			rate.value = '';
		}

		updateRow(row);
	};

	bodyElement.addEventListener('input', onTableInput);
	bodyElement.addEventListener('change', onTableInput);

	if (mainRegularInput) {
		mainRegularInput.addEventListener('input', updateAllRows);
		mainRegularInput.addEventListener('change', updateAllRows);
	}

	if (mainSaleInput) {
		const onMainSalePriceChange = () => {
			if (!hasValue(mainSaleInput)) {
				bodyElement.querySelectorAll(SALE_PRICE_FIELD).forEach((input) => {
					input.value = '';
				});
			}

			updateAllRows();
		};

		mainSaleInput.addEventListener('input', onMainSalePriceChange);
		mainSaleInput.addEventListener('change', onMainSalePriceChange);
	}

	if (typeof MutationObserver !== 'undefined') {
		const observer = new MutationObserver((mutations) => {
			for (const mutation of mutations) {
				for (const node of mutation.addedNodes) {
					if (node.nodeType === 1) {
						if (node.tagName === 'TR') {
							updateRow(node);
						} else if (node.querySelectorAll) {
							node.querySelectorAll('tr').forEach(updateRow);
						}
					}
				}
			}
		});

		observer.observe(bodyElement, { childList: true });
	}

	const formElement = document.querySelector(form) || tableElement.closest('form');

	if (formElement) {
		formElement.addEventListener('submit', () => {
			bodyElement.querySelectorAll('input:disabled').forEach((input) => {
				input.disabled = false;
			});
		});
	}
}
