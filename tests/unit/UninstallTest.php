<?php
/**
 * Tests for uninstall.php.
 *
 * @package Documentate
 */

/**
 * @coversDefaultClass uninstall
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Test uninstall file exists.
	 */
	public function test_uninstall_file_exists() {
		$file = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$this->assertFileExists( $file );
	}

	/**
	 * Test uninstall file has security check.
	 */
	public function test_uninstall_has_security_check() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $content );
		$this->assertStringContainsString( 'exit', $content );
	}

	/**
	 * Test uninstall exits without WP_UNINSTALL_PLUGIN constant.
	 *
	 * Note: We cannot directly test the exit behavior, but we verify
	 * the constant check is present in the file.
	 */
	public function test_uninstall_checks_constant() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$content = file_get_contents( $file );

		// Verify the guard clause pattern (WordPress Coding Standards spacing).
		$this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )", $content );
	}

	/**
	 * Restore the capabilities uninstall.php removes: roles live in memory
	 * across tests, so the rollback alone does not bring them back.
	 */
	public function tear_down(): void {
		Documentate_Roles::ensure_caps( true );
		parent::tear_down();
	}

	/**
	 * Test uninstall when WP_UNINSTALL_PLUGIN is defined: it removes the
	 * gestión capability from the roles and forgets the version option.
	 */
	public function test_uninstall_with_constant_defined() {
		// Define the constant if not already defined.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		Documentate_Roles::ensure_caps( true );
		$this->assertTrue( get_role( Documentate_Roles::ROLE_MANAGEMENT )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );

		$file = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';

		// Include the file - should not exit when constant is defined.
		ob_start();
		include $file;
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( get_role( Documentate_Roles::ROLE_MANAGEMENT ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( get_option( Documentate_Roles::OPTION_VERSION ) );
	}
}
