<?php
/**
 * Conversion manager for Documentate.
 *
 * Names the engine that turns a document into a PDF: the native renderer,
 * which draws it in this process and is the default, or one of the two
 * converters kept as a fallback - Collabora Online on the server, or
 * LibreOffice WASM in the browser.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Main entry point to perform document conversions.
 */
class Documentate_Conversion_Manager {
	const ENGINE_WASM = 'wasm';
	const ENGINE_COLLABORA = 'collabora';
	const ENGINE_FPDF = 'fpdf';

	/**
	 * Retrieve the engine configured in the plugin settings.
	 *
	 * A site that never picked one gets the native renderer, which needs no
	 * service. A site that did keeps what it picked.
	 *
	 * @return string
	 */
	public static function get_engine() {
		$options = get_option( 'documentate_settings', array() );
		$engine = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : self::ENGINE_FPDF;
		if ( ! in_array( $engine, array( self::ENGINE_FPDF, self::ENGINE_WASM, self::ENGINE_COLLABORA ), true ) ) {
			$engine = self::ENGINE_FPDF;
		}

		return $engine;
	}

	/**
	 * Human readable label for the current engine.
	 *
	 * @param string|null $engine Optional engine name.
	 * @return string
	 */
	public static function get_engine_label( $engine = null ) {
		if ( null === $engine ) {
			$engine = self::get_engine();
		}

		$labels = array(
			self::ENGINE_FPDF => __( 'Native PDF rendering', 'documentate' ),
			self::ENGINE_WASM => __( 'LibreOffice WASM in browser (experimental)', 'documentate' ),
			self::ENGINE_COLLABORA => __( 'Collabora Online', 'documentate' ),
		);

		return isset( $labels[ $engine ] ) ? $labels[ $engine ] : $labels[ self::ENGINE_FPDF ];
	}

	/**
	 * Determine if the configured engine can currently produce a PDF.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$engine = self::get_engine();

		// The native renderer runs in this process: there is nothing to reach
		// and therefore nothing that can be unreachable.
		if ( self::ENGINE_FPDF === $engine ) {
			return true;
		}

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			return Documentate_Collabora_Converter::is_available();
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		return Documentate_Libreoffice_Wasm_Converter::is_available();
	}

	/**
	 * Perform a conversion using the configured engine.
	 *
	 * @param string $input_path   Absolute path to the source document.
	 * @param string $output_path  Absolute path to the target document.
	 * @param string $output_format Target extension (docx|odt|pdf).
	 * @param string $input_format  Optional source extension.
	 * @return string|WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format, $input_format = '' ) {
		$engine = self::get_engine();

		// The native renderer draws PDFs; it does not translate one office
		// format into another, so it refuses rather than writing a file it
		// cannot produce.
		if ( self::ENGINE_FPDF === $engine ) {
			return new WP_Error(
				'documentate_conversion_not_available',
				self::get_unavailable_message( $input_format, $output_format )
			);
		}

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			return Documentate_Collabora_Converter::convert( $input_path, $output_path, $output_format, $input_format );
		}

		// The LibreOffice WASM engine converts in the browser; there is no
		// server-side path, so report why server-side conversion is unavailable.
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		return new WP_Error(
			'documentate_conversion_not_available',
			self::get_unavailable_message(
				$input_format,
				$output_format,
			)
		);
	}

	/**
	 * Provide a contextual message describing what is missing to run conversions.
	 *
	 * @param string $source_format Optional source extension.
	 * @param string $target_format Optional target extension.
	 * @return string
	 */
	public static function get_unavailable_message( $source_format = '', $target_format = '' ) {
		$engine = self::get_engine();
		$context = self::build_context_text( $source_format, $target_format );

		if ( self::ENGINE_FPDF === $engine ) {
			return __(
				'Native PDF rendering does not convert documents between office formats. Select Collabora Online to convert them.',
				'documentate',
			) . $context;
		}

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			$status = Documentate_Collabora_Converter::get_status_message();
			if ( '' !== $status ) {
				return $status . $context;
			}
			return __( 'Collabora Online is not available to convert documents.', 'documentate' ) . $context;
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
		if ( Documentate_Collabora_Converter::is_playground() ) {
			return __(
				'In-browser LibreOffice WASM conversion is not available in WordPress Playground. Use Collabora Online instead.',
				'documentate',
			) . $context;
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		if ( ! Documentate_Libreoffice_Wasm_Converter::assets_available() ) {
			return Documentate_Libreoffice_Wasm_Converter::get_missing_assets_message() . $context;
		}

		return Documentate_Libreoffice_Wasm_Converter::get_browser_conversion_message() . $context;
	}

	/**
	 * Build the contextual suffix for availability messages.
	 *
	 * @param string $source_format Source extension.
	 * @param string $target_format Target extension.
	 * @return string
	 */
	private static function build_context_text( $source_format, $target_format ) {
		$source_format = sanitize_key( $source_format );
		$target_format = sanitize_key( $target_format );

		if ( '' !== $source_format && '' !== $target_format ) {
			return ' '
			. sprintf(
				/* translators: 1: source extension, 2: target extension. */
				__( 'Required to convert %1$s to %2$s.', 'documentate' ),
				strtoupper( $source_format ),
				strtoupper( $target_format ),
			);
		}

		if ( '' !== $target_format ) {
			return ' '
			. sprintf(
				/* translators: %s: target extension. */
				__( 'Required to generate %s.', 'documentate' ),
				strtoupper( $target_format ),
			);
		}

		return '';
	}
}
