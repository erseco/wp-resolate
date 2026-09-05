<?php
/**
 * Tests for Documentate_Conversion_Manager class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Conversion_Manager
 */
class DocumentateConversionManagerTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'documentate_settings' );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test get_engine returns the native renderer by default.
	 */
	public function test_get_engine_default() {
		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_FPDF, $result );
	}

	/**
	 * Test get_engine returns wasm when configured.
	 */
	public function test_get_engine_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_WASM, $result );
	}

	/**
	 * Test get_engine returns collabora when configured.
	 */
	public function test_get_engine_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_COLLABORA, $result );
	}

	/**
	 * Test get_engine returns default for invalid value.
	 */
	public function test_get_engine_invalid() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'invalid_engine' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_FPDF, $result );
	}

	/**
	 * Test get_engine returns fpdf when configured.
	 */
	public function test_get_engine_fpdf() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'fpdf' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_FPDF, $result );
	}

	/**
	 * Test get_engine_label for collabora.
	 */
	public function test_get_engine_label_collabora() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'collabora' );

		$this->assertStringContainsString( 'Collabora', $result );
	}

	/**
	 * Test get_engine_label for wasm.
	 */
	public function test_get_engine_label_wasm() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'wasm' );

		$this->assertStringContainsString( 'LibreOffice', $result );
		$this->assertStringContainsString( 'WASM', $result );
	}

	/**
	 * Test get_engine_label with null defaults to current engine.
	 */
	public function test_get_engine_label_null() {
		$result = Documentate_Conversion_Manager::get_engine_label( null );

		// Should return label for default engine (collabora).
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_engine_label for the native renderer.
	 */
	public function test_get_engine_label_fpdf() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'fpdf' );

		$this->assertStringContainsString( 'Native', $result );
	}

	/**
	 * Test get_engine_label for unknown engine.
	 */
	public function test_get_engine_label_unknown() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'unknown' );

		// Falls back to the label of the default engine.
		$this->assertSame( Documentate_Conversion_Manager::get_engine_label( 'fpdf' ), $result );
	}

	/**
	 * Test is_available with collabora engine.
	 */
	public function test_is_available_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		// This will check Collabora availability.
		$result = Documentate_Conversion_Manager::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test is_available with wasm engine.
	 */
	public function test_is_available_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test get_unavailable_message for collabora.
	 */
	public function test_get_unavailable_message_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::get_unavailable_message();

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_unavailable_message for wasm.
	 */
	public function test_get_unavailable_message_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::get_unavailable_message();

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_unavailable_message with source and target format.
	 */
	public function test_get_unavailable_message_with_formats() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$result = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'pdf' );

		$this->assertStringContainsString( 'ODT', $result );
		$this->assertStringContainsString( 'PDF', $result );
	}

	/**
	 * Test get_unavailable_message with only target format.
	 */
	public function test_get_unavailable_message_target_only() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$result = Documentate_Conversion_Manager::get_unavailable_message( '', 'docx' );

		$this->assertStringContainsString( 'DOCX', $result );
	}

	/**
	 * Test convert with collabora engine.
	 */
	public function test_convert_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::convert(
			'/tmp/test.odt',
			'/tmp/test.pdf',
			'pdf',
			'odt'
		);

		// Will likely return WP_Error since Collabora isn't configured in test.
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	/**
	 * Test convert with wasm engine.
	 */
	public function test_convert_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::convert(
			'/tmp/test.odt',
			'/tmp/test.pdf',
			'pdf',
			'odt'
		);

		// Will likely return WP_Error since WASM isn't configured in test.
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	/**
	 * Test constants are defined.
	 */
	public function test_constants() {
		$this->assertSame( 'wasm', Documentate_Conversion_Manager::ENGINE_WASM );
		$this->assertSame( 'collabora', Documentate_Conversion_Manager::ENGINE_COLLABORA );
		$this->assertSame( 'fpdf', Documentate_Conversion_Manager::ENGINE_FPDF );
	}

	/**
	 * The native renderer runs in this process, so it is always available and
	 * never asked to convert.
	 */
	public function test_fpdf_engine_is_available_and_refuses_to_convert() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );

		$this->assertTrue( Documentate_Conversion_Manager::is_available() );

		$result = Documentate_Conversion_Manager::convert( '/tmp/test.odt', '/tmp/test.docx', 'docx', 'odt' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
	}

	/**
	 * The existing `wasm` option value keeps resolving to the LibreOffice WASM engine.
	 */
	public function test_wasm_option_value_is_backwards_compatible() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_WASM, Documentate_Conversion_Manager::get_engine() );

		$label = Documentate_Conversion_Manager::get_engine_label();
		$this->assertStringContainsString( 'LibreOffice', $label );
		$this->assertStringContainsString( 'WASM', $label );
		$this->assertStringNotContainsStringIgnoringCase( 'ZetaJS', $label );
	}

	/**
	 * The WASM engine does not report server-side availability.
	 */
	public function test_wasm_engine_is_not_available_server_side() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$this->assertFalse( Documentate_Conversion_Manager::is_available() );
	}

	/**
	 * Converting with the WASM engine reports that server-side conversion is unavailable.
	 */
	public function test_convert_wasm_reports_conversion_not_available() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Conversion_Manager::convert( '/tmp/test.odt', '/tmp/test.pdf', 'pdf', 'odt' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
	}

	/**
	 * The WASM unavailable message no longer mentions ZetaJS and explains the browser flow.
	 */
	public function test_unavailable_message_wasm_has_no_zetajs() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'pdf' );

		$this->assertStringNotContainsStringIgnoringCase( 'ZetaJS', $message );
		// Either it explains the browser flow, or it explains the assets are missing;
		// both are valid depending on whether the WASM assets are installed.
		$this->assertTrue(
			false !== stripos( $message, 'browser' ) || false !== stripos( $message, 'assets' )
		);
	}

	/**
	 * In Playground, the WASM engine reports that browser conversion is unavailable
	 * and points to Collabora.
	 */
	public function test_unavailable_message_wasm_in_playground() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		$message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'pdf' );

		$this->assertStringContainsString( 'Playground', $message );
		$this->assertStringContainsStringIgnoringCase( 'Collabora', $message );

		unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
	}
}
