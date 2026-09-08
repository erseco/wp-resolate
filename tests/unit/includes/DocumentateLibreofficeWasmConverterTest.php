<?php
/**
 * Tests for the LibreOffice WASM (browser) converter helper.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Libreoffice_Wasm_Converter
 */
class DocumentateLibreofficeWasmConverterTest extends Documentate_Test_Base {

	/**
	 * Prepare test dependencies.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		remove_all_filters( 'documentate_libreoffice_wasm_base_url' );
		remove_all_filters( 'documentate_libreoffice_wasm_input_formats' );

		parent::tear_down();
	}

	/**
	 * The browser engine is only active when the `wasm` option is selected.
	 *
	 * @return void
	 */
	public function test_is_browser_mode_only_when_engine_is_wasm() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$this->assertTrue( Documentate_Libreoffice_Wasm_Converter::is_browser_mode() );

		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );
		$this->assertFalse( Documentate_Libreoffice_Wasm_Converter::is_browser_mode() );

		update_option( 'documentate_settings', array() );
		$this->assertFalse( Documentate_Libreoffice_Wasm_Converter::is_browser_mode() );

		update_option( 'documentate_settings', array( 'conversion_engine' => 'invalid' ) );
		$this->assertFalse( Documentate_Libreoffice_Wasm_Converter::is_browser_mode() );
	}

	/**
	 * The deprecated is_cdn_mode() alias mirrors is_browser_mode() for backwards compatibility.
	 *
	 * @return void
	 */
	public function test_is_cdn_mode_alias_matches_browser_mode() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$this->assertSame(
			Documentate_Libreoffice_Wasm_Converter::is_browser_mode(),
			Documentate_Libreoffice_Wasm_Converter::is_cdn_mode()
		);
		$this->assertTrue( Documentate_Libreoffice_Wasm_Converter::is_cdn_mode() );
	}

	/**
	 * The browser engine never claims server-side availability.
	 *
	 * @return void
	 */
	public function test_is_available_is_always_false() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$this->assertFalse( Documentate_Libreoffice_Wasm_Converter::is_available() );
	}

	/**
	 * convert() always returns a browser-only WP_Error describing the flow.
	 *
	 * @return void
	 */
	public function test_convert_returns_browser_only_error() {
		$result = Documentate_Libreoffice_Wasm_Converter::convert( '/tmp/input.odt', '/tmp/output.pdf', 'pdf', 'odt' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_libreoffice_wasm_browser_only', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'browser', $data['mode'] );
		$this->assertSame( 'wasm', $data['engine'] );
	}

	/**
	 * Asset URLs are generated with plugins_url() and point at the vendor directory.
	 *
	 * @return void
	 */
	public function test_asset_urls_use_plugins_url() {
		$module = Documentate_Libreoffice_Wasm_Converter::get_module_url();
		$worker = Documentate_Libreoffice_Wasm_Converter::get_worker_url();
		$wasm_base = Documentate_Libreoffice_Wasm_Converter::get_wasm_base_url();

		$this->assertStringContainsString( 'admin/vendor/libreoffice-converter', $module );
		$this->assertStringEndsWith( '/dist/browser.js', $module );
		$this->assertStringEndsWith( '/dist/browser.worker.global.js', $worker );
		$this->assertStringEndsWith( '/wasm/', $wasm_base );
	}

	/**
	 * The base URL can be filtered.
	 *
	 * @return void
	 */
	public function test_base_url_filterable() {
		add_filter( 'documentate_libreoffice_wasm_base_url', function () {
			return 'https://cdn.example.com/libreoffice';
		} );

		$this->assertSame(
			'https://cdn.example.com/libreoffice/dist/browser.js',
			Documentate_Libreoffice_Wasm_Converter::get_module_url()
		);
	}

	/**
	 * The large binaries are loaded from the CDN base URL, separate from the glue.
	 *
	 * @return void
	 */
	public function test_binary_urls_use_cdn() {
		$wasm = Documentate_Libreoffice_Wasm_Converter::get_soffice_wasm_url();
		$data = Documentate_Libreoffice_Wasm_Converter::get_soffice_data_url();

		$this->assertStringEndsWith( 'soffice.wasm', $wasm );
		$this->assertStringEndsWith( 'soffice.data', $data );

		// By default the binaries come from the configured CDN, not the plugin vendor dir.
		if ( defined( 'DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL' ) ) {
			$this->assertStringStartsWith( DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL, $wasm );
			$this->assertStringNotContainsString( 'admin/vendor/libreoffice-converter', $wasm );
		}
	}

	/**
	 * The binary base URL can be filtered (e.g. to self-host the binaries).
	 *
	 * @return void
	 */
	public function test_binary_base_url_filterable() {
		add_filter( 'documentate_libreoffice_wasm_binary_base_url', function () {
			return 'https://cdn.example.com/lo/';
		} );

		$this->assertSame(
			'https://cdn.example.com/lo/soffice.wasm',
			Documentate_Libreoffice_Wasm_Converter::get_soffice_wasm_url()
		);
		$this->assertSame(
			'https://cdn.example.com/lo/soffice.data',
			Documentate_Libreoffice_Wasm_Converter::get_soffice_data_url()
		);
	}

	/**
	 * Supported input formats include the plugin defaults and can be filtered.
	 *
	 * @return void
	 */
	public function test_supported_input_formats() {
		$formats = Documentate_Libreoffice_Wasm_Converter::get_supported_input_formats();
		$this->assertContains( 'odt', $formats );
		$this->assertContains( 'docx', $formats );

		add_filter( 'documentate_libreoffice_wasm_input_formats', function () {
			return array( 'odt' );
		} );
		$this->assertSame( array( 'odt' ), Documentate_Libreoffice_Wasm_Converter::get_supported_input_formats() );
	}

	/**
	 * The target format is PDF.
	 *
	 * @return void
	 */
	public function test_target_format_is_pdf() {
		$this->assertSame( 'pdf', Documentate_Libreoffice_Wasm_Converter::get_target_format() );
	}

	/**
	 * assets_available() returns a boolean.
	 *
	 * @return void
	 */
	public function test_assets_available_returns_bool() {
		$this->assertIsBool( Documentate_Libreoffice_Wasm_Converter::assets_available() );
	}

	/**
	 * The browser configuration array exposes the URLs, formats and strings the script needs.
	 *
	 * @return void
	 */
	public function test_get_browser_config_structure() {
		$config = Documentate_Libreoffice_Wasm_Converter::get_browser_config();

		$this->assertArrayHasKey( 'moduleUrl', $config );
		$this->assertArrayHasKey( 'workerUrl', $config );
		$this->assertArrayHasKey( 'wasmBaseUrl', $config );
		$this->assertArrayHasKey( 'sofficeWasmUrl', $config );
		$this->assertArrayHasKey( 'sofficeDataUrl', $config );
		$this->assertArrayHasKey( 'supportedInputFormats', $config );
		$this->assertArrayHasKey( 'targetFormat', $config );
		$this->assertArrayHasKey( 'assetsAvailable', $config );
		$this->assertArrayHasKey( 'strings', $config );

		$this->assertSame( 'pdf', $config['targetFormat'] );
		$this->assertContains( 'odt', $config['supportedInputFormats'] );
		$this->assertIsArray( $config['strings'] );
		$this->assertArrayHasKey( 'sharedArrayBufferError', $config['strings'] );
		$this->assertNotEmpty( $config['strings']['sharedArrayBufferError'] );
	}

	/**
	 * User-facing messages describe the browser flow and never mention ZetaJS.
	 *
	 * @return void
	 */
	public function test_messages_do_not_mention_zetajs() {
		$browser = Documentate_Libreoffice_Wasm_Converter::get_browser_conversion_message();
		$missing = Documentate_Libreoffice_Wasm_Converter::get_missing_assets_message();
		$headers = Documentate_Libreoffice_Wasm_Converter::get_headers_help_message();

		foreach ( array( $browser, $missing, $headers ) as $message ) {
			$this->assertIsString( $message );
			$this->assertNotEmpty( $message );
			$this->assertStringNotContainsStringIgnoringCase( 'ZetaJS', $message );
		}

		// The browser message explains conversion happens in the browser and points to Collabora
		// for server-side generation.
		$this->assertStringContainsStringIgnoringCase( 'navegador', $browser );
		$this->assertStringContainsStringIgnoringCase( 'Collabora', $browser );

		// The headers message explains the cross-origin isolation requirement.
		$this->assertStringContainsStringIgnoringCase( 'SharedArrayBuffer', $headers );
	}
}
