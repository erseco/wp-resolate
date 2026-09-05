/**
 * Document Preview and Download E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Preview and Download', () => {
	/**
	 * Seeded demo document types, matched by the format of the office
	 * template behind them. The editable download offers that format and no
	 * other, so a test that cares about formats has to name its type instead
	 * of taking whichever one happens to sort first.
	 */
	const ODT_TYPE = /\(ODT\)/;
	const DOCX_TYPE = /\(DOCX\)/;

	/**
	 * Call the document generation AJAX endpoint directly from the page
	 * context and return the download URL. Buttons use href="#" with
	 * data-documentate-action attributes; the actual download URL is
	 * returned by the AJAX endpoint.
	 *
	 * @param {import('@playwright/test').Page} page   - Playwright page
	 * @param {string} format                          - 'docx', 'odt', or 'pdf'
	 * @param {string} [output='download']             - 'download' or 'preview'
	 * @return {Promise<string|null>} Download URL or null on failure
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
	 * Create and publish a document of one of the seeded demo types.
	 *
	 * @param {Object} documentEditor - Document editor page object
	 * @param {RegExp} typePattern    - Matches the name of the type to pick
	 * @return {Promise<number|null>} Post ID of the published document
	 */
	async function createDocumentWithType( documentEditor, typePattern = ODT_TYPE ) {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( `Preview Download Test ${ Date.now() }` );

		// Types are listed by name, so pick the one whose template format the
		// test is about rather than the first entry in the dropdown.
		const option = documentEditor.docTypeSelect
			.locator( 'option' )
			.filter( { hasText: typePattern } )
			.first();
		await documentEditor.docTypeSelect.selectOption(
			await option.getAttribute( 'value' )
		);

		// Publish the document
		await documentEditor.publish();

		return await documentEditor.getPostId();
	}

	/**
	 * Get the actions metabox buttons.
	 *
	 * Located by the label a user reads. The two editable-download buttons
	 * are labelled with the bare format name.
	 *
	 * @param {import('@playwright/test').Page} page - Playwright page
	 * @return {Object} Locators keyed by action
	 */
	function getActionButtons( page ) {
		return {
			preview: page.locator( '#documentate_actions a:has-text("Vista previa")' ),
			docx: page.locator( '#documentate_actions a:has-text("DOCX")' ),
			odt: page.locator( '#documentate_actions a:has-text("ODT")' ),
			pdf: page.locator( '#documentate_actions a:has-text("Descargar PDF")' ),
		};
	}

	test.describe( 'PDF Preview', () => {
		test( 'preview button opens PDF directly in browser', async ( {
			documentEditor,
			context,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;
			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			// The native renderer needs nothing configured, so preview is a live
			// link and never the disabled <button> the metabox falls back to.
			await expect( previewButton ).toBeVisible();

			// Headless Chromium has no PDF viewer, so the tab it opens for one
			// never commits and reports an empty URL. Record the address the
			// page hands to the browser instead.
			await page.evaluate( () => {
				window.documentateOpenedUrls = [];
				const nativeOpen = window.open.bind( window );
				window.open = ( url, ...rest ) => {
					window.documentateOpenedUrls.push( url );
					return nativeOpen( url, ...rest );
				};
			} );

			// Awaiting the page event is the assertion that a tab really opened.
			const [ newPage ] = await Promise.all( [
				context.waitForEvent( 'page' ),
				previewButton.click(),
			] );

			const openedUrl = await page.evaluate(
				() => window.documentateOpenedUrls[ 0 ]
			);

			// The tab points straight at the streaming endpoint.
			expect( openedUrl ).toContain( 'action=documentate_preview_stream' );

			// And that endpoint answers with the PDF itself, not with an HTML
			// page wrapping it in an iframe the way the old preview did.
			const streamed = await request.get( openedUrl );
			expect( streamed.status() ).toBe( 200 );
			expect( streamed.headers()[ 'content-type' ] ).toContain( 'application/pdf' );

			await newPage.close();
		} );

		test( 'preview returns correct Content-Type header', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;
			const buttons = getActionButtons( page );

			await expect( buttons.preview.first() ).toBeVisible();

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real preview URL.
			const previewUrl = await getDownloadUrlViaAjax( page, 'pdf', 'preview' );

			// The native engine draws the PDF in this process, so generation
			// succeeds with no conversion service reachable.
			expect( previewUrl ).toBeTruthy();

			// Make a request and check headers
			const response = await request.get( previewUrl );

			// Should return 200 OK
			expect( response.status() ).toBe( 200 );

			// Content-Type should be application/pdf
			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			// Content-Disposition should be inline (not attachment)
			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'inline' );
		} );
	} );

	test.describe( 'Editable Download', () => {
		test( 'a type with an ODT template offers ODT and no DOCX', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor, ODT_TYPE );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;
			const buttons = getActionButtons( page );

			// The editable download hands over the rendered template itself, so
			// the only format on offer is the one the type has a template in.
			await expect( buttons.odt ).toHaveCount( 1 );
			await expect( buttons.docx ).toHaveCount( 0 );

			await expect(
				page.locator( '#documentate_actions .documentate-actions-secondary__label' )
			).toHaveText( 'Descarga editable:' );
		} );

		test( 'a type with a DOCX template offers DOCX and no ODT', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor, DOCX_TYPE );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );

			// Mirror of the rule above: nothing is converted between office
			// formats in either direction, so a DOCX type never offers ODT.
			await expect( buttons.docx ).toHaveCount( 1 );
			await expect( buttons.odt ).toHaveCount( 0 );
		} );

		test( 'DOCX download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor, DOCX_TYPE );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'docx' );

			expect( downloadUrl ).toBeTruthy();

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'ODT Download', () => {
		test( 'ODT button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor, ODT_TYPE );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Pre-check: verify ODT generation is available.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'odt' );
			expect( downloadUrl ).toBeTruthy();

			const buttons = getActionButtons( page );
			const odtButton = buttons.odt.first();

			// Start waiting for download before clicking
			const downloadPromise = page.waitForEvent( 'download' );
			await odtButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.odt$/i );
		} );

		test( 'ODT download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor, ODT_TYPE );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'odt' );

			expect( downloadUrl ).toBeTruthy();

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.oasis.opendocument.text' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'PDF Download', () => {
		test( 'PDF button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Pre-check: the native engine renders the PDF in this process, with
			// no conversion service in the way.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'pdf' );
			expect( downloadUrl ).toBeTruthy();

			const buttons = getActionButtons( page );
			const pdfButton = buttons.pdf.first();

			// Start waiting for download before clicking
			const downloadPromise = page.waitForEvent( 'download' );
			await pdfButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.pdf$/i );
		} );

		test( 'PDF download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'pdf' );

			expect( downloadUrl ).toBeTruthy();

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'Actions Metabox', () => {
		test( 'actions metabox is visible on document edit page', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const metabox = documentEditor.page.locator( '#documentate_actions' );
			await expect( metabox ).toBeVisible();
		} );

		test( 'preview button uses AJAX with data attributes', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			await expect( previewButton ).toBeVisible();

			// New AJAX-based buttons have data attributes instead of direct URLs
			const action = await previewButton.getAttribute( 'data-documentate-action' );
			const format = await previewButton.getAttribute( 'data-documentate-format' );
			expect( action ).toBe( 'preview' );
			expect( format ).toBe( 'pdf' );
			expect( await previewButton.getAttribute( 'href' ) ).toBe( '#' );
		} );

		test( 'a type with a template leaves no action disabled', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;
			const buttons = getActionButtons( page );

			// Preview and PDF render as links when they are available and as
			// disabled <button> elements carrying a reason when they are not.
			// The native engine is always available, so a type that has a
			// template gets the links and nothing is left disabled.
			await expect( buttons.preview ).toHaveCount( 1 );
			await expect( buttons.pdf ).toHaveCount( 1 );
			await expect(
				page.locator( '#documentate_actions button[disabled]' )
			).toHaveCount( 0 );
		} );

		test( 'clicking action button shows loading modal', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			// Use a download action: preview would open a pop-up window.
			const actionButton = documentEditor.page.locator(
				'#documentate_actions a[data-documentate-action="download"]'
			).first();

			await expect( actionButton ).toBeVisible();

			// Watch for the modal before clicking. The native engine draws the PDF
			// in milliseconds, so the modal opens and closes again faster than an
			// assertion can catch it standing still; what the test is about is
			// that it was shown at all.
			await documentEditor.page.evaluate( () => {
				window.__documentateModalShown = false;
				const seen = () => {
					const modal = document.querySelector( '#documentate-loading-modal' );
					if ( modal && modal.classList.contains( 'is-visible' ) ) {
						window.__documentateModalShown = true;
					}
				};
				seen();
				// The modal is created on demand, so watch the whole document:
				// it may not exist yet when this runs.
				new window.MutationObserver( seen ).observe( document.documentElement, {
					subtree: true,
					childList: true,
					attributes: true,
					attributeFilter: [ 'class' ],
				} );
			} );

			await actionButton.click();

			await documentEditor.page.waitForFunction(
				() => true === window.__documentateModalShown,
				null,
				{ timeout: 10000 }
			);

			// And the modal carries the loading UI it is supposed to.
			const modal = documentEditor.page.locator( '#documentate-loading-modal' );
			await expect( modal.locator( '.documentate-loading-modal__spinner' ) ).toHaveCount( 1 );
			await expect( modal.locator( '.documentate-loading-modal__title' ) ).toHaveCount( 1 );
		} );
	} );
} );
