/**
 * Tests for the schema preview of the document types screen.
 *
 * The script normalises the schema the server prints in
 * #documentate_type_schema_preview (data-schema-v2) and renders one list item
 * per field. P2 added the rol key: the entries gestión documental fills in
 * carry a badge, the área ones do not.
 */
const jQuery = require( 'jquery' );

/**
 * Minimal stand-ins for the globals wp-admin provides.
 *
 * @return {Object} The documentateDocTypes global used by the script.
 */
function configuracion() {
	return {
		ajax: { url: '/wp-admin/admin-ajax.php', nonce: 'nonce' },
		fieldTypes: { number: 'Número' },
		i18n: {
			noFields: 'Sin campos',
			select: 'Seleccionar',
			diffAdded: 'Añadidos',
			diffRemoved: 'Eliminados',
			fieldCount: '%d campos',
			repeaterList: 'Bloques: %s',
			parsedAt: 'Analizada el %s',
			typeOdt: 'ODT',
			typeDocx: 'DOCX',
			typeUnknown: 'Desconocido',
		},
	};
}

/**
 * Render the preview for a schema and wait for the ready handler.
 *
 * @param {Object} schema Schema v2 structure.
 * @return {Promise<HTMLElement>} The preview container.
 */
async function pintar( schema ) {
	document.body.innerHTML =
		'<div id="documentate_type_schema_preview" data-schema-v2=\'' +
		JSON.stringify( schema ) +
		"'></div>";

	const escape = ( texto ) =>
		String( texto ).replace( /[&<>"']/g, ( caracter ) => {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#x27;',
			}[ caracter ];
		} );

	// The script reads the globals wp-admin defines, so they are put where it
	// looks for them and the file is required rather than evaluated as a
	// string: isolateModules re-runs it on every call, the way the browser
	// does on a fresh page, and the coverage report sees it.
	global.jQuery = jQuery;
	global.wp = {};
	global._ = { escape };
	global.documentateDocTypes = configuracion();

	jest.isolateModules( () => {
		require( '../../admin/js/documentate-doc-types.js' );
	} );

	// jQuery resolves its ready Deferred through two timer turns.
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	return document.getElementById( 'documentate_type_schema_preview' );
}

describe( 'schema preview', () => {
	it( 'badges the fields gestión documental fills in, whatever their case', async () => {
		const preview = await pintar( {
			version: 2,
			fields: [
				{ slug: 'objeto', name: 'objeto', title: 'Objeto', type: 'text' },
				{
					slug: 'numero_resolucion',
					name: 'numero_resolucion',
					title: 'Nº de resolución',
					type: 'text',
					rol: 'GESTION',
				},
			],
		} );

		const items = preview.querySelectorAll( 'li' );
		expect( items ).toHaveLength( 2 );
		expect( items[ 0 ].querySelector( '.documentate-field-rol' ) ).toBeNull();
		expect( items[ 1 ].querySelector( '.documentate-field-rol' ).textContent ).toBe(
			'gestión'
		);
	} );

	it( 'leaves área entries and entries without rol unbadged', async () => {
		const preview = await pintar( {
			version: 2,
			fields: [
				{ slug: 'curso', name: 'curso', title: 'Curso', type: 'text', rol: 'area' },
				{ slug: 'para', name: 'para', title: 'Para', type: 'text', rol: '' },
			],
		} );

		expect( preview.querySelectorAll( '.documentate-field-rol' ) ).toHaveLength( 0 );
	} );

	it( 'badges the fields of a repeater and escapes the labels', async () => {
		const preview = await pintar( {
			version: 2,
			fields: [],
			repeaters: [
				{
					slug: 'servicios',
					name: 'servicios',
					title: '<b>Servicios</b>',
					fields: [
						{
							slug: 'proveedor',
							name: 'proveedor',
							title: 'Proveedor',
							type: 'text',
							rol: 'gestion',
						},
					],
				},
			],
		} );

		const item = preview.querySelector( 'li' );
		expect( item.querySelector( 'strong' ).textContent ).toBe( '<b>Servicios</b>' );
		expect( item.querySelector( '.documentate-field-rol' ) ).not.toBeNull();
	} );

	it( 'says so when the type has no fields', async () => {
		const preview = await pintar( { version: 2, fields: [], repeaters: [] } );

		expect( preview.querySelector( '.documentate-schema-empty' ).textContent ).toBe(
			'Sin campos'
		);
		expect( preview.querySelector( 'li' ) ).toBeNull();
	} );
} );
