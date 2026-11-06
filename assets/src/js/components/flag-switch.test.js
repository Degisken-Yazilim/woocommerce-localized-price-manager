/**
 * Tests for the flag switch, which is the only thing keeping a rule row's
 * posted flag in step with what the switch shows.
 */

import { beforeEach, describe, expect, it } from '@jest/globals';

import initFlagSwitches from './flag-switch.js';

/**
 * Render one rule row's pair of switches.
 *
 * @returns {void}
 */
function render() {
	document.body.innerHTML = `
		<table>
			<tbody class="rows">
				<tr>
					<td>
						<label class="lpm-switch">
							<input type="hidden" class="lpm-flag-value" name="lpm_cat_blocked[]" value="0" />
							<input type="checkbox" class="lpm-flag-toggle" aria-label="Block" />
							<span class="lpm-switch-slider"></span>
						</label>
					</td>
					<td>
						<label class="lpm-switch">
							<input type="hidden" class="lpm-flag-value" name="lpm_cat_active[]" value="1" />
							<input type="checkbox" class="lpm-flag-toggle" aria-label="Active" checked />
							<span class="lpm-switch-slider"></span>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
	`;
}

/**
 * Read one switch and the hidden input it drives.
 *
 * @param {string} name Submitted field name.
 * @returns {{toggle: HTMLInputElement, value: HTMLInputElement}} The pair.
 */
function pair(name) {
	const value = document.querySelector(`[name="${name}"]`);

	return {
		value,
		toggle: value.parentElement.querySelector('.lpm-flag-toggle'),
	};
}

/**
 * Flip a checkbox the way a click does.
 *
 * @param {HTMLInputElement} toggle Checkbox.
 * @param {boolean}          on     Desired state.
 * @returns {void}
 */
function flip(toggle, on) {
	toggle.checked = on;
	toggle.dispatchEvent(new Event('change', { bubbles: true }));
}

describe('the flag switch', () => {
	beforeEach(() => {
		render();
		initFlagSwitches();
	});

	it('writes the ticked state into the hidden value', () => {
		const blocked = pair('lpm_cat_blocked[]');

		flip(blocked.toggle, true);

		expect(blocked.value.value).toBe('1');
	});

	it('writes the unticked state into the hidden value', () => {
		const active = pair('lpm_cat_active[]');

		flip(active.toggle, false);

		expect(active.value.value).toBe('0');
	});

	it('drives only the hidden input inside its own label', () => {
		flip(pair('lpm_cat_blocked[]').toggle, true);

		// The other flag of the same row must not move with it.
		expect(pair('lpm_cat_active[]').value.value).toBe('1');
	});

	it('works on a row added after it was wired', () => {
		// The rows are cloned from a template once the page is up, so a clone
		// has to work without being wired again.
		const row = document.querySelector('.rows tr');

		document.querySelector('.rows').append(row.cloneNode(true));

		const clones = document.querySelectorAll(
			'[name="lpm_cat_blocked[]"]'
		);

		expect(clones).toHaveLength(2);

		const clone = clones[1];

		flip(clone.parentElement.querySelector('.lpm-flag-toggle'), true);

		expect(clone.value).toBe('1');
	});

	it('returns cleanly when called with a falsy root', () => {
		expect(() => initFlagSwitches(null)).not.toThrow();
	});

	it('ignores change events on elements that are not flag toggles', () => {
		const otherInput = document.createElement('input');
		document.body.appendChild(otherInput);

		expect(() => {
			otherInput.dispatchEvent(new Event('change', { bubbles: true }));
		}).not.toThrow();

		otherInput.remove();
	});
});
