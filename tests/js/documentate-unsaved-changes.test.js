/**
 * Tests for the unsaved-changes guard.
 *
 * The guard exists because document generation reads saved post meta, so acting
 * on a dirty form produces a file built from the previous version. The dirty
 * detection it replaced was dead code: it probed `wp.data.select('core/editor')`
 * on a screen where Gutenberg is disabled, and `wp.autosave.server.isDirty()`,
 * which is not part of WordPress. These tests pin the two properties that
 * failure violated — plain fields count as changes, and a change blocks the
 * action — plus the save-and-resume handshake.
 */
const POST_ID = 42;
const STORAGE_KEY = `documentate_pending_action_${ POST_ID }`;

const FIXTURE = `
	<form id="post">
		<input type="hidden" id="post_ID" name="post_ID" value="${ POST_ID }">
		<input type="text" id="field-a" name="documentate_field[a]">
		<select id="field-b" name="documentate_field[b]">
			<option value="1">1</option>
			<option value="2">2</option>
		</select>
		<input type="hidden" id="documentate-attachments-field" name="documentate_attachments">
		<div class="ProseMirror" contenteditable="true"></div>
		<div class="documentate-array-items">
			<div class="documentate-array-item"></div>
		</div>
		<button type="button" class="documentate-array-add">Add</button>
		<div id="documentate_actions">
			<p class="documentate-unsaved-indicator" role="status" hidden>
				<span class="documentate-unsaved-indicator__dot"></span>Cambios sin guardar
			</p>
			<a href="#" id="btn-preview" data-documentate-action="preview" data-documentate-format="pdf">Preview</a>
			<a href="#" id="btn-pdf" data-documentate-action="download" data-documentate-format="pdf">PDF</a>
			<a href="#" id="btn-sign" data-documentate-action="sign" data-documentate-format="pdf">Sign</a>
		</div>
	</form>
`;

let downstream;
let downstreamListener;
let submitSpy;
const instances = [];

/**
 * Evaluate one browser module against the current DOM.
 *
 * isolateModules re-evaluates the file on every call, the way the browser runs
 * it on a fresh page — and unlike new Function( source ) it goes through the
 * module registry, so the coverage report sees it.
 *
 * @param {string} ruta Path of the module, relative to this file.
 * @return {void}
 */
function cargarModulo( ruta ) {
	jest.isolateModules( () => {
		require( ruta );
	} );
}

/**
 * Evaluate the guard against the current DOM.
 *
 * The module is a self-executing IIFE rather than a CommonJS module, so it is
 * evaluated fresh per test to reset its closure state.
 *
 * @return {Promise<void>} Resolves once jQuery's ready callbacks have run.
 */
async function loadGuard() {
	cargarModulo( '../../admin/js/documentate-unsaved-changes.js' );
	// jQuery defers ready callbacks by a tick when the document is already loaded.
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	instances.push( window.documentateUnsavedChanges );
}

/**
 * Click an element the way a user would.
 *
 * @param {string} selector Element selector.
 * @return {MouseEvent} The dispatched event, for inspecting defaultPrevented.
 */
function click( selector ) {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );
	document.querySelector( selector ).dispatchEvent( event );
	return event;
}

beforeAll( () => {
	global.jQuery = require( 'jquery' );
	global.$ = global.jQuery;
} );

beforeEach( () => {
	document.body.innerHTML = FIXTURE;
	window.sessionStorage.clear();
	window.documentateUnsavedChangesConfig = { postId: POST_ID, strings: {} };
	delete window.tinyMCE;

	// jsdom does not implement form submission; the guard only needs to know it
	// was requested.
	submitSpy = jest.fn();
	document.getElementById( 'post' ).submit = submitSpy;

	// Stands in for the documentate-actions handler the guard gates. It is
	// delegated on the action attribute, like the real one, so clicks on the
	// dialog's own buttons are not mistaken for the action running.
	downstream = jest.fn();
	downstreamListener = ( event ) => {
		if ( event.target.closest( '[data-documentate-action]' ) ) {
			downstream( event );
		}
	};
	document.addEventListener( 'click', downstreamListener );
} );

afterEach( () => {
	document.removeEventListener( 'click', downstreamListener );
	document.querySelectorAll( '.documentate-unsaved-modal' ).forEach( ( node ) => node.remove() );

	// Every instance keeps its capture listener on `document` for the lifetime of
	// the jsdom page. A leftover still holding a dirty flag would swallow the
	// clicks of later tests through stopImmediatePropagation(), so they are all
	// reset rather than merely abandoned.
	instances.forEach( ( instance ) => instance.markClean() );
} );

describe( 'dirty detection', () => {
	it( 'starts clean on a freshly loaded document', async () => {
		await loadGuard();

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( false );
		expect( document.querySelector( '.documentate-unsaved-indicator' ).hidden ).toBe( true );
	} );

	it( 'notices typing in a plain text field', async () => {
		await loadGuard();

		document.getElementById( 'field-a' ).value = 'nuevo texto';
		document
			.getElementById( 'field-a' )
			.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'notices a select changing', async () => {
		await loadGuard();

		document
			.getElementById( 'field-b' )
			.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'notices edits in a ProseMirror surface', async () => {
		await loadGuard();

		document
			.querySelector( '.ProseMirror' )
			.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'notices repeater rows being added', async () => {
		await loadGuard();

		click( '.documentate-array-add' );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'notices a jQuery-triggered change on a hidden field', async () => {
		// The attachments meta box writes its hidden field programmatically and
		// announces it with jQuery, which never reaches a native listener.
		await loadGuard();

		global.jQuery( '#documentate-attachments-field' ).val( '7,9' ).trigger( 'change' );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'notices a TinyMCE editor reporting itself dirty', async () => {
		const handlers = {};
		window.tinyMCE = {
			on: jest.fn(),
			editors: [
				{
					initialized: true,
					on: ( events, callback ) => {
						events.split( ' ' ).forEach( ( name ) => {
							handlers[ name ] = callback;
						} );
					},
				},
			],
		};

		await loadGuard();
		handlers.Dirty();

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
	} );

	it( 'reveals the passive indicator on the first change', async () => {
		await loadGuard();
		const indicator = document.querySelector( '.documentate-unsaved-indicator' );

		expect( indicator.hidden ).toBe( true );

		document
			.getElementById( 'field-a' )
			.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( indicator.hidden ).toBe( false );
	} );
} );

describe( 'action gate', () => {
	it( 'lets actions through while the document is clean', async () => {
		await loadGuard();

		const event = click( '#btn-preview' );

		expect( event.defaultPrevented ).toBe( false );
		expect( downstream ).toHaveBeenCalled();
		expect( document.querySelector( '.documentate-unsaved-modal.is-visible' ) ).toBeNull();
	} );

	it( 'blocks the action and opens the dialog once dirty', async () => {
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();

		const event = click( '#btn-preview' );

		expect( event.defaultPrevented ).toBe( true );
		expect( downstream ).not.toHaveBeenCalled();
		expect( document.querySelector( '.documentate-unsaved-modal.is-visible' ) ).not.toBeNull();
	} );

	it( 'gates the signing action too', async () => {
		// documentate-autofirma handles signing on the capture phase and stops
		// propagation, so the guard has to run before it.
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();

		expect( click( '#btn-sign' ).defaultPrevented ).toBe( true );
	} );

	it( 'replays the action when the saved version is accepted', async () => {
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();
		click( '#btn-preview' );

		document.querySelector( '.documentate-unsaved-modal__secondary' ).click();

		expect( downstream ).toHaveBeenCalled();
		expect( submitSpy ).not.toHaveBeenCalled();
	} );

	it( 'cancelling neither saves nor runs the action', async () => {
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();
		click( '#btn-preview' );

		document.querySelector( '.documentate-unsaved-modal__cancel' ).click();

		expect( downstream ).not.toHaveBeenCalled();
		expect( submitSpy ).not.toHaveBeenCalled();
		expect( document.querySelector( '.documentate-unsaved-modal.is-visible' ) ).toBeNull();
	} );
} );

describe( 'the form of the front-end application', () => {
	// The app editor is not #post: without config.formSelector the guard binds
	// nothing and the export buttons act on the previously saved version.
	beforeEach( () => {
		document.body.innerHTML = FIXTURE.replace(
			'<form id="post">',
			'<form class="dcta-editor">'
		).replace( 'id="post_ID"', 'id="post_ID_app"' );
		window.documentateUnsavedChangesConfig = {
			postId: POST_ID,
			formSelector: 'form.dcta-editor',
			strings: {},
		};
		submitSpy = jest.fn();
		document.querySelector( 'form.dcta-editor' ).submit = submitSpy;
	} );

	it( 'notices a change in the app editor', async () => {
		await loadGuard();

		const campo = document.getElementById( 'field-a' );
		campo.value = 'Bloque I';
		campo.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );

		expect( window.documentateUnsavedChanges.isDirty() ).toBe( true );
		expect(
			document.querySelector( '.documentate-unsaved-indicator' ).hidden
		).toBe( false );
	} );

	it( 'saves that form before resuming the export', async () => {
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();
		click( '#btn-pdf' );

		document.querySelector( '.documentate-unsaved-modal__primary' ).click();

		expect( submitSpy ).toHaveBeenCalled();
		expect(
			JSON.parse( window.sessionStorage.getItem( STORAGE_KEY ) )
		).toMatchObject( { action: 'download', format: 'pdf' } );
	} );
} );

describe( 'save and resume', () => {
	it( 'submits the form and stores the action to replay', async () => {
		await loadGuard();
		window.documentateUnsavedChanges.markDirty();
		click( '#btn-pdf' );

		document.querySelector( '.documentate-unsaved-modal__primary' ).click();

		expect( submitSpy ).toHaveBeenCalled();
		expect( JSON.parse( window.sessionStorage.getItem( STORAGE_KEY ) ) ).toMatchObject( {
			action: 'download',
			format: 'pdf',
		} );
	} );

	it( 'flushes TinyMCE before submitting', async () => {
		// jQuery's submit trigger calls the native form.submit(), which skips
		// TinyMCE's own submit listener, so the editors must be flushed by hand.
		const triggerSave = jest.fn();
		window.tinyMCE = { on: jest.fn(), editors: [], triggerSave };

		await loadGuard();
		window.documentateUnsavedChanges.markDirty();
		click( '#btn-pdf' );
		document.querySelector( '.documentate-unsaved-modal__primary' ).click();

		expect( triggerSave ).toHaveBeenCalled();
	} );

	it( 'replays a stored action after the reload', async () => {
		window.sessionStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { action: 'download', format: 'pdf', ts: Date.now() } )
		);

		await loadGuard();
		window.documentateUnsavedChanges.resumePendingAction();

		expect( downstream ).toHaveBeenCalled();
		expect( window.sessionStorage.getItem( STORAGE_KEY ) ).toBeNull();
	} );

	it( 'discards a stored action that is older than the replay window', async () => {
		window.sessionStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( {
				action: 'download',
				format: 'pdf',
				ts: Date.now() - 200000,
			} )
		);

		await loadGuard();
		window.documentateUnsavedChanges.resumePendingAction();

		expect( downstream ).not.toHaveBeenCalled();
		expect( window.sessionStorage.getItem( STORAGE_KEY ) ).toBeNull();
	} );

	it( 'waits for a click before resuming a signature', async () => {
		// AutoFirma needs a user gesture, which a replay on page load is not.
		window.sessionStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { action: 'sign', format: 'pdf', ts: Date.now() } )
		);

		await loadGuard();
		window.documentateUnsavedChanges.resumePendingAction();

		expect( downstream ).not.toHaveBeenCalled();
		expect( document.querySelector( '.documentate-unsaved-modal.is-visible' ) ).not.toBeNull();

		document.querySelector( '.documentate-unsaved-modal__primary' ).click();

		expect( downstream ).toHaveBeenCalled();
	} );
} );

describe( 'resume against the real action handler', () => {
	let ajaxSpy;

	beforeEach( () => {
		// The stand-in bound in the outer beforeEach listens from the start, which
		// is exactly the ordering these tests must not assume.
		document.removeEventListener( 'click', downstreamListener );

		window.documentateActionsConfig = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			postId: POST_ID,
			nonce: 'test-nonce',
			strings: {},
		};

		ajaxSpy = jest.fn();
		global.jQuery.ajax = ajaxSpy;
	} );

	afterEach( () => {
		// Each evaluation of documentate-actions delegates another handler on the
		// document, which outlives the fixture and would double-count later runs.
		global.jQuery( document ).off( 'click' );
		delete window.documentateActionsConfig;
	} );

	/**
	 * Evaluate both modules in the order the page loads them.
	 *
	 * documentate-actions declares the guard as a dependency, so the guard runs
	 * first and its jQuery ready callback is queued ahead of the one that binds
	 * the action click handler.
	 *
	 * @return {Promise<void>} Resolves once the ready queue has drained.
	 */
	async function loadPage() {
		cargarModulo( '../../admin/js/documentate-unsaved-changes.js' );
		cargarModulo( '../../admin/js/documentate-actions.js' );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		instances.push( window.documentateUnsavedChanges );
	}

	it( 'generates the document the save interrupted', async () => {
		// The replay is a synthetic click, so it only does something once
		// documentate-actions has delegated its handler. Firing it earlier lands
		// on nothing and the action is lost, with the stored entry consumed.
		window.sessionStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { action: 'download', format: 'pdf', ts: Date.now() } )
		);

		await loadPage();

		expect( ajaxSpy ).toHaveBeenCalledTimes( 1 );
		expect( ajaxSpy.mock.calls[ 0 ][ 0 ].data ).toMatchObject( {
			action: 'documentate_generate_document',
			post_id: POST_ID,
			format: 'pdf',
			output: 'download',
		} );
	} );

	it( 'generates nothing when no action was interrupted', async () => {
		await loadPage();

		expect( ajaxSpy ).not.toHaveBeenCalled();
	} );
} );
