/**
 * E2E tests for the export block of the application (/documentate/).
 *
 * The document view and the editor both carry the same controls the wp-admin
 * metabox has (preview, PDF, ODT, DOCX), wrapped in the `#exportar` anchor the
 * lists link to. What a given site can actually generate depends on its
 * conversion engine, so only ODT — which comes straight from the template of
 * the document type — is required to work; the rest must be either enabled or
 * disabled with a reason the user can read.
 */
const { test, expect, getDownloadUrlViaAjax } = require( '../fixtures' );
const { createFixture, removeFixture } = require( '../fixtures/site' );

const RUN = `export${ Date.now() }`;
const APP_PATH = '/documentate/';

/**
 * The controls the export block draws, and how each looks in both states.
 *
 * @type {Array<{name: string, activo: string, inactivo: string, texto: string}>}
 */
const CONTROLS = [
	{
		name: 'Previsualizar PDF',
		activo: 'a[data-documentate-action="preview"][data-documentate-format="pdf"]',
		inactivo: 'button.documentate-action-btn--preview[disabled]',
		texto: '',
	},
	{
		name: 'Descargar PDF',
		activo: 'a[data-documentate-action="download"][data-documentate-format="pdf"]',
		inactivo: 'button.documentate-action-btn--pdf[disabled]',
		texto: '',
	},
	{
		name: 'DOCX',
		activo: 'a[data-documentate-action="download"][data-documentate-format="docx"]',
		inactivo: '.documentate-actions-secondary button[disabled]',
		texto: 'DOCX',
	},
];

/**
 * Assert that one export control is either usable or disabled with a reason.
 *
 * @param {import('@playwright/test').Locator} block   The `#exportar` block.
 * @param {Object}                             control  One row of CONTROLS.
 * @return {Promise<void>}
 */
async function checkControl( block, control ) {
	const link = block.locator( control.activo );
	if ( await link.count() ) {
		await expect( link ).toHaveCount( 1 );
		// The buttons are AJAX driven: the real URL comes from the endpoint.
		await expect( link ).toHaveAttribute( 'href', '#' );
		return;
	}

	let button = block.locator( control.inactivo );
	if ( '' !== control.texto ) {
		button = button.filter( { hasText: control.texto } );
	}

	await expect( button ).toHaveCount( 1 );
	const reason = await button.getAttribute( 'title' );
	expect( reason, `«${ control.name }» deshabilitado sin explicación` )
		.toBeTruthy();
	expect( reason.trim().length ).toBeGreaterThan( 0 );
}

test.describe( 'Documentate app · export', () => {
	let fixture;
	let docId = 0;

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		fixture = createFixture( {
			categories: { export: `Export ${ RUN }` },
			types: { res: { slug: 'resolucion-administrativa' } },
			documents: {
				exportable: {
					title: `Documento exportable ${ RUN }`,
					category: 'export',
					type: 'res',
					name: `Exportable ${ RUN }`,
				},
			},
		} );

		expect( fixture.types.res ).toBeGreaterThan( 0 );
		docId = fixture.documents.exportable;
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
			documents: [ docId ],
			categories: Object.values( fixture.categories ),
		} );
	} );

	test( 'the document view carries the export block with its attributes', async ( {
		page,
	} ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }` );

		const block = page.locator( '#exportar' );
		await expect( block ).toBeVisible();
		await expect( block ).toHaveClass( /documentate-actions/ );
		await expect( block ).toHaveClass( /dcta-exportar/ );

		// The ODT comes from the template of the type, with no converter.
		const odt = block.locator(
			'a[data-documentate-action="download"][data-documentate-format="odt"]'
		);
		await expect( odt ).toHaveCount( 1 );
		await expect( odt ).toHaveText( 'ODT' );

		for ( const control of CONTROLS ) {
			await checkControl( block, control );
		}

		// The script that turns those attributes into a download is configured.
		const config = await page.evaluate(
			() => window.documentateActionsConfig || null
		);
		expect( config ).not.toBeNull();
		expect( parseInt( config.postId, 10 ) ).toBe( docId );
		expect( config.nonce ).toBeTruthy();
	} );

	test( 'the editor carries the same export block', async ( { page } ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }&vista=editar` );

		const block = page.locator( '#exportar' );
		await expect( block ).toBeVisible();
		// The block lives inside the editor form, so an export can save first.
		await expect(
			page.locator( 'form.dcta-editor #exportar' )
		).toHaveCount( 1 );

		await expect(
			block.locator(
				'a[data-documentate-action="download"][data-documentate-format="odt"]'
			)
		).toHaveCount( 1 );

		for ( const control of CONTROLS ) {
			await checkControl( block, control );
		}
	} );

	test( 'the editor warns about unsaved changes and its modal is styled', async ( {
		page,
	} ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }&vista=editar` );

		const notice = page.locator( '#exportar .documentate-unsaved-indicator' );
		await expect( notice ).toHaveCount( 1 );
		await expect( notice ).toBeHidden();

		// The guard only subscribes when the indicator is on the page, so this
		// is what tells the user before the export is blocked.
		await page.locator( '#documentate-app-nombre' ).fill(
			`Exportable ${ RUN } tocado`
		);
		await expect( notice ).toBeVisible();
		await expect( notice ).toHaveText( 'Cambios sin guardar' );

		await page
			.locator(
				'#exportar [data-documentate-action="download"][data-documentate-format="odt"]'
			)
			.click();

		const modal = page.locator( '.documentate-unsaved-modal.is-visible' );
		await expect( modal ).toBeVisible();

		// wp-admin's buttons.css is not loaded here and the modal hangs off
		// <body>, outside the sheet: without a rule of its own every button in
		// it renders as a raw OS control.
		const main = modal.locator( '.documentate-unsaved-modal__primary' );
		await expect( main ).toHaveCSS( 'border-radius', '999px' );
		await expect( main ).toHaveCSS( 'background-color', 'rgb(27, 79, 138)' );
		await expect( main ).toHaveCSS( 'color', 'rgb(255, 255, 255)' );

		const height = await main.evaluate(
			( button ) => button.getBoundingClientRect().height
		);
		expect( height ).toBeGreaterThanOrEqual( 40 );

		const cancelButton = modal.locator( '.documentate-unsaved-modal__cancel' );
		await expect( cancelButton ).toHaveCSS( 'border-top-width', '0px' );

		await cancelButton.click();
		await expect( modal ).toHaveCount( 0 );
	} );

	test( 'ODT generation returns an OpenDocument file', async ( {
		page,
		request,
	} ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }` );

		const url = await getDownloadUrlViaAjax( page, 'odt' );
		expect( url ).toBeTruthy();

		const response = await request.get( url );
		expect( response.status() ).toBe( 200 );

		const type = response.headers()[ 'content-type' ];
		expect(
			type.startsWith( 'application/vnd.oasis.opendocument.text' )
		).toBe( true );
		expect( response.headers()[ 'content-disposition' ] ).toContain(
			'attachment'
		);
	} );
} );
