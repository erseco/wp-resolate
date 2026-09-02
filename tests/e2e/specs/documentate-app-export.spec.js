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
const { crearEscenario, limpiarEscenario } = require( '../fixtures/site' );

const RUN = `export${ Date.now() }`;
const APP_PATH = '/documentate/';

/**
 * The controls the export block draws, and how each looks in both states.
 *
 * @type {Array<{nombre: string, activo: string, inactivo: string, texto: string}>}
 */
const CONTROLES = [
	{
		nombre: 'Previsualizar PDF',
		activo: 'a[data-documentate-action="preview"][data-documentate-format="pdf"]',
		inactivo: 'button.documentate-action-btn--preview[disabled]',
		texto: '',
	},
	{
		nombre: 'Descargar PDF',
		activo: 'a[data-documentate-action="download"][data-documentate-format="pdf"]',
		inactivo: 'button.documentate-action-btn--pdf[disabled]',
		texto: '',
	},
	{
		nombre: 'DOCX',
		activo: 'a[data-documentate-action="download"][data-documentate-format="docx"]',
		inactivo: '.documentate-actions-secondary button[disabled]',
		texto: 'DOCX',
	},
];

/**
 * Assert that one export control is either usable or disabled with a reason.
 *
 * @param {import('@playwright/test').Locator} bloque   The `#exportar` block.
 * @param {Object}                             control  One row of CONTROLES.
 * @return {Promise<void>}
 */
async function comprobarControl( bloque, control ) {
	const enlace = bloque.locator( control.activo );
	if ( await enlace.count() ) {
		await expect( enlace ).toHaveCount( 1 );
		// The buttons are AJAX driven: the real URL comes from the endpoint.
		await expect( enlace ).toHaveAttribute( 'href', '#' );
		return;
	}

	let boton = bloque.locator( control.inactivo );
	if ( '' !== control.texto ) {
		boton = boton.filter( { hasText: control.texto } );
	}

	await expect( boton ).toHaveCount( 1 );
	const razon = await boton.getAttribute( 'title' );
	expect( razon, `«${ control.nombre }» deshabilitado sin explicación` )
		.toBeTruthy();
	expect( razon.trim().length ).toBeGreaterThan( 0 );
}

test.describe( 'Documentate app · exportación', () => {
	let escenario;
	let docId = 0;

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		escenario = crearEscenario( {
			categorias: { export: `Export ${ RUN }` },
			tipos: { res: { slug: 'resolucion-administrativa' } },
			documentos: {
				exportable: {
					titulo: `Documento exportable ${ RUN }`,
					categoria: 'export',
					tipo: 'res',
					nombre: `Exportable ${ RUN }`,
				},
			},
		} );

		expect( escenario.tipos.res ).toBeGreaterThan( 0 );
		docId = escenario.documentos.exportable;
	} );

	test.afterAll( async () => {
		test.setTimeout( 300_000 );

		limpiarEscenario( {
			documentos: [ docId ],
			categorias: Object.values( escenario.categorias ),
		} );
	} );

	test( 'el detalle lleva el bloque de exportación con sus atributos', async ( {
		page,
	} ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }` );

		const bloque = page.locator( '#exportar' );
		await expect( bloque ).toBeVisible();
		await expect( bloque ).toHaveClass( /documentate-actions/ );
		await expect( bloque ).toHaveClass( /dcta-exportar/ );

		// The ODT comes from the template of the type, with no converter.
		const odt = bloque.locator(
			'a[data-documentate-action="download"][data-documentate-format="odt"]'
		);
		await expect( odt ).toHaveCount( 1 );
		await expect( odt ).toHaveText( 'ODT' );

		for ( const control of CONTROLES ) {
			await comprobarControl( bloque, control );
		}

		// The script that turns those attributes into a download is configured.
		const config = await page.evaluate(
			() => window.documentateActionsConfig || null
		);
		expect( config ).not.toBeNull();
		expect( parseInt( config.postId, 10 ) ).toBe( docId );
		expect( config.nonce ).toBeTruthy();
	} );

	test( 'el editor lleva el mismo bloque de exportación', async ( { page } ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }&vista=editar` );

		const bloque = page.locator( '#exportar' );
		await expect( bloque ).toBeVisible();
		// The block lives inside the editor form, so an export can save first.
		await expect(
			page.locator( 'form.dcta-editor #exportar' )
		).toHaveCount( 1 );

		await expect(
			bloque.locator(
				'a[data-documentate-action="download"][data-documentate-format="odt"]'
			)
		).toHaveCount( 1 );

		for ( const control of CONTROLES ) {
			await comprobarControl( bloque, control );
		}
	} );

	test( 'la generación ODT devuelve un fichero OpenDocument', async ( {
		page,
		request,
	} ) => {
		await page.goto( `${ APP_PATH }?doc=${ docId }` );

		const url = await getDownloadUrlViaAjax( page, 'odt' );
		expect( url ).toBeTruthy();

		const respuesta = await request.get( url );
		expect( respuesta.status() ).toBe( 200 );

		const tipo = respuesta.headers()[ 'content-type' ];
		expect(
			tipo.startsWith( 'application/vnd.oasis.opendocument.text' )
		).toBe( true );
		expect( respuesta.headers()[ 'content-disposition' ] ).toContain(
			'attachment'
		);
	} );
} );
