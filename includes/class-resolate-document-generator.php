<?php
/**
 * Document generator for Resolate based on OpenTBS templates.
 *
 * Generates DOCX/ODT using preconfigured templates via OpenTBS. PDF is
 * currently handled via print preview from the admin UI.
 *
 * @package Resolate
 */

/**
 * Resolate document generator service.
 */
class Resolate_Document_Generator {

	/**
	 * Generate a DOCX file for a given Document post using a DOCX template.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_docx( $post_id ) {
		try {
			$docx_template = self::get_template_path( $post_id, 'docx' );
			if ( '' !== $docx_template ) {
				return self::render_with_template( $post_id, $docx_template, 'docx' );
			}

			$odt_template = self::get_template_path( $post_id, 'odt' );
			if ( '' === $odt_template ) {
				return new WP_Error(
					'resolate_template_missing',
					__( 'Configura una plantilla DOCX en el tipo de documento seleccionado.', 'resolate' )
				);
			}

			$base_odt = self::render_with_template( $post_id, $odt_template, 'odt' );
			if ( is_wp_error( $base_odt ) ) {
				return $base_odt;
			}

			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-conversion-manager.php';
			if ( ! Resolate_Conversion_Manager::is_available() ) {
				return new WP_Error(
					'resolate_conversion_not_available',
					Resolate_Conversion_Manager::get_unavailable_message( 'odt', 'docx' )
				);
			}

			$target = self::build_output_path( $post_id, 'docx' );
			$result = Resolate_Conversion_Manager::convert( $base_odt, $target, 'docx', 'odt' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $target;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_docx_error', $e->getMessage() );
		}
	}

	/**
	 * Generate an ODT file for a given Document post using an ODT template.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_odt( $post_id ) {
		try {
			$odt_template = self::get_template_path( $post_id, 'odt' );
			if ( '' !== $odt_template ) {
				return self::render_with_template( $post_id, $odt_template, 'odt' );
			}

			$docx_template = self::get_template_path( $post_id, 'docx' );
			if ( '' === $docx_template ) {
				return new WP_Error(
					'resolate_template_missing',
					__( 'Configura una plantilla ODT en el tipo de documento seleccionado.', 'resolate' )
				);
			}

			$base_docx = self::render_with_template( $post_id, $docx_template, 'docx' );
			if ( is_wp_error( $base_docx ) ) {
				return $base_docx;
			}

			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-conversion-manager.php';
			if ( ! Resolate_Conversion_Manager::is_available() ) {
				return new WP_Error(
					'resolate_conversion_not_available',
					Resolate_Conversion_Manager::get_unavailable_message( 'docx', 'odt' )
				);
			}

			$target = self::build_output_path( $post_id, 'odt' );
			$result = Resolate_Conversion_Manager::convert( $base_docx, $target, 'odt', 'docx' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $target;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_odt_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve sanitized schema definition for a document type.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	private static function get_type_schema( $term_id ) {
		$raw = get_term_meta( $term_id, 'schema', true );
		if ( ! is_array( $raw ) ) {
			$raw = get_term_meta( $term_id, 'resolate_type_fields', true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug  = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
			$label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
			$type  = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
			if ( '' === $slug || '' === $label ) {
				continue;
			}
			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}
			$out[] = array(
				'slug'  => $slug,
				'label' => $label,
				'type'  => $type,
			);
		}
		return $out;
	}

	/**
	 * Generate a PDF file using the configured conversion engine.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
	 */
	public static function generate_pdf( $post_id ) {
		try {
			$source_path   = '';
			$source_format = '';

			$odt_result = self::generate_odt( $post_id );
			if ( is_wp_error( $odt_result ) ) {
				$docx_result = self::generate_docx( $post_id );
				if ( is_wp_error( $docx_result ) ) {
					return new WP_Error(
						'resolate_pdf_source_missing',
						__( 'No se pudo generar el documento base porque el tipo de documento no tiene una plantilla DOCX u ODT configurada.', 'resolate' ),
						array(
							'odt'  => $odt_result,
							'docx' => $docx_result,
						)
					);
				}
				$source_path   = $docx_result;
				$source_format = 'docx';
			} else {
				$source_path   = $odt_result;
				$source_format = 'odt';
			}

			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-conversion-manager.php';
			if ( ! Resolate_Conversion_Manager::is_available() ) {
				return new WP_Error(
					'resolate_conversion_not_available',
					Resolate_Conversion_Manager::get_unavailable_message( $source_format, 'pdf' )
				);
			}

			$target = self::build_output_path( $post_id, 'pdf' );

			$result = Resolate_Conversion_Manager::convert( $source_path, $target, 'pdf', $source_format );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $target;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_pdf_error', $e->getMessage() );
		}
	}

	/**
	 * Retrieve the template path associated with a document post for a format.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $format  Desired template format (docx|odt).
	 * @return string Template path or empty string when not available.
	 */
	public static function get_template_path( $post_id, $format ) {
		$format = sanitize_key( $format );
		if ( ! in_array( $format, array( 'docx', 'odt' ), true ) ) {
			return '';
		}

		$tpl_id = 0;
		$types  = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_id       = intval( $types[0] );
			$type_template = intval( get_term_meta( $type_id, 'resolate_type_template_id', true ) );
			$template_kind = sanitize_key( (string) get_term_meta( $type_id, 'resolate_type_template_type', true ) );
			if ( 0 < $type_template ) {
				if ( $template_kind === $format ) {
					$tpl_id = $type_template;
				} elseif ( '' === $template_kind ) {
					$path = get_attached_file( $type_template );
					if ( $path && strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) === $format ) {
						$tpl_id = $type_template;
					}
				}
			}
			if ( 0 >= $tpl_id ) {
				$meta_key = 'resolate_type_' . $format . '_template';
				$tpl_id   = intval( get_term_meta( $type_id, $meta_key, true ) );
			}
		}

		if ( 0 >= $tpl_id ) {
			return '';
		}

		$template_path = get_attached_file( $tpl_id );
		if ( ! $template_path || ! file_exists( $template_path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );
		if ( $format !== $ext ) {
			return '';
		}

		return $template_path;
	}

	/**
	 * Render a template using OpenTBS and return the generated document path.
	 *
	 * @param int    $post_id         Document post ID.
	 * @param string $template_path   Absolute template path.
	 * @param string $template_format Template format (docx|odt).
	 * @return string|WP_Error
	 */
	private static function render_with_template( $post_id, $template_path, $template_format ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

		$fields = self::build_merge_fields( $post_id );
		$path   = self::build_output_path( $post_id, $template_format );

		if ( 'docx' === $template_format ) {
			$res = Resolate_OpenTBS::render_docx( $template_path, $fields, $path );
		} else {
			$res = Resolate_OpenTBS::render_odt( $template_path, $fields, $path );
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return $path;
	}

	/**
	 * Build the array of merge fields used by OpenTBS templates.
	 *
	 * @param int $post_id Document post ID.
	 * @return array
	 */
	private static function build_merge_fields( $post_id ) {
		$opts = get_option( 'resolate_settings', array() );
		$post = get_post( $post_id );
		$structured = array();
		if ( $post && class_exists( 'Resolate_Documents' ) ) {
			$structured = Resolate_Documents::parse_structured_content( $post->post_content );
		}

		$fields = array(
			'title'  => get_the_title( $post_id ),
			'margen' => wp_strip_all_tags( isset( $opts['doc_margin_text'] ) ? $opts['doc_margin_text'] : '' ),
		);

		$types = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_id = intval( $types[0] );
			$schema  = array();
			if ( class_exists( 'Resolate_Documents' ) ) {
				$schema = Resolate_Documents::get_term_schema( $type_id );
			} else {
				$schema = self::get_type_schema( $type_id );
			}
			foreach ( $schema as $def ) {
				if ( empty( $def['slug'] ) ) {
								continue;
				}
					$slug        = sanitize_key( $def['slug'] );
					$placeholder = isset( $def['placeholder'] ) ? self::sanitize_placeholder_name( $def['placeholder'] ) : '';
				if ( '' === $placeholder ) {
									$placeholder = $slug;
				}
										$data_type = isset( $def['data_type'] ) ? sanitize_key( $def['data_type'] ) : 'text';
										$type      = isset( $def['type'] ) ? sanitize_key( $def['type'] ) : 'textarea';

				if ( 'array' === $type ) {
						$items = self::get_array_field_items_for_merge( $structured, $slug, $post_id );
						$fields[ $placeholder ] = $items;
						continue;
				}

										$value = self::get_structured_field_value( $structured, $slug, $post_id );
										$value = wp_strip_all_tags( $value );
										$fields[ $placeholder ] = self::normalize_field_value( $value, $data_type );
			}

			$logos = get_term_meta( $type_id, 'resolate_type_logos', true );
			if ( is_array( $logos ) && ! empty( $logos ) ) {
				$i = 1;
				foreach ( $logos as $att_id ) {
					$att_id = intval( $att_id );
					if ( $att_id <= 0 ) {
						continue;
					}
					$fields[ 'logo' . $i . '_path' ] = get_attached_file( $att_id );
					$fields[ 'logo' . $i . '_url' ]  = wp_get_attachment_url( $att_id );
					$i++;
				}
			}
		}

		if ( ! empty( $structured ) ) {
			foreach ( $structured as $slug => $info ) {
						$slug = sanitize_key( $slug );
				if ( '' === $slug ) {
					continue;
				}
						$placeholder = $slug;
				if ( isset( $fields[ $placeholder ] ) && '' !== $fields[ $placeholder ] ) {
					continue;
				}
				if ( isset( $info['type'] ) && 'array' === sanitize_key( $info['type'] ) ) {
						$fields[ $placeholder ] = self::get_array_field_items_for_merge( $structured, $slug, $post_id );
						continue;
				}

						$value = '';
				if ( isset( $info['value'] ) ) {
						$value = (string) $info['value'];
				}
				if ( '' === $value ) {
						$value = self::get_structured_field_value( $structured, $slug, $post_id );
				}
						$fields[ $placeholder ] = wp_strip_all_tags( $value );
			}
		}

				return $fields;
	}


	/**
	 * Get a field value from structured content with dynamic meta fallback.
	 *
	 * @param array  $structured Structured field map.
	 * @param string $slug       Field slug.
	 * @param int    $post_id    Post ID.
	 * @return string
	 */
	private static function get_structured_field_value( $structured, $slug, $post_id ) {
			$slug = sanitize_key( $slug );
		if ( '' !== $slug && isset( $structured[ $slug ] ) && is_array( $structured[ $slug ] ) ) {
				$value = isset( $structured[ $slug ]['value'] ) ? (string) $structured[ $slug ]['value'] : '';
			if ( '' !== $value ) {
				return $value;
			}
		}

		if ( '' !== $slug ) {
				$meta_key = 'resolate_field_' . $slug;
				$legacy   = get_post_meta( $post_id, $meta_key, true );
			if ( '' !== $legacy ) {
					return (string) $legacy;
			}
		}

			return '';
	}

		/**
		 * Retrieve array field items for template merges.
		 *
		 * @param array  $structured Structured map from post content.
		 * @param string $slug       Field slug.
		 * @param int    $post_id    Post ID.
		 * @return array<int, array<string, string>>
		 */
	private static function get_array_field_items_for_merge( $structured, $slug, $post_id ) {
			$slug  = sanitize_key( $slug );
			$value = '';

		if ( '' !== $slug && isset( $structured[ $slug ] ) && is_array( $structured[ $slug ] ) && isset( $structured[ $slug ]['value'] ) ) {
				$value = (string) $structured[ $slug ]['value'];
		}

		if ( '' === $value && '' !== $slug ) {
				$meta_value = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
			if ( '' !== $meta_value ) {
					$value = (string) $meta_value;
			}
		}

		if ( '' === $value && '' !== $slug ) {
				$legacy = get_post_meta( $post_id, 'resolate_' . $slug, true );
			if ( empty( $legacy ) && 'annexes' === $slug ) {
					$legacy = get_post_meta( $post_id, 'resolate_annexes', true );
			}
			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
					$value = wp_json_encode( $legacy );
			}
		}

			return Resolate_Documents::decode_array_field_value( $value );
	}

	/**
	 * Build (and ensure) the target path for a generated document.
	 *
	 * @param int    $post_id   Document post ID.
	 * @param string $extension File extension (docx|odt|pdf).
	 * @return string
	 */
	private static function build_output_path( $post_id, $extension ) {
		$extension = sanitize_key( $extension );
		$dir       = self::ensure_output_dir();
		$filename  = sanitize_title( get_the_title( $post_id ) ) . '-' . $post_id . '.' . $extension;

		return trailingslashit( $dir ) . $filename;
	}

	/**
	 * Ensure the plugin output directory exists within uploads.
	 *
	 * @return string Absolute directory path.
	 */
	private static function ensure_output_dir() {
			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
		if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
		}

			return $dir;
	}

		/**
		 * Sanitize placeholders preserving TinyButStrong supported characters.
		 *
		 * @param string $placeholder Placeholder name.
		 * @return string
		 */
	private static function sanitize_placeholder_name( $placeholder ) {
			$placeholder = (string) $placeholder;
			$placeholder = preg_replace( '/[^A-Za-z0-9._:-]/', '', $placeholder );
			return $placeholder;
	}

		/**
		 * Normalize a field value based on the detected data type.
		 *
		 * @param string $value     Original value.
		 * @param string $data_type Detected data type.
		 * @return mixed
		 */
	private static function normalize_field_value( $value, $data_type ) {
			$value     = is_string( $value ) ? trim( $value ) : $value;
			$data_type = sanitize_key( $data_type );

		switch ( $data_type ) {
			case 'number':
				if ( '' === $value ) {
						return '';
				}
				if ( is_numeric( $value ) ) {
						return 0 + $value;
				}
				$filtered = preg_replace( '/[^0-9.,\-]/', '', (string) $value );
				if ( '' === $filtered ) {
						return '';
				}
				$normalized = str_replace( ',', '.', $filtered );
				if ( is_numeric( $normalized ) ) {
						return 0 + $normalized;
				}
				return $value;
			case 'boolean':
				if ( is_bool( $value ) ) {
					return $value ? 1 : 0;
				}
					$value = strtolower( (string) $value );
				if ( in_array( $value, array( '1', 'true', 'si', 'sí', 'yes', 'on' ), true ) ) {
						return 1;
				}
				if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
						return 0;
				}
				return '' === $value ? 0 : 0;
			case 'date':
				if ( '' === $value ) {
						return '';
				}
					$timestamp = strtotime( (string) $value );
				if ( false === $timestamp ) {
						return $value;
				}
				return wp_date( 'Y-m-d', $timestamp );
			default:
				return $value;
		}
	}

	/**
	 * Generate DOCX for a Law post using the same configured DOCX template.
	 * Expects placeholders like [title] and [contenido] in the template.
	 *
	 * @param int $post_id Post ID.
	 * @return string|WP_Error
	 */
	public static function generate_docx_law( $post_id ) {
		try {
			$opts   = get_option( 'resolate_settings', array() );
			$tpl_id = isset( $opts['docx_template_id'] ) ? intval( $opts['docx_template_id'] ) : 0;
			if ( $tpl_id <= 0 ) {
				return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla DOCX configurada.', 'resolate' ) );
			}
			$template_path = get_attached_file( $tpl_id );
			if ( ! $template_path || ! file_exists( $template_path ) ) {
				return new WP_Error( 'resolate_template_missing', __( 'Plantilla DOCX no encontrada.', 'resolate' ) );
			}

			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

			$content = get_post_field( 'post_content', $post_id );
			$content = apply_filters( 'the_content', $content );
			$fields = array(
				'title'     => get_the_title( $post_id ),
				'contenido' => wp_strip_all_tags( (string) $content ),
			);

			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.docx';
			$path     = trailingslashit( $dir ) . $filename;

			$res = Resolate_OpenTBS::render_docx( $template_path, $fields, $path );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return $path;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_docx_error', $e->getMessage() );
		}
	}

	/**
	 * Generate ODT for a Law post using the same configured ODT template.
	 * Placeholders expected: [title], [contenido].
	 *
	 * @param int $post_id Post ID.
	 * @return string|WP_Error
	 */
	public static function generate_odt_law( $post_id ) {
		try {
			$opts   = get_option( 'resolate_settings', array() );
			$tpl_id = isset( $opts['odt_template_id'] ) ? intval( $opts['odt_template_id'] ) : 0;
			if ( $tpl_id <= 0 ) {
				return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla ODT configurada.', 'resolate' ) );
			}
			$template_path = get_attached_file( $tpl_id );
			if ( ! $template_path || ! file_exists( $template_path ) ) {
				return new WP_Error( 'resolate_template_missing', __( 'Plantilla ODT no encontrada.', 'resolate' ) );
			}

			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

			$content = get_post_field( 'post_content', $post_id );
			$content = apply_filters( 'the_content', $content );
			$fields = array(
				'title'     => get_the_title( $post_id ),
				'contenido' => wp_strip_all_tags( (string) $content ),
			);

			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.odt';
			$path     = trailingslashit( $dir ) . $filename;

			$res = Resolate_OpenTBS::render_odt( $template_path, $fields, $path );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return $path;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_odt_error', $e->getMessage() );
		}
	}
}
