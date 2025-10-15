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

			$array_defs    = array();
			$array_order   = array();
			$repeat_hints  = array();
			$pending       = array();
			$order_counter = 0;

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
					continue;
			}

				$placeholder = isset( $field['placeholder'] ) ? trim( (string) $field['placeholder'] ) : '';
				$parameters  = isset( $field['parameters'] ) && is_array( $field['parameters'] ) ? $field['parameters'] : array();
				$label       = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
				$data_type   = isset( $field['data_type'] ) ? sanitize_key( $field['data_type'] ) : 'text';

				$array_match = self::detect_array_placeholder_with_index( $placeholder );
			if ( $array_match ) {
					$base = $array_match['base'];
					$key  = $array_match['key'];

				if ( '' === $base || '' === $key ) {
						continue;
				}

					$repeat_hints[ $base ] = true;

				if ( ! isset( $array_defs[ $base ] ) ) {
						$array_defs[ $base ] = array(
							'slug'        => $base,
							'label'       => self::humanize_key( $base ),
							'type'        => 'array',
							'placeholder' => $base,
							'data_type'   => 'array',
							'item_schema' => array(),
							'_order'      => $order_counter++,
						);
						$array_order[] = $base;
				}

				if ( '' === $label ) {
						$label = self::humanize_key( $array_match['raw_key'] );
				}

				if ( ! isset( $array_defs[ $base ]['item_schema'][ $key ] ) ) {
						$item_data_type = self::detect_data_type( $placeholder, $parameters );
					if ( '' === $item_data_type ) {
							$item_data_type = 'text';
					}

						$array_defs[ $base ]['item_schema'][ $key ] = array(
							'label'     => $label,
							'type'      => self::infer_array_item_type( $key, $item_data_type ),
							'data_type' => $item_data_type,
						);
				}

					continue;
			}

			if ( isset( $parameters['repeat'] ) ) {
					$repeat_base = sanitize_key( $parameters['repeat'] );
				if ( '' !== $repeat_base ) {
						$repeat_hints[ $repeat_base ] = true;
				}
			}

				$pending[] = array(
					'field'       => $field,
					'placeholder' => $placeholder,
					'parameters'  => $parameters,
					'label'       => $label,
					'data_type'   => $data_type,
					'index'       => $index,
				);
		}

			$schema        = array();
			$scalar_fields = array();

		foreach ( $pending as $entry ) {
				$placeholder = $entry['placeholder'];
				$parameters  = $entry['parameters'];
				$label       = $entry['label'];
				$data_type   = $entry['data_type'];

				$dot_match = self::detect_array_placeholder_without_index( $placeholder );
			if ( $dot_match && ( isset( $repeat_hints[ $dot_match['base'] ] ) || isset( $array_defs[ $dot_match['base'] ] ) ) ) {
					$base = $dot_match['base'];
					$key  = $dot_match['key'];

				if ( '' === $base || '' === $key ) {
					continue;
				}

				if ( ! isset( $array_defs[ $base ] ) ) {
						$array_defs[ $base ] = array(
							'slug'        => $base,
							'label'       => self::humanize_key( $base ),
							'type'        => 'array',
							'placeholder' => $base,
							'data_type'   => 'array',
							'item_schema' => array(),
							'_order'      => $order_counter++,
						);
						$array_order[] = $base;
				}

				if ( ! isset( $array_defs[ $base ]['item_schema'][ $key ] ) ) {
						$item_data_type = self::detect_data_type( $placeholder, $parameters );
					if ( '' === $label ) {
							$label = self::humanize_key( $dot_match['raw_key'] );
					}
					if ( '' === $item_data_type ) {
							$item_data_type = 'text';
					}
						$array_defs[ $base ]['item_schema'][ $key ] = array(
							'label'     => ( '' !== $label ) ? $label : self::humanize_key( $dot_match['raw_key'] ),
							'type'      => self::infer_array_item_type( $key, $item_data_type ),
							'data_type' => $item_data_type,
						);
				}

					continue;
			}

				$slug = isset( $entry['field']['slug'] ) ? sanitize_key( $entry['field']['slug'] ) : '';
			if ( '' === $slug ) {
					$slug = sanitize_key( $placeholder );
			}

			if ( '' === $slug ) {
					continue;
			}

				$normalized_placeholder = '';
			if ( '' !== $placeholder ) {
					$normalized_placeholder = preg_replace( '/[^A-Za-z0-9._:-]/', '', $placeholder );
			}
			if ( '' === $label ) {
					$source = '' !== $normalized_placeholder ? $normalized_placeholder : $slug;
					$label  = self::humanize_key( $source );
			}

			if ( '' === $normalized_placeholder ) {
					$normalized_placeholder = $slug;
			}

			if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
					$data_type = 'text';
			}

			if ( in_array( $slug, array( 'onshow', 'ondata', 'block', 'var' ), true ) ) {
					continue;
			}

				$scalar_fields[] = array(
					'slug'        => $slug,
					'label'       => $label,
					'placeholder' => $normalized_placeholder,
					'data_type'   => $data_type,
					'_order'      => $entry['index'],
				);
		}

		if ( ! empty( $array_defs ) ) {
				uasort(
					$array_defs,
					static function ( $a, $b ) {
								$order_a = isset( $a['_order'] ) ? intval( $a['_order'] ) : 0;
								$order_b = isset( $b['_order'] ) ? intval( $b['_order'] ) : 0;
								return $order_a <=> $order_b;
					}
				);

			foreach ( $array_defs as $base => $def ) {
				unset( $def['_order'] );
				$schema[] = $def;
			}
		}

		if ( ! empty( $scalar_fields ) ) {
				usort(
					$scalar_fields,
					static function ( $a, $b ) {
								$order_a = isset( $a['_order'] ) ? intval( $a['_order'] ) : 0;
								$order_b = isset( $b['_order'] ) ? intval( $b['_order'] ) : 0;
								return $order_a <=> $order_b;
					}
				);

			foreach ( $scalar_fields as $field ) {
				unset( $field['_order'] );
				$field['type'] = self::infer_scalar_field_type(
					isset( $field['slug'] ) ? $field['slug'] : '',
					isset( $field['label'] ) ? $field['label'] : '',
					isset( $field['data_type'] ) ? $field['data_type'] : '',
					isset( $field['placeholder'] ) ? $field['placeholder'] : ''
				);
				$schema[] = $field;
			}
		}

		return $schema;
	}

		/**
		 * Parse a raw OpenTBS placeholder definition.
		 *
		 * @param string $raw_field Placeholder string without brackets.
		 * @return array{
		 *     raw: string,
		 *     placeholder: string,
		 *     parameters: array<string, string>
		 * }
		 */
	private static function parse_placeholder( $raw_field ) {
			$raw_field = trim( $raw_field );
		if ( '' === $raw_field ) {
				return array(
					'raw'         => '',
					'placeholder' => '',
					'parameters'  => array(),
				);
		}

			$parts       = preg_split( '/\s*;\s*/', $raw_field );
			$placeholder = trim( array_shift( $parts ) );
			$parameters  = array();

		if ( ! empty( $parts ) ) {
			foreach ( $parts as $param ) {
					$param = trim( $param );
				if ( '' === $param ) {
					continue;
				}
					$pair = explode( '=', $param, 2 );
					$name = strtolower( trim( $pair[0] ) );
				if ( '' === $name ) {
						continue;
				}
					$value = ( count( $pair ) > 1 ) ? strtolower( trim( $pair[1] ) ) : '1';
					$parameters[ $name ] = $value;
			}
		}

			return array(
				'raw'         => $raw_field,
				'placeholder' => $placeholder,
				'parameters'  => $parameters,
			);
	}

		/**
		 * Build normalized field information from a parsed placeholder.
		 *
		 * @param array $parsed Parsed placeholder data.
		 * @return array{
		 *     placeholder: string,
		 *     slug: string,
		 *     label: string,
		 *     data_type: string,
		 *     parameters: array<string, string>
		 * }
		 */
	private static function format_field_info( $parsed ) {
			$placeholder = isset( $parsed['placeholder'] ) ? (string) $parsed['placeholder'] : '';
			$parameters  = isset( $parsed['parameters'] ) && is_array( $parsed['parameters'] ) ? $parsed['parameters'] : array();

			$slug_source = self::normalize_slug_source( $placeholder );
			$slug        = sanitize_key( $slug_source );
		if ( '' === $slug ) {
				$slug = sanitize_key( str_replace( array( '.', ':' ), '_', strtolower( $placeholder ) ) );
		}

			$label_source = str_replace( array( '.', ':' ), ' ', $slug_source );
			$label        = self::humanize_key( $label_source );

			$data_type = self::detect_data_type( $placeholder, $parameters );

			return array(
				'placeholder' => $placeholder,
				'slug'        => $slug,
				'label'       => $label,
				'data_type'   => $data_type,
				'parameters'  => $parameters,
			);
	}

		/**
		 * Normalize the slug source by dropping known command prefixes.
		 *
		 * @param string $placeholder Placeholder name without parameters.
		 * @return string
		 */
	private static function normalize_slug_source( $placeholder ) {
			$placeholder = trim( (string) $placeholder );
		if ( '' === $placeholder ) {
				return '';
		}

			$segments = explode( '.', $placeholder );
		if ( count( $segments ) > 1 ) {
				$prefix          = strtolower( $segments[0] );
				$reserved_prefix = array(
					'onshow',
					'onload',
					'onchange',
					'onformat',
					'ondata',
					'onsection',
					'var',
					'block',
				);
				if ( in_array( $prefix, $reserved_prefix, true ) ) {
						array_shift( $segments );
						$placeholder = implode( '.', $segments );
				}
		}

			return $placeholder;
	}

		/**
		 * Human readable label from slug source.
		 *
		 * @param string $slug Slug source.
		 * @return string
		 */
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
