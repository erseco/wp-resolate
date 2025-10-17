<?php
/**
 * Template parser helpers for Resolate.
 *
 * @package Resolate
 */

/**
 * Template parser utility for extracting OpenTBS field placeholders.
 */
class Resolate_Template_Parser {

		/**
		 * Extract placeholders from an OpenTBS-compatible template.
		 *
		 * @param string $template_path Absolute path to template file.
		 * @return array|string[]|WP_Error Array of unique placeholder slugs or WP_Error on failure.
		 */
	public static function extract_fields( $template_path ) {
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			return new WP_Error( 'resolate_template_missing', __( 'La plantilla seleccionada no se encuentra.', 'resolate' ) );
		}

		$extension = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'docx', 'odt' ), true ) ) {
			return new WP_Error( 'resolate_template_invalid', __( 'El archivo debe ser un DOCX u ODT.', 'resolate' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'resolate_zip_missing', __( 'ZipArchive no está disponible en el servidor.', 'resolate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $template_path ) ) {
			return new WP_Error( 'resolate_template_unzip', __( 'No se pudo abrir la plantilla para su análisis.', 'resolate' ) );
		}

		$targets = array();
		if ( 'docx' === $extension ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes camelCase properties.
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( 0 === strpos( $name, 'word/' ) && self::ends_with( $name, '.xml' ) ) {
					$targets[] = $name;
				}
			}
		} else {
			// ODT: main content plus styles (headers/footers).
			foreach ( array( 'content.xml', 'styles.xml' ) as $candidate ) {
				if ( false !== $zip->locateName( $candidate ) ) {
					$targets[] = $candidate;
				}
			}
		}

			   $placeholders = array();
		foreach ( $targets as $file ) {
				$contents = $zip->getFromName( $file );
			if ( false === $contents ) {
					   continue;
			}
				$normalized = self::normalize_xml_text( $contents );
			if ( '' === $normalized ) {
						   continue;
			}
						  preg_match_all( '/\[([^\]\r\n]+)\]/', $normalized, $matches );
			if ( empty( $matches[1] ) ) {
				 continue;
			}
			foreach ( $matches[1] as $raw_field ) {
				 $parsed = self::parse_placeholder( $raw_field );
				if ( empty( $parsed['placeholder'] ) ) {
						continue;
				}
				 $key = strtolower( $parsed['placeholder'] );
				if ( isset( $placeholders[ $key ] ) ) {
						// Prefer keeping parameters when multiple instances exist.
					if ( empty( $placeholders[ $key ]['parameters'] ) && ! empty( $parsed['parameters'] ) ) {
								   $placeholders[ $key ] = $parsed;
					}
						continue;
				}
				 $placeholders[ $key ] = $parsed;
			}
		}

			   $zip->close();

		if ( empty( $placeholders ) ) {
				return array();
		}

			   $fields = array();
		foreach ( $placeholders as $parsed ) {
				$fields[] = self::format_field_info( $parsed );
		}

		if ( ! empty( $fields ) ) {
				usort(
					$fields,
					static function ( $a, $b ) {
								$label_a = isset( $a['label'] ) ? $a['label'] : '';
								$label_b = isset( $b['label'] ) ? $b['label'] : '';
								return strnatcasecmp( $label_a, $label_b );
					}
				);
		}

			   return $fields;
	}

		/**
		 * Normalize XML string content to merge split OpenTBS placeholders.
		 *
		 * @param string $xml XML fragment.
		 * @return string Plain text representation.
		 */
	private static function normalize_xml_text( $xml ) {
		if ( '' === $xml ) {
			return '';
		}

		$replacements = array(
			'/<\/w:t>\s*<w:r[^>]*>\s*<w:t[^>]*>/' => '',
			'/<\/w:t>\s*<w:t[^>]*>/'               => '',
			'/<\/text:span>\s*<text:span[^>]*>/'   => '',
			'/<\/text:p>\s*<text:p[^>]*>/'         => ' ',
		);
		$normalized = $xml;
		foreach ( $replacements as $pattern => $replacement ) {
			$normalized = preg_replace( $pattern, $replacement, $normalized );
		}

		// Remove control characters that may interfere with regex detection.
		$normalized = preg_replace( '/[\x00-\x1F\x7F]/', '', $normalized );

		// Strip tags while keeping text.
		$normalized = wp_strip_all_tags( $normalized );
				return $normalized;
	}

		/**
		 * Build a normalized schema from detected field definitions, including array fields.
		 *
		 * @param array $fields Parsed placeholder definitions returned by extract_fields().
		 * @return array[]
		 */
	public static function build_schema_from_field_definitions( $fields ) {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$schema          = array();
		$used_names      = array();
		$allowed_types   = array( 'text', 'number', 'date', 'email', 'url', 'html', 'textarea' );
		$known_parameters = array( 'type', 'title', 'placeholder', 'description', 'pattern', 'patternmsg', 'minvalue', 'maxvalue', 'length' );

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$placeholder = isset( $field['placeholder'] ) ? trim( (string) $field['placeholder'] ) : '';
			if ( '' === $placeholder ) {
				continue;
			}

			$parameters = isset( $field['parameters'] ) && is_array( $field['parameters'] ) ? $field['parameters'] : array();
			$merge_key  = $placeholder;
			$name       = $merge_key;
			$suffix     = 2;

			while ( isset( $used_names[ $name ] ) ) {
				$name = $merge_key . '__' . $suffix;
				$suffix++;
			}

			$used_names[ $name ] = true;

			$type = isset( $parameters['type'] ) ? strtolower( (string) $parameters['type'] ) : '';
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'text';
			}

			$title            = isset( $parameters['title'] ) ? trim( (string) $parameters['title'] ) : '';
			$placeholder_attr = isset( $parameters['placeholder'] ) ? trim( (string) $parameters['placeholder'] ) : '';
			$description      = isset( $parameters['description'] ) ? trim( (string) $parameters['description'] ) : '';
			$pattern          = isset( $parameters['pattern'] ) ? (string) $parameters['pattern'] : '';
			$pattern_message  = isset( $parameters['patternmsg'] ) ? (string) $parameters['patternmsg'] : '';
			$min_value        = isset( $parameters['minvalue'] ) ? (string) $parameters['minvalue'] : '';
			$max_value        = isset( $parameters['maxvalue'] ) ? (string) $parameters['maxvalue'] : '';
			$length_value     = isset( $parameters['length'] ) ? (string) $parameters['length'] : '';

			$length = is_numeric( $length_value ) ? absint( $length_value ) : 0;

			$extras = array();
			foreach ( $parameters as $param_key => $param_value ) {
				if ( in_array( $param_key, $known_parameters, true ) ) {
					continue;
				}
				$extras[ $param_key ] = $param_value;
			}

			$entry = array(
				'name'              => $name,
				'merge_key'         => $merge_key,
				'title'             => '' !== $title ? $title : $merge_key,
				'type'              => $type,
				'placeholder'       => $merge_key,
				'input_placeholder' => $placeholder_attr,
				'description'       => $description,
			);

			$entry['slug']      = sanitize_key( str_replace( array( '.', ':' ), '_', strtolower( $merge_key ) ) );
			$entry['label']     = $entry['title'];
			$entry['data_type'] = $type;

			if ( '' !== $pattern ) {
				$entry['pattern'] = $pattern;
			}
			if ( '' !== $pattern_message ) {
				$entry['patternmsg'] = $pattern_message;
			}
			if ( '' !== $min_value ) {
				$entry['minvalue'] = $min_value;
			}
			if ( '' !== $max_value ) {
				$entry['maxvalue'] = $max_value;
			}
			if ( $length > 0 ) {
				$entry['length'] = $length;
			}
			if ( ! empty( $extras ) ) {
				$entry['parameters'] = $extras;
			}
			if ( $name !== $merge_key ) {
				$entry['duplicate'] = true;
			}

			$schema[] = $entry;
		}

		return $schema;
	}

	private static function humanize_key( $slug ) {
			$slug = str_replace( array( '-', '_', '.' ), ' ', strtolower( $slug ) );
			$slug = preg_replace( '/\s+/', ' ', $slug );
			$slug = trim( $slug );
		if ( '' === $slug ) {
				return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
				return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
			return ucwords( $slug );
	}

		/**
		 * Detect placeholder data type from parameters and slug heuristics.
		 *
		 * @param string $placeholder Placeholder name.
		 * @param array  $parameters  Placeholder parameters.
		 * @return string One of text|number|boolean|date.
		 */
	private static function detect_data_type( $placeholder, $parameters ) {
			$placeholder = strtolower( (string) $placeholder );
			$parameters  = is_array( $parameters ) ? $parameters : array();

		if ( isset( $parameters['ope'] ) ) {
				$ope = strtolower( (string) $parameters['ope'] );
			if ( in_array( $ope, array( 'tbs:num', 'tbs:curr', 'tbs:percent', 'xlsxnum', 'odsnum' ), true ) ) {
				return 'number';
			}
			if ( in_array( $ope, array( 'tbs:bool', 'xlsxbool', 'odsbool' ), true ) ) {
					return 'boolean';
			}
			if ( in_array( $ope, array( 'tbs:date', 'tbs:time', 'xlsxdate', 'odsdate', 'odstime' ), true ) ) {
					return 'date';
			}
		}

		foreach ( array( 'frm', 'format' ) as $key ) {
			if ( isset( $parameters[ $key ] ) ) {
					$candidate = strtolower( (string) $parameters[ $key ] );
				if ( preg_match( '/[dmyhs]/', $candidate ) ) {
					return 'date';
				}
			}
		}

		if ( preg_match( '/(date|fecha)$/', $placeholder ) ) {
				return 'date';
		}
		if ( preg_match( '/(total|amount|importe|suma|numero|number|qty|cantidad)$/', $placeholder ) ) {
				return 'number';
		}
		if ( preg_match( '/^(is|has|tiene|flag|activo|enabled)[._-]?/', $placeholder ) || preg_match( '/(flag|activo|enabled)$/', $placeholder ) ) {
				return 'boolean';
		}

			return 'text';
	}

		/**
		 * Detect array placeholder using bracket notation (e.g. field[*].key).
		 *
		 * @param string $placeholder Placeholder string.
		 * @return array{base:string,key:string,raw_key:string}|null
		 */
	private static function detect_array_placeholder_with_index( $placeholder ) {
			$placeholder = strtolower( trim( (string) $placeholder ) );
		if ( '' === $placeholder ) {
				return null;
		}

			$segments = explode( '.', $placeholder );
		if ( empty( $segments ) ) {
				return null;
		}

			$first = array_shift( $segments );
		if ( ! preg_match( '/^([a-z0-9_]+)\[(\*|\d+)\]$/', $first, $match ) ) {
				return null;
		}

		if ( empty( $segments ) ) {
				return null;
		}

			$raw_key = implode( '.', $segments );
			$key     = sanitize_key( str_replace( '.', '_', $raw_key ) );

			return array(
				'base'    => sanitize_key( $match[1] ),
				'key'     => $key,
				'raw_key' => $raw_key,
			);
	}

		/**
		 * Detect array placeholder using dot notation when repeat hints exist.
		 *
		 * @param string $placeholder Placeholder string.
		 * @return array{base:string,key:string,raw_key:string}|null
		 */
	private static function detect_array_placeholder_without_index( $placeholder ) {
			$placeholder = strtolower( trim( (string) $placeholder ) );
		if ( '' === $placeholder ) {
				return null;
		}

			$segments = explode( '.', $placeholder );
		if ( count( $segments ) < 2 ) {
				return null;
		}

			$base     = sanitize_key( array_shift( $segments ) );
			$raw_key  = implode( '.', $segments );
			$key      = sanitize_key( str_replace( '.', '_', $raw_key ) );

		if ( '' === $base || '' === $key ) {
				return null;
		}

			return array(
				'base'    => $base,
				'key'     => $key,
				'raw_key' => $raw_key,
			);
	}

		/**
		 * Infer the best suited control type for an array item.
		 *
		 * @param string $item_key  Item key.
		 * @param string $data_type Detected data type.
		 * @return string
		 */
	private static function infer_array_item_type( $item_key, $data_type ) {
					$item_key  = strtolower( (string) $item_key );
			$data_type = strtolower( (string) $data_type );

		if ( in_array( $data_type, array( 'number', 'date', 'boolean' ), true ) ) {
					return 'single';
		}

		if ( preg_match( '/^(number|numero|número|index|indice)$/', $item_key ) ) {
			return 'single';
		}

		if ( preg_match( '/^(title|titulo|título|heading|name)$/', $item_key ) ) {
			return 'single';
		}

		if ( preg_match( '/(content|texto|text|body|descripcion|descripción)$/', $item_key ) ) {
				return 'rich';
		}

					return 'textarea';
	}

		/**
		 * Infer the control type for a scalar field definition.
		 *
		 * @param string $slug         Field slug.
		 * @param string $label        Field label.
		 * @param string $data_type    Detected data type.
		 * @param string $placeholder  Placeholder name.
		 * @return string
		 */
	private static function infer_scalar_field_type( $slug, $label, $data_type, $placeholder ) {
		$data_type = strtolower( (string) $data_type );
		if ( in_array( $data_type, array( 'number', 'date', 'boolean' ), true ) ) {
			return 'single';
		}

		$haystack = strtolower( trim( (string) $slug . ' ' . (string) $label . ' ' . (string) $placeholder ) );

		if ( preg_match( '/\b(title|titulo|título|heading|subject|asunto|name|nombre)\b/u', $haystack ) ) {
			return 'single';
		}

		if ( preg_match( '/(content|contenido|texto|text|body|descripcion|descripción|detalle|summary|resumen)/u', $haystack ) ) {
				return 'rich';
		}

		return 'textarea';
	}

		/**
		 * Polyfill for str_ends_with to support older PHP versions.
		 *
		 * @param string $haystack Full string.
		 * @param string $needle   Ending to verify.
		 * @return bool
		 */
	private static function ends_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}
		$len = strlen( $needle );
		if ( $len > strlen( $haystack ) ) {
			return false;
		}
		return 0 === substr_compare( $haystack, $needle, -$len, $len );
	}
}
