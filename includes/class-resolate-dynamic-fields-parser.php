<?php
/**
 * ODT Template Parser for Dynamic Fields.
 *
 * This parser is responsible for extracting field definitions from an .odt template
 * that uses TBS-style syntax with specific parameters for generating a dynamic form.
 *
 * @package Resolate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Parses ODT templates to extract dynamic field definitions for UI generation.
 */
class Resolate_Dynamic_Fields_Parser {

	/**
	 * Main parsing function.
	 *
	 * @param string $template_path Path to the .odt template file.
	 * @return array An array representing the JSON schema.
	 */
	public static function parse( $template_path ) {
		$raw_fields = self::extract_raw_fields( $template_path );

		if ( is_wp_error( $raw_fields ) ) {
			// In a real application, we might want to log this or display a notice.
			// For now, return an empty schema.
			return array();
		}

		if ( empty( $raw_fields ) ) {
			return array();
		}

		$schema = self::build_schema( $raw_fields );

		return array(
			'template' => basename( $template_path ),
			'fields'   => $schema,
		);
	}

	/**
	 * Extracts the raw placeholder strings from the ODT template's content.xml.
	 *
	 * @param string $template_path Path to the .odt template file.
	 * @return array|WP_Error An array of raw field strings or a WP_Error on failure.
	 */
	private static function extract_raw_fields( $template_path ) {
		if ( empty( $template_path ) || ! file_exists( $template_path ) ) {
			return new WP_Error( 'resolate_template_missing', __( 'The selected template file could not be found.', 'wp-resolate' ) );
		}

		if ( 'odt' !== strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'resolate_template_invalid', __( 'The file must be an .odt template.', 'wp-resolate' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'resolate_zip_missing', __( 'The ZipArchive class is not available on this server.', 'wp-resolate' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $template_path ) ) {
			return new WP_Error( 'resolate_template_unzip', __( 'Could not open the template file for analysis.', 'wp-resolate' ) );
		}

		$content_xml = $zip->getFromName( 'content.xml' );
		$zip->close();

		if ( false === $content_xml ) {
			return new WP_Error( 'resolate_template_no_content', __( 'Could not read content.xml from the template.', 'wp-resolate' ) );
		}

		$normalized_text = self::normalize_xml_text( $content_xml );

		preg_match_all( '/\[([^\]]+)\]/', $normalized_text, $matches );

		return empty( $matches[1] ) ? array() : array_unique( $matches[1] );
	}

	/**
	 * Builds the final schema from an array of raw field strings.
	 *
	 * @param array $raw_fields Array of strings like "name; type='text'; ...".
	 * @return array The structured schema for the fields.
	 */
	private static function build_schema( $raw_fields ) {
		$schema = array();
		$names_used = array();

		foreach ( $raw_fields as $field_string ) {
			$parsed_field = self::parse_field_string( $field_string );

			if ( ! $parsed_field || empty( $parsed_field['name'] ) ) {
				continue;
			}

			$original_name = $parsed_field['name'];
			$final_name = $original_name;

			if ( isset( $names_used[ $original_name ] ) ) {
				$names_used[ $original_name ]++;
				$final_name = $original_name . '_' . $names_used[ $original_name ];
				// This field can be used to show a warning in the UI.
				$parsed_field['is_duplicate'] = true;
				// This can be useful for debugging or warnings.
				$parsed_field['original_name'] = $original_name;
			} else {
				$names_used[ $original_name ] = 1;
			}

			// The 'name' in the schema must be unique for form fields and meta keys.
			$parsed_field['name'] = $final_name;

			// Set title: use 'title' parameter, fallback to humanized original name.
			if ( empty( $parsed_field['title'] ) ) {
				$parsed_field['title'] = ucwords( str_replace( array( '_', '-' ), ' ', $original_name ) );
			}

			// Set type: use 'type' parameter, fallback to 'text'.
			$supported_types = array( 'text', 'number', 'date', 'email', 'url', 'html', 'textarea' );
			if ( empty( $parsed_field['type'] ) || ! in_array( $parsed_field['type'], $supported_types, true ) ) {
				$parsed_field['type'] = 'text';
			}

			// Clean up the final array to only include known schema properties.
			$schema_item = array();
			$known_props = array(
				'name', 'type', 'title', 'placeholder', 'description', 'pattern',
				'patternmsg', 'minvalue', 'maxvalue', 'length', 'original_name', 'is_duplicate'
			);

			foreach ( $known_props as $prop ) {
				if ( isset( $parsed_field[ $prop ] ) ) {
					$schema_item[ $prop ] = $parsed_field[ $prop ];
				}
			}

			// Add to the schema
			$schema[] = $schema_item;
		}

		return $schema;
	}

	/**
	 * Parses a single raw field string (e.g., "user name; type='text'; title='Full Name'")
	 * into a structured associative array.
	 *
	 * @param string $field_string The raw string from inside the brackets.
	 * @return array|null A structured array of the field's properties.
	 */
	private static function parse_field_string( $field_string ) {
		// Split by semicolon, but not inside quotes
		$parts = preg_split( '/\s*;\s*(?=(?:[^"]*"[^"]*")*[^"]*$)(?=(?:[^\']*\s*\'[^\']*\s*\')*[^\']*$)/', $field_string );

		$field_name = trim( array_shift( $parts ) );

		if ( empty( $field_name ) ) {
			return null;
		}

		$field_data = array(
			'name' => $field_name,
		);

		foreach ( $parts as $part ) {
			if ( strpos( $part, '=' ) === false ) {
				continue;
			}

			list( $key, $value ) = explode( '=', $part, 2 );
			$key = trim( strtolower( $key ) );
			$value = trim( $value, " \t\n\r\0\x0B'\"" ); // Trim quotes and whitespace

			if ( $value !== '' ) {
				$field_data[ $key ] = $value;
			}
		}

		return $field_data;
	}

	/**
	 * Normalizes XML to merge text nodes that are split across multiple tags.
	 * This is essential for reliably finding complete [placeholder] strings.
	 *
	 * @param string $xml XML content from the ODT file.
	 * @return string A plain-text representation of the XML content.
	 */
	private static function normalize_xml_text( $xml ) {
		if ( '' === $xml ) {
			return '';
		}

		// These replacements are borrowed from the main Resolate parser.
		$replacements = array(
			// For DOCX compatibility, though we are targeting ODT
			'/<\/w:t>\s*<w:r[^>]*>\s*<w:t[^>]*>/' => '',
			'/<\/w:t>\s*<w:t[^>]*>/'               => '',
			// For ODT
			'/<\/text:span>\s*<text:span[^>]*>/'   => '',
			'/<\/text:p>\s*<text:p[^>]*>/'         => ' ',
		);

		$normalized = $xml;
		foreach ( $replacements as $pattern => $replacement ) {
			$normalized = preg_replace( $pattern, $replacement, $normalized );
		}

		// Strip control characters
		$normalized = preg_replace( '/[\x00-\x1F\x7F]/u', '', $normalized );

		// Strip all remaining XML tags
		return wp_strip_all_tags( $normalized );
	}
}