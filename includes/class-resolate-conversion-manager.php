<?php
/**
 * Conversion manager for Resolate.
 *
 * Chooses the appropriate engine (LibreOffice WASM via ZetaJS or Collabora
 * Online) to convert documents generated from OpenTBS templates.
 *
 * @package Resolate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main entry point to perform document conversions.
 */
class Resolate_Conversion_Manager {

	const ENGINE_WASM      = 'wasm';
	const ENGINE_COLLABORA = 'collabora';

	/**
	 * Retrieve the engine configured in the plugin settings.
	 *
	 * @return string
	 */
	public static function get_engine() {
		$options = get_option( 'resolate_settings', array() );
		$engine  = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : self::ENGINE_COLLABORA;
		if ( ! in_array( $engine, array( self::ENGINE_WASM, self::ENGINE_COLLABORA ), true ) ) {
			$engine = self::ENGINE_COLLABORA;
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
			self::ENGINE_WASM      => __( 'LibreOffice WASM en el navegador (experimental)', 'resolate' ),
			self::ENGINE_COLLABORA => __( 'Collabora Online', 'resolate' ),
		);

		return isset( $labels[ $engine ] ) ? $labels[ $engine ] : $labels[ self::ENGINE_COLLABORA ];
	}

	/**
	 * Determine if the configured engine can currently run server-side conversions.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$engine = self::get_engine();

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-collabora.php';
			return Resolate_Collabora_Converter::is_available();
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs.php';
		if ( Resolate_Zetajs_Converter::is_cdn_mode() ) {
			return false;
		}
		return Resolate_Zetajs_Converter::is_available();
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

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-collabora.php';
			return Resolate_Collabora_Converter::convert( $input_path, $output_path, $output_format, $input_format );
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs.php';
		if ( Resolate_Zetajs_Converter::is_cdn_mode() ) {
			return new WP_Error( 'resolate_conversion_not_available', self::get_unavailable_message( $input_format, $output_format ) );
		}

		return Resolate_Zetajs_Converter::convert( $input_path, $output_path, $output_format, $input_format );
	}

	/**
	 * Provide a contextual message describing what is missing to run conversions.
	 *
	 * @param string $source_format Optional source extension.
	 * @param string $target_format Optional target extension.
	 * @return string
	 */
	public static function get_unavailable_message( $source_format = '', $target_format = '' ) {
		$engine        = self::get_engine();
		$context       = self::build_context_text( $source_format, $target_format );
		$default_label = __( 'No se pudo completar la conversión.', 'resolate' );

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-collabora.php';
			$status = Resolate_Collabora_Converter::get_status_message();
			if ( '' !== $status ) {
				return $status . $context;
			}
			return __( 'Collabora Online no está disponible para convertir documentos.', 'resolate' ) . $context;
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs.php';
		if ( Resolate_Zetajs_Converter::is_cdn_mode() ) {
			return __( 'Desactiva el modo CDN de ZetaJS y configura el ejecutable local para realizar conversiones en el servidor.', 'resolate' ) . $context;
		}

		if ( Resolate_Zetajs_Converter::is_available() ) {
			return $default_label . $context;
		}

		return __( 'Configura la ruta del ejecutable de ZetaJS (LibreOffice WASM) en el servidor.', 'resolate' ) . $context;
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
			return ' ' . sprintf(
				/* translators: 1: source extension, 2: target extension. */
				__( 'Necesario para convertir %1$s a %2$s.', 'resolate' ),
				strtoupper( $source_format ),
				strtoupper( $target_format )
			);
		}

		if ( '' !== $target_format ) {
			return ' ' . sprintf(
				/* translators: %s: target extension. */
				__( 'Necesario para generar %s.', 'resolate' ),
				strtoupper( $target_format )
			);
		}

		return '';
	}
}
