/**
 * Page Object Model for the Document Editor page.
 *
 * Uses accessible selectors (getByRole, getByLabel) following
 * WordPress/Gutenberg E2E best practices.
 *
 * @see https://developer.wordpress.org/block-editor/contributors/code/testing-overview/e2e/
 */

class DocumentEditorPage {
	/**
	 * @param {import('@playwright/test').Page} page - Playwright page object
	 * @param {Object} admin - WordPress admin utilities
	 */
	constructor( page, admin ) {
		this.page = page;
		this.admin = admin;
	}

	// ─────────────────────────────────────────────────────────────────
	// Locators (lazy getters using accessible selectors)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The custom title textarea (replaced #title input).
	 * Falls back to #title if custom textarea not found.
	 */
	get titleField() {
		// The plugin creates a custom textarea with id="documentate_title_textarea"
		// We first try to find it by its role, then fall back to the hidden #title
		return this.page.locator( '#documentate_title_textarea' );
	}

	/**
	 * The original WordPress title input (may be hidden).
	 */
	get titleInput() {
		return this.page.locator( '#title' );
	}

	/**
	 * Document type metabox (now part of the unified management metabox).
	 */
	get docTypeMetabox() {
		return this.page.locator( '#documentate_document_management .documentate-doc-type-section' );
	}

	/**
	 * Document type select dropdown.
	 */
	get docTypeSelect() {
		return this.page.locator( 'select[name="documentate_doc_type"]' );
	}

	/**
	 * All document type options in the select (excluding the placeholder).
	 */
	get docTypeOptions() {
		return this.docTypeSelect.locator( 'option:not([value=""])' );
	}

	/**
	 * The fields metabox (contains template-defined fields).
	 */
	get fieldsMetabox() {
		return this.page.locator( '#documentate-fields-metabox, #documentate_fields' );
	}

	/**
	 * All document field inputs.
	 */
	get fieldInputs() {
		return this.page.locator( 'input[name^="documentate_field_"], textarea[name^="documentate_field_"]' );
	}

	/**
	 * Save Draft button.
	 */
	get saveDraftButton() {
		return this.page.locator( '#documentate-save-draft' ).or(
			this.page.getByRole( 'button', { name: /save draft/i } )
		);
	}

	/**
	 * Publish/Update button.
	 * Supports the Approve & Publish button from pending review state.
	 */
	get publishButton() {
		return this.page.locator( '#documentate-approve-publish' ).or(
			this.page.getByRole( 'button', { name: /publish|update/i } )
		).or(
			this.page.locator( '#publish' )
		);
	}

	/**
	 * Send to Review button.
	 */
	get sendToReviewButton() {
		return this.page.locator( '#documentate-send-review' );
	}

	/**
	 * Return to Draft / Revert to Draft button.
	 */
	get returnToDraftButton() {
		return this.page.locator( '#documentate-return-draft' );
	}

	/**
	 * Return to Review button (from published state).
	 */
	get returnToReviewButton() {
		return this.page.locator( '#documentate-return-review' );
	}

	/**
	 * Save (pending) button.
	 */
	get savePendingButton() {
		return this.page.locator( '#documentate-save-pending' );
	}

	/**
	 * Approve & Publish button.
	 */
	get approvePublishButton() {
		return this.page.locator( '#documentate-approve-publish' );
	}

	/**
	 * Move to Trash link.
	 */
	get trashLink() {
		return this.page.getByRole( 'link', { name: /move to trash/i } ).or(
			this.page.locator( '#delete-action a, .submitdelete' )
		);
	}

	/**
	 * Success notice after save.
	 */
	get successNotice() {
		return this.page.locator( '#message.updated, .notice-success' );
	}

	/**
	 * Metadata metabox.
	 */
	get metadataMetabox() {
		return this.page.locator( '#documentate-meta-box, #documentate_document_meta' );
	}

	/**
	 * Author input field in metadata.
	 */
	get authorField() {
		return this.page.locator( '#documentate_meta_author' ).or(
			this.page.locator( 'input[name="documentate_meta_author"]' )
		);
	}

	/**
	 * Keywords input field in metadata.
	 */
	get keywordsField() {
		return this.page.locator( '#documentate_meta_keywords' ).or(
			this.page.locator( 'input[name="documentate_meta_keywords"], textarea[name="documentate_meta_keywords"]' )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Navigation
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Navigate to the new document page.
	 */
	async navigateToNew() {
		await this.admin.visitAdminPage( 'post-new.php', 'post_type=documentate_document' );
		await this.page.waitForLoadState( 'domcontentloaded' );
		// Wait for the custom title textarea to be available
		await this.titleField.waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {} );
	}

	/**
	 * Navigate to edit an existing document.
	 *
	 * @param {number} postId - Document post ID
	 */
	async navigateToEdit( postId ) {
		await this.admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await this.page.waitForLoadState( 'domcontentloaded' );
		await this.titleField.waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {} );
	}

	// ─────────────────────────────────────────────────────────────────
	// Actions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Fill the document title.
	 *
	 * @param {string} title - Title text
	 */
	async fillTitle( title ) {
		// Try custom textarea first
		if ( await this.titleField.isVisible().catch( () => false ) ) {
			await this.titleField.fill( title );
		} else if ( await this.titleInput.count() > 0 ) {
			// Fall back to hidden #title input
			await this.titleInput.fill( title, { force: true } );
		}
	}

	/**
	 * Get the current title value.
	 *
	 * @return {Promise<string>} Current title
	 */
	async getTitle() {
		if ( await this.titleField.isVisible().catch( () => false ) ) {
			return await this.titleField.inputValue();
		}
		return await this.titleInput.inputValue();
	}

	/**
	 * Select a document type by name.
	 *
	 * @param {string} typeName - Document type name
	 */
	async selectDocType( typeName ) {
		await this.docTypeSelect.selectOption( { label: typeName } );
	}

	/**
	 * Select the first available document type.
	 *
	 * @return {Promise<boolean>} True if a type was selected
	 */
	async selectFirstDocType() {
		const options = this.docTypeOptions;
		if ( await options.count() > 0 ) {
			const value = await options.first().getAttribute( 'value' );
			await this.docTypeSelect.selectOption( value );
			return true;
		}
		return false;
	}

	/**
	 * Check if any document types are available.
	 *
	 * @return {Promise<boolean>} True if types exist
	 */
	async hasDocTypes() {
		const select = this.docTypeSelect;
		if ( await select.count() === 0 ) {
			return false;
		}
		return ( await this.docTypeOptions.count() ) > 0;
	}

	/**
	 * Check if the document type is locked (shown as text, not a select).
	 *
	 * @return {Promise<boolean>} True if locked
	 */
	async isDocTypeLocked() {
		// When locked, the select is replaced with a hidden input + text display.
		return ( await this.docTypeSelect.count() ) === 0;
	}

	/**
	 * Fill a text field by its slug.
	 *
	 * @param {string} fieldSlug - Field slug (without prefix)
	 * @param {string} value     - Value to fill
	 */
	async fillField( fieldSlug, value ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		await field.fill( value );
	}

	/**
	 * Get a field's value by its slug.
	 *
	 * @param {string} fieldSlug - Field slug
	 * @return {Promise<string>} Field value
	 */
	async getFieldValue( fieldSlug ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		return await field.inputValue();
	}

	/**
	 * Get the first text field locator.
	 *
	 * @return {import('@playwright/test').Locator} Locator for first text field
	 */
	getFirstTextField() {
		return this.page.locator( 'input[name^="documentate_field_"][type="text"]' ).first();
	}

	/**
	 * Fill metadata fields.
	 *
	 * @param {Object} metadata          - Metadata values
	 * @param {string} [metadata.author] - Author name
	 * @param {string} [metadata.keywords] - Keywords
	 */
	async fillMetadata( { author, keywords } = {} ) {
		if ( author && await this.authorField.count() > 0 ) {
			await this.authorField.fill( author );
		}
		if ( keywords && await this.keywordsField.count() > 0 ) {
			await this.keywordsField.fill( keywords );
		}
	}

	/**
	 * Fill all visible required fields that are currently empty with placeholder data.
	 *
	 * This prevents HTML5 validation from blocking form submission in tests.
	 * Only fills fields that are both required AND empty.
	 */
	async fillRequiredFields() {
		// Fill required text/email/url inputs.
		const requiredTextInputs = this.page.locator(
			'input[required]:is([type="text"], [type="email"], [type="url"])'
		);
		const textCount = await requiredTextInputs.count();
		for ( let i = 0; i < textCount; i++ ) {
			const input = requiredTextInputs.nth( i );
			if ( await input.isVisible() && ( await input.inputValue() ) === '' ) {
				await input.fill( 'Test value' );
			}
		}

		// Fill required number inputs.
		const requiredNumberInputs = this.page.locator( 'input[required][type="number"]' );
		const numCount = await requiredNumberInputs.count();
		for ( let i = 0; i < numCount; i++ ) {
			const input = requiredNumberInputs.nth( i );
			if ( await input.isVisible() && ( await input.inputValue() ) === '' ) {
				await input.fill( '1' );
			}
		}

		// Fill required date inputs.
		const requiredDateInputs = this.page.locator( 'input[required][type="date"]' );
		const dateCount = await requiredDateInputs.count();
		for ( let i = 0; i < dateCount; i++ ) {
			const input = requiredDateInputs.nth( i );
			if ( await input.isVisible() && ( await input.inputValue() ) === '' ) {
				await input.fill( '2026-01-01' );
			}
		}

		// Fill required select elements (select the first non-empty option).
		const requiredSelects = this.page.locator( 'select[required]' );
		const selCount = await requiredSelects.count();
		for ( let i = 0; i < selCount; i++ ) {
			const sel = requiredSelects.nth( i );
			if ( await sel.isVisible() && ( await sel.inputValue() ) === '' ) {
				const firstOption = sel.locator( 'option:not([value=""])' ).first();
				if ( await firstOption.count() > 0 ) {
					const val = await firstOption.getAttribute( 'value' );
					await sel.selectOption( val );
				}
			}
		}

		// Fill required rich editors (TinyMCE) via data-required wrapper.
		const requiredRichWraps = this.page.locator( '.documentate-rich-editor-wrap[data-required="true"]' );
		const richCount = await requiredRichWraps.count();
		for ( let i = 0; i < richCount; i++ ) {
			const wrap = requiredRichWraps.nth( i );
			const textarea = wrap.locator( 'textarea' ).first();
			if ( await textarea.count() > 0 ) {
				const val = await textarea.inputValue();
				if ( val.trim() === '' ) {
					const textareaId = await textarea.getAttribute( 'id' );
					// Set content via TinyMCE API if available.
					await this.page.evaluate( ( id ) => {
						if ( window.tinyMCE ) {
							const editor = window.tinyMCE.get( id );
							if ( editor ) {
								editor.setContent( '<p>Test content</p>' );
								editor.save();
								return;
							}
						}
						const el = document.getElementById( id );
						if ( el ) {
							el.value = '<p>Test content</p>';
						}
					}, textareaId );
				}
			}
		}
	}

	/**
	 * Save the document as draft.
	 */
	async saveDraft() {
		await this.fillRequiredFields();
		await this.saveDraftButton.click();
		await this.waitForSave();
	}

	/**
	 * Return a published document to review (unlocking it for editing).
	 */
	async returnToReview() {
		await Promise.all( [
			this.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			this.returnToReviewButton.click(),
		] );
		await this.waitForSave();
	}

	/**
	 * Reason textarea shown before a "Devolver" submit.
	 */
	get returnMotivoField() {
		return this.page.locator( '#documentate-return-draft-motivo' );
	}

	/**
	 * Return a pending document to draft, giving the reason the workflow requires.
	 *
	 * The first click on "Devolver al área" reveals the reason box; the
	 * second one submits.
	 *
	 * @param {string} motivo - Reason of the return.
	 */
	async returnToDraft( motivo = 'Revisión E2E' ) {
		if ( ! ( await this.returnMotivoField.isVisible().catch( () => false ) ) ) {
			await this.returnToDraftButton.click();
		}
		await this.returnMotivoField.fill( motivo );
		await Promise.all( [
			this.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			this.returnToDraftButton.click(),
		] );
		await this.waitForSave();
	}

	/**
	 * Publish the document following the full workflow.
	 *
	 * From draft: Send to Review → page reloads → Approve & Publish → page reloads.
	 * From pending: Approve & Publish → page reloads.
	 * If already on a page with a visible Approve & Publish button, click it directly.
	 */
	async publish() {
		const approveBtn = this.page.locator( '#documentate-approve-publish' );
		const sendReviewBtn = this.page.locator( '#documentate-send-review' );

		// If "Approve & Publish" is visible, we're already in pending — just approve.
		if ( await approveBtn.isVisible().catch( () => false ) ) {
			await this.fillRequiredFields();
			await Promise.all( [
				this.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
				approveBtn.click(),
			] );
			await this.waitForSave();
			return;
		}

		// Otherwise, send to review first, then approve. A brand-new document
		// (auto-draft) only offers "Save Draft" — "Send to Review" appears once
		// the draft has been saved, which saveDraft() below takes care of.
		const canSendToReview = await sendReviewBtn.isVisible().catch( () => false );
		const canSaveDraft = await this.page.locator( '#documentate-save-draft' ).isVisible().catch( () => false );
		if ( canSendToReview || canSaveDraft ) {
			// Ensure a doc type is selected (required to transition beyond draft).
			if ( await this.hasDocTypes() ) {
				const currentValue = await this.docTypeSelect.inputValue();
				if ( ! currentValue ) {
					await this.selectFirstDocType();
				}
			}

			// Save draft first to persist the doc type (control_post_status forces
			// draft when doc_type hasn't been saved yet via save_post).
			await this.saveDraft();

			// After save, required fields may have appeared — fill them.
			await this.fillRequiredFields();

			await this.page.locator( '#documentate-send-review' ).waitFor( { state: 'visible', timeout: 10000 } );
			await Promise.all( [
				this.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
				this.page.locator( '#documentate-send-review' ).click(),
			] );

			// Page reloaded — now in pending state, fill required fields and approve.
			await this.page.locator( '#documentate-approve-publish' ).waitFor( { state: 'visible', timeout: 10000 } );
			await this.fillRequiredFields();
			await Promise.all( [
				this.page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
				this.page.locator( '#documentate-approve-publish' ).click(),
			] );
			await this.waitForSave();
			return;
		}

		// Fallback: try the legacy publish button.
		await this.publishButton.click();
		await this.waitForSave();
	}

	/**
	 * Wait for save operation to complete.
	 */
	async waitForSave() {
		// Wait for spinner to appear and disappear, or success notice.
		// Supports both the Document Management meta box spinner and the legacy publishing-action spinner.
		const spinner = this.page.locator(
			'#documentate_document_management .spinner.is-active, #publishing-action .spinner.is-active'
		).first();

		await spinner.waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {} );
		await spinner.waitFor( { state: 'hidden', timeout: 10000 } ).catch( () => {} );

		// Also wait for success notice as backup
		await this.successNotice.waitFor( { state: 'visible', timeout: 10000 } ).catch( () => {} );
	}

	/**
	 * Move document to trash.
	 */
	async trash() {
		await this.trashLink.click();
		await this.page.waitForURL( /post_type=documentate_document/ );
	}

	/**
	 * Get the post ID from the current URL.
	 *
	 * @return {Promise<number|null>} Post ID or null
	 */
	async getPostId() {
		const url = this.page.url();
		const match = url.match( /post=(\d+)/ );
		return match ? parseInt( match[ 1 ], 10 ) : null;
	}

	// ─────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Assert the title field has the expected value.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 * @param {string} expectedTitle                    - Expected title
	 */
	async expectTitleToBe( expect, expectedTitle ) {
		const actualTitle = await this.getTitle();
		expect( actualTitle ).toBe( expectedTitle );
	}

	/**
	 * Assert the success notice is visible.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectSaveSuccess( expect ) {
		await expect( this.successNotice ).toBeVisible();
	}

	/**
	 * Assert a field has the expected value.
	 *
	 * @param {import('@playwright/test').expect} expect  - Playwright expect
	 * @param {string} fieldSlug                         - Field slug
	 * @param {string} expectedValue                     - Expected value
	 */
	async expectFieldValue( expect, fieldSlug, expectedValue ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		await expect( field ).toHaveValue( expectedValue );
	}
}

module.exports = { DocumentEditorPage };
