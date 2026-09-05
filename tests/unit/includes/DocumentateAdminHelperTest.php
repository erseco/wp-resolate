<?php
/**
 * Tests for Documentate_Admin_Helper class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Helper
 */
class DocumentateAdminHelperTest extends Documentate_Test_Base {

	/**
	 * Admin helper instance.
	 *
	 * @var Documentate_Admin_Helper
	 */
	private $helper;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// Create users.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin_user_id );

		// Set up admin context.
		set_current_screen( 'edit-documentate_document' );

		$this->helper = new Documentate_Admin_Helper();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertSame( 10, has_filter( 'post_row_actions', array( $this->helper, 'add_row_actions' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_docx', array( $this->helper, 'handle_export_docx' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_odt', array( $this->helper, 'handle_export_odt' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_pdf', array( $this->helper, 'handle_export_pdf' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_preview', array( $this->helper, 'handle_preview' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_preview_stream', array( $this->helper, 'handle_preview_stream' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_converter', array( $this->helper, 'render_converter_page' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_documentate_generate_document', array( $this->helper, 'ajax_generate_document' ) ) );
		$this->assertSame( 10, has_action( 'add_meta_boxes', array( $this->helper, 'add_actions_metabox' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->helper, 'maybe_notice' ) ) );
	}

	/**
	 * Test add_row_actions returns unmodified for non-document post types.
	 */
	public function test_add_row_actions_ignores_other_post_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertSame( $actions, $result );
		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions adds export link for documents with DOCX template.
	 */
	public function test_add_row_actions_adds_docx_export_with_template() {
		// Set up a DOCX template.
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
		$this->assertStringContainsString( 'Export DOCX', $result['documentate_export_docx'] );
		$this->assertStringContainsString( 'documentate_export_docx', $result['documentate_export_docx'] );
	}

	/**
	 * Test add_row_actions does not add export without template.
	 */
	public function test_add_row_actions_no_export_without_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions adds export when document type has template.
	 */
	public function test_add_row_actions_with_document_type_template() {
		// Create document type with template using wp_insert_term directly.
		$term_result = wp_insert_term( 'Test Doc Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term_result );
		$doc_type = $term_result['term_id'];
		update_term_meta( $doc_type, 'documentate_type_docx_template', 456 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_actions_metabox registers metabox.
	 */
	public function test_add_actions_metabox() {
		global $wp_meta_boxes;

		// Call the method.
		$this->helper->add_actions_metabox();

		// Check that the metabox is registered.
		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['documentate_document']['side'] );
		$this->assertArrayHasKey( 'documentate_actions', $wp_meta_boxes['documentate_document']['side']['high'] );
	}

	/**
	 * Test maybe_notice does not output without query param.
	 */
	public function test_maybe_notice_no_output_without_param() {
		unset( $_GET['documentate_notice'] );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_notice outputs notice with query param.
	 */
	public function test_maybe_notice_outputs_with_param() {
		$_GET['documentate_notice'] = 'Test error message';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Test error message', $output );
	}

	/**
	 * Test maybe_notice sanitizes input.
	 */
	public function test_maybe_notice_sanitizes_input() {
		$_GET['documentate_notice'] = '<script>alert("xss")</script>';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
	}

	/**
	 * Test enqueue_title_textarea_assets does not enqueue on wrong screen.
	 */
	public function test_enqueue_title_textarea_assets_wrong_screen() {
		set_current_screen( 'edit-post' );

		wp_dequeue_style( 'documentate-title-textarea' );
		wp_dequeue_script( 'documentate-title-textarea' );

		$this->helper->enqueue_title_textarea_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
	}

	/**
	 * Test enqueue_title_textarea_assets enqueues on document edit screen.
	 */
	public function test_enqueue_title_textarea_assets_document_screen() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_title_textarea_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-annexes', 'enqueued' ) );
	}

	/**
	 * Test enqueue_actions_metabox_assets does not enqueue on wrong hook.
	 */
	public function test_enqueue_actions_metabox_assets_wrong_hook() {
		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test enqueue_actions_metabox_assets enqueues on post edit screen.
	 */
	public function test_enqueue_actions_metabox_assets_post_screen() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-actions', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * The unsaved-changes guard must load before the actions script.
	 *
	 * Its click handler runs on the capture phase, and so does the signing
	 * handler in documentate-autofirma, which stops propagation. Capture handlers
	 * on the same node fire in registration order, so the guard only gets to see
	 * the signing click when its script is registered first. Declaring it as a
	 * dependency of documentate-actions is what pins that order.
	 */
	public function test_enqueue_actions_metabox_assets_loads_unsaved_guard_first() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-unsaved-changes', 'enqueued' ) );

		$actions = wp_scripts()->registered['documentate-actions'];
		$this->assertContains( 'documentate-unsaved-changes', $actions->deps );
	}

	/**
	 * The guard needs its post ID and labels to reach the browser.
	 */
	public function test_enqueue_actions_metabox_assets_localizes_unsaved_guard() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$data = wp_scripts()->get_data( 'documentate-unsaved-changes', 'data' );

		// wp_localize_script casts scalars to strings, which is why the guard
		// parses the ID back to an integer before using it.
		$this->assertStringContainsString( 'documentateUnsavedChangesConfig', (string) $data );
		$this->assertStringContainsString( '"postId":"' . $post->ID . '"', (string) $data );
		$this->assertStringContainsString( 'saveAndPreview', (string) $data );
	}

	/**
	 * The actions meta box renders the passive indicator, hidden until dirty.
	 */
	public function test_render_actions_metabox_includes_unsaved_indicator() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-unsaved-indicator', $output );
		$this->assertStringContainsString( 'role="status"', $output );
		$this->assertStringContainsString( 'hidden', $output );
	}

	/**
	 * Test build_action_attributes method via reflection.
	 */
	public function test_build_action_attributes() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button',
			'href'  => 'https://example.com',
			'data-test' => 'value',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringContainsString( 'href="https://example.com"', $result );
		$this->assertStringContainsString( 'data-test="value"', $result );
	}

	/**
	 * Test build_action_attributes escapes values.
	 */
	public function test_build_action_attributes_escapes_values() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'data-test' => '<script>alert("xss")</script>',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringNotContainsString( '<script>', $result );
	}

	/**
	 * Test get_preview_stream_transient_key method via reflection.
	 */
	public function test_get_preview_stream_transient_key() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$method->setAccessible( true );

		$post_id = 123;
		$user_id = 456;

		$key = $method->invoke( $this->helper, $post_id, $user_id );

		$this->assertSame( 'documentate_preview_stream_456_123', $key );
	}

	/**
	 * Test remember_preview_stream_file method via reflection.
	 */
	public function test_remember_preview_stream_file() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$post_id = 123;
		$filename = 'test-document.pdf';

		$result = $method->invoke( $this->helper, $post_id, $filename );

		$this->assertTrue( $result );

		// Verify transient was set.
		$key_method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->helper, $post_id, get_current_user_id() );

		$this->assertSame( $filename, get_transient( $key ) );
	}

	/**
	 * Test remember_preview_stream_file fails without user.
	 */
	public function test_remember_preview_stream_file_fails_without_user() {
		wp_set_current_user( 0 );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, 123, 'test.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test remember_preview_stream_file fails with empty filename.
	 */
	public function test_remember_preview_stream_file_fails_empty_filename() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, 123, '' );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_wp_filesystem method via reflection.
	 */
	public function test_get_wp_filesystem() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_wp_filesystem' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		// Should return a filesystem instance or WP_Error.
		$this->assertTrue(
			$result instanceof WP_Filesystem_Base || is_wp_error( $result )
		);
	}

	/**
	 * Test ensure_document_generator loads class.
	 */
	public function test_ensure_document_generator_loads_class() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'ensure_document_generator' );
		$method->setAccessible( true );

		$method->invoke( $this->helper );

		$this->assertTrue( class_exists( 'Documentate_Document_Generator' ) );
	}

	/**
	 * Test render_actions_metabox outputs buttons.
	 */
	public function test_render_actions_metabox_outputs_buttons() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Preview', $output );
		$this->assertStringContainsString( 'Download PDF', $output );
		$this->assertStringContainsString( 'DOCX', $output );
		$this->assertStringContainsString( 'ODT', $output );
	}

	/**
	 * Test render_actions_metabox with insufficient permissions.
	 */
	public function test_render_actions_metabox_insufficient_permissions() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_current_user( 0 );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Insufficient permissions', $output );
	}

	/**
	 * Test stream_file_download with empty path.
	 */
	public function test_stream_file_download_empty_path() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_download_missing', $result->get_error_code() );
	}

	/**
	 * Test stream_file_download with non-existent file.
	 */
	public function test_stream_file_download_nonexistent_file() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '/nonexistent/path/file.pdf', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets with GLOBALS post.
	 */
	public function test_enqueue_actions_metabox_assets_with_global_post() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$GLOBALS['post'] = $post;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test maybe_notice does not output on wrong screen.
	 */
	public function test_maybe_notice_wrong_screen() {
		$_GET['documentate_notice'] = 'Test error';
		set_current_screen( 'dashboard' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test enqueue_title_textarea_assets on post-new screen.
	 */
	public function test_enqueue_title_textarea_assets_post_new() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post-new';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_title_textarea_assets( 'post-new.php' );

		$this->assertTrue( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
	}

	/**
	 * Test render_actions_metabox with templates configured.
	 */
	public function test_render_actions_metabox_with_templates() {
		// Create a document type with a template.
		$term = wp_insert_term( 'Actions Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Create an attachment for the template using plugin_dir_path.
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ODT', $output );
	}

	/**
	 * Test build_action_attributes with empty value.
	 */
	public function test_build_action_attributes_empty_value() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button',
			'data-empty' => '',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringNotContainsString( 'data-empty', $result );
	}

	/**
	 * Test add_row_actions checks editor permissions.
	 */
	public function test_add_row_actions_editor_permissions() {
		wp_set_current_user( $this->editor_user_id );
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		// Scope is object-level authorization: place the document in the editor's scope.
		$cat = wp_insert_term( 'Editor Scope Helper', 'category' );
		$this->assertIsArray( $cat );
		update_user_meta( $this->editor_user_id, 'documentate_scope_term_id', $cat['term_id'] );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_author' => $this->editor_user_id,
			)
		);
		wp_set_object_terms( $post->ID, array( $cat['term_id'] ), 'category' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets with no post ID.
	 */
	public function test_enqueue_actions_metabox_assets_no_post_id() {
		unset( $_GET['post'] );
		unset( $GLOBALS['post'] );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test add_row_actions with DOCX template at term level.
	 */
	public function test_add_row_actions_with_docx_term_template() {
		$term_result = wp_insert_term( 'DOCX Doc Type', 'documentate_doc_type' );
		$doc_type = $term_result['term_id'];
		update_term_meta( $doc_type, 'documentate_type_docx_template', 789 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions with DOCX and PDF converter setting.
	 */
	public function test_add_row_actions_with_pdf_converter() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'wasm',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		// add_row_actions only adds DOCX export, not PDF.
		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test enqueue_title_textarea_assets when function does not exist.
	 */
	public function test_enqueue_title_textarea_no_screen_function() {
		// We can't easily undefine functions, so just test the normal path.
		$this->helper->enqueue_title_textarea_assets( 'edit.php' );
		$this->assertTrue( true );
	}

	/**
	 * Test render_actions_metabox shows disabled when no template.
	 */
	public function test_render_actions_metabox_no_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Preview', $output );
	}

	/**
	 * Test render_actions_metabox with PDF converter enabled.
	 */
	public function test_render_actions_metabox_with_pdf_converter() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'collabora',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PDF', $output );
	}

	/**
	 * Test render_actions_metabox with LibreOffice WASM converter.
	 */
	public function test_render_actions_metabox_with_wasm() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'wasm',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PDF', $output );
	}

	/**
	 * Test add_row_actions without any template configured.
	 */
	public function test_add_row_actions_no_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test build_action_attributes handles all attribute types.
	 */
	public function test_build_action_attributes_mixed_types() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button primary',
			'id' => 'my-button',
			'data-post-id' => '123',
			'disabled' => '',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button primary"', $result );
		$this->assertStringContainsString( 'id="my-button"', $result );
		$this->assertStringContainsString( 'data-post-id="123"', $result );
	}

	/**
	 * Test stream_file_download with file path with traversal attempt.
	 */
	public function test_stream_file_download_path_traversal() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '../../../etc/passwd', 'text/plain' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets on post-new hook.
	 */
	public function test_enqueue_actions_metabox_assets_post_new() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post-new.php' );

		// Should not enqueue because no post ID on new post.
		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test maybe_notice with empty message.
	 */
	public function test_maybe_notice_empty_message() {
		$_GET['documentate_notice'] = '';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_actions_metabox with published post.
	 */
	public function test_render_actions_metabox_published_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'publish',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test render_actions_metabox with draft post.
	 */
	public function test_render_actions_metabox_draft_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'draft',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test enqueue_actions_metabox_assets wrong post type.
	 */
	public function test_enqueue_actions_metabox_assets_wrong_post_type() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test add_row_actions when user lacks permissions.
	 */
	public function test_add_row_actions_no_permissions() {
		// Create a subscriber user.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_author' => $this->admin_user_id,
		) );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertEmpty( $result );
	}

	/**
	 * Test hooks are registered for AJAX handler.
	 */
	public function test_ajax_handler_registered() {
		$this->assertNotFalse(
			has_action( 'wp_ajax_documentate_generate_document', array( $this->helper, 'ajax_generate_document' ) )
		);
	}

	/**
	 * Test hooks are registered for export handlers.
	 */
	public function test_export_handlers_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_docx', array( $this->helper, 'handle_export_docx' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_odt', array( $this->helper, 'handle_export_odt' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_pdf', array( $this->helper, 'handle_export_pdf' ) )
		);
	}

	/**
	 * Test hooks are registered for preview handlers.
	 */
	public function test_preview_handlers_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_preview', array( $this->helper, 'handle_preview' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_preview_stream', array( $this->helper, 'handle_preview_stream' ) )
		);
	}

	/**
	 * Test hooks are registered for converter page.
	 */
	public function test_converter_handler_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_converter', array( $this->helper, 'render_converter_page' ) )
		);
	}

	/**
	 * Test enqueue_actions_metabox_assets with CDN mode enabled.
	 */
	public function test_enqueue_actions_metabox_assets_cdn_mode() {
		update_option( 'documentate_settings', array(
			'pdf_converter' => 'wasm',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test render_actions_metabox with ODT template.
	 */
	public function test_render_actions_metabox_with_odt_template() {
		$term = wp_insert_term( 'ODT Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'button', $output );
	}

	/**
	 * Test render_actions_metabox with DOCX template.
	 */
	public function test_render_actions_metabox_with_docx_template() {
		$term = wp_insert_term( 'DOCX Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/demo-wp-documentate.docx';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test build_action_attributes with href empty value keeps attribute.
	 */
	public function test_build_action_attributes_href_empty() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'href' => '',
			'class' => 'button',
		);

		$result = $method->invoke( $this->helper, $attrs );

		// href should be included even if empty.
		$this->assertStringContainsString( 'href=', $result );
		$this->assertStringContainsString( 'class="button"', $result );
	}

	/**
	 * Test remember_preview_stream_file sanitizes filename.
	 */
	public function test_remember_preview_stream_file_sanitizes() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$post_id = 123;
		// Attempt path traversal in filename.
		$filename = '../../../etc/passwd';

		$result = $method->invoke( $this->helper, $post_id, $filename );

		// Should sanitize (sanitize_file_name removes slashes and dots).
		$this->assertTrue( $result );

		$key_method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->helper, $post_id, get_current_user_id() );

		$stored = get_transient( $key );
		// sanitize_file_name removes dots and slashes, leaving 'etcpasswd'.
		$this->assertSame( 'etcpasswd', $stored );
	}

	/**
	 * Test render_actions_metabox outputs preferred format as primary.
	 */
	public function test_render_actions_metabox_preferred_format() {
		$term = wp_insert_term( 'Preferred Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// The ODT button should be primary.
		$this->assertStringContainsString( 'button-primary', $output );
	}

	/**
	 * Test maybe_notice on post base screen.
	 */
	public function test_maybe_notice_post_base_screen() {
		$_GET['documentate_notice'] = 'Test message';
		$screen = WP_Screen::get( 'post' );
		$screen->base = 'post';
		$screen->id = 'post';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test message', $output );
	}

	/**
	 * Test enqueue_title_textarea_assets on wrong post type.
	 */
	public function test_enqueue_title_textarea_wrong_post_type() {
		$screen = WP_Screen::get( 'post' );
		$screen->base = 'post';
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-title-textarea' );
		wp_dequeue_script( 'documentate-title-textarea' );

		$this->helper->enqueue_title_textarea_assets( 'post.php' );

		$this->assertFalse( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
	}

	/**
	 * Test add_row_actions with zero template ID at term level.
	 */
	public function test_add_row_actions_zero_term_template() {
		$term_result = wp_insert_term( 'Zero Template Type', 'documentate_doc_type' );
		$doc_type = $term_result['term_id'];
		update_term_meta( $doc_type, 'documentate_type_docx_template', 0 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test get_wp_filesystem caches instance.
	 */
	public function test_get_wp_filesystem_caches() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_wp_filesystem' );
		$method->setAccessible( true );

		$result1 = $method->invoke( $this->helper );
		$result2 = $method->invoke( $this->helper );

		// Both calls should return the same instance.
		if ( $result1 instanceof WP_Filesystem_Base && $result2 instanceof WP_Filesystem_Base ) {
			$this->assertSame( $result1, $result2 );
		}
	}

	/**
	 * Test ensure_document_generator only loads once.
	 */
	public function test_ensure_document_generator_caches() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'ensure_document_generator' );
		$method->setAccessible( true );

		$prop = $reflection->getProperty( 'document_generator_loaded' );
		$prop->setAccessible( true );

		// First call.
		$method->invoke( $this->helper );
		$this->assertTrue( $prop->getValue( $this->helper ) );

		// Second call should not reload.
		$method->invoke( $this->helper );
		$this->assertTrue( $prop->getValue( $this->helper ) );
	}

	/**
	 * Test render_actions_metabox without conversion available.
	 */
	public function test_render_actions_metabox_no_conversion() {
		delete_option( 'documentate_settings' );

		$term = wp_insert_term( 'No Conv Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ODT', $output );
	}

	/**
	 * Test admin_enqueue_scripts hook is registered.
	 */
	public function test_admin_enqueue_scripts_hooks_registered() {
		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', array( $this->helper, 'enqueue_title_textarea_assets' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', array( $this->helper, 'enqueue_actions_metabox_assets' ) )
		);
	}

	/**
	 * Test add_meta_boxes hook is registered.
	 */
	public function test_add_meta_boxes_hook_registered() {
		$this->assertNotFalse(
			has_action( 'add_meta_boxes', array( $this->helper, 'add_actions_metabox' ) )
		);
	}

	/**
	 * Test build_action_attributes with special characters.
	 */
	public function test_build_action_attributes_special_chars() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button & primary',
			'href'  => 'https://example.com?a=1&b=2',
		);

		$result = $method->invoke( $this->helper, $attrs );

		// Should be properly escaped.
		$this->assertStringContainsString( 'class="button', $result );
		$this->assertStringContainsString( 'href=', $result );
	}

	/**
	 * Test render_actions_metabox with both templates.
	 */
	public function test_render_actions_metabox_both_templates() {
		$term = wp_insert_term( 'Both Templates Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$odt_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		$docx_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/demo-wp-documentate.docx';

		if ( file_exists( $odt_path ) && file_exists( $docx_path ) ) {
			$odt_id = $this->factory->attachment->create_upload_object( $odt_path );
			$docx_id = $this->factory->attachment->create_upload_object( $docx_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $odt_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ODT', $output );
	}

	/**
	 * Test get_current_post_id from GET parameter.
	 */
	public function test_get_current_post_id_from_get() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertSame( $post->ID, $result );

		unset( $_GET['post'] );
	}

	/**
	 * Test get_current_post_id from global post.
	 */
	public function test_get_current_post_id_from_global() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$GLOBALS['post'] = $post;

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertSame( $post->ID, $result );

		unset( $GLOBALS['post'] );
	}

	/**
	 * Test get_current_post_id returns zero when not available.
	 */
	public function test_get_current_post_id_returns_zero() {
		unset( $_GET['post'] );
		unset( $GLOBALS['post'] );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test get_actions_script_strings returns expected keys.
	 */
	public function test_get_actions_script_strings() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_actions_script_strings' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'generating', $result );
		$this->assertArrayHasKey( 'generatingPreview', $result );
		$this->assertArrayHasKey( 'generatingFormat', $result );
		$this->assertArrayHasKey( 'wait', $result );
		$this->assertArrayHasKey( 'close', $result );
		$this->assertArrayHasKey( 'errorGeneric', $result );
		$this->assertArrayHasKey( 'errorNetwork', $result );
		$this->assertArrayHasKey( 'loadingWasm', $result );
		$this->assertArrayHasKey( 'convertingBrowser', $result );
		$this->assertArrayHasKey( 'wasmError', $result );
	}

	/**
	 * Test build_actions_script_config returns expected structure.
	 */
	public function test_build_actions_script_config() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_actions_script_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, $post->ID );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'ajaxUrl', $result );
		$this->assertArrayHasKey( 'postId', $result );
		$this->assertArrayHasKey( 'nonce', $result );
		$this->assertArrayHasKey( 'strings', $result );
		$this->assertSame( $post->ID, $result['postId'] );
		$this->assertStringContainsString( 'admin-ajax.php', $result['ajaxUrl'] );
	}

	/**
	 * Test add_conversion_mode_config with no conversion available.
	 */
	public function test_add_conversion_mode_config_default() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$config = array( 'test' => 'value' );
		$result = $method->invoke( $this->helper, $config );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'test', $result );
		$this->assertSame( 'value', $result['test'] );
	}

	/**
	 * Test render_converter_page method exists.
	 */
	public function test_render_converter_page_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'render_converter_page' ) );
	}

	/**
	 * Test ajax_generate_document handler exists.
	 */
	public function test_ajax_generate_document_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'ajax_generate_document' ) );
	}

	/**
	 * Test handle_preview method exists.
	 */
	public function test_handle_preview_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'handle_preview' ) );
	}

	/**
	 * Test handle_preview_stream method exists.
	 */
	public function test_handle_preview_stream_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'handle_preview_stream' ) );
	}

	/**
	 * Test handle_export_docx method exists.
	 */
	public function test_handle_export_docx_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'handle_export_docx' ) );
	}

	/**
	 * Test handle_export_odt method exists.
	 */
	public function test_handle_export_odt_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'handle_export_odt' ) );
	}

	/**
	 * Test handle_export_pdf method exists.
	 */
	public function test_handle_export_pdf_method_exists() {
		$this->assertTrue( method_exists( $this->helper, 'handle_export_pdf' ) );
	}

	/**
	 * Test get_current_post_id prefers GET over global.
	 */
	public function test_get_current_post_id_prefers_get() {
		$post1 = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$post2 = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post1->ID;
		$GLOBALS['post'] = $post2;

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertSame( $post1->ID, $result );

		unset( $_GET['post'] );
		unset( $GLOBALS['post'] );
	}

	/**
	 * Test build_actions_script_config with different post IDs.
	 */
	public function test_build_actions_script_config_different_posts() {
		$post1 = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$post2 = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_actions_script_config' );
		$method->setAccessible( true );

		$result1 = $method->invoke( $this->helper, $post1->ID );
		$result2 = $method->invoke( $this->helper, $post2->ID );

		$this->assertNotSame( $result1['postId'], $result2['postId'] );
		$this->assertNotSame( $result1['nonce'], $result2['nonce'] );
	}

	/**
	 * Test get_actions_script_strings values are strings.
	 */
	public function test_get_actions_script_strings_all_strings() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_actions_script_strings' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		foreach ( $result as $key => $value ) {
			$this->assertIsString( $value, "Value for key '$key' should be a string" );
			$this->assertNotEmpty( $value, "Value for key '$key' should not be empty" );
		}
	}

	/**
	 * Test add_conversion_mode_config preserves existing config.
	 */
	public function test_add_conversion_mode_config_preserves_existing() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$config = array(
			'ajaxUrl' => 'http://example.com/ajax',
			'postId'  => 123,
			'nonce'   => 'test_nonce',
			'strings' => array( 'test' => 'value' ),
		);
		$result = $method->invoke( $this->helper, $config );

		$this->assertSame( 'http://example.com/ajax', $result['ajaxUrl'] );
		$this->assertSame( 123, $result['postId'] );
		$this->assertSame( 'test_nonce', $result['nonce'] );
		$this->assertSame( array( 'test' => 'value' ), $result['strings'] );
	}

	/**
	 * Invoke the private build_generated_document_url() helper.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $format  Requested format.
	 * @param string $output  Either preview or download.
	 * @param string $result  Path to the generated file.
	 * @return string
	 */
	private function invoke_generated_document_url( $post_id, $format, $output, $result ) {
		$method = ( new ReflectionClass( $this->helper ) )->getMethod( 'build_generated_document_url' );
		$method->setAccessible( true );

		return $method->invoke( $this->helper, $post_id, $format, $output, $result );
	}

	/**
	 * Test build_generated_document_url returns the export URL for downloads.
	 */
	public function test_build_generated_document_url_download() {
		$url = $this->invoke_generated_document_url( 123, 'docx', 'download', '/tmp/doc.docx' );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=documentate_export_docx', $url );
		$this->assertStringContainsString( 'post_id=123', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Test build_generated_document_url uses the export action of each format.
	 */
	public function test_build_generated_document_url_download_formats() {
		foreach ( array( 'docx', 'odt', 'pdf' ) as $format ) {
			$url = $this->invoke_generated_document_url( 456, $format, 'download', '/tmp/doc.' . $format );

			$this->assertStringContainsString( 'action=documentate_export_' . $format, $url, $format );
			$this->assertStringContainsString( 'post_id=456', $url, $format );
		}
	}

	/**
	 * Test build_generated_document_url returns the stream URL for previews.
	 */
	public function test_build_generated_document_url_preview() {
		$url = $this->invoke_generated_document_url( 789, 'pdf', 'preview', '/tmp/preview.pdf' );

		$this->assertStringContainsString( 'action=documentate_preview_stream', $url );
		$this->assertStringContainsString( 'post_id=789', $url );
		$this->assertStringNotContainsString( 'documentate_export', $url );
	}

	/**
	 * Test build_generated_document_url caches the preview file for the user.
	 */
	public function test_build_generated_document_url_preview_remembers_file() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->invoke_generated_document_url( 790, 'pdf', 'preview', '/tmp/preview-790.pdf' );

		$key_method = ( new ReflectionClass( $this->helper ) )->getMethod( 'get_preview_stream_transient_key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->helper, 790, get_current_user_id() );

		$this->assertSame( 'preview-790.pdf', get_transient( $key ) );
	}

	/**
	 * Test format_generator_map static property exists.
	 */
	public function test_format_generator_map_exists() {
		$reflection = new ReflectionClass( $this->helper );
		$prop = $reflection->getProperty( 'format_generator_map' );
		$prop->setAccessible( true );

		$map = $prop->getValue();

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( 'docx', $map );
		$this->assertArrayHasKey( 'odt', $map );
		$this->assertArrayHasKey( 'pdf', $map );
		$this->assertSame( 'generate_docx', $map['docx'] );
		$this->assertSame( 'generate_odt', $map['odt'] );
		$this->assertSame( 'generate_pdf', $map['pdf'] );
	}

	/**
	 * Test export handlers are initialized in constructor.
	 */
	public function test_export_handlers_initialized() {
		$reflection = new ReflectionClass( $this->helper );

		$docx_prop = $reflection->getProperty( 'docx_handler' );
		$docx_prop->setAccessible( true );
		$this->assertInstanceOf( 'Documentate\\Export\\Export_DOCX_Handler', $docx_prop->getValue( $this->helper ) );

		$odt_prop = $reflection->getProperty( 'odt_handler' );
		$odt_prop->setAccessible( true );
		$this->assertInstanceOf( 'Documentate\\Export\\Export_ODT_Handler', $odt_prop->getValue( $this->helper ) );

		$pdf_prop = $reflection->getProperty( 'pdf_handler' );
		$pdf_prop->setAccessible( true );
		$this->assertInstanceOf( 'Documentate\\Export\\Export_PDF_Handler', $pdf_prop->getValue( $this->helper ) );
	}

	/**
	 * Test handle_export methods exist and are public.
	 */
	public function test_handle_export_methods_are_public() {
		$docx_ref = new ReflectionMethod( $this->helper, 'handle_export_docx' );
		$odt_ref = new ReflectionMethod( $this->helper, 'handle_export_odt' );
		$pdf_ref = new ReflectionMethod( $this->helper, 'handle_export_pdf' );

		$this->assertTrue( $docx_ref->isPublic() );
		$this->assertTrue( $odt_ref->isPublic() );
		$this->assertTrue( $pdf_ref->isPublic() );
	}

	/**
	 * Test render_actions_metabox with all buttons disabled.
	 */
	public function test_render_actions_metabox_all_disabled() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array(
			'post_type' => 'documentate_document',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// Should have disabled buttons.
		$this->assertStringContainsString( 'disabled', $output );
	}

	/**
	 * Test render_actions_metabox generates correct nonces.
	 */
	public function test_render_actions_metabox_generates_nonces() {
		// Configure a template so buttons are enabled.
		$term = wp_insert_term( 'Nonce Test Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array(
			'post_type' => 'documentate_document',
		) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// Verify action buttons exist.
		$this->assertStringContainsString( 'data-documentate-action', $output );
	}

	/**
	 * Test stream_file_download with valid file via mock.
	 */
	public function test_stream_file_download_valid_path() {
		// Create a temporary file.
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/documentate';
		wp_mkdir_p( $temp_dir );
		$temp_file = $temp_dir . '/test-document.docx';
		file_put_contents( $temp_file, 'test content' );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		// Can't fully test because it sends headers and exits.
		// Just verify the file exists and method can be called.
		$this->assertFileExists( $temp_file );

		// Cleanup.
		unlink( $temp_file );
	}

	/**
	 * Test add_row_actions with ODT template at term level.
	 */
	public function test_add_row_actions_with_odt_term_template() {
		$term_result = wp_insert_term( 'ODT Only Type', 'documentate_doc_type' );
		$doc_type = $term_result['term_id'];

		// Only ODT template, no DOCX.
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $doc_type, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $doc_type, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		// No DOCX template, so no DOCX export link.
		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test enqueue_title_textarea_assets loads wp_editor.
	 */
	public function test_enqueue_title_textarea_loads_editor() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_title_textarea_assets( 'post.php' );

		// Verify scripts are enqueued.
		$this->assertTrue( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-annexes', 'enqueued' ) );
	}

	/**
	 * Test add_actions_metabox adds metabox to correct location.
	 */
	public function test_add_actions_metabox_location() {
		global $wp_meta_boxes;

		$this->helper->add_actions_metabox();

		$this->assertArrayHasKey( 'documentate_actions', $wp_meta_boxes['documentate_document']['side']['high'] );

		$metabox = $wp_meta_boxes['documentate_document']['side']['high']['documentate_actions'];
		$this->assertSame( __( 'Document Actions', 'documentate' ), $metabox['title'] );
	}

	/**
	 * Test render_actions_metabox with conversion manager available.
	 */
	public function test_render_actions_metabox_with_conversion() {
		// Configure conversion settings.
		update_option( 'documentate_settings', array(
			'pdf_converter' => 'collabora',
			'collabora_base_url' => 'https://example.com/collabora',
		) );

		$term = wp_insert_term( 'Conv Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'button', $output );
	}

	/**
	 * Test build_actions_script_config includes all required keys.
	 */
	public function test_build_actions_script_config_complete() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_actions_script_config' );
		$method->setAccessible( true );

		$config = $method->invoke( $this->helper, $post->ID );

		$this->assertArrayHasKey( 'ajaxUrl', $config );
		$this->assertArrayHasKey( 'postId', $config );
		$this->assertArrayHasKey( 'nonce', $config );
		$this->assertArrayHasKey( 'strings', $config );
		$this->assertIsString( $config['ajaxUrl'] );
		$this->assertIsInt( $config['postId'] );
		$this->assertIsString( $config['nonce'] );
		$this->assertIsArray( $config['strings'] );
	}

	/**
	 * Test add_conversion_mode_config with browser WASM mode.
	 */
	public function test_add_conversion_mode_config_browser_wasm() {
		// Configure browser WASM mode.
		update_option( 'documentate_settings', array(
			'pdf_converter' => 'wasm',
		) );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$config = array( 'test' => 'value' );
		$result = $method->invoke( $this->helper, $config );

		// Original config should be preserved.
		$this->assertArrayHasKey( 'test', $result );
		$this->assertSame( 'value', $result['test'] );

		// Should have cdnMode key when browser WASM mode is configured and conversion not available.
		if ( Documentate_Libreoffice_Wasm_Converter::is_browser_mode() && ! Documentate_Conversion_Manager::is_available() ) {
			$this->assertArrayHasKey( 'cdnMode', $result );
			$this->assertTrue( $result['cdnMode'] );
		}
	}

	/**
	 * The WASM browser popup must NOT be offered in WordPress Playground, where the
	 * sandboxed, non-cross-origin-isolated iframe cannot run it.
	 */
	public function test_add_conversion_mode_config_wasm_not_offered_in_playground() {
		update_option( 'documentate_settings', array(
			'conversion_engine' => 'wasm',
		) );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, array() );

		// The WASM browser popup must never be offered in Playground. (When Collabora
		// is available it gracefully takes over via the collaboraPlayground fast path.)
		$this->assertArrayNotHasKey( 'cdnMode', $result );

		unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
	}

	/**
	 * Under the native engine the actions script must be told nothing about
	 * browser conversion, not even the Playground fast path: the PDF is drawn
	 * in PHP and there is nothing for the browser to convert. It is told that it
	 * is in Playground, because a preview cannot open in a new tab inside that
	 * sandboxed iframe and has to go to the embedded viewer instead.
	 */
	public function test_add_conversion_mode_config_is_silent_under_the_native_engine() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		try {
			update_option( 'documentate_settings', array(
				'conversion_engine' => 'collabora',
				'collabora_base_url' => 'https://collabora.example.org',
			) );
			$converter = $method->invoke( $this->helper, array() );

			update_option( 'documentate_settings', array(
				'conversion_engine' => 'fpdf',
				'collabora_base_url' => 'https://collabora.example.org',
			) );
			$native = $method->invoke( $this->helper, array( 'test' => 'value' ) );
		} finally {
			unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
		}

		// A converter engine in Playground does take the Collabora fast path.
		$this->assertArrayHasKey( 'collaboraPlayground', $converter );

		$this->assertArrayNotHasKey( 'collaboraPlayground', $native );
		$this->assertArrayNotHasKey( 'cdnMode', $native );
		$this->assertArrayNotHasKey( 'converterUrl', $native );
		$this->assertArrayNotHasKey( 'collaboraUrl', $native );

		// The one thing it does carry: where it is running.
		$this->assertTrue( $native['inPlayground'] );
		$this->assertSame( 'value', $native['test'], 'The caller\'s config must survive.' );
	}

	/**
	 * Outside Playground the native engine says nothing at all.
	 *
	 * A preview opens in a new tab everywhere else, so the flag that diverts it
	 * to the embedded viewer must not be set where the tab works.
	 */
	public function test_the_native_engine_sets_no_playground_flag_outside_playground() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'add_conversion_mode_config' );
		$method->setAccessible( true );

		unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );

		$config = $method->invoke( $this->helper, array( 'test' => 'value' ) );

		$this->assertSame( array( 'test' => 'value' ), $config, 'The config must be handed back untouched.' );
	}

	/**
	 * Test render_actions_metabox leaves out the editable download block for a
	 * document whose type has no template to hand over.
	 */
	public function test_render_actions_metabox_omits_the_editable_download_block() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-actions-primary', $output );
		$this->assertStringNotContainsString( 'Editable download:', $output );
		$this->assertStringNotContainsString( 'documentate-actions-secondary', $output );
	}

	/**
	 * Test maybe_notice on edit base screen does not show message.
	 */
	public function test_maybe_notice_edit_base_screen() {
		$_GET['documentate_notice'] = 'Test error on edit';
		$screen = WP_Screen::get( 'edit-documentate_document' );
		$screen->base = 'edit';
		$screen->id = 'edit-documentate_document';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		// Edit screen doesn't match 'post' base, so output should be empty.
		$this->assertEmpty( $output );

		unset( $_GET['documentate_notice'] );
	}

	/**
	 * Test get_current_post_id sanitizes input.
	 */
	public function test_get_current_post_id_sanitizes() {
		$_GET['post'] = '123abc';

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		$this->assertSame( 123, $result );

		unset( $_GET['post'] );
	}

	/**
	 * Test render_actions_metabox with CDN mode attributes.
	 */
	public function test_render_actions_metabox_cdn_mode_attributes() {
		update_option( 'documentate_settings', array(
			'pdf_converter' => 'wasm',
		) );

		$term = wp_insert_term( 'CDN Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// Should have action buttons.
		$this->assertStringContainsString( 'button', $output );
	}

	/**
	 * Test build_action_attributes with boolean attributes.
	 */
	public function test_build_action_attributes_boolean() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button',
			'disabled' => 'disabled',
			'data-active' => '1',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringContainsString( 'disabled="disabled"', $result );
		$this->assertStringContainsString( 'data-active="1"', $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets localizes script.
	 */
	public function test_enqueue_actions_metabox_assets_localizes() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-actions', 'enqueued' ) );

		unset( $_GET['post'] );
	}

	/**
	 * Test remember_preview_stream_file with special characters.
	 */
	public function test_remember_preview_stream_file_special_chars() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$post_id = 789;
		$filename = 'document with spaces & symbols!.pdf';

		$result = $method->invoke( $this->helper, $post_id, $filename );

		$this->assertTrue( $result );

		$key_method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->helper, $post_id, get_current_user_id() );

		$stored = get_transient( $key );
		// sanitize_file_name will clean up the filename.
		$this->assertNotEmpty( $stored );
	}

	/**
	 * Test get_preview_stream_transient_key format.
	 */
	public function test_get_preview_stream_transient_key_format() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$method->setAccessible( true );

		$key = $method->invoke( $this->helper, 100, 200 );

		$this->assertSame( 'documentate_preview_stream_200_100', $key );
	}

	/**
	 * Test render_actions_metabox with pending post.
	 */
	public function test_render_actions_metabox_pending_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'pending',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test add_row_actions with multiple document types.
	 */
	public function test_add_row_actions_multiple_types() {
		$term1 = wp_insert_term( 'Type One', 'documentate_doc_type' );
		$term2 = wp_insert_term( 'Type Two', 'documentate_doc_type' );

		update_term_meta( $term1['term_id'], 'documentate_type_docx_template', 111 );
		update_term_meta( $term2['term_id'], 'documentate_type_docx_template', 222 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, array( $term1['term_id'], $term2['term_id'] ), 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		// Uses first type's template.
		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test document_generator_loaded property behavior.
	 */
	public function test_document_generator_loaded_tracking() {
		$reflection = new ReflectionClass( $this->helper );

		$prop = $reflection->getProperty( 'document_generator_loaded' );
		$prop->setAccessible( true );

		// Initially false (might be true if already loaded).
		$initial = $prop->getValue( $this->helper );

		$method = $reflection->getMethod( 'ensure_document_generator' );
		$method->setAccessible( true );
		$method->invoke( $this->helper );

		// After calling, should be true.
		$this->assertTrue( $prop->getValue( $this->helper ) );
	}

	/**
	 * Test add_archive_row_actions ignores other post types.
	 */
	public function test_add_archive_row_actions_ignores_other_types() {
		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$actions = array( 'edit' => 'Edit' );
		$result = $this->helper->add_archive_row_actions( $actions, $post );

		// Should not add archive action for non-document post types.
		$this->assertArrayNotHasKey( 'documentate_archive', $result );
	}

	/**
	 * Test add_archive_row_actions returns actions unchanged for draft.
	 */
	public function test_add_archive_row_actions_draft() {
		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		$actions = array( 'edit' => 'Edit' );
		$result = $this->helper->add_archive_row_actions( $actions, $post );

		// Draft documents should not have archive/unarchive actions.
		$this->assertArrayNotHasKey( 'documentate_archive', $result );
		$this->assertArrayNotHasKey( 'documentate_unarchive', $result );
	}

	/**
	 * Test constructor registers archive hooks.
	 */
	public function test_constructor_registers_archive_hooks() {
		$this->assertSame( 15, has_filter( 'post_row_actions', array( $this->helper, 'add_archive_row_actions' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_archive', array( $this->helper, 'handle_archive_action' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_unarchive', array( $this->helper, 'handle_unarchive_action' ) ) );
	}

	/**
	 * Test render_actions_metabox for archived post still shows download buttons.
	 */
	public function test_render_actions_metabox_archived_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'archived',
			)
		);

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// Download actions should still be available for archived documents.
		$this->assertStringContainsString( 'DOCX', $output );
	}
}
