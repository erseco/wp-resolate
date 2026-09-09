/**
 * E2E tests for what each role sees in the application (/documentate/).
 *
 * The same site looks different to an área, to revisión, to the head of
 * service and to administración: different tabs, a badge only on the tray that
 * is waiting for them, different fields inside a document and different
 * documents in the lists. Visibility follows the scope tree: the área sits on
 * its own category, revisión and jefatura on the service above it. The gates
 * for subscribers and visitors are covered by documentate-app.spec.js and are
 * not repeated here.
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
const HEAD_LOGIN = `${ RUN }jefatura`;

const APP_PATH = '/documentate/';

const NAMES = {
	own: `Propio ${ RUN }`,
	otherDraft: `Otro borrador ${ RUN }`,
	otherInManagement: `Otro en gestión ${ RUN }`,
	otherPending: `Otro pendiente ${ RUN }`,
	headOwn: `De la jefatura ${ RUN }`,
	foreign: `Ajeno ${ RUN }`,
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
			// The scope is a tree: both áreas hang from the service, which is
			// where revisión and the head of service sit; a third área of
			// another service is out of everybody's reach but administración's.
			categories: {
				management: `Servicio ${ RUN }`,
				area: { name: `Área ${ RUN }`, parent: 'management' },
				otra: { name: `Otra área ${ RUN }`, parent: 'management' },
				ajena: `Área ajena ${ RUN }`,
			},
			// The seeded Documento 0 declares gestión fields in its schema,
			// so it goes through revisión by itself: the spec reads that
			// property instead of writing the shared term.
			types: { res: { slug: 'propuesta-gasto' } },
			users: {
				area: { login: AREA_LOGIN, role: 'author', scope: 'area' },
				management: {
					login: MANAGEMENT_LOGIN,
					role: 'editor',
					scope: 'management',
					management: true,
				},
				head: {
					login: HEAD_LOGIN,
					role: 'editor',
					scope: 'management',
					head: true,
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
				headOwn: {
					title: `Instrucciones de la jefatura ${ RUN }`,
					category: 'otra',
					type: 'res',
					author: 'head',
					name: NAMES.headOwn,
				},
				foreign: {
					title: `En revisión de otro servicio ${ RUN }`,
					category: 'ajena',
					type: 'res',
					status: 'en_gestion',
					name: NAMES.foreign,
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
			users: [ AREA_LOGIN, MANAGEMENT_LOGIN, HEAD_LOGIN ],
			categories: Object.values( fixture.categories ),
		} );
	} );

	test( 'the area gets two tabs and does not see the official data', async ( {
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
			// What waits for a rol is a chip of the list, not a tab: the área
			// opens on what it has still to send.
			await expect( page.locator( '.dcta-fchip-on' ) ).toContainText(
				'Por enviar'
			);
			await expect( page.locator( '.dcta-rol' ) ).toHaveText( 'Área' );
			await expect( page.locator( '.dcta-yo-ambito' ) ).toHaveText(
				`Área ${ RUN }`
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
				page.locator( '#documentate_field_partida' )
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
			await page.goto( `${ APP_PATH }?estado=todos` );

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

			// The list is capped at one page: what it really holds travels in
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
				// be a lie for the rest of the list.
				await expect( emptyRow ).toContainText(
					`la lista tiene ${ inTray }`
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
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar`
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
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar`
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

	test( 'management gets two tabs, opens on «En revisión» and sees the official data', async ( {
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
			expect( tabs ).toEqual( [ 'Todos los documentos', 'Nuevo documento' ] );
			await expect( page.locator( '.dcta-rol' ) ).toHaveText( 'Revisión' );
			await expect( page.locator( '.dcta-yo-ambito' ) ).toHaveText(
				`Servicio ${ RUN }`
			);
			// "Nuevo documento" is the one tab that carries an icon: the plus.
			await expect(
				page.locator( '.dcta-tab-nuevo svg.dcta-icono-plus' )
			).toHaveCount( 1 );

			// The list opens on what waits for review, and the chip counts it.
			const chip = page.locator( '.dcta-fchip-on' );
			await expect( chip ).toContainText( 'En revisión' );
			expect(
				parseInt( await chip.locator( '.dcta-fchip-n' ).innerText(), 10 )
			).toBeGreaterThanOrEqual( 1 );

			await page.goto(
				`${ APP_PATH }?doc=${ docs.otherInManagement }&vista=editar`
			);
			const managementFields = page.locator( 'tr.documentate-campo-gestion' );
			expect( await managementFields.count() ).toBeGreaterThan( 0 );
			await expect(
				page.locator( '#documentate_field_partida' )
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

	test( 'management sees every document of its scope and nothing of another service', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs(
			browser,
			baseURL,
			MANAGEMENT_LOGIN
		);

		try {
			// The chip of the rol holds what waits for review, and nothing else.
			await page.goto( APP_PATH );
			await expect( page.locator( '.dcta-h1' ) ).toHaveText(
				'Todos los documentos'
			);
			await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherPending ) ).toHaveCount( 0 );
			await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 0 );

			// "Todos" is the whole scope, drafts of both áreas included.
			await page.goto( `${ APP_PATH }?estado=todos` );
			await expect( row( page, NAMES.own ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherPending ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.foreign ) ).toHaveCount( 0 );

			// Another service is closed even by ID, pipeline or not.
			await page.goto( `${ APP_PATH }?doc=${ docs.foreign }` );
			await expect( page.locator( '.dcta-aviso' ) ).toContainText(
				'fuera de tu ámbito'
			);

			// A document that moved on to the head of service is locked for
			// revisión: the notice says who has it, and Editar is greyed out.
			await page.goto( `${ APP_PATH }?doc=${ docs.otherPending }` );
			await expect( page.locator( '.dcta-aviso-bloqueo' ) ).toContainText(
				'Lo tiene la jefatura de servicio'
			);
			await expect( page.locator( '.dcta-btn-off' ) ).toHaveText( /Editar/ );
			await expect(
				page.locator( 'a.dcta-btn-pri', { hasText: 'Editar' } )
			).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'the head of service approves from «En aprobación» without being an administrator', async ( {
		browser,
		baseURL,
	} ) => {
		const { context, page } = await loginAs( browser, baseURL, HEAD_LOGIN );

		try {
			await page.goto( APP_PATH );
			expect( await tabLabels( page ) ).toEqual( [
				'Todos los documentos',
				'Nuevo documento',
			] );
			await expect( page.locator( '.dcta-rol' ) ).toHaveText(
				'Jefatura de servicio'
			);
			// Not an administrator: no toolbar, no wp-admin shortcut.
			await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );

			// The list opens on what waits for their approval.
			await expect( page.locator( '.dcta-fchip-on' ) ).toContainText(
				'En aprobación'
			);
			await expect( row( page, NAMES.otherPending ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 0 );

			// "Mis documentos" is the other side of the same row: what they
			// wrote themselves, whatever status it ended up in.
			await Promise.all( [
				page.waitForURL( /estado=mios/ ),
				page.locator( 'a.dcta-fchip[href*="estado=mios"]' ).click(),
			] );
			await expect( page.locator( '.dcta-fchip-on' ) ).toContainText(
				'Mis documentos'
			);
			await expect( row( page, NAMES.headOwn ) ).toHaveCount( 1 );
			await expect( row( page, NAMES.otherPending ) ).toHaveCount( 0 );

			// What is still in revisión is not theirs yet.
			await page.goto( `${ APP_PATH }?doc=${ docs.otherInManagement }` );
			await expect( page.locator( '.dcta-aviso-bloqueo' ) ).toHaveCount( 0 );
			await expect( page.locator( '.dcta-btn-off' ) ).toHaveCount( 0 );

			// What waits for approval is: the editor offers the approval.
			await page.goto(
				`${ APP_PATH }?doc=${ docs.otherPending }&vista=editar`
			);
			await expect(
				page.getByRole( 'button', { name: 'Aprobar' } )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Devolver…' } )
			).toBeVisible();
		} finally {
			await context.close();
		}
	} );

	test( 'administration gets two tabs, chips with counts and the area filter', async ( {
		page,
	} ) => {
		await page.goto( `${ APP_PATH }?estado=todos` );

		// The same two tabs the head of service has, in the same order:
		// moving between the two roles must not move the tabs around.
		// Document types live in wp-admin, so no tab leaves the application.
		expect( await tabLabels( page ) ).toEqual( [
			'Todos los documentos',
			'Nuevo documento',
		] );
		await expect( page.locator( '.dcta-rol' ) ).toHaveText(
			'Administración'
		);

		// Administración sees every área at once.
		await expect( row( page, NAMES.own ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 1 );

		// The chips are the status filters that would find something, each
		// with what it holds; the one of the rol is marked.
		const chips = ( await page.locator( '.dcta-fchip' ).allInnerTexts() ).map(
			( texto ) => texto.replace( /\s+/g, ' ' ).trim()
		);
		expect( chips[ 0 ] ).toMatch( /^Todos \d+$/ );
		expect( chips.some( ( c ) => /^Por enviar \d+$/.test( c ) ) ).toBe( true );
		expect( chips.some( ( c ) => /^En revisión \d+$/.test( c ) ) ).toBe( true );
		await expect( page.locator( '.dcta-fchip-mio' ) ).toContainText(
			'En aprobación'
		);

		// The count is part of the chip, so the chip is located by where it
		// leads rather than by its accessible name.
		await Promise.all( [
			page.waitForURL( /estado=en_gestion/ ),
			page.locator( 'a.dcta-fchip[href*="estado=en_gestion"]' ).click(),
		] );
		const statuses = (
			await page.locator( '.dcta-fila .dcta-estado' ).allInnerTexts()
		).map( ( texto ) => texto.trim() );
		expect( statuses.length ).toBeGreaterThan( 0 );
		expect( [ ...new Set( statuses ) ] ).toEqual( [ 'En revisión' ] );
		await expect( row( page, NAMES.otherInManagement ) ).toHaveCount( 1 );

		// The área filter narrows the list to one category, and picking one is
		// the whole interaction: the script submits, the button stays hidden.
		await page.goto( `${ APP_PATH }?estado=todos` );
		await expect(
			page.getByRole( 'button', { name: 'Filtrar' } )
		).toBeHidden();
		await Promise.all( [
			page.waitForURL( new RegExp( `area=${ otherCatId }` ) ),
			page.selectOption( '#dcta-area', String( otherCatId ) ),
		] );
		await expect( row( page, NAMES.otherDraft ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.otherPending ) ).toHaveCount( 1 );
		await expect( row( page, NAMES.own ) ).toHaveCount( 0 );
	} );
} );
