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
	createFixture,
	removeFixture,
} = require( '../fixtures/site' );

const RUN = `flujo${ Date.now() }`;
const AREA_LOGIN = `${ RUN }area`;
const MANAGEMENT_LOGIN = `${ RUN }gestion`;

const APP_PATH = '/documentate/';

const NAME = `Piloto ${ RUN }`;
const TITLE = `Resolución por la que se aprueba el piloto ${ RUN }`;
const REASON = `Falta el número de expediente ${ RUN }`;
const RESOLUTION_NUMBER = `118/${ RUN.slice( -4 ) }`;
const FILE_NAME = `acta-${ RUN }.pdf`;

/**
 * Locator of the list row of a document, found by its internal name.
 *
 * @param {import('@playwright/test').Page} page   Page showing a tray.
 * @param {string}                          name Internal name (or its prefix form).
 * @return {import('@playwright/test').Locator} Row locator.
 */
function row( page, name ) {
	return page.locator( '.dcta-fila', {
		has: page.locator( '.dcta-doc-nombre a', { hasText: name } ),
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
async function formBody( page, extras ) {
	return await page.evaluate( ( added ) => {
		const form = document.querySelector( 'form.dcta-editor' );
		const params = new URLSearchParams();
		const outside = [ 'file', 'submit', 'button', 'reset', 'image' ];

		Array.prototype.forEach.call( form.elements, ( control ) => {
			const type = String( control.type || '' ).toLowerCase();
			if ( ! control.name || control.disabled || outside.includes( type ) ) {
				return;
			}
			if (
				( 'checkbox' === type || 'radio' === type ) &&
				! control.checked
			) {
				return;
			}
			params.append( control.name, control.value );
		} );

		Object.keys( added ).forEach( ( key ) => {
			params.set( key, added[ key ] );
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
function statusOf( docId ) {
	return runWpCmd( `post get ${ docId } --field=post_status --user=1` ).trim();
}

test.describe.serial( 'Documentate app · full workflow', () => {
	let fixture;
	let docTypeId = 0;
	let docId = 0;
	let docWithoutReason = 0;
	let areaSession = null;
	let managementSession = null;

	/**
	 * The área session, opened once and reused by every step.
	 *
	 * @param {import('@playwright/test').Browser} browser Playwright browser.
	 * @param {string}                             baseURL Site base URL.
	 * @return {Promise<import('@playwright/test').Page>} Logged-in page.
	 */
	async function areaPage( browser, baseURL ) {
		if ( ! areaSession ) {
			areaSession = await loginAs( browser, baseURL, AREA_LOGIN );
		}

		return areaSession.page;
	}

	/**
	 * The gestión documental session, opened once and reused by every step.
	 *
	 * @param {import('@playwright/test').Browser} browser Playwright browser.
	 * @param {string}                             baseURL Site base URL.
	 * @return {Promise<import('@playwright/test').Page>} Logged-in page.
	 */
	async function managementPage( browser, baseURL ) {
		if ( ! managementSession ) {
			managementSession = await loginAs( browser, baseURL, MANAGEMENT_LOGIN );
		}

		return managementSession.page;
	}

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		fixture = createFixture( {
			categories: {
				area: `Área ${ RUN }`,
				management: `Gestión ${ RUN }`,
			},
			// The seeded Resolución declares gestión fields in its schema, so
			// it is a "goes through gestión documental" type by itself: the
			// spec reads that property instead of writing the shared term.
			types: { res: { slug: 'resolucion-administrativa' } },
			users: {
				area: { login: AREA_LOGIN, role: 'author', scope: 'area' },
				management: {
					login: MANAGEMENT_LOGIN,
					role: 'editor',
					scope: 'gestion',
					management: true,
				},
			},
			documents: {
				// A second document, already waiting in gestión, so the
				// empty-reason check never touches the one the cycle walks
				// through; and a third one for administración's return.
				sinMotivo: {
					title: `Sin motivo ${ RUN }`,
					category: 'area',
					type: 'res',
					author: 'area',
					status: 'en_gestion',
					name: `Sin motivo ${ RUN }`,
				},
				devolverArea: {
					title: `Devolver al área ${ RUN }`,
					category: 'area',
					type: 'res',
					author: 'area',
					status: 'pending',
					name: `Devolver al área ${ RUN }`,
				},
				devolverGestion: {
					title: `Devolver a gestión ${ RUN }`,
					category: 'area',
					type: 'res',
					author: 'area',
					status: 'pending',
					name: `Devolver a gestión ${ RUN }`,
				},
			},
		} );

		docTypeId = fixture.types.res;
		expect( docTypeId ).toBeGreaterThan( 0 );
		docWithoutReason = fixture.documents.sinMotivo;
	} );

	test.afterAll( async () => {
		test.setTimeout( 300_000 );

		if ( areaSession ) {
			await areaSession.context.close();
		}
		if ( managementSession ) {
			await managementSession.context.close();
		}

		// A beforeAll that threw (a WP-CLI lock that never cleared, an
		// unexpected answer) leaves the fixture unbuilt, and Playwright still
		// runs this hook: without the guard it dies dereferencing it and the
		// report shows that TypeError instead of the real failure.
		if ( ! fixture ) {
			return;
		}

		removeFixture( {
			documents: Object.values( fixture.documents ).concat( [
				docId,
			] ),
			users: [ AREA_LOGIN, MANAGEMENT_LOGIN ],
			categories: Object.values( fixture.categories ),
		} );
	} );

	test( 'the area creates the document, attaches a PDF and sends it to management', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await areaPage( browser, baseURL );

		await area.goto( `${ APP_PATH }?vista=nuevo` );
		await expect( area.locator( '.dcta-rol' ) ).toContainText( 'Área' );

		await area.selectOption( '#documentate-app-tipo', String( docTypeId ) );
		// The hint under the select is written by documentate-app.js.
		await expect( area.locator( '#documentate-app-tipo-nota' ) ).toHaveText(
			'Pasa por gestión documental.'
		);
		await area.fill( '#documentate-app-nombre', NAME );
		await area.fill( '#documentate-app-titulo', TITLE );

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
				name: FILE_NAME,
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
			FILE_NAME
		);

		// Sending asks for confirmation first.
		await area
			.getByRole( 'button', { name: 'Enviar a gestión' } )
			.click();
		const confirmDialog = area.getByRole( 'dialog' );
		await expect( confirmDialog ).toContainText(
			'Ya no podrás modificarlo hasta que te lo devuelvan.'
		);

		await Promise.all( [
			area.waitForURL( /enviado=1/ ),
			confirmDialog
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

	test( 'management finds the document in «Para revisar» and sees the area data', async ( {
		browser,
		baseURL,
	} ) => {
		const management = await managementPage( browser, baseURL );

		await management.goto( `${ APP_PATH }?bandeja=revisar` );
		await expect( management.locator( '.dcta-h1' ) ).toHaveText(
			'Para revisar'
		);
		await expect( management.locator( '.dcta-rol' ) ).toHaveText(
			'Gestión documental'
		);

		const ours = row( management, NAME );
		await expect( ours ).toHaveCount( 1 );
		await expect( ours.locator( '.dcta-doc-sub' ).first() ).toHaveText(
			TITLE
		);
		// The paper clip marks the document that carries a file.
		await expect( ours.locator( '.dcta-doc-adjunto' ) ).toHaveCount( 1 );
		await expect( ours.locator( '.dcta-estado' ) ).toHaveText(
			'En gestión'
		);

		await Promise.all( [
			management.waitForURL( /vista=editar/ ),
			ours.getByRole( 'link', { name: 'Revisar' } ).click(),
		] );

		// Gestión sees both halves of the document: what the área wrote, and
		// the official data only it may fill in.
		await expect(
			management.locator( 'details.dcta-seccion-area' )
		).toContainText( 'Datos del área' );
		await expect(
			management.locator( '#documentate_field_objeto' )
		).toHaveValue( `Área ${ RUN }` );
		await expect( management.locator( '#documentate-app-nombre' ) ).toHaveValue(
			NAME
		);

		const managementFields = management.locator( 'tr.documentate-campo-gestion' );
		expect( await managementFields.count() ).toBeGreaterThan( 0 );
		await expect(
			management.locator( '#documentate_field_numero_resolucion' )
		).toBeVisible();
		await expect(
			management.locator( '#documentate-app-anotaciones' )
		).toHaveCount( 1 );
	} );

	test( 'the server refuses a return with no reason', async ( {
		browser,
		baseURL,
	} ) => {
		const management = await managementPage( browser, baseURL );

		await management.goto(
			`${ APP_PATH }?doc=${ docWithoutReason }&vista=editar&bandeja=revisar`
		);
		await expect( management.locator( 'form.dcta-editor' ) ).toHaveCount( 1 );

		const action = await management
			.locator( 'form.dcta-editor' )
			.getAttribute( 'action' );
		const body = await formBody( management, {
			documentate_app_transicion: 'devolver_area',
			documentate_app_motivo: '   ',
		} );

		const response = await management.request.post( action, {
			headers: {
				'content-type': 'application/x-www-form-urlencoded',
			},
			data: body,
		} );

		expect( response.status() ).toBe( 200 );
		expect( response.url() ).toContain( 'error=motivo' );
		expect( await response.text() ).toContain(
			'Para devolver un documento hay que decir por qué.'
		);
		// Nothing moved: the document is still waiting in gestión.
		expect( statusOf( docWithoutReason ) ).toBe( 'en_gestion' );
	} );

	test( 'management completes the official data and returns it with a reason', async ( {
		browser,
		baseURL,
	} ) => {
		const management = await managementPage( browser, baseURL );

		await management.goto(
			`${ APP_PATH }?doc=${ docId }&vista=editar&bandeja=revisar`
		);
		await management
			.locator( '#documentate_field_numero_resolucion' )
			.fill( RESOLUTION_NUMBER );
		await management
			.locator( '#documentate-app-anotaciones' )
			.fill( `Anotación ${ RUN }` );

		await management
			.getByRole( 'button', { name: 'Devolver al área' } )
			.click();
		const dialog = management.getByRole( 'dialog' );
		await expect( dialog ).toContainText( 'Motivo de la devolución' );
		await dialog.locator( '#dcta-dialogo-motivo-texto' ).fill( REASON );

		await Promise.all( [
			management.waitForURL( /devuelto=1/ ),
			dialog
				.getByRole( 'button', { name: 'Devolver', exact: true } )
				.click(),
		] );

		// A return lands on the tray, not on the document: the reviewer moves on.
		await expect( management.locator( '.dcta-h1' ) ).toHaveText(
			'Para revisar'
		);
		await expect( management.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento devuelto con el motivo indicado.'
		);
		expect( statusOf( docId ) ).toBe( 'draft' );
	} );

	test( 'the area sees «Devuelto» with the reason, corrects it and sends it again', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await areaPage( browser, baseURL );

		await area.goto( APP_PATH );
		const ours = row( area, NAME );
		await expect( ours ).toHaveClass( /dcta-fila-devuelta/ );
		await expect( ours.locator( '.dcta-estado-devuelto' ) ).toHaveText(
			'Devuelto'
		);
		await expect( ours.locator( '.dcta-doc-motivo' ) ).toContainText(
			REASON
		);
		await expect( ours.locator( '.dcta-doc-motivo' ) ).toContainText(
			'Devuelto por gestión documental'
		);

		await Promise.all( [
			area.waitForURL( /vista=editar/ ),
			ours.getByRole( 'link', { name: 'Corregir' } ).click(),
		] );
		await expect( area.locator( '.dcta-aviso-devuelto' ) ).toContainText(
			REASON
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

	test( 'management passes it to administration and still finds it', async ( {
		browser,
		baseURL,
	} ) => {
		const management = await managementPage( browser, baseURL );

		await management.goto( `${ APP_PATH }?bandeja=revisar` );
		await Promise.all( [
			management.waitForURL( /vista=editar/ ),
			row( management, NAME )
				.getByRole( 'link', { name: 'Revisar' } )
				.click(),
		] );
		// The correction the área made travelled back to gestión.
		await expect(
			management.locator( '#documentate_field_objeto' )
		).toHaveValue( `Área corregida ${ RUN }` );

		await management
			.getByRole( 'button', { name: 'Pasar a administración' } )
			.click();
		const confirmDialog = management.getByRole( 'dialog' );
		await expect( confirmDialog ).toContainText(
			'Gestión ya no podrá modificarlo hasta que lo devuelvan.'
		);

		await Promise.all( [
			management.waitForURL( /enviado=1/ ),
			confirmDialog
				.getByRole( 'button', { name: 'Pasar a administración' } )
				.click(),
		] );
		await expect( management.locator( '.dcta-aviso-ok' ) ).toHaveText(
			'Documento pasado a administración.'
		);
		await expect( management.locator( '.dcta-lado .dcta-estado' ) ).toHaveText(
			'En revisión'
		);

		// Passing it on does not hide it: the tray keeps every document that
		// has left its área, whatever its status.
		await management.goto( `${ APP_PATH }?bandeja=revisar&estado=pending` );
		await expect( row( management, NAME ) ).toHaveCount( 1 );
		await management.goto( `${ APP_PATH }?bandeja=revisar&estado=todos` );
		await expect( row( management, NAME ) ).toHaveCount( 1 );
	} );

	test( 'administration approves it and the list shows «Aprobado»', async ( {
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
			row( page, NAME ).getByRole( 'link', { name: 'Revisar' } ).click(),
		] );

		await page
			.getByRole( 'button', { name: 'Aprobar y publicar' } )
			.click();
		const confirmDialog = page.getByRole( 'dialog' );
		await expect( confirmDialog ).toContainText(
			'Quedará bloqueado; solo se podrá consultar y descargar.'
		);

		await Promise.all( [
			page.waitForURL( /aprobado=1/ ),
			confirmDialog
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
		).toContainText( RESOLUTION_NUMBER );

		const area = await areaPage( browser, baseURL );
		await area.goto( APP_PATH );
		const ours = row( area, NAME );
		await expect( ours.locator( '.dcta-estado' ) ).toHaveText(
			'Aprobado'
		);
		const viewPdf = ours.getByRole( 'link', { name: 'Ver PDF' } );
		await expect( viewPdf ).toHaveAttribute( 'href', /#exportar$/ );
	} );

	test( 'administration picks where to return it, and the document goes there', async ( {
		page,
	} ) => {
		/**
		 * Return a pending document through administración's dialog.
		 *
		 * Both radios and the hidden fallback carry the same field name, so
		 * this also proves that exactly one destination travels with the post.
		 *
		 * @param {number} doc    Document ID.
		 * @param {string} target Radio label ("Al área" / "Gestión documental").
		 * @return {Promise<void>}
		 */
		async function returnDocument( doc, target ) {
			await page.goto(
				`${ APP_PATH }?doc=${ doc }&vista=editar&bandeja=revision`
			);
			await page.getByRole( 'button', { name: 'Devolver…' } ).click();

			const dialog = page.getByRole( 'dialog' );
			await expect( dialog ).toContainText( 'Devolver a:' );
			await expect(
				dialog.getByRole( 'radio', { name: 'Gestión documental' } )
			).toBeVisible();
			await expect(
				dialog.getByRole( 'radio', { name: 'Al área' } )
			).toBeVisible();

			await dialog.getByRole( 'radio', { name: target } ).check();
			await dialog
				.locator( '#dcta-dialogo-motivo-texto' )
				.fill( `${ REASON } (${ target })` );

			await Promise.all( [
				page.waitForURL( /devuelto=1/ ),
				dialog
					.getByRole( 'button', { name: 'Devolver', exact: true } )
					.click(),
			] );
			await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText(
				'Documento devuelto con el motivo indicado.'
			);
		}

		await returnDocument( fixture.documents.devolverArea, 'Al área' );
		expect( statusOf( fixture.documents.devolverArea ) ).toBe( 'draft' );

		await returnDocument(
			fixture.documents.devolverGestion,
			'Gestión documental'
		);
		expect( statusOf( fixture.documents.devolverGestion ) ).toBe(
			'en_gestion'
		);
	} );

	test( 'the activity lists the events of the cycle in order', async ( {
		browser,
		baseURL,
	} ) => {
		const area = await areaPage( browser, baseURL );
		await area.goto( `${ APP_PATH }?doc=${ docId }` );

		const events = (
			await area.locator( '.dcta-actividad-item' ).allInnerTexts()
		).map( ( text ) => text.replace( /\s+/g, ' ' ).trim() );

		const index = ( text ) =>
			events.findIndex( ( entry ) => entry.includes( text ) );

		// The list is newest first. Only steps separated by a navigation are
		// compared: listar() orders by comment_date_gmt with no tiebreaker, so
		// two events written inside the same second may come back either way.
		expect( events[ 0 ] ).toContain( 'aprobó y publicó el documento' );
		expect( index( 'creó el borrador' ) ).toBeGreaterThan(
			index( 'envió el documento a gestión' )
		);

		expect( index( 'pasó el documento a administración' ) ).toBeGreaterThan(
			0
		);
		expect( index( 'devolvió el documento al área' ) ).toBeGreaterThan(
			index( 'pasó el documento a administración' )
		);
		expect( index( `adjuntó el fichero «${ FILE_NAME }»` ) ).toBeGreaterThan(
			index( 'devolvió el documento al área' )
		);
		expect(
			events.filter( ( entry ) =>
				entry.includes( 'envió el documento a gestión' )
			)
		).toHaveLength( 2 );
	} );
} );
