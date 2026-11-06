/**
 * Turn a server rendered settings form into the JSON payload its REST route
 * expects.
 */

/**
 * Serialize one form.
 *
 * @param {HTMLFormElement} form Form element.
 * @returns {object} Payload keyed by option name.
 */
export default function serializeForm(form) {
	const payload = {};
	const groups = new Set();

	const controls = form.querySelectorAll(
		'input[name], select[name], textarea[name]'
	);

	controls.forEach((control) => {
		const rawName = control.getAttribute('name');

		if (!rawName || control.disabled) {
			return;
		}

		if (rawName.endsWith('[]')) {
			const key = rawName.slice(0, -2);
			groups.add(key);

			if (!Array.isArray(payload[key])) {
				payload[key] = [];
			}

			if (control.type === 'checkbox' || control.type === 'radio') {
				if (control.checked) {
					payload[key].push(control.value);
				}

				return;
			}

			payload[key].push(control.value);

			return;
		}

		if (control.type === 'checkbox') {
			payload[rawName] = control.checked;

			return;
		}

		if (control.type === 'radio') {
			if (control.checked) {
				payload[rawName] = control.value;
			} else if (!Object.hasOwn(payload, rawName)) {
				payload[rawName] = '';
			}

			return;
		}

		payload[rawName] = control.value;
	});

	groups.forEach((key) => {
		if (!Array.isArray(payload[key])) {
			payload[key] = [];
		}
	});

	return payload;
}
