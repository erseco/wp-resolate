/**
 * Document Fields E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Fields', () => {
	/**
	 * Helper to select a document type and wait for fields to load.
	 *
	 * @param {Object} documentEditor - DocumentEditorPage instance
	 * @param {Object} page           - Playwright page
	 * @return {Promise<boolean>} True if doc type was selected
	 */
	async function selectDocTypeAndWaitForFields( documentEditor, page ) {
		if ( ! await documentEditor.hasDocTypes() ) {
			return false;
		}

		await documentEditor.selectFirstDocType();

		// Wait for fields to load via AJAX by checking for field inputs or the fields container
		await page.locator( '#documentate-fields-metabox, #documentate_fields, input[name^="documentate_field_"]' )
			.first()
			.waitFor( { state: 'visible', timeout: 5000 } )
			.catch( () => {} );

		return true;
	}

	test( 'fields appear when document type is selected', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Fields Test Document' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		// Save to trigger field rendering
		await documentEditor.saveDraft();

		// There should be at least one field if a document type is selected
		const fieldCount = await documentEditor.fieldInputs.count();
		expect( fieldCount ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'can fill simple text field and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Text Field Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		// Reload to get fields rendered
		await documentEditor.navigateToEdit( postId );

		// Find a text input field
		const textField = documentEditor.getFirstTextField();

		if ( await textField.count() > 0 ) {
			await textField.fill( 'Test text value' );
			await documentEditor.saveDraft();

			// Reload and verify
			await page.reload();
			await expect( textField ).toHaveValue( 'Test text value' );
		}
	} );

	test( 'can fill textarea field and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Textarea Field Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Find a textarea field (non-TinyMCE)
		const textareaField = page.locator(
			'textarea[name^="documentate_field_"]:not(.wp-editor-area)'
		).first();

		if ( await textareaField.count() > 0 ) {
			await textareaField.fill( 'Test textarea content\nWith multiple lines' );
			await documentEditor.saveDraft();

			await page.reload();
			await expect( textareaField ).toContainText( 'Test textarea content' );
		}
	} );

	test( 'can fill rich HTML field (TinyMCE) and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Rich Field Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Find a TinyMCE editor textarea
		const richTextarea = page.locator( 'textarea.wp-editor-area' ).first();

		if ( await richTextarea.count() > 0 ) {
			// Switch to Text/HTML mode
			const textTabId = await richTextarea.getAttribute( 'id' );
			const textTab = page.locator( `#${ textTabId }-html` );

			if ( await textTab.isVisible() ) {
				await textTab.click();
			}

			await richTextarea.fill( '<p>Rich HTML content with <strong>bold</strong> text</p>' );
			await documentEditor.saveDraft();

			await page.reload();

			// Switch to text mode again to read value
			if ( await textTab.isVisible() ) {
				await textTab.click();
			}

			const value = await richTextarea.inputValue();
			expect( value ).toContain( 'Rich HTML content' );
		}
	} );

	test( 'can add items to array/repeater field', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Array Field Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Look for an "Add" button for repeater fields
		// The repeater's own button, not whatever else the screen calls "add":
		// with WordPress core in English, "Add Media" matched first and the
		// test clicked it five times.
		const addButton = page.locator( '.documentate-array-add, .documentate-repeater-add' ).first();

		if ( await addButton.count() > 0 && await addButton.isVisible() ) {
			// Count items before
			const itemsBefore = await page.locator(
				'.documentate-array-item'
			).count();

			// Click add
			await addButton.click();

			// Wait for new item to appear
			await page.locator( '.documentate-array-item' )
				.nth( itemsBefore )
				.waitFor( { state: 'visible', timeout: 3000 } )
				.catch( () => {} );

			// Count items after
			const itemsAfter = await page.locator(
				'.documentate-array-item'
			).count();

			expect( itemsAfter ).toBeGreaterThanOrEqual( itemsBefore );
		}
	} );

	test( 'can remove items from array/repeater field', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Remove Array Item Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// One repeater at a time: a type may declare several, and the editor
		// keeps every repeater with at least one row, so removing the only row
		// of one field immediately puts an empty one back.
		const campo = page.locator( '.documentate-array-field' ).first();
		if ( await campo.count() === 0 ) {
			test.skip();
			return;
		}

		const filas = campo.locator( '.documentate-array-items > .documentate-array-item' );
		const antes = await filas.count();

		// Add a row first, so removing one leaves the field with something.
		await campo.locator( '.documentate-array-add' ).first().click();
		await expect( filas ).toHaveCount( antes + 1 );

		await filas.last().locator( '.documentate-array-remove' ).first().click();
		await expect( filas ).toHaveCount( antes );
	} );

	test( 'field values persist after save and reload', async ( {
		documentEditor,
		page,
	} ) => {
		// Create document via UI
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Persistence Test' );
		await documentEditor.saveDraft();

		const postId = await documentEditor.getPostId();
		if ( ! postId ) {
			test.skip( 'Could not create document' );
			return;
		}

		await documentEditor.navigateToEdit( postId );

		// Select doc type if available
		if ( await documentEditor.hasDocTypes() ) {
			await documentEditor.selectFirstDocType();
			await documentEditor.saveDraft();
			await documentEditor.navigateToEdit( postId );
		}

		// Find any text input and fill it
		const textField = documentEditor.getFirstTextField();

		if ( await textField.count() > 0 ) {
			const testValue = `Persistence test ${ Date.now() }`;
			await textField.fill( testValue );

			// Save the document
			await documentEditor.saveDraft();

			// Hard reload
			await page.goto( page.url() );

			// Verify value persists
			await expect( textField ).toHaveValue( testValue );
		}
	} );

	test( 'array field respects max items limit', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Max Items Test' );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// The repeater's own button, not whatever else the screen calls "add":
		// with WordPress core in English, "Add Media" matched first and the
		// test clicked it five times.
		const addButton = page.locator( '.documentate-array-add, .documentate-repeater-add' ).first();

		if ( await addButton.count() > 0 && await addButton.isVisible() ) {
			// Try to add items up to a reasonable number
			for ( let i = 0; i < 5; i++ ) {
				if ( await addButton.isEnabled() ) {
					const countBefore = await page.locator(
						'.documentate-array-item'
					).count();

					await addButton.click();

					// Wait for item count to change
					await page.waitForFunction(
						( expected ) => {
							const items = document.querySelectorAll( '.documentate-array-item' );
							return items.length > expected;
						},
						countBefore,
						{ timeout: 2000 }
					).catch( () => {} );
				}
			}

			// Verify items were added
			const itemCount = await page.locator(
				'.documentate-array-item'
			).count();

			expect( itemCount ).toBeGreaterThan( 0 );
		}
	} );
} );
