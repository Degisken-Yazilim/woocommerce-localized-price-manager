/**
 * Add / remove behaviour for the metabox rule tables.
 *
 * @param {object} options              Wiring options.
 * @param {string} options.body         Selector of the `<tbody>` rows are appended to.
 * @param {string} options.template     Selector of the `<template>` holding a blank row.
 * @param {string} options.addButton    Selector of the "add row" button.
 * @param {string} options.removeButton Selector of the per-row remove button.
 * @returns {void}
 */
export default function initRepeatableRows({
	body,
	template,
	addButton,
	removeButton = '.lpm-remove-row-btn',
}) {
	const rowsBody = document.querySelector(body);
	const rowTemplate = document.querySelector(template);

	if (!rowsBody || !rowTemplate) {
		return;
	}

	document.querySelectorAll(addButton).forEach((button) => {
		button.addEventListener('click', (event) => {
			event.preventDefault();

			const row = rowTemplate.content
				? rowTemplate.content.cloneNode(true)
				: null;

			if (!row) {
				return;
			}

			rowsBody.appendChild(row);

			const added = rowsBody.lastElementChild;
			const firstField = added
				? added.querySelector('select, input')
				: null;

			if (firstField) {
				firstField.focus();
			}
		});
	});

	rowsBody.addEventListener('click', (event) => {
		const button = event.target.closest(removeButton);

		if (!button || !rowsBody.contains(button)) {
			return;
		}

		event.preventDefault();

		const row = button.closest('tr');

		if (row) {
			row.remove();
		}
	});
}
