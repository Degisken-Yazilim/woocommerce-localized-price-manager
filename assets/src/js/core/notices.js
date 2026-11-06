/**
 * Dismissible admin notices rendered into the page's notice area.
 */

import { text } from './config.js';

const CONTAINER_ID = 'lpm-notices';

/**
 * Get (or lazily create) the notice container.
 *
 * @returns {HTMLElement} Container element.
 */
function container() {
	let node = document.getElementById(CONTAINER_ID);

	if (!node) {
		node = document.createElement('div');
		node.id = CONTAINER_ID;
		node.setAttribute('role', 'status');
		node.setAttribute('aria-live', 'polite');

		const wrap = document.querySelector('.wrap');

		if (wrap) {
			wrap.insertBefore(node, wrap.firstChild);
		} else {
			document.body.appendChild(node);
		}
	}

	return node;
}

/**
 * Render one notice, replacing whatever was shown before.
 *
 * @param {string} message Message text.
 * @param {string} type    One of `success`, `error`, `warning` or `info`.
 * @returns {void}
 */
export function notice(message, type = 'success') {
	const node = container();
	node.textContent = '';

	const box = document.createElement('div');
	box.className = `notice notice-${type} is-dismissible`;

	const paragraph = document.createElement('p');
	paragraph.textContent = message;
	box.appendChild(paragraph);

	const dismiss = document.createElement('button');
	dismiss.type = 'button';
	dismiss.className = 'notice-dismiss';
	dismiss.addEventListener('click', () => box.remove());

	const dismissLabel = document.createElement('span');
	dismissLabel.className = 'screen-reader-text';
	dismissLabel.textContent = text('dismissNotice', 'Dismiss this notice.');
	dismiss.appendChild(dismissLabel);

	box.appendChild(dismiss);

	node.appendChild(box);
}

/**
 * Render a success notice.
 *
 * @param {string} message Message text.
 * @returns {void}
 */
export function success(message) {
	notice(message, 'success');
}

/**
 * Render an error notice.
 *
 * @param {string} message Message text.
 * @returns {void}
 */
export function failure(message) {
	notice(message, 'error');
}

/**
 * Remove every notice.
 *
 * @returns {void}
 */
export function clear() {
	container().textContent = '';
}

export default { notice, success, failure, clear };
