/**
 * Move up / move down behaviour for the ordered provider lists.
 */

const LIST_SELECTOR = '[data-lpm-ordered-list]';
const ITEM_SELECTOR = '.lpm-ordered-list-item';
const STATUS_SELECTOR = '[data-lpm-ordered-status]';

/**
 * Get the items of one list.
 *
 * @param {Element} list List element.
 * @returns {Array<Element>} Items, in DOM order.
 */
function itemsOf(list) {
	return Array.from(list.querySelectorAll(ITEM_SELECTOR));
}

/**
 * Get one item's move button.
 *
 * @param {Element} item      List item.
 * @param {string}  direction Either `up` or `down`.
 * @returns {HTMLButtonElement|null} Button.
 */
function buttonOf(item, direction) {
	return item.querySelector(`[data-lpm-move="${direction}"]`);
}

/**
 * Disable the two buttons that cannot do anything, enable every other one.
 *
 * @param {Element} list List element.
 * @returns {void}
 */
function syncBoundaries(list) {
	const items = itemsOf(list);
	const last = items.length - 1;

	items.forEach((item, index) => {
		const up = buttonOf(item, 'up');
		const down = buttonOf(item, 'down');

		if (up) {
			up.disabled = index === 0;
		}

		if (down) {
			down.disabled = index === last;
		}
	});
}

/**
 * Announce a move in the list's live region.
 *
 * @param {Element} list     List element.
 * @param {Element} item     Item that moved.
 * @param {number}  position New one-based position.
 * @param {number}  total    Number of items.
 * @returns {void}
 */
function announce(list, item, position, total) {
	const status = list.parentElement
		? list.parentElement.querySelector(STATUS_SELECTOR)
		: null;

	if (!status) {
		return;
	}

	const label = item.querySelector('label');
	const name = label ? label.textContent.trim() : '';
	const template = status.dataset.lpmOrderedStatus || '%1$s: %2$d / %3$d';

	status.textContent = template
		.split('%1$s')
		.join(name)
		.split('%2$d')
		.join(String(position))
		.split('%3$d')
		.join(String(total));
}

/**
 * Move one item within its list.
 *
 * @param {Element} list      List element.
 * @param {Element} item      List item.
 * @param {string}  direction Either `up` or `down`.
 * @returns {void}
 */
function move(list, item, direction) {
	const sibling =
		direction === 'up'
			? item.previousElementSibling
			: item.nextElementSibling;

	if (!sibling || !sibling.matches(ITEM_SELECTOR)) {
		return;
	}

	if (direction === 'up') {
		item.parentNode.insertBefore(item, sibling);
	} else {
		item.parentNode.insertBefore(sibling, item);
	}

	syncBoundaries(list);

	const items = itemsOf(list);

	announce(list, item, items.indexOf(item) + 1, items.length);

	const pressed = buttonOf(item, direction);
	const opposite = buttonOf(item, direction === 'up' ? 'down' : 'up');
	const target = pressed && !pressed.disabled ? pressed : opposite;

	if (target && !target.disabled) {
		target.focus();
	}
}

/**
 * Bind every ordered list on the page.
 *
 * @returns {void}
 */
export default function initOrderedLists() {
	document.querySelectorAll(LIST_SELECTOR).forEach((list) => {
		syncBoundaries(list);

		list.addEventListener('click', (event) => {
			const button = event.target.closest('[data-lpm-move]');

			if (!button || !list.contains(button) || button.disabled) {
				return;
			}

			event.preventDefault();

			const item = button.closest(ITEM_SELECTOR);

			if (item) {
				move(list, item, button.dataset.lpmMove);
			}
		});
	});
}
