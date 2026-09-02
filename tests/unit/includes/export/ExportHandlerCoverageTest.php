<?php
/**
 * Additional coverage tests for Export_Handler class.
 *
 * These tests focus on increasing code coverage for methods that use wp_die,
 * redirects, and file streaming.
 *
 * @package Documentate
 */

use Documentate\Export\Export_Handler;
use Documentate\Export\Export_DOCX_Handler;

/**
 * Test class for Export_Handler coverage.
 */
class ExportHandlerCoverageTest extends WP_UnitTestCase {

	/**
	 * Temporary directory for test files.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->temp_dir = sys_get_temp_dir() . '/documentate_test_' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		// Clean up temp files.
		if ( is_dir( $this->temp_dir ) ) {
			array_map( 'unlink', glob( $this->temp_dir . '/*' ) );
			rmdir( $this->temp_dir );
		}
		unset( $_GET['post_id'], $_GET['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test validate_request dies when post_id is 0.
	 */
	public function test_validate_request_dies_when_post_id_is_zero() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Permisos insuficientes.' );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, 0 );
	}

	/**
	 * Test validate_request dies when user lacks permissions.
	 */
	public function test_validate_request_dies_when_no_permission() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Permisos insuficientes.' );

		// Create a subscriber user (no edit permissions).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
				'post_author' => 1, // Different author.
			)
		);

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, $post_id );
	}

	/**
	 * Test validate_request dies when nonce is missing.
	 */
	public function test_validate_request_dies_when_nonce_missing() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Nonce no válido.' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);

		// No nonce set in $_GET.
		unset( $_GET['_wpnonce'] );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, $post_id );
	}

	/**
	 * Test validate_request dies when nonce is invalid.
	 */
	public function test_validate_request_dies_when_nonce_invalid() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Nonce no válido.' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);

		// Set an invalid nonce.
		$_GET['_wpnonce'] = 'invalid_nonce_value';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, $post_id );
	}

	/**
	 * Test validate_request returns true on success.
	 */
	public function test_validate_request_returns_true_on_success() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);

		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, $post_id );

		$this->assertTrue( $result );
	}

	/**
	 * Test stream_file_download returns error when filesystem unavailable.
	 *
	 * This tests the WP_Filesystem initialization error path.
	 */
	public function test_stream_file_download_returns_error_for_missing_file() {
		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, '/path/that/does/not/exist/file.docx' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test stream_file_download returns error for unreadable file.
	 */
	public function test_stream_file_download_returns_error_for_unreadable_file() {
		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		// Create a file that cannot be read.
		$file_path = $this->temp_dir . '/unreadable.docx';
		file_put_contents( $file_path, 'test content' );
		chmod( $file_path, 0000 );

		$result = $method->invoke( $handler, $file_path );

		// Restore permissions for cleanup.
		chmod( $file_path, 0644 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test stream_file_download with valid file returns true.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stream_file_download_with_valid_file() {
		// Create a test file.
		$file_path = $this->temp_dir . '/test_document.docx';
		$content   = 'Test document content for streaming';
		file_put_contents( $file_path, $content );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		// Capture output.
		ob_start();
		$result = $method->invoke( $handler, $file_path );
		$output = ob_get_clean();

		$this->assertTrue( $result );
		$this->assertSame( $content, $output );
	}

	/**
	 * Test stream_file_download with empty file.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stream_file_download_with_empty_file() {
		// Create an empty test file.
		$file_path = $this->temp_dir . '/empty_document.docx';
		file_put_contents( $file_path, '' );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		// Capture output.
		ob_start();
		$result = $method->invoke( $handler, $file_path );
		$output = ob_get_clean();

		$this->assertTrue( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * Test get_post_id_from_request with string that starts with numbers.
	 */
	public function test_get_post_id_from_request_with_mixed_string() {
		$_GET['post_id'] = '123abc';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		// intval('123abc') returns 123.
		$this->assertSame( 123, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test get_post_id_from_request with negative number.
	 */
	public function test_get_post_id_from_request_with_negative() {
		$_GET['post_id'] = '-5';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( -5, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test get_post_id_from_request with zero string.
	 */
	public function test_get_post_id_from_request_with_zero_string() {
		$_GET['post_id'] = '0';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( 0, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test handle_error method exists and is protected.
	 */
	public function test_handle_error_method_is_protected() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'handle_error' );

		$this->assertTrue( $method->isProtected() );
		$this->assertSame( 2, $method->getNumberOfRequiredParameters() );
	}

	/**
	 * Test handle method is public and callable.
	 */
	public function test_handle_method_is_public() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'handle' );

		$this->assertTrue( $method->isPublic() );
		$this->assertSame( 0, $method->getNumberOfRequiredParameters() );
	}

	/**
	 * Test validate_request checks post_id before nonce.
	 *
	 * This ensures code path where post_id is checked first.
	 */
	public function test_validate_request_checks_post_id_first() {
		// Set invalid nonce but no post_id - should fail on post_id.
		$_GET['_wpnonce'] = 'some_nonce';

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Permisos insuficientes.' );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, 0 );
	}

	/**
	 * Test stream_file_download requires WP_Filesystem.
	 */
	public function test_stream_file_download_initializes_filesystem() {
		global $wp_filesystem;

		// Temporarily store the filesystem.
		$original_filesystem = $wp_filesystem;

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		// Call with non-existent file.
		$result = $method->invoke( $handler, '/nonexistent/file.docx' );

		// Filesystem should have been initialized.
		$this->assertNotNull( $wp_filesystem );
		$this->assertInstanceOf( WP_Error::class, $result );

		// Restore.
		$wp_filesystem = $original_filesystem;
	}

	/**
	 * Test validate_request with valid post but wrong nonce action.
	 */
	public function test_validate_request_with_wrong_nonce_action() {
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'Nonce no válido.' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);

		// Create nonce for wrong action.
		$_GET['_wpnonce'] = wp_create_nonce( 'wrong_action' );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$method->invoke( $handler, $post_id );
	}
}
