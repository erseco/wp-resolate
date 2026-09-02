/**
 * Automatic totals for the provider repeaters of a "propuesta de gasto".
 *
 * For every repeater whose sub-repeater rows carry cantidad, unitario and
 * total inputs (servicios, suministros, expertos) it computes, on input:
 * row total = cantidad × unitario; provider bruto = Σ row totals;
 * provider total = bruto + igic − irpf (igic and irpf stay editable euro
 * amounts). Computed inputs become readonly (data-calculado) but are still
 * posted. Each provider card gets a summary line, a summary card lists the
 * totals per kind and the grand total is written to gasto_numero.
 *
 * Plain DOM, no jQuery. It does nothing when the fields are absent, so it is
 * safe on every document type.
 */
(function () {
	'use strict';

	var SELECTOR_BOTONES = '.documentate-array-add, .documentate-array-remove, .documentate-subarray-add, .documentate-subarray-remove';
	var ETIQUETAS = {
		servicios: 'Servicio',
		suministros: 'Suministro',
		expertos: 'Experto'
	};
	var formato = null;

	function capitalizar(texto) {
		texto = String(texto || '');
		return texto.charAt(0).toUpperCase() + texto.slice(1);
	}

	function etiqueta(slug) {
		return ETIQUETAS[slug] || capitalizar(slug);
	}

	// es-ES: "1.234,56 €".
	function formatear(valor) {
		if (formato === null) {
			try {
				formato = new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
			} catch (error) {
				formato = false;
			}
		}
		return (formato ? formato.format(valor) : valor.toFixed(2)) + ' €';
	}

	// Accepts "1234.5", "1.234,50", "1234,5 €"; anything else counts as 0.
	function numero(valor) {
		var texto = String(valor === null || valor === undefined ? '' : valor).replace(/[\s€]/g, '');
		if (texto === '') {
			return 0;
		}
		if (texto.indexOf(',') !== -1) {
			texto = texto.replace(/\./g, '').replace(',', '.');
		}
		var resultado = parseFloat(texto);
		return isFinite(resultado) ? resultado : 0;
	}

	function redondear(valor) {
		return Math.round(valor * 100) / 100;
	}

	function escapar(texto) {
		return String(texto).replace(/[&<>"']/g, function (caracter) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[caracter];
		});
	}

	// Control of `raiz` whose name ends with "[clave]". With `enFila` the
	// control must sit in the sub-row itself; otherwise it must not belong
	// to any sub-row (provider-level amounts).
	function control(raiz, clave, enFila) {
		var sufijo = '[' + clave + ']';
		var candidatos = raiz.querySelectorAll('input, select, textarea');
		for (var i = 0; i < candidatos.length; i++) {
			var nombre = candidatos[i].getAttribute('name') || '';
			if (nombre.slice(-sufijo.length) !== sufijo) {
				continue;
			}
			var subfila = candidatos[i].closest('.documentate-subarray-item');
			if (enFila ? subfila === raiz : subfila === null) {
				return candidatos[i];
			}
		}
		return null;
	}

	// Writes a computed amount and locks the control.
	function fijar(input, valor) {
		if (!input) {
			return;
		}
		input.readOnly = true;
		input.setAttribute('data-calculado', '1');
		var texto = valor.toFixed(2);
		if (input.value !== texto) {
			input.value = texto;
		}
	}

	// Gives the control back to the user: there is nothing to compute, so
	// whatever was typed stays and stays editable.
	function liberar(input) {
		if (!input) {
			return;
		}
		input.readOnly = false;
		input.removeAttribute('data-calculado');
	}

	// Row total = cantidad × unitario. Null when the row has no total at all
	// (not a concepto row); a row without cantidad and unitario keeps the
	// amount typed by hand and still counts towards the bruto.
	function calcularFila(fila) {
		var cantidad = control(fila, 'cantidad', true);
		var unitario = control(fila, 'unitario', true);
		var total = control(fila, 'total', true);
		if (!total) {
			return null;
		}
		var manual = !cantidad || !unitario
			|| (cantidad.value.trim() === '' && unitario.value.trim() === '');
		if (manual) {
			liberar(total);
			return { total: numero(total.value), vacia: total.value.trim() === '' };
		}
		var importe = redondear(numero(cantidad.value) * numero(unitario.value));
		fijar(total, importe);
		return { total: importe, vacia: false };
	}

	// "Servicio 1 · Proveedor · 2 conceptos · 1.234,56 €" on the card (or on
	// the <summary> when the card is wrapped in <details class="documentate-proveedor">).
	function resumenProveedor(item, slug, indice, conceptos, total) {
		var proveedor = control(item, 'proveedor', false);
		var nombre = proveedor && proveedor.value.trim() !== '' ? proveedor.value.trim() : 'sin proveedor';
		var texto = etiqueta(slug) + ' ' + indice + ' · ' + nombre + ' · ' + conceptos
			+ (conceptos === 1 ? ' concepto' : ' conceptos') + ' · ' + formatear(total);

		var detalles = item.closest('details.documentate-proveedor');
		var destino = detalles ? detalles.querySelector('summary') : null;
		if (!destino) {
			destino = item.querySelector('.documentate-proveedor-resumen');
		}
		if (!destino) {
			destino = document.createElement('div');
			destino.className = 'documentate-proveedor-resumen';
			var barra = item.querySelector('.documentate-array-item-toolbar');
			item.insertBefore(destino, barra ? barra.nextSibling : item.firstChild);
		}
		destino.textContent = texto;
	}

	// Provider: bruto = Σ row totals; total = bruto + igic − irpf. Null when
	// the card carries no concepto row at all (not a provider repeater).
	function calcularProveedor(item, slug, indice) {
		var filas = item.querySelectorAll('.documentate-subarray-item');
		var bruto = 0;
		var conceptos = 0;
		var aplicable = false;
		for (var i = 0; i < filas.length; i++) {
			var fila = calcularFila(filas[i]);
			if (fila === null) {
				continue;
			}
			aplicable = true;
			if (!fila.vacia) {
				conceptos++;
				bruto += fila.total;
			}
		}
		if (!aplicable) {
			return null;
		}

		var igic = control(item, 'igic', false);
		var irpf = control(item, 'irpf', false);
		var vacio = conceptos === 0;
		bruto = redondear(bruto);
		var total = vacio ? 0 : redondear(bruto + numero(igic && igic.value) - numero(irpf && irpf.value));
		var campoBruto = control(item, 'bruto', false);
		var campoTotal = control(item, 'total', false);

		// With no concepto to add up there is nothing to own: the amounts
		// stay as the user left them, and editable.
		if (vacio) {
			liberar(campoBruto);
			liberar(campoTotal);
		} else {
			fijar(campoBruto, bruto);
			fijar(campoTotal, total);
		}
		resumenProveedor(item, slug, indice, conceptos, total);

		return { total: total, conceptos: conceptos };
	}

	// Summary card (Servicios (n) / Suministros (n) / … / Total de la
	// propuesta) and the grand total written to gasto_numero.
	function resumenGeneral(totales) {
		var gasto = document.querySelector('[name="documentate_field_gasto_numero"]');
		var contenedor = document.querySelector('.dcta-resumen, [data-documentate-resumen]');
		if (!contenedor && gasto) {
			contenedor = document.createElement('div');
			contenedor.className = 'documentate-resumen dcta-resumen';
			gasto.parentNode.insertBefore(contenedor, gasto);
		}

		var suma = 0;
		var proveedores = 0;
		var html = '<dl>';
		totales.forEach(function (fila) {
			suma += fila.total;
			proveedores += fila.n;
			html += '<dt>' + escapar(capitalizar(fila.slug)) + ' (' + fila.n + ')</dt><dd>' + escapar(formatear(fila.total)) + '</dd>';
		});
		suma = redondear(suma);
		// Nothing itemised yet: a "Total de la propuesta 0,00 €" above a
		// figure typed by hand reads as a broken calculation, so the card
		// says what is actually going on.
		html += proveedores > 0
			? '<dt class="documentate-resumen-total">Total de la propuesta</dt>'
				+ '<dd class="documentate-resumen-total">' + escapar(formatear(suma)) + '</dd>'
			: '<dt class="documentate-resumen-total">Total de la propuesta</dt>'
				+ '<dd class="documentate-resumen-total">Sin proveedores todavía</dd>';
		html += '</dl>';

		if (contenedor) {
			contenedor.innerHTML = html;
		}
		if (gasto) {
			if (proveedores > 0) {
				fijar(gasto, suma);
			} else {
				// Nothing itemised: gestión may still type the total by hand.
				liberar(gasto);
			}
		}
	}

	function recalcular() {
		var totales = [];
		var repetidores = document.querySelectorAll('.documentate-array-field[data-array-field]');
		for (var r = 0; r < repetidores.length; r++) {
			var slug = repetidores[r].getAttribute('data-array-field');
			var caja = repetidores[r].querySelector('.documentate-array-items');
			if (!caja) {
				continue;
			}
			var suma = 0;
			var proveedores = 0;
			var aplicable = false;
			var indice = 0;
			// Cards may be wrapped (e.g. in <details>), so they are not
			// necessarily direct children of the items box.
			var items = caja.querySelectorAll('.documentate-array-item');
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				if (item.closest('.documentate-array-items') !== caja) {
					continue;
				}
				indice++;
				var proveedor = calcularProveedor(item, slug, indice);
				if (proveedor === null) {
					continue;
				}
				aplicable = true;
				if (proveedor.conceptos > 0) {
					proveedores++;
					suma += proveedor.total;
				}
			}
			if (aplicable) {
				totales.push({ slug: slug, n: proveedores, total: redondear(suma) });
			}
		}
		if (totales.length) {
			resumenGeneral(totales);
		}
		return totales;
	}

	function alCambiar(evento) {
		var objetivo = evento.target;
		if (objetivo && objetivo.closest && objetivo.closest('.documentate-array-field')) {
			recalcular();
		}
	}

	// Rows are added, removed or reordered by documentate-annexes.js, whose
	// handlers sit below `document` and have already run when this fires.
	function alPulsar(evento) {
		var objetivo = evento.target;
		if (objetivo && objetivo.closest && objetivo.closest(SELECTOR_BOTONES)) {
			recalcular();
		}
	}

	function init() {
		document.addEventListener('input', alCambiar);
		document.addEventListener('change', alCambiar);
		document.addEventListener('click', alPulsar);
		document.addEventListener('drop', recalcular);
		recalcular();
	}

	window.documentateCalculos = {
		recalcular: recalcular,
		numero: numero,
		formatear: formatear
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
