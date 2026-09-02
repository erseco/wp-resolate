/**
 * Tests for the buttons of the wp-admin "Gestión del documento" meta box.
 *
 * The script is the only thing standing between a click on "Devolver" and a
 * save: it reveals the reason box on the first click and refuses to submit
 * while the box is empty, which is what keeps the server-side Rule 0 (a return
 * without a reason is not a permitted transition) from turning into a silent
 * "nothing happened". The rest of the buttons only set #post_status before
 * submitting, and getting that wrong sends a document to the wrong place.
 */
const jQuery = require( 'jquery' );

const POST_ID = 42;

const STRINGS = {
	lockedTitle: 'Documento bloqueado',
	lockedMessage: 'Aprobado y de solo lectura.',
	archivedMessage: 'Archivado y de solo lectura.',
	pendingMessage: 'En revisión y de solo lectura.',
	gestionMessage: 'En gestión documental y de solo lectura.',
	adminUnlock: 'Devuélvelo a revisión.',
	adminUnarchive: 'Desarchívalo.',
	needsDocType: 'Selecciona un tipo de documento antes de enviarlo.',
	confirmSendReview: '¿Enviar el documento a revisión de administración?',
	confirmSendGestion: '¿Enviar el documento a gestión documental?',
	confirmPassAdmin: '¿Pasar el documento a administración?',
	motivoRequired: 'Escribe el motivo de la devolución antes de devolver el documento.',
};

/**
 * The markup the meta box renders, plus the fields the submit touches.
 *
 * @param {string} botones Buttons of the status being rendered.
 * @return {string} HTML.
 */
function pantalla( botones ) {
	return `
		<div id="poststuff">
			<form id="post">
				<input type="hidden" id="post_status" name="post_status" value="draft">
				<input type="hidden" id="hidden_post_status" name="hidden_post_status" value="draft">
				<div id="documentate_document_management">
					<span class="spinner"></span>
					${ botones }
					<div class="documentate-mgmt-motivo" style="display:none;">
						<label for="documentate-return-draft-motivo">Motivo de la devolución</label>
						<textarea id="documentate-return-draft-motivo" name="documentate_motivo"></textarea>
					</div>
				</div>
				<div id="documentate_sections"><div class="inside"></div></div>
			</form>
		</div>`;
}

/**
 * One button of the meta box.
 *
 * @param {string} id    Button id.
 * @param {Object} datos Data attributes.
 * @return {string} HTML.
 */
function boton( id, datos = {} ) {
	const attrs = Object.keys( datos )
		.map( ( clave ) => ` data-${ clave }="${ datos[ clave ] }"` )
		.join( '' );

	return `<button type="button" id="${ id }"${ attrs }>${ id }</button>`;
}

/**
 * Run the module against the current DOM with a given configuration.
 *
 * isolateModules re-evaluates the file on every call, the way the browser runs
 * it on a fresh page — and unlike new Function( source ) it goes through the
 * module registry, so the coverage report sees it.
 *
 * @param {Object} config The documentateWorkflow global.
 * @return {Promise<void>} Resolves once jQuery's ready queue has drained.
 */
async function arrancar( config = {} ) {
	window.documentateWorkflow = {
		postId: POST_ID,
		postStatus: 'draft',
		isAdmin: false,
		hasDocType: true,
		conGestion: false,
		isPublished: false,
		isArchived: false,
		isPending: false,
		isEnGestion: false,
		isLocked: false,
		strings: STRINGS,
		...config,
	};

	jest.isolateModules( () => {
		require( '../../admin/js/documentate-workflow.js' );
	} );

	// jQuery resolves its ready Deferred through two timer turns.
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * Click a button the way a user would.
 *
 * @param {string} id Button id.
 * @return {void}
 */
function clic( id ) {
	document.getElementById( id ).dispatchEvent(
		new window.MouseEvent( 'click', { bubbles: true, cancelable: true } )
	);
}

let submits;

beforeAll( () => {
	global.jQuery = jQuery;
	global.$ = jQuery;

	// jsdom gives every element a zero box, so jQuery's :visible is always
	// false there. The reason box is shown and hidden with an inline style,
	// which is what the check is really asking about.
	jQuery.expr.pseudos.visible = ( elemento ) =>
		'none' !== elemento.style.display;
} );

beforeEach( () => {
	submits = 0;
	document.body.innerHTML = '';
	window.alert = jest.fn();
	window.confirm = jest.fn( () => true );

	// jsdom throws "not implemented" on a real form submit.
	window.HTMLFormElement.prototype.submit = function () {
		submits += 1;
	};
} );

afterEach( () => {
	jQuery( document ).off( 'ajaxComplete' );
	delete window.documentateWorkflow;
} );

describe( 'devolver: the reason box', () => {
	beforeEach( async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-return-draft' ) + boton( 'documentate-return-gestion' )
		);
		await arrancar( { postStatus: 'en_gestion', isEnGestion: true } );
	} );

	it( 'reveals the box on the first click and submits nothing', () => {
		clic( 'documentate-return-draft' );

		expect(
			document.querySelector( '.documentate-mgmt-motivo' ).style.display
		).not.toBe( 'none' );
		expect( submits ).toBe( 0 );
		expect( window.alert ).not.toHaveBeenCalled();
		expect( document.getElementById( 'post_status' ).value ).toBe( 'draft' );
	} );

	it( 'refuses to submit while the reason is empty, and says so', () => {
		clic( 'documentate-return-draft' );
		clic( 'documentate-return-draft' );

		expect( window.alert ).toHaveBeenCalledWith( STRINGS.motivoRequired );
		expect( submits ).toBe( 0 );
	} );

	it( 'refuses a reason made of spaces', () => {
		clic( 'documentate-return-draft' );
		document.getElementById( 'documentate-return-draft-motivo' ).value = '   ';
		clic( 'documentate-return-draft' );

		expect( window.alert ).toHaveBeenCalledWith( STRINGS.motivoRequired );
		expect( submits ).toBe( 0 );
	} );

	it( 'submits as a draft once the reason is written', () => {
		clic( 'documentate-return-draft' );
		document.getElementById( 'documentate-return-draft-motivo' ).value =
			'Falta el anexo firmado';
		clic( 'documentate-return-draft' );

		expect( window.alert ).not.toHaveBeenCalled();
		expect( submits ).toBe( 1 );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'draft' );
		expect( document.getElementById( 'hidden_post_status' ).value ).toBe(
			'draft'
		);
	} );

	it( 'sends the document back to gestión with its own reason', () => {
		clic( 'documentate-return-gestion' );
		document.getElementById( 'documentate-return-draft-motivo' ).value =
			'Falta el número de expediente';
		clic( 'documentate-return-gestion' );

		expect( submits ).toBe( 1 );
		expect( document.getElementById( 'post_status' ).value ).toBe(
			'en_gestion'
		);
	} );
} );

describe( 'the buttons of each status', () => {
	it( 'passes an en_gestion document to administración after confirming', async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-pass-admin' ) + boton( 'documentate-save-gestion' )
		);
		await arrancar( { postStatus: 'en_gestion', isEnGestion: true } );

		clic( 'documentate-pass-admin' );

		expect( window.confirm ).toHaveBeenCalledWith( STRINGS.confirmPassAdmin );
		expect( submits ).toBe( 1 );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'pending' );
	} );

	it( 'does nothing when the confirmation is dismissed', async () => {
		document.body.innerHTML = pantalla( boton( 'documentate-pass-admin' ) );
		await arrancar( { postStatus: 'en_gestion', isEnGestion: true } );
		window.confirm = jest.fn( () => false );

		clic( 'documentate-pass-admin' );

		expect( submits ).toBe( 0 );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'draft' );
	} );

	it( 'saves without moving the document out of en_gestion', async () => {
		document.body.innerHTML = pantalla( boton( 'documentate-save-gestion' ) );
		await arrancar( { postStatus: 'en_gestion', isEnGestion: true } );

		clic( 'documentate-save-gestion' );

		expect( submits ).toBe( 1 );
		expect( document.getElementById( 'post_status' ).value ).toBe(
			'en_gestion'
		);
	} );

	it( 'sends a draft where its type says: gestión documental', async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-send-review', { estado: 'en_gestion' } )
		);
		await arrancar( { conGestion: true } );

		clic( 'documentate-send-review' );

		expect( window.confirm ).toHaveBeenCalledWith( STRINGS.confirmSendGestion );
		expect( document.getElementById( 'post_status' ).value ).toBe(
			'en_gestion'
		);
	} );

	it( 'sends a draft of a direct type to revisión', async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-send-review', { estado: 'pending' } )
		);
		await arrancar();

		clic( 'documentate-send-review' );

		expect( window.confirm ).toHaveBeenCalledWith( STRINGS.confirmSendReview );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'pending' );
	} );

	it( 'never asks administración to confirm', async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-send-review', { estado: 'pending' } ) +
				boton( 'documentate-approve-publish' ) +
				boton( 'documentate-save-draft' )
		);
		await arrancar( { isAdmin: true } );

		clic( 'documentate-send-review' );
		expect( window.confirm ).not.toHaveBeenCalled();

		clic( 'documentate-approve-publish' );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'publish' );

		clic( 'documentate-save-draft' );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'draft' );
		expect( submits ).toBe( 3 );
	} );

	it( 'un-approves a published document from the same rail', async () => {
		document.body.innerHTML = pantalla(
			boton( 'documentate-return-review' ) + boton( 'documentate-save-pending' )
		);
		await arrancar( {
			postStatus: 'publish',
			isAdmin: true,
			isPublished: true,
		} );

		clic( 'documentate-return-review' );

		expect( submits ).toBe( 1 );
		expect( document.getElementById( 'post_status' ).value ).toBe( 'pending' );
	} );
} );

describe( 'the locked state', () => {
	it( 'tells the área that gestión documental has the document', async () => {
		document.body.innerHTML = pantalla( '' );
		await arrancar( {
			postStatus: 'en_gestion',
			isEnGestion: true,
			isLocked: true,
		} );

		const aviso = document.querySelector( '.documentate-workflow-notice' );
		expect( aviso.textContent ).toContain( STRINGS.gestionMessage );
		expect(
			document.querySelector( '#documentate_sections .locked-overlay' )
				.textContent
		).toContain( STRINGS.gestionMessage );
		expect( document.body.classList ).toContain(
			'documentate-document-locked'
		);
	} );

	it( 'tells administración how to unlock an approved document', async () => {
		document.body.innerHTML = pantalla( '' );
		await arrancar( {
			postStatus: 'publish',
			isAdmin: true,
			isPublished: true,
			isLocked: true,
		} );

		expect(
			document.querySelector( '.documentate-workflow-notice' ).textContent
		).toContain( STRINGS.adminUnlock );
	} );

	it( 'asks for a document type before anything can be sent', async () => {
		document.body.innerHTML = pantalla( '' );
		await arrancar( { hasDocType: false } );

		expect(
			document.querySelector( '.documentate-doctype-warning' ).textContent
		).toContain( STRINGS.needsDocType );
	} );

	it( 'disables every control of a locked document', async () => {
		document.body.innerHTML =
			pantalla( '' ) +
			`<div class="documentate-sections-container">
				<input type="text" id="campo" name="documentate_field[a]">
				<div class="ProseMirror" contenteditable="true"></div>
				<div class="documentate-array-field">
					<div class="documentate-array-items">
						<div class="documentate-array-item">
							<input type="text" id="fila" name="tpl_fields[a][0][b]">
							<span class="documentate-array-handle"></span>
						</div>
					</div>
					<button type="button" class="documentate-array-add">Añadir</button>
					<button type="button" class="documentate-array-remove">Quitar</button>
				</div>
				<div class="wp-editor-tabs"></div>
			</div>`;
		await arrancar( { postStatus: 'publish', isPublished: true, isLocked: true } );

		expect( document.getElementById( 'campo' ).disabled ).toBe( true );
		expect( document.getElementById( 'fila' ).disabled ).toBe( true );
		expect(
			document.querySelector( '.documentate-array-add' ).disabled
		).toBe( true );
		expect(
			document.querySelector( '.documentate-array-remove' ).disabled
		).toBe( true );
		expect(
			document.querySelector( '.ProseMirror' ).getAttribute( 'contenteditable' )
		).toBe( 'false' );
		expect(
			document.querySelector( '.documentate-array-item' ).getAttribute( 'draggable' )
		).toBe( 'false' );
		expect(
			document.querySelector( '.documentate-array-handle' ).getAttribute( 'aria-disabled' )
		).toBe( 'true' );
	} );

	it( 'disables the internal name and the área checkboxes too', async () => {
		// Both sit outside every meta box the first selectors covered: the
		// name after the title, the área in the core taxonomy box — and both
		// stayed writable on a screen that says the document cannot be edited.
		document.body.innerHTML =
			pantalla( '' ) +
			`<div class="documentate-nombre-interno">
				<span class="documentate-nombre-interno__prefijo">RES</span>
				<input type="text" id="documentate_nombre_interno" name="documentate_nombre_interno">
			</div>
			<div id="categorydiv">
				<ul id="categorychecklist">
					<li><label><input value="7" type="checkbox" name="post_category[]"> Área</label></li>
				</ul>
			</div>`;
		await arrancar( {
			postStatus: 'publish',
			isAdmin: true,
			isPublished: true,
			isLocked: true,
		} );

		expect(
			document.getElementById( 'documentate_nombre_interno' ).disabled
		).toBe( true );
		expect(
			document.querySelector( '#categorychecklist input' ).disabled
		).toBe( true );
	} );

	it( 'locks again the fields a meta box reload brought back', async () => {
		document.body.innerHTML =
			pantalla( '' ) + '<div class="documentate-sections-container"></div>';
		await arrancar( {
			postStatus: 'archived',
			isArchived: true,
			isLocked: true,
		} );

		expect(
			document.querySelector( '.documentate-workflow-notice' ).textContent
		).toContain( STRINGS.archivedMessage );

		document.querySelector( '.documentate-sections-container' ).innerHTML =
			'<input type="text" id="tarde" name="documentate_field[b]">';
		jQuery( document ).trigger( 'ajaxComplete' );

		expect( document.getElementById( 'tarde' ).disabled ).toBe( true );
	} );

	it( 'does nothing at all without a post id', async () => {
		document.body.innerHTML = pantalla( boton( 'documentate-save-draft' ) );
		await arrancar( { postId: 0, isLocked: true } );

		clic( 'documentate-save-draft' );

		expect( submits ).toBe( 0 );
		expect( document.querySelector( '.locked-overlay' ) ).toBeNull();
	} );
} );
