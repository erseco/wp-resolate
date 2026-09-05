<?php
/**
 * Tests for the PDF engine selection.
 *
 * The plugin draws PDFs natively by default, and keeps Collabora Online and
 * the in-browser LibreOffice WASM converter as the engines a site can fall
 * back to. These cases pin down which engine each setting selects and what
 * each of them is allowed to do.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Conversion_Manager
 * @covers Documentate_Document_Generator
 */
class DocumentatePdfEngineSelectionTest extends Documentate_Generation_Test_Base {

	/**
	 * The active pre_http_request stub, if any.
	 *
	 * @var callable|null
	 */
	private $http_stub = null;

	/**
	 * URLs the code under test tried to reach.
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * Start every case from an unconfigured site.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';

		$this->requests = array();
		$this->http_stub = null;
		delete_option( 'documentate_settings' );
	}

	/**
	 * Remove the HTTP stub and the engine setting.
	 */
	public function tear_down(): void {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}

		delete_option( 'documentate_settings' );

		parent::tear_down();
	}

	/**
	 * Select the conversion engine.
	 *
	 * @param string $engine   Engine key.
	 * @param string $base_url Collabora base URL, when one is needed.
	 * @return void
	 */
	private function set_engine( $engine, $base_url = '' ) {
		$settings = array( 'conversion_engine' => $engine );
		if ( '' !== $base_url ) {
			$settings['collabora_base_url'] = $base_url;
		}

		update_option( 'documentate_settings', $settings );
	}

	/**
	 * Record every outgoing HTTP request and answer it with the given response.
	 *
	 * @param array|WP_Error $response What wp_remote_post() should return.
	 * @return void
	 */
	private function record_requests( $response ) {
		$requests = &$this->requests;

		$this->http_stub = static function ( $preempt, $args, $url ) use ( &$requests, $response ) {
			unset( $preempt, $args );
			$requests[] = $url;

			return $response;
		};

		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	/**
	 * A canned Collabora conversion response carrying the given bytes.
	 *
	 * @param string $body Response body.
	 * @return array
	 */
	private function conversion_response( $body ) {
		return array(
			'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
			'body' => $body,
			'response' => array(
				'code' => 200,
				'message' => 'OK',
			),
			'cookies' => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a document of a type whose only template is the given fixture.
	 *
	 * @param string $fixture Fixture file name under tests/fixtures/templates/.
	 * @return int Post ID.
	 */
	private function document_with_template( $fixture ) {
		$type = $this->create_doc_type_with_template( $fixture );

		return $this->create_document_with_data( $type['term_id'] );
	}

	/**
	 * A site that never chose an engine gets the native renderer, and the
	 * native renderer never reports itself as unavailable.
	 */
	public function test_default_engine_is_the_native_renderer() {
		$this->assertSame( 'fpdf', Documentate_Conversion_Manager::ENGINE_FPDF );
		$this->assertSame(
			Documentate_Conversion_Manager::ENGINE_FPDF,
			Documentate_Conversion_Manager::get_engine()
		);
		$this->assertTrue( Documentate_Conversion_Manager::is_available() );
	}

	/**
	 * A site that explicitly chose Collabora keeps it across the change.
	 */
	public function test_an_explicit_choice_of_collabora_is_preserved() {
		$this->set_engine( 'collabora' );

		$this->assertSame(
			Documentate_Conversion_Manager::ENGINE_COLLABORA,
			Documentate_Conversion_Manager::get_engine()
		);
	}

	/**
	 * An unreadable engine name falls back to the native renderer rather than
	 * to a conversion service the site may not have.
	 */
	public function test_an_unknown_engine_falls_back_to_the_native_renderer() {
		$this->set_engine( 'nonsense' );

		$this->assertSame(
			Documentate_Conversion_Manager::ENGINE_FPDF,
			Documentate_Conversion_Manager::get_engine()
		);
	}

	/**
	 * Under the native engine the PDF is drawn in this process: the bytes are
	 * a PDF and not one byte left the machine to obtain them.
	 */
	public function test_native_engine_renders_the_pdf_without_any_http_request() {
		$this->set_engine( 'fpdf' );
		$this->record_requests( $this->conversion_response( 'CONVERTED-BYTES' ) );

		$post_id = $this->document_with_template( 'minimal-scalar.odt' );
		$path = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertIsString( $path, is_wp_error( $path ) ? $path->get_error_message() : '' );
		$this->temp_files[] = $path;
		$this->assertStringEndsWith( '.pdf', $path );

		$bytes = file_get_contents( $path );
		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertStringNotContainsString( 'CONVERTED-BYTES', $bytes );
		$this->assertSame( array(), $this->requests, 'The native engine must not reach the network.' );
	}

	/**
	 * A site left on Collabora keeps converting over HTTP: the PDF it hands
	 * back is exactly the payload the conversion service returned.
	 */
	public function test_collabora_engine_still_converts_over_http() {
		$this->set_engine( 'collabora', 'https://collabora.example.org' );
		$this->record_requests( $this->conversion_response( '%PDF-1.4 converted by the service' ) );

		$post_id = $this->document_with_template( 'minimal-scalar.odt' );
		$path = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertIsString( $path, is_wp_error( $path ) ? $path->get_error_message() : '' );
		$this->temp_files[] = $path;
		$this->assertSame(
			array( 'https://collabora.example.org/cool/convert-to/pdf' ),
			$this->requests
		);
		$this->assertSame( '%PDF-1.4 converted by the service', file_get_contents( $path ) );
	}

	/**
	 * The browser-only engine cannot produce a PDF on the server, and says so
	 * instead of silently drawing one natively.
	 */
	public function test_browser_engine_reports_that_it_cannot_convert_on_the_server() {
		$this->set_engine( 'wasm' );
		$this->record_requests( $this->conversion_response( 'CONVERTED-BYTES' ) );

		$post_id = $this->document_with_template( 'minimal-scalar.odt' );
		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * The native engine needs a document type to know which layout to draw, and
	 * reports its own reason rather than a missing office template.
	 */
	public function test_native_engine_reports_a_document_without_a_type() {
		$this->set_engine( 'fpdf' );

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'No type at all',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_pdf_no_type', $result->get_error_code() );
	}

	/**
	 * Converting between office formats is not the native engine's job, so it
	 * refuses instead of returning a file it never wrote.
	 */
	public function test_convert_refuses_the_native_engine() {
		$this->set_engine( 'fpdf' );
		$this->record_requests( $this->conversion_response( 'CONVERTED-BYTES' ) );

		$result = Documentate_Conversion_Manager::convert( '/tmp/a.odt', '/tmp/b.pdf', 'pdf', 'odt' );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
		$this->assertFileDoesNotExist( '/tmp/b.pdf' );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Asked why it cannot convert, the native engine names itself rather than
	 * repeating the browser converter's explanation.
	 */
	public function test_unavailable_message_under_the_native_engine_names_the_native_engine() {
		$this->set_engine( 'fpdf' );

		$message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'docx' );

		$this->assertStringNotContainsStringIgnoringCase( 'WASM', $message );
		$this->assertStringNotContainsStringIgnoringCase( 'browser', $message );
		$this->assertStringContainsString( 'ODT', $message );
		$this->assertStringContainsString( 'DOCX', $message );
	}

	/**
	 * The native engine has a label of its own, distinct from the other two.
	 */
	public function test_the_native_engine_has_its_own_label() {
		$native = Documentate_Conversion_Manager::get_engine_label( 'fpdf' );

		$this->assertNotSame( '', $native );
		$this->assertNotSame( Documentate_Conversion_Manager::get_engine_label( 'collabora' ), $native );
		$this->assertNotSame( Documentate_Conversion_Manager::get_engine_label( 'wasm' ), $native );
	}

	/**
	 * The settings screen offers the native engine first and preselects it on
	 * a site that never chose one, without dropping the Collabora option.
	 */
	public function test_settings_offer_the_native_engine_first_and_keep_collabora() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/class-documentate-admin-settings.php';

		ob_start();
		( new Documentate_Admin_Settings() )->conversion_engine_render();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="fpdf"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="collabora"', $html );
		$this->assertStringContainsString( 'value="wasm"', $html );
		$this->assertLessThan(
			strpos( $html, 'value="collabora"' ),
			strpos( $html, 'value="fpdf"' ),
			'The native engine must be the first option offered.'
		);
	}
}
