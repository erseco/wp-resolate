<?php
/**
 * Admin UI for "Tipos de documento" taxonomy term meta.
 *
 * Configures a flat taxonomy with template, color and detected schema metadata.
 *
 * @package documentate
 * @subpackage Documentate/admin
 */

defined( 'ABSPATH' ) || exit();

use Documentate\DocType\SchemaConverter;
use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

/**
 * Manage taxonomy term meta and admin screens for document types.
 *
 * @package documentate
 * @subpackage Documentate/admin
 */
class Documentate_Doc_Types_Admin {
	/**
	 * Register hooks for taxonomy term meta management.
	 */
	public function __construct() {
		add_action( 'documentate_doc_type_add_form_fields', array( $this, 'add_fields' ) );
		add_action( 'documentate_doc_type_edit_form_fields', array( $this, 'edit_fields' ), 10, 2 );
		add_action( 'created_documentate_doc_type', array( $this, 'save_term' ) );
		add_action( 'edited_documentate_doc_type', array( $this, 'save_term' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_documentate_doc_type_template_fields', array( $this, 'ajax_template_fields' ) );
		add_action( 'admin_post_documentate_reparse_schema', array( $this, 'handle_reparse_schema' ) );
	}

	/**
	 * Display stored notices for the taxonomy screens.
	 *
	 * @return void
	 */
	private function output_notices() {
		$flash_key = 'documentate_schema_flash_' . get_current_user_id();
		$flash = get_transient( $flash_key );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			$type = isset( $flash['type'] ) ? $flash['type'] : 'updated';
			add_settings_error( 'documentate_doc_type', 'documentate_schema_flash_' . uniqid(), $flash['message'], $type );
			delete_transient( $flash_key );
		}
		settings_errors( 'documentate_doc_type' );
	}

	/**
	 * Enqueue media, color picker and JS for the taxonomy screens.
	 *
	 * @param string $hook Current hook suffix.
	 */
	public function enqueue_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-documentate_doc_type' !== $screen->id ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script(
			'documentate-doc-types',
			plugins_url( 'admin/js/documentate-doc-types.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery', 'underscore', 'wp-color-picker' ),
			DOCUMENTATE_VERSION,
			true,
		);
		wp_enqueue_style(
			'documentate-doc-types',
			plugins_url( 'admin/css/documentate-doc-types.css', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$term_id = isset( $_GET['tag_ID'] ) ? intval( $_GET['tag_ID'] ) : 0;
		wp_localize_script(
			'documentate-doc-types',
			'documentateDocTypes',
			$this->build_doc_types_script_data( $term_id )
		);
	}

	/**
	 * Build the localized data payload for the doc-types admin script.
	 *
	 * @param int $term_id Current term ID, or 0 on the add screen.
	 * @return array
	 */
	private function build_doc_types_script_data( $term_id ) {
		$schema_data = $this->load_term_schema_for_script( $term_id );

		return array(
			'ajax'          => array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'documentate_doc_type_template' ),
			),
			'i18n'          => $this->get_doc_types_script_i18n(),
			'fieldTypes'    => array(
				'text'    => __( 'Text', 'documentate' ),
				'number'  => __( 'Number', 'documentate' ),
				'boolean' => __( 'Boolean', 'documentate' ),
				'date'    => __( 'Date', 'documentate' ),
			),
			'schema'        => $schema_data['slugs'],
			'schemaV2'      => $schema_data['schema_v2'],
			'schemaSummary' => $schema_data['summary'],
			'templateId'    => $schema_data['template_id'],
			'templateExt'   => $schema_data['template_ext'],
		);
	}

	/**
	 * Load schema, summary and template meta used by the doc-types UI script.
	 *
	 * @param int $term_id Term ID.
	 * @return array{slugs: array, schema_v2: array, summary: array, template_id: int, template_ext: string}
	 */
	private function load_term_schema_for_script( $term_id ) {
		$schema        = array();
		$schema_summary = array();
		$template_id   = 0;
		$template_ext  = '';

		if ( $term_id > 0 ) {
			$schema_storage = new SchemaStorage();
			$schema         = $schema_storage->get_schema( $term_id );
			$schema_summary = $schema_storage->get_summary( $term_id );
			$template_id    = intval( get_term_meta( $term_id, 'documentate_type_template_id', true ) );
			$template_ext   = sanitize_key( (string) get_term_meta( $term_id, 'documentate_type_template_type', true ) );
		}

		$schema_v2 = is_array( $schema ) ? $schema : array(
			'fields'    => array(),
			'repeaters' => array(),
			'meta'      => array(),
		);

		return array(
			'slugs'        => $this->collect_schema_slugs( $schema_v2 ),
			'schema_v2'    => $schema_v2,
			'summary'      => is_array( $schema_summary ) ? $schema_summary : array(),
			'template_id'  => $template_id,
			'template_ext' => $template_ext,
		);
	}

	/**
	 * Collect field slugs from a v2 schema (top-level fields and repeater fields).
	 *
	 * @param array $schema Schema array.
	 * @return string[]
	 */
	private function collect_schema_slugs( $schema ) {
		$slugs = array();

		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			$slugs = array_merge( $slugs, $this->slugs_from_field_list( $schema['fields'] ) );
		}

		if ( isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ) {
			foreach ( $schema['repeaters'] as $repeater ) {
				if ( ! is_array( $repeater ) || empty( $repeater['fields'] ) || ! is_array( $repeater['fields'] ) ) {
					continue;
				}
				$slugs = array_merge( $slugs, $this->slugs_from_field_list( $repeater['fields'] ) );
			}
		}

		return array_map( 'sanitize_key', $slugs );
	}

	/**
	 * Extract slugs from a list of field definition arrays.
	 *
	 * @param array $fields Field definitions.
	 * @return string[]
	 */
	private function slugs_from_field_list( $fields ) {
		$slugs = array();
		foreach ( $fields as $item ) {
			if ( is_array( $item ) && ! empty( $item['slug'] ) ) {
				$slugs[] = sanitize_key( $item['slug'] );
			}
		}
		return $slugs;
	}

	/**
	 * Translation strings for the doc-types admin script.
	 *
	 * @return array<string, string>
	 */
	private function get_doc_types_script_i18n() {
		return array(
			'select'         => __( 'Select file', 'documentate' ),
			'remove'         => __( 'Remove', 'documentate' ),
			'fieldsDetected' => __( 'Detected fields', 'documentate' ),
			'noFields'       => __( 'No fields found in template.', 'documentate' ),
			'typeDocx'       => __( 'DOCX Template', 'documentate' ),
			'typeOdt'        => __( 'ODT Template', 'documentate' ),
			'typeUnknown'    => __( 'Unknown format', 'documentate' ),
			'diffAdded'      => __( 'New fields', 'documentate' ),
			'diffRemoved'    => __( 'Removed fields', 'documentate' ),
			/* translators: %d is replaced with the total number of fields detected. */
			'fieldCount'     => __( 'Total fields: %d', 'documentate' ),
			/* translators: %s is replaced with a comma separated list of repeater names. */
			'repeaterList'   => __( 'Repeaters: %s', 'documentate' ),
			/* translators: %s is replaced with the datetime when the template was parsed. */
			'parsedAt'       => __( 'Parsed: %s', 'documentate' ),
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
			<label for="documentate_type_color"><?php esc_html_e( 'Color', 'documentate' ); ?></label>
			<input type="text" id="documentate_type_color" name="documentate_type_color" class="documentate-color-field" value="#37517e" />
		</div>
		<div class="form-field">
			<label for="documentate_type_template_id"><?php esc_html_e( 'Template', 'documentate' ); ?></label>
			<input type="hidden" id="documentate_type_template_id" name="documentate_type_template_id" value="" />
			<div id="documentate_type_template_preview" class="documentate-template-preview"></div>
			<p class="description"><?php esc_html_e( 'Select an .odt or .docx file with OpenTBS markers.', 'documentate' ); ?></p>
			<button type="button" class="button documentate-template-select" data-allowed="application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
			<?php
			esc_html_e(
				'Select template',
				'documentate',
			);
			?>
			</button>
			<p class="documentate-template-type" data-default="<?php echo esc_attr__( 'No template selected', 'documentate' ); ?>"></p>
		</div>
		<div class="form-field">
			<label for="<?php echo esc_attr( Documentate_Pdf_Layout::META_KEY ); ?>"><?php esc_html_e( 'PDF layout', 'documentate' ); ?></label>
			<?php $this->pdf_layout_field()->render( Documentate_Pdf_Layout::DEFAULT_SLUG ); ?>
		</div>
		<div class="form-field">
			<label><?php esc_html_e( 'Detected fields', 'documentate' ); ?></label>
			<?php

			$storage = new SchemaStorage();
			$schema = $storage->get_schema( 0 ); // Default empty schema.
			?>
			<div id="documentate_type_schema_preview" class="documentate-schema-preview" data-schema-v2="
			<?php
			echo esc_attr( wp_json_encode( $schema ) );
			?>
			" data-schema-summary="{}">
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
		$color = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'documentate_type_color', true ) );
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		$template_id = intval( get_term_meta( $term->term_id, 'documentate_type_template_id', true ) );
		$template_ext = sanitize_key( (string) get_term_meta( $term->term_id, 'documentate_type_template_type', true ) );
		$storage = new SchemaStorage();
		$schema = $storage->get_schema( $term->term_id );
		$schema_summary = $storage->get_summary( $term->term_id );
		$schema_json = wp_json_encode( $schema ? $schema : array() );
		$summary_json = wp_json_encode( $schema_summary ? $schema_summary : array() );
		$template_name = $template_id ? basename( (string) get_attached_file( $template_id ) ) : '';
		$pdf_layout = $this->pdf_layout_field()->stored( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><label for="documentate_type_color"><?php esc_html_e( 'Color', 'documentate' ); ?></label></th>
			<td>
				<input type="text" id="documentate_type_color" name="documentate_type_color" class="documentate-color-field" value="
				<?php
				echo esc_attr( $color );
				?>
				" />
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="documentate_type_template_id"><?php esc_html_e( 'Template', 'documentate' ); ?></label></th>
			<td>
				<input type="hidden" id="documentate_type_template_id" name="documentate_type_template_id" value="
				<?php
				echo esc_attr( (string) $template_id );
				?>
				" />
				<div id="documentate_type_template_preview" class="documentate-template-preview">
				<?php
				echo $template_name ? esc_html( $template_name ) : '';
				?>
				</div>
				<p class="description"><?php esc_html_e( 'Select an .odt or .docx file with OpenTBS markers.', 'documentate' ); ?></p>
				<button type="button" class="button documentate-template-select" data-allowed="application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
				<?php
				esc_html_e(
					'Select template',
					'documentate',
				);
				?>
				</button>
				<p class="documentate-template-type" data-default="<?php echo esc_attr__( 'No template selected', 'documentate' ); ?>" data-current="
				<?php
				echo esc_attr( $template_ext );
				?>
				"></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="<?php echo esc_attr( Documentate_Pdf_Layout::META_KEY ); ?>"><?php esc_html_e( 'PDF layout', 'documentate' ); ?></label></th>
			<td>
				<?php $this->pdf_layout_field()->render( $pdf_layout ); ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label><?php esc_html_e( 'Detected fields', 'documentate' ); ?></label></th>
			<td>
				<div id="documentate_type_schema_preview" class="documentate-schema-preview" data-schema-v2="
				<?php
				echo esc_attr( (string) $schema_json );
				?>
				" data-schema-summary="<?php echo esc_attr( (string) $summary_json ); ?>">
					<?php $this->render_schema_preview_fallback( $schema ); ?>
				</div>
				<?php if ( $template_id ) : ?>
					<p style="margin-top:8px;">
						<a class="button button-secondary" href="
						<?php
						echo esc_url(
							wp_nonce_url(
								admin_url( 'admin-post.php?action=documentate_reparse_schema&term_id=' . $term->term_id ),
								'documentate_reparse_schema_' . $term->term_id,
							)
						);
						?>
						">
							<?php esc_html_e( 'Re-parse template', 'documentate' ); ?>
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
		$term_id = absint( $term_id );
		if ( ! $this->verify_term_save_nonce( $term_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_term_save_nonce().
		$color = isset( $_POST['documentate_type_color'] )
			? sanitize_hex_color( wp_unslash( $_POST['documentate_type_color'] ) )
			: '';
		if ( empty( $color ) ) {
			$color = '#37517e';
		}
		update_term_meta( $term_id, 'documentate_type_color', $color );

		$template_id = isset( $_POST['documentate_type_template_id'] )
			? intval( wp_unslash( $_POST['documentate_type_template_id'] ) )
			: 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// Reject negatives and zero as "no template".
		$template_id = max( 0, $template_id );
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id > 0 ? $template_id : '' );

		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, $this->pdf_layout_field()->submitted() );

		$storage       = new SchemaStorage();
		$template_type = $this->save_term_template_schema( $term_id, $template_id, $storage );
		update_term_meta( $term_id, 'documentate_type_template_type', $template_type );
	}

	/**
	 * The "PDF layout" field of both taxonomy forms.
	 *
	 * @return Documentate_Doc_Type_Pdf_Layout_Field
	 */
	private function pdf_layout_field() {
		return new Documentate_Doc_Type_Pdf_Layout_Field();
	}

	/**
	 * Verify core taxonomy nonces for term meta saves.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	private function verify_term_save_nonce( $term_id ) {
		if ( isset( $_POST['_wpnonce'] ) ) {
			return (bool) wp_verify_nonce(
				sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ),
				'update-tag_' . $term_id
			);
		}
		if ( isset( $_POST['_wpnonce_add-tag'] ) ) {
			return (bool) wp_verify_nonce(
				sanitize_key( wp_unslash( $_POST['_wpnonce_add-tag'] ) ),
				'add-tag'
			);
		}
		return false;
	}

	/**
	 * Extract and store schema for the selected template attachment.
	 *
	 * @param int           $term_id     Term ID.
	 * @param int           $template_id Attachment ID (0 clears schema).
	 * @param SchemaStorage $storage     Schema storage helper.
	 * @return string Detected template type, or empty string.
	 */
	private function save_term_template_schema( $term_id, $template_id, $storage ) {
		if ( $template_id <= 0 ) {
			$this->clear_stored_schema( $term_id, $storage );
			return '';
		}

		$path = get_attached_file( $template_id );
		if ( ! $path || ! file_exists( $path ) ) {
			add_settings_error(
				'documentate_doc_type',
				'documentate_schema_missing',
				__( 'The selected template file could not be located.', 'documentate' ),
				'error'
			);
			$this->clear_stored_schema( $term_id, $storage );
			return '';
		}

		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			add_settings_error( 'documentate_doc_type', 'documentate_schema_error', $schema->get_error_message(), 'error' );
			$this->clear_stored_schema( $term_id, $storage );
			return '';
		}

		$schema['meta']['template_id'] = $template_id;
		$template_type                 = isset( $schema['meta']['template_type'] )
			? (string) $schema['meta']['template_type']
			: $this->detect_template_type( $path );
		$storage->save_schema( $term_id, $schema );

		return $template_type;
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
		delete_term_meta( $term_id, 'documentate_type_fields' );
	}

	/**
	 * Render a basic schema preview in PHP as fallback (before JS enhancement).
	 *
	 * @param array $schema Schema array.
	 * @return void
	 */
	private function render_schema_preview_fallback( $schema ) {
		$legacy = $this->schema_to_legacy_preview_list( $schema );
		if ( empty( $legacy ) ) {
			$this->render_schema_preview_empty();
			return;
		}

		echo '<ul class="documentate-schema-list">';
		foreach ( $legacy as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}
			$this->render_schema_preview_entry( $entry );
		}
		echo '</ul>';
	}

	/**
	 * Convert a v2 schema into a legacy field list for the PHP preview, or empty.
	 *
	 * @param array $schema Schema array.
	 * @return array
	 */
	private function schema_to_legacy_preview_list( $schema ) {
		if ( empty( $schema ) || ( empty( $schema['fields'] ) && empty( $schema['repeaters'] ) ) ) {
			return array();
		}

		$legacy = SchemaConverter::to_legacy( $schema );
		return is_array( $legacy ) ? $legacy : array();
	}

	/**
	 * Echo the empty-schema preview message.
	 *
	 * @return void
	 */
	private function render_schema_preview_empty() {
		echo '<p class="description documentate-schema-empty">'
			. esc_html__( 'No fields found in template.', 'documentate' )
			. '</p>';
	}

	/**
	 * Render one schema preview list entry (scalar or array/repeater).
	 *
	 * @param array $entry Legacy field definition.
	 * @return void
	 */
	private function render_schema_preview_entry( $entry ) {
		$label = isset( $entry['label'] ) && '' !== $entry['label'] ? $entry['label'] : $entry['slug'];
		$type  = isset( $entry['type'] ) ? (string) $entry['type'] : '';

		if ( 'array' === $type ) {
			$this->render_schema_preview_array_entry( $label, $entry );
			return;
		}

		echo '<li>' . esc_html( $label );
		$this->render_schema_preview_type_badge( $type );
		echo '</li>';
	}

	/**
	 * Render a repeater/array field and its nested item schema.
	 *
	 * @param string $label Entry label.
	 * @param array  $entry Field definition with optional item_schema.
	 * @return void
	 */
	private function render_schema_preview_array_entry( $label, $entry ) {
		echo '<li><strong>' . esc_html( $label ) . '</strong></li>';

		if ( empty( $entry['item_schema'] ) || ! is_array( $entry['item_schema'] ) ) {
			return;
		}

		echo '<ul class="documentate-schema-list-nested">';
		foreach ( $entry['item_schema'] as $item_slug => $item ) {
			$item_label = isset( $item['label'] ) ? $item['label'] : $item_slug;
			$item_type  = isset( $item['type'] ) ? (string) $item['type'] : '';
			echo '<li>' . esc_html( $item_label );
			$this->render_schema_preview_type_badge( $item_type );
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render a parenthetical field-type badge when a type is present.
	 *
	 * @param string $type Field type slug.
	 * @return void
	 */
	private function render_schema_preview_type_badge( $type ) {
		if ( '' === $type ) {
			return;
		}
		echo ' <span class="documentate-field-type">(' . esc_html( $type ) . ')</span>';
	}

	/**
	 * AJAX handler to preview template fields.
	 *
	 * @return void
	 */
	public function ajax_template_fields() {
		check_ajax_referer( 'documentate_doc_type_template', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'documentate' ) ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( $attachment_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid template ID.', 'documentate' ) ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			wp_send_json_error( array( 'message' => __( 'Selected template not found.', 'documentate' ) ) );
		}

		$extractor = new SchemaExtractor();
		$schema = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			wp_send_json_error( array( 'message' => $schema->get_error_message() ) );
		}

		$schema['meta']['template_id'] = $attachment_id;

		$storage = new SchemaStorage();
		$type = isset( $schema['meta']['template_type'] )
			? (string) $schema['meta']['template_type']
			: $this->detect_template_type( $path );
		$summary = $storage->summarize_schema( $schema );

		wp_send_json_success(
			array(
				'type' => $type,
				'schema' => $schema,
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
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		$term_id = isset( $_GET['term_id'] ) ? intval( $_GET['term_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $term_id <= 0 ) {
			wp_die( esc_html__( 'Invalid document type ID.', 'documentate' ) );
		}

		check_admin_referer( 'documentate_reparse_schema_' . $term_id );

		$template_id = intval( get_term_meta( $term_id, 'documentate_type_template_id', true ) );
		$redirect = add_query_arg(
			array(
				'taxonomy' => 'documentate_doc_type',
				'tag_ID' => $term_id,
			),
			admin_url( 'edit-tags.php' )
		);

		if ( $template_id <= 0 ) {
			$this->store_flash_message( __( 'No template associated with this type.', 'documentate' ), 'error' );
			wp_safe_redirect( $redirect );
			exit();
		}

		$path = get_attached_file( $template_id );
		if ( ! $path || ! file_exists( $path ) ) {
			$this->store_flash_message( __( 'Template file not found.', 'documentate' ), 'error' );
			wp_safe_redirect( $redirect );
			exit();
		}

		$extractor = new SchemaExtractor();
		$schema = $extractor->extract( $path );

		if ( is_wp_error( $schema ) ) {
			$this->store_flash_message( $schema->get_error_message(), 'error' );
			wp_safe_redirect( $redirect );
			exit();
		}

		$schema['meta']['template_id'] = $template_id;

		$template_type = isset( $schema['meta']['template_type'] )
			? (string) $schema['meta']['template_type']
			: $this->detect_template_type( $path );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		update_term_meta( $term_id, 'documentate_type_template_type', $template_type );

		$this->store_flash_message( __( 'Schema updated successfully.', 'documentate' ), 'updated' );
		wp_safe_redirect( $redirect );
		exit();
	}

	/**
	 * Persist a flash notice for the current user.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type (error|updated).
	 * @return void
	 */
	private function store_flash_message( $message, $type = 'updated' ) {
		$flash_key = 'documentate_schema_flash_' . get_current_user_id();
		set_transient(
			$flash_key,
			array(
				'message' => $message,
				'type' => $type,
			),
			MINUTE_IN_SECONDS,
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

new Documentate_Doc_Types_Admin();
