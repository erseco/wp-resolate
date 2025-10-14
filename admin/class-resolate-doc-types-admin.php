<?php
/**
 * Admin UI for "Tipos de documento" taxonomy term meta.
 *
 * Configures a flat taxonomy with template, color and detected schema metadata.
 *
 * @package resolate
 * @subpackage Resolate/admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage taxonomy term meta and admin screens for document types.
 *
 * @package resolate
 * @subpackage Resolate/admin
 */
class Resolate_Doc_Types_Admin {

	/**
	 * Register hooks for taxonomy term meta management.
	 */
	public function __construct() {
		add_action( 'resolate_doc_type_add_form_fields', array( $this, 'add_fields' ) );
		add_action( 'resolate_doc_type_edit_form_fields', array( $this, 'edit_fields' ), 10, 2 );
		add_action( 'created_resolate_doc_type', array( $this, 'save_term' ) );
		add_action( 'edited_resolate_doc_type', array( $this, 'save_term' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_resolate_doc_type_template_fields', array( $this, 'ajax_template_fields' ) );
	}

	/**
	 * Enqueue media, color picker and JS for the taxonomy screens.
	 *
	 * @param string $hook Current hook suffix.
	 */
	public function enqueue_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-resolate_doc_type' !== $screen->id ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'resolate-doc-types',
			plugins_url( 'admin/js/resolate-doc-types.js', RESOLATE_PLUGIN_FILE ),
			array( 'jquery', 'underscore', 'wp-color-picker' ),
			RESOLATE_VERSION,
			true
		);
		wp_enqueue_style(
			'resolate-doc-types',
			plugins_url( 'admin/css/resolate-doc-types.css', RESOLATE_PLUGIN_FILE ),
			array(),
			RESOLATE_VERSION
		);

		$term_id      = isset( $_GET['tag_ID'] ) ? intval( $_GET['tag_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$schema       = array();
		$template_id  = 0;
		$template_ext = '';
		if ( $term_id > 0 ) {
			$schema = get_term_meta( $term_id, 'schema', true );
			if ( ! is_array( $schema ) ) {
				$schema = get_term_meta( $term_id, 'resolate_type_fields', true );
				if ( ! is_array( $schema ) ) {
					$schema = array();
				}
			}
			$template_id  = intval( get_term_meta( $term_id, 'resolate_type_template_id', true ) );
			$template_ext = sanitize_key( (string) get_term_meta( $term_id, 'resolate_type_template_type', true ) );
		}

		$schema_slugs = array();
		foreach ( $schema as $item ) {
			if ( is_array( $item ) && ! empty( $item['slug'] ) ) {
				$schema_slugs[] = sanitize_key( $item['slug'] );
			}
		}

		wp_localize_script(
			'resolate-doc-types',
			'resolateDocTypes',
			array(
				'ajax'       => array(
					'url'   => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'resolate_doc_type_template' ),
				),
				'i18n'       => array(
					'select'         => __( 'Seleccionar archivo', 'resolate' ),
					'remove'         => __( 'Eliminar', 'resolate' ),
					'fieldsDetected' => __( 'Campos detectados', 'resolate' ),
					'noFields'       => __( 'No se encontraron campos en la plantilla.', 'resolate' ),
					'typeDocx'       => __( 'Plantilla DOCX', 'resolate' ),
					'typeOdt'        => __( 'Plantilla ODT', 'resolate' ),
					'typeUnknown'    => __( 'Formato desconocido', 'resolate' ),
					'diffAdded'      => __( 'Campos nuevos', 'resolate' ),
					'diffRemoved'    => __( 'Campos eliminados', 'resolate' ),
				),
				'schema'     => $schema_slugs,
				'templateId' => $template_id,
				'templateExt' => $template_ext,
			)
		);
	}

	/**
	 * Render extra fields on the Add term screen.
	 *
	 * @return void
	 */
	public function add_fields() {
		?>
		<div class="form-field">
			<label for="resolate_type_color"><?php esc_html_e( 'Color', 'resolate' ); ?></label>
			<input type="text" id="resolate_type_color" name="resolate_type_color" class="resolate-color-field" value="#37517e" />
		</div>
		<div class="form-field">
			<label for="resolate_type_template_id"><?php esc_html_e( 'Plantilla', 'resolate' ); ?></label>
			<input type="hidden" id="resolate_type_template_id" name="resolate_type_template_id" value="" />
			<div id="resolate_type_template_preview" class="resolate-template-preview"></div>
			<p class="description"><?php esc_html_e( 'Selecciona un archivo .odt o .docx con marcadores OpenTBS.', 'resolate' ); ?></p>
			<button type="button" class="button resolate-template-select" data-allowed="application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><?php esc_html_e( 'Seleccionar plantilla', 'resolate' ); ?></button>
			<p class="resolate-template-type" data-default="<?php echo esc_attr__( 'Sin plantilla seleccionada', 'resolate' ); ?>"></p>
		</div>
		<div class="form-field">
			<label><?php esc_html_e( 'Campos detectados', 'resolate' ); ?></label>
			<div id="resolate_type_schema_preview" class="resolate-schema-preview" data-schema="[]"></div>
		</div>
		<?php
	}

	/**
	 * Render extra fields on the Edit term screen.
	 *
	 * @param WP_Term $term     Term instance.
	 * @param string  $taxonomy Current taxonomy slug.
	 *
	 * @return void
	 */
	public function edit_fields( $term, $taxonomy ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$color = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'resolate_type_color', true ) );
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		$template_id  = intval( get_term_meta( $term->term_id, 'resolate_type_template_id', true ) );
		$template_ext = sanitize_key( (string) get_term_meta( $term->term_id, 'resolate_type_template_type', true ) );
		$schema       = get_term_meta( $term->term_id, 'schema', true );
		if ( ! is_array( $schema ) ) {
			$schema = get_term_meta( $term->term_id, 'resolate_type_fields', true );
			if ( ! is_array( $schema ) ) {
				$schema = array();
			}
		}
		$schema_json   = wp_json_encode( $schema );
		$template_name = $template_id ? basename( (string) get_attached_file( $template_id ) ) : '';
		?>
		<tr class="form-field">
			<th scope="row"><label for="resolate_type_color"><?php esc_html_e( 'Color', 'resolate' ); ?></label></th>
			<td>
				<input type="text" id="resolate_type_color" name="resolate_type_color" class="resolate-color-field" value="<?php echo esc_attr( $color ); ?>" />
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="resolate_type_template_id"><?php esc_html_e( 'Plantilla', 'resolate' ); ?></label></th>
			<td>
				<input type="hidden" id="resolate_type_template_id" name="resolate_type_template_id" value="<?php echo esc_attr( (string) $template_id ); ?>" />
				<div id="resolate_type_template_preview" class="resolate-template-preview"><?php echo $template_name ? esc_html( $template_name ) : ''; ?></div>
				<p class="description"><?php esc_html_e( 'Selecciona un archivo .odt o .docx con marcadores OpenTBS.', 'resolate' ); ?></p>
				<button type="button" class="button resolate-template-select" data-allowed="application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><?php esc_html_e( 'Seleccionar plantilla', 'resolate' ); ?></button>
				<p class="resolate-template-type" data-default="<?php echo esc_attr__( 'Sin plantilla seleccionada', 'resolate' ); ?>" data-current="<?php echo esc_attr( $template_ext ); ?>"></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Campos detectados', 'resolate' ); ?></label></th>
			<td>
				<div id="resolate_type_schema_preview" class="resolate-schema-preview" data-schema="<?php echo esc_attr( $schema_json ); ?>"></div>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term meta for document type.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return void
	 */
	public function save_term( $term_id ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-template-parser.php';

		$color = isset( $_POST['resolate_type_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['resolate_type_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		update_term_meta( $term_id, 'resolate_type_color', $color );

		$template_id = isset( $_POST['resolate_type_template_id'] ) ? intval( $_POST['resolate_type_template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$template_id = max( 0, $template_id );
		update_term_meta( $term_id, 'resolate_type_template_id', $template_id > 0 ? $template_id : '' );

		$template_type = '';
		$schema        = get_term_meta( $term_id, 'schema', true );
		if ( ! is_array( $schema ) ) {
			$schema = array();
		}

		if ( $template_id > 0 ) {
			$path   = get_attached_file( $template_id );
			$fields = Resolate_Template_Parser::extract_fields( $path );
			if ( ! is_wp_error( $fields ) ) {
				$template_type = $this->detect_template_type( $path );
				$schema        = $this->build_schema_from_fields( $fields );
			}
		}

		update_term_meta( $term_id, 'resolate_type_template_type', $template_type );
		update_term_meta( $term_id, 'schema', $schema );
		update_term_meta( $term_id, 'resolate_type_fields', $schema );
	}

	/**
	 * AJAX handler to preview template fields.
	 *
	 * @return void
	 */
	public function ajax_template_fields() {
		check_ajax_referer( 'resolate_doc_type_template', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'resolate' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( $attachment_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Identificador de plantilla inválido.', 'resolate' ) ) );
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-template-parser.php';

		$path   = get_attached_file( $attachment_id );
		$fields = Resolate_Template_Parser::extract_fields( $path );
		if ( is_wp_error( $fields ) ) {
			wp_send_json_error( array( 'message' => $fields->get_error_message() ) );
		}

		$type = $this->detect_template_type( $path );
		wp_send_json_success(
			array(
				'type'   => $type,
				'fields' => $fields,
			)
		);
	}

	/**
	 * Detect template type (odt/docx) from file path.
	 *
	 * @param string $path File path.
	 *
	 * @return string
	 */
	private function detect_template_type( $path ) {
		$ext = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'docx', 'odt' ), true ) ) {
			return $ext;
		}
		return '';
	}

	/**
	 * Build schema array from placeholder slugs.
	 *
	 * @param array $fields Placeholder slugs.
	 *
	 * @return array[]
	 */
	private function build_schema_from_fields( $fields ) {
		$schema = array();
		foreach ( $fields as $field ) {
			$slug = sanitize_key( $field );
			if ( '' === $slug ) {
				continue;
			}
			$schema[] = array(
				'slug'  => $slug,
				'label' => $this->humanize_slug( $slug ),
				'type'  => 'textarea',
			);
		}
		return $schema;
	}

	/**
	 * Convert slug into a human readable label.
	 *
	 * @param string $slug Slug.
	 *
	 * @return string
	 */
	private function humanize_slug( $slug ) {
		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = preg_replace( '/\s+/', ' ', $slug );
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( strtolower( $slug ) );
	}
}

new Resolate_Doc_Types_Admin();
