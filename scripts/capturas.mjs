/**
 * Screenshot script for the full life cycle of a document.
 *
 * Walks the application with Playwright at two screen sizes and leaves an HTML
 * report with the annotated screenshots: it verifies that the cycle works end
 * to end after every change, and doubles as the basis of the user manual.
 *
 * The script is the SCENES constant below. Every scene says who logs in, where
 * they go, what they do and what is being shown; adding a step to the manual
 * means adding a scene, not touching the engine.
 *
 * The document that walks the cycle is the demo one, «PG · Material aulas
 * digitales»: the área completes and sends it, gestión documental fills in the
 * official data and returns it, the área corrects it, gestión passes it to
 * administración and administración approves it. The demo is seeded again
 * before each screen size, so both passes start from the same state.
 *
 * Usage:  make capturas                      (everything)
 *         make capturas SOLO=movil           (mobile only)
 *         DOCUMENTATE_SIN_SEMBRAR=1 …        (do not reseed; use existing data)
 *
 * Never at the same time as the E2E suite: both write to the development site.
 */

import { chromium, devices } from '@playwright/test';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const BASE = process.env.DOCUMENTATE_URL || 'http://localhost:8989';
const OUT = process.env.DOCUMENTATE_CAPTURAS || 'capturas';
const SOLO = process.env.SOLO || '';

const USERS = {
	area: { user: 'author1', pass: 'password', label: 'Área · Departamento de Proyectos' },
	gestion: { user: 'editor1', pass: 'password', label: 'Gestión documental' },
	admin: { user: 'admin', pass: 'password', label: 'Administración' },
};

const SCREENS = [
	{ id: 'escritorio', label: 'Ordenador', viewport: { width: 1440, height: 900 } },
	{ id: 'movil', label: 'Móvil', ...devices[ 'iPhone 13' ], deviceScaleFactor: 2 },
];

/** Demo document that walks the whole cycle. */
const CYCLE = 'Material aulas digitales';

/** Demo document with providers and computed totals ("document 0"). */
const PROVIDERS = 'Renovación licencias aulas virtuales';

/** Demo document still in gestión by the time wp-admin is reached. */
const IN_MANAGEMENT = 'Listado definitivo piloto innovación';

/** Reason gestión documental gives when returning the cycle document. */
const REASON = 'Falta el desglose por proveedores y la partida presupuestaria.';

/** Pending document that administración returns, picking a target. */
const PENDING = 'Formación profesorado metodologías';

/**
 * Minimal but real PDF: attachment validation sniffs the content, so any old
 * string will not do.
 */
const PDF = Buffer.from(
	'%PDF-1.4\n' +
		'1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' +
		'2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n' +
		'trailer<</Root 1 0 R>>\n' +
		'%%EOF'
);

/**
 * The script. `run` receives the page and may navigate, fill and click; if it
 * returns false the scene is marked "revisar" in the report (for instance, a
 * button that is missing because the previous step never completed).
 *
 * `viewportOnly: true` captures only the visible window instead of the whole
 * page: that is what the scenes showing a dialog need, because a modal
 * <dialog> and its backdrop live in the top layer and are the size of the
 * window, not the size of the page.
 */
const SCENES = [
	{
		chapter: 'Entrada',
		title: 'Una sola dirección para todo el mundo',
		text: 'Todo el mundo entra por /documentate/. El área aparece directamente en sus documentos, con el rol y el ámbito escritos en la cabecera; no hay que acordarse de qué pantalla tocaba.',
		as: 'area',
		run: async ( p ) => {
			return await goTo( p, '/documentate/' );
		},
	},
	{
		chapter: 'Entrada',
		title: 'La misma puerta, para gestión documental',
		text: 'Gestión documental entra por la misma URL y ve, además de sus propios documentos y de «Nuevo documento», la bandeja «Para revisar», con el número de los que esperan a que complete los datos oficiales.',
		as: 'gestion',
		run: async ( p ) => {
			return await goTo( p, '/documentate/' );
		},
	},
	{
		chapter: 'Entrada',
		title: 'La misma puerta, para administración',
		text: 'Administración aterriza en todos los documentos de todas las áreas; como los ve todos, tiene un selector de área —aquí acotado al Departamento de Proyectos— y un acceso directo a los tipos y plantillas de wp-admin. El aviso de la pestaña cuenta todo lo que espera revisión; los contadores de debajo obedecen al filtro.',
		as: 'admin',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/documentate/' ) ) ) return false;
			const select = p.locator( '#dcta-area' );
			if ( ! ( await select.count() ) ) return false;
			await select.selectOption( { label: 'Departamento de Proyectos' } );
			return await click( p, p.locator( '.dcta-areas button[type="submit"]' ) );
		},
	},

	{
		chapter: 'El área prepara el documento',
		title: 'Mis documentos: contadores y filtros',
		text: 'Los contadores dicen qué hay por enviar, qué está en gestión, qué espera aprobación y qué se aprobó ya; los chips filtran la lista sin salir de la página. Cada fila lleva el nombre corto con su prefijo, el título oficial y el estado.',
		as: 'area',
		run: async ( p ) => {
			return await goTo( p, '/documentate/?estado=draft' );
		},
	},
	{
		chapter: 'El área prepara el documento',
		title: 'Nuevo documento',
		text: 'Crear un documento son tres decisiones: el tipo (que ya no se cambia), un nombre corto para las listas y el título oficial que saldrá en el papel. Al elegir el tipo, la ayuda dice si pasa por gestión documental y aparece el prefijo delante del nombre.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/documentate/?vista=nuevo' ) ) ) return false;
			await p.selectOption( '#documentate-app-tipo', { label: 'Resolución Administrativa' } );
			await p.fill( '#documentate-app-nombre', 'Ayudas al transporte escolar' );
			await p.fill(
				'#documentate-app-titulo',
				'Resolución por la que se convocan las ayudas al transporte escolar del curso 2026-2027'
			);
			await p.locator( '#documentate-app-tipo-nota' ).waitFor();
			return true;
		},
	},
	{
		chapter: 'El área prepara el documento',
		title: 'Completar el borrador y adjuntar el fichero',
		text: 'El editor del área tiene los datos básicos, los campos de la plantilla y el fichero del documento: se arrastra al recuadro o se elige a mano, y se sube al guardar. Los campos que solo rellena gestión documental no están aquí.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE ) ) ) return false;
			// The decree letter is a single letter: the demo value does not
			// qualify and the browser would refuse to submit the form.
			await fillField( p, '#documentate_field_letra_decreto', 'B' );
			// A freshly picked file, so the "will be uploaded on save" state
			// the caption talks about is visible.
			const attachment = p.locator( 'input[name="documentate_app_adjunto"]' );
			if ( await attachment.count() ) {
				await attachment.first().setInputFiles( {
					name: 'acta-material-aulas-digitales.pdf',
					mimeType: 'application/pdf',
					buffer: PDF,
				} );
			}
			return await save( p );
		},
	},
	{
		chapter: 'El área prepara el documento',
		title: 'Enviar a gestión, con confirmación',
		text: 'Enviar es la decisión que cierra el documento para el área, así que se pregunta antes. La ventana dice exactamente qué va a pasar: lo completará gestión documental y ya no se podrá modificar hasta que lo devuelvan.',
		as: 'area',
		viewportOnly: true,
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE ) ) ) return false;
			return await openConfirmation( p, 'enviar_gestion' );
		},
	},
	{
		chapter: 'El área prepara el documento',
		title: 'El documento queda en gestión documental',
		text: 'Confirmado, el documento sale del área. La ficha lo dice arriba y el indicador de estado marca en qué punto del recorrido está: borrador, en gestión, en revisión, aprobado.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE ) ) ) return false;
			if ( ! ( await openConfirmation( p, 'enviar_gestion' ) ) ) return false;
			return await click( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		chapter: 'Gestión documental completa',
		title: 'La bandeja de gestión',
		text: 'La bandeja «Para revisar» reúne los documentos de todas las áreas que ya salieron de su borrador. Bajo el nombre corto van el título oficial y el área y la persona que lo firma, y el clip marca los que traen fichero.',
		as: 'gestion',
		run: async ( p ) => {
			return await goTo( p, '/documentate/?bandeja=revisar' );
		},
	},
	{
		chapter: 'Gestión documental completa',
		title: 'Los datos oficiales, que solo ve gestión',
		text: 'Gestión abre el mismo editor con una sección más: los campos marcados como de gestión en la plantilla —el gasto en letra y en cifra, la partida presupuestaria— y unas anotaciones internas que no salen en el documento. Los datos del área quedan plegados, a la vista pero fuera del camino.',
		as: 'gestion',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE, 'revisar' ) ) ) return false;
			await pickField( p, '#documentate_field_partida', '18.03.322B.229.0100' );
			await fillField( p, '#documentate_field_gasto_numero', '1875' );
			await fillField(
				p,
				'#documentate_field_gasto_letra',
				'mil ochocientos setenta y cinco euros'
			);
			await fillField(
				p,
				'#documentate-app-anotaciones',
				'Comprobado con intervención: falta el desglose por proveedores antes de pasarlo a administración.'
			);
			if ( ! ( await save( p ) ) ) return false;
			await focusManagement( p );
			return true;
		},
	},
	{
		chapter: 'Gestión documental completa',
		title: 'Proveedores y totales que se calculan solos',
		text: 'En la propuesta de gasto cada proveedor lleva sus conceptos: cantidad por precio da el total de la línea, la suma de líneas el bruto, y con el IGIC y el IRPF sale el total del proveedor. El resumen de la propuesta y el importe en cifra se escriben solos.',
		as: 'gestion',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, PROVIDERS ) ) ) return false;
			await focusManagement( p );
			const summary = p.locator( '.dcta-resumen' );
			if ( ! ( await summary.count() ) ) return false;
			await summary.first().waitFor();
		},
	},
	{
		chapter: 'Gestión documental completa',
		title: 'Devolver al área, diciendo por qué',
		text: 'Si falta algo, el documento vuelve al área. El motivo es obligatorio: se manda por correo, queda en la actividad y es lo primero que ve quien lo escribió.',
		as: 'gestion',
		viewportOnly: true,
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE, 'revisar' ) ) ) return false;
			await focusManagement( p );
			return await openReasonDialog( p, 'devolver_area', REASON );
		},
	},
	{
		chapter: 'Gestión documental completa',
		title: 'Devuelto',
		text: 'Tras devolverlo, gestión vuelve a su bandeja con el aviso de que salió: el documento ya no le corresponde hasta que el área lo reenvíe.',
		as: 'gestion',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE, 'revisar' ) ) ) return false;
			if ( ! ( await openReasonDialog( p, 'devolver_area', REASON ) ) ) return false;
			return await click( p, p.locator( '#dcta-dialogo-motivo-ok' ) );
		},
	},

	{
		chapter: 'El área corrige',
		title: 'El documento vuelve, con su motivo',
		text: 'En la lista del área la fila devuelta se tiñe y lleva debajo quién lo devolvió, cuándo y por qué. La acción de la fila deja de ser «Continuar» y pasa a ser «Corregir».',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/documentate/' ) ) ) return false;
			const row = rowOf( p, CYCLE );
			if ( ! ( await row.count() ) ) return false;
			return ( await row.locator( '.dcta-doc-motivo' ).count() ) > 0;
		},
	},
	{
		chapter: 'El área corrige',
		title: 'Corregir lo que falta',
		text: 'El editor vuelve a abrirse con el motivo arriba del todo y los campos otra vez editables. Los datos oficiales que rellenó gestión siguen ahí, pero el área no los ve ni los puede tocar.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE ) ) ) return false;
			return ( await p.locator( '.dcta-aviso-devuelto' ).count() ) > 0;
		},
	},
	{
		chapter: 'El área corrige',
		title: 'Reenviado a gestión',
		text: 'Corregido, se vuelve a enviar por el mismo camino y con la misma confirmación. La marca de devuelto desaparece: el documento está otra vez en gestión documental.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE ) ) ) return false;
			if ( ! ( await openConfirmation( p, 'enviar_gestion' ) ) ) return false;
			return await click( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		chapter: 'De gestión a administración',
		title: 'Pasar a administración',
		text: 'Cuando los datos oficiales están completos, gestión lo pasa a administración —también con su confirmación, como al enviarlo—. Hecho eso, gestión tampoco puede modificarlo: la ficha ya dice «En revisión».',
		as: 'gestion',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE, 'revisar' ) ) ) return false;
			if ( ! ( await openConfirmation( p, 'pasar_admin' ) ) ) return false;
			return await click( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},
	{
		chapter: 'De gestión a administración',
		title: 'La bandeja de revisión',
		text: 'Administración tiene su propia bandeja con lo que espera aprobación, los mismos chips de estado y un selector de área para acotar cuando hay muchos: aquí, el Departamento de Proyectos.',
		as: 'admin',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/documentate/?bandeja=revision' ) ) ) return false;
			const select = p.locator( '#dcta-area' );
			if ( ! ( await select.count() ) ) return false;
			await select.selectOption( { label: 'Departamento de Proyectos' } );
			return await click( p, p.locator( '.dcta-areas button[type="submit"]' ) );
		},
	},
	{
		chapter: 'De gestión a administración',
		title: 'Devolver, eligiendo a quién',
		text: 'Administración es la única que puede devolver a dos sitios: a gestión documental, para que rehaga los datos oficiales, o directamente al área. Una sola ventana pregunta a quién y por qué; el motivo sigue siendo obligatorio.',
		as: 'admin',
		viewportOnly: true,
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, PENDING, 'revision' ) ) ) return false;
			if ( ! ( await openReasonDialog( p, 'devolver_area', 'Falta el desglose por partidas del capítulo II.' ) ) ) {
				return false;
			}
			// The dialog stays open: the scene shows it, it does not submit it.
			return ( await p.locator( '.dcta-dialogo-destinos input[type="radio"]:not([disabled])' ).count() ) === 2;
		},
	},
	{
		chapter: 'De gestión a administración',
		title: 'Aprobar y publicar',
		text: 'Aprobar publica el documento y lo cierra: a partir de ahí solo se consulta y se descarga. La ficha lo dice arriba y el indicador de estado llega al final del recorrido.',
		as: 'admin',
		run: async ( p ) => {
			if ( ! ( await goToEdit( p, CYCLE, 'revision' ) ) ) return false;
			if ( ! ( await openConfirmation( p, 'aprobar' ) ) ) return false;
			return await click( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		chapter: 'El documento terminado',
		title: 'Previsualizar y descargar',
		text: 'El documento aprobado se genera desde su plantilla: vista previa en PDF y descarga en PDF, ODT o DOCX. Los formatos que necesitan conversor aparecen desactivados y con el motivo en el título cuando el entorno no lo tiene.',
		as: 'area',
		run: async ( p ) => {
			if ( ! ( await goToDetail( p, CYCLE ) ) ) return false;
			return ( await p.locator( '#exportar' ).count() ) > 0;
		},
	},
	{
		chapter: 'El documento terminado',
		title: 'La actividad del documento',
		text: 'Todo lo que le pasó al documento queda escrito: quién creó el borrador, quién adjuntó el fichero, quién lo envió, quién lo devolvió y con qué motivo, quién lo aprobó. Debajo, cualquiera de los tres roles puede dejar un comentario.',
		as: 'gestion',
		run: async ( p ) => {
			if ( ! ( await goToDetail( p, CYCLE, 'revisar' ) ) ) return false;
			await p.fill(
				'#documentate-app-comentario',
				'Publicado y comunicado al centro; el original firmado queda en el expediente.'
			);
			return await click( p, p.locator( 'button[form="dcta-app-comentario"]' ) );
		},
	},

	{
		chapter: 'La otra cara: wp-admin',
		title: 'La lista de documentos',
		text: 'La aplicación no sustituye a wp-admin: es la misma base de datos. Administración conserva la lista de siempre con el nombre interno, el estado, el área y los filtros por estado.',
		as: 'admin',
		run: async ( p ) => {
			return await goTo( p, '/wp-admin/edit.php?post_type=documentate_document' );
		},
	},
	{
		chapter: 'La otra cara: wp-admin',
		title: 'El tipo de documento: prefijo y gestión',
		text: 'Cada tipo lleva su plantilla, un prefijo de hasta seis letras para las listas y la marca de si pasa por gestión documental. Debajo, los campos que la plantilla declara, con la etiqueta «gestión» en los que solo completa gestión documental.',
		as: 'admin',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/wp-admin/edit-tags.php?taxonomy=documentate_doc_type&post_type=documentate_document' ) ) ) {
				return false;
			}
			const row = p.locator( '#the-list tr' ).filter( { hasText: 'Resolución Administrativa' } ).first();
			if ( ! ( await row.count() ) ) return false;
			return await follow( p, row.locator( 'a.row-title' ).first() );
		},
	},
	{
		chapter: 'La otra cara: wp-admin',
		title: 'El metabox de gestión del documento',
		text: 'En la pantalla clásica del documento, «Gestión del documento» resume el recorrido y ofrece las mismas acciones que la aplicación. Las secciones de contenido se pliegan para verlo entero.',
		as: 'admin',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/wp-admin/edit.php?post_type=documentate_document' ) ) ) return false;
			const row = p.locator( '#the-list tr' ).filter( { hasText: IN_MANAGEMENT } ).first();
			if ( ! ( await row.count() ) ) return false;
			if ( ! ( await follow( p, row.locator( 'a.row-title' ).first() ) ) ) return false;
			return await collapseMetaboxes( p, 'documentate_document_management' );
		},
	},

	{
		chapter: 'Herramientas de desarrollo',
		title: 'El selector de perfiles',
		text: 'Solo en wp-env y en Playground: la pantalla de acceso lista las cuentas de prueba de los tres roles y las rellena con un clic. Su pareja es el menú «Probar como…» de la barra de administración —a la vista en las capturas de wp-admin—, que cambia de cuenta sin cerrar sesión y devuelve a la aplicación. Nada de esto se despliega: /scripts no entra en el ZIP.',
		as: 'admin',
		who: 'Sin sesión',
		run: async ( p ) => {
			if ( ! ( await goTo( p, '/wp-login.php' ) ) ) return false;
			const box = p.locator( '.documentate-dev-login-accounts' );
			if ( ! ( await box.count() ) ) return false;
			await box.first().waitFor( { state: 'visible', timeout: 5000 } );
			return true;
		},
	},
];

// ─── Engine ───────────────────────────────────────────────────────────────────

/** ID of the cycle document in the current pass; resolved only once. */
let DOC_CYCLE = 0;

/**
 * Puts the demo documents back the way they were before starting.
 *
 * Each screen size walks the whole cycle, and the cycle changes state: what
 * the desktop pass approves is no longer pending for the mobile one. Without
 * this reset the second pass photographs empty trays.
 *
 * @return {void}
 */
function reseedData() {
	if ( process.env.DOCUMENTATE_SIN_SEMBRAR ) return;
	try {
		execFileSync(
			'npx',
			[
				'@wordpress/env',
				'run',
				'cli',
				'--config=.wp-env.docker.json',
				'wp',
				'eval-file',
				'wp-content/plugins/documentate/scripts/seed-demo-app.php',
			],
			{ stdio: 'ignore' }
		);
	} catch ( e ) {
		// Without reseeding, the second pass would start from what the first
		// one left behind and the two sets of screenshots would not compare.
		throw new Error(
			'No se pudo resembrar la demo (wp eval-file seed-demo-app.php). ¿Está levantado el entorno de desarrollo? Usa DOCUMENTATE_SIN_SEMBRAR=1 para saltarlo a propósito.'
		);
	}
}

/**
 * Navigates to a site path.
 *
 * @param {import('@playwright/test').Page} p    Page.
 * @param {string}                          path Absolute site path or full URL.
 * @return {Promise<void>}
 */
async function goTo( p, path ) {
	const url = path.startsWith( 'http' ) ? path : BASE + path;
	const response = await p.goto( url, { waitUntil: 'networkidle' } );
	if ( ! response || response.status() >= 400 ) return false;

	// A 200 is not proof of anything: a login wall, a PHP fatal with
	// WP_DEBUG_DISPLAY off and a theme 404 all answer 200 with no application
	// on the page.
	return ( await p.locator( '.dcta-hoja, #wpbody-content, #login' ).count() ) > 0;
}

/**
 * Follows a link by its href, without relying on the click not overlapping
 * another navigation.
 *
 * @param {import('@playwright/test').Page}    p       Page.
 * @param {import('@playwright/test').Locator} link    Link.
 * @return {Promise<boolean>} false if the link is missing or leads nowhere.
 */
async function follow( p, link ) {
	if ( ! ( await link.count() ) ) return false;
	const href = await link.first().getAttribute( 'href' );
	if ( ! href ) return false;
	return await goTo( p, href );
}

/**
 * Clicks a button that submits a form and waits for the redirect after it.
 *
 * Every handler of the application redirects to another URL with its feedback
 * flag, so waiting for the address to change is enough.
 *
 * @param {import('@playwright/test').Page}    p     Page.
 * @param {import('@playwright/test').Locator} button Button.
 * @return {Promise<boolean>} false if the button is missing.
 */
async function click( p, button ) {
	if ( ! ( await button.count() ) ) return false;
	const before = p.url();
	let navigated = true;
	await Promise.all( [
		p
			.waitForURL( ( url ) => String( url ) !== before, { timeout: 20000 } )
			.catch( () => {
				navigated = false;
			} ),
		button.first().click(),
	] );
	await p.waitForLoadState( 'networkidle' );

	// Every handler of the application redirects with its feedback flag: no
	// new address means the form never went through (a blocked required
	// field, a button that does nothing), and the scene has to say so.
	return navigated;
}

/**
 * Row of the document list whose name contains the given text.
 *
 * @param {import('@playwright/test').Page} p      Page.
 * @param {string}                          name Fragment of the internal name.
 * @return {import('@playwright/test').Locator}
 */
function rowOf( p, name ) {
	return p.locator( '.dcta-fila' ).filter( { hasText: name } ).first();
}

/**
 * Resolves the ID of the document that walks the cycle, reading it from the
 * área list once per pass (seeding changes the IDs).
 *
 * @param {import('@playwright/test').Page} p Page of any role with access.
 * @return {Promise<number>} 0 if it does not show up.
 */
async function cycleId( p ) {
	if ( DOC_CYCLE ) return DOC_CYCLE;

	for ( const path of [ '/documentate/', '/documentate/?bandeja=revisar', '/documentate/?estado=todos' ] ) {
		await goTo( p, path );
		const link = rowOf( p, CYCLE ).locator( '.dcta-doc-nombre a' ).first();
		if ( ! ( await link.count() ) ) continue;
		const href = await link.getAttribute( 'href' );
		DOC_CYCLE = Number( new URL( href, BASE ).searchParams.get( 'doc' ) ) || 0;
		if ( DOC_CYCLE ) return DOC_CYCLE;
	}

	return 0;
}

/**
 * Opens the editor of a document, finding it by name in the lists.
 *
 * @param {import('@playwright/test').Page} p       Page.
 * @param {string}                          name    Fragment of the internal name.
 * @param {string}                          tray    Tray the visit comes from.
 * @return {Promise<boolean>} false if the document is missing or not editable.
 */
async function goToEdit( p, name, tray = '' ) {
	const id = CYCLE === name ? await cycleId( p ) : await idOf( p, name, tray );
	if ( ! id ) return false;

	const queue = '' !== tray ? '&bandeja=' + tray : '';
	await goTo( p, '/documentate/?doc=' + id + '&vista=editar' + queue );

	return ( await p.locator( 'form.dcta-editor' ).count() ) > 0;
}

/**
 * Opens the detail view of a document, finding it by name in the lists.
 *
 * @param {import('@playwright/test').Page} p       Page.
 * @param {string}                          name    Fragment of the internal name.
 * @param {string}                          tray    Tray the visit comes from.
 * @return {Promise<boolean>} false if the document does not show up.
 */
async function goToDetail( p, name, tray = '' ) {
	const id = CYCLE === name ? await cycleId( p ) : await idOf( p, name, tray );
	if ( ! id ) return false;

	const queue = '' !== tray ? '&bandeja=' + tray : '';
	await goTo( p, '/documentate/?doc=' + id + queue );

	return ( await p.locator( '.dcta-detalle' ).count() ) > 0;
}

/**
 * ID of any document, looking for it in the given tray.
 *
 * @param {import('@playwright/test').Page} p       Page.
 * @param {string}                          name    Fragment of the internal name.
 * @param {string}                          tray    Tray to look in.
 * @return {Promise<number>} 0 if it does not show up.
 */
async function idOf( p, name, tray ) {
	const paths = '' !== tray
		? [ '/documentate/?bandeja=' + tray + '&estado=todos', '/documentate/' ]
		: [ '/documentate/', '/documentate/?estado=todos' ];

	for ( const path of paths ) {
		await goTo( p, path );
		const link = rowOf( p, name ).locator( '.dcta-doc-nombre a' ).first();
		if ( ! ( await link.count() ) ) continue;
		const href = await link.getAttribute( 'href' );
		const id = Number( new URL( href, BASE ).searchParams.get( 'doc' ) ) || 0;
		if ( id ) return id;
	}

	return 0;
}

/**
 * Types into an editor field if it is present.
 *
 * @param {import('@playwright/test').Page} p        Page.
 * @param {string}                          selector Field selector.
 * @param {string}                          value    Value to type.
 * @return {Promise<boolean>} false if the field is not in this view.
 */
async function fillField( p, selector, value ) {
	const field = p.locator( selector );
	if ( ! ( await field.count() ) ) return false;
	await field.first().fill( value );
	return true;
}

/**
 * Picks an option from an editor dropdown if it is present.
 *
 * @param {import('@playwright/test').Page} p        Page.
 * @param {string}                          selector Dropdown selector.
 * @param {string}                          value    Option value.
 * @return {Promise<boolean>} false if the dropdown is not in this view.
 */
async function pickField( p, selector, value ) {
	const field = p.locator( selector );
	if ( ! ( await field.count() ) ) return false;
	await field.first().selectOption( value );
	return true;
}

/**
 * Saves the editor and waits for the confirmation notice.
 *
 * @param {import('@playwright/test').Page} p Page.
 * @return {Promise<boolean>} false if there is no save button.
 */
async function save( p ) {
	if ( ! ( await click( p, p.locator( 'button[name="documentate_app_estado"][value="guardar"]' ) ) ) ) {
		return false;
	}
	return ( await p.locator( '.dcta-aviso-ok' ).count() ) > 0;
}

/**
 * Clicks a transition button and leaves its confirmation dialog open.
 *
 * @param {import('@playwright/test').Page} p     Page.
 * @param {string}                          key   Transition key.
 * @return {Promise<boolean>} false if the button is missing or the dialog stays shut.
 */
async function openConfirmation( p, key ) {
	const button = p.locator( 'button[name="documentate_app_transicion"][value="' + key + '"]' );
	if ( ! ( await button.count() ) ) return false;

	await button.first().click();
	const dialog = p.locator( '#dcta-dialogo-confirmar' );
	try {
		await dialog.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		return false;
	}

	return true;
}

/**
 * Clicks a return button, leaves the reason dialog open and types the reason.
 *
 * @param {import('@playwright/test').Page} p      Page.
 * @param {string}                          key    Transition key.
 * @param {string}                          reason Reason for the return.
 * @return {Promise<boolean>} false if the button is missing or the dialog stays shut.
 */
async function openReasonDialog( p, key, reason ) {
	const button = p.locator( 'button[data-motivo][name="documentate_app_transicion"][value="' + key + '"]' );
	if ( ! ( await button.count() ) ) return false;

	await button.first().click();
	const dialog = p.locator( '#dcta-dialogo-motivo' );
	try {
		await dialog.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		return false;
	}

	await p.fill( '#dcta-dialogo-motivo-texto', reason );
	return true;
}

/**
 * Brings the gestión documental half of the editor into view.
 *
 * Expands the provider cards and folds «Datos del área» away, which is how the
 * work is done when the official data is what has to be filled in: unfolded,
 * the full-page screenshot is ten screens tall and nothing can be read.
 *
 * @param {import('@playwright/test').Page} p Page.
 * @return {Promise<void>}
 */
async function focusManagement( p ) {
	await p.evaluate( () => {
		document.querySelectorAll( 'details' ).forEach( ( d ) => {
			d.open = true;
		} );
		const area = document.querySelector( 'details.dcta-seccion-area' );
		if ( area ) {
			area.open = false;
		}
		// The summary recomputes on input; expanding has to ask for it.
		if ( window.documentateCalculations ) {
			window.documentateCalculations.recalculate();
		}
	} );
}

/**
 * Folds every wp-admin metabox except the one worth showing.
 *
 * The classic document screen is several screens tall; folded, the whole
 * screenshot fits and what is being explained is visible.
 *
 * @param {import('@playwright/test').Page} p     Page.
 * @param {string}                          keepOpen ID of the metabox left open.
 * @return {Promise<boolean>} false if this is not the classic editor screen.
 */
async function collapseMetaboxes( p, keepOpen ) {
	const box = p.locator( '#' + keepOpen );
	if ( ! ( await box.count() ) ) return false;

	await p.evaluate( ( id ) => {
		document.querySelectorAll( '#poststuff .postbox' ).forEach( ( box ) => {
			if ( box.id !== id ) {
				box.classList.add( 'closed' );
			}
		} );
	}, keepOpen );

	return true;
}

/**
 * Width in CSS pixels of the screenshot just taken.
 *
 * The report draws every figure at that width, so a page that widens (or a
 * mobile window) comes out at its real size instead of rescaled.
 *
 * @param {import('@playwright/test').Page} p           Page.
 * @param {boolean}                         viewportOnly Whether only the window was captured.
 * @return {Promise<number>} Width in CSS pixels.
 */
async function cssWidth( p, viewportOnly ) {
	const viewport = p.viewportSize();
	if ( viewportOnly ) return viewport ? viewport.width : 0;

	return await p.evaluate( () =>
		Math.max(
			document.documentElement.scrollWidth,
			document.documentElement.clientWidth
		)
	);
}

/**
 * Logs in with one of the demo accounts.
 *
 * @param {import('@playwright/test').BrowserContext} context  Context of the role.
 * @param {string}                                    who      USERS key.
 * @return {Promise<import('@playwright/test').Page>}
 */
async function logIn( context, who ) {
	const { user, pass } = USERS[ who ];
	const p = await context.newPage();
	await p.goto( BASE + '/wp-login.php', { waitUntil: 'networkidle' } );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', pass );
	await Promise.all( [ p.waitForLoadState( 'networkidle' ), p.click( '#wp-submit' ) ] );
	const inside = ! ( await p.locator( '#loginform' ).count() );
	if ( ! inside ) {
		throw new Error( `No se pudo iniciar sesión como «${ user }». ¿Está el entorno de desarrollo levantado?` );
	}
	return p;
}

/**
 * Walks the whole script at each screen size and writes the report.
 *
 * @return {Promise<void>}
 */
async function main() {
	const browser = await chromium.launch();
	await rm( OUT, { recursive: true, force: true } );
	await mkdir( path.join( OUT, 'img' ), { recursive: true } );

	const screens = SOLO ? SCREENS.filter( ( s ) => s.id === SOLO ) : SCREENS;
	if ( ! screens.length ) {
		throw new Error( `SOLO=${ SOLO } no existe. Usa: ${ SCREENS.map( ( s ) => s.id ).join( ', ' ) }` );
	}

	const done = [];

	for ( const screen of screens ) {
		const { id, label, ...options } = screen;
		console.log( `\n▸ ${ label }` );
		reseedData();
		DOC_CYCLE = 0;

		// One session per role and screen: switching user mid-script would
		// force a fresh login on every scene.
		const sessions = {};
		for ( const who of Object.keys( USERS ) ) {
			const context = await browser.newContext( { ...options, locale: 'es-ES' } );
			sessions[ who ] = { context, page: await logIn( context, who ) };
		}

		for ( const [ i, scene ] of SCENES.entries() ) {
			const { page } = sessions[ scene.as ];
			let ok = true;
			let error = '';
			try {
				ok = ( await scene.run( page ) ) !== false;
			} catch ( e ) {
				ok = false;
				error = String( e.message || e ).split( '\n' )[ 0 ];
			}

			const name = `${ String( i + 1 ).padStart( 2, '0' ) }-${ id }-${ slug( scene.title ) }.png`;
			// A modal <dialog> and its backdrop are the size of the window: in a
			// full-page screenshot the backdrop darkens only the first screen
			// and on mobile the dialog ends up thousands of pixels down.
			await page.screenshot( {
				path: path.join( OUT, 'img', name ),
				fullPage: ! scene.viewportOnly,
			} );
			const width = await cssWidth( page, scene.viewportOnly );
			done.push( {
				...scene,
				screen: label,
				screenId: id,
				img: `img/${ name }`,
				width,
				url: page.url().replace( BASE, '' ),
				ok,
				error,
			} );
			console.log( `  ${ ok ? '✓' : '✗' } ${ scene.chapter } — ${ scene.title }${ error ? ` (${ error })` : '' }` );
		}

		for ( const s of Object.values( sessions ) ) await s.context.close();
	}

	await browser.close();
	await writeFile( path.join( OUT, 'informe.html' ), report( done ), 'utf8' );

	const failures = done.filter( ( c ) => ! c.ok );
	console.log( `\n${ done.length } capturas en ${ OUT }/informe.html` );
	if ( failures.length ) {
		console.log( `${ failures.length } escenas no se pudieron completar (siguen capturadas, marcadas en el informe).` );
		process.exitCode = 1;
	}
}

const slug = ( t ) =>
	t
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

const esc = ( t ) =>
	String( t ).replace( /[&<>"]/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ] ) );

/**
 * Builds the HTML report: one chapter per block of the script and, at each
 * step, the two screens side by side.
 *
 * @param {Array<Object>} shots Screenshots taken, in order.
 * @return {string} Full HTML of the report.
 */
function report( shots ) {
	const chapters = [ ...new Set( shots.map( ( c ) => c.chapter ) ) ];
	const date = new Date().toLocaleString( 'es-ES' );
	const failures = shots.filter( ( c ) => ! c.ok ).length;

	const index = `<nav class="indice"><ol>${ chapters
		.map(
			( chapter ) =>
				`<li><a href="#${ slug( chapter ) }">${ esc( chapter ) }</a></li>`
		)
		.join( '' ) }</ol></nav>`;

	const body = chapters
		.map( ( chapter ) => {
			const theirs = shots.filter( ( c ) => c.chapter === chapter );
			const titles = [ ...new Set( theirs.map( ( c ) => c.title ) ) ];
			return `<section id="${ slug( chapter ) }"><h2>${ esc( chapter ) }</h2>${ titles
				.map( ( title ) => {
					const step = theirs.filter( ( c ) => c.title === title );
					const who = step[ 0 ].who || USERS[ step[ 0 ].as ].label;
					return `<article id="${ slug( title ) }">
	<h3><a class="ancla" href="#${ slug( title ) }">§</a> ${ esc( title ) }${ step.every( ( c ) => c.ok ) ? '' : ' <span class="ko">revisar</span>' }</h3>
	<p class="who">${ esc( who ) }</p>
	<p>${ esc( step[ 0 ].text ) }</p>
	${ step.map( figure ).join( '' ) }
</article>`;
				} )
				.join( '' ) }</section>`;
		} )
		.join( '' );

	return `<!doctype html>
<html lang="es"><head><meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Documentate — el ciclo completo de un documento</title>
<style>
:root { --tinta:#12202a; --suave:#5a6b75; --linea:#d5dde2; --fondo:#f3f5f6; --ko:#a93a2c; }
* { box-sizing:border-box }
body { margin:0; background:var(--fondo); color:var(--tinta);
  font:16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; }
.wrap { max-width:1100px; margin:0 auto; padding:48px 24px 96px; }
h1 { font-size:34px; letter-spacing:-.02em; margin:0 0 6px; }
.sub { color:var(--suave); margin:0 0 8px; }
.meta { color:var(--suave); font-size:13px; font-family:ui-monospace,Menlo,monospace; }
.indice { margin:26px 0 0; padding:16px 20px; background:#fff; border:1px solid var(--linea); }
.indice ol { margin:0; padding-left:20px; }
.indice a { color:var(--tinta); }
h2 { font-size:13px; text-transform:uppercase; letter-spacing:.12em; color:var(--suave);
  margin:56px 0 0; padding-bottom:10px; border-bottom:1px solid var(--linea); }
article { margin-top:34px; }
h3 { font-size:21px; margin:0 0 4px; letter-spacing:-.01em; }
.ancla { color:var(--linea); text-decoration:none; }
.quien { margin:0 0 10px; font-size:12px; letter-spacing:.06em; text-transform:uppercase;
  color:var(--suave); font-family:ui-monospace,Menlo,monospace; }
article p { margin:0 0 18px; color:var(--suave); max-width:62ch; }
/* The two screens stack one under the other: in two columns, the
   ordenador se dibujaba al 44 % y su texto de 16 px quedaba ilegible. */
figure { margin:0 0 22px; background:#fff; border:1px solid var(--linea); }
figure img { display:block; width:100%; height:auto;
  max-height:1500px; object-fit:cover; object-position:top; }
figcaption { font-size:12px; color:var(--suave); padding:8px 12px; border-top:1px solid var(--linea);
  font-family:ui-monospace,Menlo,monospace; word-break:break-all; }
figcaption a { color:var(--suave); }
figcaption code { font-family:inherit; }
.ko { color:var(--ko); font-weight:600; }
@media print { body { background:#fff } figure { break-inside:avoid } }
</style></head><body><div class="wrap">
<h1>Documentate</h1>
<p class="sub">El ciclo completo de un documento: el área lo prepara, gestión documental completa los datos oficiales y administración lo aprueba.</p>
<p class="meta">${ esc( date ) } · ${ esc( BASE ) } · ${ shots.length } capturas${
		failures ? ` · <span class="ko">${ failures } por revisar</span>` : ''
	}</p>
${ index }
${ body }
</div></body></html>`;
}

/**
 * One figure of the report, drawn at the real width of its screenshot.
 *
 * @param {Object} c Screenshot record.
 * @return {string} HTML of the figure.
 */
function figure( c ) {
	const width = c.width > 0 ? ` style="max-width:${ c.width }px"` : '';

	return `<figure class="${ c.screenId }"${ width }>
		<img src="${ c.img }" alt="${ esc( c.title ) } en ${ esc( c.screen ) }" loading="lazy" />
		<figcaption>${ esc( c.screen ) } · <code>${ esc( c.url || '' ) }</code> · <a href="${ c.img }">captura completa</a>${
			c.error ? ` — <span class="ko">${ esc( c.error ) }</span>` : ''
		}</figcaption>
	</figure>`;
}

main().catch( ( e ) => {
	console.error( '\n' + e.message );
	process.exit( 1 );
} );
