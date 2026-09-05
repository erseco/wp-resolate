<?php
/**
 *
 * Documentate – Document Generator.
 *
 * @link              https://github.com/ateeducacion/wp-documentate
 * @package           Documentate
 *
 * @wordpress-plugin
 * Plugin Name:       Documentate – Document Generator
 * Plugin URI:        https://github.com/ateeducacion/wp-documentate
 * Description:       Digital document generator. Defines a custom post type for structured documents with customizable sections and allows exporting to Word (DOCX) and PDF.
 * Version:           0.0.0
 * Requires at least: 6.1
 * Requires PHP:      8.3
 * Author:            Área de Tecnología Educativa
 * Author URI:        https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       documentate
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

define( 'DOCUMENTATE_VERSION', '0.0.0' );
define( 'DOCUMENTATE_PLUGIN_FILE', __FILE__ );
define( 'DOCUMENTATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
	define( 'DOCUMENTATE_COLLABORA_DEFAULT_URL', 'https://demo.us.collaboraonline.com' );
}

// Base URL (trailing slash) for the large LibreOffice WASM binaries
// (soffice.wasm ~140 MB, soffice.data ~95 MB). These are served from a CDN with
// CORS so they do not need to be committed or shipped in the plugin ZIP. Override
// with this constant or the `documentate_libreoffice_wasm_binary_base_url` filter.
if ( ! defined( 'DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL' ) ) {
	define( 'DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL', 'https://erseco.github.io/libreoffice-document-converter/wasm/' );
}

$documentate_vendor_autoload = plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
if ( is_readable( $documentate_vendor_autoload ) ) {
	require_once $documentate_vendor_autoload;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/autofirma/class-documentate-autofirma-bundled-autoloader.php';
Documentate_AutoFirma_Bundled_Autoloader::register();

require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaextractor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemastorage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaconverter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-template-parser.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-autofirma.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/autofirma/class-documentate-autofirma-intermediate-controller.php';

if ( interface_exists( 'Erseco\AutoFirma\IntermediateServer\Storage\StoreInterface' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/autofirma/class-documentate-autofirma-transient-store.php';
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-demo-data.php';

/**
 * The code that runs during plugin activation.
 */
function documentate_activate_plugin() {
	// Set the permalink structure if necessary.
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	flush_rewrite_rules();

	update_option( 'documentate_flush_rewrites', true );
	update_option( 'documentate_version', DOCUMENTATE_VERSION );

	// Only request demo seeding in non-production environments (and Playground).
	// This prevents demo login accounts from ever being created on production.
	if ( Documentate_Demo_Data::should_allow_demo_seeding() ) {
		update_option( 'documentate_seed_demo_documents', true );
	}

	// Ensure default fixtures (templates) are available in Media Library and settings.
	Documentate_Demo_Data::ensure_default_media();
}

/**
 * The code that runs during plugin deactivation.
 */
function documentate_deactivate_plugin() {
	flush_rewrite_rules();
}

/**
 * Plugin Update Handler
 *
 * @param WP_Upgrader $upgrader_object Upgrader object.
 * @param array       $options         Upgrade options.
 */
function documentate_update_handler( $upgrader_object, $options ) {
	// Check if the update is for your specific plugin.
	if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		$plugins_updated = $options['plugins'];

		// Replace with your plugin's base name (typically folder/main-plugin-file.php).
		$plugin_file = plugin_basename( __FILE__ );

		// Check if your plugin is in the list of updated plugins.
		if ( in_array( $plugin_file, $plugins_updated ) ) {
			// Perform update-specific tasks.
			flush_rewrite_rules();
		}
	}
}

register_activation_hook( __FILE__, 'documentate_activate_plugin' );
register_deactivation_hook( __FILE__, 'documentate_deactivate_plugin' );
add_action( 'upgrader_process_complete', 'documentate_update_handler', 10, 2 );

/**
 * Maybe flush rewrite rules on init if needed.
 */
function documentate_maybe_flush_rewrite_rules() {
	$saved_version = get_option( 'documentate_version' );

	// If plugin version changed, or a flag has been set (e.g. on activation), flush rules.
	if ( DOCUMENTATE_VERSION !== $saved_version || get_option( 'documentate_flush_rewrites' ) ) {
		flush_rewrite_rules();
		update_option( 'documentate_version', DOCUMENTATE_VERSION );
		delete_option( 'documentate_flush_rewrites' );
	}
}

add_action( 'init', 'documentate_maybe_flush_rewrite_rules', 999 );

// Initialize demo data system.
Documentate_Demo_Data::init( __FILE__ );

// Initialize AutoFirma signature positioning and intermediate-server routes.
Documentate_AutoFirma::init();
$documentate_autofirma_intermediate = new Documentate_AutoFirma_Intermediate_Controller();
$documentate_autofirma_intermediate->register();

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 */
function documentate_run_plugin() {
	$plugin = new Documentate();
	$plugin->run();
}

documentate_run_plugin();
