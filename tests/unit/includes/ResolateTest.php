<?php
/**
 * Class Test_Resolate
 *
 * @package Resolate
 */

/**
 * Main plugin test case.
 */
class ResolateTest extends Resolate_Test_Base {
        protected $resolate;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

           // Force the initialization of taxonomies and roles
		do_action( 'init' );

               // Create an administrator user for the tests
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

               // Instantiate the plugin
                $this->resolate = new Resolate();
	}

	public function test_plugin_initialization() {
                $this->assertInstanceOf( Resolate::class, $this->resolate );
                $this->assertEquals( 'resolate', $this->resolate->get_plugin_name() );
                $this->assertEquals( RESOLATE_VERSION, $this->resolate->get_version() );
	}

	public function test_plugin_dependencies() {
           // Verify that the loader exists and is properly instantiated
                $loader = $this->get_private_property( $this->resolate, 'loader' );
		$this->assertInstanceOf( 'Resolate_Loader', $loader );

           // Verify that the required properties are set
                $this->assertNotEmpty( $this->get_private_property( $this->resolate, 'plugin_name' ) );
                $this->assertNotEmpty( $this->get_private_property( $this->resolate, 'version' ) );
	}

	/**
	 * Helper method to access private properties
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * Test current_user_has_at_least_minimum_role.
	 */
	public function test_current_user_has_at_least_minimum_role() {
               // Configure Resolate settings
                update_option( 'resolate_settings', array( 'minimum_user_profile' => 'editor' ) );

           // Create users with standard roles
		$admin      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$editor     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

               // Test with an administrator
		wp_set_current_user( $admin );
		$this->assertTrue( Resolate::current_user_has_at_least_minimum_role(), 'Administrator should have editor access.' );

               // Test with an editor
		wp_set_current_user( $editor );
		$this->assertTrue( Resolate::current_user_has_at_least_minimum_role(), 'Editor should have editor access.' );

               // Test with a subscriber
		wp_set_current_user( $subscriber );
		$this->assertFalse( Resolate::current_user_has_at_least_minimum_role(), 'Subscriber should not have editor access.' );

               // Restore the current user
		wp_set_current_user( 0 );
	}

	public function tear_down() {
               // Clean up data
		wp_delete_user( $this->admin_user_id );
                delete_option( 'resolate_settings' );
		parent::tear_down();
	}
}
