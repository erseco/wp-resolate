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
	update_option( 'resolate_seed_demo_documents', true );

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
 * Maybe seed demo documents after activation.
 *
 * @return void
 */
function resolate_maybe_seed_demo_documents() {
	if ( ! post_type_exists( 'resolate_document' ) || ! taxonomy_exists( 'resolate_doc_type' ) ) {
		return;
	}

	$should_seed = (bool) get_option( 'resolate_seed_demo_documents', false );
	if ( ! $should_seed ) {
		return;
	}

	resolate_maybe_seed_default_doc_types();

	$terms = get_terms(
		array(
			'taxonomy'   => 'resolate_doc_type',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		delete_option( 'resolate_seed_demo_documents' );
		return;
	}

	foreach ( $terms as $term ) {
		if ( resolate_demo_document_exists( $term->term_id ) ) {
			continue;
		}

		resolate_create_demo_document_for_type( $term );
	}

	delete_option( 'resolate_seed_demo_documents' );
}

/**
 * Check whether a demo document already exists for the given document type.
 *
 * @param int $term_id Term ID.
 * @return bool
 */
function resolate_demo_document_exists( $term_id ) {
	$term_id = absint( $term_id );
	if ( $term_id <= 0 ) {
		return true;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'resolate_document',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_resolate_demo_type_id',
			'meta_value'     => (string) $term_id,
		)
	);

	return ! empty( $existing );
}

/**
 * Create a demo document for a specific document type.
 *
 * @param WP_Term $term Document type term.
 * @return bool
 */
function resolate_create_demo_document_for_type( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$term_id = absint( $term->term_id );
	if ( $term_id <= 0 ) {
		return false;
	}

	$schema = Resolate_Documents::get_term_schema( $term_id );
	if ( empty( $schema ) || ! is_array( $schema ) ) {
		return false;
	}

	/* translators: %s: document type name. */
	$title = sprintf( __( 'Documento de prueba – %s', 'resolate' ), $term->name );
	$author = __( 'Equipo de demostración', 'resolate' );
	$keywords = __( 'lorem, ipsum, demostración', 'resolate' );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'resolate_document',
			'post_title'   => $title,
			'post_status'  => 'private',
			'post_content' => '',
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) || 0 === $post_id ) {
		return false;
	}

	wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

	$structured_fields = array();
	foreach ( $schema as $definition ) {
		if ( empty( $definition['slug'] ) ) {
			continue;
		}

		$slug      = sanitize_key( $definition['slug'] );
		$type      = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
		$data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';

		if ( '' === $slug ) {
			continue;
		}

		if ( 'array' === $type ) {
			$item_schema = isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] ) ? $definition['item_schema'] : array();
			$items       = resolate_generate_demo_array_items(
				$slug,
				$item_schema,
				array(
					'document_title' => $title,
				)
			);

			if ( empty( $items ) ) {
				continue;
			}

			$encoded = wp_json_encode( $items, JSON_UNESCAPED_UNICODE );
			update_post_meta( $post_id, 'resolate_field_' . $slug, $encoded );

			$structured_fields[ $slug ] = array(
				'type'  => 'array',
				'value' => $encoded,
			);
			continue;
		}

		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		$value = resolate_generate_demo_scalar_value(
			$slug,
			$type,
			$data_type,
			1,
			array(
				'document_title' => $title,
			)
		);

		if ( 'rich' === $type ) {
			$value = wp_kses_post( $value );
		} elseif ( 'single' === $type ) {
			$value = sanitize_text_field( $value );
		} else {
			$value = sanitize_textarea_field( $value );
		}

		update_post_meta( $post_id, 'resolate_field_' . $slug, $value );

		$structured_fields[ $slug ] = array(
			'type'  => $type,
			'value' => $value,
		);
	}

	update_post_meta( $post_id, '_resolate_demo_type_id', (string) $term_id );
	update_post_meta( $post_id, \Resolate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT, sanitize_text_field( $title ) );
	update_post_meta( $post_id, \Resolate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR, sanitize_text_field( $author ) );
	update_post_meta( $post_id, \Resolate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS, sanitize_text_field( $keywords ) );

	$content = resolate_build_structured_demo_content( $structured_fields );
	if ( '' !== $content ) {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
	}

	return true;
}

/**
 * Generate demo values for array fields.
 *
 * @param string $slug        Repeater slug.
 * @param array  $item_schema Item schema definition.
 * @param array  $context     Additional context.
 * @return array<int, array<string, string>>
 */
function resolate_generate_demo_array_items( $slug, $item_schema, $context = array() ) {
	$slug        = sanitize_key( $slug );
	$item_schema = is_array( $item_schema ) ? $item_schema : array();

	if ( empty( $item_schema ) ) {
		$value = resolate_generate_demo_scalar_value(
			'contenido',
			'textarea',
			'text',
			1,
			$context
		);

		return array(
			array(
				'contenido' => sanitize_textarea_field( $value ),
			),
		);
	}

	$items = array();

	for ( $index = 1; $index <= 2; $index++ ) {
		$item = array();

		foreach ( $item_schema as $item_slug => $definition ) {
			$item_slug = sanitize_key( $item_slug );
			if ( '' === $item_slug ) {
				continue;
			}

			$type      = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
			$data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$value = resolate_generate_demo_scalar_value(
				$item_slug,
				$type,
				$data_type,
				$index,
				array_merge(
					$context,
					array(
						'index'       => $index,
						'parent_slug' => $slug,
					)
				)
			);

			if ( 'rich' === $type ) {
				$value = wp_kses_post( $value );
			} elseif ( 'single' === $type ) {
				$value = sanitize_text_field( $value );
			} else {
				$value = sanitize_textarea_field( $value );
			}

			$item[ $item_slug ] = $value;
		}

		if ( ! empty( $item ) ) {
			$items[] = $item;
		}
	}

	return $items;
}

/**
 * Generate a demo scalar value given a schema definition.
 *
 * @param string $slug      Field slug.
 * @param string $type      Field type.
 * @param string $data_type Field data type.
 * @param int    $index     Optional index for repeaters.
 * @param array  $context   Additional context.
 * @return string
 */
function resolate_generate_demo_scalar_value( $slug, $type, $data_type, $index = 1, $context = array() ) {
	$slug      = strtolower( (string) $slug );
	$type      = sanitize_key( $type );
	$data_type = sanitize_key( $data_type );
	$index     = max( 1, absint( $index ) );

	$document_title = isset( $context['document_title'] ) ? (string) $context['document_title'] : __( 'Resolución demostrativa', 'resolate' );
	$number_value   = (string) ( 1 + $index );

	if ( 'date' === $data_type ) {
		$month = max( 1, min( 12, $index ) );
		$day   = max( 1, min( 28, 10 + $index ) );
		return sprintf( '2025-%02d-%02d', $month, $day );
	}

	if ( 'number' === $data_type ) {
		return $number_value;
	}

	if ( 'boolean' === $data_type ) {
		return ( $index % 2 ) ? '1' : '0';
	}

	if ( false !== strpos( $slug, 'email' ) ) {
		return 'demo' . $index . '@ejemplo.es';
	}

	if ( false !== strpos( $slug, 'phone' ) || false !== strpos( $slug, 'tel' ) ) {
		return '+3460000000' . $index;
	}

	if ( false !== strpos( $slug, 'dni' ) ) {
		return '1234567' . $index . 'A';
	}

	if ( false !== strpos( $slug, 'url' ) || false !== strpos( $slug, 'sitio' ) || false !== strpos( $slug, 'web' ) ) {
		return 'https://ejemplo.es/recurso-' . $index;
	}

	if ( false !== strpos( $slug, 'nombre' ) || false !== strpos( $slug, 'name' ) ) {
		return ( 1 === $index ) ? 'María García Pérez' : 'Juan Carlos López';
	}

	if ( false !== strpos( $slug, 'title' ) || false !== strpos( $slug, 'titulo' ) || 'post_title' === $slug ) {
		if ( 'post_title' === $slug ) {
			return $document_title;
		}

		/* translators: %d: item sequence number. */
		return sprintf( __( 'Elemento demostrativo %d', 'resolate' ), $index );
	}

	if ( false !== strpos( $slug, 'summary' ) || false !== strpos( $slug, 'resumen' ) ) {
		/* translators: %d: item sequence number. */
		return sprintf( __( 'Resumen demostrativo %d con información breve.', 'resolate' ), $index );
	}

	if ( false !== strpos( $slug, 'objeto' ) ) {
		return __( 'Objeto de la resolución de ejemplo para ilustrar el flujo.', 'resolate' );
	}

	if ( false !== strpos( $slug, 'antecedentes' ) ) {
		return __( 'Antecedentes de hecho redactados con contenido de prueba.', 'resolate' );
	}

	if ( false !== strpos( $slug, 'fundamentos' ) ) {
		return __( 'Fundamentos jurídicos de prueba con referencias genéricas.', 'resolate' );
	}

	if ( false !== strpos( $slug, 'resuelv' ) ) {
		return '<p>' . __( 'Primero. Aprobar la actuación demostrativa.', 'resolate' ) . '</p><p>' . __( 'Segundo. Notificar a las personas interesadas.', 'resolate' ) . '</p>';
	}

	if ( false !== strpos( $slug, 'observaciones' ) ) {
		return __( 'Observaciones adicionales de ejemplo para completar la plantilla.', 'resolate' );
	}

	if ( false !== strpos( $slug, 'body' ) || false !== strpos( $slug, 'cuerpo' ) ) {
		$rich  = '<h3>' . __( 'Encabezado de prueba', 'resolate' ) . '</h3>';
		$rich .= '<p>' . __( 'Primer párrafo con texto de ejemplo.', 'resolate' ) . '</p>';
		/* translators: 1: bold text label, 2: italic text label, 3: underline text label. */
		$rich .= '<p>' . sprintf( __( 'Segundo párrafo con %1$s, %2$s y %3$s.', 'resolate' ), '<strong>' . __( 'negritas', 'resolate' ) . '</strong>', '<em>' . __( 'cursivas', 'resolate' ) . '</em>', '<u>' . __( 'subrayado', 'resolate' ) . '</u>' ) . '</p>';
		$rich .= '<ul><li>' . __( 'Elemento uno', 'resolate' ) . '</li><li>' . __( 'Elemento dos', 'resolate' ) . '</li></ul>';
		$rich .= '<table><tr><th>' . __( 'Col 1', 'resolate' ) . '</th><th>' . __( 'Col 2', 'resolate' ) . '</th></tr><tr><td>' . __( 'Dato A1', 'resolate' ) . '</td><td>' . __( 'Dato A2', 'resolate' ) . '</td></tr><tr><td>' . __( 'Dato B1', 'resolate' ) . '</td><td>' . __( 'Dato B2', 'resolate' ) . '</td></tr></table>';
		return $rich;
	}

	// Generic HTML content fields: enrich demo data with formatted HTML.
	if (
		false !== strpos( $slug, 'content' ) ||
		false !== strpos( $slug, 'contenido' ) ||
		false !== strpos( $slug, 'html' )
	) {
		$rich  = '<h3>' . __( 'Encabezado de prueba', 'resolate' ) . '</h3>';
		$rich .= '<p>' . __( 'Primer párrafo con texto de ejemplo.', 'resolate' ) . '</p>';
		/* translators: 1: bold text label, 2: italic text label, 3: underline text label. */
		$rich .= '<p>' . sprintf( __( 'Segundo párrafo con %1$s, %2$s y %3$s.', 'resolate' ), '<strong>' . __( 'negritas', 'resolate' ) . '</strong>', '<em>' . __( 'cursivas', 'resolate' ) . '</em>', '<u>' . __( 'subrayado', 'resolate' ) . '</u>' ) . '</p>';
		$rich .= '<ul><li>' . __( 'Elemento uno', 'resolate' ) . '</li><li>' . __( 'Elemento dos', 'resolate' ) . '</li></ul>';
		$rich .= '<table><tr><th>' . __( 'Col 1', 'resolate' ) . '</th><th>' . __( 'Col 2', 'resolate' ) . '</th></tr><tr><td>' . __( 'Dato A1', 'resolate' ) . '</td><td>' . __( 'Dato A2', 'resolate' ) . '</td></tr><tr><td>' . __( 'Dato B1', 'resolate' ) . '</td><td>' . __( 'Dato B2', 'resolate' ) . '</td></tr></table>';
		return $rich;
	}

	if ( false !== strpos( $slug, 'keywords' ) || false !== strpos( $slug, 'palabras' ) ) {
		return __( 'palabras, clave, demostración', 'resolate' );
	}

	return __( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'resolate' );
}

/**
 * Compose structured content fragments for seeded demo documents.
 *
 * @param array<string, array{type:string,value:string}> $fields Structured fields.
 * @return string
 */
function resolate_build_structured_demo_content( $fields ) {
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return '';
	}

	$fragments = array();

	foreach ( $fields as $slug => $info ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			continue;
		}

		$type  = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : '';
		$value = isset( $info['value'] ) ? (string) $info['value'] : '';

		$attributes = 'slug="' . esc_attr( $slug ) . '"';
		if ( '' !== $type && in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
			$attributes .= ' type="' . esc_attr( $type ) . '"';
		}

		$fragments[] = '<!-- resolate-field ' . $attributes . " -->\n" . $value . "\n<!-- /resolate-field -->";
	}

	return implode( "\n\n", $fragments );
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
add_action( 'init', 'resolate_maybe_seed_demo_documents', 60 );


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
