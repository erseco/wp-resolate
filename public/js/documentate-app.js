/**
 * Progressive enhancement of the Documentate application.
 *
 * Three small jobs, all optional: the confirmation and reason dialogs of the
 * workflow buttons, the drag-and-drop area of the document file and the hint
 * under the document type of the "new document" form. Without JavaScript the
 * buttons submit their form as they are, the plain file input takes the file
 * and the reason travels in the inline <details> fallback, so the application
 * keeps working; when the dialogs are available the fallback is disabled so
 * exactly one documentate_app_motivo is ever posted.
 *
 * Plain DOM, no jQuery.
 */
(function () {
	'use strict';

	var MAX_BYTES = 20971520;
	var EXTENSIONS = ['pdf', 'odt', 'docx'];
	var FILE_ERROR = 'No se pudo subir el fichero: solo PDF, ODT o DOCX de hasta 20 MB.';
	var FORM_ID = 'dcta-app-form';

	function byId(id) {
		return document.getElementById(id);
	}

	function each(selector, callback, root) {
		var nodes = (root || document).querySelectorAll(selector);
		Array.prototype.forEach.call(nodes, callback);
	}

	function mark(element) {
		if (!element || element.getAttribute('data-dcta-listo')) {
			return false;
		}
		element.setAttribute('data-dcta-listo', '1');
		return true;
	}

	function text(element) {
		return String(element.textContent || '').trim();
	}

	// --- dialogs -------------------------------------------------------------

	function hasDialogs(dialog) {
		return !!dialog && typeof dialog.showModal === 'function';
	}

	function disableFallback() {
		each('.dcta-motivo-fallback', function (details) {
			details.hidden = true;
			each('textarea, input, button', function (control) {
				control.disabled = true;
			}, details);
		});
	}

	function disable(dialog) {
		each('textarea, input', function (control) {
			control.disabled = true;
		}, dialog);
		var targets = dialog.querySelector('.dcta-dialogo-destinos');
		if (targets) {
			targets.hidden = true;
		}
	}

	function close(dialog) {
		if (!dialog) {
			return;
		}
		disable(dialog);
		if (typeof dialog.close === 'function') {
			dialog.close();
		}
	}

	function openReasonDialog(button, dialog) {
		var withTargets = button.hasAttribute('data-destinos');
		var title = dialog.querySelector('.dcta-dialogo-titulo');
		var targets = dialog.querySelector('.dcta-dialogo-destinos');
		var textarea = byId('dcta-dialogo-motivo-texto');
		var key = byId('dcta-dialogo-motivo-clave');

		if (title) {
			title.textContent = withTargets ? 'Devolver el documento' : text(button);
		}
		if (targets) {
			targets.hidden = !withTargets;
			each('input[type="radio"]', function (radio) {
				radio.disabled = !withTargets;
			}, targets);
		}
		if (textarea) {
			textarea.disabled = false;
			textarea.value = '';
		}
		if (key) {
			key.disabled = withTargets;
			key.value = button.value;
		}

		dialog.showModal();
		if (textarea && typeof textarea.focus === 'function') {
			textarea.focus();
		}
	}

	function formIsValid() {
		var form = byId(FORM_ID);

		return !form || typeof form.reportValidity !== 'function' || form.reportValidity();
	}

	function openConfirmDialog(button, dialog, message) {
		var title = dialog.querySelector('.dcta-dialogo-titulo');
		var paragraph = byId('dcta-dialogo-confirmar-texto');
		var accept = byId('dcta-dialogo-confirmar-ok');
		var key = byId('dcta-dialogo-confirmar-clave');

		if (title) {
			title.textContent = text(button);
		}
		if (paragraph) {
			paragraph.textContent = message;
		}
		if (accept) {
			accept.textContent = text(button);
		}
		if (key) {
			key.disabled = false;
			key.value = button.value;
		}

		dialog.showModal();
	}

	function initDialogs() {
		var reasonDialog = byId('dcta-dialogo-motivo');
		var confirmDialog = byId('dcta-dialogo-confirmar');
		if (!hasDialogs(reasonDialog) || !hasDialogs(confirmDialog)) {
			return;
		}

		disableFallback();

		each('[data-dcta-cerrar]', function (button) {
			if (mark(button)) {
				button.addEventListener('click', function () {
					close(button.closest('dialog'));
				});
			}
		});

		// A return must not be blocked by fields the reviewer is not filling in.
		var reasonAccept = byId('dcta-dialogo-motivo-ok');
		if (reasonAccept && mark(reasonAccept)) {
			reasonAccept.addEventListener('click', function () {
				var form = byId(FORM_ID);
				if (form) {
					form.noValidate = true;
				}
			});
		}

		each('button[data-motivo], button[data-confirmar]', function (button) {
			if (!mark(button)) {
				return;
			}
			button.addEventListener('click', function (event) {
				if (button.hasAttribute('data-motivo')) {
					event.preventDefault();
					openReasonDialog(button, reasonDialog);
					return;
				}
				var message = button.getAttribute('data-confirmar') || '';
				if ('' === message) {
					return;
				}
				event.preventDefault();
				// While a modal is open everything outside it is inert, so the
				// browser could not point at an invalid field: ask first.
				if (!formIsValid()) {
					return;
				}
				openConfirmDialog(button, confirmDialog, message);
			});
		});

		[reasonDialog, confirmDialog].forEach(function (dialog) {
			if (mark(dialog)) {
				dialog.addEventListener('close', function () {
					disable(dialog);
				});
			}
		});
	}

	// --- document file -------------------------------------------------------

	function extension(name) {
		var parts = String(name || '').split('.');
		return parts.length > 1 ? parts.pop().toLowerCase() : '';
	}

	function isAcceptable(zone, file) {
		var valid = EXTENSIONS.indexOf(extension(file.name)) !== -1 && file.size <= MAX_BYTES;
		var error = zone.querySelector('.dcta-dropzone-error');
		if (error) {
			error.textContent = valid ? '' : FILE_ERROR;
			error.hidden = valid;
		}
		return valid;
	}

	function showChosen(zone, file) {
		var row = zone.querySelector('.dcta-dropzone-elegido');
		if (!row) {
			return;
		}
		row.textContent = file ? file.name + ' · se subirá al guardar' : '';
		row.hidden = !file;
	}

	function initDropZone(zone, input) {
		['dragenter', 'dragover'].forEach(function (event) {
			zone.addEventListener(event, function (e) {
				e.preventDefault();
				zone.classList.add('dcta-dropzone-on');
			});
		});
		['dragleave', 'drop'].forEach(function (event) {
			zone.addEventListener(event, function (e) {
				e.preventDefault();
				zone.classList.remove('dcta-dropzone-on');
			});
		});
		zone.addEventListener('drop', function (e) {
			var files = e.dataTransfer && e.dataTransfer.files;
			if (!files || !files.length || !isAcceptable(zone, files[0])) {
				return;
			}
			try {
				input.files = files;
				showChosen(zone, files[0]);
			} catch (error) {
				// Browsers that refuse the assignment keep the plain input,
				// so nothing is queued and the line must not say otherwise.
				showChosen(zone, null);
			}
		});
	}

	function initAttachment() {
		each('[data-dcta-dropzone]', function (zone) {
			var input = byId('documentate-app-adjunto');
			if (!input || !mark(zone)) {
				return;
			}

			zone.hidden = false;
			input.classList.add('dcta-oculto-visual');

			var choose = zone.querySelector('[data-dcta-elegir]');
			if (choose) {
				choose.addEventListener('click', function () {
					input.click();
				});
			}

			input.addEventListener('change', function () {
				if (!input.files || !input.files.length) {
					showChosen(zone, null);
					return;
				}
				var file = input.files[0];
				if (!isAcceptable(zone, file)) {
					input.value = '';
					showChosen(zone, null);
					return;
				}
				showChosen(zone, file);
			});

			initDropZone(zone, input);
		});
	}

	// --- new document --------------------------------------------------------

	function initTypeHint() {
		var select = byId('documentate-app-tipo');
		if (!select || !mark(select)) {
			return;
		}

		var note = byId('documentate-app-tipo-nota');
		var prefix = byId('documentate-app-prefijo');

		function paint() {
			var option = select.options[select.selectedIndex];
			var value = option ? option.value : '';
			var management = option ? option.getAttribute('data-gestion') : '';
			var prefixMark = option ? option.getAttribute('data-prefijo') : '';

			if (note) {
				note.textContent = '' === value
					? ''
					: (management ? 'Pasa por gestión documental.' : 'Va directo a administración.');
			}
			if (prefix) {
				prefix.textContent = prefixMark || '';
				prefix.hidden = !prefixMark;
			}
		}

		select.addEventListener('change', paint);
		paint();
	}

	/**
	 * Strip accents and case so «gestión» also matches «gestion».
	 */
	function normalize(text) {
		var plain = String(text || '').toLowerCase();

		return plain.normalize ? plain.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : plain;
	}

	/**
	 * The quick filter: hides the rows that do not match what is typed.
	 *
	 * It is an enhancement on top of the status chips, which do the real
	 * query; this one only narrows what is already on screen, so the box
	 * stays hidden when this script does not run. The tray is capped at one
	 * page, so when it holds more the counts say how many rows were looked
	 * at and keep the warning the server footer carries.
	 */
	function initSearch() {
		var box = document.querySelector('[data-dcta-busqueda]');
		var table = document.querySelector('.dcta-tabla');
		if (!box || !table || !mark(box)) {
			return;
		}

		var field = box.querySelector('.dcta-busqueda-campo');
		var footer = table.querySelector('[data-dcta-pie]');
		var rows = [];
		each('.dcta-fila:not(.dcta-fila-cab)', function (row) {
			rows.push(row);
		}, table);

		if (!field || !rows.length) {
			return;
		}

		var total = rows.length;
		var found = footer ? (parseInt(footer.getAttribute('data-dcta-pie-total'), 10) || total) : total;
		var truncated = found > total;
		var originalFooter = footer ? footer.textContent : '';
		var emptyRow = document.createElement('div');
		emptyRow.className = 'dcta-vacio';
		emptyRow.hidden = true;
		emptyRow.textContent = truncated
			? 'Ningún documento de los ' + total + ' que hay en pantalla coincide con el filtro · la bandeja tiene ' + found + ', afina con los filtros.'
			: 'Ningún documento de la lista coincide con el filtro.';
		if (footer) {
			table.insertBefore(emptyRow, footer);
		} else {
			table.appendChild(emptyRow);
		}

		box.hidden = false;

		function filter() {
			var search = normalize(field.value).trim();
			var visible = 0;

			for (var i = 0; i < rows.length; i++) {
				var text = normalize(rows[i].getAttribute('data-dcta-texto') || rows[i].textContent);
				var matches = '' === search || text.indexOf(search) !== -1;
				rows[i].hidden = !matches;
				if (matches) {
					visible++;
				}
			}

			emptyRow.hidden = 0 !== visible;

			if (footer) {
				footer.textContent = '' === search
					? originalFooter
					: visible + ' de ' + total + (1 === total ? ' documento' : ' documentos')
						+ (truncated ? ' mostrados de ' + found + ' · afina con los filtros' : '');
			}
		}

		field.addEventListener('input', filter);
		field.addEventListener('search', filter);
		filter();
	}

	function init() {
		initDialogs();
		initAttachment();
		initTypeHint();
		initSearch();
	}

	window.documentateApp = { init: init, initSearch: initSearch };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
