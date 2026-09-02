/**
 * Progressive enhancement of the Documentate application.
 *
 * Three small jobs, all optional: the confirmation and "motivo" dialogs of the
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
	var EXTENSIONES = ['pdf', 'odt', 'docx'];
	var ERROR_FICHERO = 'No se pudo subir el fichero: solo PDF, ODT o DOCX de hasta 20 MB.';
	var ID_FORM = 'dcta-app-form';

	function porId(id) {
		return document.getElementById(id);
	}

	function cada(selector, callback, raiz) {
		var nodos = (raiz || document).querySelectorAll(selector);
		Array.prototype.forEach.call(nodos, callback);
	}

	function marcar(elemento) {
		if (!elemento || elemento.getAttribute('data-dcta-listo')) {
			return false;
		}
		elemento.setAttribute('data-dcta-listo', '1');
		return true;
	}

	function texto(elemento) {
		return String(elemento.textContent || '').trim();
	}

	// --- dialogs -------------------------------------------------------------

	function hayDialogos(dialogo) {
		return !!dialogo && typeof dialogo.showModal === 'function';
	}

	function desactivarFallback() {
		cada('.dcta-motivo-fallback', function (detalle) {
			detalle.hidden = true;
			cada('textarea, input, button', function (control) {
				control.disabled = true;
			}, detalle);
		});
	}

	function desactivar(dialogo) {
		cada('textarea, input', function (control) {
			control.disabled = true;
		}, dialogo);
		var destinos = dialogo.querySelector('.dcta-dialogo-destinos');
		if (destinos) {
			destinos.hidden = true;
		}
	}

	function cerrar(dialogo) {
		if (!dialogo) {
			return;
		}
		desactivar(dialogo);
		if (typeof dialogo.close === 'function') {
			dialogo.close();
		}
	}

	function abrirMotivo(boton, dialogo) {
		var conDestinos = boton.hasAttribute('data-destinos');
		var titulo = dialogo.querySelector('.dcta-dialogo-titulo');
		var destinos = dialogo.querySelector('.dcta-dialogo-destinos');
		var area = porId('dcta-dialogo-motivo-texto');
		var clave = porId('dcta-dialogo-motivo-clave');

		if (titulo) {
			titulo.textContent = conDestinos ? 'Devolver el documento' : texto(boton);
		}
		if (destinos) {
			destinos.hidden = !conDestinos;
			cada('input[type="radio"]', function (radio) {
				radio.disabled = !conDestinos;
			}, destinos);
		}
		if (area) {
			area.disabled = false;
			area.value = '';
		}
		if (clave) {
			clave.disabled = conDestinos;
			clave.value = boton.value;
		}

		dialogo.showModal();
		if (area && typeof area.focus === 'function') {
			area.focus();
		}
	}

	function formularioValido() {
		var form = porId(ID_FORM);

		return !form || typeof form.reportValidity !== 'function' || form.reportValidity();
	}

	function abrirConfirmar(boton, dialogo, mensaje) {
		var titulo = dialogo.querySelector('.dcta-dialogo-titulo');
		var parrafo = porId('dcta-dialogo-confirmar-texto');
		var aceptar = porId('dcta-dialogo-confirmar-ok');
		var clave = porId('dcta-dialogo-confirmar-clave');

		if (titulo) {
			titulo.textContent = texto(boton);
		}
		if (parrafo) {
			parrafo.textContent = mensaje;
		}
		if (aceptar) {
			aceptar.textContent = texto(boton);
		}
		if (clave) {
			clave.disabled = false;
			clave.value = boton.value;
		}

		dialogo.showModal();
	}

	function iniciarDialogos() {
		var motivo = porId('dcta-dialogo-motivo');
		var confirmar = porId('dcta-dialogo-confirmar');
		if (!hayDialogos(motivo) || !hayDialogos(confirmar)) {
			return;
		}

		desactivarFallback();

		cada('[data-dcta-cerrar]', function (boton) {
			if (marcar(boton)) {
				boton.addEventListener('click', function () {
					cerrar(boton.closest('dialog'));
				});
			}
		});

		// A return must not be blocked by fields the reviewer is not filling in.
		var aceptarMotivo = porId('dcta-dialogo-motivo-ok');
		if (aceptarMotivo && marcar(aceptarMotivo)) {
			aceptarMotivo.addEventListener('click', function () {
				var form = porId(ID_FORM);
				if (form) {
					form.noValidate = true;
				}
			});
		}

		cada('button[data-motivo], button[data-confirmar]', function (boton) {
			if (!marcar(boton)) {
				return;
			}
			boton.addEventListener('click', function (evento) {
				if (boton.hasAttribute('data-motivo')) {
					evento.preventDefault();
					abrirMotivo(boton, motivo);
					return;
				}
				var mensaje = boton.getAttribute('data-confirmar') || '';
				if ('' === mensaje) {
					return;
				}
				evento.preventDefault();
				// While a modal is open everything outside it is inert, so the
				// browser could not point at an invalid field: ask first.
				if (!formularioValido()) {
					return;
				}
				abrirConfirmar(boton, confirmar, mensaje);
			});
		});

		[motivo, confirmar].forEach(function (dialogo) {
			if (marcar(dialogo)) {
				dialogo.addEventListener('close', function () {
					desactivar(dialogo);
				});
			}
		});
	}

	// --- document file -------------------------------------------------------

	function extension(nombre) {
		var partes = String(nombre || '').split('.');
		return partes.length > 1 ? partes.pop().toLowerCase() : '';
	}

	function aceptable(zona, fichero) {
		var valido = EXTENSIONES.indexOf(extension(fichero.name)) !== -1 && fichero.size <= MAX_BYTES;
		var error = zona.querySelector('.dcta-dropzone-error');
		if (error) {
			error.textContent = valido ? '' : ERROR_FICHERO;
			error.hidden = valido;
		}
		return valido;
	}

	function mostrarElegido(zona, fichero) {
		var fila = zona.querySelector('.dcta-dropzone-elegido');
		if (!fila) {
			return;
		}
		fila.textContent = fichero ? fichero.name + ' · se subirá al guardar' : '';
		fila.hidden = !fichero;
	}

	function iniciarSoltar(zona, entrada) {
		['dragenter', 'dragover'].forEach(function (evento) {
			zona.addEventListener(evento, function (e) {
				e.preventDefault();
				zona.classList.add('dcta-dropzone-on');
			});
		});
		['dragleave', 'drop'].forEach(function (evento) {
			zona.addEventListener(evento, function (e) {
				e.preventDefault();
				zona.classList.remove('dcta-dropzone-on');
			});
		});
		zona.addEventListener('drop', function (e) {
			var ficheros = e.dataTransfer && e.dataTransfer.files;
			if (!ficheros || !ficheros.length || !aceptable(zona, ficheros[0])) {
				return;
			}
			try {
				entrada.files = ficheros;
			} catch (error) {
				// Browsers that refuse the assignment keep the plain input.
			}
			mostrarElegido(zona, ficheros[0]);
		});
	}

	function iniciarAdjunto() {
		cada('[data-dcta-dropzone]', function (zona) {
			var entrada = porId('documentate-app-adjunto');
			if (!entrada || !marcar(zona)) {
				return;
			}

			zona.hidden = false;
			entrada.classList.add('dcta-oculto-visual');

			var elegir = zona.querySelector('[data-dcta-elegir]');
			if (elegir) {
				elegir.addEventListener('click', function () {
					entrada.click();
				});
			}

			entrada.addEventListener('change', function () {
				if (!entrada.files || !entrada.files.length) {
					mostrarElegido(zona, null);
					return;
				}
				var fichero = entrada.files[0];
				if (!aceptable(zona, fichero)) {
					entrada.value = '';
					mostrarElegido(zona, null);
					return;
				}
				mostrarElegido(zona, fichero);
			});

			iniciarSoltar(zona, entrada);
		});
	}

	// --- new document --------------------------------------------------------

	function iniciarTipo() {
		var select = porId('documentate-app-tipo');
		if (!select || !marcar(select)) {
			return;
		}

		var nota = porId('documentate-app-tipo-nota');
		var prefijo = porId('documentate-app-prefijo');

		function pintar() {
			var opcion = select.options[select.selectedIndex];
			var valor = opcion ? opcion.value : '';
			var gestion = opcion ? opcion.getAttribute('data-gestion') : '';
			var marca = opcion ? opcion.getAttribute('data-prefijo') : '';

			if (nota) {
				nota.textContent = '' === valor
					? ''
					: (gestion ? 'Pasa por gestión documental.' : 'Va directo a administración.');
			}
			if (prefijo) {
				prefijo.textContent = marca || '';
				prefijo.hidden = !marca;
			}
		}

		select.addEventListener('change', pintar);
		pintar();
	}

	function init() {
		iniciarDialogos();
		iniciarAdjunto();
		iniciarTipo();
	}

	window.documentateApp = { init: init };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
