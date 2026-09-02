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
	createFixture,
	removeFixture,
} = require( '../fixtures/site' );

const RUN = `roles${ Date.now() }`;
const AREA_LOGIN = `${ RUN }area`;
const MANAGEMENT_LOGIN = `${ RUN }gestion`;

const APP_PATH = '/documentate/';

const NAMES = {
	own: `Propio ${ RUN }`,
	otherDraft: `Otro borrador ${ RUN }`,
	otherInManagement: `Otro en gestión ${ RUN }`,
	otherPending: `Otro pendiente ${ RUN }`,
};

/**
 * Locator of the list row of a document, found by its internal name.
 *
 * @param {import('@playwright/test').Page} page   Page showing a tray.
 * @param {string}                          name Internal name.
 * @return {import('@playwright/test').Locator} Row locator.
 */
function row( page, name ) {
	return page.locator( '.dcta-fila', {
		has: page.locator( '.dcta-doc-nombre a', { hasText: name } ),
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
async function tabLabels( page ) {
	const texts = await page.locator( '.dcta-tab' ).allInnerTexts();

	return texts.map( ( texto ) => texto.replace( /\s+/g, ' ' ).trim() );
}

test.describe( 'Documentate app · roles', () => {
	let fixture;
	let otherCatId = 0;
	const docs = {};

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		fixture = createFixture( {
			categories: {
				area: `Área ${ RUN }`,
				otra: `Otra área ${ RUN }`,
				management: `Gestión ${ RUN }`,
			},
			// The seeded Resolución declares gestión fields in its schema, so
			// it goes through gestión documental by itself: the spec reads
			// that property instead of writing the shared term.
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
				own: {
					title: `Documento del área ${ RUN }`,
					category: 'area',
					type: 'res',
					author: 'area',
					name: NAMES.own,
				},
				otherDraft: {
					title: `Borrador de otra área ${ RUN }`,
					category: 'otra',
					type: 'res',
					name: NAMES.otherDraft,
				},
				otherInManagement: {
					title: `En gestión de otra área ${ RUN }`,
					category: 'otra',
					type: 'res',
					status: 'en_gestion',
					name: NAMES.otherInManagement,
				},
				otherPending: {
					title: `Pendiente de otra área ${ RUN }`,
					category: 'otra',
					type: 'res',
					status: 'pending',
					name: NAMES.otherPending,
				},
			},
		} );

		otherCatId = fixture.categories.otra;
		Object.assign( docs, fixture.documents );
		expect( fixture.types.res ).toBeGreaterThan( 0 );
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
			documents: Object.values( fixture.documents ),
			users: [ AREA_LOGIN, MANAGEMENT_LOGIN ],
			categories: Object.values( fixture.categories ),
		} );
	} );

	test( 'the area gets two tabs with no badge and does not see the official data', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, AREA_LOGIN );

		try {
			await page.goto( APP_PATH );
			expect( await tabLabels( page ) ).toEqual( [
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
			await expect( row( page, NAMES.own ) ).toHaveCount( 1 );

			await page.goto( `${ APP_PATH }?doc=${ docs.own }&vista=editar` );
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
			await page.goto( `${ APP_PATH }?doc=${ docs.otherInManagement }` );
			await expect( page.locator( '.dcta-aviso' ) ).toContainText(
				'fuera de tu ámbito'
			);
		} finally {
			await context.close();
		}
	} );

	test( 'the quick filter really hides the rows that do not match', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, MANAGEMENT_LOGIN );

		try {
			await page.goto( `${ APP_PATH }?bandeja=revisar&estado=todos` );

			const rows = page.locator( '.dcta-fila:not(.dcta-fila-cab)' );
			const box = page.locator( '.dcta-busqueda-campo' );

			// Without the script the box would not be there: it only narrows
			// what the chips already brought.
			await expect( box ).toBeVisible();
			const total = await rows.count();
			expect( total ).toBeGreaterThan( 1 );

			// The footer is the only thing that changes as the filter runs,
			// so it is what a screen reader is told about.
			const footer = page.locator( '[data-dcta-pie]' );
			await expect( footer ).toHaveAttribute( 'role', 'status' );

			// The tray is capped at one page: what it really holds travels in
			// the footer, and the counts have to keep saying so.
			const inTray = parseInt(
				await footer.getAttribute( 'data-dcta-pie-total' ),
				10
			);
			expect( inTray ).toBeGreaterThanOrEqual( total );
			const queue =
				inTray > total
					? ` mostrados de ${ inTray } · afina con los filtros`
					: '';

			// A row is a grid, so hiding it takes more than the hidden
			// property: what matters is that it stops being on screen.
			await box.fill( NAMES.otherInManagement );
			await expect( rows.locator( 'visible=true' ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherInManagement ) ).toBeVisible();
			await expect( footer ).toHaveText( `1 de ${ total } documentos${ queue }` );

			await box.fill( 'zzz sin coincidencias zzz' );
			await expect( rows.locator( 'visible=true' ) ).toHaveCount( 0 );
			const emptyRow = page.locator( '.dcta-vacio' );
			await expect( emptyRow ).toBeVisible();
			if ( inTray > total ) {
				// The other rows were never looked at: "nothing matches" would
				// be a lie for the rest of the tray.
				await expect( emptyRow ).toContainText(
					`la bandeja tiene ${ inTray }`
				);
			}

			await box.fill( '' );
			await expect( rows.locator( 'visible=true' ) ).toHaveCount( total );
		} finally {
			await context.close();
		}
	} );

	test( 'the return dialog opens centred in the viewport', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, MANAGEMENT_LOGIN );

		try {
			await page.setViewportSize( { width: 1280, height: 900 } );
			await page.goto(
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar&bandeja=revisar`
			);

			await page.locator( 'button[data-motivo]' ).first().click();

			const dialog = page.getByRole( 'dialog' );
			await expect( dialog ).toBeVisible();

			// A modal <dialog> is centred by the `margin: auto` of the browser,
			// which the block layout of the page zeroes: without a rule of its
			// own the dialog ends up jammed against the top edge, over the
			// admin bar.
			const box = await dialog.boundingBox();
			expect( box.y ).toBeGreaterThan( 60 );
			const center = box.y + box.height / 2;
			expect( Math.abs( center - 450 ) ).toBeLessThan( 40 );
		} finally {
			await context.close();
		}
	} );

	test( 'on mobile the actions are not painted over the status card', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, MANAGEMENT_LOGIN );

		try {
			await page.setViewportSize( { width: 390, height: 780 } );
			await page.goto(
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar&bandeja=revisar`
			);

			const status = page.locator( '.dcta-editor-lado .dcta-card' ).first();
			const actions = page.locator( '.dcta-editor-acciones' );
			await expect( actions ).toBeVisible();

			// Here the rail is the last item of a single column, below the whole
			// form: a card stuck to `bottom: 0` never reaches the bottom of the
			// window and clamps over the «Estado» card instead.
			await expect( actions ).toHaveCSS( 'position', 'static' );

			const height = await page.evaluate(
				() => document.documentElement.scrollHeight
			);
			for ( const target of [ 0, Math.round( height / 2 ), height ] ) {
				await page.evaluate( ( y ) => window.scrollTo( 0, y ), target );
				const top = await status.boundingBox();
				const bottom = await actions.boundingBox();
				expect(
					top.y + top.height,
					`las tarjetas se solapan con scrollY=${ target }`
				).toBeLessThanOrEqual( bottom.y );
			}
		} finally {
			await context.close();
		}
	} );

	test( 'management gets three tabs, a badge on «Para revisar» and sees the official data', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs(
			browser,
			baseURL,
			MANAGEMENT_LOGIN
		);

		try {
			await page.goto( APP_PATH );
			const tabs = await tabLabels( page );
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
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar&bandeja=revisar`
			);
			const managementFields = page.locator( 'tr.documentate-campo-gestion' );
			expect( await managementFields.count() ).toBeGreaterThan( 0 );
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

	test( 'management does not see another area draft but does see what already left it', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs(
			browser,
			baseURL,
			MANAGEMENT_LOGIN
		);

		try {
			// The review tray holds every área, but only from the moment a
			// document leaves its own: a draft still belongs to whoever wrote it.
			await page.goto( `${ APP_PATH }?bandeja=revisar&estado=todos` );
			await expect( page.locator( '.dcta-h1' ) ).toHaveText(
				'Para revisar'
			);
			await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherPending ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 0 );
			await expect( row( page, NAMES.own ) ).toHaveCount( 0 );

			// Same rule when the document is asked for by ID.
			await page.goto( `${ APP_PATH }?doc=${ docs.otherDraft }` );
			await expect( page.locator( '.dcta-aviso' ) ).toContainText(
				'fuera de tu ámbito'
			);

			await page.goto( `${ APP_PATH }?doc=${ docs.otherInManagement }` );
			await expect( page.locator( '.dcta-h1' ) ).toContainText(
				NAMES.otherInManagement
			);

			// "Mis documentos" stays inside the scope of gestión's own área.
			await page.goto( APP_PATH );
			await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 0 );
			await expect( row( page, NAMES.own ) ).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'administration gets three tabs, counters, chips and the area filter', async ( {
		page,
	} ) => {
		await page.goto( APP_PATH );

		// The same three tabs gestión has, in the same order: moving between
		// the two roles must not move the tabs around. Document types live in
		// wp-admin, so no tab leaves the application.
		const tabs = await tabLabels( page );
		expect( tabs ).toHaveLength( 3 );
		expect( tabs[ 0 ] ).toBe( 'Todos los documentos' );
		expect( tabs[ 1 ] ).toMatch( /^Para revisar \d+$/ );
		expect( tabs[ 2 ] ).toBe( 'Nuevo documento' );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText(
			'Administración'
		);

		// Counters of the "todos" tray, the one administración approves from first.
		const labels = await page.locator( '.dcta-cifra span' ).allInnerTexts();
		expect( labels.map( ( t ) => t.trim() ) ).toEqual( [
			'En revisión',
			'En gestión',
			'Aprobados',
			'Devueltos',
		] );
		const inReview = parseInt(
			await page.locator( '.dcta-cifra' ).first().locator( 'b' ).innerText(),
			10
		);
		expect( inReview ).toBeGreaterThanOrEqual( 1 );

		// Administración sees every área at once.
		await expect( row( page, NAMES.own ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 1 );

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
		const statuses = (
			await page.locator( '.dcta-fila .dcta-estado' ).allInnerTexts()
		).map( ( texto ) => texto.trim() );
		expect( statuses.length ).toBeGreaterThan( 0 );
		expect( [ ...new Set( statuses ) ] ).toEqual( [ 'En gestión' ] );
		await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 1 );

		// The área filter narrows every tray to one category.
		await page.goto( APP_PATH );
		await page.selectOption( '#dcta-area', String( otherCatId ) );
		await Promise.all( [
			page.waitForURL( new RegExp( `area=${ otherCatId }` ) ),
			page.getByRole( 'button', { name: 'Filtrar' } ).click(),
		] );
		await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.otherPending ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.own ) ).toHaveCount( 0 );
	} );
} );
