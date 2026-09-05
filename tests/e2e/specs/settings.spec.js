/**
 * Settings Page E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model and accessible selectors following
 * WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Settings Page', () => {
	// Settings modify shared state — run serially to avoid conflicts with parallel workers.
	test.describe.configure( { mode: 'serial' } );

	test( 'can navigate to settings page', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		// Verify we're on the settings page
		await settingsPage.expectOnSettingsPage( expect );
	} );

	test( 'settings page offers the three conversion engines', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		// The native renderer sits alongside the two converters it replaces,
		// so a site can fall back while the new engine is being proven.
		await expect( settingsPage.fpdfOption ).toHaveCount( 1 );
		await expect( settingsPage.collaboraOption ).toHaveCount( 1 );
		await expect( settingsPage.wasmOption ).toHaveCount( 1 );

		// And those three are the whole selector.
		await expect( settingsPage.engineOptions ).toHaveCount( 3 );
	} );

	test( 'the native engine is selected by default', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		// A site that has never chosen an engine renders the PDF itself.
		await settingsPage.expectFpdfSelected( expect );
		await expect( settingsPage.collaboraOption ).not.toBeChecked();
		await expect( settingsPage.wasmOption ).not.toBeChecked();
	} );

	test( 'can select conversion engine', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		// Try to select Collabora if available
		const selectedCollabora = await settingsPage.selectCollabora();
		if ( selectedCollabora ) {
			await settingsPage.expectCollaboraSelected( expect );
			return;
		}

		// Fall back to WASM
		const selectedWasm = await settingsPage.selectWasm();
		if ( selectedWasm ) {
			await settingsPage.expectWasmSelected( expect );
		}
	} );

	test( 'can configure Collabora base URL', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		const urlSet = await settingsPage.setCollaboraUrl( 'https://collabora.example.com' );

		if ( ! urlSet ) {
			test.skip();
			return;
		}

		// Verify the value is set
		await settingsPage.expectCollaboraUrl( expect, 'https://collabora.example.com' );
	} );

	test( 'can save settings successfully', async ( { settingsPage } ) => {
		await settingsPage.navigate();

		// Find any text input to modify
		const textInput = settingsPage.textInputs.first();

		if ( await textInput.count() > 0 ) {
			// Get current value and modify slightly
			const originalValue = await textInput.inputValue();
			const newValue = originalValue.trim() + ' ';
			await textInput.fill( newValue.trim() );
		}

		// Save settings
		await settingsPage.save();

		// Verify success message is visible
		await settingsPage.expectSaveSuccess( expect );
	} );

	test( 'settings persist after save and reload', async ( { settingsPage, page } ) => {
		await settingsPage.navigate();

		// Check if URL input exists
		const currentUrl = await settingsPage.getCollaboraUrl();
		if ( currentUrl === null ) {
			test.skip();
			return;
		}

		// Set a unique value
		const testValue = `https://test-${ Date.now() }.example.com`;
		await settingsPage.setCollaboraUrl( testValue );

		// Save
		await settingsPage.save();

		// Reload the page
		await page.reload();

		// Verify the value persisted
		await settingsPage.expectCollaboraUrl( expect, testValue );
	} );
} );
