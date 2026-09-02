/**
 * E2E tests for what each role sees in the application (/documentate/).
 *
 * The same site looks different to an área, to gestión documental and to
 * administración: different tabs, a badge only on the tray that is waiting for
 * them, different fields inside a document and different documents in the
 * lists. The gates for subscribers and visitors are covered by
 * documentate-app.spec.js and are not repeated here.
 */
const { test, expect } = require( '../fixtures' );
const {
	loginAs,
	crearEscenario,
	limpiarEscenario,
} = require( '../fixtures/site' );

const RUN = `roles${ Date.now() }`;
const AREA_LOGIN = `${ RUN }area`;
const GESTION_LOGIN = `${ RUN }gestion`;

const APP_PATH = '/documentate/';

const NOMBRES = {
	propio: `Propio ${ RUN }`,
	otroBorrador: `Otro borrador ${ RUN }`,
	otroEnGestion: `Otro en gestión ${ RUN }`,
	otroPendiente: `Otro pendiente ${ RUN }`,
};

/**
 * Locator of the list row of a document, found by its internal name.
 *
 * @param {import('@playwright/test').Page} page   Page showing a tray.
 * @param {string}                          nombre Internal name.
 * @return {import('@playwright/test').Locator} Row locator.
 */
function fila( page, nombre ) {
	return page.locator( '.dcta-fila', {
		has: page.locator( '.dcta-doc-nombre a', { hasText: nombre } ),
	} );
}

/**
 * The labels of the tab bar, whitespace-normalised.
 *
 * The badge of the actionable tab is part of the tab, so a tab that carries
 * one reads "Para revisar 4".
 *
 * @param {import('@playwright/test').Page} page Page showing the application.
 * @return {Promise<string[]>} Tab labels in order.
 */
async function pestanas( page ) {
	const textos = await page.locator( '.dcta-tab' ).allInnerTexts();

	return textos.map( ( texto ) => texto.replace( /\s+/g, ' ' ).trim() );
}

test.describe( 'Documentate app · roles', () => {
	let escenario;
	let otraCatId = 0;
	const docs = {};

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		escenario = crearEscenario( {
			categorias: {
				area: `Área ${ RUN }`,
				otra: `Otra área ${ RUN }`,
				gestion: `Gestión ${ RUN }`,
			},
			// The seeded Resolución declares gestión fields in its schema, so
			// it goes through gestión documental by itself: the spec reads
			// that property instead of writing the shared term.
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
				propio: {
					titulo: `Documento del área ${ RUN }`,
					categoria: 'area',
					tipo: 'res',
					autor: 'area',
					nombre: NOMBRES.propio,
				},
				otroBorrador: {
					titulo: `Borrador de otra área ${ RUN }`,
					categoria: 'otra',
					tipo: 'res',
					nombre: NOMBRES.otroBorrador,
				},
				otroEnGestion: {
					titulo: `En gestión de otra área ${ RUN }`,
					categoria: 'otra',
					tipo: 'res',
					estado: 'en_gestion',
					nombre: NOMBRES.otroEnGestion,
				},
				otroPendiente: {
					titulo: `Pendiente de otra área ${ RUN }`,
					categoria: 'otra',
					tipo: 'res',
					estado: 'pending',
					nombre: NOMBRES.otroPendiente,
				},
			},
		} );

		otraCatId = escenario.categorias.otra;
		Object.assign( docs, escenario.documentos );
		expect( escenario.tipos.res ).toBeGreaterThan( 0 );
	} );

	test.afterAll( async () => {
		test.setTimeout( 300_000 );

		// A beforeAll that threw (a WP-CLI lock that never cleared, an
		// unexpected answer) leaves the fixture unbuilt, and Playwright still
		// runs this hook: without the guard it dies dereferencing it and the
		// report shows that TypeError instead of the real failure.
		if ( ! escenario ) {
			return;
		}

		limpiarEscenario( {
			documentos: Object.values( escenario.documentos ),
			usuarios: [ AREA_LOGIN, GESTION_LOGIN ],
			categorias: Object.values( escenario.categorias ),
		} );
	} );

	test( 'el área tiene dos pestañas sin aviso y no ve los datos oficiales', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, AREA_LOGIN );

		try {
			await page.goto( APP_PATH );
			expect( await pestanas( page ) ).toEqual( [
				'Mis documentos',
				'Nuevo documento',
			] );
			// Nothing is ever waiting for the área: no tab carries a badge.
			await expect( page.locator( '.dcta-tab-n' ) ).toHaveCount( 0 );
			await expect( page.locator( '.dcta-rol' ) ).toHaveText(
				`Área · Área ${ RUN }`
			);
			await expect( page.locator( '.dcta-h1' ) ).toHaveText(
				'Mis documentos'
			);
			await expect( fila( page, NOMBRES.propio ) ).toHaveCount( 1 );

			await page.goto( `${ APP_PATH }?doc=${ docs.propio }&vista=editar` );
			// The área writes its own fields and never sees the official ones.
			await expect(
				page.locator( '#documentate_field_objeto' )
			).toHaveCount( 1 );
			await expect(
				page.locator( 'tr.documentate-campo-gestion' )
			).toHaveCount( 0 );
			await expect(
				page.locator( '#documentate_field_numero_resolucion' )
			).toHaveCount( 0 );
			await expect(
				page.locator( 'h3.documentate-seccion-rol' )
			).toHaveCount( 0 );
			await expect(
				page.locator( '#documentate-app-anotaciones' )
			).toHaveCount( 0 );

			// Another área's documents are out of reach, whatever their status.
			await page.goto( `${ APP_PATH }?doc=${ docs.otroEnGestion }` );
			await expect( page.locator( '.dcta-aviso' ) ).toContainText(
				'fuera de tu ámbito'
			);
		} finally {
			await context.close();
		}
	} );

	test( 'gestión tiene tres pestañas, aviso en «Para revisar» y ve los datos oficiales', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs(
			browser,
			baseURL,
			GESTION_LOGIN
		);

		try {
			await page.goto( APP_PATH );
			const tabs = await pestanas( page );
			expect( tabs ).toHaveLength( 3 );
			expect( tabs[ 0 ] ).toBe( 'Mis documentos' );
			expect( tabs[ 1 ] ).toMatch( /^Para revisar \d+$/ );
			expect( tabs[ 2 ] ).toBe( 'Nuevo documento' );
			await expect( page.locator( '.dcta-rol' ) ).toHaveText(
				'Gestión documental'
			);

			// The badge counts every document waiting in gestión, ours included.
			const badge = await page.locator( '.dcta-tab-n' ).innerText();
			expect( parseInt( badge, 10 ) ).toBeGreaterThanOrEqual( 1 );

			await page.goto(
				`${ APP_PATH }?doc=${ docs.otroEnGestion }&vista=editar&bandeja=revisar`
			);
			const camposGestion = page.locator( 'tr.documentate-campo-gestion' );
			expect( await camposGestion.count() ).toBeGreaterThan( 0 );
			await expect(
				page.locator( '#documentate_field_numero_resolucion' )
			).toBeVisible();
			await expect(
				page.locator( '#documentate-app-anotaciones' )
			).toHaveCount( 1 );
			await expect(
				page.locator( 'details.dcta-seccion-area' )
			).toContainText( 'Datos del área' );
		} finally {
			await context.close();
		}
	} );

	test( 'gestión no ve el borrador de otra área pero sí lo que ya salió de ella', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs(
			browser,
			baseURL,
			GESTION_LOGIN
		);

		try {
			// The review tray holds every área, but only from the moment a
			// document leaves its own: a draft still belongs to whoever wrote it.
			await page.goto( `${ APP_PATH }?bandeja=revisar&estado=todos` );
			await expect( page.locator( '.dcta-h1' ) ).toHaveText(
				'Para revisar'
			);
			await expect( fila( page, NOMBRES.otroEnGestion ) ).toHaveCount( 1 );
			await expect( fila( page, NOMBRES.otroPendiente ) ).toHaveCount( 1 );
			await expect( fila( page, NOMBRES.otroBorrador ) ).toHaveCount( 0 );
			await expect( fila( page, NOMBRES.propio ) ).toHaveCount( 0 );

			// Same rule when the document is asked for by ID.
			await page.goto( `${ APP_PATH }?doc=${ docs.otroBorrador }` );
			await expect( page.locator( '.dcta-aviso' ) ).toContainText(
				'fuera de tu ámbito'
			);

			await page.goto( `${ APP_PATH }?doc=${ docs.otroEnGestion }` );
			await expect( page.locator( '.dcta-h1' ) ).toContainText(
				NOMBRES.otroEnGestion
			);

			// "Mis documentos" stays inside the scope of gestión's own área.
			await page.goto( APP_PATH );
			await expect( fila( page, NOMBRES.otroEnGestion ) ).toHaveCount( 0 );
			await expect( fila( page, NOMBRES.propio ) ).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'administración tiene cuatro pestañas, contadores, chips y filtro de área', async ( {
		page,
	} ) => {
		await page.goto( APP_PATH );

		const tabs = await pestanas( page );
		expect( tabs ).toHaveLength( 4 );
		expect( tabs[ 0 ] ).toMatch( /^Para revisar \d+$/ );
		expect( tabs[ 1 ] ).toBe( 'Todos los documentos' );
		expect( tabs[ 2 ] ).toBe( 'Nuevo documento' );
		expect( tabs[ 3 ] ).toBe( 'Tipos y plantillas ↗' );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText(
			'Administración'
		);

		// Counters of the "todos" tray, the one administración approves from first.
		const etiquetas = await page.locator( '.dcta-cifra span' ).allInnerTexts();
		expect( etiquetas.map( ( t ) => t.trim() ) ).toEqual( [
			'En revisión',
			'En gestión',
			'Aprobados',
			'Devueltos',
		] );
		const enRevision = parseInt(
			await page.locator( '.dcta-cifra' ).first().locator( 'b' ).innerText(),
			10
		);
		expect( enRevision ).toBeGreaterThanOrEqual( 1 );

		// Administración sees every área at once.
		await expect( fila( page, NOMBRES.propio ) ).toHaveCount( 1 );
		await expect( fila( page, NOMBRES.otroBorrador ) ).toHaveCount( 1 );

		// The chips are the status filters that would find something.
		const chips = ( await page.locator( '.dcta-fchip' ).allInnerTexts() ).map(
			( texto ) => texto.trim()
		);
		expect( chips[ 0 ] ).toBe( 'Todos' );
		expect( chips ).toContain( 'Por enviar' );
		expect( chips ).toContain( 'En gestión' );

		await Promise.all( [
			page.waitForURL( /estado=en_gestion/ ),
			page
				.getByRole( 'link', { name: 'En gestión', exact: true } )
				.click(),
		] );
		const estados = (
			await page.locator( '.dcta-fila .dcta-estado' ).allInnerTexts()
		).map( ( texto ) => texto.trim() );
		expect( estados.length ).toBeGreaterThan( 0 );
		expect( [ ...new Set( estados ) ] ).toEqual( [ 'En gestión' ] );
		await expect( fila( page, NOMBRES.otroEnGestion ) ).toHaveCount( 1 );

		// The área filter narrows every tray to one category.
		await page.goto( APP_PATH );
		await page.selectOption( '#dcta-area', String( otraCatId ) );
		await Promise.all( [
			page.waitForURL( new RegExp( `area=${ otraCatId }` ) ),
			page.getByRole( 'button', { name: 'Filtrar' } ).click(),
		] );
		await expect( fila( page, NOMBRES.otroBorrador ) ).toHaveCount( 1 );
		await expect( fila( page, NOMBRES.otroPendiente ) ).toHaveCount( 1 );
		await expect( fila( page, NOMBRES.propio ) ).toHaveCount( 0 );
	} );
} );
