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
	require dirname( __DIR__ ) . '/documentate.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
WPIntegration\bootstrap_it();


// Include the custom factory classes.
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-documentate-doc-type.php';
require_once __DIR__ . '/includes/class-wp-unittest-factory-for-documentate-document.php';

// Include the custom base test class.
require_once __DIR__ . '/includes/class-wp-unittest-documentate-test-base.php';

// Include document generation test helpers.
require_once __DIR__ . '/includes/class-document-xml-asserter.php';
require_once __DIR__ . '/includes/class-documentate-generation-test-base.php';

// Include the helper that reads text back out of generated PDFs.
require_once __DIR__ . '/includes/class-documentate-pdf-test-helper.php';

// Include the exception used to intercept "redirect then exit()" endings.
require_once __DIR__ . '/includes/class-documentate-exit-exception.php';

tests_add_filter( 'after_setup_theme', function() {

        // Register the custom factories with the global WordPress factory.
        $wp_factory = WP_UnitTestCase::factory();


        $wp_factory->doctype = new WP_UnitTest_Factory_For_Documentate_Doc_Type( $wp_factory );
        $wp_factory->document = new WP_UnitTest_Factory_For_Documentate_Document( $wp_factory );

        if ( isset( $wp_factory ) && $wp_factory instanceof WP_UnitTest_Factory ) {
                $wp_factory->register( 'doctype', 'WP_UnitTest_Factory_For_Documentate_Doc_Type' );
                $wp_factory->register( 'document', 'WP_UnitTest_Factory_For_Documentate_Document' );
        } else {
                error_log( 'WP_UnitTest_Factory global is not available. Factories not registered.' );
                exit(1);
        }
});
