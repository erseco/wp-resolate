/**
 * E2E tests for the front-end application (/documentate/).
 *
 * Covers the admin-bar entry point, the create → edit → send for review flow,
 * the scope rules for a non-admin editor and the gates for subscribers and
 * visitors. Every string is matched in Spanish and English because the dev
 * site runs in es_ES while the plugin sources are English.
 */
const { test, expect } = require( '../fixtures' );
const {
	PASSWORD,
	runWpCmd,
	runWpCmdSafe,
	loginAs,
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

/**
 * Create a document with a scope category, type and author.
 *
 * @param {Object} opts            Options.
 * @param {string} opts.title      Post title.
 * @param {number} opts.categoryId Scope category term ID.
 * @param {number} opts.docTypeId  documentate_doc_type term ID.
 * @param {number} [opts.authorId] Post author.
 * @param {string} [opts.status]   Final post status (draft by default).
 * @return {number} Post ID.
 */
function createDocument( { title, categoryId, docTypeId, authorId, status = 'draft' } ) {
	let cmd = `post create --post_type=documentate_document --post_title="${ title }" --post_status=draft --post_category=${ categoryId } --porcelain`;
	if ( authorId ) {
		cmd += ` --post_author=${ authorId }`;
	}
	const postId = parseInt( runWpCmd( cmd ), 10 );

	runWpCmd( `post term set ${ postId } documentate_doc_type ${ docTypeId } --by=id` );
	runWpCmd( `post meta update ${ postId } documentate_locked_doc_type ${ docTypeId }` );

	// A type that goes through gestión documental cannot jump from draft to
	// "en revisión": the workflow refuses the transition, so the fixture walks
	// the same path a person would. The intermediate step is refused (and
	// harmless) for types that go straight to administración.
	if ( 'pending' === status ) {
		runWpCmd( `post update ${ postId } --post_status=en_gestion --user=1` );
	}

	if ( 'draft' !== status ) {
		runWpCmd( `post update ${ postId } --post_status=${ status } --user=1` );
	}

	return postId;
}

/**
 * Read one term meta, or an empty string when the term does not carry it.
 *
 * `wp term meta get` exits with an error when the key is absent, which is a
 * perfectly normal answer here.
 *
 * @param {number} termId Term ID.
 * @param {string} key    Meta key.
 * @return {string} Stored value, or an empty string.
 */
function readTermMeta( termId, key ) {
	try {
		return runWpCmd( `term meta get ${ termId } ${ key }` ).trim();
	} catch ( error ) {
		return '';
	}
}

/**
 * Fill every required field of the editor form so the browser lets it submit.
 * Native controls get `value`; rich editors get it through their code tab,
 * which is what the form posts while that tab is active.
 *
 * @param {import('@playwright/test').Page} page  Page on the edit view.
 * @param {string}                          value Text to put in the fields.
 */
async function fillRequiredFields( page, value ) {
	const form = page.locator( 'form.dcta-editor' );

	// Amounts documentate-calculos.js owns are readonly, and Playwright
	// refuses to fill those; they already carry the computed value. The two
	// fields of "Datos básicos" are filled by the test itself.
	const basicos = ':not(#documentate-app-titulo):not(#documentate-app-nombre)';
	const editables = `input[required]:not([type="hidden"]):not([readonly]):not([data-calculado])${ basicos }, textarea[required]:not([readonly])${ basicos }`;

	for ( const control of await form.locator( editables ).all() ) {
		const type = await control.getAttribute( 'type' );
		if ( 'checkbox' === type ) {
			await control.check();
		} else if ( 'number' === type ) {
			await control.fill( '1' );
		} else if ( 'date' === type ) {
			await control.fill( '2026-09-01' );
		} else {
			await control.fill( value );
		}
	}

	for ( const select of await form.locator( 'select[required]' ).all() ) {
		await select.selectOption( { index: 1 } );
	}

	for ( const wrap of await form.locator( '.documentate-rich-editor-wrap[data-required="true"]' ).all() ) {
		await wrap.locator( '.switch-html' ).click();
		await wrap.locator( 'textarea.wp-editor-area' ).fill( `<p>${ value }</p>` );
	}
}

test.describe( 'Documentate app', () => {
	let scopeCatId, otherCatId, docTypeId, editorId, conGestionPrevia;
	let createdDocType = false;
	const docs = {};
	const docIds = [];

	test.beforeAll( async () => {
		scopeCatId = parseInt(
			runWpCmd( `term create category "App Scope ${ RUN }" --porcelain` ),
			10
		);
		otherCatId = parseInt(
			runWpCmd( `term create category "App Other ${ RUN }" --porcelain` ),
			10
		);

		// Prefer the seeded type with a real template so the form has fields.
		const seeded = runWpCmd(
			'term list documentate_doc_type --slug=resolucion-administrativa --field=term_id'
		);
		docTypeId = parseInt( seeded, 10 );
		if ( Number.isNaN( docTypeId ) ) {
			docTypeId = parseInt(
				runWpCmd( `term create documentate_doc_type "App Type ${ RUN }" --porcelain` ),
				10
			);
			createdDocType = true;
		}

		editorId = parseInt(
			runWpCmd(
				`user create ${ EDITOR_LOGIN } ${ EDITOR_LOGIN }@example.com --role=editor --user_pass=${ PASSWORD } --porcelain`
			),
			10
		);
		// The application step this spec exercises is "goes through gestión
		// documental", which the type declares with a term meta. The dev site
		// may carry an older schema for the seeded type, so the flag is set
		// explicitly and restored afterwards.
		conGestionPrevia = readTermMeta( docTypeId, 'documentate_type_con_gestion' );
		runWpCmd( `term meta update ${ docTypeId } documentate_type_con_gestion 1` );

		runWpCmd( `user meta update ${ editorId } documentate_scope_term_id ${ scopeCatId }` );
		runWpCmd(
			`user create ${ SUBSCRIBER_LOGIN } ${ SUBSCRIBER_LOGIN }@example.com --role=subscriber --user_pass=${ PASSWORD }`
		);

		docs.inScope = createDocument( {
			title: TITLES.inScope,
			categoryId: scopeCatId,
			docTypeId,
			authorId: editorId,
		} );
		docs.outOfScope = createDocument( {
			title: TITLES.outOfScope,
			categoryId: otherCatId,
			docTypeId,
		} );
		docs.pending = createDocument( {
			title: TITLES.pending,
			categoryId: scopeCatId,
			docTypeId,
			authorId: editorId,
			status: 'pending',
		} );
		docIds.push( docs.inScope, docs.outOfScope, docs.pending );
	} );

	test.afterAll( async () => {
		const validDocIds = docIds.filter( ( id ) => ! Number.isNaN( id ) );
		if ( validDocIds.length ) {
			runWpCmdSafe( `post delete ${ validDocIds.join( ' ' ) } --force` );
		}
		runWpCmdSafe( `user delete ${ EDITOR_LOGIN } --yes --reassign=1` );
		runWpCmdSafe( `user delete ${ SUBSCRIBER_LOGIN } --yes --reassign=1` );
		runWpCmdSafe( `term delete category ${ scopeCatId } ${ otherCatId }` );
		if ( createdDocType ) {
			runWpCmdSafe( `term delete documentate_doc_type ${ docTypeId }` );
		} else if ( conGestionPrevia ) {
			runWpCmdSafe(
				`term meta update ${ docTypeId } documentate_type_con_gestion ${ conGestionPrevia }`
			);
		} else {
			runWpCmdSafe( `term meta delete ${ docTypeId } documentate_type_con_gestion` );
		}
	} );

	test( 'admin bar links to the application', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'index.php' );

		const node = page.locator( '#wp-admin-bar-documentate-app > a' );
		await expect( node ).toHaveText( 'Documentate' );
		await expect( node ).toHaveAttribute( 'href', /\/documentate\/?$/ );

		await node.click();
		await page.waitForURL( /\/documentate\/?(\?.*)?$/ );
		await expect( page.locator( '.dcta-h1' ) ).toHaveText( /Todos los documentos|All documents/ );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText( /Administración|Administration/ );
	} );

	test( 'administrator creates a document, saves the fields and sends it for review', async ( { page } ) => {
		await page.goto( `${ APP_PATH }?vista=nuevo` );

		await page.selectOption( '#documentate-app-tipo', String( docTypeId ) );
		await page.fill( '#documentate-app-nombre', NAMES.created );
		await page.fill( '#documentate-app-titulo', TITLES.created );
		await Promise.all( [
			page.waitForURL( /vista=editar/ ),
			page.getByRole( 'button', { name: /Crear borrador|Create draft/ } ).click(),
		] );

		const createdId = parseInt( new URL( page.url() ).searchParams.get( 'doc' ), 10 );
		expect( createdId ).toBeGreaterThan( 0 );
		docIds.push( createdId );

		await expect( page.locator( '#documentate-app-titulo' ) ).toHaveValue( TITLES.created );
		await expect( page.locator( '#documentate-app-nombre' ) ).toHaveValue( NAMES.created );
		await expect( page.locator( 'form.dcta-editor input[name="documentate_sections_nonce"]' ) ).toHaveCount( 1 );

		const value = `Valor ${ RUN }`;
		await fillRequiredFields( page, value );

		await Promise.all( [
			page.waitForURL( /guardado=1/ ),
			page.getByRole( 'button', { name: /Guardar borrador|Save draft/ } ).click(),
		] );
		await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText( /Cambios guardados|Changes saved/ );

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
		const confirmacion = page.getByRole( 'dialog' );
		await expect( confirmacion ).toBeVisible();
		await expect( confirmacion ).toContainText( /Ya no podrás modificarlo/ );

		await Promise.all( [
			page.waitForURL( /enviado=1/ ),
			confirmacion.getByRole( 'button', { name: 'Enviar a gestión' } ).click(),
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
			await expect( page.locator( '.dcta-h1' ) ).toHaveText( /Mis documentos|My documents/ );
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.inScope } ) ).toBeVisible();
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.pending } ) ).toBeVisible();
			await expect( page.locator( '.dcta-doc-nombre', { hasText: TITLES.outOfScope } ) ).toHaveCount( 0 );

			await page.goto( `${ APP_PATH }?doc=${ docs.outOfScope }` );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /fuera de tu ámbito|outside your scope/ );

			await page.goto( `${ APP_PATH }?doc=${ docs.pending }&vista=editar` );
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /bloqueado|locked/ );

			await page.goto( `${ APP_PATH }?doc=${ docs.inScope }&vista=editar` );
			const renamed = `${ TITLES.inScope } editado`;
			await page.fill( '#documentate-app-titulo', renamed );
			await page.fill( '#documentate-app-nombre', `Corto ${ RUN }` );
			await fillRequiredFields( page, `Editor ${ RUN }` );
			await Promise.all( [
				page.waitForURL( /guardado=1/ ),
				page.getByRole( 'button', { name: /Guardar borrador|Save draft/ } ).click(),
			] );
			await expect( page.locator( '.dcta-aviso-ok' ) ).toHaveText( /Cambios guardados|Changes saved/ );
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
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /no puede editar documentos|cannot edit documents/ );
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
			await expect( page.locator( '.dcta-aviso' ) ).toHaveText( /Inicia sesión|Sign in/ );
		} finally {
			await context.close();
		}
	} );
} );
