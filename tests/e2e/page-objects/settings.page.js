/**
 * Page Object Model for the Documentate Settings page.
 *
 * Uses accessible selectors (getByRole, getByLabel) following
 * WordPress/Gutenberg E2E best practices.
 */

class SettingsPage {
	/**
	 * @param {import('@playwright/test').Page} page - Playwright page object
	 * @param {Object} admin - WordPress admin utilities
	 */
	constructor( page, admin ) {
		this.page = page;
		this.admin = admin;
	}

	// ─────────────────────────────────────────────────────────────────
	// Locators
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The main settings form.
	 */
	get form() {
		return this.page.locator( 'form' );
	}

	/**
	 * The page wrapper.
	 */
	get pageWrapper() {
		return this.page.locator( '.wrap, .settings_page_documentate_settings form, form[action="options.php"]' );
	}

	/**
	 * Conversion engine radio buttons.
	 */
	get engineOptions() {
		return this.page.locator(
			'input[name*="engine"], input[name*="conversion"]'
		);
	}

	/**
	 * Native PDF (FPDF) radio option, the engine a fresh site starts on.
	 */
	get fpdfOption() {
		return this.page.locator( 'input[type="radio"][value="fpdf"]' );
	}

	/**
	 * Collabora radio option.
	 */
	get collaboraOption() {
		return this.page.getByRole( 'radio', { name: /collabora/i } ).or(
			this.page.locator( 'input[type="radio"][value="collabora"]' )
		);
	}

	/**
	 * WASM/LibreOffice radio option.
	 */
	get wasmOption() {
		return this.page.getByRole( 'radio', { name: /wasm|libreoffice/i } ).or(
			this.page.locator( 'input[type="radio"][value="wasm"]' )
		);
	}

	/**
	 * Collabora base URL input.
	 */
	get collaboraUrlInput() {
		return this.page.getByRole( 'textbox', { name: /collabora.*url|base.*url/i } ).or(
			this.page.locator( 'input[name*="collabora_url"], input[name*="base_url"]' ).first()
		);
	}

	/**
	 * Any text input on the page.
	 */
	get textInputs() {
		return this.page.locator( 'input[type="text"], input[type="url"]' );
	}

	/**
	 * Save/Submit button.
	 */
	get saveButton() {
		return this.page.getByRole( 'button', { name: /save changes/i } ).or(
			this.page.locator( 'input[type="submit"], button[type="submit"], #submit' )
		);
	}

	/**
	 * Success notice after save.
	 */
	get successNotice() {
		return this.page.locator( '.notice-success, .updated, #setting-error-settings_updated' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Navigation
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Navigate to the settings page.
	 */
	async navigate() {
		await this.admin.visitAdminPage( 'admin.php', 'page=documentate_settings' );
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Actions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Select Collabora as the conversion engine.
	 *
	 * @return {Promise<boolean>} True if option was selected
	 */
	async selectCollabora() {
		if ( await this.collaboraOption.count() > 0 ) {
			await this.collaboraOption.first().check();
			return true;
		}
		return false;
	}

	/**
	 * Select WASM as the conversion engine.
	 *
	 * @return {Promise<boolean>} True if option was selected
	 */
	async selectWasm() {
		if ( await this.wasmOption.count() > 0 ) {
			await this.wasmOption.first().check();
			return true;
		}
		return false;
	}

	/**
	 * Set the Collabora base URL.
	 *
	 * @param {string} url - URL to set
	 * @return {Promise<boolean>} True if field was found and filled
	 */
	async setCollaboraUrl( url ) {
		if ( await this.collaboraUrlInput.count() > 0 ) {
			await this.collaboraUrlInput.fill( url );
			return true;
		}
		return false;
	}

	/**
	 * Get the current Collabora URL value.
	 *
	 * @return {Promise<string|null>} URL value or null
	 */
	async getCollaboraUrl() {
		if ( await this.collaboraUrlInput.count() > 0 ) {
			return await this.collaboraUrlInput.inputValue();
		}
		return null;
	}

	/**
	 * Save the settings.
	 */
	async save() {
		await this.saveButton.click();
		await this.successNotice.first().waitFor( { state: 'visible', timeout: 10000 } );
	}

	/**
	 * Check if engine options are visible.
	 *
	 * @return {Promise<boolean>} True if engine options exist
	 */
	async hasEngineOptions() {
		return ( await this.engineOptions.count() ) > 0;
	}

	// ─────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Assert we're on the settings page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectOnSettingsPage( expect ) {
		await expect( this.page ).toHaveURL( /page=documentate_settings/ );
		await expect( this.pageWrapper.first() ).toBeVisible();
	}

	/**
	 * Assert success notice is visible after save.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectSaveSuccess( expect ) {
		await expect( this.successNotice.first() ).toBeVisible();
	}

	/**
	 * Assert the native PDF option is checked.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectFpdfSelected( expect ) {
		await expect( this.fpdfOption ).toBeChecked();
	}

	/**
	 * Assert Collabora option is checked.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectCollaboraSelected( expect ) {
		await expect( this.collaboraOption.first() ).toBeChecked();
	}

	/**
	 * Assert WASM option is checked.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectWasmSelected( expect ) {
		await expect( this.wasmOption.first() ).toBeChecked();
	}

	/**
	 * Assert the URL input has expected value.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 * @param {string} expectedUrl                      - Expected URL
	 */
	async expectCollaboraUrl( expect, expectedUrl ) {
		await expect( this.collaboraUrlInput ).toHaveValue( expectedUrl );
	}
}

module.exports = { SettingsPage };
