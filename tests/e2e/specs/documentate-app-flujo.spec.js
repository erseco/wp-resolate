/**
 * E2E tests for the full document cycle of the application (/documentate/).
 *
 * One document of a type that goes through gestión documental (Resolución)
 * travels the whole pipeline: the área creates it, attaches a file, fills its
 * fields and sends it; gestión completes the official data and returns it with
 * a reason; the área corrects it and sends it again; gestión passes it to
 * administración and administración approves it. The activity of the document
 * is then the log of everything that happened.
 *
 * Every role works in its own browser context, because the application shows a
 * different sheet to each of them.
 */
const { test, expect, fillRequiredAppFields } = require( '../fixtures' );
const {
	PDF_FIXTURE,
	runWpCmd,
	loginAs,
	crearEscenario,
	limpiarEscenario,
} = require( '../fixtures/site' );

const RUN = `flujo${ Date.now() }`;
const AREA_LOGIN = `${ RUN }area`;
const GESTION_LOGIN = `${ RUN }gestion`;

const APP_PATH = '/documentate/';

const NOMBRE = `Piloto ${ RUN }`;
const TITULO = `Resolución por la que se aprueba el piloto ${ RUN }`;
const MOTIVO = `Falta el número de expediente ${ RUN }`;
const NUMERO_RESOLUCION = `118/${ RUN.slice( -4 ) }`;
const FICHERO = `acta-${ RUN }.pdf`;

/**
 * Locator of the list row of a document, found by its internal name.
 *
 * @param {import('@playwright/test').Page} page   Page showing a tray.
 * @param {string}                          nombre Internal name (or its prefix form).
 * @return {import('@playwright/test').Locator} Row locator.
 */
function fila( page, nombre ) {
	return page.locator( '.dcta-fila', {
		has: page.locator( '.dcta-doc-nombre a', { hasText: nombre } ),
	} );
}

/**
 * Serialise the editor form exactly as the browser would post it.
 *
 * Used by the no-JavaScript checks: the server must refuse a return with no
 * reason even when the dialog is bypassed, and the rest of the form has to
 * travel with it so the request is the one a real submit would make.
 *
 * @param {import('@playwright/test').Page} page   Page on the edit view.
 * @param {Object<string,string>}           extras Fields to add or replace.
 * @return {Promise<string>} URL-encoded body.
 */
async function cuerpoDelFormulario( page, extras ) {
	return await page.evaluate( ( añadidos ) => {
		const form = document.querySelector( 'form.dcta-editor' );
		const params = new URLSearchParams();
		const fuera = [ 'file', 'submit', 'button', 'reset', 'image' ];

		Array.prototype.forEach.call( form.elements, ( control ) => {
			const tipo = String( control.type || '' ).toLowerCase();
			if ( ! control.name || control.disabled || fuera.includes( tipo ) ) {
				return;
			}
			if (
				( 'checkbox' === tipo || 'radio' === tipo ) &&
				! control.checked
			) {
				return;
			}
			params.append( control.name, control.value );
		} );

		Object.keys( añadidos ).forEach( ( clave ) => {
			params.set( clave, añadidos[ clave ] );
		} );

		return params.toString();
	}, extras );
}

/**
 * Status a document is stored with right now.
 *
 * @param {number} docId Document ID.
 * @return {string} Post status.
 */
function estadoDe( docId ) {
	return runWpCmd( `post get ${ docId } --field=post_status --user=1` ).trim();
}

test.describe.serial( 'Documentate app · flujo completo', () => {
	let escenario;
	let docTypeId = 0;
	let docId = 0;
	let docSinMotivo = 0;
	let sesionArea = null;
	let sesionGestion = null;

	/**
	 * The área session, opened once and reused by every step.
	 *
	 * @param {import('@playwright/test').Browser} browser Playwright browser.
	 * @param {string}                             baseURL Site base URL.
	 * @return {Promise<import('@playwright/test').Page>} Logged-in page.
	 */
	async function paginaArea( browser, baseURL ) {
		if ( ! sesionArea ) {
			sesionArea = await loginAs( browser, baseURL, AREA_LOGIN );
		}

		return sesionArea.page;
	}

	/**
	 * The gestión documental session, opened once and reused by every step.
	 *
	 * @param {import('@playwright/test').Browser} browser Playwright browser.
	 * @param {string}                             baseURL Site base URL.
	 * @return {Promise<import('@playwright/test').Page>} Logged-in page.
	 */
	async function paginaGestion( browser, baseURL ) {
		if ( ! sesionGestion ) {
			sesionGestion = await loginAs( browser, baseURL, GESTION_LOGIN );
		}

		return sesionGestion.page;
	}

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		escenario = crearEscenario( {
			categorias: {
				area: `Área ${ RUN }`,
				gestion: `Gestión ${ RUN }`,
			},
			// The seeded Resolución declares gestión fields in its schema, so
			// it is a "goes through gestión documental" type by itself: the
			// spec reads that property instead of writing the shared term.
			tipos: { res: { slug: 'resolucion-administrativa' } },
			usuarios: {
				area: { login: AREA_LOGIN, rol: 'author', ambito: 'area' },
				gestion: {
					login: GESTION_LOGIN,
					rol: 'editor',
					ambito: 'gestion',
					gestion: true,
				},
			},
			documentos: {
				// A second document, already waiting in gestión, so the
				// empty-reason check never touches the one the cycle walks
				// through; and a third one for administración's return.
				sinMotivo: {
					titulo: `Sin motivo ${ RUN }`,
					categoria: 'area',
					tipo: 'res',
					autor: 'area',
					estado: 'en_gestion',
					nombre: `Sin motivo ${ RUN }`,
				},
				devolverArea: {
					titulo: `Devolver al área ${ RUN }`,
					categoria: 'area',
					tipo: 'res',
					autor: 'area',
					estado: 'pending',
					nombre: `Devolver al área ${ RUN }`,
				},
				devolverGestion: {
					titulo: `Devolver a gestión ${ RUN }`,
					categoria: 'area',
					tipo: 'res',
					autor: 'area',
					estado: 'pending',
					nombre: `Devolver a gestión ${ RUN }`,
				},
			},
		} );

		docTypeId = escenario.tipos.res;
		expect( docTypeId ).toBeGreaterThan( 0 );
		docSinMotivo = escenario.documentos.sinMotivo;
	} );

	test.afterAll( async () => {
		test.setTimeout( 300_000 );

		if ( sesionArea ) {
			await sesionArea.context.close();
		}
		if ( sesionGestion ) {
			await sesionGestion.context.close();
		}

		// A beforeAll that threw (a WP-CLI lock that never cleared, an
		// unexpected answer) leaves the fixture unbuilt, and Playwright still
		// runs this hook: without the guard it dies dereferencing it and the
		// report shows that TypeError instead of the real failure.
		if ( ! escenario ) {
			return;
		}

		limpiarEscenario( {
			documentos: Object.values( escenario.documentos ).concat( [
				docId,
			] ),
			usuarios: [ AREA_LOGIN, GESTION_LOGIN ],
			categorias: Object.values( escenario.categorias ),
		} );
	} );

	test( 'el área crea el documento, adjunta un PDF y lo envía a gestión', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await paginaArea( browser, baseURL );

		await area.goto( `${ APP_PATH }?vista=nuevo` );
		await expect( area.locator( '.dcta-rol' ) ).toContainText( 'Área' );

		await area.selectOption( '#documentate-app-tipo', String( docTypeId ) );
		// The hint under the select is written by documentate-app.js.
		await expect( area.locator( '#documentate-app-tipo-nota' ) ).toHaveText(
			'Pasa por gestión documental.'
		);
		await area.fill( '#documentate-app-nombre', NOMBRE );
		await area.fill( '#documentate-app-titulo', TITULO );

		await Promise.all( [
			area.waitForURL( /vista=editar/ ),
			area
				.getByRole( 'button', { name: 'Crear borrador' } )
				.click(),
		] );

		docId = parseInt( new URL( area.url() ).searchParams.get( 'doc' ), 10 );
		expect( docId ).toBeGreaterThan( 0 );

		// The banner tells the área where this type of document is going.
		await expect( area.locator( '.dcta-aviso-info' ) ).toContainText(
			'pasa por gestión documental'
		);

		await area
			.locator( '#documentate-app-adjunto' )
			.setInputFiles( {
				name: FICHERO,
				mimeType: 'application/pdf',
				buffer: PDF_FIXTURE,
			} );
		await fillRequiredAppFields( area, `Área ${ RUN }` );

		await Promise.all( [
			area.waitForURL( /guardado=1/ ),
			area.getByRole( 'button', { name: 'Guardar borrador' } ).click(),
		] );
		await expect( area.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Cambios guardados.'
		);
		await expect( area.locator( '.dcta-adjunto-nombre' ) ).toHaveText(
			FICHERO
		);

		// Sending asks for confirmation first.
		await area
			.getByRole( 'button', { name: 'Enviar a gestión' } )
			.click();
		const confirmacion = area.getByRole( 'dialog' );
		await expect( confirmacion ).toContainText(
			'Ya no podrás modificarlo hasta que te lo devuelvan.'
		);

		await Promise.all( [
			area.waitForURL( /enviado=1/ ),
			confirmacion
				.getByRole( 'button', { name: 'Enviar a gestión' } )
				.click(),
		] );
		await expect( area.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento enviado a gestión documental.'
		);
		await expect( area.locator( '.dcta-lado .dcta-estado' ) ).toHaveText(
			'En gestión'
		);

		// The área can no longer touch it: the document is with gestión.
		await area.goto( `${ APP_PATH }?doc=${ docId }&vista=editar` );
		await expect( area.locator( '.dcta-aviso' ) ).toContainText(
			'bloqueado en su estado actual'
		);
	} );

	test( 'gestión encuentra el documento en «Para revisar» y ve los datos del área', async ( {
		browser,
		baseURL,
	} ) => {
		const gestion = await paginaGestion( browser, baseURL );

		await gestion.goto( `${ APP_PATH }?bandeja=revisar` );
		await expect( gestion.locator( '.dcta-h1' ) ).toHaveText(
			'Para revisar'
		);
		await expect( gestion.locator( '.dcta-rol' ) ).toHaveText(
			'Gestión documental'
		);

		const nuestra = fila( gestion, NOMBRE );
		await expect( nuestra ).toHaveCount( 1 );
		await expect( nuestra.locator( '.dcta-doc-sub' ).first() ).toHaveText(
			TITULO
		);
		// The paper clip marks the document that carries a file.
		await expect( nuestra.locator( '.dcta-doc-adjunto' ) ).toHaveCount( 1 );
		await expect( nuestra.locator( '.dcta-estado' ) ).toHaveText(
			'En gestión'
		);

		await Promise.all( [
			gestion.waitForURL( /vista=editar/ ),
			nuestra.getByRole( 'link', { name: 'Revisar' } ).click(),
		] );

		// Gestión sees both halves of the document: what the área wrote, and
		// the official data only it may fill in.
		await expect(
			gestion.locator( 'details.dcta-seccion-area' )
		).toContainText( 'Datos del área' );
		await expect(
			gestion.locator( '#documentate_field_objeto' )
		).toHaveValue( `Área ${ RUN }` );
		await expect( gestion.locator( '#documentate-app-nombre' ) ).toHaveValue(
			NOMBRE
		);

		const camposGestion = gestion.locator( 'tr.documentate-campo-gestion' );
		expect( await camposGestion.count() ).toBeGreaterThan( 0 );
		await expect(
			gestion.locator( '#documentate_field_numero_resolucion' )
		).toBeVisible();
		await expect(
			gestion.locator( '#documentate-app-anotaciones' )
		).toHaveCount( 1 );
	} );

	test( 'el servidor rechaza una devolución sin motivo', async ( {
		browser,
		baseURL,
	} ) => {
		const gestion = await paginaGestion( browser, baseURL );

		await gestion.goto(
			`${ APP_PATH }?doc=${ docSinMotivo }&vista=editar&bandeja=revisar`
		);
		await expect( gestion.locator( 'form.dcta-editor' ) ).toHaveCount( 1 );

		const accion = await gestion
			.locator( 'form.dcta-editor' )
			.getAttribute( 'action' );
		const cuerpo = await cuerpoDelFormulario( gestion, {
			documentate_app_transicion: 'devolver_area',
			documentate_app_motivo: '   ',
		} );

		const respuesta = await gestion.request.post( accion, {
			headers: {
				'content-type': 'application/x-www-form-urlencoded',
			},
			data: cuerpo,
		} );

		expect( respuesta.status() ).toBe( 200 );
		expect( respuesta.url() ).toContain( 'error=motivo' );
		expect( await respuesta.text() ).toContain(
			'Para devolver un documento hay que decir por qué.'
		);
		// Nothing moved: the document is still waiting in gestión.
		expect( estadoDe( docSinMotivo ) ).toBe( 'en_gestion' );
	} );

	test( 'gestión completa los datos oficiales y lo devuelve con un motivo', async ( {
		browser,
		baseURL,
	} ) => {
		const gestion = await paginaGestion( browser, baseURL );

		await gestion.goto(
			`${ APP_PATH }?doc=${ docId }&vista=editar&bandeja=revisar`
		);
		await gestion
			.locator( '#documentate_field_numero_resolucion' )
			.fill( NUMERO_RESOLUCION );
		await gestion
			.locator( '#documentate-app-anotaciones' )
			.fill( `Anotación ${ RUN }` );

		await gestion
			.getByRole( 'button', { name: 'Devolver al área' } )
			.click();
		const dialogo = gestion.getByRole( 'dialog' );
		await expect( dialogo ).toContainText( 'Motivo de la devolución' );
		await dialogo.locator( '#dcta-dialogo-motivo-texto' ).fill( MOTIVO );

		await Promise.all( [
			gestion.waitForURL( /devuelto=1/ ),
			dialogo
				.getByRole( 'button', { name: 'Devolver', exact: true } )
				.click(),
		] );

		// A return lands on the tray, not on the document: the reviewer moves on.
		await expect( gestion.locator( '.dcta-h1' ) ).toHaveText(
			'Para revisar'
		);
		await expect( gestion.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento devuelto con el motivo indicado.'
		);
		expect( estadoDe( docId ) ).toBe( 'draft' );
	} );

	test( 'el área ve «Devuelto» con el motivo, corrige y vuelve a enviar', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await paginaArea( browser, baseURL );

		await area.goto( APP_PATH );
		const nuestra = fila( area, NOMBRE );
		await expect( nuestra ).toHaveClass( /dcta-fila-devuelta/ );
		await expect( nuestra.locator( '.dcta-estado-devuelto' ) ).toHaveText(
			'Devuelto'
		);
		await expect( nuestra.locator( '.dcta-doc-motivo' ) ).toContainText(
			MOTIVO
		);
		await expect( nuestra.locator( '.dcta-doc-motivo' ) ).toContainText(
			'Devuelto por gestión documental'
		);

		await Promise.all( [
			area.waitForURL( /vista=editar/ ),
			nuestra.getByRole( 'link', { name: 'Corregir' } ).click(),
		] );
		await expect( area.locator( '.dcta-aviso-devuelto' ) ).toContainText(
			MOTIVO
		);
		await expect( area.locator( '.dcta-aviso-devuelto' ) ).toContainText(
			'Corrige lo que haga falta y vuelve a enviarlo.'
		);

		// The área never sees the official data, not even after a return.
		await expect(
			area.locator( 'tr.documentate-campo-gestion' )
		).toHaveCount( 0 );
		await expect(
			area.locator( '#documentate_field_numero_resolucion' )
		).toHaveCount( 0 );

		await area
			.locator( '#documentate_field_objeto' )
			.fill( `Área corregida ${ RUN }` );

		await area.getByRole( 'button', { name: 'Enviar a gestión' } ).click();
		await Promise.all( [
			area.waitForURL( /enviado=1/ ),
			area
				.getByRole( 'dialog' )
				.getByRole( 'button', { name: 'Enviar a gestión' } )
				.click(),
		] );
		await expect( area.locator( '.dcta-lado .dcta-estado' ) ).toHaveText(
			'En gestión'
		);
		// The mark is cleared by the forward move: no stale "Devuelto" line.
		await expect( area.locator( '.dcta-aviso-devuelto' ) ).toHaveCount( 0 );
	} );

	test( 'gestión lo pasa a administración y sigue encontrándolo', async ( {
		browser,
		baseURL,
	} ) => {
		const gestion = await paginaGestion( browser, baseURL );

		await gestion.goto( `${ APP_PATH }?bandeja=revisar` );
		await Promise.all( [
			gestion.waitForURL( /vista=editar/ ),
			fila( gestion, NOMBRE )
				.getByRole( 'link', { name: 'Revisar' } )
				.click(),
		] );
		// The correction the área made travelled back to gestión.
		await expect(
			gestion.locator( '#documentate_field_objeto' )
		).toHaveValue( `Área corregida ${ RUN }` );

		await gestion
			.getByRole( 'button', { name: 'Pasar a administración' } )
			.click();
		const confirmacion = gestion.getByRole( 'dialog' );
		await expect( confirmacion ).toContainText(
			'Gestión ya no podrá modificarlo hasta que lo devuelvan.'
		);

		await Promise.all( [
			gestion.waitForURL( /enviado=1/ ),
			confirmacion
				.getByRole( 'button', { name: 'Pasar a administración' } )
				.click(),
		] );
		await expect( gestion.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento pasado a administración.'
		);
		await expect( gestion.locator( '.dcta-lado .dcta-estado' ) ).toHaveText(
			'En revisión'
		);

		// Passing it on does not hide it: the tray keeps every document that
		// has left its área, whatever its status.
		await gestion.goto( `${ APP_PATH }?bandeja=revisar&estado=pending` );
		await expect( fila( gestion, NOMBRE ) ).toHaveCount( 1 );
		await gestion.goto( `${ APP_PATH }?bandeja=revisar&estado=todos` );
		await expect( fila( gestion, NOMBRE ) ).toHaveCount( 1 );
	} );

	test( 'administración lo aprueba y la lista muestra «Aprobado»', async ( {
		page,
		browser,
		baseURL,
	} ) => {
		await page.goto( `${ APP_PATH }?bandeja=revision` );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText(
			'Administración'
		);

		await Promise.all( [
			page.waitForURL( /vista=editar/ ),
			fila( page, NOMBRE ).getByRole( 'link', { name: 'Revisar' } ).click(),
		] );

		await page
			.getByRole( 'button', { name: 'Aprobar y publicar' } )
			.click();
		const confirmacion = page.getByRole( 'dialog' );
		await expect( confirmacion ).toContainText(
			'Quedará bloqueado; solo se podrá consultar y descargar.'
		);

		await Promise.all( [
			page.waitForURL( /aprobado=1/ ),
			confirmacion
				.getByRole( 'button', { name: 'Aprobar y publicar' } )
				.click(),
		] );
		await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento aprobado y publicado.'
		);
		await expect( page.locator( '.dcta-lado .dcta-estado' ) ).toHaveText(
			'Aprobado'
		);

		// The number gestión typed survived the correction round trip: the
		// área cannot write the fields it never sees.
		await expect(
			page.locator( '.dcta-card', { hasText: 'Datos oficiales' } )
		).toContainText( NUMERO_RESOLUCION );

		const area = await paginaArea( browser, baseURL );
		await area.goto( APP_PATH );
		const nuestra = fila( area, NOMBRE );
		await expect( nuestra.locator( '.dcta-estado' ) ).toHaveText(
			'Aprobado'
		);
		const verPdf = nuestra.getByRole( 'link', { name: 'Ver PDF' } );
		await expect( verPdf ).toHaveAttribute( 'href', /#exportar$/ );
	} );

	test( 'administración elige el destino al devolver, y el documento va donde dice', async ( {
		page,
	} ) => {
		/**
		 * Return a pending document through administración's dialog.
		 *
		 * Both radios and the hidden fallback carry the same field name, so
		 * this also proves that exactly one destination travels with the post.
		 *
		 * @param {number} doc     Document ID.
		 * @param {string} destino Radio label ("Al área" / "Gestión documental").
		 * @return {Promise<void>}
		 */
		async function devolver( doc, destino ) {
			await page.goto(
				`${ APP_PATH }?doc=${ doc }&vista=editar&bandeja=revision`
			);
			await page.getByRole( 'button', { name: 'Devolver…' } ).click();

			const dialogo = page.getByRole( 'dialog' );
			await expect( dialogo ).toContainText( 'Devolver a:' );
			await expect(
				dialogo.getByRole( 'radio', { name: 'Gestión documental' } )
			).toBeVisible();
			await expect(
				dialogo.getByRole( 'radio', { name: 'Al área' } )
			).toBeVisible();

			await dialogo.getByRole( 'radio', { name: destino } ).check();
			await dialogo
				.locator( '#dcta-dialogo-motivo-texto' )
				.fill( `${ MOTIVO } (${ destino })` );

			await Promise.all( [
				page.waitForURL( /devuelto=1/ ),
				dialogo
					.getByRole( 'button', { name: 'Devolver', exact: true } )
					.click(),
			] );
			await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText(
				'Documento devuelto con el motivo indicado.'
			);
		}

		await devolver( escenario.documentos.devolverArea, 'Al área' );
		expect( estadoDe( escenario.documentos.devolverArea ) ).toBe( 'draft' );

		await devolver(
			escenario.documentos.devolverGestion,
			'Gestión documental'
		);
		expect( estadoDe( escenario.documentos.devolverGestion ) ).toBe(
			'en_gestion'
		);
	} );

	test( 'la actividad enumera los eventos del ciclo en orden', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await paginaArea( browser, baseURL );
		await area.goto( `${ APP_PATH }?doc=${ docId }` );

		const eventos = (
			await area.locator( '.dcta-actividad-item' ).allInnerTexts()
		).map( ( texto ) => texto.replace( /\s+/g, ' ' ).trim() );

		const indice = ( texto ) =>
			eventos.findIndex( ( fila_ ) => fila_.includes( texto ) );

		// The list is newest first. Only steps separated by a navigation are
		// compared: listar() orders by comment_date_gmt with no tiebreaker, so
		// two events written inside the same second may come back either way.
		expect( eventos[ 0 ] ).toContain( 'aprobó y publicó el documento' );
		expect( indice( 'creó el borrador' ) ).toBeGreaterThan(
			indice( 'envió el documento a gestión' )
		);

		expect( indice( 'pasó el documento a administración' ) ).toBeGreaterThan(
			0
		);
		expect( indice( 'devolvió el documento al área' ) ).toBeGreaterThan(
			indice( 'pasó el documento a administración' )
		);
		expect( indice( `adjuntó el fichero «${ FICHERO }»` ) ).toBeGreaterThan(
			indice( 'devolvió el documento al área' )
		);
		expect(
			eventos.filter( ( fila_ ) =>
				fila_.includes( 'envió el documento a gestión' )
			)
		).toHaveLength( 2 );
	} );
} );
