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

use Resolate\DocType\SchemaExtractor;
use Resolate\DocType\SchemaStorage;
use Resolate\DocType\SchemaConverter;

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
		add_action( 'admin_post_resolate_reparse_schema', array( $this, 'handle_reparse_schema' ) );
	}

	/**
	 * Display stored notices for the taxonomy screens.
	 *
	 * @return void
	 */
	private function output_notices() {
		$flash_key = 'resolate_schema_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			$type = isset( $flash['type'] ) ? $flash['type'] : 'updated';
			add_settings_error(
				'resolate_doc_type',
				'resolate_schema_flash_' . uniqid(),
				$flash['message'],
				$type
			);
			delete_transient( $flash_key );
		}
		settings_errors( 'resolate_doc_type' );
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

		$term_id         = isset( $_GET['tag_ID'] ) ? intval( $_GET['tag_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$schema          = array();
		$schema_summary  = array();
		$template_id     = 0;
		$template_ext    = '';
		$schema_storage  = new SchemaStorage();
		if ( $term_id > 0 ) {
			$schema         = $schema_storage->get_schema( $term_id );
			$schema_summary = $schema_storage->get_summary( $term_id );
			$template_id    = intval( get_term_meta( $term_id, 'resolate_type_template_id', true ) );
			$template_ext   = sanitize_key( (string) get_term_meta( $term_id, 'resolate_type_template_type', true ) );
		}

		$schema_slugs = array();
		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			foreach ( $schema['fields'] as $item ) {
				if ( is_array( $item ) && ! empty( $item['slug'] ) ) {
					$schema_slugs[] = sanitize_key( $item['slug'] );
				}
			}
		}
		if ( isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ) {
			foreach ( $schema['repeaters'] as $repeater ) {
				if ( ! is_array( $repeater ) || empty( $repeater['fields'] ) || ! is_array( $repeater['fields'] ) ) {
					continue;
				}
				foreach ( $repeater['fields'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['slug'] ) ) {
						$schema_slugs[] = sanitize_key( $item['slug'] );
					}
				}
			}
		}

		$schema_v2 = array(
			'fields'    => array(),
			'repeaters' => array(),
			'meta'      => array(),
		);
		if ( is_array( $schema ) ) {
			$schema_v2 = $schema;
		}

		foreach ( $schema_slugs as $index => $slug_value ) {
			$schema_slugs[ $index ] = sanitize_key( $slug_value );
		}

		$schema_summary = is_array( $schema_summary ) ? $schema_summary : array();

				wp_localize_script(
					'resolate-doc-types',
					'resolateDocTypes',
					array(
						'ajax'        => array(
							'url'   => admin_url( 'admin-ajax.php' ),
							'nonce' => wp_create_nonce( 'resolate_doc_type_template' ),
						),
						'i18n'        => array(
							'select'         => __( 'Seleccionar archivo', 'resolate' ),
							'remove'         => __( 'Eliminar', 'resolate' ),
							'fieldsDetected' => __( 'Campos detectados', 'resolate' ),
							'noFields'       => __( 'No se encontraron campos en la plantilla.', 'resolate' ),
							'typeDocx'       => __( 'Plantilla DOCX', 'resolate' ),
							'typeOdt'        => __( 'Plantilla ODT', 'resolate' ),
							'typeUnknown'    => __( 'Formato desconocido', 'resolate' ),
							'diffAdded'      => __( 'Campos nuevos', 'resolate' ),
							'diffRemoved'    => __( 'Campos eliminados', 'resolate' ),
							/* translators: %d is replaced with the total number of fields detected. */
							'fieldCount'     => __( 'Total de campos: %d', 'resolate' ),
							/* translators: %s is replaced with a comma separated list of repeater names. */
							'repeaterList'   => __( 'Repetidores: %s', 'resolate' ),
							/* translators: %s is replaced with the datetime when the template was parsed. */
							'parsedAt'       => __( 'Analizado: %s', 'resolate' ),
						),
						'fieldTypes' => array(
							'text'    => __( 'Texto', 'resolate' ),
							'number'  => __( 'Número', 'resolate' ),
							'boolean' => __( 'Booleano', 'resolate' ),
							'date'    => __( 'Fecha', 'resolate' ),
						),
						'schema'       => $schema_slugs,
						'schemaV2'     => $schema_v2,
						'schemaSummary' => $schema_summary,
						'templateId'   => $template_id,
						'templateExt'  => $template_ext,
					)
				);
	}

	/**
	 * Render extra fields on the Add term screen.
	 *
	 * @return void
	 */
	public function add_fields() {
		$this->output_notices();
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
			<?php
			$storage = new SchemaStorage();
			$schema  = $storage->get_schema( 0 ); // Default empty schema.
			?>
			<div id="resolate_type_schema_preview" class="resolate-schema-preview" data-schema-v2="<?php echo esc_attr( wp_json_encode( $schema ) ); ?>" data-schema-summary="{}">
				<?php $this->render_schema_preview_fallback( $schema ); ?>
			</div>
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
		$this->output_notices();
		$color = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'resolate_type_color', true ) );
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		$template_id  = intval( get_term_meta( $term->term_id, 'resolate_type_template_id', true ) );
		$template_ext = sanitize_key( (string) get_term_meta( $term->term_id, 'resolate_type_template_type', true ) );
		$storage        = new SchemaStorage();
		$schema         = $storage->get_schema( $term->term_id );
		$schema_summary = $storage->get_summary( $term->term_id );
		$schema_json    = wp_json_encode( $schema ? $schema : array() );
		$summary_json   = wp_json_encode( $schema_summary ? $schema_summary : array() );
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
				<div id="resolate_type_schema_preview" class="resolate-schema-preview" data-schema-v2="<?php echo esc_attr( (string) $schema_json ); ?>" data-schema-summary="<?php echo esc_attr( (string) $summary_json ); ?>">
					<?php $this->render_schema_preview_fallback( $schema ); ?>
				</div>
				<?php if ( $template_id ) : ?>
					<p style="margin-top:8px;">
						<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=resolate_reparse_schema&term_id=' . $term->term_id ), 'resolate_reparse_schema_' . $term->term_id ) ); ?>">
							<?php esc_html_e( 'Volver a analizar plantilla', 'resolate' ); ?>
						</a>
					</p>
				<?php endif; ?>
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
		$color = isset( $_POST['resolate_type_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['resolate_type_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		update_term_meta( $term_id, 'resolate_type_color', $color );

		$template_id = isset( $_POST['resolate_type_template_id'] ) ? intval( $_POST['resolate_type_template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$template_id = max( 0, $template_id );
		update_term_meta( $term_id, 'resolate_type_template_id', $template_id > 0 ? $template_id : '' );

		$template_type = '';
		$storage       = new SchemaStorage();

		if ( $template_id > 0 ) {
			$path = get_attached_file( $template_id );
			if ( $path && file_exists( $path ) ) {
				$extractor = new SchemaExtractor();
				$schema    = $extractor->extract( $path );

				if ( is_wp_error( $schema ) ) {
					add_settings_error(
						'resolate_doc_type',
						'resolate_schema_error',
						$schema->get_error_message(),
						'error'
					);
					$this->clear_stored_schema( $term_id, $storage );
				} else {
					$schema['meta']['template_id'] = $template_id;
					$template_type                  = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : $this->detect_template_type( $path );
					$storage->save_schema( $term_id, $schema );
				}
			} else {
				add_settings_error(
					'resolate_doc_type',
					'resolate_schema_missing',
					__( 'The selected template file could not be located.', 'resolate' ),
					'error'
				);
				$this->clear_stored_schema( $term_id, $storage );
			}
		} else {
			$this->clear_stored_schema( $term_id, $storage );
		}

		update_term_meta( $term_id, 'resolate_type_template_type', $template_type );
	}

	/**
	 * Clear stored schema metadata.
	 *
	 * @param int                $term_id Term ID.
	 * @param SchemaStorage|null $storage Existing storage helper.
	 * @return void
	 */
	private function clear_stored_schema( $term_id, $storage = null ) {
		if ( null === $storage ) {
			$storage = new SchemaStorage();
		}
		$storage->delete_schema( $term_id );
		delete_term_meta( $term_id, 'schema' );
		delete_term_meta( $term_id, 'resolate_type_fields' );
	}

	/**
	 * Render a basic schema preview in PHP as fallback (before JS enhancement).
	 *
	 * @param array $schema Schema array.
	 * @return void
	 */
	private function render_schema_preview_fallback( $schema ) {
		if ( empty( $schema ) || ( empty( $schema['fields'] ) && empty( $schema['repeaters'] ) ) ) {
			echo '<p class="description resolate-schema-empty">' . esc_html__( 'No se encontraron campos en la plantilla.', 'resolate' ) . '</p>';
			return;
		}

		$legacy = SchemaConverter::to_legacy( $schema );
		if ( empty( $legacy ) ) {
			echo '<p class="description resolate-schema-empty">' . esc_html__( 'No se encontraron campos en la plantilla.', 'resolate' ) . '</p>';
			return;
		}

		echo '<ul class="resolate-schema-list">';
		foreach ( $legacy as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}
			$label = isset( $entry['label'] ) && '' !== $entry['label'] ? $entry['label'] : $entry['slug'];
			$type  = isset( $entry['type'] ) ? $entry['type'] : '';

			if ( 'array' === $type ) {
				echo '<li><strong>' . esc_html( $label ) . '</strong></li>';
				if ( isset( $entry['item_schema'] ) && is_array( $entry['item_schema'] ) ) {
					echo '<ul class="resolate-schema-list-nested">';
					foreach ( $entry['item_schema'] as $item_slug => $item ) {
						$item_label = isset( $item['label'] ) ? $item['label'] : $item_slug;
						$item_type  = isset( $item['type'] ) ? $item['type'] : '';
						echo '<li>' . esc_html( $item_label );
						if ( '' !== $item_type ) {
							echo ' <span class="resolate-field-type">(' . esc_html( $item_type ) . ')</span>';
						}
						echo '</li>';
					}
					echo '</ul>';
				}
				continue;
			}

			echo '<li>' . esc_html( $label );
			if ( '' !== $type ) {
				echo ' <span class="resolate-field-type">(' . esc_html( $type ) . ')</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
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

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'La plantilla seleccionada no se encuentra.', 'resolate' ) ) );
		}

		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			wp_send_json_error( array( 'message' => $schema->get_error_message() ) );
		}

		$schema['meta']['template_id'] = $attachment_id;

		$storage = new SchemaStorage();
		$type    = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : $this->detect_template_type( $path );
		$summary = $storage->summarize_schema( $schema );

		wp_send_json_success(
			array(
				'type'    => $type,
				'schema'  => $schema,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Handle manual schema reparse requests from the admin UI.
	 *
	 * @return void
	 */
	public function handle_reparse_schema() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		$term_id = isset( $_GET['term_id'] ) ? intval( $_GET['term_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $term_id <= 0 ) {
			wp_die( esc_html__( 'Identificador de tipo de documento inválido.', 'resolate' ) );
		}

		check_admin_referer( 'resolate_reparse_schema_' . $term_id );

		$template_id = intval( get_term_meta( $term_id, 'resolate_type_template_id', true ) );
		$redirect    = add_query_arg(
			array(
				'taxonomy' => 'resolate_doc_type',
				'tag_ID'   => $term_id,
			),
			admin_url( 'edit-tags.php' )
		);

		if ( $template_id <= 0 ) {
			$this->store_flash_message( __( 'No hay ninguna plantilla asociada a este tipo.', 'resolate' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$path = get_attached_file( $template_id );
		if ( ! $path || ! file_exists( $path ) ) {
			$this->store_flash_message( __( 'El archivo de plantilla no se encuentra.', 'resolate' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $path );

		if ( is_wp_error( $schema ) ) {
			$this->store_flash_message( $schema->get_error_message(), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		$schema['meta']['template_id'] = $template_id;

		$template_type = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : $this->detect_template_type( $path );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		update_term_meta( $term_id, 'resolate_type_template_type', $template_type );

		$this->store_flash_message( __( 'Esquema actualizado correctamente.', 'resolate' ), 'updated' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Persist a flash notice for the current user.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type (error|updated).
	 * @return void
	 */
	private function store_flash_message( $message, $type = 'updated' ) {
		$flash_key = 'resolate_schema_flash_' . get_current_user_id();
		set_transient(
			$flash_key,
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS
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
}

new Resolate_Doc_Types_Admin();
