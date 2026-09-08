<?php
/**
 * Schema extractor for document type templates.
 *
 * @package Documentate
 */

namespace Documentate\DocType;

use WP_Error;
use ZipArchive;

/**
 * Parses DOCX/ODT templates to build a normalized schema definition.
 */
class SchemaExtractor {
	const SCHEMA_VERSION = 2;

	/**
	 * TinyButStrong/OpenTBS visibility directive names.
	 * These should NOT be treated as data repeaters.
	 *
	 * @var array<int, string>
	 */
	const VISIBILITY_DIRECTIVES = array(
		'onshow',
		'ondata',
		'onload',
		'onformat',
		'onsection',
	);

	/**
	 * Field attributes read from placeholder parameters, in the order the
	 * schema entry declares them, mapped to whether the value is sanitised
	 * as text rather than kept as a raw string.
	 *
	 * @var array<string, bool>
	 */
	private static $field_attributes = array(
		'title' => true,
		'placeholder' => true,
		'description' => true,
		'pattern' => false,
		'patternmsg' => true,
		'minvalue' => false,
		'maxvalue' => false,
		'length' => false,
		'rol' => true,
	);

	/**
	 * Schema keys carried over from a collected repeater field, in the order
	 * the entry declares them, mapped to their default value.
	 *
	 * @var array<string, string>
	 */
	private static $repeater_field_defaults = array(
		'type' => 'text',
		'title' => '',
		'placeholder' => '',
		'description' => '',
		'pattern' => '',
		'patternmsg' => '',
		'minvalue' => '',
		'maxvalue' => '',
		'length' => '',
		'rol' => '',
		'case' => '',
	);

	/**
	 * Values the "rol" placeholder attribute may take.
	 *
	 * @var string[]
	 */
	const ROLES = array( 'area', 'gestion' );

	/**
	 * Default validation patterns by field type.
	 *
	 * @var array<string, array{pattern: string, message: string}>
	 */
	private static $default_patterns = array(
		'email' => array(
			'pattern' => '^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$',
			'message' => '', // Set at runtime for translation.
		),
	);

	/**
	 * Get default pattern configuration for a field type.
	 *
	 * @param string $field_type Field type.
	 * @return array{pattern: string, message: string}|null
	 */
	private static function get_default_pattern( $field_type ) {
		if ( ! isset( self::$default_patterns[ $field_type ] ) ) {
			return null;
		}

		$config = self::$default_patterns[ $field_type ];

		// Handle translatable messages at runtime.
		if ( 'email' === $field_type && '' === $config['message'] ) {
			$config['message'] = 'Introduce un email válido (usuario@dominio.tld)';
		}

		return $config;
	}

	/**
	 * Extract the schema for a given template file.
	 *
	 * @param string $template_path Absolute path to the template file.
	 * @return array|WP_Error Schema array on success or WP_Error on failure.
	 */
	public function extract( $template_path ) {
		$template_path = (string) $template_path;

		if ( '' === $template_path || ! file_exists( $template_path ) || ! is_readable( $template_path ) ) {
			return new WP_Error(
				'documentate_schema_template_missing',
				'El archivo de plantilla seleccionado no es accesible.'
			);
		}

		$template_type = $this->detect_template_type( $template_path );
		if ( '' === $template_type ) {
			return new WP_Error(
				'documentate_schema_template_type',
				'La plantilla debe ser un archivo DOCX u ODT.'
			);
		}

		$placeholders = $this->collect_placeholders( $template_path, $template_type );
		if ( is_wp_error( $placeholders ) ) {
			return $placeholders;
		}

		$schema = $this->build_schema( $placeholders, $template_type, $template_path );
		if ( is_wp_error( $schema ) ) {
			return $schema;
		}

		return $schema;
	}

	/**
	 * Detect the template type (docx/odt).
	 *
	 * @param string $template_path Template path.
	 * @return string
	 */
	private function detect_template_type( $template_path ) {
		$ext = strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'docx', 'odt' ), true ) ) {
			return $ext;
		}
		return '';
	}

	/**
	 * Collect placeholder tokens from a template file.
	 *
	 * @param string $template_path Absolute template path.
	 * @param string $template_type Template type (docx|odt).
	 * @return array<int, array<string,mixed>>|WP_Error
	 */
	private function collect_placeholders( $template_path, $template_type ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $template_path ) ) {
			return new WP_Error( 'documentate_schema_template_open', 'No se pudo abrir el archivo de plantilla.' );
		}

		$targets = array();
		if ( 'docx' === $template_type ) {
			$targets = $this->collect_docx_targets( $zip );
		} else {
			$targets = $this->collect_odt_targets( $zip );
		}

		$tokens = array();
		foreach ( $targets as $target ) {
			$contents = $zip->getFromName( $target );
			if ( false === $contents ) {
				continue;
			}

			$normalized = $this->normalize_xml_text( $contents, $template_type );
			if ( '' === $normalized ) {
				continue;
			}

			foreach ( $this->extract_placeholder_chunks( $normalized ) as $chunk ) {
				$inner = substr( $chunk, 1, -1 );
				$token = $this->parse_placeholder_token( $inner );
				if ( empty( $token['name'] ) ) {
					continue;
				}

				$token['source'] = $target;
				$token['order'] = count( $tokens );
				$tokens[] = $token;
			}
		}

		$zip->close();

		return $tokens;
	}

	/**
	 * Collect XML targets from a DOCX archive.
	 *
	 * @param ZipArchive $zip Open ZipArchive instance.
	 * @return array<string>
	 */
	private function collect_docx_targets( ZipArchive $zip ) {
		$targets = array();

		$preferred = array(
			'word/document.xml',
			'word/header1.xml',
			'word/header2.xml',
			'word/footer1.xml',
			'word/footer2.xml',
			'word/footnotes.xml',
			'word/endnotes.xml',
		);

		foreach ( $preferred as $candidate ) {
			if ( false !== $zip->locateName( $candidate ) ) {
				$targets[] = $candidate;
			}
		}

		// Include any additional headers/footers not covered above.
		for ( $i = 0;; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				break;
			}

			if ( preg_match( '#^word/(header|footer|footnotes|endnotes)[^/]*\.xml$#i', $name ) ) {
				if ( ! in_array( $name, $targets, true ) ) {
					$targets[] = $name;
				}
			}
		}

		return $targets;
	}

	/**
	 * Collect XML targets from an ODT archive.
	 *
	 * @param ZipArchive $zip Open ZipArchive instance.
	 * @return array<string>
	 */
	private function collect_odt_targets( ZipArchive $zip ) {
		$targets = array();
		foreach ( array( 'content.xml', 'styles.xml' ) as $candidate ) {
			if ( false !== $zip->locateName( $candidate ) ) {
				$targets[] = $candidate;
			}
		}
		return $targets;
	}

	/**
	 * Normalize the XML text, collapsing runs to recover placeholders.
	 *
	 * @param string $xml            Raw XML chunk.
	 * @param string $template_type  Template type (docx|odt).
	 * @return string
	 */
	private function normalize_xml_text( $xml, $template_type ) {
		$xml = (string) $xml;
		if ( '' === $xml ) {
			return '';
		}

		if ( 'docx' === $template_type ) {
			$patterns = array(
				'#</w:t>\s*</w:r>\s*<w:r[^>]*>\s*<w:t[^>]*>#i',
				'#</w:t>\s*<w:r[^>]*>\s*<w:t[^>]*>#i',
				'#</w:t>\s*<w:t[^>]*>#i',
			);
			$xml = preg_replace( $patterns, '', $xml );
		} else {
			// Handle nested spans: multiple closing tags followed by multiple opening tags.
			// Loop to collapse nested structures like </span></span><span><span>.
			$prev = '';
			while ( $prev !== $xml ) {
				$prev = $xml;
				$xml = preg_replace( '#(</text:span>\s*)+(<text:span[^>]*>\s*)+#i', ' ', $xml );
			}
			// Also collapse paragraph boundaries.
			$xml = preg_replace( '#</text:p>\s*<text:p[^>]*>#i', ' ', $xml );
		}

		$xml = preg_replace( '/[\x00-\x1F\x7F]/', '', $xml );
		$xml = wp_strip_all_tags( (string) $xml );
		$xml = html_entity_decode( $xml, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$xml = str_replace( array( "\r\n", "\r" ), "\n", $xml );

		return (string) $xml;
	}

	/**
	 * Parse a placeholder token into structured data.
	 *
	 * @param string $raw Raw placeholder without brackets.
	 * @return array<string,mixed>
	 */
	private function parse_placeholder_token( $raw ) {
		$raw = (string) $raw;
		$decoded = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = trim( $decoded );

		$segments = $this->split_placeholder_segments( $decoded );
		if ( empty( $segments ) ) {
			return array(
				'name' => '',
				'parameters' => array(),
				'raw' => $raw,
				'decoded' => $decoded,
			);
		}

		$name = array_shift( $segments );
		$name = trim( (string) $name );

		$parameters = array();
		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment ) {
				continue;
			}
			$param = $this->parse_parameter_segment( $segment );
			if ( empty( $param['name'] ) ) {
				continue;
			}
			$parameters[ $param['name'] ] = $param['value'];
		}

		return array(
			'name' => $name,
			'parameters' => $parameters,
			'raw' => $raw,
			'decoded' => $decoded,
		);
	}

	/**
	 * Split placeholder definition into segments separated by semicolons.
	 *
	 * @param string $placeholder Placeholder string.
	 * @return array<int,string>
	 */
	private function split_placeholder_segments( $placeholder ) {
		$result = array();
		$length = strlen( $placeholder );
		$buffer = '';
		$quote = null;
		$prev_char = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $placeholder[ $i ];

			if ( ( "'" === $char || '"' === $char ) && '\\' !== $prev_char ) {
				if ( null === $quote ) {
					$quote = $char;
				} elseif ( $quote === $char ) {
					$quote = null;
				}
			}

			if ( ';' === $char && null === $quote ) {
				$result[] = trim( $buffer );
				$buffer = '';
				$prev_char = $char;
				continue;
			}

			$buffer .= $char;
			$prev_char = $char;
		}

		if ( '' !== $buffer || empty( $result ) ) {
			$result[] = trim( $buffer );
		}

		return array_filter(
			$result,
			static function ( $segment ) {
				return '' !== $segment;
			}
		);
	}

	/**
	 * Parse a single parameter definition segment.
	 *
	 * @param string $segment Raw segment.
	 * @return array{name:string,value:mixed}
	 */
	private function parse_parameter_segment( $segment ) {
		$name = '';
		$value = true;

		if ( false !== strpos( $segment, '=' ) ) {
			list($raw_name, $raw_value) = explode( '=', $segment, 2 );
			$raw_name = trim( (string) $raw_name );
			$raw_value = trim( (string) $raw_value );

			if ( '' !== $raw_name ) {
				$name = strtolower( $raw_name );
			}

			if ( '' !== $raw_value ) {
				$first = substr( $raw_value, 0, 1 );
				$last = substr( $raw_value, -1 );
				if ( ( "'" === $first && "'" === $last ) || ( '"' === $first && '"' === $last ) ) {
					$raw_value = substr( $raw_value, 1, -1 );
				}
				$raw_value = html_entity_decode( $raw_value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$value = $raw_value;
			}
		} else {
			$name = strtolower( $segment );
		}

		return array(
			'name' => $name,
			'value' => $value,
		);
	}

	/**
	 * Build the normalized schema structure.
	 *
	 * @param array<int,array<string,mixed>> $placeholders Parsed placeholders.
	 * @param string                         $template_type Template type.
	 * @param string                         $template_path Template path.
	 * @return array|WP_Error
	 */
	private function build_schema( $placeholders, $template_type, $template_path ) {
		$state = array(
			'fields' => array(),
			'repeaters' => array(),
			'stack' => array(),
			'visibility_stack' => array(),
			// First pass: identify repeaters from tbs:row or tbs:cell block patterns.
			'tbs_repeaters' => $this->detect_tbs_repeaters( $placeholders ),
			'added_tbs_repeaters' => array(),
			// TBS automatic sub-blocks declared via subN= on an explicit block:
			// "<block>_subN" => array( parent repeater index, data key ).
			'sub_blocks' => array(),
		);

		foreach ( $placeholders as $token ) {
			$this->consume_placeholder_token( $token, $state );
		}

		return array(
			'version' => self::SCHEMA_VERSION,
			'fields' => $state['fields'],
			'repeaters' => $state['repeaters'],
			'meta' => array(
				'template_type' => $template_type,
				'template_name' => basename( $template_path ),
				'hash' => $this->hash_template( $template_path, $placeholders ),
				'parsed_at' => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Fold one placeholder token into the schema being built.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function consume_placeholder_token( $token, array &$state ) {
		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();
		$block_mode = isset( $parameters['block'] ) ? strtolower( (string) $parameters['block'] ) : '';

		if ( 'begin' === $block_mode ) {
			$this->open_block( $token, $state );
			return;
		}

		if ( 'end' === $block_mode ) {
			$this->close_block( $token, $state );
			return;
		}

		// Handle OpenTBS tbs:row/tbs:cell style repeaters.
		if ( preg_match( '/^tbs:(row|cell|p|page)/', $block_mode ) ) {
			$this->add_tbs_repeater( $token, $state );
			return;
		}

		// Check if this is a dotted field belonging to a TBS repeater (e.g., asistentes.nombre).
		// These fields are already collected in detect_tbs_repeaters(), so skip them here.
		if ( $this->belongs_to_tbs_repeater( $token, $state ) ) {
			return;
		}

		$field = $this->build_field_entry( $token );
		if ( empty( $field ) ) {
			return;
		}

		if ( empty( $state['stack'] ) ) {
			$this->add_top_level_field( $field, $state );
			return;
		}

		$this->add_repeater_field( $field, $state );
	}

	/**
	 * Handle a block-begin token, opening a repeater or a visibility block.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function open_block( $token, array &$state ) {
		$token_name = isset( $token['name'] ) ? strtolower( (string) $token['name'] ) : '';

		// Check if this is a visibility directive (not a data repeater).
		if ( in_array( $token_name, self::VISIBILITY_DIRECTIVES, true ) ) {
			// Track visibility block for proper end matching, but don't create repeater.
			$state['visibility_stack'][] = $token_name;
			return;
		}

		// Regular data repeater.
		$state['repeaters'][] = $this->build_repeater_entry( $token );
		$repeater_index = count( $state['repeaters'] ) - 1;
		$state['stack'][] = $repeater_index;

		// Register TBS automatic sub-blocks (subN=<key>): their fields are named
		// "<block>_subN.*" in the template and merge each record's <key> array.
		$parameters = isset( $token['parameters'] ) && is_array( $token['parameters'] ) ? $token['parameters'] : array();
		$i = 1;
		while ( isset( $parameters[ 'sub' . $i ] ) ) {
			$sub_key = sanitize_key( (string) $parameters[ 'sub' . $i ] );
			if ( '' !== $sub_key ) {
				$state['sub_blocks'][ sanitize_key( $token_name ) . '_sub' . $i ] = array(
					'parent' => $repeater_index,
					'key' => $sub_key,
				);
			}
			++$i;
		}
	}

	/**
	 * Handle a block-end token, closing a visibility block or a repeater.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function close_block( $token, array &$state ) {
		$token_name = isset( $token['name'] ) ? strtolower( (string) $token['name'] ) : '';

		// Check if ending a visibility block by name.
		if ( in_array( $token_name, self::VISIBILITY_DIRECTIVES, true ) && ! empty( $state['visibility_stack'] ) ) {
			array_pop( $state['visibility_stack'] );
			return;
		}

		if ( ! empty( $state['stack'] ) ) {
			// Ending a data repeater.
			array_pop( $state['stack'] );
		}
	}

	/**
	 * Register an OpenTBS row/cell style repeater the first time it appears.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function add_tbs_repeater( $token, array &$state ) {
		$token_name = isset( $token['name'] ) ? (string) $token['name'] : '';
		$base_name = $this->extract_tbs_repeater_base( $token_name );

		if ( '' === $base_name || ! isset( $state['tbs_repeaters'][ $base_name ] ) ) {
			return;
		}

		if ( isset( $state['added_tbs_repeaters'][ $base_name ] ) ) {
			return;
		}

		// A sub-block ("<name>_subN") nests inside its parent repeater as an
		// array field named after the data key its rows come from. It takes
		// the parent block's rol unless its own row declares one.
		if ( isset( $state['sub_blocks'][ $base_name ] ) ) {
			$sub = $state['sub_blocks'][ $base_name ];
			if ( isset( $state['repeaters'][ $sub['parent'] ] ) ) {
				$parent = $state['repeaters'][ $sub['parent'] ];
				$entry = $this->build_tbs_repeater_entry( $base_name, $state['tbs_repeaters'][ $base_name ] );
				$state['repeaters'][ $sub['parent'] ]['fields'][] = $this->inherit_role(
					array(
						'name' => $sub['key'],
						'slug' => sanitize_key( $sub['key'] ),
						'title' => '',
						'description' => '',
						'type' => 'array',
						'rol' => $entry['rol'],
						'parameters' => array( 'tbs_sub_block' => $base_name ),
						'fields' => $entry['fields'],
					),
					isset( $parent['rol'] ) ? (string) $parent['rol'] : ''
				);
				$state['added_tbs_repeaters'][ $base_name ] = true;
				return;
			}
		}

		$state['repeaters'][] = $this->build_tbs_repeater_entry( $base_name, $state['tbs_repeaters'][ $base_name ] );
		$state['added_tbs_repeaters'][ $base_name ] = true;
	}

	/**
	 * Whether a dotted token was already collected as a TBS repeater field.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return bool
	 */
	private function belongs_to_tbs_repeater( $token, array $state ) {
		$token_name = isset( $token['name'] ) ? (string) $token['name'] : '';
		if ( false === strpos( $token_name, '.' ) ) {
			return false;
		}

		$base_name = $this->extract_tbs_repeater_base( $token_name );
		if ( '' === $base_name || ! isset( $state['tbs_repeaters'][ $base_name ] ) ) {
			return false;
		}

		if ( empty( $state['stack'] ) ) {
			return true;
		}

		// Inside an explicit block, dotted fields normally feed the open
		// repeater — except fields of a TBS sub-block ("<name>_subN.*"), which
		// were already collected for the nested repeater in the pre-pass.
		return isset( $state['sub_blocks'][ $base_name ] );
	}

	/**
	 * Add a field outside any repeater, skipping duplicate slugs.
	 *
	 * @param array<string,mixed> $field Field entry.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function add_top_level_field( $field, array &$state ) {
		// Deduplicate: only add if slug doesn't already exist.
		$field_slug = isset( $field['slug'] ) ? $field['slug'] : '';

		foreach ( $state['fields'] as $existing ) {
			if ( isset( $existing['slug'] ) && $existing['slug'] === $field_slug ) {
				return;
			}
		}

		$state['fields'][] = $field;
	}

	/**
	 * Add a field to the repeater currently open on the stack.
	 *
	 * @param array<string,mixed> $field Field entry.
	 * @param array<string,mixed> $state Schema accumulator.
	 * @return void
	 */
	private function add_repeater_field( $field, array &$state ) {
		$current_index = end( $state['stack'] );
		if ( ! isset( $state['repeaters'][ $current_index ] ) ) {
			return;
		}

		$repeater = $state['repeaters'][ $current_index ];

		$field = $this->strip_repeater_prefix(
			$field,
			isset( $repeater['name'] ) ? (string) $repeater['name'] : '',
			isset( $repeater['slug'] ) ? (string) $repeater['slug'] : ''
		);

		$state['repeaters'][ $current_index ]['fields'][] = $this->inherit_role(
			$field,
			isset( $repeater['rol'] ) ? (string) $repeater['rol'] : ''
		);
	}

	/**
	 * Give a field the rol of the block it belongs to, unless it declares one.
	 *
	 * Applies recursively to the fields of a nested repeater, so a block-level
	 * rol reaches its sub-repeater rows as well.
	 *
	 * @param array<string,mixed> $field Field (or nested repeater) entry.
	 * @param string              $role  Rol declared by the enclosing block.
	 * @return array<string,mixed>
	 */
	private function inherit_role( array $field, $role ) {
		if ( '' === $role ) {
			return $field;
		}

		if ( empty( $field['rol'] ) ) {
			$field['rol'] = $role;
		}

		if ( isset( $field['fields'] ) && is_array( $field['fields'] ) ) {
			foreach ( $field['fields'] as $index => $sub_field ) {
				if ( is_array( $sub_field ) ) {
					$field['fields'][ $index ] = $this->inherit_role( $sub_field, $role );
				}
			}
		}

		return $field;
	}

	/**
	 * Normalise the "rol" attribute (alias "role") of a placeholder.
	 *
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return string "area", "gestion", or an empty string when not declared.
	 */
	private function normalize_role( $parameters ) {
		$parameters = is_array( $parameters ) ? $parameters : array();
		$raw = '';

		foreach ( array( 'rol', 'role' ) as $key ) {
			if ( isset( $parameters[ $key ] ) && is_string( $parameters[ $key ] ) ) {
				$raw = $parameters[ $key ];
				break;
			}
		}

		$role = strtolower( trim( remove_accents( $raw ) ) );

		return in_array( $role, self::ROLES, true ) ? $role : '';
	}

	/**
	 * Strip the repeater base from dotted field names like "anexos.title".
	 *
	 * @param array<string,mixed> $field     Field entry.
	 * @param string              $base_name Repeater name.
	 * @param string              $base_slug Repeater slug.
	 * @return array<string,mixed>
	 */
	private function strip_repeater_prefix( $field, $base_name, $base_slug ) {
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			return $field;
		}

		$segments = explode( '.', $name );
		$first = strtolower( (string) $segments[0] );
		if ( strtolower( $base_name ) !== $first && strtolower( $base_slug ) !== $first ) {
			return $field;
		}

		array_shift( $segments );
		$item_name = implode( '.', $segments );
		$item_slug = sanitize_key( str_replace( '.', '_', $item_name ) );
		if ( '' === $item_slug ) {
			return $field;
		}

		$field['name'] = $item_name;
		$field['slug'] = $item_slug;

		// Keep placeholder and labels as-is; UI will humanize from slug/name if needed.
		return $field;
	}

	/**
	 * Hash the template file, falling back to its parsed placeholders.
	 *
	 * @param string           $template_path Template path.
	 * @param array<int,mixed> $placeholders  Parsed placeholders.
	 * @return string
	 */
	private function hash_template( $template_path, $placeholders ) {
		$hash = md5_file( $template_path );

		return false === $hash ? md5( wp_json_encode( $placeholders ) ) : $hash;
	}

	/**
	 * Build a repeater entry from a block placeholder.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @return array<string,mixed>
	 */
	private function build_repeater_entry( $token ) {
		$name = isset( $token['name'] ) ? (string) $token['name'] : '';
		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();

		$title = isset( $parameters['title'] ) ? sanitize_text_field( $parameters['title'] ) : '';
		$description = isset( $parameters['description'] ) ? sanitize_text_field( $parameters['description'] ) : '';

		$clean_parameters = $parameters;
		unset( $clean_parameters['block'] );

		return array(
			'name' => $name,
			'slug' => sanitize_key( $name ),
			'title' => $title,
			'description' => $description,
			'rol' => $this->normalize_role( $parameters ),
			'parameters' => $clean_parameters,
			'fields' => array(),
		);
	}

	/**
	 * Build a single field definition entry.
	 *
	 * @param array<string,mixed> $token Placeholder token data.
	 * @return array<string,mixed>
	 */
	private function build_field_entry( $token ) {
		$name = isset( $token['name'] ) ? (string) $token['name'] : '';

		if ( '' === $name ) {
			return array();
		}
		// Allow dots in field names so dotted placeholders like "anexos.title" are accepted.
		if ( preg_match( '/[^\\p{L}\\p{N}_\\-. ]/u', $name ) ) {
			return array();
		}

		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();

		$field_type = $this->determine_field_type( $name, $parameters );
		$field_type = $this->normalize_field_type_name( $field_type );

		$attributes = $this->apply_default_pattern( $this->extract_field_attributes( $parameters ), $field_type );

		$slug = $this->resolve_field_slug( $name, $parameters );

		return array_merge(
			array(
				'name' => $name,
				'slug' => '' === $slug ? sanitize_key( $name ) : $slug,
				'type' => $field_type,
			),
			$attributes,
			array(
				'parameters' => $parameters,
				'raw' => isset( $token['raw'] ) ? (string) $token['raw'] : '',
				'source' => isset( $token['source'] ) ? (string) $token['source'] : '',
			)
		);
	}

	/**
	 * Read the declared field attributes from the placeholder parameters.
	 *
	 * Keys are produced in the order the schema entry declares them.
	 *
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return array<string,string>
	 */
	private function extract_field_attributes( $parameters ) {
		$attributes = array();

		foreach ( self::$field_attributes as $key => $sanitize ) {
			if ( ! isset( $parameters[ $key ] ) ) {
				$attributes[ $key ] = '';
				continue;
			}

			$attributes[ $key ] = $sanitize
				? sanitize_text_field( $parameters[ $key ] )
				: (string) $parameters[ $key ];
		}

		$attributes['rol'] = $this->normalize_role( $parameters );
		$attributes['case'] = $this->normalize_case( $parameters );

		return $attributes;
	}

	/**
	 * Normalise the case transformation parameter.
	 *
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return string One of upper|lower|title, or an empty string.
	 */
	private function normalize_case( $parameters ) {
		$case = isset( $parameters['case'] ) ? strtolower( trim( (string) $parameters['case'] ) ) : '';

		return in_array( $case, array( 'upper', 'lower', 'title' ), true ) ? $case : '';
	}

	/**
	 * Fall back to the built-in pattern for the field type.
	 *
	 * @param array<string,string> $attributes Field attributes.
	 * @param string               $field_type Normalized field type.
	 * @return array<string,string>
	 */
	private function apply_default_pattern( array $attributes, $field_type ) {
		if ( '' !== $attributes['pattern'] ) {
			return $attributes;
		}

		$default_config = self::get_default_pattern( $field_type );
		if ( ! $default_config ) {
			return $attributes;
		}

		$attributes['pattern'] = $default_config['pattern'];
		if ( '' === $attributes['patternmsg'] ) {
			$attributes['patternmsg'] = $default_config['message'];
		}

		return $attributes;
	}

	/**
	 * Extract placeholder chunks from normalized text, skipping nested bracket expressions inside quotes.
	 *
	 * @param string $text Normalized XML text.
	 * @return array<int,string>
	 */
	private function extract_placeholder_chunks( $text ) {
		$chunks = array();
		$length = strlen( $text );
		$in_placeholder = false;
		$buffer = '';
		$current_quote = null;
		$previous_char = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $in_placeholder ) {
				$buffer .= $char;

				if ( ( "'" === $char || '"' === $char ) && '\\' !== $previous_char ) {
					if ( null === $current_quote ) {
						$current_quote = $char;
					} elseif ( $current_quote === $char ) {
						$current_quote = null;
					}
				} elseif ( ']' === $char && null === $current_quote ) {
					$chunks[] = $buffer;
					$buffer = '';
					$in_placeholder = false;
				}
			} elseif ( '[' === $char ) {
				$in_placeholder = true;
				$buffer = '[';
				$current_quote = null;
			}

			$previous_char = $char;
		}

		return $chunks;
	}

	/**
	 * Determine the field type from placeholder parameters.
	 *
	 * @param string              $name       Placeholder name.
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return string
	 */
	private function determine_field_type( $name, $parameters ) {
		$name = strtolower( (string) $name );
		$parameters = is_array( $parameters ) ? $parameters : array();

		$declared = '';
		if ( isset( $parameters['type'] ) ) {
			$declared = $this->normalize_declared_field_type( $parameters['type'] );
		} elseif ( isset( $parameters['data-type'] ) ) {
			$declared = $this->normalize_declared_field_type( $parameters['data-type'] );
		}

		if ( '' !== $declared ) {
			return $declared;
		}

		// Detect HTML-likely fields by name patterns.
		// Includes common Spanish legal/administrative terms that typically contain rich text.
		if ( preg_match(
			'/(html|rich|contenido|body|cuerpo|antecedentes|hechos|fundamentos|observaciones|notas|descripcion|detalle|resolucion|resuelvo|texto)/u',
			$name,
		) ) {
			return 'html';
		}

		return '';
	}

	/**
	 * Normalize a declared field type value.
	 *
	 * @param string $candidate Declared type value.
	 * @return string Normalized type or empty string if unknown.
	 */
	private function normalize_declared_field_type( $candidate ) {
		$type = strtolower( trim( (string) $candidate ) );

		if ( '' === $type ) {
			return '';
		}

		$aliases = array(
			'rich' => 'html',
			'tinymce' => 'html',
			'editor' => 'html',
			'text-area' => 'textarea',
			'text_area' => 'textarea',
			'numeric' => 'number',
			'int' => 'number',
			'integer' => 'number',
			'float' => 'number',
			'decimal' => 'number',
			'bool' => 'boolean',
			'checkbox' => 'boolean',
			'dropdown' => 'select',
			'choice' => 'select',
		);

		if ( isset( $aliases[ $type ] ) ) {
			$type = $aliases[ $type ];
		}

		$valid = array( 'text', 'number', 'date', 'email', 'url', 'textarea', 'html', 'boolean', 'select' );
		if ( in_array( $type, $valid, true ) ) {
			return $type;
		}

		return '';
	}

	/**
	 * Apply default normalization to a computed field type.
	 *
	 * @param string $type Computed field type.
	 * @return string Normalized field type.
	 */
	private function normalize_field_type_name( $type ) {
		$type = strtolower( trim( (string) $type ) );

		if ( '' === $type ) {
			return 'text';
		}

		$valid = array( 'text', 'number', 'date', 'email', 'url', 'textarea', 'html', 'boolean', 'select' );

		if ( in_array( $type, $valid, true ) ) {
			return $type;
		}

		return 'text';
	}

	/**
	 * Resolve a stable slug for a schema field.
	 *
	 * @param string              $name       Placeholder name.
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return string
	 */
	private function resolve_field_slug( $name, $parameters ) {
		$parameters = is_array( $parameters ) ? $parameters : array();

		if ( isset( $parameters['slug'] ) ) {
			$explicit = sanitize_key( $parameters['slug'] );
			if ( '' !== $explicit ) {
				return $explicit;
			}
		}

		$base_slug = sanitize_key( $name );
		$title_slug = '';

		if ( isset( $parameters['title'] ) ) {
			$title_slug = sanitize_key( $parameters['title'] );
		}

		if ( '' === $base_slug && '' !== $title_slug ) {
			return $title_slug;
		}

		if ( '' !== $title_slug && $this->should_prefer_title_slug( $base_slug ) ) {
			return $title_slug;
		}

		return $base_slug;
	}

	/**
	 * Determine whether we should fall back to the title-derived slug.
	 *
	 * @param string $slug Current slug candidate.
	 * @return bool
	 */
	private function should_prefer_title_slug( $slug ) {
		if ( '' === $slug ) {
			return true;
		}

		$generic = array(
			'name',
			'phone',
			'units',
			'field',
			'value',
			'column',
		);

		if ( in_array( $slug, $generic, true ) ) {
			return true;
		}

		if ( preg_match( '/^(field|col|column|item|entry)[0-9]+$/', $slug ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect TBS-style repeaters (tbs:row, tbs:cell, etc.) from placeholders.
	 *
	 * @param array<int,array<string,mixed>> $placeholders Parsed placeholders.
	 * @return array<string,array<string,mixed>> Map of base name to field info.
	 */
	private function detect_tbs_repeaters( $placeholders ) {
		$repeaters = array();

		foreach ( $placeholders as $token ) {
			$this->collect_tbs_repeater_token( $token, $repeaters );
		}

		return $repeaters;
	}

	/**
	 * Register a repeater and collect its fields from one dotted token.
	 *
	 * @param array<string,mixed> $token     Placeholder token.
	 * @param array<string,mixed> $repeaters Repeaters collected so far.
	 * @return void
	 */
	private function collect_tbs_repeater_token( $token, array &$repeaters ) {
		$name = isset( $token['name'] ) ? (string) $token['name'] : '';
		if ( false === strpos( $name, '.' ) ) {
			return;
		}

		$base_name = $this->extract_tbs_repeater_base( $name );
		if ( '' === $base_name ) {
			return;
		}

		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();

		$this->maybe_open_tbs_repeater( $base_name, $parameters, $repeaters );

		// Collect all fields that belong to a repeater (e.g., a.field patterns).
		if ( ! isset( $repeaters[ $base_name ] ) ) {
			return;
		}

		$field_name = $this->extract_tbs_field_name( $name );
		if ( '' === $field_name ) {
			return;
		}

		$repeaters[ $base_name ]['fields'][ $field_name ] = $this->build_collected_repeater_field( $field_name, $parameters );
	}

	/**
	 * Register a repeater the first time a block token declares it.
	 *
	 * @param string              $base_name  Repeater base name.
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @param array<string,mixed> $repeaters  Repeaters collected so far.
	 * @return void
	 */
	private function maybe_open_tbs_repeater( $base_name, $parameters, array &$repeaters ) {
		if ( isset( $repeaters[ $base_name ] ) ) {
			return;
		}

		$block_mode = isset( $parameters['block'] ) ? strtolower( (string) $parameters['block'] ) : '';

		// Look for patterns like [a.field;block=tbs:row]. The rol declared on
		// the block token applies to the whole repeater.
		if ( preg_match( '/^tbs:(row|cell|p|page)/', $block_mode ) ) {
			$repeaters[ $base_name ] = array(
				'fields' => array(),
				'rol' => $this->normalize_role( $parameters ),
			);
		}
	}

	/**
	 * Read the field segment of a dotted placeholder name.
	 *
	 * @param string $name Placeholder name (e.g., "a.firstname").
	 * @return string
	 */
	private function extract_tbs_field_name( $name ) {
		$parts = explode( '.', $name );

		return isset( $parts[1] ) ? $parts[1] : '';
	}

	/**
	 * Build the collected record for one repeater field.
	 *
	 * @param string              $field_name Field name within the repeater.
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return array<string,mixed>
	 */
	private function build_collected_repeater_field( $field_name, $parameters ) {
		return array_merge(
			array(
				'name' => $field_name,
				'slug' => sanitize_key( $field_name ),
				'type' => $this->normalize_repeater_field_type( $parameters ),
			),
			$this->extract_field_attributes( $parameters )
		);
	}

	/**
	 * Normalise the declared type of a repeater field.
	 *
	 * @param array<string,mixed> $parameters Placeholder parameters.
	 * @return string
	 */
	private function normalize_repeater_field_type( $parameters ) {
		$field_type = isset( $parameters['type'] ) ? strtolower( trim( (string) $parameters['type'] ) ) : 'text';
		$valid_types = array( 'text', 'textarea', 'html', 'number', 'date', 'email', 'url', 'select' );

		return in_array( $field_type, $valid_types, true ) ? $field_type : 'text';
	}

	/**
	 * Extract the base repeater name from a dotted placeholder name.
	 *
	 * @param string $name Placeholder name (e.g., "a.firstname").
	 * @return string Base name (e.g., "a") or empty string.
	 */
	private function extract_tbs_repeater_base( $name ) {
		if ( false === strpos( $name, '.' ) ) {
			return '';
		}
		$parts = explode( '.', $name );
		return sanitize_key( $parts[0] );
	}

	/**
	 * Build a repeater entry for a TBS-style repeater.
	 *
	 * @param string              $base_name Base name of the repeater.
	 * @param array<string,mixed> $info      Collected repeater info.
	 * @return array<string,mixed>
	 */
	private function build_tbs_repeater_entry( $base_name, $info ) {
		$fields = array();
		$role = isset( $info['rol'] ) ? (string) $info['rol'] : '';

		$collected = isset( $info['fields'] ) && is_array( $info['fields'] ) ? $info['fields'] : array();
		foreach ( $collected as $field_name => $field_info ) {
			$fields[] = $this->inherit_role( $this->build_tbs_repeater_field( $field_name, $field_info ), $role );
		}

		return array(
			'name' => $base_name,
			'slug' => sanitize_key( $base_name ),
			'title' => '',
			'description' => '',
			'rol' => $role,
			'parameters' => array(),
			'fields' => $fields,
		);
	}

	/**
	 * Build a schema field entry from a collected repeater field.
	 *
	 * @param string              $field_name Field name within the repeater.
	 * @param array<string,mixed> $field_info Collected field info.
	 * @return array<string,mixed>
	 */
	private function build_tbs_repeater_field( $field_name, $field_info ) {
		$field = array(
			'name' => $field_name,
			'slug' => sanitize_key( $field_name ),
		);

		foreach ( self::$repeater_field_defaults as $key => $default ) {
			$field[ $key ] = isset( $field_info[ $key ] ) ? $field_info[ $key ] : $default;
		}

		$field['parameters'] = array();
		$field['raw'] = '';
		$field['source'] = '';

		return $field;
	}
}
