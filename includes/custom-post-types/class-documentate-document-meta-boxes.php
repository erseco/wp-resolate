<?php
/**
 * Editor metabox rendering for Documentate documents.
 *
 * Extracted from Documentate_Documents, which registered the metaboxes and drew
 * every control in the editor as well as saving them and building the admin
 * list. Rendering is the largest of those jobs and shares nothing with the
 * others beyond the field-value helpers on Documents_Meta_Handler.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Renders the document editor's metaboxes and their field controls.
 */
class Documentate_Document_Meta_Boxes {

	/**
	 * Register admin meta boxes for document sections.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'documentate_sections',
			__( 'Document Sections', 'documentate' ),
			array( $this, 'render_sections_metabox' ),
			'documentate_document',
			'normal',
			'high',
		);

		if ( post_type_supports( 'documentate_document', 'comments' ) ) {
			add_meta_box( 'commentsdiv', __( 'Comments', 'default' ), 'post_comment_meta_box', 'documentate_document', 'normal', 'core' );
		}

		// Move author metabox to side with low priority.
		remove_meta_box( 'authordiv', 'documentate_document', 'normal' );
		add_meta_box( 'authordiv', __( 'Author', 'documentate' ), 'post_author_meta_box', 'documentate_document', 'side', 'low' );
	}
	/**
	 * Render the document type selector metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_type_metabox( $post ) {
		wp_nonce_field( 'documentate_type_nonce', 'documentate_type_nonce' );

		$assigned = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$current = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);

		if ( ! $terms || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html__( 'No document types defined. Create one in Document Types.', 'documentate' ) . '</p>';
			return;
		}

		$locked = $current > 0 && 'auto-draft' !== $post->post_status;
		echo '<p class="description">'
				. esc_html__( 'Choose the type when creating the document. It cannot be changed later.', 'documentate' )
				. '</p>';
		if ( $locked ) {
			$term = get_term( $current, 'documentate_doc_type' );
			echo '<p><strong>'
					. esc_html__( 'Selected type:', 'documentate' )
					. '</strong> '
					. esc_html( $term ? $term->name : '' )
					. '</p>';
			echo '<input type="hidden" name="documentate_doc_type" value="' . esc_attr( (string) $current ) . '" />';
		} else {
			echo '<select name="documentate_doc_type" class="widefat">';
			echo '<option value="">' . esc_html__( 'Select a type…', 'documentate' ) . '</option>';
			foreach ( $terms as $t ) {
				echo '<option value="'
						. esc_attr( (string) $t->term_id )
						. '" '
						. selected( $current, $t->term_id, false )
						. '>'
						. esc_html( $t->name )
						. '</option>';
			}
			echo '</select>';
		}
	}
	/**
	 * Render the sections meta box (dynamic by document type, with legacy fallback).
	 *
	 * The front-end application reuses this renderer and needs its own layout
	 * around the rol groups: the área rows folded into a <details> and its
	 * "Anotaciones internas" box inside the gestión section. It passes that
	 * markup in $envoltorios; wp-admin passes nothing (WordPress hands the
	 * metabox definition to the callback, and none of its keys is read) and
	 * gets the flat layout it has always had.
	 *
	 * @param WP_Post $post        Current post.
	 * @param array   $envoltorios Markup printed around a group that has visible
	 *                             rows: area_abrir, area_cerrar, gestion_cerrar.
	 * @return void
	 */
	public function render_sections_metabox( $post, array $envoltorios = array() ) {
		$envoltorios += array(
			'area_abrir' => '',
			'area_cerrar' => '',
			'gestion_cerrar' => '',
		);

		wp_nonce_field( 'documentate_sections_nonce', 'documentate_sections_nonce' );

		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );

		if ( empty( $schema ) ) {
			echo '<div class="documentate-sections">';
			echo '<p class="description">'
					. esc_html__( 'Configure a document type with fields to edit its content.', 'documentate' )
					. '</p>';
			$unknown = $this->collect_unknown_dynamic_fields( $post->ID, array() );
			$this->render_unknown_dynamic_fields_ui( $unknown );
			echo '</div>';
			return;
		}

		// The raw schema exposes placeholders, constraints and help text.
		$raw_schema = $this->get_raw_schema_for_post( $post->ID );
		$context = array(
			'post' => $post,
			'raw_schema' => $raw_schema,
			'raw_fields' => isset( $raw_schema['fields'] ) && is_array( $raw_schema['fields'] ) ? $raw_schema['fields'] : array(),
			'stored_fields' => Documents_Meta_Handler::get_structured_field_values( $post->ID ),
		);
		$grupos = Documentate_Campos_Rol::agrupar( $schema );

		echo '<div class="documentate-sections">';
		$known_meta_keys = array_merge(
			$this->render_rows_by_rol( $grupos['area'], $context, '', $envoltorios['area_abrir'], $envoltorios['area_cerrar'] ),
			$this->render_rows_by_rol( $grupos['gestion'], $context, 'Datos oficiales · los completa gestión documental', '', $envoltorios['gestion_cerrar'] )
		);

		$unknown = $this->collect_unknown_dynamic_fields( $post->ID, $known_meta_keys );
		$this->render_unknown_dynamic_fields_ui( $unknown );
		echo '</div>';
	}
	/**
	 * Render the rows of one rol group the current user may see.
	 *
	 * Rows the user cannot see are not drawn, but their meta keys are still
	 * claimed so their stored values never surface as unknown fields.
	 *
	 * @param array  $rows    Schema rows of the group.
	 * @param array  $context Post, raw schema, raw fields and stored values.
	 * @param string $heading Heading drawn above the group, or '' for none.
	 * @param string $abrir   Markup printed before the group, already escaped.
	 * @param string $cerrar  Markup printed after the group, already escaped.
	 * @return string[] Meta keys claimed by the group.
	 */
	private function render_rows_by_rol( array $rows, array $context, $heading, $abrir = '', $cerrar = '' ) {
		$known_meta_keys = array();
		$visible = array();

		foreach ( $rows as $row ) {
			if ( Documentate_Campos_Rol::puede_ver( $row ) ) {
				$visible[] = $row;
			} elseif ( ! empty( $row['slug'] ) ) {
				$known_meta_keys[] = 'documentate_field_' . sanitize_key( $row['slug'] );
			}
		}

		if ( empty( $visible ) ) {
			return $known_meta_keys;
		}

		// The wrappers come from the application and are already escaped.
		echo $abrir; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( '' !== $heading ) {
			echo '<h3 class="documentate-seccion-rol">' . esc_html( $heading ) . '</h3>';
		}

		echo '<table class="form-table"><tbody>';
		foreach ( $visible as $row ) {
			$meta_key = $this->render_schema_row( $context['post'], $row, $context['raw_schema'], $context['raw_fields'], $context['stored_fields'] );
			if ( '' !== $meta_key ) {
				$known_meta_keys[] = $meta_key;
			}
		}
		echo '</tbody></table>';

		echo $cerrar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return $known_meta_keys;
	}
	/**
	 * CSS class marking a row gestión documental fills in.
	 *
	 * @param array $field Prepared field from prepare_schema_row().
	 * @return string Leading-space class, or '' for área rows.
	 */
	private function rol_css_class( array $field ) {
		return isset( $field['rol'] ) && Documentate_Campos_Rol::ROL_GESTION === $field['rol'] ? ' documentate-campo-gestion' : '';
	}
	/**
	 * Collect meta values whose keys start with documentate_field_ but are not part of the schema.
	 *
	 * @param int   $post_id         Post ID.
	 * @param array $known_meta_keys Dynamic meta keys defined by the current schema.
	 * @return array[] Array keyed by meta key with value/source data.
	 */
	private function collect_unknown_dynamic_fields( $post_id, $known_meta_keys ) {
		$known_lookup = array();
		if ( ! empty( $known_meta_keys ) ) {
			foreach ( $known_meta_keys as $meta_key ) {
				$known_lookup[ $meta_key ] = true;
			}
		}

		$unknown = array();
		$prefix = 'documentate_field_';

		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! is_string( $key ) || ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			if ( isset( $known_lookup[ $key ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}
			$unknown[ $key ] = array(
				'value' => wp_unslash( $value ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'source' => 'post',
			);
		}

		if ( $post_id > 0 ) {
			$stored = Documents_Meta_Handler::get_structured_field_values( $post_id );
			if ( ! empty( $stored ) ) {
				foreach ( $stored as $slug => $info ) {
					$meta_key = $prefix . sanitize_key( $slug );
					if ( isset( $known_lookup[ $meta_key ] ) || isset( $unknown[ $meta_key ] ) ) {
						continue;
					}
					$value = isset( $info['value'] ) ? (string) $info['value'] : '';
					$unknown[ $meta_key ] = array(
						'value' => $value,
						'source' => 'content',
					);
				}
			}
		}

		return $unknown;
	}
	/**
	 * Document type term assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Term ID, or 0 when the post has no type.
	 */
	private function get_assigned_doc_type_id( $post_id ) {
		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );

		return ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;
	}
	/**
	 * Retrieve raw schema data for the current document type.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,array>> Indexed schema details.
	 */
	private function get_raw_schema_for_post( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$term_id = $this->get_assigned_doc_type_id( $post_id );
		if ( $term_id <= 0 ) {
			return array();
		}

		$storage = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );
		if ( ! is_array( $schema_v2 ) ) {
			return array();
		}

		return array(
			'fields' => $this->index_schema_entries(
				isset( $schema_v2['fields'] ) ? $schema_v2['fields'] : array()
			),
			'repeaters' => $this->index_schema_repeaters(
				isset( $schema_v2['repeaters'] ) ? $schema_v2['repeaters'] : array()
			),
		);
	}
	/**
	 * Read the stored rows of a repeater, falling back to one blank row.
	 *
	 * The editor always shows at least one row, so an empty repeater still has
	 * something to type into.
	 *
	 * @param string $slug          Repeater slug.
	 * @param array  $stored_fields Structured values stored on the post.
	 * @return array
	 */
	private function get_repeater_rows( $slug, $stored_fields ) {
		$items = array();
		if (
			isset( $stored_fields[ $slug ] )
			&& isset( $stored_fields[ $slug ]['type'] )
			&& 'array' === $stored_fields[ $slug ]['type']
		) {
			$items = Documents_Meta_Handler::get_array_field_items_from_structured( $stored_fields[ $slug ] );
		}

		return empty( $items ) ? array( array() ) : $items;
	}
	/**
	 * Index schema entries by slug, dropping any that cannot be identified.
	 *
	 * @param mixed $entries Raw entry list from the stored schema.
	 * @return array<string,array>
	 */
	private function index_schema_entries( $entries ) {
		$index = array();

		if ( ! is_array( $entries ) ) {
			return $index;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slug = $this->schema_entry_slug( $entry );
			if ( '' === $slug ) {
				continue;
			}

			$index[ $slug ] = $entry;
		}

		return $index;
	}
	/**
	 * Index repeaters by slug, indexing each one's own fields as well.
	 *
	 * @param mixed $repeaters Raw repeater list from the stored schema.
	 * @return array<string,array{definition:array,fields:array}>
	 */
	private function index_schema_repeaters( $repeaters ) {
		$index = array();

		if ( ! is_array( $repeaters ) ) {
			return $index;
		}

		foreach ( $repeaters as $repeater ) {
			if ( ! is_array( $repeater ) ) {
				continue;
			}

			$slug = $this->schema_entry_slug( $repeater );
			if ( '' === $slug ) {
				continue;
			}

			$index[ $slug ] = array(
				'definition' => $repeater,
				'fields' => $this->index_schema_entries(
					isset( $repeater['fields'] ) ? $repeater['fields'] : array()
				),
			);
		}

		return $index;
	}
	/**
	 * Normalize one schema row into the values its control needs.
	 *
	 * Rows without a usable slug and label are dropped here rather than in the
	 * render loop, so the caller deals with fields it can actually draw.
	 *
	 * @param array $row        Raw schema row.
	 * @param array $raw_fields Raw field definitions, keyed by slug.
	 * @return array|null Null when the row cannot be rendered.
	 */
	private function prepare_schema_row( $row, $raw_fields ) {
		if ( empty( $row['slug'] ) || empty( $row['label'] ) ) {
			return null;
		}

		$slug = sanitize_key( $row['slug'] );
		$label = sanitize_text_field( $row['label'] );
		if ( '' === $slug || '' === $label ) {
			return null;
		}

		$raw_field = isset( $raw_fields[ $slug ] ) ? $raw_fields[ $slug ] : array();

		return array_merge(
			Documentate_Document_Repeater_Field::prepare_field_control( $row, $raw_field, $label ),
			array(
				'slug' => $slug,
				'field_type' => \Documentate\Documents\Documents_Field_Validator::extract_raw_type( $raw_field ),
				'data_type' => isset( $row['data_type'] ) ? sanitize_key( $row['data_type'] ) : '',
				'rol' => Documentate_Campos_Rol::rol_del_campo( $row ),
			)
		);
	}
	/**
	 * Render the table row holding a repeater.
	 *
	 * @param array  $field         Prepared field from prepare_schema_row().
	 * @param string $meta_key      Meta key the repeater is stored under.
	 * @param array  $row           Raw schema row, for the item schema.
	 * @param array  $raw_schema    Full raw schema, for the repeater definition.
	 * @param array  $stored_fields Structured values stored on the post.
	 * @return void
	 */
	private function render_repeater_field_row( $field, $meta_key, $row, $raw_schema, $stored_fields ) {
		$slug = $field['slug'];
		$raw_repeater = isset( $raw_schema['repeaters'][ $slug ] ) && is_array( $raw_schema['repeaters'][ $slug ] )
			? $raw_schema['repeaters'][ $slug ]
			: array();
		$repeater_source = isset( $raw_repeater['definition'] ) ? $raw_repeater['definition'] : array();

		// A repeater carries its own title, which wins over the field's.
		$repeater_title = Documentate_Document_Repeater_Field::get_field_title( $repeater_source );
		$label = '' !== $repeater_title ? $repeater_title : $field['label'];
		$title_attribute = Documentate_Document_Repeater_Field::resolve_title_attribute( $repeater_source, $repeater_title );

		$items = $this->get_repeater_rows( $slug, $stored_fields );
		$help = Documentate_Document_Field_Help::build_field_help_context( $meta_key, $slug, $field['raw_field'] );

		echo '<tr class="documentate-field documentate-field-array documentate-field-' . esc_attr( $slug . $this->rol_css_class( $field ) ) . '">';
		echo '<th scope="row"><label';
		if ( '' !== $title_attribute ) {
			echo ' title="' . esc_attr( $title_attribute ) . '"';
		}
		echo '>' . esc_html( $label ) . '</label></th>';
		echo '<td>';
		Documentate_Document_Field_Help::render_before_description( $help['before'] );
		Documentate_Document_Repeater_Field::render_array_field(
			$slug,
			$label,
			$title_attribute,
			Documents_Meta_Handler::normalize_array_item_schema( $row ),
			$items,
			$raw_repeater
		);
		Documentate_Document_Field_Help::render_help_descriptions( $help );
		echo '</td></tr>';
	}
	/**
	 * Dispatch to the control matching a scalar field's type.
	 *
	 * @param WP_Post $post     Post being edited.
	 * @param array   $field    Prepared field from prepare_schema_row().
	 * @param string  $meta_key Meta key the value is stored under.
	 * @param string  $type     Resolved control type.
	 * @param string  $value    Current value.
	 * @param array   $help     Context from build_field_help_context().
	 * @return void
	 */
	private function render_scalar_control( $post, $field, $meta_key, $type, $value, $help ) {
		if ( 'single' === $type ) {
			Documentate_Document_Scalar_Field::render_single_input_control(
				$meta_key,
				$field['label'],
				$value,
				$field['field_type'],
				$field['data_type'],
				$field['raw_field'],
				$help['describedby'],
				$help['validation'],
			);

			return;
		}

		if ( 'rich' === $type ) {
			$is_locked = in_array( $post->post_status, array( 'publish', 'archived' ), true )
				|| ! Documentate_Workflow::user_can_modify_status( (string) $post->post_status, get_current_user_id() );
			Documentate_Document_Scalar_Field::render_rich_editor_control(
				$meta_key,
				$value,
				$is_locked,
				$field['raw_field'],
				$help['describedby'],
				$help['validation']
			);

			return;
		}

		Documentate_Document_Scalar_Field::render_textarea_control( $meta_key, $value, $field['raw_field'], $help['describedby'], $help['validation'] );
	}
	/**
	 * Render the table row holding a scalar control.
	 *
	 * @param WP_Post $post          Post being edited.
	 * @param array   $field         Prepared field from prepare_schema_row().
	 * @param string  $meta_key      Meta key the value is stored under.
	 * @param array   $stored_fields Structured values stored on the post.
	 * @return void
	 */
	private function render_scalar_field_row( $post, $field, $meta_key, $stored_fields ) {
		$slug = $field['slug'];
		$type = in_array( $field['type'], array( 'single', 'textarea', 'rich' ), true ) ? $field['type'] : 'textarea';
		$value = isset( $stored_fields[ $slug ] ) ? (string) $stored_fields[ $slug ]['value'] : '';
		$help = Documentate_Document_Field_Help::build_field_help_context( $meta_key, $slug, $field['raw_field'] );

		echo '<tr class="documentate-field documentate-field-'
				. esc_attr( $slug )
				. ' documentate-field-control-'
				. esc_attr( $type . $this->rol_css_class( $field ) )
				. '">';
		echo '<th scope="row"><label for="' . esc_attr( $meta_key ) . '"';
		if ( '' !== $field['title_attribute'] ) {
			echo ' title="' . esc_attr( $field['title_attribute'] ) . '"';
		}
		echo '>' . esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';
		Documentate_Document_Field_Help::render_before_description( $help['before'] );
		$this->render_scalar_control( $post, $field, $meta_key, $type, $value, $help );
		Documentate_Document_Field_Help::render_help_descriptions( $help );
		echo '</td></tr>';
	}
	/**
	 * Render one schema row and report the meta key it occupies.
	 *
	 * The key is returned even for rows that draw nothing, because the caller
	 * uses it to decide which stored values still count as unknown fields.
	 *
	 * @param WP_Post $post          Post being edited.
	 * @param array   $row           Raw schema row.
	 * @param array   $raw_schema    Full raw schema, for repeater definitions.
	 * @param array   $raw_fields    Raw field definitions, keyed by slug.
	 * @param array   $stored_fields Structured values stored on the post.
	 * @return string Meta key claimed by the row, or '' when the row was skipped.
	 */
	private function render_schema_row( $post, $row, $raw_schema, $raw_fields, $stored_fields ) {
		$field = $this->prepare_schema_row( $row, $raw_fields );
		if ( null === $field ) {
			return '';
		}

		$meta_key = 'documentate_field_' . $field['slug'];

		// WordPress draws the native title field itself.
		if ( 'post_title' === $field['slug'] ) {
			return $meta_key;
		}

		if ( 'array' === $field['type'] ) {
			$this->render_repeater_field_row( $field, $meta_key, $row, $raw_schema, $stored_fields );

			return $meta_key;
		}

		$this->render_scalar_field_row( $post, $field, $meta_key, $stored_fields );

		return $meta_key;
	}
	/**
	 * Render UI controls for dynamic fields not defined in the selected taxonomy schema.
	 *
	 * @param array $unknown_fields Unknown field definitions.
	 * @return void
	 */
	private function render_unknown_dynamic_fields_ui( $unknown_fields ) {
		if ( empty( $unknown_fields ) ) {
			return;
		}

		echo '<div class="documentate-unknown-dynamic" style="margin-top:24px;">';
		echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">'
				. esc_html__(
					'The document contains additional fields that do not belong to the selected type. Review their content before saving.',
					'documentate',
				)
				. '</div>';

		foreach ( $unknown_fields as $meta_key => $data ) {
			$label = Documents_Meta_Handler::humanize_unknown_field_label( $meta_key );
			$value = '';
			if ( isset( $data['value'] ) && is_string( $data['value'] ) ) {
				$value = wp_kses_post( $data['value'] );
			}
			echo '<div class="documentate-field documentate-field-warning" style="margin-bottom:16px;border:1px solid #dba617;padding:12px;background:#fffbea;">';
			/* translators: %s: detected dynamic field key. */
			$additional_field_label = sprintf( __( 'Additional field: %s', 'documentate' ), $label );
			echo '<label for="'
					. esc_attr( $meta_key )
					. '" style="font-weight:600;display:block;margin-bottom:4px;">'
					. esc_html( $additional_field_label )
					. '</label>';
			echo '<p class="description" style="margin-top:0;margin-bottom:8px;">'
					. esc_html__( 'This field is not defined in the current document type taxonomy.', 'documentate' )
					. '</p>';
			$tinymce_config = Documentate_Document_Scalar_Field::get_rich_editor_tinymce_config();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles escaping.
			wp_editor(
				$value,
				$meta_key,
				array(
					'textarea_name' => $meta_key,
					'textarea_rows' => 6,
					'media_buttons' => false,
					'teeny' => false,
					'wpautop' => false,
					'tinymce' => $tinymce_config,
					'quicktags' => true,
					'editor_height' => 200,
				)
			);
			echo '</div>';
		}

		echo '</div>';
	}
	/**
	 * Slug of a schema entry, taken from its slug or falling back to its name.
	 *
	 * @param array $entry Schema field or repeater definition.
	 * @return string Sanitized slug, or an empty string when it has neither.
	 */
	private function schema_entry_slug( array $entry ) {
		if ( isset( $entry['slug'] ) ) {
			return sanitize_key( $entry['slug'] );
		}

		if ( isset( $entry['name'] ) ) {
			return sanitize_key( $entry['name'] );
		}

		return '';
	}
}
