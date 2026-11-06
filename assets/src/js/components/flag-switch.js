/**
 * Keep a presentational switch and the hidden input it submits in step.
 */

/**
 * Wire the flag switches under one root.
 *
 * @param {Element|Document} [root] Container to listen on. Defaults to the document.
 * @returns {void}
 */
export default function initFlagSwitches(root = document) {
	if (!root) {
		return;
	}

	root.addEventListener('change', (event) => {
		const toggle = event.target.closest('.lpm-flag-toggle');

		if (!toggle) {
			return;
		}

		const value = toggle.parentElement
			? toggle.parentElement.querySelector('.lpm-flag-value')
			: null;

		if (value) {
			value.value = toggle.checked ? '1' : '0';
		}
	});
}
