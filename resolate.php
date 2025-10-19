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

if ( ! defined( 'RESOLATE_ZETAJS_CDN_BASE' ) ) {
	define( 'RESOLATE_ZETAJS_CDN_BASE', 'https://cdn.zetaoffice.net/zetaoffice_latest/' );
}

if ( ! defined( 'RESOLATE_COLLABORA_DEFAULT_URL' ) ) {
	define( 'RESOLATE_COLLABORA_DEFAULT_URL', 'https://demo.us.collaboraonline.com' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaextractor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemastorage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaconverter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-resolate-template-parser.php';

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

	// Ensure default fixtures (templates) are available in Media Library and settings.
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
		if ( file_exists( $p ) && is_readable( $p ) ) {
			$source = $p;
			break; }
	}
	if ( '' === $source ) {
		return 0;
	}

	$hash = @md5_file( $source );
	if ( $hash ) {
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => '_resolate_fixture_hash',
				'meta_value'  => $hash,
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
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
 * Ensure default templates are set in settings by importing fixtures when empty.
 *
 * @return void
 */
function resolate_ensure_default_media() {

	// ODT template.
	resolate_import_fixture_file( 'plantilla.odt' );
	// DOCX template.
	resolate_import_fixture_file( 'plantilla.docx' );

	// Ensure demo fixtures are present for testing scenarios.
	resolate_import_fixture_file( 'demo-wp-resolate.odt' );
	resolate_import_fixture_file( 'demo-wp-resolate.docx' );
}

/**
 * Ensure demo document types exist with bundled templates.
 *
 * @return void
 */
function resolate_maybe_seed_default_doc_types() {
	if ( ! taxonomy_exists( 'resolate_doc_type' ) ) {
		return;
	}

	resolate_ensure_default_media();

	$options = get_option( 'resolate_settings', array() );

	$definitions = array();

	$odt_id = resolate_import_fixture_file( 'plantilla.odt' );	
	if ( $odt_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'resolate-demo-odt',
			'name'        => __( 'Tipo de documento de prueba (ODT)', 'resolate' ),
			'description' => __( 'Ejemplo creado automáticamente con la plantilla ODT incluida.', 'resolate' ),
			'color'       => '#37517e',
			'template_id' => $odt_id,
			'fixture_key' => 'resolate-demo-odt',
		);
	}

	$docx_id = resolate_import_fixture_file( 'plantilla.docx' );
	if ( $docx_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'resolate-demo-docx',
			'name'        => __( 'Tipo de documento de prueba (DOCX)', 'resolate' ),
			'description' => __( 'Ejemplo creado automáticamente con la plantilla DOCX incluida.', 'resolate' ),
			'color'       => '#2a7fb8',
			'template_id' => $docx_id,
			'fixture_key' => 'resolate-demo-docx',
		);
	}

	$advanced_odt_id = resolate_import_fixture_file( 'demo-wp-resolate.odt' );
	if ( $advanced_odt_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'resolate-demo-wp-resolate-odt',
			'name'        => __( 'Tipo de documento de prueba avanzado (ODT)', 'resolate' ),
			'description' => __( 'Ejemplo creado automáticamente con la plantilla demo-wp-resolate.odt incluida.', 'resolate' ),
			'color'       => '#6c5ce7',
			'template_id' => $advanced_odt_id,
			'fixture_key' => 'resolate-demo-wp-resolate-odt',
		);
	}

	$advanced_docx_id = resolate_import_fixture_file( 'demo-wp-resolate.docx' );
	if ( $advanced_docx_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'resolate-demo-wp-resolate-docx',
			'name'        => __( 'Tipo de documento de prueba avanzado (DOCX)', 'resolate' ),
			'description' => __( 'Ejemplo creado automáticamente con la plantilla demo-wp-resolate.docx incluida.', 'resolate' ),
			'color'       => '#0f9d58',
			'template_id' => $advanced_docx_id,
			'fixture_key' => 'resolate-demo-wp-resolate-docx',
		);
	}

	if ( empty( $definitions ) ) {
		return;
	}

	foreach ( $definitions as $definition ) {
		$slug        = $definition['slug'];
		$template_id = intval( $definition['template_id'] );
		if ( $template_id <= 0 ) {
			continue;
		}

		$term    = get_term_by( 'slug', $slug, 'resolate_doc_type' );
		$term_id = $term instanceof WP_Term ? intval( $term->term_id ) : 0;

		if ( $term_id <= 0 ) {
			$created = wp_insert_term(
				$definition['name'],
				'resolate_doc_type',
				array(
					'slug'        => $slug,
					'description' => $definition['description'],
				)
			);

			if ( is_wp_error( $created ) ) {
				continue;
			}

			$term_id = intval( $created['term_id'] );
		}

		if ( $term_id <= 0 ) {
			continue;
		}

		$fixture_key = get_term_meta( $term_id, '_resolate_fixture', true );
		if ( ! empty( $fixture_key ) && $fixture_key !== $definition['fixture_key'] ) {
			continue;
		}

		update_term_meta( $term_id, '_resolate_fixture', $definition['fixture_key'] );
		update_term_meta( $term_id, 'resolate_type_color', $definition['color'] );
		update_term_meta( $term_id, 'resolate_type_template_id', $template_id );

		$path = get_attached_file( $template_id );
		if ( ! $path ) {
			continue;
		}

		$extractor = new Resolate\DocType\SchemaExtractor();
		$storage   = new Resolate\DocType\SchemaStorage();

		$existing_schema = $storage->get_schema( $term_id );
		$template_hash   = @md5_file( $path );

		if ( ! empty( $existing_schema ) && $template_hash && isset( $existing_schema['meta']['hash'] ) && $template_hash === $existing_schema['meta']['hash'] ) {
			$template_type = isset( $existing_schema['meta']['template_type'] ) ? (string) $existing_schema['meta']['template_type'] : strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			update_term_meta( $term_id, 'resolate_type_template_type', $template_type );
			continue;
		}

		$schema = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			continue;
		}

		$schema['meta']['template_id']   = $template_id;
		$schema['meta']['template_type'] = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$schema['meta']['template_name'] = basename( $path );
		if ( empty( $schema['meta']['hash'] ) && $template_hash ) {
			$schema['meta']['hash'] = $template_hash;
		}

		update_term_meta( $term_id, 'resolate_type_template_type', $schema['meta']['template_type'] );

		$storage->save_schema( $term_id, $schema );
	}
}

/**
 * Convert slug into a human readable label.
 *
 * @param string $slug Slug.
 * @return string
 */
function resolate_humanize_slug( $slug ) {
	$slug = str_replace( array( '-', '_' ), ' ', $slug );
	$slug = preg_replace( '/\s+/', ' ', $slug );
	$slug = trim( (string) $slug );

	if ( '' === $slug ) {
		return '';
	}

	if ( function_exists( 'mb_convert_case' ) ) {
		return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
	}

	return ucwords( strtolower( $slug ) );
}

add_action( 'init', 'resolate_maybe_seed_default_doc_types', 40 );


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
