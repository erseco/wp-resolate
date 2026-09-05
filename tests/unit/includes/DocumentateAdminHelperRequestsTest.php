<?php
/**
 * Request-handling tests for Documentate_Admin_Helper.
 *
 * Covers the admin-post entry points: archiving, preview, preview streaming and
 * the cross-origin isolated converter page, including their authorisation and
 * nonce enforcement.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Helper
 * @covers Documentate\Export\Export_Handler
 * @covers Documentate\Export\Export_DOCX_Handler
 * @covers Documentate\Export\Export_ODT_Handler
 * @covers Documentate\Export\Export_PDF_Handler
 */
class DocumentateAdminHelperRequestsTest extends Documentate_Test_Base {

	/**
	 * Helper under test.
	 *
	 * @var Documentate_Admin_Helper
	 */
	private $helper;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Document type term ID, required before a document may leave draft.
	 *
	 * @var int
	 */
	private $doc_type_id;

	/**
	 * Set up the helper and an administrator session.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$term = wp_insert_term( 'Request Handler Type', 'documentate_doc_type' );
		$this->doc_type_id = (int) $term['term_id'];

		$this->helper = new Documentate_Admin_Helper();
	}

	/**
	 * Reset the request superglobals the handlers read.
	 */
	public function tear_down() {
		unset( $_GET['post_id'], $_GET['_wpnonce'], $_GET['post'] );
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Create a document post in a given workflow status.
	 *
	 * The workflow keeps unclassified documents in draft, so anything beyond
	 * draft needs a document type assigned first.
	 *
	 * @param string $status Target post status.
	 * @return int Post ID.
	 */
	private function create_document( $status = 'publish' ) {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Request handler document',
				'post_status' => 'draft',
			)
		);

		if ( 'draft' === $status ) {
			return $post_id;
		}

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );
		wp_update_post(
			array(
				'ID' => $post_id,
				'post_status' => 'publish',
			)
		);

		if ( 'publish' !== $status ) {
			wp_update_post(
				array(
					'ID' => $post_id,
					'post_status' => $status,
				)
			);
		}

		$this->assertSame( $status, get_post_status( $post_id ), 'Test fixture must reach the requested status.' );

		return $post_id;
	}

	/**
	 * Run a request handler, ignoring "headers already sent" warnings.
	 *
	 * These endpoints send real HTTP headers. Under the CLI test runner output
	 * has already been flushed, so every header() call raises a warning that
	 * PHPUnit would otherwise turn into an error. Only that warning is ignored;
	 * anything else still reaches the previous handler.
	 *
	 * @param callable $callback Handler invocation.
	 * @return mixed Callback return value.
	 */
	private function run_request( callable $callback ) {
		$previous = null;
		$previous = set_error_handler(
			static function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$previous ) {
				if ( false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}

				return null === $previous ? false : call_user_func( $previous, $errno, $errstr, $errfile, $errline );
			}
		);

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Run a callback that ends in wp_safe_redirect() + exit() and return the target.
	 *
	 * @param callable $callback Handler invocation.
	 * @return string Redirect location.
	 */
	private function capture_redirect( callable $callback ) {
		$interceptor = static function ( $location ) {
			throw new Documentate_Exit_Exception( $location );
		};

		add_filter( 'wp_redirect', $interceptor, 1 );

		try {
			$this->run_request( $callback );
			$this->fail( 'The handler was expected to redirect.' );
		} catch ( Documentate_Exit_Exception $exception ) {
			return $exception->get_location();
		} finally {
			remove_filter( 'wp_redirect', $interceptor, 1 );
		}

		return '';
	}

	/**
	 * Run a callback expected to call wp_die() and return the message.
	 *
	 * @param callable $callback Handler invocation.
	 * @return string wp_die() message.
	 */
	private function capture_die( callable $callback ) {
		try {
			$this->run_request( $callback );
		} catch ( WPDieException $exception ) {
			return $exception->getMessage();
		}

		$this->fail( 'The handler was expected to terminate the request.' );
	}

	/**
	 * Administrators get an "Archive" row action on published documents.
	 */
	public function test_archive_row_action_is_offered_for_published_documents() {
		$post = get_post( $this->create_document( 'publish' ) );

		$actions = $this->helper->add_archive_row_actions( array(), $post );

		$this->assertArrayHasKey( 'documentate_archive', $actions );
		$this->assertArrayNotHasKey( 'documentate_unarchive', $actions );
		$this->assertStringContainsString( 'action=documentate_archive', html_entity_decode( $actions['documentate_archive'] ) );
		$this->assertStringContainsString( '_wpnonce=', html_entity_decode( $actions['documentate_archive'] ) );
	}

	/**
	 * Archived documents get the inverse "Unarchive" action instead.
	 */
	public function test_unarchive_row_action_is_offered_for_archived_documents() {
		$post = get_post( $this->create_document( 'archived' ) );

		$actions = $this->helper->add_archive_row_actions( array(), $post );

		$this->assertArrayHasKey( 'documentate_unarchive', $actions );
		$this->assertArrayNotHasKey( 'documentate_archive', $actions );
		$this->assertStringContainsString( 'action=documentate_unarchive', html_entity_decode( $actions['documentate_unarchive'] ) );
	}

	/**
	 * Drafts are neither archivable nor unarchivable.
	 */
	public function test_no_archive_row_actions_for_drafts() {
		$post = get_post( $this->create_document( 'draft' ) );

		$actions = $this->helper->add_archive_row_actions( array( 'edit' => 'x' ), $post );

		$this->assertSame( array( 'edit' => 'x' ), $actions );
	}

	/**
	 * Users without manage_options never see the archive actions.
	 */
	public function test_archive_row_actions_require_manage_options() {
		$post = get_post( $this->create_document( 'publish' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$actions = $this->helper->add_archive_row_actions( array( 'edit' => 'x' ), $post );

		$this->assertSame( array( 'edit' => 'x' ), $actions );
	}

	/**
	 * Archiving moves a published document to the archived status.
	 */
	public function test_archive_action_moves_document_to_archived() {
		$post_id = $this->create_document( 'publish' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_archive_' . $post_id );

		$location = $this->capture_redirect(
			function () {
				$this->helper->handle_archive_action();
			}
		);

		$this->assertSame( 'archived', get_post_status( $post_id ) );
		$this->assertStringContainsString( 'post_type=documentate_document', $location );
	}

	/**
	 * Unarchiving restores the published status.
	 */
	public function test_unarchive_action_restores_published_status() {
		$post_id = $this->create_document( 'archived' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_unarchive_' . $post_id );

		$location = $this->capture_redirect(
			function () {
				$this->helper->handle_unarchive_action();
			}
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertStringContainsString( 'post_type=documentate_document', $location );
	}

	/**
	 * A request without a post ID is rejected before anything else happens.
	 */
	public function test_archive_action_requires_a_post_id() {
		$message = $this->capture_die(
			function () {
				$this->helper->handle_archive_action();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions', $message );
	}

	/**
	 * Editors cannot archive documents even with a valid nonce.
	 */
	public function test_archive_action_requires_manage_options() {
		$post_id = $this->create_document( 'publish' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_archive_' . $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_archive_action();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions', $message );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * A missing or wrong nonce blocks archiving.
	 *
	 * @dataProvider provide_invalid_nonces
	 *
	 * @param string|null $nonce Nonce value, or null to omit it entirely.
	 */
	public function test_archive_action_requires_a_valid_nonce( $nonce ) {
		$post_id = $this->create_document( 'publish' );
		$_GET['post_id'] = (string) $post_id;
		if ( null !== $nonce ) {
			$_GET['_wpnonce'] = $nonce;
		}

		$message = $this->capture_die(
			function () {
				$this->helper->handle_archive_action();
			}
		);

		$this->assertStringContainsString( 'Invalid nonce', $message );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Nonce values that must be rejected.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public function provide_invalid_nonces() {
		return array(
			'missing' => array( null ),
			'wrong value' => array( 'not-a-nonce' ),
			'empty' => array( '' ),
		);
	}

	/**
	 * Only published documents of the right post type can be archived.
	 */
	public function test_archive_action_rejects_documents_in_the_wrong_state() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_archive_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_archive_action();
			}
		);

		$this->assertStringContainsString( 'Invalid document or status', $message );
	}

	/**
	 * Only archived documents can be unarchived.
	 */
	public function test_unarchive_action_rejects_documents_that_are_not_archived() {
		$post_id = $this->create_document( 'publish' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_unarchive_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_unarchive_action();
			}
		);

		$this->assertStringContainsString( 'Invalid document or status', $message );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Unarchiving enforces the same capability and nonce rules as archiving.
	 */
	public function test_unarchive_action_enforces_capability_and_nonce() {
		$post_id = $this->create_document( 'archived' );
		$_GET['post_id'] = (string) $post_id;

		$nonce_message = $this->capture_die(
			function () {
				$this->helper->handle_unarchive_action();
			}
		);
		$this->assertStringContainsString( 'Invalid nonce', $nonce_message );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_unarchive_' . $post_id );

		$cap_message = $this->capture_die(
			function () {
				$this->helper->handle_unarchive_action();
			}
		);
		$this->assertStringContainsString( 'Insufficient permissions', $cap_message );
		$this->assertSame( 'archived', get_post_status( $post_id ) );
	}

	/**
	 * The export entry points delegate to the format handlers, which enforce the
	 * export nonce.
	 *
	 * @dataProvider provide_export_handlers
	 *
	 * @param string $method Admin helper method name.
	 */
	public function test_export_handlers_enforce_the_export_nonce( $method ) {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = 'invalid';

		$message = $this->capture_die(
			function () use ( $method ) {
				$this->helper->{$method}();
			}
		);

		$this->assertStringContainsString( 'Invalid nonce', $message );
	}

	/**
	 * The export entry points reject users who cannot edit the document.
	 *
	 * @dataProvider provide_export_handlers
	 *
	 * @param string $method Admin helper method name.
	 */
	public function test_export_handlers_reject_unauthorized_users( $method ) {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$message = $this->capture_die(
			function () use ( $method ) {
				$this->helper->{$method}();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions', $message );
	}

	/**
	 * Export entry points on the admin helper.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provide_export_handlers() {
		return array(
			'docx' => array( 'handle_export_docx' ),
			'odt' => array( 'handle_export_odt' ),
			'pdf' => array( 'handle_export_pdf' ),
		);
	}

	/**
	 * Preview requires a document the user may edit.
	 */
	public function test_preview_requires_edit_capability() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_' . $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions', $message );
	}

	/**
	 * Preview requires its own nonce, not the export one.
	 */
	public function test_preview_requires_its_own_nonce() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview();
			}
		);

		$this->assertStringContainsString( 'Invalid nonce', $message );
	}

	/**
	 * A document the generator cannot render is not previewed, and its error is
	 * surfaced to the user rather than swallowed. A document carrying no type
	 * has no schema to draw, which is the one thing the native renderer refuses.
	 */
	public function test_preview_reports_generation_failures() {
		$post_id = $this->create_document( 'draft' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview();
			}
		);

		$this->assertStringContainsString( 'document type', strtolower( $message ) );
	}

	/**
	 * The preview stream endpoint enforces its own nonce.
	 */
	public function test_preview_stream_requires_its_own_nonce() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview_stream();
			}
		);

		$this->assertStringContainsString( 'Invalid nonce', $message );
	}

	/**
	 * The preview stream endpoint rejects users who cannot edit the document.
	 */
	public function test_preview_stream_requires_edit_capability() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_stream_' . $post_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview_stream();
			}
		);

		$this->assertStringContainsString( 'Insufficient permissions', $message );
	}

	/**
	 * Without a cached preview the endpoint regenerates it, and a generation
	 * failure is reported rather than serving a stale or partial file.
	 */
	public function test_preview_stream_reports_generation_failures() {
		$post_id = $this->create_document( 'draft' );
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_stream_' . $post_id );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview_stream();
			}
		);

		$this->assertStringContainsString( 'Could not generate the PDF for preview', $message );
	}

	/**
	 * A cached filename that sanitizes away is refused instead of being used to
	 * build a path.
	 */
	public function test_preview_stream_refuses_an_unusable_cached_filename() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_stream_' . $post_id );
		set_transient( 'documentate_preview_stream_' . $this->admin_id . '_' . $post_id, '???' );

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview_stream();
			}
		);

		$this->assertStringContainsString( 'Preview file not available', $message );
	}

	/**
	 * A cached filename pointing at a missing file produces an access error.
	 */
	public function test_preview_stream_reports_a_missing_cached_file() {
		$post_id = $this->create_document();
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_preview_stream_' . $post_id );
		set_transient(
			'documentate_preview_stream_' . $this->admin_id . '_' . $post_id,
			'never-generated.pdf'
		);

		$message = $this->capture_die(
			function () {
				$this->helper->handle_preview_stream();
			}
		);

		$this->assertStringContainsString( 'Could not access the generated PDF file', $message );
	}

	/**
	 * The preview stream answers 200 for a file name that needs RFC 5987
	 * encoding, rather than falling through to a WordPress error page.
	 *
	 * Only the status line is observable under the CLI runner: PHP has already
	 * flushed output, so nocache_headers() and header() are no-ops.
	 *
	 * @dataProvider provide_preview_filesizes
	 *
	 * @param int $filesize File size passed to the header builder.
	 */
	public function test_preview_stream_answers_200( $filesize ) {
		$codes = array();
		$record_status = static function ( $header, $code ) use ( &$codes ) {
			$codes[] = $code;
			return $header;
		};

		add_filter( 'status_header', $record_status, 10, 2 );

		try {
			$method = new ReflectionMethod( Documentate_Admin_Helper::class, 'send_preview_headers' );
			$method->setAccessible( true );
			$this->run_request(
				function () use ( $method, $filesize ) {
					$method->invoke( $this->helper, 'resolución final.pdf', $filesize );
				}
			);
		} finally {
			remove_filter( 'status_header', $record_status, 10 );
		}

		$this->assertSame( array( 200 ), $codes );
	}

	/**
	 * File sizes covering the known and unknown Content-Length cases.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function provide_preview_filesizes() {
		return array(
			'known size' => array( 2048 ),
			'unknown size' => array( 0 ),
		);
	}

	/**
	 * Streaming a PDF inline fails loudly when the file is not there.
	 */
	public function test_stream_pdf_inline_reports_a_missing_file() {
		$method = new ReflectionMethod( Documentate_Admin_Helper::class, 'stream_pdf_inline' );
		$method->setAccessible( true );

		$message = $this->capture_die(
			function () use ( $method ) {
				$method->invoke( $this->helper, '/nonexistent/documentate/missing.pdf', 'Title' );
			}
		);

		$this->assertStringContainsString( 'Could not access the generated PDF file', $message );
	}

	/**
	 * Streaming a generated document succeeds and unwinds every output buffer
	 * first, so nothing WordPress had buffered is prepended to the binary body.
	 *
	 * The payload itself goes straight to the output stream, which is why the
	 * file is left empty here: the method discards the buffer that would have
	 * captured it.
	 */
	public function test_stream_file_download_unwinds_buffers_before_streaming() {
		$upload_dir = wp_upload_dir();
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'documentate';
		wp_mkdir_p( $dir );
		$path = $dir . '/stream-download-test.docx';
		file_put_contents( $path, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$method = new ReflectionMethod( Documentate_Admin_Helper::class, 'stream_file_download' );
		$method->setAccessible( true );

		$level = ob_get_level();
		ob_start();
		$result = $this->run_request(
			function () use ( $method, $path ) {
				return $method->invoke( $this->helper, $path, 'application/octet-stream' );
			}
		);
		$level_after_streaming = ob_get_level();
		while ( ob_get_level() < $level ) {
			ob_start();
		}

		unlink( $path );

		$this->assertTrue( $result );
		$this->assertSame( 0, $level_after_streaming, 'Every output buffer must be discarded before the body is sent.' );
	}

	/**
	 * A file that cannot be read is reported instead of streaming nothing.
	 */
	public function test_stream_file_download_rejects_a_missing_file() {
		$method = new ReflectionMethod( Documentate_Admin_Helper::class, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '/nonexistent/documentate/absent.docx', 'application/pdf' );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_download_unreadable', $result->get_error_code() );
	}

	/**
	 * An empty path never reaches the filesystem.
	 */
	public function test_stream_file_download_rejects_an_empty_path() {
		$method = new ReflectionMethod( Documentate_Admin_Helper::class, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '', 'application/pdf' );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_download_missing', $result->get_error_code() );
	}

	/**
	 * The cross-origin isolated converter page is closed to users who cannot
	 * edit posts, even though it is served from admin-post.php.
	 */
	public function test_converter_page_requires_edit_posts() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$level = ob_get_level();
		try {
			$message = $this->capture_die(
				function () {
					$this->helper->render_converter_page();
				}
			);
		} finally {
			// render_converter_page() unwinds every output buffer on purpose so
			// the popup can send its own COOP/COEP headers.
			while ( ob_get_level() < $level ) {
				ob_start();
			}
		}

		$this->assertStringContainsString( 'do not have permission', $message );
	}
}
