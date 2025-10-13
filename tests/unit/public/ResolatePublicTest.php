<?php

/**
 * Class Test_Resolate_Public
 *
 * @package Resolate
 */

class ResolatePublicTest extends Resolate_Test_Base {

	/**
	 * Instancia de Resolate_Public.
	 *
	 * @var Resolate_Public
	 */
	protected $resolate_public;

	/**
     * Setup before each test.
	 */
	public function set_up(): void {
		parent::set_up();

               // Define the WP_TESTS_RUNNING constant.
		if ( ! defined( 'WP_TESTS_RUNNING' ) ) {
			define( 'WP_TESTS_RUNNING', true );
		}

               // Create an instance of Resolate_Public.
		$this->resolate_public = new Resolate_Public( 'resolate', '1.0.0' );
	}

	/**
     * Clean up after each test.
	 */
	public function tear_down(): void {
		delete_option( 'resolate_settings' );
		parent::tear_down();
	}

	public function test_enqueue_scripts() {
               // Simulate a query_var value.
		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'resolate_page';
				return $vars;
			}
		);

		set_query_var( 'resolate_page', 'analytics' );

		$this->resolate_public->enqueue_scripts();

                // Verify that the scripts have been enqueued.
                $this->assertTrue( wp_script_is( 'config', 'enqueued' ) );
	}
}
