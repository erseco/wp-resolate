<?php
/**
 * Schema extractor for document type templates.
 *
 * @package Resolate
 */

namespace Resolate\DocType;

use WP_Error;
use ZipArchive;

/**
 * Parses DOCX/ODT templates to build a normalized schema definition.
 */
class SchemaExtractor {

	const SCHEMA_VERSION = 2;

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
				'resolate_schema_template_missing',
				__( 'The selected template file is not accessible.', 'resolate' )
			);
		}

		$template_type = $this->detect_template_type( $template_path );
		if ( '' === $template_type ) {
			return new WP_Error(
				'resolate_schema_template_type',
				__( 'The template must be a DOCX or ODT file.', 'resolate' )
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
			return new WP_Error(
				'resolate_schema_template_open',
				__( 'The template file could not be opened.', 'resolate' )
			);
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
				$token['order']  = count( $tokens );
				$tokens[]        = $token;
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
		for ( $i = 0; ; $i++ ) {
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
				'#</w:t>\s*<w:r[^>]*>\s*<w:t[^>]*>#i',
				'#</w:t>\s*<w:t[^>]*>#i',
			);
			$xml = preg_replace( $patterns, '', $xml );
		} else {
			$patterns = array(
				'#</text:span>\s*<text:span[^>]*>#i',
				'#</text:p>\s*<text:p[^>]*>#i',
			);
			$xml = preg_replace( $patterns, ' ', $xml );
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
				'name'       => '',
				'parameters' => array(),
				'raw'        => $raw,
				'decoded'    => $decoded,
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
			'name'       => $name,
			'parameters' => $parameters,
			'raw'        => $raw,
			'decoded'    => $decoded,
		);
	}

	/**
	 * Split placeholder definition into segments separated by semicolons.
	 *
	 * @param string $placeholder Placeholder string.
	 * @return array<int,string>
	 */
	private function split_placeholder_segments( $placeholder ) {
		$result    = array();
		$length    = strlen( $placeholder );
		$buffer    = '';
		$quote     = null;
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
				$buffer   = '';
				$prev_char = $char;
				continue;
			}

			$buffer   .= $char;
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
		$name  = '';
		$value = true;

		if ( false !== strpos( $segment, '=' ) ) {
			list( $raw_name, $raw_value ) = explode( '=', $segment, 2 );
			$raw_name = trim( (string) $raw_name );
			$raw_value = trim( (string) $raw_value );

			if ( '' !== $raw_name ) {
				$name = strtolower( $raw_name );
			}

			if ( '' !== $raw_value ) {
				$first = substr( $raw_value, 0, 1 );
				$last  = substr( $raw_value, -1 );
				if ( ( "'" === $first && "'" === $last ) || ( '"' === $first && '"' === $last ) ) {
					$raw_value = substr( $raw_value, 1, -1 );
				}
				$raw_value = html_entity_decode( $raw_value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$value     = $raw_value;
			}
		} else {
			$name = strtolower( $segment );
		}

		return array(
			'name'  => $name,
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
		$fields    = array();
		$repeaters = array();
		$stack     = array();

		foreach ( $placeholders as $token ) {
			$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();
			$block_mode = isset( $parameters['block'] ) ? strtolower( (string) $parameters['block'] ) : '';

			if ( 'begin' === $block_mode ) {
				$repeaters[] = $this->build_repeater_entry( $token );
				$stack[]     = count( $repeaters ) - 1;
				continue;
			}

			if ( 'end' === $block_mode ) {
				array_pop( $stack );
				continue;
			}

			$field = $this->build_field_entry( $token );
			if ( empty( $field ) ) {
				continue;
			}

			if ( empty( $stack ) ) {
				$fields[] = $field;
			} else {
				$current_index = end( $stack );
				if ( isset( $repeaters[ $current_index ] ) ) {
					$repeaters[ $current_index ]['fields'][] = $field;
				}
			}
		}

		$hash = md5_file( $template_path );
		if ( false === $hash ) {
			$hash = md5( wp_json_encode( $placeholders ) );
		}

		return array(
			'version'   => self::SCHEMA_VERSION,
			'fields'    => $fields,
			'repeaters' => $repeaters,
			'meta'      => array(
				'template_type' => $template_type,
				'template_name' => basename( $template_path ),
				'hash'          => $hash,
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build a repeater entry from a block placeholder.
	 *
	 * @param array<string,mixed> $token Placeholder token.
	 * @return array<string,mixed>
	 */
	private function build_repeater_entry( $token ) {
		$name       = isset( $token['name'] ) ? (string) $token['name'] : '';
		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();

		$title       = isset( $parameters['title'] ) ? sanitize_text_field( $parameters['title'] ) : '';
		$description = isset( $parameters['description'] ) ? sanitize_text_field( $parameters['description'] ) : '';

		$clean_parameters = $parameters;
		unset( $clean_parameters['block'] );

		return array(
			'name'        => $name,
			'slug'        => sanitize_key( $name ),
			'title'       => $title,
			'description' => $description,
			'parameters'  => $clean_parameters,
			'fields'      => array(),
		);
	}

	/**
	 * Build a single field definition entry.
	 *
	 * @param array<string,mixed> $token Placeholder token data.
	 * @return array<string,mixed>
	 */
	private function build_field_entry( $token ) {
		$name       = isset( $token['name'] ) ? (string) $token['name'] : '';
		$parameters = isset( $token['parameters'] ) ? $token['parameters'] : array();

		if ( '' === $name ) {
			return array();
		}
		if ( preg_match( '/[^\\p{L}\\p{N}_\\- ]/u', $name ) ) {
			return array();
		}

		$field_type = $this->determine_field_type( $name, $parameters );
		$field_type = $this->normalize_field_type_name( $field_type );

		$title       = isset( $parameters['title'] ) ? sanitize_text_field( $parameters['title'] ) : '';
		$placeholder = isset( $parameters['placeholder'] ) ? sanitize_text_field( $parameters['placeholder'] ) : '';
		$description = isset( $parameters['description'] ) ? sanitize_text_field( $parameters['description'] ) : '';
		$pattern     = isset( $parameters['pattern'] ) ? (string) $parameters['pattern'] : '';
		$pattern_msg = isset( $parameters['patternmsg'] ) ? sanitize_text_field( $parameters['patternmsg'] ) : '';
		$min_value   = isset( $parameters['minvalue'] ) ? (string) $parameters['minvalue'] : '';
		$max_value   = isset( $parameters['maxvalue'] ) ? (string) $parameters['maxvalue'] : '';
		$length      = isset( $parameters['length'] ) ? (string) $parameters['length'] : '';

		return array(
			'name'        => $name,
			'slug'        => sanitize_key( $name ),
			'type'        => $field_type,
			'title'       => $title,
			'placeholder' => $placeholder,
			'description' => $description,
			'pattern'     => $pattern,
			'patternmsg'  => $pattern_msg,
			'minvalue'    => $min_value,
			'maxvalue'    => $max_value,
			'length'      => $length,
			'parameters'  => $parameters,
			'raw'         => isset( $token['raw'] ) ? (string) $token['raw'] : '',
			'source'      => isset( $token['source'] ) ? (string) $token['source'] : '',
		);
	}

	/**
	 * Extract placeholder chunks from normalized text, skipping nested bracket expressions inside quotes.
	 *
	 * @param string $text Normalized XML text.
	 * @return array<int,string>
	 */
	private function extract_placeholder_chunks( $text ) {
		$chunks          = array();
		$length          = strlen( $text );
		$in_placeholder  = false;
		$buffer          = '';
		$current_quote   = null;
		$previous_char   = '';

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
					$chunks[]       = $buffer;
					$buffer         = '';
					$in_placeholder = false;
				}
			} elseif ( '[' === $char ) {
				$in_placeholder = true;
				$buffer         = '[';
				$current_quote  = null;
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
		$name       = strtolower( (string) $name );
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

		if ( preg_match( '/(html|rich|contenido|body|cuerpo)/u', $name ) ) {
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
			'rich'        => 'html',
			'tinymce'     => 'html',
			'editor'      => 'html',
			'text-area'   => 'textarea',
			'text_area'   => 'textarea',
			'numeric'     => 'number',
			'int'         => 'number',
			'integer'     => 'number',
			'float'       => 'number',
			'decimal'     => 'number',
			'bool'        => 'boolean',
			'checkbox'    => 'boolean',
		);

		if ( isset( $aliases[ $type ] ) ) {
			$type = $aliases[ $type ];
		}

		$valid = array( 'text', 'number', 'date', 'email', 'url', 'textarea', 'html', 'boolean' );
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
			return 'textarea';
		}

		$valid = array( 'text', 'number', 'date', 'email', 'url', 'textarea', 'html', 'boolean' );

		if ( in_array( $type, $valid, true ) ) {
			return $type;
		}

		return 'textarea';
	}
}
