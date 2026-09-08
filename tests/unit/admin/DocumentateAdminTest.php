<?php
/**
 * Class Test_Documentate_Admin
 *
 * @package Documentate
 */

require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';


class DocumentateAdminTest extends WP_UnitTestCase {
	protected $admin;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

		// Mock admin context
		set_current_screen( 'edit-post' );

		// Create admin user and log in
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->admin = new Documentate_Admin( 'documentate', '1.0.0' );
	}

	public function test_constructor() {
		$this->assertInstanceOf( Documentate_Admin::class, $this->admin );
		$this->assertEquals( 10, has_filter( 'plugin_action_links_' . plugin_basename( DOCUMENTATE_PLUGIN_FILE ), array( $this->admin, 'add_settings_link' ) ) );
	}

	public function test_add_settings_link() {
		$links     = array();
		$new_links = $this->admin->add_settings_link( $links );

		$this->assertIsArray( $new_links );
		$this->assertCount( 1, $new_links );
		$this->assertStringContainsString( 'options-general.php?page=documentate_settings', $new_links[0] );
		$this->assertStringContainsString( 'Ajustes', $new_links[0] );
	}

	public function test_enqueue_styles() {
                // Clear any previously enqueued style
		wp_dequeue_style( 'documentate' );

                // Test with non-matching hook
		$this->admin->enqueue_styles( 'wrong_hook' );
		$this->assertFalse( wp_style_is( 'documentate', 'enqueued' ) );

                // Test with matching hook
		$this->admin->enqueue_styles( 'settings_page_documentate_settings' );
		$this->assertTrue( wp_style_is( 'documentate', 'enqueued' ) );
	}

	public function test_enqueue_scripts() {
                // Clear any previously enqueued script
		wp_dequeue_script( 'documentate' );

                // Test with non-matching hook
		$this->admin->enqueue_scripts( 'wrong_hook' );
		$this->assertFalse( wp_script_is( 'documentate', 'enqueued' ) );

                // Test with matching hook
		$this->admin->enqueue_scripts( 'settings_page_documentate_settings' );
		$this->assertTrue( wp_script_is( 'documentate', 'enqueued' ) );
	}


	public function test_load_dependencies() {
		$reflection = new ReflectionClass( $this->admin );
		$method     = $reflection->getMethod( 'load_dependencies' );
		$method->setAccessible( true );

		// Call the method again to test multiple loads
		$method->invoke( $this->admin );

		$this->assertTrue( class_exists( 'Documentate_Admin_Settings' ) );
	}

	/**
	 * Test is_collaborative_enabled returns false by default.
	 */
	public function test_is_collaborative_enabled_false_by_default() {
		delete_option( 'documentate_settings' );

		$result = Documentate_Admin::is_collaborative_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Test is_collaborative_enabled returns true when enabled.
	 */
	public function test_is_collaborative_enabled_true() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );

		$result = Documentate_Admin::is_collaborative_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Test get_collaborative_settings returns default signaling server.
	 */
	public function test_get_collaborative_settings_default() {
		delete_option( 'documentate_settings' );

		$result = Documentate_Admin::get_collaborative_settings();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['enabled'] );
		$this->assertSame( 'wss://signaling.yjs.dev', $result['signalingServer'] );
	}

	/**
	 * Test get_collaborative_settings returns custom signaling server.
	 */
	public function test_get_collaborative_settings_custom() {
		update_option(
			'documentate_settings',
			array(
				'collaborative_enabled'   => '1',
				'collaborative_signaling' => 'wss://custom.signaling.com',
			)
		);

		$result = Documentate_Admin::get_collaborative_settings();

		$this->assertTrue( $result['enabled'] );
		$this->assertSame( 'wss://custom.signaling.com', $result['signalingServer'] );
	}

	/**
	 * Test enqueue_collaborative_editor does not enqueue on wrong hook.
	 */
	public function test_enqueue_collaborative_editor_wrong_hook() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		wp_dequeue_script( 'documentate-collaborative-editor' );

		$this->admin->enqueue_collaborative_editor( 'edit.php' );

		$this->assertFalse( wp_script_is( 'documentate-collaborative-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_collaborative_editor does not enqueue for other post types.
	 */
	public function test_enqueue_collaborative_editor_wrong_post_type() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		wp_dequeue_script( 'documentate-collaborative-editor' );

		$screen            = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		$this->admin->enqueue_collaborative_editor( 'post.php' );

		$this->assertFalse( wp_script_is( 'documentate-collaborative-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_collaborative_editor does not enqueue when disabled.
	 */
	public function test_enqueue_collaborative_editor_disabled() {
		delete_option( 'documentate_settings' );
		wp_dequeue_script( 'documentate-collaborative-editor' );

		$screen            = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->admin->enqueue_collaborative_editor( 'post.php' );

		$this->assertFalse( wp_script_is( 'documentate-collaborative-editor', 'enqueued' ) );
	}

	/**
	 * Test enqueue_collaborative_editor enqueues when enabled.
	 */
	public function test_enqueue_collaborative_editor_enabled() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );

		$screen            = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->admin->enqueue_collaborative_editor( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-collaborative-editor', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'documentate-collaborative-editor', 'enqueued' ) );
	}

	/**
	 * Test add_module_type_to_collaborative_script modifies tag.
	 */
	public function test_add_module_type_to_collaborative_script() {
		$tag    = '<script src="test.js"></script>';
		$result = $this->admin->add_module_type_to_collaborative_script( $tag, 'documentate-collaborative-editor', 'test.js' );

		$this->assertStringContainsString( 'type="module"', $result );
	}

	/**
	 * Test add_module_type_to_collaborative_script ignores other scripts.
	 */
	public function test_add_module_type_to_collaborative_script_ignores_other() {
		$tag    = '<script src="test.js"></script>';
		$result = $this->admin->add_module_type_to_collaborative_script( $tag, 'other-script', 'test.js' );

		$this->assertStringNotContainsString( 'type="module"', $result );
	}

	/**
	 * Test register_collaborative_status_metabox does nothing when disabled.
	 */
	public function test_register_collaborative_status_metabox_disabled() {
		global $wp_meta_boxes;

		delete_option( 'documentate_settings' );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$this->admin->register_collaborative_status_metabox( $post );

		$metabox_exists = isset( $wp_meta_boxes['documentate_document']['side']['high']['documentate_collaborative_status'] );
		$this->assertFalse( $metabox_exists );
	}

	/**
	 * Test register_collaborative_status_metabox adds metabox when enabled.
	 */
	public function test_register_collaborative_status_metabox_enabled() {
		global $wp_meta_boxes;

		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$this->admin->register_collaborative_status_metabox( $post );

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['documentate_document']['side'] );
		$this->assertArrayHasKey( 'documentate_collaborative_status', $wp_meta_boxes['documentate_document']['side']['high'] );
	}

	/**
	 * Test render_collaborative_status_metabox outputs HTML.
	 */
	public function test_render_collaborative_status_metabox() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->admin->render_collaborative_status_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-collab-status-metabox', $output );
		$this->assertStringContainsString( 'documentate-collab-metabox__status', $output );
		$this->assertStringContainsString( 'Conectando', $output );
	}

	/**
	 * Test disable_post_lock_dialog returns false for documents with collab enabled.
	 */
	public function test_disable_post_lock_dialog_collaborative() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$user = wp_get_current_user();

		$result = $this->admin->disable_post_lock_dialog( true, $post, $user );

		$this->assertFalse( $result );
	}

	/**
	 * Test disable_post_lock_dialog returns original value for other types.
	 */
	public function test_disable_post_lock_dialog_other_type() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$user = wp_get_current_user();

		$result = $this->admin->disable_post_lock_dialog( true, $post, $user );

		$this->assertTrue( $result );
	}

	/**
	 * Test disable_post_lock_window returns false for documents with collab.
	 */
	public function test_disable_post_lock_window_collaborative() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post          = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post']  = $post->ID;

		$result = $this->admin->disable_post_lock_window( 150 );

		$this->assertFalse( $result );

		unset( $_GET['post'] );
	}

	/**
	 * Test disable_post_lock_window returns original for other types.
	 */
	public function test_disable_post_lock_window_other_type() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post         = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$_GET['post'] = $post->ID;

		$result = $this->admin->disable_post_lock_window( 150 );

		$this->assertSame( 150, $result );

		unset( $_GET['post'] );
	}

	/**
	 * Test disable_post_lock returns false for documents with collab.
	 */
	public function test_disable_post_lock_collaborative() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$result = $this->admin->disable_post_lock( array( 'user' => 1 ), $post->ID );

		$this->assertFalse( $result );
	}

	/**
	 * Test disable_post_lock returns original for other types.
	 */
	public function test_disable_post_lock_other_type() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$lock = array( 'user' => 1 );

		$result = $this->admin->disable_post_lock( $lock, $post->ID );

		$this->assertSame( $lock, $result );
	}

	/**
	 * Test remove_post_lock_for_collaborative does nothing on edit.php.
	 */
	public function test_remove_post_lock_for_collaborative_wrong_page() {
		global $pagenow;
		$pagenow = 'edit.php';

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, '_edit_lock', time() . ':1' );

		$this->admin->remove_post_lock_for_collaborative();

		// Lock should still exist.
		$this->assertNotEmpty( get_post_meta( $post->ID, '_edit_lock', true ) );
	}

	/**
	 * Test remove_post_lock_for_collaborative removes lock for documents.
	 */
	public function test_remove_post_lock_for_collaborative() {
		global $pagenow;
		$pagenow = 'post.php';

		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, '_edit_lock', time() . ':1' );
		$_GET['post'] = $post->ID;

		$this->admin->remove_post_lock_for_collaborative();

		$this->assertEmpty( get_post_meta( $post->ID, '_edit_lock', true ) );

		unset( $_GET['post'] );
	}

	/**
	 * Test enqueue_revisions_assets does not enqueue on wrong hook.
	 */
	public function test_enqueue_revisions_assets_wrong_hook() {
		wp_dequeue_style( 'documentate-revisions' );
		wp_dequeue_script( 'documentate-revisions' );

		$this->admin->enqueue_revisions_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'documentate-revisions', 'enqueued' ) );
	}

	/**
	 * Test enqueue_revisions_assets enqueues on revision.php.
	 */
	public function test_enqueue_revisions_assets_revision_page() {
		$post            = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$revision_id     = wp_save_post_revision( $post->ID );
		$_GET['revision'] = $revision_id;

		$this->admin->enqueue_revisions_assets( 'revision.php' );

		$this->assertTrue( wp_style_is( 'documentate-revisions', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-revisions', 'enqueued' ) );

		unset( $_GET['revision'] );
	}

	/**
	 * Test enqueue_revisions_assets enqueues on post.php for documents.
	 */
	public function test_enqueue_revisions_assets_post_page() {
		$screen            = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->admin->enqueue_revisions_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-revisions', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-revisions', 'enqueued' ) );
	}

	/**
	 * Test get_revision_field_labels via reflection.
	 */
	public function test_get_revision_field_labels() {
		$reflection = new ReflectionClass( $this->admin );
		$method     = $reflection->getMethod( 'get_revision_field_labels' );
		$method->setAccessible( true );

		$labels = $method->invoke( $this->admin );

		$this->assertIsArray( $labels );
		$this->assertArrayHasKey( 'post_title', $labels );
		$this->assertArrayHasKey( 'antecedentes', $labels );
		$this->assertArrayHasKey( 'resuelve', $labels );
	}

	/**
	 * Test get_schema_labels_for_post via reflection.
	 */
	public function test_get_schema_labels_for_post_no_type() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->admin );
		$method     = $reflection->getMethod( 'get_schema_labels_for_post' );
		$method->setAccessible( true );

		$labels = $method->invoke( $this->admin, $post->ID );

		$this->assertIsArray( $labels );
		$this->assertEmpty( $labels );
	}

	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
		wp_set_current_user( 0 );
	}
}
