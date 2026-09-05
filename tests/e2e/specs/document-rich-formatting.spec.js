/**
 * Rich formatting E2E tests for Documentate classic editor.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Rich Formatting', () => {
	/**
	 * Helper to select a document type and wait for fields to load.
	 *
	 * @param {Object} documentEditor Document editor page object.
	 * @param {import('@playwright/test').Page} page Playwright page.
	 * @return {Promise<boolean>} Whether a document type was selected.
	 */
	async function selectDocTypeAndWaitForFields( documentEditor, page ) {
		if ( ! await documentEditor.hasDocTypes() ) {
			return false;
		}

		await documentEditor.selectFirstDocType();
		await page.locator( '#documentate-fields-metabox, #documentate_fields, textarea.wp-editor-area' )
			.first()
			.waitFor( { state: 'visible', timeout: 5000 } )
			.catch( () => {} );

		return true;
	}

	test( 'classic editor preserves alignment, bold text, spacing, and tables after reload', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( `Rich Formatting ${ Date.now() }` );

		const hasDocTypes = await selectDocTypeAndWaitForFields( documentEditor, page );
		if ( ! hasDocTypes ) {
			test.skip();
			return;
		}

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();
		await documentEditor.navigateToEdit( postId );

		const richTextarea = page.locator( 'textarea.wp-editor-area' ).first();
		if ( await richTextarea.count() === 0 ) {
			test.skip();
			return;
		}

		const textareaId = await richTextarea.getAttribute( 'id' );
		const visualTab = page.locator( `#${ textareaId }-tmce` );
		await expect( visualTab ).toBeVisible();
		await visualTab.click();

		await page.waitForFunction(
			( id ) => window.tinyMCE && window.tinyMCE.get( id )?.initialized,
			textareaId,
			{ timeout: 10000 }
		);

		const toolbar1 = await page.evaluate( ( id ) => {
			const editor = window.tinyMCE && window.tinyMCE.get( id );
			if ( ! editor || ! editor.settings ) {
				return '';
			}

			return editor.settings.toolbar1 || '';
		}, textareaId );

		expect( toolbar1 ).toContain( 'alignleft' );
		expect( toolbar1 ).toContain( 'aligncenter' );
		expect( toolbar1 ).toContain( 'alignright' );
		expect( toolbar1 ).toContain( 'alignjustify' );

		// Switch to HTML tab to set content directly, bypassing TinyMCE normalization.
		const htmlTab = page.locator( `#${ textareaId }-html` );
		await expect( htmlTab ).toBeVisible();
		await htmlTab.click();
		await expect( richTextarea ).toBeVisible();

		const content = [
			'<p style="text-align: justify"><b>Primero. Introducción&nbsp;&nbsp;</b></p>',
			'<p>El Programa esTEla surge de la necesidad de favorecer el éxito escolar del alumnado.&nbsp;&nbsp;</p>',
			'<p>&nbsp;</p>',
			'<p>Con el fin de alcanzar este objetivo general, se establecen los siguientes objetivos específicos:&nbsp;&nbsp;</p>',
			'<table><thead><tr><th>Distrito</th><th><b>Estado</b></th></tr></thead><tbody><tr><td>Norte</td><td>Activo</td></tr></tbody></table>',
			'<p>Contra el presente acto, por ser de trámite, no cabe recurso alguno.</p>',
		].join( '' );

		await richTextarea.fill( content );

		await documentEditor.saveDraft();
		await documentEditor.navigateToEdit( postId );

		const textTab = page.locator( `#${ textareaId }-html` );
		await expect( textTab ).toBeVisible();
		await textTab.click();
		await expect( richTextarea ).toBeVisible();

		const storedHtml = await richTextarea.inputValue();

		expect( storedHtml ).toMatch( /text-align:\s*justify/ );
		expect( storedHtml ).toMatch( /<(b|strong)>Primero\./ );
		expect( storedHtml ).toContain( '<p>&nbsp;</p>' );
		expect( storedHtml ).toContain( '<table>' );
		expect( storedHtml ).toContain( '<thead>' );
		expect( storedHtml ).toContain( '<tbody>' );
		expect( storedHtml ).toContain( '<th>Distrito</th>' );
		expect( storedHtml ).toMatch( /<(b|strong)>Estado/ );
		expect( storedHtml ).toContain( '<p>Contra el presente acto, por ser de trámite, no cabe recurso alguno.</p>' );
	} );
} );
