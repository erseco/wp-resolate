<?php
/**
 * Dynamic fields manager for Resolate documents.
 *
 * @package Resolate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle dynamic OpenTBS-driven fields for the document CPT.
 */
class Resolate_Dynamic_Fields {
	/**
	 * Nonce action for dynamic fields.
	 */
	const NONCE_ACTION = 'resolate_dynamic_fields_nonce';

	/**
	 * Nonce field name.
	 */
	const NONCE_NAME = 'resolate_dynamic_fields_nonce';

	/**
	 * Request key used for submitted fields.
	 */
	const REQUEST_KEY = 'resolate_dynamic_fields';

	/**
	 * Transient prefix for validation errors.
	 */
	const TRANSIENT_PREFIX = 'resolate_dynamic_fields_errors_';

	/**
	 * Last post ID that failed validation in the current request.
	 *
	 * @var int
	 */
	private $invalid_post_id = 0;

	/**
	 * Validation errors collected during the current save cycle.
	 *
	 * @var string[]
	 */
	private $validation_errors = array();

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes_resolate_document', array( $this, 'register_meta_box' ), 20, 1 );
		add_action( 'save_post_resolate_document', array( $this, 'save_dynamic_fields' ), 20, 1 );
		add_filter( 'redirect_post_location', array( $this, 'append_validation_query_arg' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_validation_notices' ) );
	}

	/**
	 * Add the dynamic fields meta box when schema is available.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function register_meta_box( $post ) {
		$schema = self::get_schema_for_post( $post ? $post->ID : 0 );
		if ( empty( $schema ) ) {
			return;
		}

		remove_meta_box( 'resolate_sections', 'resolate_document', 'normal' );

		add_meta_box(
			'resolate_dynamic_fields',
			__( 'Campos dinámicos', 'resolate' ),
			array( $this, 'render_meta_box' ),
			'resolate_document',
			'normal',
			'high'
		);
	}

	/**
	 * Render the dynamic fields meta box.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$schema = self::get_schema_for_post( $post->ID );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		if ( empty( $schema ) ) {
			echo '<p>' . esc_html__( 'Configura una plantilla con campos dinámicos para editar su contenido.', 'resolate' ) . '</p>';
			return;
		}

		$values = self::get_field_values( $post->ID );
		$errors = $this->read_persisted_errors();

		if ( ! empty( $errors ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Revisa los errores antes de guardar.', 'resolate' ) . '</p><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<div class="resolate-dynamic-fields">';
		foreach ( $schema as $field ) {
			$this->render_field_row( $field, $values );
		}
		echo '</div>';
	}

	/**
	 * Persist submitted dynamic fields.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function save_dynamic_fields( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$schema = self::get_schema_for_post( $post_id );
		if ( empty( $schema ) ) {
			return;
		}

		$posted = array();
		if ( isset( $_POST[ self::REQUEST_KEY ] ) && is_array( $_POST[ self::REQUEST_KEY ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$posted = wp_unslash( $_POST[ self::REQUEST_KEY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field.
		}

		$sanitized = array();
		$errors    = array();

		foreach ( $schema as $field ) {
			$name      = isset( $field['name'] ) ? (string) $field['name'] : '';
			$merge_key = isset( $field['merge_key'] ) ? (string) $field['merge_key'] : $name;
			if ( '' === $name || '' === $merge_key ) {
				continue;
			}

			$raw_value = '';
			if ( isset( $posted[ $name ] ) ) {
				$raw_value = $posted[ $name ];
			}

			$result = $this->sanitize_field_value( $field, $raw_value );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
				continue;
			}
			$sanitized[ $name ] = $result;
		}

		if ( ! empty( $errors ) ) {
			$this->validation_errors = $errors;
			$this->invalid_post_id    = $post_id;
			$this->persist_errors( $errors );
			return;
		}

		foreach ( $schema as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$value = isset( $sanitized[ $name ] ) ? $sanitized[ $name ] : '';
			if ( '' === $value ) {
				delete_post_meta( $post_id, $name );
			} else {
				update_post_meta( $post_id, $name, $value );
			}
		}
	}

	/**
	 * Append validation error flag to redirect URL when needed.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	public function append_validation_query_arg( $location, $post_id ) {
		if ( $this->invalid_post_id && $post_id === $this->invalid_post_id && ! empty( $this->validation_errors ) ) {
			return add_query_arg( 'resolate_dynamic_fields_error', 1, $location );
		}
		return $location;
	}

	/**
	 * Display validation errors persisted from the previous request.
	 */
	public function render_validation_notices() {
		if ( empty( $_GET['resolate_dynamic_fields_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$errors = $this->read_persisted_errors();
		if ( empty( $errors ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudieron guardar los campos dinámicos.', 'resolate' ) . '</p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Retrieve and purge persisted errors for the current user.
	 *
	 * @return string[]
	 */
	private function read_persisted_errors() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$key    = self::TRANSIENT_PREFIX . $user_id;
		$errors = get_transient( $key );
		if ( false !== $errors ) {
			delete_transient( $key );
		}
		return is_array( $errors ) ? array_filter( array_map( 'strval', $errors ) ) : array();
	}

	/**
	 * Persist validation errors for the current user.
	 *
	 * @param string[] $errors Error messages.
	 * @return void
	 */
	private function persist_errors( $errors ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( self::TRANSIENT_PREFIX . $user_id, array_values( $errors ), 60 );
	}

	/**
	 * Render a single field row inside the meta box.
	 *
	 * @param array $field  Field definition.
	 * @param array $values Stored field values keyed by field name.
	 * @return void
	 */
	private function render_field_row( $field, $values ) {
		$name      = isset( $field['name'] ) ? (string) $field['name'] : '';
		$merge_key = isset( $field['merge_key'] ) ? (string) $field['merge_key'] : $name;
		if ( '' === $name || '' === $merge_key ) {
			return;
		}

		$title        = isset( $field['title'] ) && '' !== $field['title'] ? $field['title'] : $merge_key;
		$type         = isset( $field['type'] ) ? $field['type'] : 'text';
		$description  = isset( $field['description'] ) ? $field['description'] : '';
		$placeholder  = isset( $field['input_placeholder'] ) ? $field['input_placeholder'] : '';
		$pattern      = isset( $field['pattern'] ) ? $field['pattern'] : '';
		$pattern_msg  = isset( $field['patternmsg'] ) ? $field['patternmsg'] : '';
		$min_value    = isset( $field['minvalue'] ) ? $field['minvalue'] : '';
		$max_value    = isset( $field['maxvalue'] ) ? $field['maxvalue'] : '';
		$length_limit = isset( $field['length'] ) ? absint( $field['length'] ) : 0;
		$duplicate    = ! empty( $field['duplicate'] );

		$value = isset( $values[ $name ] ) ? $values[ $name ] : '';

		$input_id   = 'resolate_dynamic_field_' . sanitize_key( $name );
		$input_name = self::REQUEST_KEY . '[' . $name . ']';

		echo '<div class="resolate-field" style="margin-bottom:16px;">';
		echo '<label for="' . esc_attr( $input_id ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( $title ) . '</label>';
		if ( $duplicate ) {
			echo '<p class="description" style="color:#cc1818;">' . esc_html__( 'Este campo aparece varias veces en la plantilla.', 'resolate' ) . '</p>';
		}

		$attr = array(
			'id'    => $input_id,
			'name'  => $input_name,
			'class' => 'widefat',
		);
		if ( '' !== $placeholder ) {
			$attr['placeholder'] = $placeholder;
		}
		if ( '' !== $pattern && in_array( $type, array( 'text', 'email', 'url' ), true ) ) {
			$attr['pattern'] = $pattern;
			if ( '' !== $pattern_msg ) {
				$attr['title'] = $pattern_msg;
			}
		}
		if ( $length_limit > 0 && in_array( $type, array( 'text', 'textarea', 'email', 'url' ), true ) ) {
			$attr['maxlength'] = (string) $length_limit;
		}

		switch ( $type ) {
			case 'number':
				$attr['type'] = 'number';
				$attr['step'] = 'any';
				if ( '' !== $min_value ) {
					$attr['min'] = $min_value;
				}
				if ( '' !== $max_value ) {
					$attr['max'] = $max_value;
				}
				echo '<input ' . $this->format_attributes( $attr ) . ' value="' . esc_attr( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped.
				break;
			case 'date':
				$attr['type'] = 'date';
				if ( '' !== $min_value ) {
					$attr['min'] = $min_value;
				}
				if ( '' !== $max_value ) {
					$attr['max'] = $max_value;
				}
				echo '<input ' . $this->format_attributes( $attr ) . ' value="' . esc_attr( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped.
				break;
			case 'email':
			case 'url':
				$attr['type'] = $type;
				echo '<input ' . $this->format_attributes( $attr ) . ' value="' . esc_attr( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped.
				break;
			case 'textarea':
				echo '<textarea ' . $this->format_attributes( $attr ) . ' rows="6">' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped.
				break;
			case 'html':
				wp_editor(
					$value,
					$input_id,
					array(
						'textarea_name' => $input_name,
						'textarea_rows' => 8,
						'editor_height' => 220,
						'media_buttons' => false,
					)
				);
				break;
			default:
				$attr['type'] = 'text';
				echo '<input ' . $this->format_attributes( $attr ) . ' value="' . esc_attr( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped.
				break;
		}

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Format HTML attributes for output.
	 *
	 * @param array $attributes Attribute map.
	 * @return string
	 */
	private function format_attributes( $attributes ) {
		$parts = array();
		foreach ( $attributes as $key => $value ) {
			$parts[] = sprintf( '%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Sanitize and validate a single field value.
	 *
	 * @param array        $field Field definition.
	 * @param string|array $raw   Raw submitted value.
	 * @return string|WP_Error
	 */
	private function sanitize_field_value( $field, $raw ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';
		$raw  = is_array( $raw ) ? '' : (string) $raw;
		$raw  = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		switch ( $type ) {
			case 'number':
				if ( ! is_numeric( $raw ) ) {
					return new WP_Error( 'resolate_dynamic_number', __( 'Introduce un número válido.', 'resolate' ) );
				}
				$value = $raw;
				break;
			case 'date':
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
					return new WP_Error( 'resolate_dynamic_date', __( 'Introduce una fecha en formato YYYY-MM-DD.', 'resolate' ) );
				}
				list( $y, $m, $d ) = array_map( 'intval', explode( '-', $raw ) );
				if ( ! checkdate( $m, $d, $y ) ) {
					return new WP_Error( 'resolate_dynamic_date', __( 'La fecha proporcionada no es válida.', 'resolate' ) );
				}
				$value = $raw;
				break;
			case 'email':
				if ( ! is_email( $raw ) ) {
					return new WP_Error( 'resolate_dynamic_email', __( 'Introduce una dirección de correo válida.', 'resolate' ) );
				}
				$value = sanitize_email( $raw );
				break;
			case 'url':
				$value = esc_url_raw( $raw );
				if ( '' === $value ) {
					return new WP_Error( 'resolate_dynamic_url', __( 'Introduce una URL válida.', 'resolate' ) );
				}
				break;
			case 'textarea':
				$value = sanitize_textarea_field( $raw );
				break;
			case 'html':
				$value = wp_kses_post( $raw );
				break;
			default:
				$value = sanitize_text_field( $raw );
				break;
		}

		$pattern = isset( $field['pattern'] ) ? (string) $field['pattern'] : '';
		if ( '' !== $pattern && in_array( $type, array( 'text', 'textarea', 'email', 'url', 'number' ), true ) ) {
			$regex = '/' . str_replace( '/', '\/', $pattern ) . '/u';
			$check = @preg_match( $regex, $value );
			if ( false === $check ) {
				return new WP_Error( 'resolate_dynamic_pattern', __( 'El patrón de validación no es válido.', 'resolate' ) );
			}
			if ( 1 !== $check ) {
				$message = isset( $field['patternmsg'] ) && '' !== $field['patternmsg'] ? $field['patternmsg'] : __( 'El valor no coincide con el formato requerido.', 'resolate' );
				return new WP_Error( 'resolate_dynamic_pattern', $message );
			}
		}

		$length_limit = isset( $field['length'] ) ? absint( $field['length'] ) : 0;
		if ( $length_limit > 0 && in_array( $type, array( 'text', 'textarea', 'email', 'url' ), true ) ) {
			if ( function_exists( 'mb_strlen' ) ) {
				if ( mb_strlen( $value ) > $length_limit ) {
					/* translators: %d: número máximo de caracteres permitidos. */
					return new WP_Error( 'resolate_dynamic_length', sprintf( __( 'El valor debe tener como máximo %d caracteres.', 'resolate' ), $length_limit ) );
				}
			} elseif ( strlen( $value ) > $length_limit ) {
				/* translators: %d: número máximo de caracteres permitidos. */
				return new WP_Error( 'resolate_dynamic_length', sprintf( __( 'El valor debe tener como máximo %d caracteres.', 'resolate' ), $length_limit ) );
			}
		}

		if ( 'number' === $type ) {
			$numeric = floatval( $value );
			if ( isset( $field['minvalue'] ) && '' !== $field['minvalue'] && $numeric < floatval( $field['minvalue'] ) ) {
				return new WP_Error( 'resolate_dynamic_number_min', __( 'El valor es inferior al mínimo permitido.', 'resolate' ) );
			}
			if ( isset( $field['maxvalue'] ) && '' !== $field['maxvalue'] && $numeric > floatval( $field['maxvalue'] ) ) {
				return new WP_Error( 'resolate_dynamic_number_max', __( 'El valor supera el máximo permitido.', 'resolate' ) );
			}
		}

		if ( 'date' === $type ) {
			if ( isset( $field['minvalue'] ) && '' !== $field['minvalue'] && $value < $field['minvalue'] ) {
				return new WP_Error( 'resolate_dynamic_date_min', __( 'La fecha es anterior al mínimo permitido.', 'resolate' ) );
			}
			if ( isset( $field['maxvalue'] ) && '' !== $field['maxvalue'] && $value > $field['maxvalue'] ) {
				return new WP_Error( 'resolate_dynamic_date_max', __( 'La fecha es posterior al máximo permitido.', 'resolate' ) );
			}
		}

		return $value;
	}

	/**
	 * Retrieve sanitized schema for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public static function get_schema_for_post( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( $term_id <= 0 ) {
			return array();
		}

		$raw = get_term_meta( $term_id, 'schema', true );
		if ( ! is_array( $raw ) ) {
			$raw = get_term_meta( $term_id, 'resolate_type_fields', true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$name      = isset( $entry['name'] ) ? self::clean_field_name( $entry['name'] ) : '';
			$merge_key = isset( $entry['merge_key'] ) ? self::clean_field_name( $entry['merge_key'] ) : $name;
			if ( '' === $name || '' === $merge_key ) {
				continue;
			}

			$type = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'text';
			if ( ! in_array( $type, array( 'text', 'textarea', 'html', 'number', 'date', 'email', 'url' ), true ) ) {
				$type = 'text';
			}

			$field = array(
				'name'        => $name,
				'merge_key'   => $merge_key,
				'title'       => isset( $entry['title'] ) && '' !== $entry['title'] ? sanitize_text_field( $entry['title'] ) : $merge_key,
				'type'        => $type,
				'placeholder' => $merge_key,
			);

			if ( isset( $entry['input_placeholder'] ) && '' !== $entry['input_placeholder'] ) {
				$field['input_placeholder'] = sanitize_text_field( $entry['input_placeholder'] );
			}

			if ( isset( $entry['description'] ) && '' !== $entry['description'] ) {
				$field['description'] = sanitize_textarea_field( $entry['description'] );
			}

			foreach ( array( 'pattern', 'patternmsg', 'minvalue', 'maxvalue' ) as $maybe ) {
				if ( empty( $entry[ $maybe ] ) ) {
					continue;
				}
				if ( 'patternmsg' === $maybe ) {
					$field[ $maybe ] = sanitize_text_field( $entry[ $maybe ] );
					continue;
				}
				$field[ $maybe ] = is_scalar( $entry[ $maybe ] ) ? (string) $entry[ $maybe ] : '';
			}

			if ( isset( $entry['length'] ) && absint( $entry['length'] ) > 0 ) {
				$field['length'] = absint( $entry['length'] );
			}

			if ( ! empty( $entry['duplicate'] ) ) {
				$field['duplicate'] = true;
			}

			$out[] = $field;
		}

		return $out;
	}

	/**
	 * Retrieve stored field values for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function get_field_values( $post_id ) {
		$schema = self::get_schema_for_post( $post_id );
		if ( empty( $schema ) ) {
			return array();
		}

		$values = array();
		foreach ( $schema as $field ) {
			$name = isset( $field['name'] ) ? (string) $field['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$stored = get_post_meta( $post_id, $name, true );
			$values[ $name ] = is_string( $stored ) ? $stored : '';
		}
		return $values;
	}

	/**
	 * Clean field names to remove control characters and normalize spaces.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function clean_field_name( $name ) {
		$name = is_string( $name ) ? $name : '';
		$name = preg_replace( '/[\x00-\x1F\x7F]/', '', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		return $name;
	}
}

new Resolate_Dynamic_Fields();
