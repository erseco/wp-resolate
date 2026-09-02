/**
 * Tests for the progressive enhancement of the Documentate application.
 *
 * The module works on the markup Documentate_App_Shell and the edit view
 * render: the two dialogs after the footer, the transition buttons of the
 * rail, the inline <details> fallback, the file dropzone and the type select
 * of the "new document" form.
 */
const DIALOGS = `
	<dialog class="dcta-dialogo" id="dcta-dialogo-motivo">
		<h2 class="dcta-dialogo-titulo">Devolver el documento</h2>
		<div class="dcta-dialogo-destinos" hidden>
			<label><input type="radio" name="documentate_app_transicion" value="devolver_gestion" form="dcta-app-form" checked disabled></label>
			<label><input type="radio" name="documentate_app_transicion" value="devolver_area" form="dcta-app-form" disabled></label>
		</div>
		<textarea id="dcta-dialogo-motivo-texto" name="documentate_app_motivo" form="dcta-app-form" disabled></textarea>
		<input type="hidden" id="dcta-dialogo-motivo-clave" name="documentate_app_transicion" value="" form="dcta-app-form" disabled>
		<div class="dcta-dialogo-pie">
			<button type="button" data-dcta-cerrar="1">Cancelar</button>
			<button type="submit" id="dcta-dialogo-motivo-ok" form="dcta-app-form">Devolver</button>
		</div>
	</dialog>
	<dialog class="dcta-dialogo" id="dcta-dialogo-confirmar">
		<h2 class="dcta-dialogo-titulo">Confirmar</h2>
		<p class="dcta-dialogo-texto" id="dcta-dialogo-confirmar-texto"></p>
		<input type="hidden" id="dcta-dialogo-confirmar-clave" name="documentate_app_transicion" value="" form="dcta-app-form" disabled>
		<div class="dcta-dialogo-pie">
			<button type="button" data-dcta-cerrar="1">Cancelar</button>
			<button type="submit" id="dcta-dialogo-confirmar-ok" form="dcta-app-form">Confirmar</button>
		</div>
	</dialog>`;

const FALLBACK = `
	<details class="dcta-motivo-fallback">
		<summary>Motivo de la devolución</summary>
		<textarea id="dcta-motivo-fallback-texto" name="documentate_app_motivo"></textarea>
		<button type="submit" name="documentate_app_transicion" value="devolver_gestion">Devolver a gestión</button>
	</details>`;

const ATTACHMENT = `
	<div class="dcta-dropzone" data-dcta-dropzone="1" hidden>
		<p class="dcta-dropzone-texto">Arrastra aquí el fichero del documento</p>
		<button type="button" data-dcta-elegir="1">Elegir fichero</button>
		<p class="dcta-dropzone-elegido" hidden></p>
		<p class="dcta-dropzone-error" hidden></p>
	</div>
	<input type="file" id="documentate-app-adjunto" name="documentate_app_adjunto" accept=".pdf,.odt,.docx">`;

/**
 * Markup of the edit view: the form with its rail, plus the dialogs.
 *
 * @param {string} buttons Transition buttons of the rail.
 * @return {string} HTML.
 */
function editor( buttons ) {
	return `
		<form class="dcta-editor" id="dcta-app-form" method="post">
			<input type="text" name="documentate_app_nombre" required>
			${ ATTACHMENT }
			<div class="dcta-editor-acciones">
				<button type="submit" name="documentate_app_estado" value="guardar" formnovalidate>Guardar</button>
				${ buttons }
				${ FALLBACK }
			</div>
		</form>
		${ DIALOGS }`;
}

const NEW_DOCUMENT = `
	<form class="dcta-form" method="post">
		<select id="documentate-app-tipo" name="documentate_app_tipo">
			<option value="">Elige un tipo…</option>
			<option value="7" data-prefijo="RES" data-gestion="1">Resolución</option>
			<option value="9" data-prefijo="CONV" data-gestion="">Convocatoria</option>
		</select>
		<p class="dcta-ayuda" id="documentate-app-tipo-nota"></p>
		<span class="dcta-prefijo" id="documentate-app-prefijo" hidden></span>
		<input type="text" id="documentate-app-nombre" name="documentate_app_nombre">
	</form>`;

/**
 * Give every dialog of the document the two methods the module needs.
 *
 * jsdom does not implement the top layer, so showModal() and close() are
 * stubbed on the prototype when the class exists and on the elements when it
 * does not.
 */
function stubDialogs() {
	function open() {
		this.open = true;
	}
	function close() {
		this.open = false;
		this.dispatchEvent( new window.Event( 'close' ) );
	}

	if ( window.HTMLDialogElement ) {
		window.HTMLDialogElement.prototype.showModal = open;
		window.HTMLDialogElement.prototype.close = close;
	}

	document.querySelectorAll( 'dialog' ).forEach( ( dialog ) => {
		if ( 'function' !== typeof dialog.showModal ) {
			dialog.showModal = open;
			dialog.close = close;
		}
	} );
}

/**
 * Evaluate the module against the current DOM.
 */
function load() {
	// isolateModules re-evaluates the file on every call, the way the browser
	// runs it on a fresh page — and unlike new Function( source ) it goes
	// through the module registry, so the coverage report sees it.
	jest.isolateModules( () => {
		require( '../../public/js/documentate-app.js' );
	} );
}

/**
 * Build the edit view and run the module on it.
 *
 * @param {string} buttons Transition buttons of the rail.
 */
function mountEditor( buttons ) {
	document.body.innerHTML = editor( buttons );
	stubDialogs();
	load();
}

/**
 * Pretend a file was chosen in the file input.
 *
 * @param {string} name File name.
 * @param {number} size   File size in bytes.
 */
function chooseFile( name, size ) {
	const input = document.getElementById( 'documentate-app-adjunto' );
	Object.defineProperty( input, 'files', {
		value: [ { name: name, size } ],
		writable: true,
		configurable: true,
	} );
	input.dispatchEvent( new window.Event( 'change' ) );
}

describe( 'return dialog', () => {
	it( 'opens with the key of the button and enables only its own reason box', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="devolver_area" data-motivo="1">Devolver al área</button>'
		);

		const button = document.querySelector( '[data-motivo]' );
		button.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		const dialog = document.getElementById( 'dcta-dialogo-motivo' );
		expect( dialog.open ).toBe( true );
		expect( dialog.querySelector( '.dcta-dialogo-titulo' ).textContent ).toBe( 'Devolver al área' );
		expect( document.getElementById( 'dcta-dialogo-motivo-texto' ).disabled ).toBe( false );
		expect( document.getElementById( 'dcta-dialogo-motivo-clave' ).disabled ).toBe( false );
		expect( document.getElementById( 'dcta-dialogo-motivo-clave' ).value ).toBe( 'devolver_area' );
		expect( dialog.querySelector( '.dcta-dialogo-destinos' ).hidden ).toBe( true );
	} );

	it( 'disables the no-JavaScript fallback so only one reason is posted', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="devolver_area" data-motivo="1">Devolver al área</button>'
		);

		const details = document.querySelector( '.dcta-motivo-fallback' );
		expect( details.hidden ).toBe( true );
		expect( document.getElementById( 'dcta-motivo-fallback-texto' ).disabled ).toBe( true );
		expect( details.querySelector( 'button' ).disabled ).toBe( true );
	} );

	it( 'shows the destinations when the document can go back to two places', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="devolver_area" data-motivo="1" data-destinos="1">Devolver…</button>'
		);

		document
			.querySelector( '[data-motivo]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		const targets = document.querySelector( '.dcta-dialogo-destinos' );
		expect( targets.hidden ).toBe( false );
		expect( targets.querySelectorAll( 'input[type="radio"]:disabled' ) ).toHaveLength( 0 );
		// The radios carry the key, so the hidden input must not post one too.
		expect( document.getElementById( 'dcta-dialogo-motivo-clave' ).disabled ).toBe( true );
	} );

	it( 'lets the return through even when required fields are empty', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="devolver_area" data-motivo="1">Devolver al área</button>'
		);

		// Added after the module's own listener, so it runs second: jsdom does
		// not implement form submission and would log an error.
		const accept = document.getElementById( 'dcta-dialogo-motivo-ok' );
		accept.addEventListener( 'click', ( event ) => event.preventDefault() );
		accept.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		expect( document.getElementById( 'dcta-app-form' ).noValidate ).toBe( true );
	} );

	it( 'disables everything again when it is cancelled', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="devolver_area" data-motivo="1">Devolver al área</button>'
		);

		document
			.querySelector( '[data-motivo]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );
		document
			.querySelector( '#dcta-dialogo-motivo [data-dcta-cerrar]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		const dialog = document.getElementById( 'dcta-dialogo-motivo' );
		expect( dialog.open ).toBe( false );
		expect( document.getElementById( 'dcta-dialogo-motivo-texto' ).disabled ).toBe( true );
		expect( document.getElementById( 'dcta-dialogo-motivo-clave' ).disabled ).toBe( true );
	} );
} );

describe( 'confirmation dialog', () => {
	/**
	 * Fill the required fields, as a document ready to be sent has them.
	 */
	function fillRequiredFields() {
		document.querySelector( '[name="documentate_app_nombre"]' ).value = 'Material aulas';
	}

	it( 'asks the question of the button and carries its key', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="enviar_gestion" data-confirmar="¿Enviar el documento a gestión documental?">Enviar a gestión</button>'
		);
		fillRequiredFields();

		document
			.querySelector( '[data-confirmar]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		expect( document.getElementById( 'dcta-dialogo-confirmar' ).open ).toBe( true );
		expect( document.getElementById( 'dcta-dialogo-confirmar-texto' ).textContent ).toBe(
			'¿Enviar el documento a gestión documental?'
		);
		expect( document.getElementById( 'dcta-dialogo-confirmar-ok' ).textContent ).toBe( 'Enviar a gestión' );
		expect( document.getElementById( 'dcta-dialogo-confirmar-clave' ).value ).toBe( 'enviar_gestion' );
		expect( document.getElementById( 'dcta-dialogo-confirmar-clave' ).disabled ).toBe( false );
	} );

	it( 'submits straight away when the transition has nothing to confirm', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="pasar_admin" data-confirmar="">Pasar a administración</button>'
		);

		const button = document.querySelector( '[data-confirmar]' );
		const event = new window.MouseEvent( 'click', { bubbles: true, cancelable: true } );
		button.dispatchEvent( event );

		expect( event.defaultPrevented ).toBe( false );
		expect( document.getElementById( 'dcta-dialogo-confirmar' ).open ).toBeFalsy();
	} );

	it( 'lets the browser point at an invalid field instead of opening', () => {
		mountEditor(
			'<button type="submit" name="documentate_app_transicion" value="enviar_gestion" data-confirmar="¿Enviar el documento a gestión documental?">Enviar a gestión</button>'
		);

		// The required internal name is empty: nothing outside a modal dialog
		// can be focused, so the question must not be asked yet.
		const form = document.getElementById( 'dcta-app-form' );
		form.reportValidity = jest.fn().mockReturnValue( false );

		document
			.querySelector( '[data-confirmar]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		expect( form.reportValidity ).toHaveBeenCalled();
		expect( document.getElementById( 'dcta-dialogo-confirmar' ).open ).toBeFalsy();
	} );
} );

describe( 'document file', () => {
	beforeEach( () => {
		mountEditor( '' );
	} );

	it( 'reveals the dropzone and hides the plain input', () => {
		expect( document.querySelector( '.dcta-dropzone' ).hidden ).toBe( false );
		expect(
			document.getElementById( 'documentate-app-adjunto' ).classList.contains( 'dcta-oculto-visual' )
		).toBe( true );
	} );

	it( 'opens the file picker from the button', () => {
		const input = document.getElementById( 'documentate-app-adjunto' );
		input.click = jest.fn();

		document
			.querySelector( '[data-dcta-elegir]' )
			.dispatchEvent( new window.MouseEvent( 'click', { bubbles: true, cancelable: true } ) );

		expect( input.click ).toHaveBeenCalled();
	} );

	it( 'announces the chosen file', () => {
		chooseFile( 'resolucion.pdf', 1024 );

		const row = document.querySelector( '.dcta-dropzone-elegido' );
		expect( row.hidden ).toBe( false );
		expect( row.textContent ).toBe( 'resolucion.pdf · se subirá al guardar' );
		expect( document.querySelector( '.dcta-dropzone-error' ).hidden ).toBe( true );
	} );

	it( 'refuses another extension with the same message as the server', () => {
		chooseFile( 'hoja.xlsx', 1024 );

		const error = document.querySelector( '.dcta-dropzone-error' );
		expect( error.hidden ).toBe( false );
		expect( error.textContent ).toBe(
			'No se pudo subir el fichero: solo PDF, ODT o DOCX de hasta 20 MB.'
		);
		expect( document.querySelector( '.dcta-dropzone-elegido' ).hidden ).toBe( true );
	} );

	it( 'refuses a file over the limit', () => {
		chooseFile( 'enorme.pdf', 20971521 );

		expect( document.querySelector( '.dcta-dropzone-error' ).hidden ).toBe( false );
		expect( document.querySelector( '.dcta-dropzone-elegido' ).hidden ).toBe( true );
	} );
} );

/**
 * Drop a file on the zone, with the given behaviour for `input.files`.
 *
 * @param {string}  name  File name.
 * @param {boolean} accepts  Whether the browser lets the assignment through.
 */
function dropFile( name, accepts ) {
	const input = document.getElementById( 'documentate-app-adjunto' );
	const files = [ { name: name, size: 1024 } ];
	Object.defineProperty( input, 'files', {
		configurable: true,
		get: () => ( accepts ? files : [] ),
		set: () => {
			if ( ! accepts ) {
				// Safari and friends refuse the assignment outright.
				throw new TypeError( 'files is read-only' );
			}
		},
	} );

	const event = new window.Event( 'drop', { bubbles: true, cancelable: true } );
	event.dataTransfer = { files: files };
	document.querySelector( '.dcta-dropzone' ).dispatchEvent( event );
}

describe( 'dropped file', () => {
	beforeEach( () => {
		mountEditor( '' );
	} );

	it( 'announces the file the input accepted', () => {
		dropFile( 'resolucion.pdf', true );

		const row = document.querySelector( '.dcta-dropzone-elegido' );
		expect( row.hidden ).toBe( false );
		expect( row.textContent ).toBe( 'resolucion.pdf · se subirá al guardar' );
	} );

	it( 'says nothing when the browser refuses to take the file', () => {
		dropFile( 'resolucion.pdf', false );

		// Nothing is attached to the input, so the plain field is still the
		// only way to send it: promising it would be posted is a lie.
		const row = document.querySelector( '.dcta-dropzone-elegido' );
		expect( row.hidden ).toBe( true );
		expect( row.textContent ).toBe( '' );
	} );
} );

describe( 'new document form', () => {
	beforeEach( () => {
		document.body.innerHTML = NEW_DOCUMENT;
		load();
	} );

	it( 'says nothing until a type is chosen', () => {
		expect( document.getElementById( 'documentate-app-tipo-nota' ).textContent ).toBe( '' );
		expect( document.getElementById( 'documentate-app-prefijo' ).hidden ).toBe( true );
	} );

	it( 'explains where each type goes and shows its prefix', () => {
		const select = document.getElementById( 'documentate-app-tipo' );

		select.value = '7';
		select.dispatchEvent( new window.Event( 'change' ) );
		expect( document.getElementById( 'documentate-app-tipo-nota' ).textContent ).toBe(
			'Pasa por gestión documental.'
		);
		expect( document.getElementById( 'documentate-app-prefijo' ).textContent ).toBe( 'RES' );
		expect( document.getElementById( 'documentate-app-prefijo' ).hidden ).toBe( false );

		select.value = '9';
		select.dispatchEvent( new window.Event( 'change' ) );
		expect( document.getElementById( 'documentate-app-tipo-nota' ).textContent ).toBe(
			'Va directo a administración.'
		);
		expect( document.getElementById( 'documentate-app-prefijo' ).textContent ).toBe( 'CONV' );
	} );
} );

const LIST = `
	<div class="dcta-filtros">
		<a class="dcta-fchip dcta-fchip-on" href="#">Todos</a>
		<span class="dcta-busqueda" data-dcta-busqueda hidden>
			<label class="screen-reader-text" for="dcta-busqueda">Filtrar los documentos de la lista</label>
			<input type="search" id="dcta-busqueda" class="dcta-busqueda-campo" placeholder="Filtrar…">
		</span>
	</div>
	<div class="dcta-tabla">
		<div class="dcta-fila dcta-fila-cab"><span>Documento</span></div>
		<div class="dcta-fila" data-dcta-texto="PG · Material aulas digitales Propuesta de gasto Borrador"></div>
		<div class="dcta-fila" data-dcta-texto="RES · Listado definitivo Resolución En gestión"></div>
		<div class="dcta-fila" data-dcta-texto="CONV · Jornadas competencia digital Convocatoria Borrador"></div>
		<div class="dcta-tabla-pie" data-dcta-pie data-dcta-pie-total="3">3 documentos</div>
	</div>`;

/**
 * Build a tray and run the module on it.
 */
function mountList() {
	document.body.innerHTML = LIST;
	load();
}

/**
 * Type into the quick filter.
 *
 * @param {string} text What the person types.
 */
function typeInFilter( text ) {
	const field = document.getElementById( 'dcta-busqueda' );
	field.value = text;
	field.dispatchEvent( new window.Event( 'input' ) );
}

/**
 * The rows still on screen.
 *
 * @return {string[]} Their searchable text.
 */
function visibleRows() {
	return Array.from(
		document.querySelectorAll( '.dcta-fila:not(.dcta-fila-cab)' )
	)
		.filter( ( row ) => ! row.hidden )
		.map( ( row ) => row.getAttribute( 'data-dcta-texto' ) );
}

describe( 'quick filter', () => {
	it( 'shows the box only when the script runs', () => {
		document.body.innerHTML = LIST;
		expect( document.querySelector( '[data-dcta-busqueda]' ).hidden ).toBe( true );

		load();
		expect( document.querySelector( '[data-dcta-busqueda]' ).hidden ).toBe( false );
	} );

	it( 'narrows the rows as you type and counts what is left', () => {
		mountList();

		typeInFilter( 'jornadas' );
		expect( visibleRows() ).toEqual( [
			'CONV · Jornadas competencia digital Convocatoria Borrador',
		] );
		expect( document.querySelector( '[data-dcta-pie]' ).textContent ).toBe(
			'1 de 3 documentos'
		);
	} );

	it( 'matches the type and the status too, ignoring accents', () => {
		mountList();

		typeInFilter( 'gestion' );
		expect( visibleRows() ).toEqual( [
			'RES · Listado definitivo Resolución En gestión',
		] );

		typeInFilter( 'propuesta' );
		expect( visibleRows() ).toEqual( [
			'PG · Material aulas digitales Propuesta de gasto Borrador',
		] );
	} );

	it( 'says so when nothing matches, and restores the list when cleared', () => {
		mountList();

		typeInFilter( 'nada de nada' );
		expect( visibleRows() ).toEqual( [] );
		expect( document.querySelector( '.dcta-vacio' ).hidden ).toBe( false );
		expect( document.querySelector( '.dcta-vacio' ).textContent ).toBe(
			'Ningún documento de la lista coincide con el filtro.'
		);

		typeInFilter( '' );
		expect( visibleRows() ).toHaveLength( 3 );
		expect( document.querySelector( '.dcta-vacio' ).hidden ).toBe( true );
		expect( document.querySelector( '[data-dcta-pie]' ).textContent ).toBe( '3 documentos' );
	} );

	it( 'does nothing on a page without a tray', () => {
		document.body.innerHTML = '<div class="dcta-hoja"></div>';
		expect( () => load() ).not.toThrow();
	} );

	it( 'keeps the truncation warning of a capped tray in every count', () => {
		document.body.innerHTML = LIST.replace(
			'data-dcta-pie-total="3">3 documentos',
			'data-dcta-pie-total="500">mostrando 3 de 500 documentos · afina con los filtros'
		);
		load();

		typeInFilter( 'jornadas' );
		expect( document.querySelector( '[data-dcta-pie]' ).textContent ).toBe(
			'1 de 3 documentos mostrados de 500 · afina con los filtros'
		);

		// The filter never looked at the other 497: saying nothing matches
		// would be false.
		typeInFilter( 'nada de nada' );
		expect( document.querySelector( '.dcta-vacio' ).hidden ).toBe( false );
		expect( document.querySelector( '.dcta-vacio' ).textContent ).toBe(
			'Ningún documento de los 3 que hay en pantalla coincide con el filtro · la bandeja tiene 500, afina con los filtros.'
		);
	} );

	it( 'is set up once however often the module runs', () => {
		mountList();
		load();
		load();

		typeInFilter( 'nada de nada' );
		expect( document.querySelectorAll( '.dcta-vacio' ) ).toHaveLength( 1 );
		expect( document.querySelector( '[data-dcta-pie]' ).textContent ).toBe(
			'0 de 3 documentos'
		);
	} );
} );
