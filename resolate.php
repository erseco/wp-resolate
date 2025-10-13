<?php
/**
 *
 * Resolate – Generador de resoluciones.
 *
 * @link              https://github.com/erseco/wp-resolate
 * @package           Resolate
 *
 * @wordpress-plugin
 * Plugin Name:       Resolate – Generador de resoluciones
 * Plugin URI:        https://github.com/erseco/wp-resolate
 * Description:       Generador de resoluciones digitales de la Consejería de Educación del Gobierno de Canarias. Define un tipo de contenido de "Resolución" con secciones estructuradas y permite exportar a Word (DOCX) y, próximamente, PDF.
 * Version:           0.0.0
 * Author:            Área de Tecnología Educativa
 * Author URI:        https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       resolate
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'RESOLATE_VERSION', '0.0.0' );
define( 'RESOLATE_PLUGIN_FILE', __FILE__ );

/**
 * The code that runs during plugin activation.
 */
function activate_resolate() {
    // Set the permalink structure if necessary.
    if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
        update_option( 'permalink_structure', '/%postname%/' );
    }

    flush_rewrite_rules();

    update_option( 'resolate_flush_rewrites', true );
    update_option( 'resolate_version', RESOLATE_VERSION );

    // Ensure default fixtures (templates/logos) are available in Media Library and settings.
    resolate_ensure_default_media();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_resolate() {
    flush_rewrite_rules();
}

/**
 * Plugin Update Handler
 *
 * @param WP_Upgrader $upgrader_object Upgrader object.
 * @param array       $options         Upgrade options.
 */
function resolate_update_handler( $upgrader_object, $options ) {
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

register_activation_hook( __FILE__, 'activate_resolate' );
register_deactivation_hook( __FILE__, 'deactivate_resolate' );
add_action( 'upgrader_process_complete', 'resolate_update_handler', 10, 2 );


/**
 * Maybe flush rewrite rules on init if needed.
 */
function resolate_maybe_flush_rewrite_rules() {
    $saved_version = get_option( 'resolate_version' );

    // If plugin version changed, or a flag has been set (e.g. on activation), flush rules.
    if ( RESOLATE_VERSION !== $saved_version || get_option( 'resolate_flush_rewrites' ) ) {
        flush_rewrite_rules();
        update_option( 'resolate_version', RESOLATE_VERSION );
        delete_option( 'resolate_flush_rewrites' );
    }
}
add_action( 'init', 'resolate_maybe_flush_rewrite_rules', 999 );

/**
 * On first runs where settings are empty (e.g., after manual file deploy),
 * attempt to import default fixtures into Media Library.
 */
function resolate_maybe_import_fixtures_on_init() {
    $options = get_option( 'resolate_settings', array() );
    $need_templates = empty( $options['odt_template_id'] ) || empty( $options['docx_template_id'] );
    $need_logos = empty( $options['doc_logo_id'] ) || empty( $options['doc_logo_right_id'] );
    if ( $need_templates || $need_logos ) {
        resolate_ensure_default_media();
    }
}
add_action( 'init', 'resolate_maybe_import_fixtures_on_init', 20 );

/**
 * Import a fixture file to the Media Library if not already imported.
 *
 * Looks for the file under plugin fixtures directory and root as fallback.
 * Uses file hash to avoid duplicate imports and tags attachment as plugin fixture.
 *
 * @param string $filename Filename inside fixtures/ (e.g., 'plantilla.odt').
 * @return int Attachment ID or 0 on failure/missing file.
 */
function resolate_import_fixture_file( $filename ) {
    $base_dir = plugin_dir_path( __FILE__ );
    $paths = array(
        $base_dir . 'fixtures/' . $filename,
        $base_dir . $filename,
    );
    $source = '';
    foreach ( $paths as $p ) {
        if ( file_exists( $p ) && is_readable( $p ) ) { $source = $p; break; }
    }
    if ( '' === $source ) {
        return 0;
    }

    $hash = @md5_file( $source );
    if ( $hash ) {
        $found = get_posts( array(
            'post_type'   => 'attachment',
            'post_status' => 'inherit',
            'meta_key'    => '_resolate_fixture_hash',
            'meta_value'  => $hash,
            'fields'      => 'ids',
            'numberposts' => 1,
        ) );
        if ( ! empty( $found ) ) {
            return intval( $found[0] );
        }
    }

    $contents = @file_get_contents( $source );
    if ( false === $contents ) {
        return 0;
    }

    $upload = wp_upload_bits( basename( $source ), null, $contents );
    if ( ! empty( $upload['error'] ) ) {
        return 0;
    }

    $filetype = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ) );
    $attachment = array(
        'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
        'post_title'     => sanitize_file_name( basename( $source ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
    if ( ! $attach_id ) {
        return 0;
    }

    // Generate and save attachment metadata (for images).
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
    if ( ! empty( $attach_data ) ) {
        wp_update_attachment_metadata( $attach_id, $attach_data );
    }

    // Tag as fixture to allow reuse.
    if ( $hash ) {
        update_post_meta( $attach_id, '_resolate_fixture_hash', $hash );
    }
    update_post_meta( $attach_id, '_resolate_fixture_name', basename( $source ) );

    return intval( $attach_id );
}

/**
 * Ensure default templates and logos are set in settings by importing fixtures when empty.
 *
 * @return void
 */
function resolate_ensure_default_media() {
    $options = get_option( 'resolate_settings', array() );

    // ODT template.
    $odt_id = isset( $options['odt_template_id'] ) ? intval( $options['odt_template_id'] ) : 0;
    if ( $odt_id <= 0 || ! get_post( $odt_id ) ) {
        $imported = resolate_import_fixture_file( 'plantilla.odt' );
        if ( $imported > 0 ) {
            $options['odt_template_id'] = $imported;
        }
    }

    // DOCX template.
    $docx_id = isset( $options['docx_template_id'] ) ? intval( $options['docx_template_id'] ) : 0;
    if ( $docx_id <= 0 || ! get_post( $docx_id ) ) {
        $imported = resolate_import_fixture_file( 'plantilla.docx' );
        if ( $imported > 0 ) {
            $options['docx_template_id'] = $imported;
        }
    }

    // Logos.
    $logo_left_id = isset( $options['doc_logo_id'] ) ? intval( $options['doc_logo_id'] ) : 0;
    $logo_right_id = isset( $options['doc_logo_right_id'] ) ? intval( $options['doc_logo_right_id'] ) : 0;

    if ( ( $logo_left_id <= 0 || ! get_post( $logo_left_id ) ) || ( $logo_right_id <= 0 || ! get_post( $logo_right_id ) ) ) {
        $logo1 = resolate_import_fixture_file( 'logo1.png' );
        $logo2 = resolate_import_fixture_file( 'logo2.jpg' );

        if ( $logo_left_id <= 0 || ! get_post( $logo_left_id ) ) {
            // Prefer PNG as left logo if available.
            $options['doc_logo_id'] = $logo1 > 0 ? $logo1 : ( $logo2 > 0 ? $logo2 : 0 );
        }
        if ( $logo_right_id <= 0 || ! get_post( $logo_right_id ) ) {
            // Prefer JPG as right logo if available to vary from left.
            $options['doc_logo_right_id'] = $logo2 > 0 ? $logo2 : ( $logo1 > 0 ? $logo1 : 0 );
        }
    }

    update_option( 'resolate_settings', $options );
}


/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-resolate.php';


if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-resolate-wpcli.php';
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 */
function run_resolate() {

    $plugin = new Resolate();
    $plugin->run();
}
run_resolate();
