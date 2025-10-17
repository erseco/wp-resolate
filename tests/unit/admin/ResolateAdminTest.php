<?php
/**
 * Class Test_Resolate_Admin
 *
 * @package Resolate
 */

require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';


class ResolateAdminTest extends WP_UnitTestCase {
	protected $admin;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

		// Mock admin context
		set_current_screen( 'edit-post' );

		// Create admin user and log in
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->admin = new Resolate_Admin( 'resolate', '1.0.0' );
	}

	public function test_constructor() {
		$this->assertInstanceOf( Resolate_Admin::class, $this->admin );
		$this->assertEquals( 10, has_filter( 'plugin_action_links_' . plugin_basename( RESOLATE_PLUGIN_FILE ), array( $this->admin, 'add_settings_link' ) ) );
	}

	public function test_add_settings_link() {
		$links     = array();
		$new_links = $this->admin->add_settings_link( $links );

		$this->assertIsArray( $new_links );
		$this->assertCount( 1, $new_links );
		$this->assertStringContainsString( 'options-general.php?page=resolate_settings', $new_links[0] );
		$this->assertStringContainsString( 'Settings', $new_links[0] );
	}

	public function test_enqueue_styles() {
                // Clear any previously enqueued style
		wp_dequeue_style( 'resolate' );

                // Test with non-matching hook
		$this->admin->enqueue_styles( 'wrong_hook' );
		$this->assertFalse( wp_style_is( 'resolate', 'enqueued' ) );

                // Test with matching hook
		$this->admin->enqueue_styles( 'settings_page_resolate_settings' );
		$this->assertTrue( wp_style_is( 'resolate', 'enqueued' ) );
	}

	public function test_enqueue_scripts() {
                // Clear any previously enqueued script
		wp_dequeue_script( 'resolate' );

                // Test with non-matching hook
		$this->admin->enqueue_scripts( 'wrong_hook' );
		$this->assertFalse( wp_script_is( 'resolate', 'enqueued' ) );

                // Test with matching hook
		$this->admin->enqueue_scripts( 'settings_page_resolate_settings' );
		$this->assertTrue( wp_script_is( 'resolate', 'enqueued' ) );
	}


	public function test_load_dependencies() {
		$reflection = new ReflectionClass( $this->admin );
		$method     = $reflection->getMethod( 'load_dependencies' );
		$method->setAccessible( true );

		// Call the method again to test multiple loads
		$method->invoke( $this->admin );

		$this->assertTrue( class_exists( 'Resolate_Admin_Settings' ) );
	}

	public function tear_down() {
		parent::tear_down();
		wp_set_current_user( 0 );
	}
}
