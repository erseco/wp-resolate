<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Starter_Plugin
 */

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = WPIntegration\get_path_to_wp_test_dir();
if ( false === $_tests_dir ) {
        echo PHP_EOL . 'ERROR: The WordPress native unit test bootstrap file could not be found. '
                . 'Please set either the WP_TESTS_DIR or the WP_DEVELOP_DIR environment variable, '
                . 'either in your OS or in a custom phpunit.xml file.' . PHP_EOL;
        exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . 'includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/resolate.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
WPIntegration\bootstrap_it();


// Include the custom factory classes.
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-resolate-board.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-resolate-label.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-resolate-task.php';
// Removed event factory as Kanban/Events are deprecated.

// Include the custom base test class.
require_once __DIR__ . '/includes/class-wp-unittest-resolate-test-base.php';

// tests_add_filter( 'after_setup_theme', function() {

// // Register the custom factories with the global WordPress factory.
// $wp_factory = WP_UnitTestCase::factory();


// $wp_factory->board = new WP_UnitTest_Factory_For_Resolate_Board( $wp_factory );
// $wp_factory->label = new WP_UnitTest_Factory_For_Resolate_Label( $wp_factory );
// $wp_factory->task = new WP_UnitTest_Factory_For_Resolate_Task( $wp_factory );

// if ( isset( $wp_factory ) && $wp_factory instanceof WP_UnitTest_Factory ) {
// $wp_factory->register( 'board', 'WP_UnitTest_Factory_For_Resolate_Board' );
// $wp_factory->register( 'label', 'WP_UnitTest_Factory_For_Resolate_Label' );
// $wp_factory->register( 'task', 'WP_UnitTest_Factory_For_Resolate_Task' );
// } else {
// error_log( 'WP_UnitTest_Factory global is not available. Factories not registered.' );
// exit(1);
// }
// });
