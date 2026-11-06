/**
 * Ordered provider list tests.
 *
 * The list is a priority queue: PHP reads the checked boxes in DOM order, so
 * the order of the `<li>` elements is the whole feature.
 */

import initOrderedLists from './ordered-list.js';

const TEMPLATE = '%1$s: %2$d / %3$d';

/**
 * Render a list of named items.
 *
 * @param {Array<string>} names Item labels, in order.
 * @returns {void}
 */
function render(names) {
	const item = (name) => `
		<li class="lpm-ordered-list-item">
			<label>${name}</label>
			<button type="button" data-lpm-move="up">Up</button>
			<button type="button" data-lpm-move="down">Down</button>
		</li>
	`;

	document.body.innerHTML = `
		<div>
			<ul data-lpm-ordered-list>${names.map(item).join('')}</ul>
			<span data-lpm-ordered-status="${TEMPLATE}"></span>
		</div>
	`;
}

/**
 * Read the current order of labels.
 *
 * @returns {Array<string>} Labels in DOM order.
 */
function order() {
	return Array.from(document.querySelectorAll('.lpm-ordered-list-item label')).map(
		(label) => label.textContent.trim()
	);
}

/**
 * Press one item's move button.
 *
 * @param {number} index     Zero-based item position.
 * @param {string} direction Either `up` or `down`.
 * @returns {MouseEvent} The dispatched event.
 */
function press(index, direction) {
	const event = new MouseEvent('click', { bubbles: true, cancelable: true });

	button(index, direction).dispatchEvent(event);

	return event;
}

/**
 * Read one item's button.
 *
 * @param {number} index     Zero-based item position.
 * @param {string} direction Either `up` or `down`.
 * @returns {HTMLButtonElement} Button.
 */
function button(index, direction) {
	const items = document.querySelectorAll('.lpm-ordered-list-item');

	return items[index].querySelector(`[data-lpm-move="${direction}"]`);
}

describe('initOrderedLists', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('does nothing on a screen with no list', () => {
		expect(() => initOrderedLists()).not.toThrow();
	});

	it('disables the two buttons that cannot act', () => {
		// Disabled rather than hidden: hiding them would reflow the control on
		// every move and drop the focus of the key that just pressed one.
		render(['Frankfurter', 'Fixer', 'OpenExchangeRates']);
		initOrderedLists();

		expect(button(0, 'up')).toBeDisabled();
		expect(button(0, 'down')).toBeEnabled();
		expect(button(1, 'up')).toBeEnabled();
		expect(button(1, 'down')).toBeEnabled();
		expect(button(2, 'down')).toBeDisabled();
	});

	it('disables both buttons of a lone item', () => {
		render(['Frankfurter']);
		initOrderedLists();

		expect(button(0, 'up')).toBeDisabled();
		expect(button(0, 'down')).toBeDisabled();
	});

	it('moves an item up', () => {
		render(['Frankfurter', 'Fixer', 'OpenExchangeRates']);
		initOrderedLists();

		press(1, 'up');

		expect(order()).toEqual(['Fixer', 'Frankfurter', 'OpenExchangeRates']);
	});

	it('moves an item down', () => {
		render(['Frankfurter', 'Fixer', 'OpenExchangeRates']);
		initOrderedLists();

		press(1, 'down');

		expect(order()).toEqual(['Frankfurter', 'OpenExchangeRates', 'Fixer']);
	});

	it('re-syncs the boundaries after a move', () => {
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		press(1, 'up');

		// Fixer is now first, so its up button must be the disabled one.
		expect(button(0, 'up')).toBeDisabled();
		expect(button(1, 'down')).toBeDisabled();
	});

	it('ignores a press on a disabled button', () => {
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		press(0, 'up');

		expect(order()).toEqual(['Frankfurter', 'Fixer']);
	});

	it('ignores a click that is not on a move button', () => {
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		document
			.querySelector('.lpm-ordered-list-item label')
			.dispatchEvent(new MouseEvent('click', { bubbles: true }));

		expect(order()).toEqual(['Frankfurter', 'Fixer']);
	});

	it('suppresses the default action so the settings form is not submitted', () => {
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		expect(press(1, 'up').defaultPrevented).toBe(true);
	});

	it('announces the new position in the live region', () => {
		render(['Frankfurter', 'Fixer', 'OpenExchangeRates']);
		initOrderedLists();

		press(2, 'up');

		expect(document.querySelector('[data-lpm-ordered-status]')).toHaveTextContent(
			'OpenExchangeRates: 2 / 3'
		);
	});

	it('stays silent when the screen renders no live region', () => {
		render(['Frankfurter', 'Fixer']);
		document.querySelector('[data-lpm-ordered-status]').remove();
		initOrderedLists();

		expect(() => press(1, 'up')).not.toThrow();
	});

	it('keeps focus on the pressed button while it can still act', () => {
		render(['Frankfurter', 'Fixer', 'OpenExchangeRates']);
		initOrderedLists();

		press(2, 'up');

		expect(button(1, 'up')).toHaveFocus();
	});

	it('hands focus to the opposite button when the press disabled its own', () => {
		// Pressing "up" on the second item makes it first, which disables the
		// very button that was pressed. A disabled button drops focus to the
		// document, so focus goes to the one that can still act.
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		press(1, 'up');

		expect(button(0, 'up')).toBeDisabled();
		expect(button(0, 'down')).toHaveFocus();
	});

	it('leaves the order alone when an enabled button has nowhere to move', () => {
		render(['Frankfurter', 'Fixer']);
		initOrderedLists();

		button(0, 'up').disabled = false;
		press(0, 'up');

		expect(order()).toEqual(['Frankfurter', 'Fixer']);
	});
});
