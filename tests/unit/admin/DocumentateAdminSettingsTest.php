<?php
/**
 * Tests for Documentate_Admin_Settings class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Settings
 */
class DocumentateAdminSettingsTest extends Documentate_Test_Base {

	/**
	 * Settings instance.
	 *
	 * @var Documentate_Admin_Settings
	 */
	private $settings;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		set_current_screen( 'options-general' );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/class-documentate-admin-settings.php';

		$this->settings = new Documentate_Admin_Settings();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->settings, 'create_menu' ) ) );
		$this->assertNotFalse( has_action( 'admin_init', array( $this->settings, 'settings_init' ) ) );
	}

	/**
	 * Test create_menu adds options page.
	 */
	public function test_create_menu_adds_page() {
		global $submenu;

		// Call create_menu.
		$this->settings->create_menu();

		// Check the page was added to options-general.php submenu.
		$this->assertArrayHasKey( 'options-general.php', $submenu );

		$found = false;
		foreach ( $submenu['options-general.php'] as $item ) {
			if ( in_array( 'documentate_settings', $item, true ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Documentate settings page should be in options submenu' );
	}

	/**
	 * Test settings_init registers settings.
	 */
	public function test_settings_init_registers_settings() {
		global $new_allowed_options;

		$this->settings->settings_init();

		// Check that the setting group was registered.
		$this->assertArrayHasKey( 'documentate', $new_allowed_options );
		$this->assertContains( 'documentate_settings', $new_allowed_options['documentate'] );
	}

	/**
	 * Test settings_section_callback outputs description.
	 */
	public function test_settings_section_callback_outputs_description() {
		ob_start();
		$this->settings->settings_section_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure', $output );
	}

	/**
	 * Test conversion_engine_render outputs radio buttons.
	 */
	public function test_conversion_engine_render_outputs_radios() {
		ob_start();
		$this->settings->conversion_engine_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="radio"', $output );
		$this->assertStringContainsString( 'value="fpdf"', $output );
		$this->assertStringContainsString( 'value="collabora"', $output );
		$this->assertStringContainsString( 'value="wasm"', $output );
	}

	/**
	 * Test conversion_engine_render preselects the native engine on a site that
	 * never chose one.
	 */
	public function test_conversion_engine_render_defaults_to_the_native_engine() {
		delete_option( 'documentate_settings' );

		ob_start();
		$this->settings->conversion_engine_render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="fpdf"[^>]*checked/', $output );
		$this->assertDoesNotMatchRegularExpression( '/value="collabora"[^>]*checked/', $output );
	}

	/**
	 * Test conversion_engine_render keeps an explicit Collabora choice.
	 */
	public function test_conversion_engine_render_keeps_an_explicit_collabora_choice() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		ob_start();
		$this->settings->conversion_engine_render();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="collabora"[^>]*checked/', $output );
		$this->assertDoesNotMatchRegularExpression( '/value="fpdf"[^>]*checked/', $output );
	}

	/**
	 * Test conversion_engine_render shows current value.
	 */
	public function test_conversion_engine_render_shows_current_value() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		ob_start();
		$this->settings->conversion_engine_render();
		$output = ob_get_clean();

		// WordPress uses checked='checked' format.
		$this->assertStringContainsString( 'value="wasm"', $output );
		$this->assertStringContainsString( "checked='checked'", $output );
	}

	/**
	 * Test the WASM engine radio is disabled in WordPress Playground.
	 */
	public function test_conversion_engine_render_disables_wasm_in_playground() {
		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		ob_start();
		$this->settings->conversion_engine_render();
		$output = ob_get_clean();

		unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );

		$this->assertStringContainsString( "disabled='disabled'", $output );
		$this->assertStringContainsString( 'not available in WordPress Playground', $output );
	}

	/**
	 * Test collabora_base_url_render outputs URL input.
	 */
	public function test_collabora_base_url_render_outputs_input() {
		ob_start();
		$this->settings->collabora_base_url_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="url"', $output );
		$this->assertStringContainsString( 'collabora_base_url', $output );
	}

	/**
	 * Test collabora_base_url_render shows saved value.
	 */
	public function test_collabora_base_url_render_shows_saved_value() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://custom.example.com' ) );

		ob_start();
		$this->settings->collabora_base_url_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'https://custom.example.com', $output );
	}

	/**
	 * Test collabora_lang_render outputs text input.
	 */
	public function test_collabora_lang_render_outputs_input() {
		ob_start();
		$this->settings->collabora_lang_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="text"', $output );
		$this->assertStringContainsString( 'collabora_lang', $output );
	}

	/**
	 * Test collabora_lang_render shows saved value.
	 */
	public function test_collabora_lang_render_shows_saved_value() {
		update_option( 'documentate_settings', array( 'collabora_lang' => 'fr-FR' ) );

		ob_start();
		$this->settings->collabora_lang_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'fr-FR', $output );
	}

	/**
	 * Test collabora_disable_ssl_render outputs checkbox.
	 */
	public function test_collabora_disable_ssl_render_outputs_checkbox() {
		ob_start();
		$this->settings->collabora_disable_ssl_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'collabora_disable_ssl', $output );
	}

	/**
	 * Test collabora_disable_ssl_render shows checked state.
	 */
	public function test_collabora_disable_ssl_render_shows_checked() {
		update_option( 'documentate_settings', array( 'collabora_disable_ssl' => '1' ) );

		ob_start();
		$this->settings->collabora_disable_ssl_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	/**
	 * Test the AutoFirma signature text field uses the default value.
	 */
	public function test_autofirma_layer2_text_render_uses_default_value() {
		ob_start();
		$this->settings->autofirma_layer2_text_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'autofirma_layer2_text', $output );
		$this->assertStringContainsString( 'Firmado por $$SUBJECTCN$$', $output );
		$this->assertStringContainsString( '$$SIGNDATE=dd/MM/yyyy$$', $output );
		$this->assertStringContainsString( '$$ISSUERCN$$', $output );
	}

	/**
	 * Test the AutoFirma signature text field uses the saved value.
	 */
	public function test_autofirma_layer2_text_render_uses_saved_value() {
		update_option(
			'documentate_settings',
			array( 'autofirma_layer2_text' => 'Firma personalizada por $$SUBJECTCN$$.' )
		);

		ob_start();
		$this->settings->autofirma_layer2_text_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Firma personalizada por $$SUBJECTCN$$.', $output );
	}

	/**
	 * Test collaborative_enabled_render outputs checkbox.
	 */
	public function test_collaborative_enabled_render_outputs_checkbox() {
		ob_start();
		$this->settings->collaborative_enabled_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $output );
		$this->assertStringContainsString( 'collaborative_enabled', $output );
	}

	/**
	 * Test collaborative_enabled_render shows checked state.
	 */
	public function test_collaborative_enabled_render_shows_checked() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );

		ob_start();
		$this->settings->collaborative_enabled_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'checked', $output );
	}

	/**
	 * Test collaborative_signaling_render outputs URL input.
	 */
	public function test_collaborative_signaling_render_outputs_input() {
		ob_start();
		$this->settings->collaborative_signaling_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="url"', $output );
		$this->assertStringContainsString( 'collaborative_signaling', $output );
		$this->assertStringContainsString( 'wss://signaling.yjs.dev', $output );
		$this->assertStringNotContainsString( 'herokuapp.com', $output );
	}

	/**
	 * Test collaborative_signaling_render shows saved value.
	 */
	public function test_collaborative_signaling_render_shows_saved_value() {
		update_option( 'documentate_settings', array( 'collaborative_signaling' => 'wss://custom.signal.com' ) );

		ob_start();
		$this->settings->collaborative_signaling_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wss://custom.signal.com', $output );
	}

	/**
	 * Test options_page renders form.
	 */
	public function test_options_page_renders_form() {
		// First initialize settings to register sections.
		$this->settings->settings_init();

		ob_start();
		$this->settings->options_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form', $output );
		$this->assertStringContainsString( 'options.php', $output );
	}

	/**
	 * Test settings_validate with valid input.
	 */
	public function test_settings_validate_valid_input() {
		$input = array(
			'conversion_engine'       => 'wasm',
			'collabora_base_url'      => 'https://collabora.example.com/',
			'collabora_lang'          => 'es-ES',
			'collabora_disable_ssl'   => '1',
			'autofirma_layer2_text'   => 'Firmado digitalmente por $$SUBJECTCN$$.',
			'collaborative_enabled'   => '1',
			'collaborative_signaling' => 'wss://signal.example.com',
		);

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( 'wasm', $result['conversion_engine'] );
		$this->assertSame( 'https://collabora.example.com', $result['collabora_base_url'] );
		$this->assertSame( 'es-ES', $result['collabora_lang'] );
		$this->assertSame( '1', $result['collabora_disable_ssl'] );
		$this->assertSame( 'Firmado digitalmente por $$SUBJECTCN$$.', $result['autofirma_layer2_text'] );
		$this->assertSame( '1', $result['collaborative_enabled'] );
		$this->assertSame( 'wss://signal.example.com', $result['collaborative_signaling'] );
	}

	/**
	 * Test settings_validate with invalid engine.
	 */
	public function test_settings_validate_invalid_engine() {
		$input = array(
			'conversion_engine' => 'invalid_engine',
		);

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( 'fpdf', $result['conversion_engine'] );
	}

	/**
	 * Test settings_validate accepts the native engine.
	 */
	public function test_settings_validate_accepts_the_native_engine() {
		$result = $this->settings->settings_validate( array( 'conversion_engine' => 'fpdf' ) );

		$this->assertSame( 'fpdf', $result['conversion_engine'] );
	}

	/**
	 * Test settings_validate keeps an explicit Collabora choice.
	 */
	public function test_settings_validate_keeps_collabora() {
		$result = $this->settings->settings_validate( array( 'conversion_engine' => 'collabora' ) );

		$this->assertSame( 'collabora', $result['conversion_engine'] );
	}

	/**
	 * Test settings_validate with empty collabora_lang defaults.
	 */
	public function test_settings_validate_empty_lang_defaults() {
		$input = array(
			'collabora_lang' => '',
		);

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( 'en-US', $result['collabora_lang'] );
	}

	/**
	 * Test settings_validate with empty AutoFirma text defaults.
	 */
	public function test_settings_validate_empty_autofirma_text_defaults() {
		$result = $this->settings->settings_validate(
			array( 'autofirma_layer2_text' => '' )
		);

		$this->assertSame(
			'Firmado por $$SUBJECTCN$$ el día $$SIGNDATE=dd/MM/yyyy$$ con un certificado emitido por $$ISSUERCN$$',
			$result['autofirma_layer2_text']
		);
	}

	/**
	 * Test settings_validate with empty signaling URL defaults.
	 */
	public function test_settings_validate_empty_signaling_defaults() {
		$input = array(
			'collaborative_signaling' => '',
		);

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( 'wss://signaling.yjs.dev', $result['collaborative_signaling'] );
	}

	/**
	 * Test settings_validate sanitizes collabora_base_url.
	 */
	public function test_settings_validate_sanitizes_base_url() {
		$input = array(
			'collabora_base_url' => '  https://example.com/trailing/  ',
		);

		$result = $this->settings->settings_validate( $input );

		// Should be trimmed and trailing slash removed.
		$this->assertSame( 'https://example.com/trailing', $result['collabora_base_url'] );
	}

	/**
	 * Test settings_validate sets checkboxes to '0' when not present.
	 */
	public function test_settings_validate_checkboxes_default_to_zero() {
		$input = array();

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( '0', $result['collabora_disable_ssl'] );
		$this->assertSame( '0', $result['collaborative_enabled'] );
	}

	/**
	 * Test settings_validate handles missing fields gracefully.
	 */
	public function test_settings_validate_handles_missing_fields() {
		$input = array();

		$result = $this->settings->settings_validate( $input );

		$this->assertSame( 'fpdf', $result['conversion_engine'] );
		$this->assertSame( '', $result['collabora_base_url'] );
		$this->assertSame( 'en-US', $result['collabora_lang'] );
		$this->assertSame(
			'Firmado por $$SUBJECTCN$$ el día $$SIGNDATE=dd/MM/yyyy$$ con un certificado emitido por $$ISSUERCN$$',
			$result['autofirma_layer2_text']
		);
	}
}
