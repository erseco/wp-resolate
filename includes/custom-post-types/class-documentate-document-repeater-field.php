<?php
/**
 * Renders repeating (array) document fields.
 *
 * Extracted from Documentate_Document_Meta_Boxes: the rows, the clone template
 * the front-end copies, and one control per column. It also carries the field
 * resolution both row builders share, which is why prepare_field_control() and
 * the title helpers are public here rather than on the metabox class.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Renders repeating (array) document fields.
 */
class Documentate_Document_Repeater_Field {

	/**
	 * Retrieve the field title from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_field_title( $raw_field ) {
		return Documents_Field_Validator::get_field_title( $raw_field );
	}
	/**
	 * Resolve the label, control type and hover text shared by every field.
	 *
	 * Schema rows and repeater columns declare the same things in the same way,
	 * so both arrive here rather than spelling the rules out twice.
	 *
	 * @param array  $definition    Field definition, from a schema row or an item schema.
	 * @param array  $raw_field     Raw schema definition for the field.
	 * @param string $default_label Label to use when the definition declares none.
	 * @return array{label:string,type:string,raw_field:array,title_attribute:string}
	 */
	public static function prepare_field_control( $definition, $raw_field, $default_label ) {
		$label = isset( $definition['label'] )
			? sanitize_text_field( $definition['label'] )
			: $default_label;
		$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
		$field_title = self::get_field_title( $raw_field );

		return array(
			'label' => '' !== $field_title ? $field_title : $label,
			'type' => self::resolve_field_control_type( $type, $raw_field ),
			'raw_field' => $raw_field,
			'title_attribute' => self::resolve_title_attribute( $raw_field, $field_title ),
		);
	}
	/**
	 * Render an array field with repeatable items.
	 *
	 * The label and hover text are resolved by the caller, which already has to
	 * know them to draw the surrounding table row.
	 *
	 * @param string $slug            Field slug.
	 * @param string $label           Field label.
	 * @param string $title_attribute Hover text for the repeater heading.
	 * @param array  $item_schema     Item schema definition.
	 * @param array  $items           Current values.
	 * @param array  $raw_repeater    Raw schema definition for this repeater.
	 * @return void
	 */
	public static function render_array_field( $slug, $label, $title_attribute, $item_schema, $items, $raw_repeater = array() ) {
		$slug = sanitize_key( $slug );
		$label = sanitize_text_field( $label );
		$field_id = 'documentate-array-' . $slug;
		$items = is_array( $items ) ? $items : array();
		// Columns gestión documental owns are not drawn for the área, exactly
		// like the top-level fields of the sections metabox.
		$item_schema = Documentate_Campos_Rol::filtrar_item_schema( is_array( $item_schema ) ? $item_schema : array() );
		$raw_fields = isset( $raw_repeater['fields'] ) && is_array( $raw_repeater['fields'] )
			? $raw_repeater['fields']
			: array();

		echo '<div class="documentate-array-field" data-array-field="' . esc_attr( $slug ) . '" style="margin-bottom:24px;">';
		echo '<div class="documentate-array-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">';
		echo '<span class="documentate-array-title" style="font-weight:600;font-size:15px;"';
		if ( '' !== $title_attribute ) {
			echo ' title="' . esc_attr( $title_attribute ) . '"';
		}
		echo '>' . esc_html( $label ) . '</span>';
		echo '<button type="button" class="button button-secondary documentate-array-add" data-array-target="'
				. esc_attr( $slug )
				. '">'
				. esc_html__( 'Add item', 'documentate' )
				. '</button>';
		echo '</div>';

		echo '<div class="documentate-array-items" id="' . esc_attr( $field_id ) . '" data-field="' . esc_attr( $slug ) . '">';
		foreach ( $items as $index => $values ) {
			$values = is_array( $values ) ? $values : array();
			self::render_array_field_item( $slug, (string) $index, $item_schema, $values, false, $raw_fields );
		}
		echo '</div>';

		echo '<template class="documentate-array-template" data-field="' . esc_attr( $slug ) . '">';
		self::render_array_field_item( $slug, '__INDEX__', $item_schema, array(), true, $raw_fields );
		echo '</template>';
		echo '</div>';
	}
	/**
	 * Pick the text shown when hovering a field label.
	 *
	 * @param array  $raw_field   Raw schema definition for the field.
	 * @param string $field_title Title already resolved for the field.
	 * @return string
	 */
	public static function resolve_title_attribute( $raw_field, $field_title ) {
		$pattern_message = self::get_field_pattern_message( $raw_field );

		return '' !== $pattern_message ? $pattern_message : $field_title;
	}
	/**
	 * Retrieve pattern validation message from raw schema.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_pattern_message( $raw_field ) {
		return Documents_Field_Validator::get_field_pattern_message( $raw_field );
	}
	/**
	 * Resolve everything one column of a repeater row needs to draw itself.
	 *
	 * @param string $slug_key   Raw key of the column inside the item schema.
	 * @param array  $definition Item schema entry for the column.
	 * @param array  $raw_fields Raw schema definitions, keyed by column.
	 * @param array  $values     Values stored for this row.
	 * @param string $slug       Repeater slug.
	 * @param string $index_attr Row index, already a string.
	 * @return array|null Null when the column has no usable key.
	 */
	private static function prepare_array_item_field( $slug_key, $definition, $raw_fields, $values, $slug, $index_attr ) {
		$item_key = sanitize_key( $slug_key );
		if ( '' === $item_key ) {
			return null;
		}

		$raw_field = isset( $raw_fields[ $item_key ] ) ? $raw_fields[ $item_key ] : array();

		return array_merge(
			self::prepare_field_control( $definition, $raw_field, Documents_Meta_Handler::humanize_unknown_field_label( $item_key ) ),
			array(
				'item_key' => $item_key,
				'field_name' => 'tpl_fields[' . $slug . '][' . $index_attr . '][' . $item_key . ']',
				'field_id' => 'documentate-' . $slug . '-' . $item_key . '-' . $index_attr,
				'value' => isset( $values[ $item_key ] ) ? (string) $values[ $item_key ] : '',
				'definition' => $definition,
			)
		);
	}
	/**
	 * Render a single repeatable array item row.
	 *
	 * @param string $slug         Field slug.
	 * @param string $index        Item index.
	 * @param array  $item_schema  Item schema definition.
	 * @param array  $values       Current values.
	 * @param bool   $is_template  Whether the row is a template placeholder.
	 * @param array  $raw_fields   Raw schema definitions for the repeater items.
	 * @return void
	 */
	private static function render_array_field_item(
		$slug,
		$index,
		$item_schema,
		$values,
		$is_template = false,
		$raw_fields = array(),
	) {
		$slug = sanitize_key( $slug );
		$index_attr = (string) $index;
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$values = is_array( $values ) ? $values : array();
		$raw_fields = is_array( $raw_fields ) ? $raw_fields : array();

		echo '<div class="documentate-array-item" data-index="'
				. esc_attr( $index_attr )
				. '" draggable="true" style="border:1px solid #e5e5e5;padding:16px;margin-bottom:12px;background:#fff;">';
		echo '<div class="documentate-array-item-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;">';
		echo '<span class="documentate-array-handle" role="button" tabindex="0" aria-label="'
				. esc_attr__( 'Move item', 'documentate' )
				. '" style="cursor:move;user-select:none;">≡</span>';
		echo '<button type="button" class="button-link-delete documentate-array-remove">'
				. esc_html__( 'Delete', 'documentate' )
				. '</button>';
		echo '</div>';

		foreach ( $item_schema as $key => $definition ) {
			if ( isset( $definition['type'] ) && 'array' === $definition['type'] ) {
				self::render_subarray_field( $slug, $index_attr, $key, $definition, $values, $raw_fields );
				continue;
			}

			$field = self::prepare_array_item_field( $key, $definition, $raw_fields, $values, $slug, $index_attr );
			if ( null === $field ) {
				continue;
			}

			echo '<div class="documentate-array-field-control" style="margin-bottom:12px;">';
			echo '<label for="' . esc_attr( $field['field_id'] ) . '" style="font-weight:600;display:block;margin-bottom:4px;"';
			if ( '' !== $field['title_attribute'] ) {
				echo ' title="' . esc_attr( $field['title_attribute'] ) . '"';
			}
			echo '>' . esc_html( $field['label'] ) . '</label>';

			self::render_array_item_control( $field, $is_template );

			echo '</div>';
		}

		echo '</div>';
	}
	/**
	 * Render a nested repeater (TBS sub-block) inside one parent row.
	 *
	 * Each parent record carries its own rows for the sub-repeater (e.g. the
	 * conceptos of one provider). Inputs are named
	 * tpl_fields[slug][parent][key][sub][column]; the clone template uses the
	 * __SUBINDEX__ marker so the parent row's __INDEX__ stays untouched until
	 * the parent row itself is cloned.
	 *
	 * @param string $slug         Parent repeater slug.
	 * @param string $parent_index Parent row index (or __INDEX__ in the template).
	 * @param string $key          Item schema key of the nested repeater.
	 * @param array  $definition   Normalized item schema entry (type array).
	 * @param array  $values       Values stored for the parent row.
	 * @param array  $raw_fields   Raw schema definitions, keyed by column.
	 * @return void
	 */
	private static function render_subarray_field( $slug, $parent_index, $key, $definition, $values, $raw_fields ) {
		$item_key = sanitize_key( $key );
		if ( '' === $item_key ) {
			return;
		}

		$label = isset( $definition['label'] )
			? sanitize_text_field( $definition['label'] )
			: Documents_Meta_Handler::humanize_unknown_field_label( $item_key );
		$sub_schema = isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] )
			? $definition['item_schema']
			: array();
		$sub_raw_fields = self::index_raw_sub_fields( isset( $raw_fields[ $item_key ] ) ? $raw_fields[ $item_key ] : array() );

		$items = isset( $values[ $item_key ] ) && is_array( $values[ $item_key ] ) ? array_values( $values[ $item_key ] ) : array();
		if ( empty( $items ) ) {
			// The editor always shows at least one row to type into.
			$items = array( array() );
		}

		echo '<div class="documentate-subarray-field" data-subarray-field="'
				. esc_attr( $item_key )
				. '" style="margin:12px 0 16px;padding:12px;border:1px solid #e5e5e5;background:#fafafa;">';
		echo '<div class="documentate-subarray-heading" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;">';
		echo '<span style="font-weight:600;">' . esc_html( $label ) . '</span>';
		echo '<button type="button" class="button button-secondary documentate-subarray-add">'
				. esc_html__( 'Add item', 'documentate' )
				. '</button>';
		echo '</div>';

		echo '<div class="documentate-subarray-items">';
		foreach ( $items as $sub_index => $sub_values ) {
			self::render_subarray_item( $slug, $parent_index, $item_key, (string) $sub_index, $sub_schema, is_array( $sub_values ) ? $sub_values : array(), $sub_raw_fields );
		}
		echo '</div>';

		echo '<template class="documentate-subarray-template">';
		self::render_subarray_item( $slug, $parent_index, $item_key, '__SUBINDEX__', $sub_schema, array(), $sub_raw_fields );
		echo '</template>';
		echo '</div>';
	}
	/**
	 * Render one row of a nested repeater.
	 *
	 * @param string $slug         Parent repeater slug.
	 * @param string $parent_index Parent row index.
	 * @param string $item_key     Item schema key of the nested repeater.
	 * @param string $sub_index    Row index (or __SUBINDEX__ in the template).
	 * @param array  $sub_schema   Normalized item schema of the nested repeater.
	 * @param array  $sub_values   Values stored for this row.
	 * @param array  $sub_raw      Raw schema definitions, keyed by column.
	 * @return void
	 */
	private static function render_subarray_item( $slug, $parent_index, $item_key, $sub_index, $sub_schema, $sub_values, $sub_raw ) {
		echo '<div class="documentate-subarray-item" data-subindex="'
				. esc_attr( $sub_index )
				. '" style="border:1px solid #e5e5e5;padding:12px;margin-bottom:8px;background:#fff;">';
		echo '<div style="display:flex;justify-content:flex-end;margin-bottom:8px;">';
		echo '<button type="button" class="button-link-delete documentate-subarray-remove">'
				. esc_html__( 'Delete', 'documentate' )
				. '</button>';
		echo '</div>';

		foreach ( $sub_schema as $sub_key => $sub_definition ) {
			$column = sanitize_key( $sub_key );
			if ( '' === $column ) {
				continue;
			}

			$raw_field = isset( $sub_raw[ $column ] ) ? $sub_raw[ $column ] : array();
			$field = array_merge(
				self::prepare_field_control( $sub_definition, $raw_field, Documents_Meta_Handler::humanize_unknown_field_label( $column ) ),
				array(
					'item_key' => $column,
					'field_name' => 'tpl_fields[' . $slug . '][' . $parent_index . '][' . $item_key . '][' . $sub_index . '][' . $column . ']',
					'field_id' => 'documentate-' . $slug . '-' . $item_key . '-' . $column . '-' . $parent_index . '-' . $sub_index,
					'value' => isset( $sub_values[ $column ] ) && is_scalar( $sub_values[ $column ] ) ? (string) $sub_values[ $column ] : '',
					'definition' => $sub_definition,
				)
			);

			echo '<div class="documentate-array-field-control" style="margin-bottom:12px;">';
			echo '<label for="' . esc_attr( $field['field_id'] ) . '" style="font-weight:600;display:block;margin-bottom:4px;"';
			if ( '' !== $field['title_attribute'] ) {
				echo ' title="' . esc_attr( $field['title_attribute'] ) . '"';
			}
			echo '>' . esc_html( $field['label'] ) . '</label>';

			self::render_array_item_control( $field, false );

			echo '</div>';
		}

		echo '</div>';
	}
	/**
	 * Index the raw definitions of a nested repeater's columns by slug.
	 *
	 * @param array $raw_entry Raw schema entry of the nested repeater.
	 * @return array<string,array>
	 */
	private static function index_raw_sub_fields( $raw_entry ) {
		$index = array();
		if ( ! isset( $raw_entry['fields'] ) || ! is_array( $raw_entry['fields'] ) ) {
			return $index;
		}

		foreach ( $raw_entry['fields'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$entry_slug = '';
			if ( isset( $entry['slug'] ) ) {
				$entry_slug = sanitize_key( $entry['slug'] );
			} elseif ( isset( $entry['name'] ) ) {
				$entry_slug = sanitize_key( $entry['name'] );
			}
			if ( '' !== $entry_slug ) {
				$index[ $entry_slug ] = $entry;
			}
		}

		return $index;
	}
	/**
	 * Dispatch to the control matching a repeater column's type.
	 *
	 * @param array $field       Prepared column from prepare_array_item_field().
	 * @param bool  $is_template Whether this row is the hidden clone template.
	 * @return void
	 */
	private static function render_array_item_control( $field, $is_template ) {
		if ( 'single' === $field['type'] ) {
			self::render_array_item_single(
				$field['item_key'],
				$field['field_name'],
				$field['field_id'],
				$field['label'],
				$field['raw_field'],
				$field['value'],
				$field['definition']
			);

			return;
		}

		if ( 'rich' === $field['type'] ) {
			self::render_array_item_rich(
				$field['item_key'],
				$field['field_name'],
				$field['field_id'],
				$field['raw_field'],
				$field['value'],
				$is_template
			);

			return;
		}

		self::render_array_item_textarea(
			$field['item_key'],
			$field['field_name'],
			$field['field_id'],
			$field['raw_field'],
			$field['value']
		);
	}
	/**
	 * Render a rich text control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @param bool   $is_template  Whether this is the hidden row the JS clones,
	 *                             which must carry the template marker class.
	 * @return void
	 */
	private static function render_array_item_rich( $item_key, $field_name, $field_id, $raw_field, $value, $is_template ) {
		$help = Documentate_Document_Field_Help::build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = Documentate_Document_Field_Help::apply_help_attributes(
			Documentate_Document_Scalar_Field::build_scalar_input_attributes( $raw_field, 'textarea' ),
			$help
		);
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 8;
		}

		// Check if collaborative editing is enabled.
		$is_collaborative = Documentate_Document_Scalar_Field::is_collaborative_editing_enabled();
		Documentate_Document_Field_Help::render_before_description( $help['before'] );

		if ( $is_collaborative ) {
			// Render TipTap collaborative editor container for array fields.
			$classes = trim(
				Documentate_Document_Scalar_Field::build_input_class( 'textarea' )
				. ' documentate-array-rich documentate-collab-textarea'
				. ( $is_template ? ' documentate-array-rich-template' : '' ),
			);
			$attributes['class'] = $classes;
			$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );
			echo '<div class="documentate-collab-container">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<textarea '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '">'
					. esc_textarea( $value )
					. '</textarea>';
			echo '</div>';
		} else {
			$classes = trim(
				Documentate_Document_Scalar_Field::build_input_class( 'textarea' )
				. ' documentate-array-rich'
				. ( $is_template ? ' documentate-array-rich-template' : '' ),
			);
			$attributes['class'] = $classes;
			$attributes['data-editor-initialized'] = 'false';
			$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<textarea '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '">'
					. esc_textarea( $value )
					. '</textarea>';
		}

		Documentate_Document_Field_Help::render_help_descriptions( $help );
	}
	/**
	 * Render a single-line control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param string $label        Visible label, reused by the screen-reader text.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @param array  $definition   Item schema entry, read for its data_type hint.
	 * @return void
	 */
	private static function render_array_item_single( $item_key, $field_name, $field_id, $label, $raw_field, $value, $definition ) {
		$raw_field_type = \Documentate\Documents\Documents_Field_Validator::extract_raw_type( $raw_field );
		$raw_data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : '';
		$input_type = Documentate_Document_Scalar_Field::map_single_input_type( $raw_field_type, $raw_data_type );
		$normalized_value = Documentate_Document_Scalar_Field::normalize_scalar_value( $value, $input_type );
		$help = Documentate_Document_Field_Help::build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = Documentate_Document_Field_Help::apply_help_attributes(
			Documentate_Document_Scalar_Field::build_scalar_input_attributes( $raw_field, $input_type ),
			$help
		);
		$attributes['class'] = Documentate_Document_Scalar_Field::build_input_class( $input_type );
		$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );
		Documentate_Document_Field_Help::render_before_description( $help['before'] );

		if ( 'select' === $input_type ) {
			$options = Documentate_Document_Scalar_Field::parse_select_options( $raw_field );
			$placeholder = Documentate_Document_Scalar_Field::get_select_placeholder( $raw_field );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" ' . $attribute_string . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $placeholder ) {
				echo '<option value="">' . esc_html( $placeholder ) . '</option>';
			} elseif ( empty( $attributes['required'] ) ) {
				echo '<option value="">' . esc_html__( 'Select an option…', 'documentate' ) . '</option>';
			}
			foreach ( $options as $option_value => $option_label ) {
				echo '<option value="'
						. esc_attr( $option_value )
						. '" '
						. selected( $option_value, $normalized_value, false )
						. '>'
						. esc_html( $option_label )
						. '</option>';
			}
			echo '</select>';
		} elseif ( 'checkbox' === $input_type ) {
			echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="0" />';
			echo '<label class="documentate-checkbox-wrapper">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="checkbox" id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '" value="1" '
					. checked( '1', $normalized_value, false )
					. ' '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
			echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			echo '</label>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="'
					. esc_attr( $input_type )
					. '" id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '" value="'
					. esc_attr( $normalized_value )
					. '" '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
		}
		Documentate_Document_Field_Help::render_help_descriptions( $help );
	}
	/**
	 * Render a textarea control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @return void
	 */
	private static function render_array_item_textarea( $item_key, $field_name, $field_id, $raw_field, $value ) {
		$help = Documentate_Document_Field_Help::build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = Documentate_Document_Field_Help::apply_help_attributes(
			Documentate_Document_Scalar_Field::build_scalar_input_attributes( $raw_field, 'textarea' ),
			$help
		);
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 6;
		}
		$attributes['class'] = Documentate_Document_Scalar_Field::build_input_class( 'textarea' );
		$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );
		Documentate_Document_Field_Help::render_before_description( $help['before'] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<textarea '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. ' id="'
				. esc_attr( $field_id )
				. '" name="'
				. esc_attr( $field_name )
				. '">'
				. esc_textarea( $value )
				. '</textarea>';
		Documentate_Document_Field_Help::render_help_descriptions( $help );
	}
	/**
	 * Decide the UI control to use based on schema hints.
	 *
	 * @param string     $legacy_type Legacy control type.
	 * @param array|null $raw_field   Raw schema definition.
	 * @return string Control identifier: single|textarea|rich|array.
	 */
	private static function resolve_field_control_type( $legacy_type, $raw_field ) {
		return Documents_Field_Validator::resolve_field_control_type( $legacy_type, $raw_field );
	}
}
