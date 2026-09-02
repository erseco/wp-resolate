/**
 * Tests for the automatic totals of the propuesta de gasto providers.
 *
 * The module works on the markup the sections metabox renders for a
 * repeater with a nested sub-repeater: provider cards
 * (.documentate-array-item) holding concepto rows (.documentate-subarray-item)
 * plus the provider amounts, and the gasto_numero scalar field.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SOURCE = fs.readFileSync(
	path.join( __dirname, '../../admin/js/documentate-calculos.js' ),
	'utf8'
);

/**
 * Markup of one concepto row.
 *
 * @param {string} slug    Repeater slug.
 * @param {number} i       Provider index.
 * @param {number} j       Row index.
 * @param {Object} valores Row values.
 * @return {string} HTML.
 */
function fila( slug, i, j, valores ) {
	const base = `tpl_fields[${ slug }][${ i }][conceptos][${ j }]`;
	return `
		<div class="documentate-subarray-item" data-subindex="${ j }">
			<input type="text" name="${ base }[concepto]" value="${ valores.concepto || '' }">
			<input type="number" name="${ base }[cantidad]" value="${ valores.cantidad || '' }">
			<input type="number" name="${ base }[unitario]" value="${ valores.unitario || '' }">
			<input type="number" name="${ base }[total]" value="${ valores.total || '' }">
		</div>`;
}

/**
 * Markup of one provider card.
 *
 * @param {string} slug    Repeater slug.
 * @param {number} i       Provider index.
 * @param {Object} valores Provider values.
 * @param {Array}  filas   Concepto rows.
 * @return {string} HTML.
 */
function proveedor( slug, i, valores, filas ) {
	const base = `tpl_fields[${ slug }][${ i }]`;
	return `
		<div class="documentate-array-item" data-index="${ i }">
			<div class="documentate-array-item-toolbar"><button type="button" class="documentate-array-remove">Delete</button></div>
			<input type="text" name="${ base }[proveedor]" value="${ valores.proveedor || '' }">
			<input type="text" name="${ base }[cif]" value="">
			<div class="documentate-subarray-field" data-subarray-field="conceptos">
				<button type="button" class="documentate-subarray-add">Add</button>
				<div class="documentate-subarray-items">${ filas.map( ( f, j ) => fila( slug, i, j, f ) ).join( '' ) }</div>
			</div>
			<input type="number" name="${ base }[bruto]" value="">
			<input type="number" name="${ base }[igic]" value="${ valores.igic || '' }">
			<input type="number" name="${ base }[irpf]" value="${ valores.irpf || '' }">
			<input type="number" name="${ base }[total]" value="">
		</div>`;
}

/**
 * Markup of one provider repeater.
 *
 * @param {string} slug        Repeater slug.
 * @param {Array}  proveedores Provider cards.
 * @return {string} HTML.
 */
function repetidor( slug, proveedores ) {
	return `
		<div class="documentate-array-field" data-array-field="${ slug }">
			<button type="button" class="documentate-array-add">Add item</button>
			<div class="documentate-array-items">${ proveedores.join( '' ) }</div>
		</div>`;
}

const GASTO = `
	<table><tbody><tr class="documentate-field documentate-field-gasto_numero"><td>
		<input type="number" id="documentate_field_gasto_numero" name="documentate_field_gasto_numero" value="">
	</td></tr></tbody></table>`;

const PROPUESTA = `
	<form id="post">
		${ repetidor( 'servicios', [
			proveedor( 'servicios', 0, { proveedor: 'Acme', igic: '7', irpf: '' }, [
				{ concepto: 'Curso', cantidad: '2', unitario: '10.5' },
				{ concepto: 'Material', cantidad: '1', unitario: '1000' },
				{},
			] ),
		] ) }
		${ repetidor( 'suministros', [ proveedor( 'suministros', 0, {}, [ {} ] ) ] ) }
		${ GASTO }
	</form>`;

const SIN_PROVEEDORES = `
	<form id="post">
		<div class="documentate-array-field" data-array-field="anexos">
			<div class="documentate-array-items">
				<div class="documentate-array-item"><input type="text" name="tpl_fields[anexos][0][title]" value="Anexo"></div>
			</div>
		</div>
		${ GASTO }
	</form>`;

/**
 * Evaluate the module against the current DOM.
 */
function cargar() {
	// eslint-disable-next-line no-new-func
	new Function( SOURCE )();
}

/**
 * Value of the input with the given name.
 *
 * @param {string} name Input name.
 * @return {HTMLInputElement} The input.
 */
function input( name ) {
	return document.querySelector( `[name="${ name }"]` );
}

beforeEach( () => {
	document.body.innerHTML = PROPUESTA;
} );

describe( 'row and provider totals', () => {
	it( 'computes row totals, bruto and total on load and locks them', () => {
		cargar();

		const t0 = input( 'tpl_fields[servicios][0][conceptos][0][total]' );
		const t1 = input( 'tpl_fields[servicios][0][conceptos][1][total]' );
		expect( t0.value ).toBe( '21.00' );
		expect( t1.value ).toBe( '1000.00' );
		expect( t0.readOnly ).toBe( true );
		expect( t0.getAttribute( 'data-calculado' ) ).toBe( '1' );

		expect( input( 'tpl_fields[servicios][0][bruto]' ).value ).toBe( '1021.00' );
		expect( input( 'tpl_fields[servicios][0][total]' ).value ).toBe( '1028.00' );
		expect( input( 'tpl_fields[servicios][0][total]' ).readOnly ).toBe( true );
		// igic stays editable.
		expect( input( 'tpl_fields[servicios][0][igic]' ).readOnly ).toBe( false );
	} );

	it( 'leaves blank rows and blank providers empty so they are not saved', () => {
		cargar();

		expect( input( 'tpl_fields[servicios][0][conceptos][2][total]' ).value ).toBe( '' );
		expect( input( 'tpl_fields[suministros][0][bruto]' ).value ).toBe( '' );
		expect( input( 'tpl_fields[suministros][0][total]' ).value ).toBe( '' );
	} );

	it( 'keeps a row whose total was typed by hand and adds it to the bruto', () => {
		document.body.innerHTML = `
			<form id="post">
				${ repetidor( 'expertos', [
					proveedor( 'expertos', 0, { proveedor: 'Ponente', igic: '', irpf: '' }, [
						{ concepto: 'Ponencia', total: '300' },
					] ),
				] ) }
				${ GASTO }
			</form>`;
		cargar();

		const total = input( 'tpl_fields[expertos][0][conceptos][0][total]' );
		expect( total.value ).toBe( '300' );
		expect( total.readOnly ).toBe( false );
		expect( total.hasAttribute( 'data-calculado' ) ).toBe( false );
		expect( input( 'tpl_fields[expertos][0][bruto]' ).value ).toBe( '300.00' );
		expect( input( 'tpl_fields[expertos][0][total]' ).value ).toBe( '300.00' );
		expect( input( 'documentate_field_gasto_numero' ).value ).toBe( '300.00' );
	} );

	it( 'gives a computed total back to the user when its row is emptied', () => {
		cargar();

		const cantidad = input( 'tpl_fields[servicios][0][conceptos][0][cantidad]' );
		const unitario = input( 'tpl_fields[servicios][0][conceptos][0][unitario]' );
		expect( input( 'tpl_fields[servicios][0][conceptos][0][total]' ).readOnly ).toBe( true );

		cantidad.value = '';
		unitario.value = '';
		unitario.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		const total = input( 'tpl_fields[servicios][0][conceptos][0][total]' );
		expect( total.readOnly ).toBe( false );
		expect( total.value ).toBe( '21.00' );
		// The amount left in the row still counts, so nothing is lost.
		expect( input( 'tpl_fields[servicios][0][bruto]' ).value ).toBe( '1021.00' );
	} );

	it( 'writes a summary line on every provider card in es-ES', () => {
		cargar();

		const lineas = Array.from( document.querySelectorAll( '.documentate-proveedor-resumen' ) ).map( ( n ) => n.textContent );
		expect( lineas ).toEqual( [
			'Servicio 1 · Acme · 2 conceptos · 1028,00 €',
			'Suministro 1 · sin proveedor · 0 conceptos · 0,00 €',
		] );
	} );

	it( 'uses the <summary> when the card is wrapped in details.documentate-proveedor', () => {
		const item = document.querySelector( '[data-array-field="servicios"] .documentate-array-item' );
		const details = document.createElement( 'details' );
		details.className = 'documentate-proveedor';
		details.innerHTML = '<summary>…</summary>';
		item.parentNode.insertBefore( details, item );
		details.appendChild( item );

		cargar();

		expect( details.querySelector( 'summary' ).textContent ).toBe( 'Servicio 1 · Acme · 2 conceptos · 1028,00 €' );
		expect( item.querySelector( '.documentate-proveedor-resumen' ) ).toBeNull();
	} );

	it( 'recalculates on input and on row buttons', () => {
		cargar();

		const cantidad = input( 'tpl_fields[servicios][0][conceptos][0][cantidad]' );
		cantidad.value = '3';
		cantidad.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( input( 'tpl_fields[servicios][0][conceptos][0][total]' ).value ).toBe( '31.50' );
		expect( input( 'tpl_fields[servicios][0][bruto]' ).value ).toBe( '1031.50' );
		expect( input( 'tpl_fields[servicios][0][total]' ).value ).toBe( '1038.50' );
		expect( input( 'documentate_field_gasto_numero' ).value ).toBe( '1038.50' );

		// A row removed by the repeater script before the click bubbles up.
		input( 'tpl_fields[servicios][0][conceptos][1][total]' ).closest( '.documentate-subarray-item' ).remove();
		document.querySelector( '.documentate-subarray-add' ).dispatchEvent( new window.MouseEvent( 'click', { bubbles: true } ) );

		expect( input( 'tpl_fields[servicios][0][bruto]' ).value ).toBe( '31.50' );
		expect( input( 'tpl_fields[servicios][0][total]' ).value ).toBe( '38.50' );
		expect( document.querySelector( '.documentate-proveedor-resumen' ).textContent ).toBe( 'Servicio 1 · Acme · 1 concepto · 38,50 €' );
	} );
} );

describe( 'summary card and gasto_numero', () => {
	it( 'builds the summary before gasto_numero and writes the grand total', () => {
		cargar();

		const gasto = input( 'documentate_field_gasto_numero' );
		const resumen = document.querySelector( '.documentate-resumen' );
		expect( resumen ).not.toBeNull();
		expect( resumen.nextElementSibling ).toBe( gasto );
		expect( resumen.textContent ).toContain( 'Servicios (1)' );
		expect( resumen.textContent ).toContain( 'Suministros (0)' );
		expect( resumen.textContent ).toContain( 'Total de la propuesta' );
		expect( resumen.querySelector( 'dd.documentate-resumen-total' ).textContent ).toBe( '1028,00 €' );
		expect( gasto.value ).toBe( '1028.00' );
		expect( gasto.readOnly ).toBe( true );
		expect( gasto.getAttribute( 'data-calculado' ) ).toBe( '1' );
	} );

	it( 'fills an existing .dcta-resumen card instead of creating one', () => {
		document.body.insertAdjacentHTML( 'afterbegin', '<div class="dcta-resumen"></div>' );
		cargar();

		expect( document.querySelectorAll( '.dcta-resumen' ).length ).toBe( 1 );
		expect( document.querySelector( '.dcta-resumen' ).textContent ).toContain( '1028,00 €' );
	} );

	it( 'keeps a hand-typed gasto_numero editable when no provider has conceptos', () => {
		document.body.innerHTML = `<form>${ repetidor( 'servicios', [ proveedor( 'servicios', 0, {}, [ {} ] ) ] ) }${ GASTO }</form>`;
		input( 'documentate_field_gasto_numero' ).value = '99';
		cargar();

		const gasto = input( 'documentate_field_gasto_numero' );
		expect( gasto.value ).toBe( '99' );
		expect( gasto.readOnly ).toBe( false );
		expect( gasto.hasAttribute( 'data-calculado' ) ).toBe( false );
		expect( document.querySelector( '.documentate-resumen' ).textContent ).toContain( 'Servicios (0)' );
		// Nothing itemised yet: the card says so instead of showing 0,00 €.
		expect( document.querySelector( 'dd.documentate-resumen-total' ).textContent ).toBe( 'Sin proveedores todavía' );
	} );

	it( 'takes gasto_numero over again as soon as a concepto row is filled', () => {
		document.body.innerHTML = `<form>${ repetidor( 'servicios', [ proveedor( 'servicios', 0, {}, [ {} ] ) ] ) }${ GASTO }</form>`;
		input( 'documentate_field_gasto_numero' ).value = '99';
		cargar();

		const cantidad = input( 'tpl_fields[servicios][0][conceptos][0][cantidad]' );
		cantidad.value = '2';
		cantidad.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		const unitario = input( 'tpl_fields[servicios][0][conceptos][0][unitario]' );
		unitario.value = '10';
		unitario.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		const gasto = input( 'documentate_field_gasto_numero' );
		expect( gasto.value ).toBe( '20.00' );
		expect( gasto.readOnly ).toBe( true );
		expect( gasto.getAttribute( 'data-calculado' ) ).toBe( '1' );
	} );

	it( 'does nothing when the document has no provider repeaters', () => {
		document.body.innerHTML = SIN_PROVEEDORES;
		cargar();

		expect( document.querySelector( '.documentate-resumen' ) ).toBeNull();
		expect( document.querySelector( '.documentate-proveedor-resumen' ) ).toBeNull();
		const gasto = input( 'documentate_field_gasto_numero' );
		expect( gasto.readOnly ).toBe( false );
		expect( gasto.value ).toBe( '' );
		expect( window.documentateCalculos.recalcular() ).toEqual( [] );
	} );
} );

describe( 'helpers', () => {
	it( 'parses Spanish and plain numbers', () => {
		cargar();
		const { numero } = window.documentateCalculos;

		expect( numero( '1234.5' ) ).toBe( 1234.5 );
		expect( numero( '1.234,50' ) ).toBe( 1234.5 );
		expect( numero( '12,5 €' ) ).toBe( 12.5 );
		expect( numero( '' ) ).toBe( 0 );
		expect( numero( 'abc' ) ).toBe( 0 );
		expect( numero( null ) ).toBe( 0 );
	} );

	it( 'formats amounts in es-ES with two decimals', () => {
		cargar();

		// Spanish groups thousands from five digits on ("1234,50" but "12.345,50").
		expect( window.documentateCalculos.formatear( 12345.5 ) ).toBe( '12.345,50 €' );
		expect( window.documentateCalculos.formatear( 1234.5 ) ).toBe( '1234,50 €' );
		expect( window.documentateCalculos.formatear( 0 ) ).toBe( '0,00 €' );
	} );
} );
