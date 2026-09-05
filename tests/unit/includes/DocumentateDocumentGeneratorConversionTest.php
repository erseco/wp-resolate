<?php
/**
 * Conversion-path tests for Documentate_Document_Generator.
 *
 * The engine is pinned to Collabora Online throughout: this file is the
 * regression net for the site that has not moved to the native renderer, where
 * the PDF is still produced by rendering the OpenTBS template and converting
 * it. Every test intercepts the HTTP layer, so no request ever leaves the
 * process.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Document_Generator
 * @covers Documentate_Conversion_Manager
 * @covers Documentate_Collabora_Converter
 */
class DocumentateDocumentGeneratorConversionTest extends Documentate_Test_Base {

	/**
	 * Files created for the test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Requests the conversion engine attempted.
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * The active pre_http_request stub, if any.
	 *
	 * @var callable|null
	 */
	private $http_stub;

	/**
	 * Set up an administrator session.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->requests = array();
		$this->http_stub = null;

		// The native renderer is the default engine; these cases are about the
		// converter a site can still choose instead.
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );
	}

	/**
	 * Remove temporary files, options and HTTP stubs.
	 */
	public function tear_down() {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		delete_option( 'documentate_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Answer every outgoing HTTP request with a canned conversion response.
	 *
	 * @param int    $status HTTP status code to return.
	 * @param string $body   Response body.
	 * @return void
	 */
	private function stub_conversion( $status = 200, $body = 'CONVERTED-BYTES' ) {
		$requests = &$this->requests;

		$this->http_stub = static function ( $preempt, $args, $url ) use ( &$requests, $status, $body ) {
			unset( $preempt, $args );

			if ( false === strpos( $url, '/cool/convert-to/' ) ) {
				// Keep unrelated WordPress traffic (version checks, ...) offline.
				return new WP_Error( 'documentate_test_no_network', 'Blocked by the test.' );
			}

			$requests[] = $url;

			return array(
				'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'body' => $body,
				'response' => array(
					'code' => $status,
					'message' => 200 === $status ? 'OK' : 'Error',
				),
				'cookies' => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	/**
	 * Create a document type whose only template is the given fixture.
	 *
	 * @param string $fixture Fixture file name under tests/fixtures/templates/.
	 * @return int Term ID.
	 */
	private function create_doc_type_with( $fixture ) {
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$path = trailingslashit( $upload_dir['basedir'] ) . 'generator-' . $fixture;
		copy( dirname( __DIR__, 2 ) . '/fixtures/templates/' . $fixture, $path );
		$this->temp_files[] = $path;

		$extension = strtolower( pathinfo( $fixture, PATHINFO_EXTENSION ) );
		$template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'odt' === $extension
					? 'application/vnd.oasis.opendocument.text'
					: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'post_title' => 'Generator template ' . $fixture,
				'post_status' => 'inherit',
			),
			$path
		);

		$term = wp_insert_term( 'Generator Type ' . wp_generate_password( 6, false ), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', $extension );

		return $term_id;
	}

	/**
	 * Create a document, optionally assigned to a document type.
	 *
	 * @param int $term_id Document type term ID, or 0 for none.
	 * @return int Post ID.
	 */
	private function create_document( $term_id = 0 ) {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Conversion document',
				'post_status' => 'draft',
			)
		);

		if ( $term_id > 0 ) {
			wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );
		}

		return $post_id;
	}

	/**
	 * Track a generated file for cleanup and return it.
	 *
	 * @param string|WP_Error $result Generator result.
	 * @return string|WP_Error The same result.
	 */
	private function track( $result ) {
		if ( is_string( $result ) ) {
			$this->temp_files[] = $result;
		}

		return $result;
	}

	/**
	 * PDF is produced from the ODT template when one is configured.
	 */
	public function test_pdf_is_converted_from_the_odt_source() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion( 200, '%PDF-1.4 converted' );

		$result = $this->track( Documentate_Document_Generator::generate_pdf( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/cool/convert-to/pdf', $this->requests[0] );
		$this->assertSame( '%PDF-1.4 converted', file_get_contents( $result ) );
	}

	/**
	 * A document type with only a DOCX template still yields a PDF, sourced from
	 * the DOCX because the ODT branch failed first.
	 */
	public function test_pdf_falls_back_to_the_docx_source() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.docx' ) );
		$this->stub_conversion( 200, '%PDF-1.4 converted' );

		$result = $this->track( Documentate_Document_Generator::generate_pdf( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		// The DOCX is rendered and converted straight to PDF: no step in between.
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/cool/convert-to/pdf', $this->requests[0] );
	}

	/**
	 * The editable download is never converted: a type with only an ODT
	 * template reports the missing DOCX template instead of converting into it.
	 */
	public function test_docx_is_not_converted_from_the_odt_template() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion();

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_template_missing', $result->get_error_code() );
		$this->assertSame( array(), $this->requests, 'No conversion may be attempted for an editable download.' );
	}

	/**
	 * The mirror image: a DOCX template does not produce an ODT.
	 */
	public function test_odt_is_not_converted_from_the_docx_template() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.docx' ) );
		$this->stub_conversion();

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_template_missing', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A document without a type reports it: DOCX and ODT because no template
	 * can be found for one, PDF because there is no source to convert.
	 *
	 * @dataProvider provide_generator_methods
	 *
	 * @param string $method        Generator method name.
	 * @param string $expected_code Expected WP_Error code.
	 */
	public function test_missing_templates_are_reported( $method, $expected_code ) {
		$post_id = $this->create_document();
		$this->stub_conversion();

		$result = Documentate_Document_Generator::{$method}( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertEmpty( $this->requests, 'No conversion may be attempted without a template.' );
	}

	/**
	 * Generator entry points and the error they report without a template.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_generator_methods() {
		return array(
			'docx' => array( 'generate_docx', 'documentate_template_missing' ),
			'odt' => array( 'generate_odt', 'documentate_template_missing' ),
			'pdf' => array( 'generate_pdf', 'documentate_pdf_source_missing' ),
		);
	}

	/**
	 * The browser-only engine cannot convert on the server, so it explains
	 * itself rather than returning a broken file.
	 */
	public function test_pdf_requires_a_server_side_conversion_engine() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion();

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_message() );
		$this->assertEmpty( $this->requests, 'The browser-only engine must not call a conversion service.' );
	}

	/**
	 * A conversion service error is surfaced instead of a truncated document.
	 */
	public function test_conversion_service_errors_are_surfaced() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion( 503, 'service unavailable' );

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_collabora_http_error', $result->get_error_code() );
		$this->assertStringContainsString( '503', $result->get_error_message() );
	}

	/**
	 * A 200 with no payload must not be written out as a zero byte document.
	 */
	public function test_empty_conversion_response_is_treated_as_a_failure() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion( 200, '' );

		// Post IDs are reused between runs, so clear any leftover output first.
		$upload_dir = wp_upload_dir();
		$expected_output = trailingslashit( $upload_dir['basedir'] ) . 'documentate/'
			. sanitize_title( get_the_title( $post_id ) ) . '-' . $post_id . '.pdf';
		if ( file_exists( $expected_output ) ) {
			unlink( $expected_output );
		}

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_collabora_empty_response', $result->get_error_code() );
		$this->assertFileDoesNotExist( $expected_output, 'No zero byte document may be left behind.' );
	}
}
