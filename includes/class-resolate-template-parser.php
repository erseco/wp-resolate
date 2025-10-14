<?php
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
			preg_match_all( '/\[[A-Za-z0-9._-]+\]/', $normalized, $matches );
			if ( empty( $matches[0] ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$slug = trim( $match, '[]' );
				if ( '' !== $slug ) {
					$placeholders[ $slug ] = true;
				}
			}
		}

		$zip->close();

		$fields = array_keys( $placeholders );
		sort( $fields, SORT_NATURAL | SORT_FLAG_CASE );
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
