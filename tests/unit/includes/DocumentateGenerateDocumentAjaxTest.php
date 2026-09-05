<?php
/**
 * AJAX tests for Documentate_Admin_Helper::ajax_generate_document().
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Helper
 * @covers Documentate_Document_Generator
 * @covers Documentate_Conversion_Manager
 * @covers Documentate_Collabora_Converter
 */
class DocumentateGenerateDocumentAjaxTest extends WP_Ajax_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Document type term ID with an ODT template attached.
	 *
	 * @var int
	 */
	private $term_id;

	/**
	 * Files created for the test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Create an administrator and a document type backed by a real ODT template.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$fixture = dirname( __DIR__, 2 ) . '/fixtures/templates/minimal-scalar.odt';
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$template_path = trailingslashit( $upload_dir['basedir'] ) . 'ajax-minimal-scalar.odt';
		copy( $fixture, $template_path );
		$this->temp_files[] = $template_path;

		$template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.oasis.opendocument.text',
				'post_title' => 'AJAX template',
				'post_status' => 'inherit',
			),
			$template_path
		);

		$term = wp_insert_term( 'AJAX Generation Type', 'documentate_doc_type' );
		$this->term_id = (int) $term['term_id'];
		update_term_meta( $this->term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $this->term_id, 'documentate_type_template_type', 'odt' );
	}

	/**
	 * Remove generated files and request state.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a document assigned to the ODT-backed document type.
	 *
	 * @return int Post ID.
	 */
	private function create_document() {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'AJAX generated document',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $this->term_id, 'documentate_doc_type' );

		return $post_id;
	}

	/**
	 * Dispatch the AJAX action and return the decoded response.
	 *
	 * @return array<string, mixed> Decoded JSON response.
	 */
	private function dispatch() {
		try {
			$this->_handleAjax( 'documentate_generate_document' );
		} catch ( WPAjaxDieContinueException $exception ) {
			unset( $exception );
		} catch ( WPAjaxDieStopException $exception ) {
			unset( $exception );
		}

		$response = json_decode( $this->_last_response, true );
		$this->assertIsArray( $response, 'The handler must answer with JSON.' );

		return $response;
	}

	/**
	 * Generating a document returns a download URL carrying the export nonce.
	 */
	public function test_download_response_points_at_the_export_endpoint() {
		$post_id = $this->create_document();
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'odt';
		$_POST['output'] = 'download';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_' . $post_id );

		$response = $this->dispatch();

		$this->assertTrue( $response['success'], wp_json_encode( $response ) );
		$url = html_entity_decode( $response['data']['url'] );
		$this->assertStringContainsString( 'action=documentate_export_odt', $url );
		$this->assertStringContainsString( 'post_id=' . $post_id, $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Requesting a preview returns the streaming URL and caches the generated
	 * file name so the stream endpoint does not regenerate it.
	 */
	public function test_preview_response_caches_the_generated_file() {
		$post_id = $this->create_document();
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'odt';
		$_POST['output'] = 'preview';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_' . $post_id );

		$response = $this->dispatch();

		$this->assertTrue( $response['success'], wp_json_encode( $response ) );
		$this->assertStringContainsString(
			'action=documentate_preview_stream',
			html_entity_decode( $response['data']['url'] )
		);

		$cached = get_transient( 'documentate_preview_stream_' . $this->admin_id . '_' . $post_id );
		$this->assertNotFalse( $cached, 'The generated file name must be cached for the stream endpoint.' );
		$this->assertStringEndsWith( '.odt', $cached );
		$this->temp_files[] = trailingslashit( wp_upload_dir()['basedir'] ) . 'documentate/' . $cached;
	}

	/**
	 * An unknown format falls back to PDF generation rather than erroring out
	 * on an undefined generator method.
	 */
	public function test_unknown_format_falls_back_to_pdf() {
		$post_id = $this->create_document();
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'rtf';
		$_POST['output'] = 'download';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_' . $post_id );

		$requested = array();
		$stub = static function ( $preempt, $args, $url ) use ( &$requested ) {
			unset( $preempt, $args );

			if ( false === strpos( $url, '/cool/convert-to/' ) ) {
				// Keep unrelated WordPress traffic (version checks, ...) offline.
				return new WP_Error( 'documentate_test_no_network', 'Blocked by the test.' );
			}

			$requested[] = $url;

			return array(
				'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'body' => '%PDF-1.4 stub',
				'response' => array(
					'code' => 200,
					'message' => 'OK',
				),
				'cookies' => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		try {
			$response = $this->dispatch();
		} finally {
			remove_filter( 'pre_http_request', $stub, 10 );
		}

		$this->assertSame( array(), $requested, 'The PDF path is drawn on the server, with no conversion request.' );
		$this->assertTrue( $response['success'], wp_json_encode( $response ) );
		$this->assertStringContainsString(
			'action=documentate_export_rtf',
			html_entity_decode( $response['data']['url'] )
		);
	}

	/**
	 * Generation failures are reported with the generator message, and never
	 * with the conversion endpoint details.
	 */
	public function test_generation_failure_is_reported_without_internal_details() {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Untyped document',
				'post_status' => 'draft',
			)
		);
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'docx';
		$_POST['output'] = 'download';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_' . $post_id );

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertArrayHasKey( 'message', $response['data'] );
		$this->assertArrayNotHasKey( 'data', $response['data'] );
		$this->assertStringContainsString( 'template', strtolower( $response['data']['message'] ) );
	}

	/**
	 * The handler refuses requests without a valid generation nonce.
	 */
	public function test_invalid_nonce_is_rejected() {
		$post_id = $this->create_document();
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'odt';
		$_POST['_wpnonce'] = 'nope';

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'nonce', strtolower( $response['data']['message'] ) );
	}

	/**
	 * The handler refuses users who cannot edit the document.
	 */
	public function test_unauthorized_user_is_rejected() {
		$post_id = $this->create_document();
		$_POST['post_id'] = (string) $post_id;
		$_POST['format'] = 'odt';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_' . $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permissions', strtolower( $response['data']['message'] ) );
	}

	/**
	 * A request without a post ID is rejected before any generation happens.
	 */
	public function test_missing_post_id_is_rejected() {
		$_POST['format'] = 'odt';
		$_POST['_wpnonce'] = wp_create_nonce( 'documentate_generate_0' );

		$response = $this->dispatch();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'permissions', strtolower( $response['data']['message'] ) );
	}
}
