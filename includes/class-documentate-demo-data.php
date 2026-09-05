<?php
/**
 * Demo data generator for the Documentate plugin.
 *
 * Handles importing fixture files, seeding document types and creating demo documents.
 *
 * @package Documentate
 * @subpackage Documentate/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die();
}

/**
 * Class for generating demo data.
 */
class Documentate_Demo_Data {
	/**
	 * Plugin base directory path.
	 *
	 * @var string
	 */
	private static $plugin_dir = '';

	/**
	 * Initialize the demo data system.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return void
	 */
	public static function init( $plugin_file ) {
		self::$plugin_dir = plugin_dir_path( $plugin_file );

		add_action( 'init', array( __CLASS__, 'maybe_seed_default_doc_types' ), 40 );
		add_action( 'init', array( __CLASS__, 'maybe_seed_demo_categories' ), 45 );
		add_action( 'init', array( __CLASS__, 'maybe_seed_demo_users' ), 50 );
		add_action( 'init', array( __CLASS__, 'maybe_seed_demo_documents' ), 60 );
	}

	/**
	 * Whether demo content may be seeded in the current environment.
	 *
	 * Demo data — most importantly the demo login accounts — must never be
	 * created on production sites. Seeding is permitted inside WordPress
	 * Playground and in any non-production environment (local, development,
	 * staging), as reported by wp_get_environment_type().
	 *
	 * @return bool True when demo seeding is permitted.
	 */
	public static function should_allow_demo_seeding() {
		if ( ! class_exists( 'Documentate_Collabora_Converter' ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'class-documentate-collabora-converter.php';
		}

		if ( Documentate_Collabora_Converter::is_playground() ) {
			return true;
		}

		return 'production' !== wp_get_environment_type();
	}

	/**
	 * Import a fixture file to the Media Library if not already imported.
	 *
	 * Looks for the file under plugin fixtures directory and root as fallback.
	 * Uses file hash to avoid duplicate imports and tags attachment as plugin fixture.
	 *
	 * @param string $filename Filename inside fixtures/ (e.g., 'resolucion.odt').
	 * @return int Attachment ID or 0 on failure/missing file.
	 */
	public static function import_fixture_file( $filename ) {
		$source = self::locate_fixture_source( $filename );
		if ( '' === $source ) {
			return 0;
		}

		$hash       = md5_file( $source );
		$existing_id = self::find_fixture_attachment_by_hash( $hash );
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		return self::create_fixture_attachment( $source, $hash ? $hash : '' );
	}

	/**
	 * Resolve the absolute path of a fixture file.
	 *
	 * @param string $filename Fixture basename.
	 * @return string Absolute path, or empty string if not found.
	 */
	private static function locate_fixture_source( $filename ) {
		$paths = array(
			self::$plugin_dir . 'fixtures/' . $filename,
			self::$plugin_dir . $filename,
		);
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Find an existing media attachment tagged with a fixture hash.
	 *
	 * @param string|false $hash MD5 hash, or false on failure.
	 * @return int Attachment ID or 0.
	 */
	private static function find_fixture_attachment_by_hash( $hash ) {
		if ( ! $hash ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => '_documentate_fixture_hash',
				'meta_value'  => $hash,
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		return ! empty( $found ) ? intval( $found[0] ) : 0;
	}

	/**
	 * Create a Media Library attachment from a fixture file path.
	 *
	 * @param string $source Absolute source path.
	 * @param string $hash   Optional fixture hash for reuse tagging.
	 * @return int Attachment ID or 0 on failure.
	 */
	private static function create_fixture_attachment( $source, $hash ) {
		$contents = file_get_contents( $source );
		if ( false === $contents ) {
			return 0;
		}

		$upload = wp_upload_bits( basename( $source ), null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$filetype   = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ) );
		$attach_id  = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
				'post_title'     => sanitize_file_name( basename( $source ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $attach_id ) {
			return 0;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( ! empty( $attach_data ) ) {
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		if ( '' !== $hash ) {
			update_post_meta( $attach_id, '_documentate_fixture_hash', $hash );
		}
		update_post_meta( $attach_id, '_documentate_fixture_name', basename( $source ) );

		return intval( $attach_id );
	}

	/**
	 * Ensure default templates are set in settings by importing fixtures when empty.
	 *
	 * @return void
	 */
	public static function ensure_default_media() {
		// ODT template for resolutions.
		self::import_fixture_file( 'resolucion.odt' );

		// Ensure demo fixtures are present for testing scenarios.
		self::import_fixture_file( 'demo-wp-documentate.odt' );
		self::import_fixture_file( 'demo-wp-documentate.docx' );
		self::import_fixture_file( 'autorizacionviaje.odt' );
		self::import_fixture_file( 'gastossuplidos.odt' );
		self::import_fixture_file( 'propuestagasto.odt' );
		self::import_fixture_file( 'convocatoriareunion.odt' );
		self::import_fixture_file( 'memoria_pago_cep.odt' );
		self::import_fixture_file( 'respuesta_escrito.odt' );
		self::import_fixture_file( 'haceconstar.odt' );
	}

	/**
	 * Ensure demo document types exist with bundled templates.
	 *
	 * @return void
	 */
	public static function maybe_seed_default_doc_types() {
		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			return;
		}

		$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
		if ( ! $should_seed ) {
			return;
		}

		self::ensure_default_media();

		foreach ( self::get_doc_type_definitions() as $definition ) {
			self::seed_doc_type( $definition );
		}
	}

	/**
	 * Create or update a single demo document type and its schema.
	 *
	 * @param array $definition Document type definition.
	 * @return void
	 */
	private static function seed_doc_type( $definition ) {
		$template_id = intval( $definition['template_id'] );
		if ( $template_id <= 0 ) {
			return;
		}

		$term_id = self::resolve_doc_type_term( $definition );
		if ( $term_id <= 0 ) {
			return;
		}

		// Leave alone any term a different fixture already claimed.
		$fixture_key = get_term_meta( $term_id, '_documentate_fixture', true );
		if ( ! empty( $fixture_key ) && $fixture_key !== $definition['fixture_key'] ) {
			return;
		}

		update_term_meta( $term_id, '_documentate_fixture', $definition['fixture_key'] );
		update_term_meta( $term_id, 'documentate_type_color', $definition['color'] );
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, $definition['pdf_layout'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );

		$path = get_attached_file( $template_id );
		if ( ! $path ) {
			return;
		}

		self::sync_doc_type_schema( $term_id, $template_id, $path );
	}

	/**
	 * Find the demo document type term, creating it when missing.
	 *
	 * @param array $definition Document type definition.
	 * @return int Term ID, or 0 when it could not be created.
	 */
	private static function resolve_doc_type_term( $definition ) {
		$term = get_term_by( 'slug', $definition['slug'], 'documentate_doc_type' );
		if ( $term instanceof WP_Term ) {
			return intval( $term->term_id );
		}

		$created = wp_insert_term(
			$definition['name'],
			'documentate_doc_type',
			array(
				'slug' => $definition['slug'],
				'description' => $definition['description'],
			)
		);

		if ( is_wp_error( $created ) ) {
			return 0;
		}

		return intval( $created['term_id'] );
	}

	/**
	 * Extract and store the schema for a demo document type template.
	 *
	 * @param int    $term_id     Document type term ID.
	 * @param int    $template_id Template attachment ID.
	 * @param string $path        Template file path.
	 * @return void
	 */
	private static function sync_doc_type_schema( $term_id, $template_id, $path ) {
		$storage = new Documentate\DocType\SchemaStorage();

		$existing_schema = $storage->get_schema( $term_id );
		$template_hash = @md5_file( $path );

		// Nothing to re-extract while the stored schema still matches the file.
		if ( self::schema_matches_template( $existing_schema, $template_hash ) ) {
			update_term_meta(
				$term_id,
				'documentate_type_template_type',
				self::resolve_template_type( $existing_schema, $path )
			);
			return;
		}

		$extractor = new Documentate\DocType\SchemaExtractor();
		$schema = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			return;
		}

		$schema['meta']['template_id'] = $template_id;
		$schema['meta']['template_type'] = self::resolve_template_type( $schema, $path );
		$schema['meta']['template_name'] = basename( $path );
		if ( empty( $schema['meta']['hash'] ) && $template_hash ) {
			$schema['meta']['hash'] = $template_hash;
		}

		update_term_meta( $term_id, 'documentate_type_template_type', $schema['meta']['template_type'] );

		$storage->save_schema( $term_id, $schema );
	}

	/**
	 * Whether a stored schema still matches the template file on disk.
	 *
	 * @param mixed        $existing_schema Stored schema, if any.
	 * @param string|false $template_hash   Hash of the template file.
	 * @return bool
	 */
	private static function schema_matches_template( $existing_schema, $template_hash ) {
		return ! empty( $existing_schema )
			&& $template_hash
			&& isset( $existing_schema['meta']['hash'] )
			&& $template_hash === $existing_schema['meta']['hash'];
	}

	/**
	 * Read the template type from a schema, falling back to the file extension.
	 *
	 * @param array  $schema Schema holding the meta section.
	 * @param string $path   Template file path.
	 * @return string
	 */
	private static function resolve_template_type( $schema, $path ) {
		if ( isset( $schema['meta']['template_type'] ) ) {
			return (string) $schema['meta']['template_type'];
		}

		return strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get document type definitions for seeding.
	 *
	 * @return array
	 */
	private static function get_doc_type_definitions() {
		$definitions = array();

		foreach ( self::get_doc_type_fixtures() as $fixture => $spec ) {
			$template_id = self::import_fixture_file( $fixture );
			if ( $template_id <= 0 ) {
				continue;
			}

			$definitions[] = array_merge(
				$spec,
				array(
					'template_id' => $template_id,
					'fixture_key' => $spec['slug'],
				)
			);
		}

		return $definitions;
	}

	/**
	 * Demo document types keyed by the fixture file they are built from.
	 *
	 * Declaration order is the seeding order. The fixture key of every entry
	 * is its slug, so it is derived rather than repeated.
	 *
	 * @return array<string,array{slug:string,name:string,description:string,color:string,pdf_layout:string}>
	 */
	private static function get_doc_type_fixtures() {
		return array(
			'resolucion.odt' => array(
				'slug' => 'resolucion-administrativa',
				'name' => 'Resolución Administrativa',
				'description' => 'Plantilla para resoluciones administrativas con antecedentes, fundamentos de derecho, resuelvo y anexos.',
				'color' => '#37517e',
				'pdf_layout' => 'resolucion',
			),
			'demo-wp-documentate.odt' => array(
				'slug' => 'documentate-demo-wp-documentate-odt',
				'name' => __( 'Advanced test document type (ODT)', 'documentate' ),
				'description' => __(
					'Example automatically created with the included demo-wp-documentate.odt template.',
					'documentate',
				),
				'color' => '#6c5ce7',
				'pdf_layout' => 'generic',
			),
			'demo-wp-documentate.docx' => array(
				'slug' => 'documentate-demo-wp-documentate-docx',
				'name' => __( 'Advanced test document type (DOCX)', 'documentate' ),
				'description' => __(
					'Example automatically created with the included demo-wp-documentate.docx template.',
					'documentate',
				),
				'color' => '#0f9d58',
				'pdf_layout' => 'generic',
			),
			'autorizacionviaje.odt' => array(
				'slug' => 'autorizacion-viaje',
				'name' => 'Autorización de viaje',
				'description' => 'Plantilla para autorizaciones de viaje con listado de asistentes.',
				'color' => '#e67e22',
				'pdf_layout' => 'autorizacionviaje',
			),
			'gastossuplidos.odt' => array(
				'slug' => 'gastos-suplidos',
				'name' => 'Solicitud de gastos suplidos',
				'description' => 'Plantilla para solicitud de reembolso de gastos con listado de facturas.',
				'color' => '#27ae60',
				'pdf_layout' => 'gastossuplidos',
			),
			'propuestagasto.odt' => array(
				'slug' => 'propuesta-gasto',
				'name' => 'Propuesta de gasto',
				'description' => 'Plantilla para propuestas de gasto con libramientos, servicios, suministros y expertos.',
				'color' => '#9b59b6',
				'pdf_layout' => 'propuestagasto',
			),
			'convocatoriareunion.odt' => array(
				'slug' => 'convocatoria-reunion',
				'name' => 'Convocatoria de reunión',
				'description' => 'Plantilla para convocatorias de reuniones con lugar, fecha, horario y orden del día.',
				'color' => '#3498db',
				'pdf_layout' => 'convocatoriareunion',
			),
			'memoria_pago_cep.odt' => array(
				'slug' => 'memoria-pago',
				'name' => 'Memoria justificativa de pago',
				'description' => 'Plantilla para memorias justificativas de pago con listado de facturas y datos del CEP.',
				'color' => '#d35400',
				'pdf_layout' => 'memoria_pago_cep',
			),
			'respuesta_escrito.odt' => array(
				'slug' => 'respuesta-escrito',
				'name' => 'Respuesta a escrito',
				'description' => 'Plantilla para respuestas a escritos y solicitudes con destinatario, asunto y texto de respuesta.',
				'color' => '#2c3e50',
				'pdf_layout' => 'respuesta_escrito',
			),
			'modelo_informe.odt' => array(
				'slug' => 'modelo-informe',
				'name' => 'Modelo de informe',
				'description' => 'Plantilla para informes con asunto, texto del informe y cargo firmante.',
				'color' => '#16a085',
				'pdf_layout' => 'modelo_informe',
			),
			'haceconstar.odt' => array(
				'slug' => 'hace-constar',
				'name' => 'Hace constar',
				'description' => 'Plantilla de certificado «Hace constar» que acredita la participación de una persona en determinadas actividades.',
				'color' => '#c0392b',
				'pdf_layout' => 'haceconstar',
			),
		);
	}

	/**
	 * Maybe seed demo documents after activation.
	 *
	 * @return void
	 */
	public static function maybe_seed_demo_documents() {
		if ( ! post_type_exists( 'documentate_document' ) || ! taxonomy_exists( 'documentate_doc_type' ) ) {
			return;
		}

		$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
		if ( ! $should_seed ) {
			return;
		}

		// Check if demo documents already exist - if so, skip seeding.
		if ( self::demo_documents_already_seeded() ) {
			delete_option( 'documentate_seed_demo_documents' );
			return;
		}

		self::maybe_seed_default_doc_types();

		$seeded_ids = self::seed_specific_demo_documents();

		// Also create demo documents for other document types (advanced demos).
		self::seed_remaining_demo_documents( $seeded_ids );

		self::assign_demo_document_metadata();

		delete_option( 'documentate_seed_demo_documents' );
	}

	/**
	 * Whether any demo document already exists.
	 *
	 * @return bool
	 */
	private static function demo_documents_already_seeded() {
		$existing_demos = get_posts(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key' => '_documentate_demo_key',
						'compare' => 'EXISTS',
					),
					array(
						'key' => '_documentate_demo_type_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return ! empty( $existing_demos );
	}

	/**
	 * Create the hand-written demo documents for the types that ship one.
	 *
	 * @return int[] Term IDs that received a specific demo document.
	 */
	private static function seed_specific_demo_documents() {
		$seeded_ids = array();

		// Resolución Administrativa ships three demo documents rather than one.
		$resolucion = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
		if ( $resolucion instanceof WP_Term ) {
			self::create_resolucion_demo_documents( $resolucion );
			$seeded_ids[] = $resolucion->term_id;
		}

		foreach ( self::get_specific_demos() as $slug => $demo ) {
			$term = get_term_by( 'slug', $slug, 'documentate_doc_type' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			self::create_specific_demo_documents( $term, $demo );
			$seeded_ids[] = $term->term_id;
		}

		return $seeded_ids;
	}

	/**
	 * Document type slugs mapped to their hand-written demo document.
	 *
	 * @return array<string,array>
	 */
	private static function get_specific_demos() {
		return array(
			'autorizacion-viaje' => self::get_autorizacion_viaje_demo(),
			'gastos-suplidos' => self::get_gastos_suplidos_demo(),
			'propuesta-gasto' => self::get_propuesta_gasto_demo(),
			'convocatoria-reunion' => self::get_convocatoria_reunion_demo(),
			'memoria-pago' => self::get_memoria_pago_demo(),
			'respuesta-escrito' => self::get_respuesta_escrito_demo(),
			'modelo-informe' => self::get_modelo_informe_demo(),
			'hace-constar' => self::get_hace_constar_demo(),
		);
	}

	/**
	 * Create a generic demo document for every remaining document type.
	 *
	 * @param int[] $exclude_ids Term IDs that already received a demo document.
	 * @return void
	 */
	private static function seed_remaining_demo_documents( array $exclude_ids ) {
		$terms = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
				'exclude' => $exclude_ids,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		foreach ( $terms as $other_term ) {
			if ( self::demo_document_exists( $other_term->term_id ) ) {
				continue;
			}

			self::create_demo_document_for_type( $other_term );
		}
	}

	/**
	 * Seed a hierarchical category structure for scope filtering demo.
	 *
	 * @return void
	 */
	public static function maybe_seed_demo_categories() {
		$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
		if ( ! $should_seed ) {
			return;
		}

		if ( term_exists( 'Organización', 'category' ) ) {
			return;
		}

		$tree = array(
			'Organización' => array(
				'Dirección General' => array(
					'Secretaría General' => array(),
					'Gabinete' => array(),
				),
				'Subdirección de Administración' => array(
					'Departamento de Personal' => array(),
					'Departamento de Contabilidad' => array(),
				),
				'Subdirección Técnica' => array(
					'Departamento de Proyectos' => array(),
					'Departamento de Sistemas' => array(),
				),
			),
		);

		self::create_category_tree( $tree, 0 );
	}

	/**
	 * Recursively create categories from a nested array.
	 *
	 * @param array $tree     Associative array of name => children.
	 * @param int   $parent_id Parent term ID (0 for top-level).
	 * @return void
	 */
	private static function create_category_tree( $tree, $parent_id = 0 ) {
		foreach ( $tree as $name => $children ) {
			$result = wp_insert_term( $name, 'category', array( 'parent' => $parent_id ) );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$term_id = intval( $result['term_id'] );

			if ( ! empty( $children ) ) {
				self::create_category_tree( $children, $term_id );
			}
		}
	}

	/**
	 * Seed demo users with scope category assignments.
	 *
	 * @return void
	 */
	public static function maybe_seed_demo_users() {
		$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
		if ( ! $should_seed ) {
			return;
		}

		// Defence in depth: never create real login accounts on production.
		if ( ! self::should_allow_demo_seeding() ) {
			return;
		}

		$users = array(
			array(
				'user_login' => 'editor1',
				'user_email' => 'editor1@example.com',
				'user_pass' => 'password',
				'role' => 'editor',
				'display_name' => 'María García',
				'first_name' => 'María',
				'last_name' => 'García',
				'scope' => 'Subdirección de Administración',
			),
			array(
				'user_login' => 'author1',
				'user_email' => 'author1@example.com',
				'user_pass' => 'password',
				'role' => 'author',
				'display_name' => 'Carlos López',
				'first_name' => 'Carlos',
				'last_name' => 'López',
				'scope' => 'Departamento de Proyectos',
			),
			array(
				'user_login' => 'subscriber1',
				'user_email' => 'subscriber1@example.com',
				'user_pass' => 'password',
				'role' => 'subscriber',
				'display_name' => 'Ana Martínez',
				'first_name' => 'Ana',
				'last_name' => 'Martínez',
				'scope' => 'Departamento de Personal',
			),
		);

		foreach ( $users as $user_data ) {
			$scope_name = $user_data['scope'];
			unset( $user_data['scope'] );

			// Skip if user already exists.
			if ( username_exists( $user_data['user_login'] ) ) {
				continue;
			}

			$user_id = wp_insert_user( $user_data );
			if ( is_wp_error( $user_id ) ) {
				continue;
			}

			// Assign scope category.
			$scope_term = get_term_by( 'name', $scope_name, 'category' );
			if ( $scope_term instanceof WP_Term ) {
				update_user_meta( $user_id, Documentate_User_Scope::META_KEY, $scope_term->term_id );
			}
		}
	}

	/**
	 * Assign categories and authors to demo documents for scope filtering.
	 *
	 * @return void
	 */
	private static function assign_demo_document_metadata() {
		// Get the root "Organización" term.
		$root = get_term_by( 'name', 'Organización', 'category' );
		if ( ! $root instanceof WP_Term ) {
			return;
		}

		// Get all descendant categories (excluding root and Uncategorized).
		$all_cats = get_terms(
			array(
				'taxonomy' => 'category',
				'child_of' => $root->term_id,
				'hide_empty' => false,
				'fields' => 'ids',
			)
		);

		if ( is_wp_error( $all_cats ) || empty( $all_cats ) ) {
			return;
		}

		// Get all demo documents.
		$demo_docs = get_posts(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'any',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key' => '_documentate_demo_key',
						'compare' => 'EXISTS',
					),
					array(
						'key' => '_documentate_demo_type_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( empty( $demo_docs ) ) {
			return;
		}

		// Build list of authors to round-robin: admin + editor1 + author1.
		$author_ids = array( get_current_user_id() );
		$editor_user = get_user_by( 'login', 'editor1' );
		$author_user = get_user_by( 'login', 'author1' );
		if ( $editor_user ) {
			$author_ids[] = $editor_user->ID;
		}
		if ( $author_user ) {
			$author_ids[] = $author_user->ID;
		}

		$cat_count = count( $all_cats );
		$author_count = count( $author_ids );

		foreach ( $demo_docs as $index => $post_id ) {
			// Round-robin category assignment.
			$cat_id = $all_cats[ $index % $cat_count ];
			wp_set_post_terms( $post_id, array( $cat_id ), 'category', false );

			// Round-robin author assignment.
			wp_update_post(
				array(
					'ID' => $post_id,
					'post_author' => $author_ids[ $index % $author_count ],
				)
			);
		}
	}

	/**
	 * Create the 3 specific demo documents for the Resolución Administrativa type.
	 *
	 * @param WP_Term $term Document type term.
	 * @return void
	 */
	private static function create_resolucion_demo_documents( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$term_id = absint( $term->term_id );
		if ( $term_id <= 0 ) {
			return;
		}

		$demo_documents = self::get_resolucion_demo_data();

		foreach ( $demo_documents as $demo_key => $demo_data ) {
			// Check if this specific demo document already exists.
			$existing = get_posts(
				array(
					'post_type' => 'documentate_document',
					'post_status' => 'any',
					'posts_per_page' => 1,
					'fields' => 'ids',
					'meta_key' => '_documentate_demo_key',
					'meta_value' => $demo_key,
				)
			);

			if ( ! empty( $existing ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type' => 'documentate_document',
					'post_title' => $demo_data['title'],
					'post_status' => 'private',
					'post_content' => '',
					'post_author' => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				continue;
			}

			wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

			// Save field values.
			$structured_fields = self::save_demo_fields( $post_id, $demo_data['fields'] );

			update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
			update_post_meta( $post_id, '_documentate_demo_key', $demo_key );
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT,
				sanitize_text_field( $demo_data['title'] ),
			);
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR,
				sanitize_text_field( $demo_data['author'] ),
			);
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS,
				sanitize_text_field( $demo_data['keywords'] ),
			);

			$content = self::build_structured_demo_content( $structured_fields );
			if ( '' !== $content ) {
				wp_update_post(
					array(
						'ID' => $post_id,
						'post_content' => wp_slash( $content ),
					)
				);
			}
		}
	}

	/**
	 * Create specific demo documents for a document type using provided data.
	 *
	 * @param WP_Term $term      Document type term.
	 * @param array   $demo_data Array of demo documents keyed by demo_key.
	 * @return void
	 */
	private static function create_specific_demo_documents( $term, $demo_data ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$term_id = absint( $term->term_id );
		if ( $term_id <= 0 || empty( $demo_data ) ) {
			return;
		}

		foreach ( $demo_data as $demo_key => $data ) {
			// Check if this specific demo document already exists.
			$existing = get_posts(
				array(
					'post_type' => 'documentate_document',
					'post_status' => 'any',
					'posts_per_page' => 1,
					'fields' => 'ids',
					'meta_key' => '_documentate_demo_key',
					'meta_value' => $demo_key,
				)
			);

			if ( ! empty( $existing ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type' => 'documentate_document',
					'post_title' => $data['title'],
					'post_status' => 'private',
					'post_content' => '',
					'post_author' => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				continue;
			}

			wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

			// Save field values.
			$structured_fields = self::save_demo_fields( $post_id, $data['fields'] );

			update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
			update_post_meta( $post_id, '_documentate_demo_key', $demo_key );
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT,
				sanitize_text_field( $data['title'] ),
			);
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR,
				sanitize_text_field( $data['author'] ),
			);
			update_post_meta(
				$post_id,
				\Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS,
				sanitize_text_field( $data['keywords'] ),
			);

			$content = self::build_structured_demo_content( $structured_fields );
			if ( '' !== $content ) {
				wp_update_post(
					array(
						'ID' => $post_id,
						'post_content' => wp_slash( $content ),
					)
				);
			}
		}
	}

	/**
	 * Save demo field values and return structured fields array.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Fields data.
	 * @return array Structured fields for content building.
	 */
	private static function save_demo_fields( $post_id, $fields ) {
		$structured_fields = array();

		foreach ( $fields as $slug => $field_data ) {
			$value = $field_data['value'];
			$type = $field_data['type'];

			if ( 'array' === $type ) {
				$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				// Metadata writes unslash strings; preserve JSON escapes such as newlines.
				update_post_meta( $post_id, 'documentate_field_' . $slug, wp_slash( $encoded ) );
				$structured_fields[ $slug ] = array(
					'type' => 'array',
					'value' => $encoded,
				);
			} else {
				if ( 'rich' === $type ) {
					$value = wp_kses_post( $value );
				} elseif ( 'single' === $type ) {
					$value = sanitize_text_field( $value );
				} else {
					$value = sanitize_textarea_field( $value );
				}
				update_post_meta( $post_id, 'documentate_field_' . $slug, $value );
				$structured_fields[ $slug ] = array(
					'type' => $type,
					'value' => $value,
				);
			}
		}

		return $structured_fields;
	}

	/**
	 * Get demo data for the 3 specific resolution documents.
	 *
	 * @return array
	 */
	private static function get_resolucion_demo_data() {
		return array(
			'resolucion-prueba' => self::get_resolucion_prueba_demo(),
			'listado-provisional-prueba' => self::get_listado_provisional_demo(),
			'listado-definitivo-prueba' => self::get_listado_definitivo_demo(),
		);
	}

	/**
	 * Get demo data for "Resolución de prueba".
	 *
	 * @return array
	 */
	private static function get_resolucion_prueba_demo() {
		return array(
			'title' => 'Ejemplo: Resolución de prueba',
			'author' => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'resolución, convocatoria, bases, prueba',
			'fields' => array(
				'objeto' => array(
					'type' => 'textarea',
					'value' => 'Aprobación de las bases reguladoras y convocatoria del programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> El Decreto 114/2011, de 11 de mayo, por el que se regula la convocatoria, reconocimiento, certificación y registro de las actividades de formación permanente del profesorado.</p>
<p><strong>Segundo.</strong> La Orden de 9 de octubre de 2013, por la que se desarrolla el Decreto 81/2010, de 8 de julio, por el que se aprueba el Reglamento Orgánico de los centros docentes públicos no universitarios de la Comunidad Autónoma de Canarias.</p>
<p><strong>Tercero.</strong> Se hace necesario impulsar programas que fomenten la innovación educativa en los centros docentes públicos de la Comunidad Autónoma de Canarias, con el fin de mejorar la calidad de la enseñanza.</p>',
				),
				'fundamentos' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> La Ley Orgánica 2/2006, de 3 de mayo, de Educación, modificada por la Ley Orgánica 3/2020, de 29 de diciembre, establece en su artículo 102 que la formación permanente constituye un derecho y una obligación de todo el profesorado.</p>
<p><strong>Segundo.</strong> El artículo 132 del Estatuto de Autonomía de Canarias, aprobado por Ley Orgánica 1/2018, de 5 de noviembre, atribuye a la Comunidad Autónoma la competencia de desarrollo legislativo y ejecución en materia de educación.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio, por el que se aprueba la estructura orgánica de la Consejería de Educación, Formación Profesional, Actividad Física y Deportes.</p>',
				),
				'resuelvo' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> Aprobar las bases reguladoras del programa piloto de innovación educativa para el curso 2025-2026, que se recogen en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Convocar la participación de los centros docentes públicos no universitarios de la Comunidad Autónoma de Canarias en el citado programa.</p>
<p><strong>Tercero.</strong> El plazo de presentación de solicitudes será de 15 días hábiles contados a partir del día siguiente al de la publicación de esta resolución.</p>
<p><strong>Cuarto.</strong> Contra la presente resolución, que no pone fin a la vía administrativa, cabe interponer recurso de alzada ante la Viceconsejería de Educación en el plazo de un mes.</p>',
				),
				'anexos' => array(
					'type' => 'array',
					'value' => array(
						array(
							'code' => 'Anexo I',
							'title' => 'BASES REGULADORAS DEL PROGRAMA',
							'summary' => '<p><strong>1. Objeto y finalidad.</strong> El presente programa tiene como finalidad promover la innovación educativa en los centros docentes públicos.</p>
<p><strong>2. Destinatarios.</strong> Podrán participar los centros docentes públicos no universitarios dependientes de la Consejería de Educación.</p>
<p><strong>3. Requisitos.</strong> Los centros participantes deberán contar con la aprobación del Consejo Escolar y disponer de los recursos necesarios.</p>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Listado provisional de prueba".
	 *
	 * @return array
	 */
	private static function get_listado_provisional_demo() {
		return array(
			'title' => 'Ejemplo: Listado provisional de prueba',
			'author' => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'listado, provisional, admitidos, centros',
			'fields' => array(
				'objeto' => array(
					'type' => 'textarea',
					'value' => 'Publicación del listado provisional de centros admitidos y excluidos en el programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> Por Resolución de fecha 15 de septiembre de 2025, se aprobaron las bases reguladoras y se convocó la participación en el programa piloto de innovación educativa para el curso 2025-2026.</p>
<p><strong>Segundo.</strong> Finalizado el plazo de presentación de solicitudes, se ha procedido a la revisión y baremación de las mismas por la comisión de selección.</p>
<p><strong>Tercero.</strong> De conformidad con lo establecido en la base séptima de la convocatoria, procede la publicación del listado provisional de centros admitidos y excluidos.</p>',
				),
				'fundamentos' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> La base séptima de la Resolución de 15 de septiembre de 2025 establece que, una vez finalizado el plazo de presentación de solicitudes, se publicará el listado provisional.</p>
<p><strong>Segundo.</strong> La Ley 39/2015, de 1 de octubre, del Procedimiento Administrativo Común de las Administraciones Públicas, establece en su artículo 45 los requisitos de publicación de actos administrativos.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio.</p>',
				),
				'resuelvo' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> Publicar el listado provisional de centros admitidos en el programa piloto de innovación educativa, que figura en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Publicar el listado provisional de centros excluidos, con indicación de las causas de exclusión, que figura en el Anexo II.</p>
<p><strong>Tercero.</strong> Abrir un plazo de 10 días hábiles para la presentación de alegaciones, contados a partir del día siguiente al de la publicación de esta resolución.</p>
<p><strong>Cuarto.</strong> Las alegaciones deberán presentarse a través de la sede electrónica del Gobierno de Canarias.</p>',
				),
				'anexos' => array(
					'type' => 'array',
					'value' => array(
						array(
							'code' => 'Anexo I',
							'title' => 'LISTADO PROVISIONAL DE CENTROS ADMITIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Isla</th><th>Puntuación</th></tr></thead><tbody>
<tr><td>35001234</td><td>CEIP Ejemplo Uno</td><td>Gran Canaria</td><td>85</td></tr>
<tr><td>38002345</td><td>IES Ejemplo Dos</td><td>Tenerife</td><td>82</td></tr>
<tr><td>35003456</td><td>CEO Ejemplo Tres</td><td>Lanzarote</td><td>78</td></tr>
<tr><td>38004567</td><td>CEIP Ejemplo Cuatro</td><td>La Palma</td><td>75</td></tr>
</tbody></table>',
						),
						array(
							'code' => 'Anexo II',
							'title' => 'LISTADO PROVISIONAL DE CENTROS EXCLUIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Causa de exclusión</th></tr></thead><tbody>
<tr><td>35005678</td><td>CEIP Ejemplo Cinco</td><td>No aporta acta del Consejo Escolar</td></tr>
<tr><td>38006789</td><td>IES Ejemplo Seis</td><td>Solicitud fuera de plazo</td></tr>
</tbody></table>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Listado definitivo de prueba".
	 *
	 * @return array
	 */
	private static function get_listado_definitivo_demo() {
		return array(
			'title' => 'Ejemplo: Listado definitivo de prueba',
			'author' => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'listado, definitivo, admitidos, centros',
			'fields' => array(
				'objeto' => array(
					'type' => 'textarea',
					'value' => 'Publicación del listado definitivo de centros admitidos y excluidos en el programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> Por Resolución de fecha 15 de septiembre de 2025, se aprobaron las bases reguladoras y se convocó la participación en el programa piloto de innovación educativa para el curso 2025-2026.</p>
<p><strong>Segundo.</strong> Por Resolución de fecha 20 de octubre de 2025, se publicó el listado provisional de centros admitidos y excluidos, abriéndose un plazo de alegaciones.</p>
<p><strong>Tercero.</strong> Finalizado el plazo de alegaciones y estudiadas las mismas por la comisión de selección, procede la publicación del listado definitivo.</p>
<p><strong>Cuarto.</strong> Se han estimado las alegaciones presentadas por el CEIP Ejemplo Cinco, al subsanar la documentación requerida.</p>',
				),
				'fundamentos' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> La base octava de la Resolución de 15 de septiembre de 2025 establece que, una vez resueltas las alegaciones, se publicará el listado definitivo.</p>
<p><strong>Segundo.</strong> La Ley 39/2015, de 1 de octubre, del Procedimiento Administrativo Común de las Administraciones Públicas.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio.</p>',
				),
				'resuelvo' => array(
					'type' => 'rich',
					'value' => '<p><strong>Primero.</strong> Publicar el listado definitivo de centros admitidos en el programa piloto de innovación educativa, que figura en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Publicar el listado definitivo de centros excluidos, con indicación de las causas de exclusión, que figura en el Anexo II.</p>
<p><strong>Tercero.</strong> Contra la presente resolución, que no pone fin a la vía administrativa, cabe interponer recurso de alzada ante la Viceconsejería de Educación en el plazo de un mes.</p>',
				),
				'anexos' => array(
					'type' => 'array',
					'value' => array(
						array(
							'code' => 'Anexo I',
							'title' => 'LISTADO DEFINITIVO DE CENTROS ADMITIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Isla</th><th>Puntuación</th></tr></thead><tbody>
<tr><td>35001234</td><td>CEIP Ejemplo Uno</td><td>Gran Canaria</td><td>85</td></tr>
<tr><td>38002345</td><td>IES Ejemplo Dos</td><td>Tenerife</td><td>82</td></tr>
<tr><td>35003456</td><td>CEO Ejemplo Tres</td><td>Lanzarote</td><td>78</td></tr>
<tr><td>38004567</td><td>CEIP Ejemplo Cuatro</td><td>La Palma</td><td>75</td></tr>
<tr><td>35005678</td><td>CEIP Ejemplo Cinco</td><td>Gran Canaria</td><td>72</td></tr>
</tbody></table>',
						),
						array(
							'code' => 'Anexo II',
							'title' => 'LISTADO DEFINITIVO DE CENTROS EXCLUIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Causa de exclusión</th></tr></thead><tbody>
<tr><td>38006789</td><td>IES Ejemplo Seis</td><td>Solicitud fuera de plazo (no subsanable)</td></tr>
</tbody></table>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Autorización de viaje".
	 *
	 * @return array
	 */
	private static function get_autorizacion_viaje_demo() {
		return array(
			'autorizacion-viaje-prueba' => array(
				'title' => 'Ejemplo: Autorización de viaje a Madrid',
				'author' => 'Dirección General de Personal',
				'keywords' => 'viaje, autorización, comisión de servicios',
				'fields' => array(
					'lugar' => array(
						'type' => 'single',
						'value' => 'Madrid',
					),
					'fecha_evento_inicio' => array(
						'type' => 'single',
						'value' => '2025-03-10',
					),
					'fecha_evento_fin' => array(
						'type' => 'single',
						'value' => '2025-03-12',
					),
					'invitante' => array(
						'type' => 'single',
						'value' => 'Ministerio de Educación, Formación Profesional y Deportes',
					),
					'temas' => array(
						'type' => 'textarea',
						'value' => 'Reunión de coordinación interterritorial sobre programas de innovación educativa y formación del profesorado para el curso 2025-2026.',
					),
					'pagador' => array(
						'type' => 'single',
						'value' => 'Consejería de Educación, Formación Profesional, Actividad Física y Deportes del Gobierno de Canarias',
					),
					'asistentes' => array(
						'type' => 'array',
						'value' => array(
							array(
								'apellido1' => 'García',
								'apellido2' => 'Hernández',
								'nombre' => 'María del Carmen',
							),
							array(
								'apellido1' => 'Rodríguez',
								'apellido2' => 'Pérez',
								'nombre' => 'Juan Antonio',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Solicitud de gastos suplidos".
	 *
	 * @return array
	 */
	private static function get_gastos_suplidos_demo() {
		return array(
			'gastos-suplidos-prueba' => array(
				'title' => 'Ejemplo: Solicitud de reembolso de gastos de viaje',
				'author' => 'Servicio de Gestión Económica',
				'keywords' => 'gastos, suplidos, reembolso, facturas',
				'fields' => array(
					'nombre_completo' => array(
						'type' => 'single',
						'value' => 'María del Carmen García Hernández',
					),
					'dni' => array(
						'type' => 'single',
						'value' => '43123456A',
					),
					'iban' => array(
						'type' => 'single',
						'value' => 'ES9121000418450200051332',
					),
					'gastos' => array(
						'type' => 'array',
						'value' => array(
							array(
								'proveedor' => 'Iberia LAE S.A.',
								'cif' => 'A28017648',
								'factura' => 'IBE-2025-00123',
								'fecha' => '2025-03-10',
								'importe' => '245.80',
							),
							array(
								'proveedor' => 'Hotel Meliá Castilla',
								'cif' => 'A28011069',
								'factura' => 'FAC-2025-4567',
								'fecha' => '2025-03-12',
								'importe' => '312.50',
							),
							array(
								'proveedor' => 'Taxi Madrid S.L.',
								'cif' => 'B12345678',
								'factura' => 'T-2025-0089',
								'fecha' => '2025-03-10',
								'importe' => '35.00',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Propuesta de gasto".
	 *
	 * @return array
	 */
	private static function get_propuesta_gasto_demo() {
		return array(
			'propuesta-gasto-prueba' => array(
				'title' => 'Ejemplo: Documento 0 - Propuesta de gasto para formación del profesorado',
				'author' => 'Servicio de Innovación Educativa',
				'keywords' => 'propuesta, gasto, formación, profesorado',
				'fields' => array_merge(
					self::get_propuesta_gasto_general_fields(),
					self::get_propuesta_gasto_libramiento_fields(),
					self::get_propuesta_gasto_provider_fields()
				),
			),
		);
	}

	/**
	 * General fields of the propuesta de gasto demo document.
	 *
	 * @return array<string,array>
	 */
	private static function get_propuesta_gasto_general_fields() {
		return array(
			'curso' => array(
				'type' => 'single',
				'value' => '2024/2025',
			),
			'numero_decreto' => array(
				'type' => 'single',
				'value' => '17',
			),
			'letra_decreto' => array(
				'type' => 'single',
				'value' => 'a',
			),
			'para' => array(
				'type' => 'textarea',
				'value' => 'la formación del profesorado en metodologías activas y competencias digitales',
			),
			'objeto' => array(
				'type' => 'textarea',
				'value' => 'Desarrollo de un programa de formación continua para el profesorado de centros públicos de Canarias en el ámbito de las metodologías activas de aprendizaje y la competencia digital docente.',
			),
			'lineadeactuacion' => array(
				'type' => 'textarea',
				'value' => 'Formación del profesorado y desarrollo profesional docente',
			),
			'destinatarios' => array(
				'type' => 'single',
				'value' => 'Profesorado de centros públicos de educación primaria y secundaria',
			),
			'alcance_centros' => array(
				'type' => 'single',
				'value' => '150',
			),
			'alcance_profesorado' => array(
				'type' => 'single',
				'value' => '2500',
			),
			'alcance_alumnado' => array(
				'type' => 'single',
				'value' => '45000',
			),
			'alcance_familias' => array(
				'type' => 'single',
				'value' => '0',
			),
			'gasto_numero' => array(
				'type' => 'single',
				'value' => '25000',
			),
			'gasto_letra' => array(
				'type' => 'single',
				'value' => 'veinticinco mil euros',
			),
			'partida' => array(
				'type' => 'single',
				'value' => '18.03.322B.229.0100',
			),
		);
	}

	/**
	 * Libramiento repeater of the propuesta de gasto demo document.
	 *
	 * @return array<string,array>
	 */
	private static function get_propuesta_gasto_libramiento_fields() {
		return array(
			'g_libramientos' => array(
				'type' => 'array',
				'value' => array(
					array(
						'centro' => '35001234',
						'finalidad' => 'Material didáctico para formación',
						'importe' => '3500',
					),
					array(
						'centro' => '38002345',
						'finalidad' => 'Equipamiento tecnológico',
						'importe' => '4200',
					),
				),
			),
		);
	}

	/**
	 * Provider fields and repeaters of the propuesta de gasto demo document.
	 *
	 * Covers servicios, suministros and expertos, which share the same shape.
	 *
	 * @return array<string,array>
	 */
	private static function get_propuesta_gasto_provider_fields() {
		return array(
			'servicios' => array(
				'type' => 'array',
				'value' => array(
					array(
						'proveedor' => 'Formación Docente Canarias S.L.',
						'cif' => 'B76543210',
						'email' => 'contacto@formaciondocente.es',
						'telefono' => '922123456',
						'bruto' => '4500',
						'igic' => '7',
						'irpf' => '0',
						'total' => '4815',
						'conceptos' => array(
							array(
								'concepto' => 'Curso presencial metodologías activas (20h)',
								'cantidad' => '2',
								'unitario' => '1500',
								'total' => '3000',
							),
							array(
								'concepto' => 'Taller competencia digital docente (10h)',
								'cantidad' => '3',
								'unitario' => '500',
								'total' => '1500',
							),
						),
					),
					array(
						'proveedor' => 'Aula Abierta Formación S.C.P.',
						'cif' => 'J35678901',
						'email' => 'info@aulaabiertaformacion.es',
						'telefono' => '928111222',
						'bruto' => '2100',
						'igic' => '7',
						'irpf' => '0',
						'total' => '2247',
						'conceptos' => array(
							array(
								'concepto' => 'Seminario de evaluación competencial (8h)',
								'cantidad' => '1',
								'unitario' => '900',
								'total' => '900',
							),
							array(
								'concepto' => 'Mentoría en centros (12h)',
								'cantidad' => '4',
								'unitario' => '300',
								'total' => '1200',
							),
						),
					),
				),
			),
			'suministros' => array(
				'type' => 'array',
				'value' => array(
					array(
						'proveedor' => 'TecnoEducación S.A.',
						'cif' => 'A12345678',
						'email' => 'ventas@tecnoeducacion.es',
						'telefono' => '928654321',
						'bruto' => '3500',
						'igic' => '7',
						'irpf' => '0',
						'total' => '3745',
						'conceptos' => array(
							array(
								'concepto' => 'Tablets educativas',
								'cantidad' => '10',
								'unitario' => '350',
								'total' => '3500',
							),
						),
					),
				),
			),
			'expertos' => array(
				'type' => 'array',
				'value' => array(
					array(
						'proveedor' => 'Dr. Juan Pérez González',
						'cif' => '43123456B',
						'email' => 'juan.perez@universidad.es',
						'telefono' => '650123456',
						'bruto' => '500',
						'igic' => '0',
						'irpf' => '15',
						'total' => '425',
						'conceptos' => array(
							array(
								'concepto' => 'Ponencia inaugural jornadas formativas',
								'cantidad' => '1',
								'unitario' => '500',
								'total' => '500',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Convocatoria de reunión".
	 *
	 * @return array
	 */
	private static function get_convocatoria_reunion_demo() {
		return array(
			'convocatoria-reunion-prueba' => array(
				'title' => 'Ejemplo: Convocatoria de reunión de coordinación',
				'author' => 'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación',
				'keywords' => 'convocatoria, reunión, coordinación, centros',
				'fields' => array(
					'motivo_reunion' => array(
						'type' => 'single',
						'value' => 'de coordinación de centros de referencia',
					),
					'area' => array(
						'type' => 'single',
						'value' => 'Área de Tecnología Educativa',
					),
					'convocado' => array(
						'type' => 'single',
						'value' => 'la persona responsable de las TIC',
					),
					'tipo_reunion' => array(
						'type' => 'single',
						'value' => 'telemática',
					),
					'lugar' => array(
						'type' => 'single',
						'value' => 'Videoconferencia (se enviará enlace por correo electrónico)',
					),
					'dia' => array(
						'type' => 'single',
						'value' => '2025-03-15',
					),
					'horario' => array(
						'type' => 'single',
						'value' => 'de 10:00 a 12:00 horas',
					),
					'orden_del_dia' => array(
						'type' => 'rich',
						'value' => '<ul>
<li>Bienvenida y presentación de los asistentes.</li>
<li>Análisis del estado actual de los proyectos de innovación tecnológica en los centros.</li>
<li>Presentación de nuevas herramientas y recursos digitales para el curso 2025-2026.</li>
<li>Planificación de las jornadas de formación del profesorado.</li>
<li>Ruegos y preguntas.</li>
</ul>',
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Memoria justificativa de pago".
	 *
	 * @return array
	 */
	private static function get_memoria_pago_demo() {
		return array(
			'memoria-pago-prueba' => array(
				'title' => 'Ejemplo: Memoria justificativa de pago de jornadas formativas',
				'author' => 'CEP de Santa Cruz de Tenerife',
				'keywords' => 'memoria, pago, facturas, CEP, jornadas',
				'fields' => array(
					'cep' => array(
						'type' => 'single',
						'value' => 'CEP DE SANTA CRUZ DE TENERIFE',
					),
					'concepto' => array(
						'type' => 'single',
						'value' => 'DE VARIAS FACTURAS PARA SUFRAGAR LOS GASTOS DERIVADOS DE LA ASISTENCIA A LAS JORNADAS DE INNOVACIÓN PEDAGÓGICA 2025',
					),
					'parrafo_jornadas' => array(
						'type' => 'textarea',
						'value' => 'Las jornadas de innovación pedagógica tienen como objetivo principal la formación del profesorado en metodologías activas de aprendizaje, incluyendo aprendizaje basado en proyectos, gamificación y uso de herramientas digitales en el aula. Están dirigidas a docentes de educación primaria y secundaria de la provincia de Santa Cruz de Tenerife.',
					),
					'resolucion_num' => array(
						'type' => 'single',
						'value' => '1539/2025',
					),
					'resolucion_fecha' => array(
						'type' => 'single',
						'value' => '2025-02-15',
					),
					'año' => array(
						'type' => 'single',
						'value' => '2025',
					),
					'tipo_persona' => array(
						'type' => 'single',
						'value' => 'COORDINADOR/A',
					),
					'items' => array(
						'type' => 'array',
						'value' => array(
							array(
								'nombre' => 'María García López',
								'concepto' => 'Material didáctico para talleres',
								'num_factura' => 'FAC-2025-0112',
								'importe' => '245.80',
							),
							array(
								'nombre' => 'Suministros Educativos S.L.',
								'concepto' => 'Equipamiento audiovisual',
								'num_factura' => 'SE-2025-0034',
								'importe' => '890.00',
							),
							array(
								'nombre' => 'Catering Insular S.A.',
								'concepto' => 'Servicio de catering para jornadas',
								'num_factura' => 'CI-2025-0567',
								'importe' => '420.50',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Respuesta a escrito".
	 *
	 * @return array
	 */
	private static function get_respuesta_escrito_demo() {
		return array(
			'respuesta-escrito-prueba' => array(
				'title' => 'Ejemplo: Respuesta a solicitud de información',
				'author' => 'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación',
				'keywords' => 'respuesta, escrito, solicitud, información',
				'fields' => array(
					'destinatario' => array(
						'type' => 'single',
						'value' => 'D./D.ª Juan Rodríguez Martín',
					),
					'asunto' => array(
						'type' => 'single',
						'value' => 'Remisión de informe solicitado con referencia de expediente 2025/00123',
					),
					'numero_solicitud' => array(
						'type' => 'single',
						'value' => '2025/00123',
					),
					'respuesta' => array(
						'type' => 'single',
						'value' => 'se hace constar que la Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación no dispone de antecedentes, datos ni información en relación con el caso referido.',
					),
				),
			),
		);
	}

	/**
	 * Get demo data for Modelo de informe.
	 *
	 * @return array
	 */
	private static function get_modelo_informe_demo() {
		return array(
			'modelo-informe-prueba' => array(
				'title' => 'Ejemplo: Informe sobre adaptaciones curriculares en centros de educación secundaria',
				'author' => 'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación',
				'keywords' => 'informe, adaptaciones curriculares, educación secundaria',
				'fields' => array(
					'asunto' => array(
						'type' => 'single',
						'value' => 'Remisión de informe sobre adaptaciones curriculares en centros de educación secundaria obligatoria',
					),
					'respuesta' => array(
						'type' => 'rich',
						'value' =>
							'<p>En relación con el asunto indicado en el encabezamiento, y tras el análisis realizado por esta Dirección General, se informa lo siguiente:</p>'
								. '<p><strong>PRIMERO.</strong> De conformidad con lo establecido en el artículo 71 de la Ley Orgánica 2/2006, de 3 de mayo, de Educación, las Administraciones educativas dispondrán los medios necesarios para que todo el alumnado alcance el máximo desarrollo personal, intelectual, social y emocional.</p>'
								. '<p><strong>SEGUNDO.</strong> Examinados los datos recabados de los centros educativos durante el curso 2024-2025, se constata que un total de 342 centros de educación secundaria obligatoria han implementado adaptaciones curriculares significativas, atendiendo a 4.127 alumnos y alumnas con necesidades específicas de apoyo educativo.</p>'
								. '<p><strong>TERCERO.</strong> Los equipos de orientación educativa han valorado positivamente la implementación de dichas adaptaciones, destacando la mejora en los indicadores de rendimiento académico y bienestar del alumnado beneficiario.</p>'
								. '<p>Es cuanto se informa a los efectos oportunos.</p>',
					),
					'firma_cargo' => array(
						'type' => 'single',
						'value' => 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS, INCLUSIÓN E INNOVACIÓN',
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Hace constar".
	 *
	 * @return array
	 */
	private static function get_hace_constar_demo() {
		return array(
			'hace-constar-prueba' => array(
				'title' => 'Ejemplo: Certificado de participación',
				'author' => 'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación',
				'keywords' => 'hace constar, certificado, participación',
				'fields' => array(
					'firmante' => array(
						'type' => 'single',
						'value' => 'Ivonne Piñero Montesdeoca',
					),
					'cargo' => array(
						'type' => 'single',
						'value' => 'RESPONSABLE DEL SERVICIO DE ORDENACIÓN DE LAS ENSEÑANZAS Y EDUCACIÓN DE PERSONAS ADULTAS',
					),
					'tratamiento' => array(
						'type' => 'single',
						'value' => 'Doña',
					),
					'nombre_completo' => array(
						'type' => 'single',
						'value' => 'Beatriz Oliver Taño',
					),
					'dni' => array(
						'type' => 'single',
						'value' => '12345678A',
					),
					'participaciones' => array(
						'type' => 'rich',
						'value' => '<ul><li>Comisión de evaluación del programa de innovación educativa, durante el curso 2024-2025.</li><li>Tribunal de selección de materiales didácticos para la educación de personas adultas.</li><li>Grupo de trabajo para la elaboración de la guía de buenas prácticas docentes.</li></ul>',
					),
					'lugar_firma' => array(
						'type' => 'single',
						'value' => 'Santa Cruz de Tenerife',
					),
				),
			),
		);
	}

	/**
	 * Check whether a demo document already exists for the given document type.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function demo_document_exists( $term_id ) {
		$term_id = absint( $term_id );
		if ( $term_id <= 0 ) {
			return true;
		}

		$existing = get_posts(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'any',
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_key' => '_documentate_demo_type_id',
				'meta_value' => (string) $term_id,
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
	public static function create_demo_document_for_type( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return false;
		}

		$term_id = absint( $term->term_id );
		if ( $term_id <= 0 ) {
			return false;
		}

		$schema = Documentate_Documents::get_term_schema( $term_id );
		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return false;
		}

		/* translators: %s: document type name. */
		$title    = sprintf( __( 'Test document – %s', 'documentate' ), $term->name );
		$author   = __( 'Demo team', 'documentate' );
		$keywords = __( 'lorem, ipsum, demo', 'documentate' );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'documentate_document',
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

		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );
		$structured_fields = self::populate_demo_document_fields( $post_id, $schema, $title );

		update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
		update_post_meta(
			$post_id,
			\Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT,
			sanitize_text_field( $title )
		);
		update_post_meta(
			$post_id,
			\Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR,
			sanitize_text_field( $author )
		);
		update_post_meta(
			$post_id,
			\Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS,
			sanitize_text_field( $keywords )
		);

		$content = self::build_structured_demo_content( $structured_fields );
		if ( '' !== $content ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content ),
				)
			);
		}

		return true;
	}

	/**
	 * Fill post meta for each field in a document-type schema.
	 *
	 * @param int    $post_id Document post ID.
	 * @param array  $schema  Schema field definitions.
	 * @param string $title   Demo document title (context for generators).
	 * @return array Structured field map for build_structured_demo_content().
	 */
	private static function populate_demo_document_fields( $post_id, $schema, $title ) {
		$structured_fields = array();
		$context           = array( 'document_title' => $title );

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
				$entry = self::build_demo_array_field_entry( $slug, $definition, $context );
				if ( null === $entry ) {
					continue;
				}
				update_post_meta( $post_id, 'documentate_field_' . $slug, wp_slash( $entry['value'] ) );
				$structured_fields[ $slug ] = $entry;
				continue;
			}

			$entry = self::build_demo_scalar_field_entry( $slug, $type, $data_type, $context );
			update_post_meta( $post_id, 'documentate_field_' . $slug, $entry['value'] );
			$structured_fields[ $slug ] = $entry;
		}

		return $structured_fields;
	}

	/**
	 * Build and encode a demo array field value.
	 *
	 * @param string $slug       Field slug.
	 * @param array  $definition Field definition.
	 * @param array  $context    Generator context.
	 * @return array{type: string, value: string}|null
	 */
	private static function build_demo_array_field_entry( $slug, $definition, $context ) {
		$item_schema = isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] )
			? $definition['item_schema']
			: array();
		$items       = self::generate_demo_array_items( $slug, $item_schema, $context );
		if ( empty( $items ) ) {
			return null;
		}

		return array(
			'type'  => 'array',
			'value' => wp_json_encode( $items, JSON_UNESCAPED_UNICODE ),
		);
	}

	/**
	 * Build a sanitized demo scalar field value.
	 *
	 * @param string $slug      Field slug.
	 * @param string $type      Field type.
	 * @param string $data_type Field data type.
	 * @param array  $context   Generator context.
	 * @return array{type: string, value: string}
	 */
	private static function build_demo_scalar_field_entry( $slug, $type, $data_type, $context ) {
		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		$value = self::generate_demo_scalar_value( $slug, $type, $data_type, 1, $context );
		$value = self::sanitize_demo_field_value( $value, $type );

		return array(
			'type'  => $type,
			'value' => $value,
		);
	}

	/**
	 * Sanitize a generated demo value for its field type.
	 *
	 * @param string $value Raw demo value.
	 * @param string $type  Field type.
	 * @return string
	 */
	private static function sanitize_demo_field_value( $value, $type ) {
		if ( 'rich' === $type ) {
			return wp_kses_post( $value );
		}
		if ( 'single' === $type ) {
			return sanitize_text_field( $value );
		}
		return sanitize_textarea_field( $value );
	}

	/**
	 * Generate demo values for array fields.
	 *
	 * @param string $slug        Repeater slug.
	 * @param array  $item_schema Item schema definition.
	 * @param array  $context     Additional context.
	 * @return array<int, array<string, string>>
	 */
	public static function generate_demo_array_items( $slug, $item_schema, $context = array() ) {
		$slug = sanitize_key( $slug );
		$item_schema = is_array( $item_schema ) ? $item_schema : array();

		if ( empty( $item_schema ) ) {
			$value = self::generate_demo_scalar_value( 'contenido', 'textarea', 'text', 1, $context );

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

				$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
				$data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';

				if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
				}

				$value = self::generate_demo_scalar_value(
					$item_slug,
					$type,
					$data_type,
					$index,
					array_merge(
						$context,
						array(
							'index' => $index,
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
	public static function generate_demo_scalar_value( $slug, $type, $data_type, $index = 1, $context = array() ) {
		$slug      = strtolower( (string) $slug );
		$type      = sanitize_key( $type );
		$data_type = sanitize_key( $data_type );
		$index     = max( 1, absint( $index ) );

		$document_title = isset( $context['document_title'] )
			? (string) $context['document_title']
			: __( 'Demo resolution', 'documentate' );

		$by_data_type = self::demo_value_for_data_type( $data_type, $index );
		if ( null !== $by_data_type ) {
			return $by_data_type;
		}

		$by_slug = self::demo_value_for_slug( $slug, $index, $document_title );
		if ( null !== $by_slug ) {
			return $by_slug;
		}

		return __( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'documentate' );
	}

	/**
	 * Demo value derived from the field data type, if applicable.
	 *
	 * @param string $data_type Field data type.
	 * @param int    $index     Item index.
	 * @return string|null
	 */
	private static function demo_value_for_data_type( $data_type, $index ) {
		if ( 'date' === $data_type ) {
			$month = max( 1, min( 12, $index ) );
			$day   = max( 1, min( 28, 10 + $index ) );
			return sprintf( '2025-%02d-%02d', $month, $day );
		}
		if ( 'number' === $data_type ) {
			return (string) ( 1 + $index );
		}
		if ( 'boolean' === $data_type ) {
			return $index % 2 ? '1' : '0';
		}
		return null;
	}

	/**
	 * Demo value derived from slug keywords.
	 *
	 * @param string $slug           Lowercased field slug.
	 * @param int    $index          Item index.
	 * @param string $document_title Demo document title.
	 * @return string|null
	 */
	private static function demo_value_for_slug( $slug, $index, $document_title ) {
		$special = self::demo_value_for_special_slug( $slug, $index, $document_title );
		if ( null !== $special ) {
			return $special;
		}

		foreach ( self::demo_slug_value_matchers() as $matcher ) {
			if ( self::slug_contains_any( $slug, $matcher[0] ) ) {
				return (string) call_user_func( $matcher[1], $index );
			}
		}

		return null;
	}

	/**
	 * Special-case slug handlers that need more than a simple keyword map.
	 *
	 * @param string $slug           Lowercased field slug.
	 * @param int    $index          Item index.
	 * @param string $document_title Demo document title.
	 * @return string|null
	 */
	private static function demo_value_for_special_slug( $slug, $index, $document_title ) {
		if ( 'cif' === $slug ) {
			return 1 === $index ? 'B12345678' : 'A87654321';
		}

		if ( self::slug_contains_any( $slug, array( 'title', 'titulo' ) ) || 'post_title' === $slug ) {
			if ( 'post_title' === $slug ) {
				return $document_title;
			}
			/* translators: %d: item sequence number. */
			return sprintf( __( 'Demo item %d', 'documentate' ), $index );
		}

		if ( self::slug_contains_any( $slug, array( 'body', 'cuerpo', 'content', 'contenido', 'html' ) ) ) {
			return self::build_rich_demo_html();
		}

		return null;
	}

	/**
	 * Keyword matchers for demo scalar values.
	 *
	 * Each entry is [ needles[], callable($index): string ].
	 *
	 * @return array<int, array{0: string[], 1: callable}>
	 */
	private static function demo_slug_value_matchers() {
		return array(
			array(
				array( 'email' ),
				static function ( $i ) {
					return 'demo' . $i . '@ejemplo.es';
				},
			),
			array(
				array( 'phone', 'tel' ),
				static function ( $i ) {
					return '+3460000000' . $i;
				},
			),
			array(
				array( 'dni' ),
				static function ( $i ) {
					return '1234567' . $i . 'A';
				},
			),
			array(
				array( 'url', 'sitio', 'web' ),
				static function ( $i ) {
					return 'https://ejemplo.es/recurso-' . $i;
				},
			),
			array(
				array( 'nombre', 'name' ),
				static function ( $i ) {
					return 1 === $i ? 'Jane Doe' : 'John Smith';
				},
			),
			array(
				array( 'summary', 'resumen' ),
				static function ( $i ) {
					/* translators: %d: item sequence number. */
					return sprintf( __( 'Demo summary %d with brief information.', 'documentate' ), $i );
				},
			),
			array(
				array( 'objeto' ),
				static function () {
					return __( 'Subject of the example resolution to illustrate the workflow.', 'documentate' );
				},
			),
			array(
				array( 'antecedentes' ),
				static function () {
					return __( 'Background facts written with test content.', 'documentate' );
				},
			),
			array(
				array( 'fundamentos' ),
				static function () {
					return __( 'Legal grounds for testing with generic references.', 'documentate' );
				},
			),
			array(
				array( 'resuelv' ),
				static function () {
					return (
						'<p>'
						. __( 'First. Approve the demo action.', 'documentate' )
						. '</p><p>'
						. __( 'Second. Notify interested parties.', 'documentate' )
						. '</p>'
					);
				},
			),
			array(
				array( 'observaciones' ),
				static function () {
					return __( 'Additional observations to complete the template.', 'documentate' );
				},
			),
			array(
				array( 'proveedor' ),
				static function ( $i ) {
					return 1 === $i ? 'Suministros Ejemplo S.L.' : 'Servicios Demo S.A.';
				},
			),
			array(
				array( 'factura' ),
				static function ( $i ) {
					return sprintf( '%03d/2025', 100 + $i );
				},
			),
			array(
				array( 'importe' ),
				static function ( $i ) {
					return 1 === $i ? '1250' : '3475.50';
				},
			),
			array(
				array( 'lugar' ),
				static function () {
					return 'Madrid';
				},
			),
			array(
				array( 'invitante' ),
				static function () {
					return 'Ministerio de Educación';
				},
			),
			array(
				array( 'temas' ),
				static function () {
					return 'Discusión de programas de innovación educativa y coordinación interterritorial.';
				},
			),
			array(
				array( 'pagador' ),
				static function () {
					return 'Consejería de Educación del Gobierno de Canarias';
				},
			),
			array(
				array( 'apellido1' ),
				static function ( $i ) {
					return 1 === $i ? 'García' : 'Rodríguez';
				},
			),
			array(
				array( 'apellido2' ),
				static function ( $i ) {
					return 1 === $i ? 'López' : 'Martínez';
				},
			),
			array(
				array( 'iban' ),
				static function () {
					return 'ES9121000418450200051332';
				},
			),
			array(
				array( 'nombre_completo' ),
				static function ( $i ) {
					return 1 === $i ? 'María García López' : 'Juan Rodríguez Martínez';
				},
			),
			array(
				array( 'keywords', 'palabras' ),
				static function () {
					return __( 'keywords, tags, demo', 'documentate' );
				},
			),
		);
	}

	/**
	 * Whether a slug contains any of the given needles.
	 *
	 * @param string   $slug    Lowercased slug.
	 * @param string[] $needles Substrings to search for.
	 * @return bool
	 */
	private static function slug_contains_any( $slug, $needles ) {
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $slug, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Shared rich-text HTML sample used by body/content demo fields.
	 *
	 * @return string
	 */
	private static function build_rich_demo_html() {
		$rich  = '<h3>' . __( 'Test heading', 'documentate' ) . '</h3>';
		$rich .= '<p>' . __( 'First paragraph with example text.', 'documentate' ) . '</p>';
		$rich .=
			'<p>'
			. sprintf(
				/* translators: 1: bold text label, 2: italic text label, 3: underline text label. */
				__( 'Second paragraph with %1$s, %2$s and %3$s.', 'documentate' ),
				'<strong>' . __( 'bold', 'documentate' ) . '</strong>',
				'<em>' . __( 'italics', 'documentate' ) . '</em>',
				'<u>' . __( 'underline', 'documentate' ) . '</u>'
			)
			. '</p>';
		$rich .= '<ul><li>' . __( 'Item one', 'documentate' ) . '</li><li>' . __( 'Item two', 'documentate' ) . '</li></ul>';
		$rich .=
			'<table><tr><th>'
			. __( 'Col 1', 'documentate' )
			. '</th><th>'
			. __( 'Col 2', 'documentate' )
			. '</th></tr><tr><td>'
			. __( 'Data A1', 'documentate' )
			. '</td><td>'
			. __( 'Data A2', 'documentate' )
			. '</td></tr><tr><td>'
			. __( 'Data B1', 'documentate' )
			. '</td><td>'
			. __( 'Data B2', 'documentate' )
			. '</td></tr></table>';
		return $rich;
	}

	/**
	 * Compose structured content fragments for seeded demo documents.
	 *
	 * @param array<string, array{type:string,value:string}> $fields Structured fields.
	 * @return string
	 */
	public static function build_structured_demo_content( $fields ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}

		$fragments = array();

		foreach ( $fields as $slug => $info ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug ) {
				continue;
			}

			$type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : '';
			$value = isset( $info['value'] ) ? (string) $info['value'] : '';

			$attributes = 'slug="' . esc_attr( $slug ) . '"';
			if ( '' !== $type && in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
				$attributes .= ' type="' . esc_attr( $type ) . '"';
			}

			$fragments[] = '<!-- documentate-field ' . $attributes . " -->\n" . $value . "\n<!-- /documentate-field -->";
		}

		return implode( "\n\n", $fragments );
	}

	/**
	 * Convert slug into a human readable label.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function humanize_slug( $slug ) {
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

	/**
	 * Create sample data for Documentate Plugin.
	 *
	 * Sets up alert settings to indicate demo data is in use.
	 */
	public function create_sample_data() {
		// Temporarily elevate permissions.
		$current_user = wp_get_current_user();
		$old_user = $current_user;
		wp_set_current_user( 1 ); // Switch to admin user (ID 1).

		// Set up alert settings for demo data.
		$options = get_option( 'documentate_settings', array() );
		$options['alert_color'] = 'danger';
		$options['alert_message'] =
			'<strong>'
			. __( 'Warning', 'documentate' )
			. ':</strong> '
			. __( 'You are running this site with demo data.', 'documentate' );
		update_option( 'documentate_settings', $options );

		// Restore original user.
		wp_set_current_user( $old_user->ID );
	}
}
