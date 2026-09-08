<?php
/**
 * Renders the non-repeating document field controls.
 *
 * Extracted from Documentate_Document_Meta_Boxes: text, number, date, select,
 * checkbox, textarea and the TinyMCE editor. Each takes a resolved field and
 * writes one control; none of them knows about the table row around it.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;

/**
 * Renders the non-repeating document field controls.
 */
class Documentate_Document_Scalar_Field {

	/**
	 * Build CSS classes for rendered controls following WP admin conventions.
	 *
	 * @param string $input_type Input type.
	 * @return string
	 */
	public static function build_input_class( $input_type ) {
		return Documents_Field_Renderer::build_input_class( $input_type );
	}
	/**
	 * Build common HTML attributes from raw schema metadata.
	 *
	 * @param array  $raw_field  Raw field definition.
	 * @param string $input_type Input type being rendered.
	 * @return array<string,string>
	 */
	public static function build_scalar_input_attributes( $raw_field, $input_type ) {
		return Documents_Field_Validator::build_scalar_input_attributes( $raw_field, $input_type );
	}
	/**
	 * Get TinyMCE configuration for Documentate rich editors.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_rich_editor_tinymce_config() {
		return array(
			'toolbar1' => 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,table,undo,redo,searchreplace,removeformat',
			'content_style' => 'table{border-collapse:collapse}th,td{border:1px solid #000;padding:2px}',
			// TinyMCE content filtering: remove elements not supported by OpenTBS.
			'invalid_elements' => self::get_rich_editor_invalid_elements(),
			'valid_elements' => self::get_rich_editor_valid_elements(),
			'paste_remove_styles' => false,
			'paste_strip_class_attributes' => 'all',
		);
	}
	/**
	 * Determine select placeholder text if provided.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_select_placeholder( $raw_field ) {
		return Documents_Field_Renderer::get_select_placeholder( $raw_field );
	}
	/**
	 * Check if collaborative editing is enabled in settings.
	 *
	 * @return bool True if collaborative editing is enabled.
	 */
	public static function is_collaborative_editing_enabled() {
		return Documentate_Admin::is_collaborative_enabled();
	}
	/**
	 * Map schema type hints to concrete HTML input types.
	 *
	 * @param string $field_type Original schema field type.
	 * @param string $data_type  Normalized data type.
	 * @return string
	 */
	public static function map_single_input_type( $field_type, $data_type ) {
		return Documents_Field_Validator::map_single_input_type( $field_type, $data_type );
	}
	/**
	 * Normalize stored value for the selected HTML control type.
	 *
	 * @param string $value      Stored value.
	 * @param string $input_type Target input type.
	 * @return string
	 */
	public static function normalize_scalar_value( $value, $input_type ) {
		return Documents_Field_Validator::normalize_scalar_value( $value, $input_type );
	}
	/**
	 * Parse select options from schema parameters.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return array<string,string>
	 */
	public static function parse_select_options( $raw_field ) {
		return Documents_Field_Renderer::parse_select_options( $raw_field );
	}
	/**
	 * Render a rich text editor control.
	 *
	 * @param string              $meta_key    The meta key for the field.
	 * @param string              $value       The current field value.
	 * @param bool                $is_locked   Whether the editor should be readonly (default false).
	 * @param array<string,mixed> $raw_field   Raw field definition (default empty).
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation  Validation message.
	 */
	public static function render_rich_editor_control(
		$meta_key,
		$value,
		$is_locked = false,
		$raw_field = array(),
		$describedby = array(),
		$validation = '',
	) {
		$is_collaborative = self::is_collaborative_editing_enabled();
		$is_required = \Documentate\Documents\Documents_Field_Validator::is_field_required( $raw_field );
		$describedby_attribute = ! empty( $describedby ) ? implode( ' ', $describedby ) : '';

		if ( $is_collaborative ) {
			echo '<div class="documentate-collab-container">';
			echo '<textarea id="'
					. esc_attr( $meta_key )
					. '" name="'
					. esc_attr( $meta_key )
					. '" class="documentate-collab-textarea" rows="8"'
					. ( '' !== $describedby_attribute ? ' aria-describedby="' . esc_attr( $describedby_attribute ) . '"' : '' )
					. ( '' !== $validation ? ' data-validation-message="' . esc_attr( $validation ) . '"' : '' )
					. ( $is_required ? ' data-required="true"' : '' )
					. '>'
					. esc_textarea( $value )
					. '</textarea>';
			echo '</div>';
		} else {
			$tinymce_config = self::get_rich_editor_tinymce_config();

			if ( $is_locked ) {
				$tinymce_config['readonly'] = 1;
			}

			if ( $is_required ) {
				echo '<div class="documentate-rich-editor-wrap" data-required="true">';
			}

			ob_start();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
			wp_editor(
				$value,
				$meta_key,
				array(
					'textarea_name' => $meta_key,
					'textarea_rows' => 8,
					'media_buttons' => false,
					'teeny' => false,
					'wpautop' => false,
					'tinymce' => $tinymce_config,
					'quicktags' => true,
					'editor_height' => 220,
				)
			);
			$editor_html = ob_get_clean();

			if ( '' !== $describedby_attribute ) {
				$editor_html = preg_replace(
					'/<textarea\b/',
					'<textarea aria-describedby="' . esc_attr( $describedby_attribute ) . '"',
					$editor_html,
					1,
				);
			}

			if ( '' !== $validation ) {
				$editor_html = preg_replace(
					'/<textarea\b/',
					'<textarea data-validation-message="' . esc_attr( $validation ) . '"',
					$editor_html,
					1,
				);
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
			echo $editor_html;

			if ( $is_required ) {
				echo '</div>';
			}
		}
	}
	/**
	 * Render a single-line input control (text, number, date, select, checkbox).
	 *
	 * @param string              $meta_key   The meta key for the field.
	 * @param string              $label      The field label.
	 * @param string              $value      The current field value.
	 * @param string              $field_type Field type from schema.
	 * @param string              $data_type  Data type from schema.
	 * @param array<string,mixed> $raw_field  Raw field definition.
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation Validation message.
	 */
	public static function render_single_input_control(
		$meta_key,
		$label,
		$value,
		$field_type,
		$data_type,
		$raw_field,
		$describedby,
		$validation,
	) {
		$input_type = self::map_single_input_type( $field_type, $data_type );
		$normalized_value = self::normalize_scalar_value( $value, $input_type );
		$attributes = self::build_scalar_input_attributes( $raw_field, $input_type );

		if ( ! empty( $describedby ) ) {
			$attributes['aria-describedby'] = implode( ' ', $describedby );
		}
		if ( '' !== $validation ) {
			$attributes['data-validation-message'] = $validation;
		}

		$attributes['class'] = self::build_input_class( $input_type );
		$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );

		if ( 'select' === $input_type ) {
			self::render_select_control( $meta_key, $normalized_value, $raw_field, $attributes, $attribute_string );
		} elseif ( 'checkbox' === $input_type ) {
			self::render_checkbox_control( $meta_key, $label, $normalized_value, $attribute_string );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="'
					. esc_attr( $input_type )
					. '" id="'
					. esc_attr( $meta_key )
					. '" name="'
					. esc_attr( $meta_key )
					. '" value="'
					. esc_attr( $normalized_value )
					. '" '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
		}
	}
	/**
	 * Render a textarea control.
	 *
	 * @param string              $meta_key   The meta key for the field.
	 * @param string              $value      The current field value.
	 * @param array<string,mixed> $raw_field  Raw field definition.
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation Validation message.
	 */
	public static function render_textarea_control( $meta_key, $value, $raw_field, $describedby, $validation ) {
		$attributes = self::build_scalar_input_attributes( $raw_field, 'textarea' );
		if ( ! empty( $describedby ) ) {
			$attributes['aria-describedby'] = implode( ' ', $describedby );
		}
		if ( '' !== $validation ) {
			$attributes['data-validation-message'] = $validation;
		}
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 6;
		}
		$attributes['class'] = self::build_input_class( 'textarea' );
		$attribute_string = Documentate_Document_Field_Help::format_field_attributes( $attributes );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<textarea id="'
				. esc_attr( $meta_key )
				. '" name="'
				. esc_attr( $meta_key )
				. '" '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '>'
				. esc_textarea( $value )
				. '</textarea>';
	}
	/**
	 * Get TinyMCE invalid elements for Documentate rich editors.
	 *
	 * @return string
	 */
	private static function get_rich_editor_invalid_elements() {
		return implode(
			',',
			array(
				'article',
				'span',
				'button',
				'form',
				'select',
				'input',
				'textarea',
				'div',
				'iframe',
				'embed',
				'object',
				'label',
				'font',
				'img',
				'video',
				'audio',
				'canvas',
				'svg',
				'script',
				'style',
				'noscript',
				'map',
				'area',
				'applet',
			)
		);
	}
	/**
	 * Get TinyMCE valid elements for Documentate rich editors.
	 *
	 * @return string
	 */
	private static function get_rich_editor_valid_elements() {
		return implode(
			',',
			array(
				'a[href|title|target]',
				'strong/b',
				'em/i',
				'u',
				'p[style|class|align]',
				'br',
				'ul',
				'ol',
				'li',
				'h1',
				'h2',
				'h3',
				'h4',
				'h5',
				'h6',
				'blockquote',
				'code',
				'pre',
				'table[border|cellpadding|cellspacing|style|class|align]',
				'thead',
				'tbody',
				'tfoot',
				'tr',
				'td[colspan|rowspan|style|class|align]',
				'th[colspan|rowspan|style|class|align]',
			)
		);
	}
	/**
	 * Render a checkbox control.
	 *
	 * @param string $meta_key         The meta key for the field.
	 * @param string $label            The field label.
	 * @param string $value            The current field value.
	 * @param string $attribute_string Formatted attribute string.
	 */
	private static function render_checkbox_control( $meta_key, $label, $value, $attribute_string ) {
		// Hidden field guarantees we persist an explicit "0" when unchecked.
		echo '<input type="hidden" name="' . esc_attr( $meta_key ) . '" value="0" />';
		echo '<label class="documentate-checkbox-wrapper">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<input type="checkbox" id="'
				. esc_attr( $meta_key )
				. '" name="'
				. esc_attr( $meta_key )
				. '" value="1" '
				. checked( '1', $value, false )
				. ' '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. ' />';
		echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
		echo '</label>';
	}
	/**
	 * Default option for school-year ("curso") selects: the current academic year.
	 *
	 * The academic year runs from September to August, so from September 2026 to
	 * August 2027 the default is "2026/2027" (or "2026-2027", whichever matches an
	 * option). Only applies to fields whose key contains "curso" and only when the
	 * document has no stored value yet.
	 *
	 * @param string               $meta_key The meta key for the field.
	 * @param array<string,string> $options  Select options keyed by value.
	 * @param int|null             $now      Optional timestamp to resolve the year against.
	 * @return string Matching option value, or an empty string when none applies.
	 */
	public static function get_academic_year_default( $meta_key, $options, $now = null ) {
		if ( false === strpos( strtolower( (string) $meta_key ), 'curso' ) ) {
			return '';
		}

		$timestamp = null === $now ? current_datetime()->getTimestamp() : (int) $now;
		$year = (int) wp_date( 'Y', $timestamp );
		$month = (int) wp_date( 'n', $timestamp );
		$start = $month >= 9 ? $year : $year - 1;
		$end = $start + 1;

		$candidates = array(
			$start . '/' . $end,
			$start . '-' . $end,
			$start . '/' . substr( (string) $end, -2 ),
			$start . '-' . substr( (string) $end, -2 ),
		);

		foreach ( $candidates as $candidate ) {
			if ( isset( $options[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return '';
	}
	/**
	 * Render a select dropdown control.
	 *
	 * @param string              $meta_key         The meta key for the field.
	 * @param string              $value            The current field value.
	 * @param array<string,mixed> $raw_field        Raw field definition.
	 * @param array<string,mixed> $attributes       Field attributes.
	 * @param string              $attribute_string Formatted attribute string.
	 */
	private static function render_select_control( $meta_key, $value, $raw_field, $attributes, $attribute_string ) {
		$options = self::parse_select_options( $raw_field );
		if ( '' === $value ) {
			$value = self::get_academic_year_default( $meta_key, $options );
		}
		$placeholder = self::get_select_placeholder( $raw_field );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<select id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" ' . $attribute_string . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $placeholder ) {
			echo '<option value="">' . esc_html( $placeholder ) . '</option>';
		} elseif ( empty( $attributes['required'] ) ) {
			echo '<option value="">' . esc_html( 'Selecciona una opción…' ) . '</option>';
		}
		foreach ( $options as $option_value => $option_label ) {
			echo '<option value="'
					. esc_attr( $option_value )
					. '" '
					. selected( $option_value, $value, false )
					. '>'
					. esc_html( $option_label )
					. '</option>';
		}
		echo '</select>';
	}
}
