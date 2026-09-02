/**
 * Automatic totals for the provider repeaters of a "propuesta de gasto".
 *
 * For every repeater whose sub-repeater rows carry quantity, unitPrice and
 * total inputs (servicios, suministros, expertos) it computes, on input:
 * row total = quantity × unitPrice; provider gross = Σ row totals;
 * provider total = gross + igic − irpf (igic and irpf stay editable euro
 * amounts). Computed inputs become readonly (data-calculado) but are still
 * posted. Each provider card gets a summary line, a summary card lists the
 * totals per kind and the grand total is written to gasto_numero.
 *
 * Plain DOM, no jQuery. It does nothing when the fields are absent, so it is
 * safe on every document type.
 */
(function () {
	'use strict';

	var BUTTON_SELECTOR = '.documentate-array-add, .documentate-array-remove, .documentate-subarray-add, .documentate-subarray-remove';
	var LABELS = {
		servicios: 'Servicio',
		suministros: 'Suministro',
		expertos: 'Experto'
	};
	var formatter = null;

	function capitalize(text) {
		text = String(text || '');
		return text.charAt(0).toUpperCase() + text.slice(1);
	}

	function label(slug) {
		return LABELS[slug] || capitalize(slug);
	}

	// es-ES: "1.234,56 €".
	function formatAmount(value) {
		if (formatter === null) {
			try {
				formatter = new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
			} catch (error) {
				formatter = false;
			}
		}
		return (formatter ? formatter.format(value) : value.toFixed(2)) + ' €';
	}

	// Accepts "1234.5", "1.234,50", "1234,5 €"; anything else counts as 0.
	function toNumber(value) {
		var text = String(value === null || value === undefined ? '' : value).replace(/[\s€]/g, '');
		if (text === '') {
			return 0;
		}
		if (text.indexOf(',') !== -1) {
			text = text.replace(/\./g, '').replace(',', '.');
		}
		var result = parseFloat(text);
		return isFinite(result) ? result : 0;
	}

	function roundToCents(value) {
		return Math.round(value * 100) / 100;
	}

	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (character) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
		});
	}

	// Control of `root` whose name ends with "[key]". With `inRow` the
	// control must sit in the sub-row itself; otherwise it must not belong
	// to any sub-row (provider-level amounts).
	function control(root, key, inRow) {
		var suffix = '[' + key + ']';
		var candidates = root.querySelectorAll('input, select, textarea');
		for (var i = 0; i < candidates.length; i++) {
			var name = candidates[i].getAttribute('name') || '';
			if (name.slice(-suffix.length) !== suffix) {
				continue;
			}
			var subRow = candidates[i].closest('.documentate-subarray-item');
			if (inRow ? subRow === root : subRow === null) {
				return candidates[i];
			}
		}
		return null;
	}

	// Writes a computed amount and locks the control.
	function lockValue(input, value) {
		if (!input) {
			return;
		}
		input.readOnly = true;
		input.setAttribute('data-calculado', '1');
		var text = value.toFixed(2);
		if (input.value !== text) {
			input.value = text;
		}
	}

	// Gives the control back to the user: there is nothing to compute, so
	// whatever was typed stays and stays editable.
	function unlock(input) {
		if (!input) {
			return;
		}
		input.readOnly = false;
		input.removeAttribute('data-calculado');
	}

	// Row total = quantity × unitPrice. Null when the row has no total at all
	// (not a concepto row); a row without quantity and unitPrice keeps the
	// amount typed by hand and still counts towards the gross.
	function computeRow(row) {
		var quantity = control(row, 'cantidad', true);
		var unitPrice = control(row, 'unitario', true);
		var total = control(row, 'total', true);
		if (!total) {
			return null;
		}
		var manual = !quantity || !unitPrice
			|| (quantity.value.trim() === '' && unitPrice.value.trim() === '');
		if (manual) {
			unlock(total);
			return { total: toNumber(total.value), empty: total.value.trim() === '' };
		}
		var amount = roundToCents(toNumber(quantity.value) * toNumber(unitPrice.value));
		lockValue(total, amount);
		return { total: amount, empty: false };
	}

	// "Servicio 1 · Proveedor · 2 conceptos · 1.234,56 €" on the card (or on
	// the <summary> when the card is wrapped in <details class="documentate-proveedor">).
	function renderProviderSummary(item, slug, index, conceptCount, total) {
		var provider = control(item, 'proveedor', false);
		var name = provider && provider.value.trim() !== '' ? provider.value.trim() : 'sin proveedor';
		var text = label(slug) + ' ' + index + ' · ' + name + ' · ' + conceptCount
			+ (conceptCount === 1 ? ' concepto' : ' conceptos') + ' · ' + formatAmount(total);

		var details = item.closest('details.documentate-proveedor');
		var target = details ? details.querySelector('summary') : null;
		if (!target) {
			target = item.querySelector('.documentate-proveedor-resumen');
		}
		if (!target) {
			target = document.createElement('div');
			target.className = 'documentate-proveedor-resumen';
			var toolbar = item.querySelector('.documentate-array-item-toolbar');
			item.insertBefore(target, toolbar ? toolbar.nextSibling : item.firstChild);
		}
		target.textContent = text;
	}

	// Provider: gross = Σ row totals; total = gross + igic − irpf. Null when
	// the card carries no concepto row at all (not a provider repeater).
	function computeProvider(item, slug, index) {
		var rows = item.querySelectorAll('.documentate-subarray-item');
		var gross = 0;
		var conceptCount = 0;
		var applicable = false;
		for (var i = 0; i < rows.length; i++) {
			var row = computeRow(rows[i]);
			if (row === null) {
				continue;
			}
			applicable = true;
			if (!row.empty) {
				conceptCount++;
				gross += row.total;
			}
		}
		if (!applicable) {
			return null;
		}

		var igic = control(item, 'igic', false);
		var irpf = control(item, 'irpf', false);
		var isEmpty = conceptCount === 0;
		gross = roundToCents(gross);
		var total = isEmpty ? 0 : roundToCents(gross + toNumber(igic && igic.value) - toNumber(irpf && irpf.value));
		var grossField = control(item, 'bruto', false);
		var totalField = control(item, 'total', false);

		// With no concepto to add up there is nothing to own: the amounts
		// stay as the user left them, and editable.
		if (isEmpty) {
			unlock(grossField);
			unlock(totalField);
		} else {
			lockValue(grossField, gross);
			lockValue(totalField, total);
		}
		renderProviderSummary(item, slug, index, conceptCount, total);

		return { total: total, conceptCount: conceptCount };
	}

	// Summary card (Servicios (n) / Suministros (n) / … / Total de la
	// propuesta) and the grand total written to gasto_numero.
	function renderOverallSummary(totals) {
		var expenseField = document.querySelector('[name="documentate_field_gasto_numero"]');
		var container = document.querySelector('.dcta-resumen, [data-documentate-resumen]');
		if (!container && expenseField) {
			container = document.createElement('div');
			container.className = 'documentate-resumen dcta-resumen';
			expenseField.parentNode.insertBefore(container, expenseField);
		}

		var sum = 0;
		var providerCount = 0;
		var html = '<dl>';
		totals.forEach(function (row) {
			sum += row.total;
			providerCount += row.n;
			html += '<dt>' + escapeHtml(capitalize(row.slug)) + ' (' + row.n + ')</dt><dd>' + escapeHtml(formatAmount(row.total)) + '</dd>';
		});
		sum = roundToCents(sum);
		// Nothing itemised yet: a "Total de la propuesta 0,00 €" above a
		// figure typed by hand reads as a broken calculation, so the card
		// says what is actually going on.
		html += providerCount > 0
			? '<dt class="documentate-resumen-total">Total de la propuesta</dt>'
				+ '<dd class="documentate-resumen-total">' + escapeHtml(formatAmount(sum)) + '</dd>'
			: '<dt class="documentate-resumen-total">Total de la propuesta</dt>'
				+ '<dd class="documentate-resumen-total">Sin proveedores todavía</dd>';
		html += '</dl>';

		if (container) {
			container.innerHTML = html;
		}
		if (expenseField) {
			if (providerCount > 0) {
				lockValue(expenseField, sum);
			} else {
				// Nothing itemised: gestión may still type the total by hand.
				unlock(expenseField);
			}
		}
	}

	function recalculate() {
		var totals = [];
		var repeaters = document.querySelectorAll('.documentate-array-field[data-array-field]');
		for (var r = 0; r < repeaters.length; r++) {
			var slug = repeaters[r].getAttribute('data-array-field');
			var box = repeaters[r].querySelector('.documentate-array-items');
			if (!box) {
				continue;
			}
			var sum = 0;
			var providerCount = 0;
			var applicable = false;
			var index = 0;
			// Cards may be wrapped (e.g. in <details>), so they are not
			// necessarily direct children of the items box.
			var items = box.querySelectorAll('.documentate-array-item');
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				if (item.closest('.documentate-array-items') !== box) {
					continue;
				}
				index++;
				var provider = computeProvider(item, slug, index);
				if (provider === null) {
					continue;
				}
				applicable = true;
				if (provider.conceptCount > 0) {
					providerCount++;
					sum += provider.total;
				}
			}
			if (applicable) {
				totals.push({ slug: slug, n: providerCount, total: roundToCents(sum) });
			}
		}
		if (totals.length) {
			renderOverallSummary(totals);
		}
		return totals;
	}

	function onFieldChange(event) {
		var target = event.target;
		if (target && target.closest && target.closest('.documentate-array-field')) {
			recalculate();
		}
	}

	// Rows are added, removed or reordered by documentate-annexes.js, whose
	// handlers sit below `document` and have already run when this fires.
	function onToolbarClick(event) {
		var target = event.target;
		if (target && target.closest && target.closest(BUTTON_SELECTOR)) {
			recalculate();
		}
	}

	function init() {
		document.addEventListener('input', onFieldChange);
		document.addEventListener('change', onFieldChange);
		document.addEventListener('click', onToolbarClick);
		document.addEventListener('drop', recalculate);
		recalculate();
	}

	window.documentateCalculations = {
		recalculate: recalculate,
		toNumber: toNumber,
		formatAmount: formatAmount
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
