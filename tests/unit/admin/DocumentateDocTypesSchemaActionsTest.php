<?php
/**
 * Tests for the template-preview AJAX endpoint of Documentate_Doc_Types_Admin.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Doc_Types_Admin
 * @covers Documentate\DocType\SchemaExtractor
 * @covers Documentate\DocType\SchemaStorage
 */
class DocumentateDocTypesSchemaActionsTest extends WP_Ajax_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Attachment ID of the ODT template.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Files created for the test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Set up an administrator and a real ODT template attachment.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$path = trailingslashit( $upload_dir['basedir'] ) . 'doc-type-template-fields.odt';
		copy( dirname( __DIR__, 2 ) . '/fixtures/templates/minimal-scalar.odt', $path );
		$this->temp_files[] = $path;

		$this->template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.oasis.opendocument.text',
				'post_title' => 'Template fields template',
				'post_status' => 'inherit',
			),
			$path
		);
	}

	/**
	 * Remove temporary files and request state.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
		$_POST = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch the template-fields AJAX action and decode the response.
	 *
	 * @return array<string, mixed> Decoded JSON response.
	 */
	private function dispatch( $attachment_id ) {
		$_POST['nonce'] = wp_create_nonce( 'documentate_doc_type_template' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['attachment_id'] = (string) $attachment_id;

		try {
			$this->_handleAjax( 'documentate_doc_type_template_fields' );
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
	 * Register an attachment for a file that is not a template.
	 *
	 * @return int Attachment ID.
	 */
	private function create_plain_text_attachment() {
		$upload_dir = wp_upload_dir();
		$path = trailingslashit( $upload_dir['basedir'] ) . 'not-a-template.txt';
		file_put_contents( $path, 'plain text' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		return wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title' => 'Not a template',
				'post_status' => 'inherit',
			),
			$path
		);
	}

	/**
	 * Previewing a template returns its extracted schema and summary.
	 */
	public function test_template_preview_returns_the_extracted_schema() {
		$response = $this->dispatch( $this->template_id );

		$this->assertTrue( $response['success'], wp_json_encode( $response ) );
		$this->assertSame( 'odt', $response['data']['type'] );
		$this->assertSame( $this->template_id, $response['data']['schema']['meta']['template_id'] );
		$this->assertNotEmpty( $response['data']['schema']['fields'] );
		$this->assertArrayHasKey( 'field_count', $response['data']['summary'] );
	}

	/**
	 * A template ID that is not an attachment is rejected.
	 */
	public function test_template_preview_rejects_a_missing_template_file() {
		$response = $this->dispatch( 999999 );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'no encontrada', $response['data']['message'] );
	}

	/**
	 * A zero or negative attachment ID is rejected before touching the disk.
	 *
	 * @dataProvider provide_invalid_attachment_ids
	 *
	 * @param string $attachment_id Requested attachment ID.
	 */
	public function test_template_preview_rejects_an_invalid_template_id( $attachment_id ) {
		$response = $this->dispatch( $attachment_id );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'ID de plantilla no válido', $response['data']['message'] );
	}

	/**
	 * Attachment IDs that cannot identify a template.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_invalid_attachment_ids() {
		return array(
			'zero' => array( '0' ),
			'negative' => array( '-3' ),
			'not numeric' => array( 'abc' ),
		);
	}

	/**
	 * A file that is not a DOCX or ODT template produces the extractor error.
	 */
	public function test_template_preview_rejects_an_unsupported_file_type() {
		$response = $this->dispatch( $this->create_plain_text_attachment() );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'DOCX', $response['data']['message'] );
	}

	/**
	 * Only administrators may preview a template's fields.
	 */
	public function test_template_preview_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->dispatch( $this->template_id );

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'Permisos insuficientes', $response['data']['message'] );
	}
}
