<?php
/**
 * Tests for Documentate_Doc_Types_Admin::handle_reparse_schema().
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_Doc_Types_Admin
 * @covers Documentate\DocType\SchemaExtractor
 * @covers Documentate\DocType\SchemaStorage
 */
class DocumentateDocTypesReparseSchemaTest extends Documentate_Test_Base {

	/**
	 * Doc types admin instance.
	 *
	 * @var Documentate_Doc_Types_Admin
	 */
	private $admin;

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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$path = trailingslashit( $upload_dir['basedir'] ) . 'doc-type-reparse.odt';
		copy( dirname( __DIR__, 2 ) . '/fixtures/templates/minimal-scalar.odt', $path );
		$this->temp_files[] = $path;

		$this->template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.oasis.opendocument.text',
				'post_title' => 'Reparse template',
				'post_status' => 'inherit',
			),
			$path
		);

		$this->admin = new Documentate_Doc_Types_Admin();
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
		unset( $_GET['term_id'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
		delete_transient( 'documentate_schema_flash_' . get_current_user_id() );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a document type term, optionally with a template attached.
	 *
	 * @param int $template_id Attachment ID, or 0 for no template.
	 * @return int Term ID.
	 */
	private function create_doc_type( $template_id = 0 ) {
		$term = wp_insert_term( 'Reparse Type ' . wp_generate_password( 6, false ), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];

		if ( $template_id > 0 ) {
			update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		}

		return $term_id;
	}

	/**
	 * Point the request at a document type with a valid nonce.
	 *
	 * @param int $term_id Document type term ID.
	 * @return void
	 */
	private function request_reparse_of( $term_id ) {
		$_GET['term_id'] = (string) $term_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_reparse_schema_' . $term_id );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];
	}

	/**
	 * Run the handler and capture the redirect it ends with.
	 *
	 * @return string Redirect target.
	 */
	private function capture_redirect() {
		$interceptor = static function ( $location ) {
			throw new Documentate_Exit_Exception( $location );
		};

		add_filter( 'wp_redirect', $interceptor, 1 );

		try {
			$this->admin->handle_reparse_schema();
			$this->fail( 'The handler was expected to redirect.' );
		} catch ( Documentate_Exit_Exception $exception ) {
			return $exception->get_location();
		} finally {
			remove_filter( 'wp_redirect', $interceptor, 1 );
		}

		return '';
	}

	/**
	 * Run the handler expecting it to terminate the request.
	 *
	 * @return string wp_die() message.
	 */
	private function capture_die() {
		try {
			$this->admin->handle_reparse_schema();
		} catch ( WPDieException $exception ) {
			return $exception->getMessage();
		}

		$this->fail( 'The handler was expected to terminate the request.' );
	}

	/**
	 * Read back the flash notice the handler stored.
	 *
	 * @return array<string, string> Flash payload.
	 */
	private function get_flash() {
		$flash = get_transient( 'documentate_schema_flash_' . get_current_user_id() );
		$this->assertIsArray( $flash, 'The handler must store a flash notice.' );

		return $flash;
	}

	/**
	 * Register an attachment for a file that is not a template.
	 *
	 * @return int Attachment ID.
	 */
	private function create_plain_text_attachment() {
		$upload_dir = wp_upload_dir();
		$path = trailingslashit( $upload_dir['basedir'] ) . 'broken-reparse-template.txt';
		file_put_contents( $path, 'plain text' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->temp_files[] = $path;

		return wp_insert_attachment(
			array(
				'post_mime_type' => 'text/plain',
				'post_title' => 'Broken template',
				'post_status' => 'inherit',
			),
			$path
		);
	}

	/**
	 * Reparsing stores the freshly extracted schema and the template type, then
	 * returns to the document type screen.
	 */
	public function test_reparse_stores_the_extracted_schema() {
		$term_id = $this->create_doc_type( $this->template_id );
		$this->request_reparse_of( $term_id );

		$location = $this->capture_redirect();
		$schema = ( new SchemaStorage() )->get_schema( $term_id );

		$this->assertNotEmpty( $schema['fields'] );
		$this->assertSame( $this->template_id, $schema['meta']['template_id'] );
		$this->assertSame( 'odt', get_term_meta( $term_id, 'documentate_type_template_type', true ) );
		$this->assertStringContainsString( 'tag_ID=' . $term_id, $location );
		$this->assertStringContainsString( 'taxonomy=documentate_doc_type', $location );
		$this->assertSame( 'updated', $this->get_flash()['type'] );
	}

	/**
	 * A document type without a template reports it instead of failing silently.
	 */
	public function test_reparse_reports_a_missing_template_association() {
		$this->request_reparse_of( $this->create_doc_type() );

		$this->capture_redirect();
		$flash = $this->get_flash();

		$this->assertSame( 'error', $flash['type'] );
		$this->assertStringContainsString( 'No hay plantilla asociada', $flash['message'] );
	}

	/**
	 * A template attachment whose file has disappeared is reported.
	 */
	public function test_reparse_reports_a_missing_template_file() {
		$this->request_reparse_of( $this->create_doc_type( 999999 ) );

		$this->capture_redirect();
		$flash = $this->get_flash();

		$this->assertSame( 'error', $flash['type'] );
		$this->assertStringContainsString( 'Archivo de plantilla no encontrado', $flash['message'] );
	}

	/**
	 * An extraction failure is surfaced as an error notice and stores no schema.
	 */
	public function test_reparse_reports_extraction_failures() {
		$term_id = $this->create_doc_type( $this->create_plain_text_attachment() );
		$this->request_reparse_of( $term_id );

		$this->capture_redirect();
		$flash = $this->get_flash();

		$this->assertSame( 'error', $flash['type'] );
		$this->assertEmpty( ( new SchemaStorage() )->get_schema( $term_id ) );
	}

	/**
	 * Reparsing requires manage_options.
	 */
	public function test_reparse_requires_manage_options() {
		$term_id = $this->create_doc_type( $this->template_id );
		$this->request_reparse_of( $term_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertStringContainsString( 'Permisos insuficientes', $this->capture_die() );
		$this->assertEmpty( ( new SchemaStorage() )->get_schema( $term_id ) );
	}

	/**
	 * Reparsing requires a term ID.
	 */
	public function test_reparse_requires_a_term_id() {
		$this->assertStringContainsString( 'ID de tipo de documento no válido', $this->capture_die() );
	}

	/**
	 * Reparsing requires the per-term nonce.
	 */
	public function test_reparse_requires_a_valid_nonce() {
		$term_id = $this->create_doc_type( $this->template_id );
		$_GET['term_id'] = (string) $term_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_reparse_schema_' . ( $term_id + 1 ) );
		$_REQUEST['_wpnonce'] = $_GET['_wpnonce'];

		$this->capture_die();

		$this->assertEmpty( ( new SchemaStorage() )->get_schema( $term_id ) );
	}
}
