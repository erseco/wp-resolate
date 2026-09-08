/**
 * E2E tests for the front-end application (/documentate/).
 *
 * Covers the admin-bar entry point, the create → edit → send for review flow,
 * the scope rules for a non-admin editor and the gates for subscribers and
 * visitors. Every string is matched in Spanish and English because the dev
 * site runs in es_ES while the plugin sources are English.
 */
const { test, expect, fillRequiredAppFields } = require( '../fixtures' );
const {
	loginAs,
	createFixture,
	removeFixture,
} = require( '../fixtures/site' );

const RUN = `app${ Date.now() }`;
const EDITOR_LOGIN = `${ RUN }editor`;
const SUBSCRIBER_LOGIN = `${ RUN }subscriber`;

const TITLES = {
	inScope: `In Scope Draft ${ RUN }`,
	outOfScope: `Out Of Scope Draft ${ RUN }`,
	pending: `In Scope Pending ${ RUN }`,
	created: `App Created ${ RUN }`,
};

// The internal name is what the lists show; the title is the official one.
const NAMES = {
	created: `Nombre ${ RUN }`,
};

const APP_PATH = '/documentate/';

test.describe( 'Documentate app', () => {
	let fixture;
	let docTypeId = 0;
	const docs = {};
	/** Documents the tests themselves create, cleaned up with the rest. */
	const docIds = [];

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		fixture = createFixture( {
			categories: {
				scope: `App Scope ${ RUN }`,
				other: `App Other ${ RUN }`,
			},
			// The seeded Resolución declares gestión fields in its schema, so
			// it goes through gestión documental by itself. The shared term is
			// never written here: parallel workers read the same type.
			types: { res: { slug: 'resolucion-administrativa' } },
			users: {
				editor: {
					login: EDITOR_LOGIN,
					role: 'editor',
					scope: 'scope',
					management: true,
				},
				subscriber: { login: SUBSCRIBER_LOGIN, role: 'subscriber' },
			},
			documents: {
				inScope: {
					title: TITLES.inScope,
					category: 'scope',
					type: 'res',
					author: 'editor',
				},
				outOfScope: {
					title: TITLES.outOfScope,
					category: 'other',
					type: 'res',
				},
				pending: {
					title: TITLES.pending,
					category: 'scope',
					type: 'res',
					author: 'editor',
					status: 'pending',
				},
			},
		} );

		docTypeId = fixture.types.res;
		expect( docTypeId ).toBeGreaterThan( 0 );
		Object.assign( docs, fixture.documents );
	} );

	test.afterAll( async () => {
		test.setTimeout( 300_000 );

		// A beforeAll that threw (a WP-CLI lock that never cleared, an
		// unexpected answer) leaves the fixture unbuilt, and Playwright still
		// runs this hook: without the guard it dies dereferencing it and the
		// report shows that TypeError instead of the real failure.
		if ( ! fixture ) {
			return;
		}

		removeFixture( {
			documents: Object.values( fixture.documents ).concat( docIds ),
			users: [ EDITOR_LOGIN, SUBSCRIBER_LOGIN ],
			categories: Object.values( fixture.categories ),
		} );
	} );

	test( 'admin bar links to the application', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'index.php' );

		const node = page.locator( '#wp-admin-bar-documentate-app > a' );
		await expect( node ).toHaveText( 'Documentate' );
		await expect( node ).toHaveAttribute( 'href', /\/documentate\/?$/ );

		await node.click();
		await page.waitForURL( /\/documentate\/?(\?.*)?$/ );
		await expect( page.locator( '.dcta-h1' ) ).toHaveText( 'Todos los documentos' );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText( 'Administración' );
	} );

	test( 'administrator creates a document, saves the fields and sends it for review', async ( { page } ) => {
		await page.goto( `${ APP_PATH }?vista=nuevo` );

		await page.selectOption( '#documentate-app-tipo', String( docTypeId ) );
		await page.fill( '#documentate-app-nombre', NAMES.created );
		await page.fill( '#documentate-app-titulo', TITLES.created );
		await Promise.all( [
			page.waitForURL( /vista=editar/ ),
			page.getByRole( 'button', { name: 'Crear borrador' } ).click(),
		] );

		const createdId = parseInt( new URL( page.url() ).searchParams.get( 'doc' ), 10 );
		expect( createdId ).toBeGreaterThan( 0 );
		docIds.push( createdId );

		await expect( page.locator( '#documentate-app-titulo' ) ).toHaveValue( TITLES.created );
		await expect( page.locator( '#documentate-app-nombre' ) ).toHaveValue( NAMES.created );
		await expect( page.locator( 'form.dcta-editor input[name="documentate_sections_nonce"]' ) ).toHaveCount( 1 );

		const value = `Valor ${ RUN }`;
		await fillRequiredAppFields( page, value );

		await Promise.all( [
			page.waitForURL( /guardado=1/ ),
			page.getByRole( 'button', { name: 'Guardar borrador' } ).click(),
		] );
		await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText( /Cambios guardados/ );

		// Values survive the round trip through the content writer: a plain
		// field and a rich one (the textarea behind TinyMCE keeps the HTML).
		const form = page.locator( 'form.dcta-editor' );
		const scalar = form.locator( 'input[type="text"][name^="documentate_field_"], textarea[name^="documentate_field_"]:not(.wp-editor-area)' ).first();
		if ( await scalar.count() ) {
			await expect( scalar ).toHaveValue( value );
		}
		const rich = form.locator( '.documentate-rich-editor-wrap[data-required="true"] textarea.wp-editor-area' ).first();
		if ( await rich.count() ) {
			await expect( rich ).toHaveValue( new RegExp( value ) );
		}

		// Sending asks for confirmation in a native dialog first.
		await page.getByRole( 'button', { name: 'Enviar a gestión' } ).click();
		const confirmDialog = page.getByRole( 'dialog' );
		await expect( confirmDialog ).toBeVisible();
		await expect( confirmDialog ).toContainText( /Ya no podrás modificarlo/ );

		await Promise.all( [
			page.waitForURL( /enviado=1/ ),
			confirmDialog.getByRole( 'button', { name: 'Enviar a gestión' } ).click(),
		] );
		// The type goes through gestión documental, so it stops there before
		// reaching administración.
		await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText( /Documento enviado a gestión documental/ );
		await expect( page.locator( '.dcta-lado .dcta-estado' ) ).toHaveText( /En gestión/ );
		await expect( page.locator( '.dcta-h1' ) ).toContainText( NAMES.created );

		// Gestión documental completes it: the área can no longer edit it, and
		// the document is waiting in the review tray.
		await page.goto( `${ APP_PATH }?bandeja=revisar&estado=en_gestion` );
		await expect(
			page.locator( '.dcta-doc-nombre', { hasText: NAMES.created } )
		).toBeVisible();
	} );

	test( 'editor only works with in-scope documents', async ( { browser, baseURL } ) => {
		const { context, page } = await loginAs( browser, baseURL, EDITOR_LOGIN );

		try {
			await page.goto( APP_PATH );
			await expect( page.locator( '.dcta-h1' ) ).toHaveText( 'Mis documentos' );
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.inScope } ) ).toBeVisible();
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.pending } ) ).toBeVisible();
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.outOfScope } ) ).toHaveCount( 0 );

			await page.goto( `${ APP_PATH }?doc=${ docs.outOfScope }` );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /fuera de tu ámbito/ );

			await page.goto( `${ APP_PATH }?doc=${ docs.pending }&vista=editar` );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /bloqueado/ );

			await page.goto( `${ APP_PATH }?doc=${ docs.inScope }&vista=editar` );
			const renamed = `${ TITLES.inScope } editado`;
			await page.fill( '#documentate-app-titulo', renamed );
			await page.fill( '#documentate-app-nombre', `Corto ${ RUN }` );
			await fillRequiredAppFields( page, `Editor ${ RUN }` );
			await Promise.all( [
				page.waitForURL( /guardado=1/ ),
				page.getByRole( 'button', { name: 'Guardar borrador' } ).click(),
			] );
			await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText( /Cambios guardados/ );
			await expect( page.locator( '#documentate-app-titulo' ) ).toHaveValue( renamed );
			await expect( page.locator( '#documentate-app-nombre' ) ).toHaveValue( `Corto ${ RUN }` );

			// The list shows the internal name, not the official title.
			await page.goto( APP_PATH );
			await expect(
				page.locator( '.dcta-doc-nombre', { hasText: `Corto ${ RUN }` } )
			).toBeVisible();
		} finally {
			await context.close();
		}
	} );

	test( 'subscriber cannot use the application', async ( { browser, baseURL } ) => {
		const { context, page } = await loginAs( browser, baseURL, SUBSCRIBER_LOGIN );

		try {
			await page.goto( APP_PATH );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /no puede editar documentos/ );
		} finally {
			await context.close();
		}
	} );

	test( 'visitor is asked to sign in', async ( { browser, baseURL } ) => {
		// The browser fixture applies the admin storage state to new contexts.
		const context = await browser.newContext( { baseURL } );
		await context.clearCookies();
		const page = await context.newPage();

		try {
			await page.goto( APP_PATH );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /Inicia sesión/ );
		} finally {
			await context.close();
		}
	} );
} );
