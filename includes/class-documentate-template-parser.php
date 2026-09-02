<?php
/**
 * Template parser helpers for Documentate.
 *
 * @package Documentate
 */

/**
 * Template parser utility for extracting OpenTBS field placeholders.
 */
class Documentate_Template_Parser {
	/**
	 * Parsed templates reused within this request, keyed by path and content hash.
	 *
	 * @var array<string,array>
	 */
	private static $fields_cache = array();

	/**
	 * OpenTBS `ope` operators mapped to the data type they imply.
	 *
	 * Consulted in declaration order.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $operator_data_types = array(
		'number' => array( 'tbs:num', 'tbs:curr', 'tbs:percent', 'xlsxnum', 'odsnum' ),
		'boolean' => array( 'tbs:bool', 'xlsxbool', 'odsbool' ),
		'date' => array( 'tbs:date', 'tbs:time', 'xlsxdate', 'odsdate', 'odstime' ),
	);

	/**
	 * Placeholder naming heuristics mapped to the data type they imply.
	 *
	 * Consulted in declaration order, and within each type in pattern order.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $slug_data_types = array(
		'date' => array( '/(date|fecha)$/' ),
		'number' => array( '/(total|amount|importe|suma|numero|number|qty|cantidad)$/' ),
		'boolean' => array(
			'/^(is|has|tiene|flag|activo|enabled)[._-]?/',
			'/(flag|activo|enabled)$/',
		),
	);

	/**
	 * Extract placeholders from an OpenTBS-compatible template.
	 *
	 * @param string $template_path Absolute path to template file.
	 * @return array|string[]|WP_Error Array of unique placeholder slugs or WP_Error on failure.
	 */
	public static function extract_fields( $template_path ) {
		$extension = self::validate_template( $template_path );
		if ( is_wp_error( $extension ) ) {
			return $extension;
		}

		// Hash the compressed file so same-size replacements within one second
		// cannot reuse stale fields. Do not persist this cache across requests.
		$hash = is_readable( $template_path ) ? hash_file( 'sha256', $template_path ) : false;
		$cached = self::$fields_cache[ $template_path ] ?? array();
		if ( false !== $hash && isset( $cached['hash'] ) && $hash === $cached['hash'] ) {
			return $cached['fields'];
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $template_path ) ) {
			return new WP_Error( 'documentate_template_unzip', 'No se pudo abrir la plantilla para su análisis.' );
		}

		$placeholders = self::collect_placeholders( $zip, self::locate_target_parts( $zip, $extension ) );

		$zip->close();

		$fields = self::build_sorted_fields( $placeholders );
		self::$fields_cache[ $template_path ] = array(
			'hash' => $hash,
			'fields' => $fields,
		);
		return $fields;
	}

	/**
	 * Check that a template can be analysed and return its extension.
	 *
	 * @param string $template_path Absolute path to template file.
	 * @return string|WP_Error Lowercased extension, or WP_Error when unusable.
	 */
	private static function validate_template( $template_path ) {
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			return new WP_Error( 'documentate_template_missing', 'La plantilla seleccionada no se encuentra.' );
		}

		$extension = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'docx', 'odt' ), true ) ) {
			return new WP_Error( 'documentate_template_invalid', 'El archivo debe ser un DOCX u ODT.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'documentate_zip_missing', 'ZipArchive no está disponible en el servidor.' );
		}

		return $extension;
	}

	/**
	 * List the archive entries that may contain placeholders.
	 *
	 * @param ZipArchive $zip       Opened template archive.
	 * @param string     $extension Template extension.
	 * @return string[]
	 */
	private static function locate_target_parts( ZipArchive $zip, $extension ) {
		$targets = array();

		if ( 'docx' !== $extension ) {
			// ODT: main content plus styles (headers/footers).
			foreach ( array( 'content.xml', 'styles.xml' ) as $candidate ) {
				if ( false !== $zip->locateName( $candidate ) ) {
					$targets[] = $candidate;
				}
			}

			return $targets;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes camelCase properties.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( str_starts_with( $name, 'word/' ) && self::ends_with( $name, '.xml' ) ) {
				$targets[] = $name;
			}
		}

		return $targets;
	}

	/**
	 * Parse every placeholder found across the given archive entries.
	 *
	 * @param ZipArchive $zip     Opened template archive.
	 * @param string[]   $targets Archive entries to read.
	 * @return array<string,array> Parsed placeholders keyed by lowercased name.
	 */
	private static function collect_placeholders( ZipArchive $zip, array $targets ) {
		$placeholders = array();

		foreach ( $targets as $file ) {
			foreach ( self::match_placeholders( $zip->getFromName( $file ) ) as $raw_field ) {
				self::merge_placeholder( $placeholders, $raw_field );
			}
		}

		return $placeholders;
	}

	/**
	 * Extract the raw bracketed placeholders from an archive entry.
	 *
	 * @param string|false $contents Raw entry contents, or false when unreadable.
	 * @return string[]
	 */
	private static function match_placeholders( $contents ) {
		if ( false === $contents ) {
			return array();
		}

		$normalized = self::normalize_xml_text( $contents );
		if ( '' === $normalized ) {
			return array();
		}

		preg_match_all( '/\[([^\]\r\n]+)\]/', $normalized, $matches );

		return empty( $matches[1] ) ? array() : $matches[1];
	}

	/**
	 * Add a parsed placeholder to the accumulator, keeping the richest copy.
	 *
	 * @param array<string,array> $placeholders Accumulated placeholders.
	 * @param string              $raw_field    Raw placeholder body.
	 * @return void
	 */
	private static function merge_placeholder( array &$placeholders, $raw_field ) {
		$parsed = self::parse_placeholder( $raw_field );
		if ( empty( $parsed['placeholder'] ) ) {
			return;
		}

		$key = strtolower( $parsed['placeholder'] );
		if ( ! isset( $placeholders[ $key ] ) ) {
			$placeholders[ $key ] = $parsed;
			return;
		}

		// Prefer keeping parameters when multiple instances exist.
		if ( empty( $placeholders[ $key ]['parameters'] ) && ! empty( $parsed['parameters'] ) ) {
			$placeholders[ $key ] = $parsed;
		}
	}

	/**
	 * Format parsed placeholders into field definitions sorted by label.
	 *
	 * @param array<string,array> $placeholders Parsed placeholders.
	 * @return array[]
	 */
	private static function build_sorted_fields( array $placeholders ) {
		$fields = array();
		foreach ( $placeholders as $parsed ) {
			$fields[] = self::format_field_info( $parsed );
		}

		usort(
			$fields,
			static function ( $a, $b ) {
				$label_a = isset( $a['label'] ) ? $a['label'] : '';
				$label_b = isset( $b['label'] ) ? $b['label'] : '';
				return strnatcasecmp( $label_a, $label_b );
			}
		);

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
			'/<\/w:t>\s*<w:t[^>]*>/' => '',
			'/<\/text:span>\s*<text:span[^>]*>/' => '',
			'/<\/text:p>\s*<text:p[^>]*>/' => ' ',
		);
		$normalized = $xml;
		foreach ( $replacements as $pattern => $replacement ) {
			$normalized = preg_replace( $pattern, $replacement, $normalized );
		}

		// Remove control characters that may interfere with regex detection.
		$normalized = preg_replace( '/[\x00-\x1F\x7F]/', '', $normalized );

		// Avoid treating ODT <style:...> as HTML <style>, which makes the
		// WordPress regex repeatedly scan for nonexistent </style> closers.
		// Only rename XML prefixes; retain WordPress's script/style cleanup.
		$normalized = preg_replace( '@(</?)(style|script):@i', '$1documentate-$2:', $normalized );
		return wp_strip_all_tags( $normalized );
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

		$state = array(
			'array_defs'    => array(),
			'repeat_hints'  => array(),
			'pending'       => array(),
			'order_counter' => 0,
		);

		foreach ( $fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			self::collect_field_definition_pass( $field, $index, $state );
		}

		$scalar_fields = array();
		foreach ( $state['pending'] as $entry ) {
			$result = self::resolve_pending_field_entry( $entry, $state );
			if ( null !== $result ) {
				$scalar_fields[] = $result;
			}
		}

		return self::finalize_schema_definitions( $state['array_defs'], $scalar_fields );
	}

	/**
	 * First pass: register indexed array placeholders and collect pending scalar/array candidates.
	 *
	 * @param array $field Field definition.
	 * @param int   $index Source order index.
	 * @param array $state Mutable collector state.
	 * @return void
	 */
	private static function collect_field_definition_pass( $field, $index, &$state ) {
		$placeholder = isset( $field['placeholder'] ) ? trim( (string) $field['placeholder'] ) : '';
		$parameters  = isset( $field['parameters'] ) && is_array( $field['parameters'] ) ? $field['parameters'] : array();
		$label       = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
		$data_type   = isset( $field['data_type'] ) ? sanitize_key( $field['data_type'] ) : 'text';

		$array_match = self::detect_array_placeholder_with_index( $placeholder );
		if ( $array_match ) {
			self::register_array_item_field(
				$state,
				$array_match['base'],
				$array_match['key'],
				$array_match['raw_key'],
				$placeholder,
				$parameters,
				$label
			);
			return;
		}

		if ( isset( $parameters['repeat'] ) ) {
			$repeat_base = sanitize_key( $parameters['repeat'] );
			if ( '' !== $repeat_base ) {
				$state['repeat_hints'][ $repeat_base ] = true;
			}
		}

		$state['pending'][] = array(
			'field'       => $field,
			'placeholder' => $placeholder,
			'parameters'  => $parameters,
			'label'       => $label,
			'data_type'   => $data_type,
			'index'       => $index,
		);
	}

	/**
	 * Second pass: attach unindexed array members or emit a scalar field definition.
	 *
	 * @param array $entry Pending entry from the first pass.
	 * @param array $state Mutable collector state.
	 * @return array|null Scalar field definition, or null when handled as array item.
	 */
	private static function resolve_pending_field_entry( $entry, &$state ) {
		$placeholder = $entry['placeholder'];
		$parameters  = $entry['parameters'];
		$label       = $entry['label'];
		$data_type   = $entry['data_type'];

		$dot_match = self::detect_array_placeholder_without_index( $placeholder );
		if (
			$dot_match
			&& ( isset( $state['repeat_hints'][ $dot_match['base'] ] ) || isset( $state['array_defs'][ $dot_match['base'] ] ) )
		) {
			self::register_array_item_field(
				$state,
				$dot_match['base'],
				$dot_match['key'],
				$dot_match['raw_key'],
				$placeholder,
				$parameters,
				$label
			);
			return null;
		}

		return self::build_scalar_field_definition( $entry );
	}

	/**
	 * Ensure an array field definition exists and register one item-schema entry.
	 *
	 * @param array  $state       Mutable collector state.
	 * @param string $base        Array base slug.
	 * @param string $key         Item key.
	 * @param string $raw_key     Raw key for humanized labels.
	 * @param string $placeholder Full placeholder string.
	 * @param array  $parameters  Placeholder parameters.
	 * @param string $label       Optional label.
	 * @return void
	 */
	private static function register_array_item_field( &$state, $base, $key, $raw_key, $placeholder, $parameters, $label ) {
		if ( '' === $base || '' === $key ) {
			return;
		}

		$state['repeat_hints'][ $base ] = true;

		if ( ! isset( $state['array_defs'][ $base ] ) ) {
			$state['array_defs'][ $base ] = array(
				'slug'        => $base,
				'label'       => self::humanize_key( $base ),
				'type'        => 'array',
				'placeholder' => $base,
				'data_type'   => 'array',
				'item_schema' => array(),
				'_order'      => $state['order_counter']++,
			);
		}

		if ( isset( $state['array_defs'][ $base ]['item_schema'][ $key ] ) ) {
			return;
		}

		if ( '' === $label ) {
			$label = self::humanize_key( $raw_key );
		}

		$item_data_type = self::detect_data_type( $placeholder, $parameters );
		if ( '' === $item_data_type ) {
			$item_data_type = 'text';
		}

		$state['array_defs'][ $base ]['item_schema'][ $key ] = array(
			'label'      => $label,
			'type'       => self::infer_array_item_type( $key, $item_data_type ),
			'data_type'  => $item_data_type,
			'parameters' => $parameters,
		);
	}

	/**
	 * Build a scalar field definition from a pending entry.
	 *
	 * @param array $entry Pending field entry.
	 * @return array|null
	 */
	private static function build_scalar_field_definition( $entry ) {
		$placeholder = $entry['placeholder'];
		$parameters  = $entry['parameters'];
		$label       = $entry['label'];
		$data_type   = $entry['data_type'];

		$slug = isset( $entry['field']['slug'] ) ? sanitize_key( $entry['field']['slug'] ) : '';
		if ( '' === $slug ) {
			$slug = sanitize_key( $placeholder );
		}
		if ( '' === $slug || in_array( $slug, array( 'onshow', 'ondata', 'block', 'var', 'sign' ), true ) ) {
			return null;
		}

		$normalized_placeholder = '' !== $placeholder
			? preg_replace( '/[^A-Za-z0-9._:-]/', '', $placeholder )
			: '';
		if ( '' === $normalized_placeholder ) {
			$normalized_placeholder = $slug;
		}
		if ( '' === $label ) {
			$label = self::humanize_key( $normalized_placeholder );
		}
		if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
			$data_type = 'text';
		}

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'placeholder' => $normalized_placeholder,
			'data_type'   => $data_type,
			'parameters'  => $parameters,
			'_order'      => $entry['index'],
		);
	}

	/**
	 * Sort and assemble the final schema array from array + scalar definitions.
	 *
	 * @param array $array_defs    Array field definitions keyed by base slug.
	 * @param array $scalar_fields Scalar field definitions.
	 * @return array[]
	 */
	private static function finalize_schema_definitions( $array_defs, $scalar_fields ) {
		return array_merge(
			self::ordered_array_field_definitions( $array_defs ),
			self::ordered_scalar_field_definitions( $scalar_fields )
		);
	}

	/**
	 * Sort array field definitions by capture order.
	 *
	 * @param array $array_defs Definitions keyed by base slug.
	 * @return array[]
	 */
	private static function ordered_array_field_definitions( $array_defs ) {
		if ( empty( $array_defs ) ) {
			return array();
		}

		uasort( $array_defs, array( self::class, 'compare_schema_order' ) );

		$schema = array();
		foreach ( $array_defs as $def ) {
			unset( $def['_order'] );
			$schema[] = $def;
		}
		return $schema;
	}

	/**
	 * Sort scalar field definitions and assign inferred types.
	 *
	 * @param array $scalar_fields Scalar field definitions.
	 * @return array[]
	 */
	private static function ordered_scalar_field_definitions( $scalar_fields ) {
		if ( empty( $scalar_fields ) ) {
			return array();
		}

		usort( $scalar_fields, array( self::class, 'compare_schema_order' ) );

		$schema = array();
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
		return $schema;
	}

	/**
	 * Compare two schema entries by their internal _order key.
	 *
	 * @param array $a First entry.
	 * @param array $b Second entry.
	 * @return int
	 */
	private static function compare_schema_order( $a, $b ) {
		$order_a = isset( $a['_order'] ) ? intval( $a['_order'] ) : 0;
		$order_b = isset( $b['_order'] ) ? intval( $b['_order'] ) : 0;
		return $order_a <=> $order_b;
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
				'raw' => '',
				'placeholder' => '',
				'parameters' => array(),
			);
		}

		$parts = preg_split( '/\s*;\s*/', $raw_field );
		$placeholder = trim( array_shift( $parts ) );
		$parameters = array();

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
				$value = count( $pair ) > 1 ? strtolower( trim( $pair[1] ) ) : '1';
				$parameters[ $name ] = $value;
			}
		}

		return array(
			'raw' => $raw_field,
			'placeholder' => $placeholder,
			'parameters' => $parameters,
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
		$parameters = isset( $parsed['parameters'] ) && is_array( $parsed['parameters'] ) ? $parsed['parameters'] : array();

		$slug_source = self::normalize_slug_source( $placeholder );
		$slug = sanitize_key( $slug_source );
		if ( '' === $slug ) {
			$slug = sanitize_key( str_replace( array( '.', ':' ), '_', strtolower( $placeholder ) ) );
		}

		$label_source = str_replace( array( '.', ':' ), ' ', $slug_source );
		$label = self::humanize_key( $label_source );

		$data_type = self::detect_data_type( $placeholder, $parameters );

		return array(
			'placeholder' => $placeholder,
			'slug' => $slug,
			'label' => $label,
			'data_type' => $data_type,
			'parameters' => $parameters,
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
			$prefix = strtolower( $segments[0] );
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
		$parameters = is_array( $parameters ) ? $parameters : array();

		$type = self::type_from_operator( $parameters );
		if ( '' === $type ) {
			$type = self::type_from_format( $parameters );
		}
		if ( '' === $type ) {
			$type = self::type_from_slug( strtolower( (string) $placeholder ) );
		}

		return '' === $type ? 'text' : $type;
	}

	/**
	 * Resolve the data type from the OpenTBS `ope` parameter.
	 *
	 * @param array $parameters Placeholder parameters.
	 * @return string Detected type, or an empty string when undecided.
	 */
	private static function type_from_operator( array $parameters ) {
		if ( ! isset( $parameters['ope'] ) ) {
			return '';
		}

		$ope = strtolower( (string) $parameters['ope'] );
		foreach ( self::$operator_data_types as $type => $operators ) {
			if ( in_array( $ope, $operators, true ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Resolve the data type from a date-like format parameter.
	 *
	 * @param array $parameters Placeholder parameters.
	 * @return string Detected type, or an empty string when undecided.
	 */
	private static function type_from_format( array $parameters ) {
		foreach ( array( 'frm', 'format' ) as $key ) {
			if ( isset( $parameters[ $key ] ) && preg_match( '/[dmyhs]/', strtolower( (string) $parameters[ $key ] ) ) ) {
				return 'date';
			}
		}

		return '';
	}

	/**
	 * Resolve the data type from naming heuristics on the placeholder slug.
	 *
	 * @param string $placeholder Lowercased placeholder name.
	 * @return string Detected type, or an empty string when undecided.
	 */
	private static function type_from_slug( $placeholder ) {
		foreach ( self::$slug_data_types as $type => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $placeholder ) ) {
					return $type;
				}
			}
		}

		return '';
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
		$key = sanitize_key( str_replace( '.', '_', $raw_key ) );

		return array(
			'base' => sanitize_key( $match[1] ),
			'key' => $key,
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

		$base = sanitize_key( array_shift( $segments ) );
		$raw_key = implode( '.', $segments );
		$key = sanitize_key( str_replace( '.', '_', $raw_key ) );

		if ( '' === $base || '' === $key ) {
			return null;
		}

		return array(
			'base' => $base,
			'key' => $key,
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
		$item_key = strtolower( (string) $item_key );
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
	 * Check whether a template contains a [sign] placeholder.
	 *
	 * @param string $template_path Absolute path to template file.
	 * @return bool True if the [sign] placeholder exists in the template.
	 */
	public static function template_has_sign_placeholder( $template_path ) {
		return false !== self::get_sign_placeholder_info( $template_path );
	}

	/**
	 * Get [sign] placeholder info including optional position parameters.
	 *
	 * Supports parameters: x, y (PDF points from bottom-left), page (page number, -1 = last).
	 * Example: [sign;x=100;y=200] or [sign;x=50;y=80;page=2]
	 *
	 * @param string $template_path Absolute path to template file.
	 * @return array{x: int, y: int, page: int}|false Position data or false if not found.
	 */
	public static function get_sign_placeholder_info( $template_path ) {
		$fields = self::extract_fields( $template_path );
		if ( is_wp_error( $fields ) || empty( $fields ) ) {
			return false;
		}
		foreach ( $fields as $field ) {
			if ( ! isset( $field['placeholder'] ) || 'sign' !== strtolower( $field['placeholder'] ) ) {
				continue;
			}

			$params = isset( $field['parameters'] ) && is_array( $field['parameters'] ) ? $field['parameters'] : array();
			return array(
				'x' => isset( $params['x'] ) ? intval( $params['x'] ) : 0,
				'y' => isset( $params['y'] ) ? intval( $params['y'] ) : 0,
				'page' => isset( $params['page'] ) ? intval( $params['page'] ) : 0,
			);
		}
		return false;
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
