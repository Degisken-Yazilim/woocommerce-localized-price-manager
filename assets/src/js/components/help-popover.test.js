/**
 * Help popover tap-support tests.
 *
 * Hover and keyboard focus are CSS alone; what is asserted here is the touch
 * case CSS cannot express — toggling by tap and every way of dismissing.
 */

import initHelpPopovers from './help-popover.js';

/**
 * Render `count` popovers.
 *
 * @param {number} count How many wrappers to render.
 * @returns {void}
 */
function render(count = 1) {
	const one = (index) => `
		<span data-lpm-help id="help-${index}">
			<button class="lpm-help-trigger" type="button">?</button>
			<span class="lpm-help-popover">Description ${index}.</span>
		</span>
	`;

	document.body.innerHTML = Array.from({ length: count }, (_, i) => one(i)).join(
		''
	);
}

/**
 * Read one wrapper.
 *
 * @param {number} index Wrapper index.
 * @returns {HTMLElement} Wrapper element.
 */
function root(index = 0) {
	return document.getElementById(`help-${index}`);
}

/**
 * Click one wrapper's trigger the way a pointer would, so the event bubbles.
 *
 * @param {number} index Wrapper index.
 * @returns {MouseEvent} The dispatched event.
 */
function tapTrigger(index = 0) {
	const event = new MouseEvent('click', { bubbles: true, cancelable: true });

	root(index).querySelector('.lpm-help-trigger').dispatchEvent(event);

	return event;
}

describe('initHelpPopovers', () => {
	beforeEach(() => {
		document.body.innerHTML = '';
	});

	it('does nothing on a screen with no popovers', () => {
		expect(() => initHelpPopovers()).not.toThrow();
	});

	it('skips a wrapper that has no trigger', () => {
		document.body.innerHTML = '<span data-lpm-help id="help-0"></span>';

		expect(() => initHelpPopovers()).not.toThrow();
	});

	it('opens on the first tap', () => {
		render();
		initHelpPopovers();

		tapTrigger();

		expect(root()).toHaveClass('is-open');
	});

	it('stays open, because the opening tap must not reach the document', () => {
		// The document listener closes everything. Without stopPropagation the
		// popover would close in the same gesture that opened it.
		render();
		initHelpPopovers();

		tapTrigger();

		expect(root()).toHaveClass('is-open');
	});

	it('closes on a second tap', () => {
		render();
		initHelpPopovers();

		tapTrigger();
		tapTrigger();

		expect(root()).not.toHaveClass('is-open');
	});

	it('drops focus when the second tap closes it', () => {
		// A browser treating a tap as keyboard-like focus would otherwise keep
		// the popover on screen through the CSS :focus-visible rule.
		render();
		initHelpPopovers();
		const trigger = root().querySelector('.lpm-help-trigger');

		tapTrigger();
		trigger.focus();
		tapTrigger();

		expect(trigger).not.toHaveFocus();
	});

	it('suppresses the default action so the label is not activated', () => {
		render();
		initHelpPopovers();

		expect(tapTrigger().defaultPrevented).toBe(true);
	});

	it('closes the others when one opens', () => {
		render(3);
		initHelpPopovers();

		tapTrigger(0);
		tapTrigger(2);

		expect(root(0)).not.toHaveClass('is-open');
		expect(root(2)).toHaveClass('is-open');
	});

	it('closes on a press anywhere outside', () => {
		render();
		document.body.insertAdjacentHTML('beforeend', '<main id="elsewhere"></main>');
		initHelpPopovers();

		tapTrigger();
		document
			.getElementById('elsewhere')
			.dispatchEvent(new MouseEvent('click', { bubbles: true }));

		expect(root()).not.toHaveClass('is-open');
	});

	it('closes on Escape', () => {
		render(2);
		initHelpPopovers();

		tapTrigger(0);
		tapTrigger(1);
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));

		expect(root(0)).not.toHaveClass('is-open');
		expect(root(1)).not.toHaveClass('is-open');
	});

	it('ignores keys other than Escape', () => {
		render();
		initHelpPopovers();

		tapTrigger();
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

		expect(root()).toHaveClass('is-open');
	});
});
