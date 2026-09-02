/**
 * Custom Playwright fixtures for Documentate E2E tests.
 *
 * Extends WordPress E2E test utilities with:
 * - Page Object Model fixtures
 * - UI-based document creation (CPT has REST API disabled for security)
 * - Reusable test data fixtures
 *
 * NOTE: The documentate_document CPT has show_in_rest => false for security,
 * so we use UI-based creation for documents. Document types taxonomy does
 * have REST API enabled.
 *
 * @see https://playwright.dev/docs/test-fixtures
 * @see https://github.com/WordPress/gutenberg/tree/trunk/packages/e2e-test-utils-playwright
 */

const wpTestUtils = require( '@wordpress/e2e-test-utils-playwright' );
const baseTest = wpTestUtils.test;
const baseExpect = wpTestUtils.expect;
const { DocumentEditorPage } = require( '../page-objects/document-editor.page' );
const { DocumentTypesPage } = require( '../page-objects/document-types.page' );
const { SettingsPage } = require( '../page-objects/settings.page' );
const { DocumentsListPage } = require( '../page-objects/documents-list.page' );

/**
 * Call the document generation AJAX endpoint from the page context and return
 * the download URL.
 *
 * The export buttons are anchors with `href="#"` and
 * `data-documentate-action` attributes: the real URL is what the endpoint
 * answers, so specs that need the file itself ask for it here.
 *
 * @param {import('@playwright/test').Page} page             Page carrying documentateActionsConfig.
 * @param {string}                          format           'docx', 'odt' or 'pdf'.
 * @param {string}                          [output='download'] 'download' or 'preview'.
 * @return {Promise<string|null>} Generated file URL, or null when generation failed.
 */
async function getDownloadUrlViaAjax( page, format, output = 'download' ) {
	return await page.evaluate(
		async ( { fmt, out } ) => {
			const cfg = window.documentateActionsConfig;
			if ( ! cfg || ! cfg.ajaxUrl || ! cfg.postId ) {
				return null;
			}

			const body = new URLSearchParams( {
				action: 'documentate_generate_document',
				post_id: cfg.postId,
				format: fmt,
				output: out,
				_wpnonce: cfg.nonce,
			} );

			const resp = await fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			if ( ! resp.ok ) {
				return null;
			}

			const json = await resp.json();
			return json.success && json.data?.url ? json.data.url : null;
		},
		{ fmt: format, out: output }
	);
}

/**
 * Fill every required field of the application editor form so the browser
 * lets it submit.
 *
 * Native controls get `value`; rich editors get it through their code tab,
 * which is what the form posts while that tab is active. The two fields of
 * "Datos básicos" are left alone: the specs own them.
 *
 * @param {import('@playwright/test').Page} page  Page on the edit view.
 * @param {string}                          value Text to put in the fields.
 * @return {Promise<void>}
 */
async function fillRequiredAppFields( page, value ) {
	const form = page.locator( 'form.dcta-editor' );

	// Amounts documentate-calculos.js owns are readonly, and Playwright
	// refuses to fill those; they already carry the computed value.
	const basicos = ':not(#documentate-app-titulo):not(#documentate-app-nombre)';
	const editables = `input[required]:not([type="hidden"]):not([readonly]):not([data-calculado])${ basicos }, textarea[required]:not([readonly])${ basicos }`;

	for ( const control of await form.locator( editables ).all() ) {
		const type = await control.getAttribute( 'type' );
		if ( 'checkbox' === type ) {
			await control.check();
		} else if ( 'number' === type ) {
			await control.fill( '1' );
		} else if ( 'date' === type ) {
			await control.fill( '2026-09-01' );
		} else {
			await control.fill( value );
		}
	}

	for ( const select of await form.locator( 'select[required]' ).all() ) {
		await select.selectOption( { index: 1 } );
	}

	for ( const wrap of await form
		.locator( '.documentate-rich-editor-wrap[data-required="true"]' )
		.all() ) {
		await wrap.locator( '.switch-html' ).click();
		await wrap.locator( 'textarea.wp-editor-area' ).fill( `<p>${ value }</p>` );
	}
}

/**
 * UI-based helper functions for document creation.
 * Used because documentate_document CPT has show_in_rest => false.
 */
const documentHelpers = {
	/**
	 * Create a document via UI navigation.
	 *
	 * @param {Object} page  - Playwright page object
	 * @param {Object} admin - WordPress admin helper
	 * @param {Object} data  - Document data
	 * @param {string} data.title - Document title
	 * @param {string} [data.status='draft'] - Post status ('draft' or 'publish')
	 * @return {Promise<Object>} Created document object with id, title
	 */
	async createDocument( page, admin, { title, status = 'draft' } = {} ) {
		const docTitle = title || `Test Document ${ Date.now() }`;

		// Navigate to new document page
		await admin.visitAdminPage( 'post-new.php', 'post_type=documentate_document' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Wait for custom title textarea
		await page.locator( '#documentate_title_textarea' )
			.waitFor( { state: 'visible', timeout: 5000 } )
			.catch( () => {} );

		// Fill title
		const customTitle = page.locator( '#documentate_title_textarea' );
		const titleInput = page.locator( '#title' );

		if ( await customTitle.isVisible().catch( () => false ) ) {
			await customTitle.fill( docTitle );
		} else if ( await titleInput.count() > 0 ) {
			await titleInput.fill( docTitle, { force: true } );
		}

		// Save or publish based on status
		if ( status === 'publish' ) {
			// Follow the workflow: Save Draft → Send to Review → Approve & Publish.
			const sendReviewBtn = page.locator( '#documentate-send-review' );
			const approveBtn = page.locator( '#documentate-approve-publish' );

			if ( await approveBtn.isVisible().catch( () => false ) ) {
				await approveBtn.click();
			} else {
				// A new document (auto-draft) only offers "Save Draft" —
				// "Send to Review" appears once the draft has been saved.
				if ( ! ( await sendReviewBtn.isVisible().catch( () => false ) ) ) {
					await page.locator( '#documentate-save-draft' ).click();
					await page.locator( '#message.updated, .notice-success' )
						.first()
						.waitFor( { state: 'visible', timeout: 10000 } )
						.catch( () => {} );
				}
				await sendReviewBtn.click();
				await page.locator( '#message.updated, .notice-success' )
					.first()
					.waitFor( { state: 'visible', timeout: 10000 } )
					.catch( () => {} );
				await approveBtn.click();
			}
		} else {
			const draftBtn = page.locator( '#documentate-save-draft' ).or(
				page.getByRole( 'button', { name: /save draft|guardar borrador/i } )
			);
			await draftBtn.click();
		}

		// Wait for save to complete
		await page.locator( '#message.updated, .notice-success' )
			.first()
			.waitFor( { state: 'visible', timeout: 10000 } )
			.catch( () => {} );

		// Get post ID from URL
		const url = page.url();
		const match = url.match( /post=(\d+)/ );
		const postId = match ? parseInt( match[ 1 ], 10 ) : null;

		return {
			id: postId,
			title: docTitle,
			status,
		};
	},

	/**
	 * Delete a document via UI (move to trash then delete permanently).
	 *
	 * @param {Object} page   - Playwright page object
	 * @param {Object} admin  - WordPress admin helper
	 * @param {number} postId - Post ID to delete
	 * @return {Promise<void>}
	 */
	async deleteDocument( page, admin, postId ) {
		// Go to document edit page
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// Click trash link
		const trashLink = page.getByRole( 'link', { name: /move to trash|mover a la papelera/i } ).or(
			page.locator( '#delete-action a, .submitdelete' )
		);
		if ( await trashLink.isVisible().catch( () => false ) ) {
			await trashLink.click();
			await page.waitForURL( /post_type=documentate_document/ );
		}
	},
};

/**
 * REST API helper functions for document types (taxonomy has REST API enabled).
 */
const restApiHelpers = {
	/**
	 * Create a document type term via REST API.
	 *
	 * @param {Object} requestUtils   - WordPress request utilities
	 * @param {Object} data           - Term data
	 * @param {string} data.name      - Term name
	 * @param {string} [data.slug]    - Term slug
	 * @param {string} [data.description] - Term description
	 * @return {Promise<Object>} Created term object
	 */
	async createDocumentType( requestUtils, { name, slug, description } = {} ) {
		const data = {
			name: name || `Test Type ${ Date.now() }`,
		};

		if ( slug ) {
			data.slug = slug;
		}
		if ( description ) {
			data.description = description;
		}

		const response = await requestUtils.rest( {
			path: '/wp/v2/documentate_doc_type',
			method: 'POST',
			data,
		} );

		return {
			id: response.id,
			name: response.name,
			slug: response.slug,
		};
	},

	/**
	 * Delete a document type term via REST API.
	 *
	 * @param {Object} requestUtils - WordPress request utilities
	 * @param {number} termId       - Term ID to delete
	 * @param {boolean} [force=true] - Whether to force delete
	 * @return {Promise<void>}
	 */
	async deleteDocumentType( requestUtils, termId, force = true ) {
		await requestUtils.rest( {
			path: `/wp/v2/documentate_doc_type/${ termId }`,
			method: 'DELETE',
			data: { force },
		} );
	},

	/**
	 * Get all document types via REST API.
	 *
	 * @param {Object} requestUtils - WordPress request utilities
	 * @return {Promise<Array>} Array of document type terms
	 */
	async getDocumentTypes( requestUtils ) {
		return await requestUtils.rest( {
			path: '/wp/v2/documentate_doc_type',
			method: 'GET',
		} );
	},
};

/**
 * Extended test with Page Object Model fixtures.
 *
 * Note: Documents are created via UI because the CPT has show_in_rest => false.
 * Document types can use REST API (taxonomy has it enabled).
 */
const test = baseTest.extend( {
	/**
	 * Document Editor page object for interacting with the document edit screen.
	 */
	documentEditor: async ( { page, admin }, use ) => {
		await use( new DocumentEditorPage( page, admin ) );
	},

	/**
	 * Document Types page object for managing document type taxonomy.
	 */
	documentTypes: async ( { page, admin }, use ) => {
		await use( new DocumentTypesPage( page, admin ) );
	},

	/**
	 * Settings page object for plugin configuration.
	 */
	settingsPage: async ( { page, admin }, use ) => {
		await use( new SettingsPage( page, admin ) );
	},

	/**
	 * Documents list page object for the admin list view.
	 */
	documentsList: async ( { page, admin }, use ) => {
		await use( new DocumentsListPage( page, admin ) );
	},

	/**
	 * Pre-created test document fixture (created via UI).
	 * Automatically creates a document before the test and cleans up after.
	 */
	testDocument: async ( { page, admin }, use ) => {
		const doc = await documentHelpers.createDocument( page, admin, {
			title: `Test Document ${ Date.now() }`,
			status: 'draft',
		} );

		await use( doc );

		// Cleanup: delete the document after test
		if ( doc.id ) {
			await documentHelpers.deleteDocument( page, admin, doc.id ).catch( () => {
				// Ignore errors if document was already deleted by the test
			} );
		}
	},

	/**
	 * Pre-created published document fixture (created via UI).
	 */
	publishedDocument: async ( { page, admin }, use ) => {
		const doc = await documentHelpers.createDocument( page, admin, {
			title: `Published Document ${ Date.now() }`,
			status: 'publish',
		} );

		await use( doc );

		if ( doc.id ) {
			await documentHelpers.deleteDocument( page, admin, doc.id ).catch( () => {} );
		}
	},

	/**
	 * REST API helpers for document types (taxonomy has REST API enabled).
	 */
	restApi: async ( { requestUtils }, use ) => {
		await use( {
			createDocumentType: ( data ) => restApiHelpers.createDocumentType( requestUtils, data ),
			deleteDocumentType: ( termId, force ) => restApiHelpers.deleteDocumentType( requestUtils, termId, force ),
			getDocumentTypes: () => restApiHelpers.getDocumentTypes( requestUtils ),
		} );
	},
} );

module.exports = {
	test,
	expect: baseExpect,
	documentHelpers,
	restApiHelpers,
	getDownloadUrlViaAjax,
	fillRequiredAppFields,
};
