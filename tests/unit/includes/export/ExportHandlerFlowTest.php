<?php
/**
 * Flow tests for the abstract Documentate\Export\Export_Handler.
 *
 * The concrete DOCX/ODT/PDF handlers only supply a format, a MIME type and a
 * generator call, so the request flow itself is exercised here through a test
 * double that can produce each outcome on demand.
 *
 * @package Documentate
 */

use Documentate\Export\Export_Handler;

/**
 * @covers Documentate\Export\Export_Handler
 */
class ExportHandlerFlowTest extends Documentate_Test_Base {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Document under export.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Set up an administrator and a document to export.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Export flow document',
				'post_status' => 'draft',
			)
		);

		$_GET['post_id'] = (string) $this->post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $this->post_id );
	}

	/**
	 * Clear the request state.
	 */
	public function tear_down() {
		unset( $_GET['post_id'], $_GET['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Build a handler whose generate() returns a fixed result.
	 *
	 * @param string|WP_Error $result Value generate() should return.
	 * @return Export_Handler
	 */
	private function make_handler( $result ) {
		return new class( $result ) extends Export_Handler {
			/**
			 * Value returned by generate().
			 *
			 * @var string|WP_Error
			 */
			private $result;

			/**
			 * Store the canned generation result.
			 *
			 * @param string|WP_Error $result Value generate() should return.
			 */
			public function __construct( $result ) {
				$this->result = $result;
			}

			/**
			 * Export format.
			 *
			 * @return string
			 */
			protected function get_format() {
				return 'docx';
			}

			/**
			 * Response MIME type.
			 *
			 * @return string
			 */
			protected function get_mime_type() {
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			}

			/**
			 * Return the canned result.
			 *
			 * @param int $post_id Document post ID.
			 * @return string|WP_Error
			 */
			protected function generate( $post_id ) {
				unset( $post_id );
				return $this->result;
			}
		};
	}

	/**
	 * Run a handler expecting it to end with wp_die().
	 *
	 * @param Export_Handler $handler Handler under test.
	 * @return string wp_die() message.
	 */
	private function capture_die( Export_Handler $handler ) {
		try {
			$handler->handle();
		} catch ( WPDieException $exception ) {
			return $exception->getMessage();
		}

		$this->fail( 'The handler was expected to terminate the request.' );
	}

	/**
	 * Run a handler expecting it to end with a redirect.
	 *
	 * @param Export_Handler $handler Handler under test.
	 * @return string Redirect target.
	 */
	private function capture_redirect( Export_Handler $handler ) {
		$interceptor = static function ( $location ) {
			throw new Documentate_Exit_Exception( $location );
		};

		add_filter( 'wp_redirect', $interceptor, 1 );

		try {
			$handler->handle();
			$this->fail( 'The handler was expected to redirect.' );
		} catch ( Documentate_Exit_Exception $exception ) {
			return $exception->get_location();
		} finally {
			remove_filter( 'wp_redirect', $interceptor, 1 );
		}

		return '';
	}

	/**
	 * A generation failure sends the user back to the editor with the reason in
	 * the query string, instead of emitting a broken download.
	 */
	public function test_generation_failure_redirects_back_with_the_reason() {
		$handler = $this->make_handler(
			new WP_Error( 'documentate_template_missing', 'Falta la plantilla DOCX.' )
		);

		$location = $this->capture_redirect( $handler );

		$this->assertStringContainsString( 'documentate_notice=', $location );
		$this->assertStringContainsString( rawurlencode( 'Falta la plantilla DOCX.' ), $location );
		$this->assertStringContainsString( 'post=' . $this->post_id, $location );
	}

	/**
	 * A generated file that is not on disk is reported rather than streamed.
	 */
	public function test_unreadable_generated_file_is_reported() {
		$handler = $this->make_handler( '/nonexistent/documentate/never-written.docx' );

		$message = $this->capture_die( $handler );

		$this->assertStringContainsString( 'Fichero generado no encontrado', $message );
	}

	/**
	 * Export requires a post ID; the request never reaches generate().
	 */
	public function test_missing_post_id_is_rejected_before_generating() {
		unset( $_GET['post_id'] );
		$handler = $this->make_handler( '/should/not/be/used.docx' );

		$message = $this->capture_die( $handler );

		$this->assertStringContainsString( 'Permisos insuficientes', $message );
	}

	/**
	 * Export requires the documentate_export_{id} nonce.
	 */
	public function test_export_nonce_is_enforced() {
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . ( $this->post_id + 1 ) );
		$handler = $this->make_handler( '/should/not/be/used.docx' );

		$message = $this->capture_die( $handler );

		$this->assertStringContainsString( 'Nonce no válido', $message );
	}

	/**
	 * Users who cannot edit the document cannot export it.
	 */
	public function test_export_requires_edit_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$handler = $this->make_handler( '/should/not/be/used.docx' );

		$message = $this->capture_die( $handler );

		$this->assertStringContainsString( 'Permisos insuficientes', $message );
	}
}
