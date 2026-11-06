/**
 * Tap support for the `?` help popovers next to the settings labels.
 */

const ROOT_SELECTOR = '[data-lpm-help]';
const TRIGGER_SELECTOR = '.lpm-help-trigger';
const OPEN_CLASS = 'is-open';

/**
 * Close every open popover, optionally sparing one.
 *
 * @param {Element|null} except Popover left untouched.
 * @returns {void}
 */
function closeAll(except = null) {
	document
		.querySelectorAll(`${ROOT_SELECTOR}.${OPEN_CLASS}`)
		.forEach((root) => {
			if (root !== except) {
				root.classList.remove(OPEN_CLASS);
			}
		});
}

/**
 * Bind one trigger.
 *
 * @param {Element} root Popover wrapper.
 * @returns {void}
 */
function bind(root) {
	const trigger = root.querySelector(TRIGGER_SELECTOR);

	if (!trigger) {
		return;
	}

	trigger.addEventListener('click', (event) => {
		event.preventDefault();
		event.stopPropagation();

		const wasOpen = root.classList.contains(OPEN_CLASS);

		closeAll(root);
		root.classList.toggle(OPEN_CLASS, !wasOpen);

		if (wasOpen) {
			trigger.blur();
		}
	});
}

/**
 * Bind every help popover on the page.
 *
 * @returns {void}
 */
export default function initHelpPopovers() {
	const roots = document.querySelectorAll(ROOT_SELECTOR);

	if (roots.length === 0) {
		return;
	}

	roots.forEach(bind);

	document.addEventListener('click', () => closeAll());
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			closeAll();
		}
	});
}
