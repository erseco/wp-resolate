<?php
/**
 * Collabora Online converter for Resolate.
 *
 * Provides document conversion capabilities by delegating to a Collabora
 * Online instance using its public conversion API.
 *
 * @package Resolate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Helper to convert documents through a Collabora Online endpoint.
 */
class Resolate_Collabora_Converter {

	/**
	 * Get an initialized WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|WP_Error Filesystem handler or error on failure.
	 */
	private static function get_wp_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'resolate_fs_unavailable', __( 'No se pudo inicializar el sistema de archivos de WordPress.', 'resolate' ) );
		}

		return $wp_filesystem;
	}

	/**
	 * Check whether the converter has enough configuration to run.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::get_base_url();
	}

	/**
	 * Return a human readable message describing missing configuration.
	 *
	 * @return string
	 */
	public static function get_status_message() {
		if ( '' === self::get_base_url() ) {
			return __( 'Configura la URL base del servicio Collabora Online en los ajustes.', 'resolate' );
		}

		return '';
	}

	/**
	 * Convert a document using the configured Collabora endpoint.
	 *
	 * @param string $input_path   Absolute source path.
	 * @param string $output_path  Absolute destination path.
	 * @param string $output_format Desired output extension.
	 * @param string $input_format  Optional hint with the input extension.
	 * @return string|WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format, $input_format = '' ) {
		$fs = self::get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->exists( $input_path ) ) {
			return new WP_Error( 'resolate_collabora_input_missing', __( 'El fichero origen para la conversión no existe.', 'resolate' ) );
		}

		$base_url = self::get_base_url();
		if ( '' === $base_url ) {
			return new WP_Error( 'resolate_collabora_not_configured', __( 'Configura la URL del servicio Collabora Online para convertir documentos.', 'resolate' ) );
		}

			$supported_formats = array( 'pdf', 'docx', 'odt' );
		$output_format     = sanitize_key( $output_format );
		if ( ! in_array( $output_format, $supported_formats, true ) ) {
			return new WP_Error( 'resolate_collabora_invalid_target', __( 'Formato de salida no soportado por Collabora.', 'resolate' ) );
		}

		$endpoint = untrailingslashit( $base_url ) . '/cool/convert-to/' . rawurlencode( $output_format );

		$dir = dirname( $output_path );
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( $fs->exists( $output_path ) ) {
			wp_delete_file( $output_path );
		}

		$mime      = self::guess_mime_type( $input_format, $input_path );
		$lang      = self::get_language();
		$filename  = basename( $input_path );
		$file_body = $fs->get_contents( $input_path );
		if ( false === $file_body ) {
			return new WP_Error( 'resolate_collabora_read_failed', __( 'No se pudo leer el fichero de entrada para la conversión.', 'resolate' ) );
		}

		$boundary = wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';
		$body    .= '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="data"; filename="' . $filename . '"' . $eol;
		$body    .= 'Content-Type: ' . $mime . $eol . $eol;
		$body    .= $file_body . $eol;
		$body    .= '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="lang"' . $eol . $eol;
		$body    .= $lang . $eol;
		$body    .= '--' . $boundary . '--' . $eol;

		$args = array(
			'timeout'   => apply_filters( 'resolate_collabora_timeout', 120 ),
			'sslverify' => ! self::is_ssl_verification_disabled(),
			'headers'   => array(
				'Accept'       => 'application/octet-stream',
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'      => $body,
		);

		$response = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'resolate_collabora_request_failed',
				sprintf(
					/* translators: %s: error message returned by wp_remote_post(). */
					__( 'Error al conectar con Collabora Online: %s', 'resolate' ),
					$response->get_error_message()
				),
				array( 'code' => $response->get_error_code() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'resolate_collabora_http_error',
				sprintf(
					/* translators: %d: HTTP status code returned by Collabora. */
					__( 'Collabora Online devolvió el código HTTP %d durante la conversión.', 'resolate' ),
					$status
				),
				array( 'body' => $body )
			);
		}

		$written = $fs->put_contents( $output_path, $body, FS_CHMOD_FILE );
		if ( false === $written ) {
			return new WP_Error( 'resolate_collabora_write_failed', __( 'No se pudo guardar el fichero convertido en el disco.', 'resolate' ) );
		}

		return $output_path;
	}

	/**
	 * Retrieve the configured base URL.
	 *
	 * @return string
	 */
	private static function get_base_url() {
		$options = get_option( 'resolate_settings', array() );
		$value   = isset( $options['collabora_base_url'] ) ? trim( (string) $options['collabora_base_url'] ) : '';
		if ( '' === $value && defined( 'RESOLATE_COLLABORA_DEFAULT_URL' ) ) {
			$value = trim( (string) RESOLATE_COLLABORA_DEFAULT_URL );
		}

		if ( '' === $value ) {
			return '';
		}

		return untrailingslashit( esc_url_raw( $value ) );
	}

	/**
	 * Retrieve the language parameter configured for conversions.
	 *
	 * @return string
	 */
	private static function get_language() {
		$options = get_option( 'resolate_settings', array() );
		$lang    = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'es-ES';
		if ( '' === $lang ) {
			$lang = 'es-ES';
		}

		return $lang;
	}

	/**
	 * Determine whether SSL verification should be skipped.
	 *
	 * @return bool
	 */
	private static function is_ssl_verification_disabled() {
		$options = get_option( 'resolate_settings', array() );
		return isset( $options['collabora_disable_ssl'] ) && '1' === $options['collabora_disable_ssl'];
	}

	/**
	 * Guess the MIME type for the uploaded document.
	 *
	 * @param string $input_format Format hint.
	 * @param string $path         Fallback file path.
	 * @return string
	 */
	private static function guess_mime_type( $input_format, $path ) {
		$input_format = sanitize_key( $input_format );
		switch ( $input_format ) {
			case 'docx':
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			case 'odt':
				return 'application/vnd.oasis.opendocument.text';
			case 'pdf':
				return 'application/pdf';
		}

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream';
		return $mime ? $mime : 'application/octet-stream';
	}
}
