/**
 * Guion de capturas del ciclo completo de un documento.
 *
 * Recorre la aplicación con Playwright en dos tamaños de pantalla y deja un
 * informe HTML con las capturas comentadas: sirve para verificar que el ciclo
 * funciona de punta a punta después de cada cambio, y como base del manual.
 *
 * El guion es la constante ESCENAS de abajo. Cada escena dice quién entra, a
 * dónde va, qué hace y qué se está enseñando; añadir un paso al manual es
 * añadir una escena, no tocar el motor.
 *
 * El documento que recorre el ciclo es el de la demo «PG · Material aulas
 * digitales»: el área lo completa y lo envía, gestión documental rellena los
 * datos oficiales y lo devuelve, el área lo corrige, gestión lo pasa a
 * administración y administración lo aprueba. Antes de cada tamaño de pantalla
 * se vuelve a sembrar la demo, así que las dos pasadas parten del mismo estado.
 *
 * Uso:  make capturas                      (todo)
 *       make capturas SOLO=movil           (solo el móvil)
 *       DOCUMENTATE_SIN_SEMBRAR=1 …        (no resembrar; usa los datos que haya)
 *
 * Nunca a la vez que los E2E: los dos escriben en el sitio de desarrollo.
 */

import { chromium, devices } from '@playwright/test';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const BASE = process.env.DOCUMENTATE_URL || 'http://localhost:8989';
const OUT = process.env.DOCUMENTATE_CAPTURAS || 'capturas';
const SOLO = process.env.SOLO || '';

const USUARIOS = {
	area: { user: 'author1', pass: 'password', etiqueta: 'Área · Departamento de Proyectos' },
	gestion: { user: 'editor1', pass: 'password', etiqueta: 'Gestión documental' },
	admin: { user: 'admin', pass: 'password', etiqueta: 'Administración' },
};

const PANTALLAS = [
	{ id: 'escritorio', etiqueta: 'Ordenador', viewport: { width: 1440, height: 900 } },
	{ id: 'movil', etiqueta: 'Móvil', ...devices[ 'iPhone 13' ], deviceScaleFactor: 2 },
];

/** Documento de la demo que recorre el ciclo entero. */
const CICLO = 'Material aulas digitales';

/** Documento de la demo con proveedores y totales calculados («documento 0»). */
const PROVEEDORES = 'Renovación licencias aulas virtuales';

/** Documento de la demo que sigue en gestión cuando se llega a wp-admin. */
const EN_GESTION = 'Listado definitivo piloto innovación';

/** Motivo con el que gestión documental devuelve el documento del ciclo. */
const MOTIVO = 'Falta el desglose por proveedores y la partida presupuestaria.';

/** Documento pendiente que administración devuelve, eligiendo destino. */
const PENDIENTE = 'Formación profesorado metodologías';

/**
 * PDF mínimo pero real: la validación del adjunto olfatea el contenido, así
 * que no vale una cadena cualquiera.
 */
const PDF = Buffer.from(
	'%PDF-1.4\n' +
		'1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' +
		'2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n' +
		'trailer<</Root 1 0 R>>\n' +
		'%%EOF'
);

/**
 * El guion. `hacer` recibe la página y puede navegar, rellenar y pulsar; si
 * devuelve false la escena queda marcada «revisar» en el informe (por ejemplo,
 * un botón que no está porque el paso anterior no llegó a completarse).
 *
 * `pantallaSola: true` captura solo la ventana visible en vez de la página
 * entera: es lo que necesitan las escenas que enseñan un diálogo, porque un
 * <dialog> modal y su fondo viven en la capa superior y miden lo que mide la
 * ventana, no lo que mide la página.
 */
const ESCENAS = [
	{
		capitulo: 'Entrada',
		titulo: 'Una sola dirección para todo el mundo',
		texto: 'Todo el mundo entra por /documentate/. El área aparece directamente en sus documentos, con el rol y el ámbito escritos en la cabecera; no hay que acordarse de qué pantalla tocaba.',
		como: 'area',
		hacer: async ( p ) => {
			return await ir( p, '/documentate/' );
		},
	},
	{
		capitulo: 'Entrada',
		titulo: 'La misma puerta, para gestión documental',
		texto: 'Gestión documental entra por la misma URL y ve, además de sus propios documentos y de «Nuevo documento», la bandeja «Para revisar», con el número de los que esperan a que complete los datos oficiales.',
		como: 'gestion',
		hacer: async ( p ) => {
			return await ir( p, '/documentate/' );
		},
	},
	{
		capitulo: 'Entrada',
		titulo: 'La misma puerta, para administración',
		texto: 'Administración aterriza en todos los documentos de todas las áreas; como los ve todos, tiene un selector de área —aquí acotado al Departamento de Proyectos— y un acceso directo a los tipos y plantillas de wp-admin. El aviso de la pestaña cuenta todo lo que espera revisión; los contadores de debajo obedecen al filtro.',
		como: 'admin',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/documentate/' ) ) ) return false;
			const select = p.locator( '#dcta-area' );
			if ( ! ( await select.count() ) ) return false;
			await select.selectOption( { label: 'Departamento de Proyectos' } );
			return await pulsar( p, p.locator( '.dcta-areas button[type="submit"]' ) );
		},
	},

	{
		capitulo: 'El área prepara el documento',
		titulo: 'Mis documentos: contadores y filtros',
		texto: 'Los contadores dicen qué hay por enviar, qué está en gestión, qué espera aprobación y qué se aprobó ya; los chips filtran la lista sin salir de la página. Cada fila lleva el nombre corto con su prefijo, el título oficial y el estado.',
		como: 'area',
		hacer: async ( p ) => {
			return await ir( p, '/documentate/?estado=draft' );
		},
	},
	{
		capitulo: 'El área prepara el documento',
		titulo: 'Nuevo documento',
		texto: 'Crear un documento son tres decisiones: el tipo (que ya no se cambia), un nombre corto para las listas y el título oficial que saldrá en el papel. Al elegir el tipo, la ayuda dice si pasa por gestión documental y aparece el prefijo delante del nombre.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/documentate/?vista=nuevo' ) ) ) return false;
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
		capitulo: 'El área prepara el documento',
		titulo: 'Completar el borrador y adjuntar el fichero',
		texto: 'El editor del área tiene los datos básicos, los campos de la plantilla y el fichero del documento: se arrastra al recuadro o se elige a mano, y se sube al guardar. Los campos que solo rellena gestión documental no están aquí.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO ) ) ) return false;
			// La letra del decreto es de una sola letra: el dato de demo no
			// vale y el navegador no dejaría enviar el formulario.
			await escribirCampo( p, '#documentate_field_letra_decreto', 'B' );
			// Un fichero recién elegido, para que se vea el estado «se subirá
			// al guardar» del que habla el texto.
			const adjunto = p.locator( 'input[name="documentate_app_adjunto"]' );
			if ( await adjunto.count() ) {
				await adjunto.first().setInputFiles( {
					name: 'acta-material-aulas-digitales.pdf',
					mimeType: 'application/pdf',
					buffer: PDF,
				} );
			}
			return await guardar( p );
		},
	},
	{
		capitulo: 'El área prepara el documento',
		titulo: 'Enviar a gestión, con confirmación',
		texto: 'Enviar es la decisión que cierra el documento para el área, así que se pregunta antes. La ventana dice exactamente qué va a pasar: lo completará gestión documental y ya no se podrá modificar hasta que lo devuelvan.',
		como: 'area',
		pantallaSola: true,
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO ) ) ) return false;
			return await abrirConfirmacion( p, 'enviar_gestion' );
		},
	},
	{
		capitulo: 'El área prepara el documento',
		titulo: 'El documento queda en gestión documental',
		texto: 'Confirmado, el documento sale del área. La ficha lo dice arriba y el indicador de estado marca en qué punto del recorrido está: borrador, en gestión, en revisión, aprobado.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO ) ) ) return false;
			if ( ! ( await abrirConfirmacion( p, 'enviar_gestion' ) ) ) return false;
			return await pulsar( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		capitulo: 'Gestión documental completa',
		titulo: 'La bandeja de gestión',
		texto: 'La bandeja «Para revisar» reúne los documentos de todas las áreas que ya salieron de su borrador. Bajo el nombre corto van el título oficial y el área y la persona que lo firma, y el clip marca los que traen fichero.',
		como: 'gestion',
		hacer: async ( p ) => {
			return await ir( p, '/documentate/?bandeja=revisar' );
		},
	},
	{
		capitulo: 'Gestión documental completa',
		titulo: 'Los datos oficiales, que solo ve gestión',
		texto: 'Gestión abre el mismo editor con una sección más: los campos marcados como de gestión en la plantilla —el gasto en letra y en cifra, la partida presupuestaria— y unas anotaciones internas que no salen en el documento. Los datos del área quedan plegados, a la vista pero fuera del camino.',
		como: 'gestion',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO, 'revisar' ) ) ) return false;
			await elegirCampo( p, '#documentate_field_partida', '18.03.322B.229.0100' );
			await escribirCampo( p, '#documentate_field_gasto_numero', '1875' );
			await escribirCampo(
				p,
				'#documentate_field_gasto_letra',
				'mil ochocientos setenta y cinco euros'
			);
			await escribirCampo(
				p,
				'#documentate-app-anotaciones',
				'Comprobado con intervención: falta el desglose por proveedores antes de pasarlo a administración.'
			);
			if ( ! ( await guardar( p ) ) ) return false;
			await enfocarGestion( p );
			return true;
		},
	},
	{
		capitulo: 'Gestión documental completa',
		titulo: 'Proveedores y totales que se calculan solos',
		texto: 'En la propuesta de gasto cada proveedor lleva sus conceptos: cantidad por precio da el total de la línea, la suma de líneas el bruto, y con el IGIC y el IRPF sale el total del proveedor. El resumen de la propuesta y el importe en cifra se escriben solos.',
		como: 'gestion',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, PROVEEDORES ) ) ) return false;
			await enfocarGestion( p );
			const resumen = p.locator( '.dcta-resumen' );
			if ( ! ( await resumen.count() ) ) return false;
			await resumen.first().waitFor();
		},
	},
	{
		capitulo: 'Gestión documental completa',
		titulo: 'Devolver al área, diciendo por qué',
		texto: 'Si falta algo, el documento vuelve al área. El motivo es obligatorio: se manda por correo, queda en la actividad y es lo primero que ve quien lo escribió.',
		como: 'gestion',
		pantallaSola: true,
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO, 'revisar' ) ) ) return false;
			await enfocarGestion( p );
			return await abrirMotivo( p, 'devolver_area', MOTIVO );
		},
	},
	{
		capitulo: 'Gestión documental completa',
		titulo: 'Devuelto',
		texto: 'Tras devolverlo, gestión vuelve a su bandeja con el aviso de que salió: el documento ya no le corresponde hasta que el área lo reenvíe.',
		como: 'gestion',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO, 'revisar' ) ) ) return false;
			if ( ! ( await abrirMotivo( p, 'devolver_area', MOTIVO ) ) ) return false;
			return await pulsar( p, p.locator( '#dcta-dialogo-motivo-ok' ) );
		},
	},

	{
		capitulo: 'El área corrige',
		titulo: 'El documento vuelve, con su motivo',
		texto: 'En la lista del área la fila devuelta se tiñe y lleva debajo quién lo devolvió, cuándo y por qué. La acción de la fila deja de ser «Continuar» y pasa a ser «Corregir».',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/documentate/' ) ) ) return false;
			const fila = filaDe( p, CICLO );
			if ( ! ( await fila.count() ) ) return false;
			return ( await fila.locator( '.dcta-doc-motivo' ).count() ) > 0;
		},
	},
	{
		capitulo: 'El área corrige',
		titulo: 'Corregir lo que falta',
		texto: 'El editor vuelve a abrirse con el motivo arriba del todo y los campos otra vez editables. Los datos oficiales que rellenó gestión siguen ahí, pero el área no los ve ni los puede tocar.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO ) ) ) return false;
			return ( await p.locator( '.dcta-aviso-devuelto' ).count() ) > 0;
		},
	},
	{
		capitulo: 'El área corrige',
		titulo: 'Reenviado a gestión',
		texto: 'Corregido, se vuelve a enviar por el mismo camino y con la misma confirmación. La marca de devuelto desaparece: el documento está otra vez en gestión documental.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO ) ) ) return false;
			if ( ! ( await abrirConfirmacion( p, 'enviar_gestion' ) ) ) return false;
			return await pulsar( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		capitulo: 'De gestión a administración',
		titulo: 'Pasar a administración',
		texto: 'Cuando los datos oficiales están completos, gestión lo pasa a administración —también con su confirmación, como al enviarlo—. Hecho eso, gestión tampoco puede modificarlo: la ficha ya dice «En revisión».',
		como: 'gestion',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO, 'revisar' ) ) ) return false;
			if ( ! ( await abrirConfirmacion( p, 'pasar_admin' ) ) ) return false;
			return await pulsar( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},
	{
		capitulo: 'De gestión a administración',
		titulo: 'La bandeja de revisión',
		texto: 'Administración tiene su propia bandeja con lo que espera aprobación, los mismos chips de estado y un selector de área para acotar cuando hay muchos: aquí, el Departamento de Proyectos.',
		como: 'admin',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/documentate/?bandeja=revision' ) ) ) return false;
			const select = p.locator( '#dcta-area' );
			if ( ! ( await select.count() ) ) return false;
			await select.selectOption( { label: 'Departamento de Proyectos' } );
			return await pulsar( p, p.locator( '.dcta-areas button[type="submit"]' ) );
		},
	},
	{
		capitulo: 'De gestión a administración',
		titulo: 'Devolver, eligiendo a quién',
		texto: 'Administración es la única que puede devolver a dos sitios: a gestión documental, para que rehaga los datos oficiales, o directamente al área. Una sola ventana pregunta a quién y por qué; el motivo sigue siendo obligatorio.',
		como: 'admin',
		pantallaSola: true,
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, PENDIENTE, 'revision' ) ) ) return false;
			if ( ! ( await abrirMotivo( p, 'devolver_area', 'Falta el desglose por partidas del capítulo II.' ) ) ) {
				return false;
			}
			// La ventana se queda abierta: la escena la enseña, no la envía.
			return ( await p.locator( '.dcta-dialogo-destinos input[type="radio"]:not([disabled])' ).count() ) === 2;
		},
	},
	{
		capitulo: 'De gestión a administración',
		titulo: 'Aprobar y publicar',
		texto: 'Aprobar publica el documento y lo cierra: a partir de ahí solo se consulta y se descarga. La ficha lo dice arriba y el indicador de estado llega al final del recorrido.',
		como: 'admin',
		hacer: async ( p ) => {
			if ( ! ( await irEditar( p, CICLO, 'revision' ) ) ) return false;
			if ( ! ( await abrirConfirmacion( p, 'aprobar' ) ) ) return false;
			return await pulsar( p, p.locator( '#dcta-dialogo-confirmar-ok' ) );
		},
	},

	{
		capitulo: 'El documento terminado',
		titulo: 'Previsualizar y descargar',
		texto: 'El documento aprobado se genera desde su plantilla: vista previa en PDF y descarga en PDF, ODT o DOCX. Los formatos que necesitan conversor aparecen desactivados y con el motivo en el título cuando el entorno no lo tiene.',
		como: 'area',
		hacer: async ( p ) => {
			if ( ! ( await irDetalle( p, CICLO ) ) ) return false;
			return ( await p.locator( '#exportar' ).count() ) > 0;
		},
	},
	{
		capitulo: 'El documento terminado',
		titulo: 'La actividad del documento',
		texto: 'Todo lo que le pasó al documento queda escrito: quién creó el borrador, quién adjuntó el fichero, quién lo envió, quién lo devolvió y con qué motivo, quién lo aprobó. Debajo, cualquiera de los tres roles puede dejar un comentario.',
		como: 'gestion',
		hacer: async ( p ) => {
			if ( ! ( await irDetalle( p, CICLO, 'revisar' ) ) ) return false;
			await p.fill(
				'#documentate-app-comentario',
				'Publicado y comunicado al centro; el original firmado queda en el expediente.'
			);
			return await pulsar( p, p.locator( 'button[form="dcta-app-comentario"]' ) );
		},
	},

	{
		capitulo: 'La otra cara: wp-admin',
		titulo: 'La lista de documentos',
		texto: 'La aplicación no sustituye a wp-admin: es la misma base de datos. Administración conserva la lista de siempre con el nombre interno, el estado, el área y los filtros por estado.',
		como: 'admin',
		hacer: async ( p ) => {
			return await ir( p, '/wp-admin/edit.php?post_type=documentate_document' );
		},
	},
	{
		capitulo: 'La otra cara: wp-admin',
		titulo: 'El tipo de documento: prefijo y gestión',
		texto: 'Cada tipo lleva su plantilla, un prefijo de hasta seis letras para las listas y la marca de si pasa por gestión documental. Debajo, los campos que la plantilla declara, con la etiqueta «gestión» en los que solo completa gestión documental.',
		como: 'admin',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/wp-admin/edit-tags.php?taxonomy=documentate_doc_type&post_type=documentate_document' ) ) ) {
				return false;
			}
			const fila = p.locator( '#the-list tr' ).filter( { hasText: 'Resolución Administrativa' } ).first();
			if ( ! ( await fila.count() ) ) return false;
			return await seguir( p, fila.locator( 'a.row-title' ).first() );
		},
	},
	{
		capitulo: 'La otra cara: wp-admin',
		titulo: 'El metabox de gestión del documento',
		texto: 'En la pantalla clásica del documento, «Gestión del documento» resume el recorrido y ofrece las mismas acciones que la aplicación. Las secciones de contenido se pliegan para verlo entero.',
		como: 'admin',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/wp-admin/edit.php?post_type=documentate_document' ) ) ) return false;
			const fila = p.locator( '#the-list tr' ).filter( { hasText: EN_GESTION } ).first();
			if ( ! ( await fila.count() ) ) return false;
			if ( ! ( await seguir( p, fila.locator( 'a.row-title' ).first() ) ) ) return false;
			return await plegarMetaboxes( p, 'documentate_document_management' );
		},
	},

	{
		capitulo: 'Herramientas de desarrollo',
		titulo: 'El selector de perfiles',
		texto: 'Solo en wp-env y en Playground: la pantalla de acceso lista las cuentas de prueba de los tres roles y las rellena con un clic. Su pareja es el menú «Probar como…» de la barra de administración —a la vista en las capturas de wp-admin—, que cambia de cuenta sin cerrar sesión y devuelve a la aplicación. Nada de esto se despliega: /scripts no entra en el ZIP.',
		como: 'admin',
		quien: 'Sin sesión',
		hacer: async ( p ) => {
			if ( ! ( await ir( p, '/wp-login.php' ) ) ) return false;
			const caja = p.locator( '.documentate-dev-login-accounts' );
			if ( ! ( await caja.count() ) ) return false;
			await caja.first().waitFor( { state: 'visible', timeout: 5000 } );
			return true;
		},
	},
];

// ─── Motor ────────────────────────────────────────────────────────────────────

/** ID del documento del ciclo en la pasada actual; se resuelve una sola vez. */
let DOC_CICLO = 0;

/**
 * Vuelve a dejar los documentos de demo como estaban antes de empezar.
 *
 * Cada tamaño de pantalla recorre el ciclo entero, y el ciclo cambia el estado:
 * lo que aprueba el ordenador ya no está pendiente para el móvil. Sin este
 * reinicio la segunda pasada fotografía bandejas vacías.
 *
 * @return {void}
 */
function reiniciarDatos() {
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
		// Sin resembrar, la segunda pasada partiría de los datos que dejó la
		// primera y las dos tandas de capturas no serían comparables.
		throw new Error(
			'No se pudo resembrar la demo (wp eval-file seed-demo-app.php). ¿Está levantado el entorno de desarrollo? Usa DOCUMENTATE_SIN_SEMBRAR=1 para saltarlo a propósito.'
		);
	}
}

/**
 * Navega a una ruta del sitio.
 *
 * @param {import('@playwright/test').Page} p    Página.
 * @param {string}                          ruta Ruta absoluta del sitio o URL completa.
 * @return {Promise<void>}
 */
async function ir( p, ruta ) {
	const url = ruta.startsWith( 'http' ) ? ruta : BASE + ruta;
	const respuesta = await p.goto( url, { waitUntil: 'networkidle' } );
	if ( ! respuesta || respuesta.status() >= 400 ) return false;

	// A 200 is not proof of anything: a login wall, a PHP fatal with
	// WP_DEBUG_DISPLAY off and a theme 404 all answer 200 with no application
	// on the page.
	return ( await p.locator( '.dcta-hoja, #wpbody-content, #login' ).count() ) > 0;
}

/**
 * Sigue un enlace por su href, sin depender de que el clic no se solape con
 * otra navegación.
 *
 * @param {import('@playwright/test').Page}    p       Página.
 * @param {import('@playwright/test').Locator} enlace  Enlace.
 * @return {Promise<boolean>} false si el enlace no existe o no lleva a ningún sitio.
 */
async function seguir( p, enlace ) {
	if ( ! ( await enlace.count() ) ) return false;
	const href = await enlace.first().getAttribute( 'href' );
	if ( ! href ) return false;
	return await ir( p, href );
}

/**
 * Pulsa un botón que envía un formulario y espera al redirect posterior.
 *
 * Todos los handlers de la aplicación redirigen a otra URL con su bandera de
 * feedback, así que basta con esperar a que la dirección cambie.
 *
 * @param {import('@playwright/test').Page}    p     Página.
 * @param {import('@playwright/test').Locator} boton Botón.
 * @return {Promise<boolean>} false si el botón no existe.
 */
async function pulsar( p, boton ) {
	if ( ! ( await boton.count() ) ) return false;
	const antes = p.url();
	let navegó = true;
	await Promise.all( [
		p
			.waitForURL( ( url ) => String( url ) !== antes, { timeout: 20000 } )
			.catch( () => {
				navegó = false;
			} ),
		boton.first().click(),
	] );
	await p.waitForLoadState( 'networkidle' );

	// Every handler of the application redirects with its feedback flag: no
	// new address means the form never went through (a blocked required
	// field, a button that does nothing), and the scene has to say so.
	return navegó;
}

/**
 * Fila de la lista de documentos cuyo nombre contiene el texto dado.
 *
 * @param {import('@playwright/test').Page} p      Página.
 * @param {string}                          nombre Trozo del nombre interno.
 * @return {import('@playwright/test').Locator}
 */
function filaDe( p, nombre ) {
	return p.locator( '.dcta-fila' ).filter( { hasText: nombre } ).first();
}

/**
 * Resuelve el ID del documento que recorre el ciclo, mirándolo en la lista del
 * área una sola vez por pasada (la siembra cambia los IDs).
 *
 * @param {import('@playwright/test').Page} p Página de cualquier rol con acceso.
 * @return {Promise<number>} 0 si no aparece.
 */
async function idCiclo( p ) {
	if ( DOC_CICLO ) return DOC_CICLO;

	for ( const ruta of [ '/documentate/', '/documentate/?bandeja=revisar', '/documentate/?estado=todos' ] ) {
		await ir( p, ruta );
		const enlace = filaDe( p, CICLO ).locator( '.dcta-doc-nombre a' ).first();
		if ( ! ( await enlace.count() ) ) continue;
		const href = await enlace.getAttribute( 'href' );
		DOC_CICLO = Number( new URL( href, BASE ).searchParams.get( 'doc' ) ) || 0;
		if ( DOC_CICLO ) return DOC_CICLO;
	}

	return 0;
}

/**
 * Abre el editor de un documento buscándolo por su nombre en las listas.
 *
 * @param {import('@playwright/test').Page} p       Página.
 * @param {string}                          nombre  Trozo del nombre interno.
 * @param {string}                          bandeja Bandeja de la que se viene.
 * @return {Promise<boolean>} false si el documento no aparece o no es editable.
 */
async function irEditar( p, nombre, bandeja = '' ) {
	const id = CICLO === nombre ? await idCiclo( p ) : await idDe( p, nombre, bandeja );
	if ( ! id ) return false;

	const cola = '' !== bandeja ? '&bandeja=' + bandeja : '';
	await ir( p, '/documentate/?doc=' + id + '&vista=editar' + cola );

	return ( await p.locator( 'form.dcta-editor' ).count() ) > 0;
}

/**
 * Abre la ficha de un documento buscándolo por su nombre en las listas.
 *
 * @param {import('@playwright/test').Page} p       Página.
 * @param {string}                          nombre  Trozo del nombre interno.
 * @param {string}                          bandeja Bandeja de la que se viene.
 * @return {Promise<boolean>} false si el documento no aparece.
 */
async function irDetalle( p, nombre, bandeja = '' ) {
	const id = CICLO === nombre ? await idCiclo( p ) : await idDe( p, nombre, bandeja );
	if ( ! id ) return false;

	const cola = '' !== bandeja ? '&bandeja=' + bandeja : '';
	await ir( p, '/documentate/?doc=' + id + cola );

	return ( await p.locator( '.dcta-detalle' ).count() ) > 0;
}

/**
 * ID de un documento cualquiera, buscándolo en la bandeja indicada.
 *
 * @param {import('@playwright/test').Page} p       Página.
 * @param {string}                          nombre  Trozo del nombre interno.
 * @param {string}                          bandeja Bandeja donde mirar.
 * @return {Promise<number>} 0 si no aparece.
 */
async function idDe( p, nombre, bandeja ) {
	const rutas = '' !== bandeja
		? [ '/documentate/?bandeja=' + bandeja + '&estado=todos', '/documentate/' ]
		: [ '/documentate/', '/documentate/?estado=todos' ];

	for ( const ruta of rutas ) {
		await ir( p, ruta );
		const enlace = filaDe( p, nombre ).locator( '.dcta-doc-nombre a' ).first();
		if ( ! ( await enlace.count() ) ) continue;
		const href = await enlace.getAttribute( 'href' );
		const id = Number( new URL( href, BASE ).searchParams.get( 'doc' ) ) || 0;
		if ( id ) return id;
	}

	return 0;
}

/**
 * Escribe en un campo del editor si está presente.
 *
 * @param {import('@playwright/test').Page} p        Página.
 * @param {string}                          selector Selector del campo.
 * @param {string}                          valor    Valor a escribir.
 * @return {Promise<boolean>} false si el campo no está en esta vista.
 */
async function escribirCampo( p, selector, valor ) {
	const campo = p.locator( selector );
	if ( ! ( await campo.count() ) ) return false;
	await campo.first().fill( valor );
	return true;
}

/**
 * Elige una opción de un desplegable del editor si está presente.
 *
 * @param {import('@playwright/test').Page} p        Página.
 * @param {string}                          selector Selector del desplegable.
 * @param {string}                          valor    Valor de la opción.
 * @return {Promise<boolean>} false si el desplegable no está en esta vista.
 */
async function elegirCampo( p, selector, valor ) {
	const campo = p.locator( selector );
	if ( ! ( await campo.count() ) ) return false;
	await campo.first().selectOption( valor );
	return true;
}

/**
 * Guarda el editor y espera al aviso de confirmación.
 *
 * @param {import('@playwright/test').Page} p Página.
 * @return {Promise<boolean>} false si no hay botón de guardar.
 */
async function guardar( p ) {
	if ( ! ( await pulsar( p, p.locator( 'button[name="documentate_app_estado"][value="guardar"]' ) ) ) ) {
		return false;
	}
	return ( await p.locator( '.dcta-aviso-ok' ).count() ) > 0;
}

/**
 * Pulsa un botón de transición y deja abierta su ventana de confirmación.
 *
 * @param {import('@playwright/test').Page} p     Página.
 * @param {string}                          clave Clave de la transición.
 * @return {Promise<boolean>} false si el botón no está o la ventana no se abre.
 */
async function abrirConfirmacion( p, clave ) {
	const boton = p.locator( 'button[name="documentate_app_transicion"][value="' + clave + '"]' );
	if ( ! ( await boton.count() ) ) return false;

	await boton.first().click();
	const dialogo = p.locator( '#dcta-dialogo-confirmar' );
	try {
		await dialogo.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		return false;
	}

	return true;
}

/**
 * Pulsa un botón de devolución, deja abierta la ventana del motivo y lo escribe.
 *
 * @param {import('@playwright/test').Page} p      Página.
 * @param {string}                          clave  Clave de la transición.
 * @param {string}                          motivo Motivo de la devolución.
 * @return {Promise<boolean>} false si el botón no está o la ventana no se abre.
 */
async function abrirMotivo( p, clave, motivo ) {
	const boton = p.locator( 'button[data-motivo][name="documentate_app_transicion"][value="' + clave + '"]' );
	if ( ! ( await boton.count() ) ) return false;

	await boton.first().click();
	const dialogo = p.locator( '#dcta-dialogo-motivo' );
	try {
		await dialogo.waitFor( { state: 'visible', timeout: 5000 } );
	} catch {
		return false;
	}

	await p.fill( '#dcta-dialogo-motivo-texto', motivo );
	return true;
}

/**
 * Deja a la vista la parte de gestión documental del editor.
 *
 * Despliega las fichas de proveedor y pliega «Datos del área», que es como se
 * trabaja cuando lo que toca es completar los datos oficiales: sin plegarlos,
 * la captura de página completa mide diez pantallas y no se lee nada.
 *
 * @param {import('@playwright/test').Page} p Página.
 * @return {Promise<void>}
 */
async function enfocarGestion( p ) {
	await p.evaluate( () => {
		document.querySelectorAll( 'details' ).forEach( ( d ) => {
			d.open = true;
		} );
		const area = document.querySelector( 'details.dcta-seccion-area' );
		if ( area ) {
			area.open = false;
		}
		// El resumen se recalcula al escribir; al desplegar hay que pedirlo.
		if ( window.documentateCalculos ) {
			window.documentateCalculos.recalcular();
		}
	} );
}

/**
 * Pliega todos los metaboxes de wp-admin menos el que interesa enseñar.
 *
 * La pantalla clásica del documento mide varias pantallas de alto; plegada, la
 * captura entera cabe y se ve lo que se está explicando.
 *
 * @param {import('@playwright/test').Page} p     Página.
 * @param {string}                          dejar ID del metabox que se deja abierto.
 * @return {Promise<boolean>} false si la pantalla no es la del editor clásico.
 */
async function plegarMetaboxes( p, dejar ) {
	const caja = p.locator( '#' + dejar );
	if ( ! ( await caja.count() ) ) return false;

	await p.evaluate( ( id ) => {
		document.querySelectorAll( '#poststuff .postbox' ).forEach( ( box ) => {
			if ( box.id !== id ) {
				box.classList.add( 'closed' );
			}
		} );
	}, dejar );

	return true;
}

/**
 * Anchura en píxeles CSS de la captura que se acaba de hacer.
 *
 * El informe dibuja cada figura a esa anchura, así que una página que se
 * ensancha (o una ventana de móvil) sale a su tamaño real y no reescalada.
 *
 * @param {import('@playwright/test').Page} p           Página.
 * @param {boolean}                         soloVentana Si se capturó solo la ventana.
 * @return {Promise<number>} Anchura en píxeles CSS.
 */
async function anchoCss( p, soloVentana ) {
	const ventana = p.viewportSize();
	if ( soloVentana ) return ventana ? ventana.width : 0;

	return await p.evaluate( () =>
		Math.max(
			document.documentElement.scrollWidth,
			document.documentElement.clientWidth
		)
	);
}

/**
 * Inicia sesión con una de las cuentas de demo.
 *
 * @param {import('@playwright/test').BrowserContext} contexto Contexto propio del rol.
 * @param {string}                                    quien    Clave de USUARIOS.
 * @return {Promise<import('@playwright/test').Page>}
 */
async function entrar( contexto, quien ) {
	const { user, pass } = USUARIOS[ quien ];
	const p = await contexto.newPage();
	await p.goto( BASE + '/wp-login.php', { waitUntil: 'networkidle' } );
	await p.fill( '#user_login', user );
	await p.fill( '#user_pass', pass );
	await Promise.all( [ p.waitForLoadState( 'networkidle' ), p.click( '#wp-submit' ) ] );
	const dentro = ! ( await p.locator( '#loginform' ).count() );
	if ( ! dentro ) {
		throw new Error( `No se pudo iniciar sesión como «${ user }». ¿Está el entorno de desarrollo levantado?` );
	}
	return p;
}

/**
 * Recorre el guion entero en cada tamaño de pantalla y escribe el informe.
 *
 * @return {Promise<void>}
 */
async function main() {
	const navegador = await chromium.launch();
	await rm( OUT, { recursive: true, force: true } );
	await mkdir( path.join( OUT, 'img' ), { recursive: true } );

	const pantallas = SOLO ? PANTALLAS.filter( ( s ) => s.id === SOLO ) : PANTALLAS;
	if ( ! pantallas.length ) {
		throw new Error( `SOLO=${ SOLO } no existe. Usa: ${ PANTALLAS.map( ( s ) => s.id ).join( ', ' ) }` );
	}

	const hechas = [];

	for ( const pantalla of pantallas ) {
		const { id, etiqueta, ...opciones } = pantalla;
		console.log( `\n▸ ${ etiqueta }` );
		reiniciarDatos();
		DOC_CICLO = 0;

		// Una sesión por rol y pantalla: cambiar de usuario a mitad de guion
		// obligaría a reiniciar la sesión en cada escena.
		const sesiones = {};
		for ( const quien of Object.keys( USUARIOS ) ) {
			const contexto = await navegador.newContext( { ...opciones, locale: 'es-ES' } );
			sesiones[ quien ] = { contexto, pagina: await entrar( contexto, quien ) };
		}

		for ( const [ i, escena ] of ESCENAS.entries() ) {
			const { pagina } = sesiones[ escena.como ];
			let ok = true;
			let error = '';
			try {
				ok = ( await escena.hacer( pagina ) ) !== false;
			} catch ( e ) {
				ok = false;
				error = String( e.message || e ).split( '\n' )[ 0 ];
			}

			const nombre = `${ String( i + 1 ).padStart( 2, '0' ) }-${ id }-${ slug( escena.titulo ) }.png`;
			// Un <dialog> modal y su fondo miden lo que mide la ventana: en una
			// captura de página completa el fondo oscurece solo la primera
			// pantalla y en el móvil el diálogo acaba a miles de píxeles.
			await pagina.screenshot( {
				path: path.join( OUT, 'img', nombre ),
				fullPage: ! escena.pantallaSola,
			} );
			const ancho = await anchoCss( pagina, escena.pantallaSola );
			hechas.push( {
				...escena,
				pantalla: etiqueta,
				pantallaId: id,
				img: `img/${ nombre }`,
				ancho,
				url: pagina.url().replace( BASE, '' ),
				ok,
				error,
			} );
			console.log( `  ${ ok ? '✓' : '✗' } ${ escena.capitulo } — ${ escena.titulo }${ error ? ` (${ error })` : '' }` );
		}

		for ( const s of Object.values( sesiones ) ) await s.contexto.close();
	}

	await navegador.close();
	await writeFile( path.join( OUT, 'informe.html' ), informe( hechas ), 'utf8' );

	const fallos = hechas.filter( ( c ) => ! c.ok );
	console.log( `\n${ hechas.length } capturas en ${ OUT }/informe.html` );
	if ( fallos.length ) {
		console.log( `${ fallos.length } escenas no se pudieron completar (siguen capturadas, marcadas en el informe).` );
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
 * Compone el informe HTML: un capítulo por bloque del guion y, en cada paso,
 * las dos pantallas una al lado de la otra.
 *
 * @param {Array<Object>} capturas Capturas hechas, en orden.
 * @return {string} HTML completo del informe.
 */
function informe( capturas ) {
	const capitulos = [ ...new Set( capturas.map( ( c ) => c.capitulo ) ) ];
	const fecha = new Date().toLocaleString( 'es-ES' );
	const fallos = capturas.filter( ( c ) => ! c.ok ).length;

	const indice = `<nav class="indice"><ol>${ capitulos
		.map(
			( capitulo ) =>
				`<li><a href="#${ slug( capitulo ) }">${ esc( capitulo ) }</a></li>`
		)
		.join( '' ) }</ol></nav>`;

	const cuerpo = capitulos
		.map( ( capitulo ) => {
			const suyas = capturas.filter( ( c ) => c.capitulo === capitulo );
			const titulos = [ ...new Set( suyas.map( ( c ) => c.titulo ) ) ];
			return `<section id="${ slug( capitulo ) }"><h2>${ esc( capitulo ) }</h2>${ titulos
				.map( ( titulo ) => {
					const paso = suyas.filter( ( c ) => c.titulo === titulo );
					const quien = paso[ 0 ].quien || USUARIOS[ paso[ 0 ].como ].etiqueta;
					return `<article id="${ slug( titulo ) }">
	<h3><a class="ancla" href="#${ slug( titulo ) }">§</a> ${ esc( titulo ) }${ paso.every( ( c ) => c.ok ) ? '' : ' <span class="ko">revisar</span>' }</h3>
	<p class="quien">${ esc( quien ) }</p>
	<p>${ esc( paso[ 0 ].texto ) }</p>
	${ paso.map( figura ).join( '' ) }
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
/* Las dos pantallas van una debajo de otra: en dos columnas, la captura de
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
<p class="meta">${ esc( fecha ) } · ${ esc( BASE ) } · ${ capturas.length } capturas${
		fallos ? ` · <span class="ko">${ fallos } por revisar</span>` : ''
	}</p>
${ indice }
${ cuerpo }
</div></body></html>`;
}

/**
 * Una figura del informe, dibujada a la anchura real de su captura.
 *
 * @param {Object} c Captura.
 * @return {string} HTML de la figura.
 */
function figura( c ) {
	const ancho = c.ancho > 0 ? ` style="max-width:${ c.ancho }px"` : '';

	return `<figure class="${ c.pantallaId }"${ ancho }>
		<img src="${ c.img }" alt="${ esc( c.titulo ) } en ${ esc( c.pantalla ) }" loading="lazy" />
		<figcaption>${ esc( c.pantalla ) } · <code>${ esc( c.url || '' ) }</code> · <a href="${ c.img }">captura completa</a>${
			c.error ? ` — <span class="ko">${ esc( c.error ) }</span>` : ''
		}</figcaption>
	</figure>`;
}

main().catch( ( e ) => {
	console.error( '\n' + e.message );
	process.exit( 1 );
} );
