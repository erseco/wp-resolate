<?php
/**
 * Collabora Online converter for Documentate.
 *
 * Provides document conversion capabilities by delegating to a Collabora
 * Online instance using its public conversion API.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Helper to convert documents through a Collabora Online endpoint.
 */
class Documentate_Collabora_Converter {
	/**
	 * MIME type mapping for document formats.
	 *
	 * @var array<string, string>
	 */
	private static $mime_type_map = array(
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt' => 'application/vnd.oasis.opendocument.text',
		'pdf' => 'application/pdf',
	);

	/**
	 * Log debug information when WP_DEBUG is enabled.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	private static function log( $message, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$log_entry = sprintf(
			'[Documentate Collabora] %s | Context: %s',
			$message,
			wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
		error_log( $log_entry );
	}

	/**
	 * Check if running inside WordPress Playground.
	 *
	 * @return bool
	 */
	public static function is_playground() {
		// Playground sets specific constants.
		if ( defined( 'WORDPRESS_PLAYGROUND' ) && WORDPRESS_PLAYGROUND ) {
			return true;
		}

		// Check for Playground-specific URL patterns.
		$site_url = get_site_url();
		if ( strpos( $site_url, 'playground.wordpress.net' ) !== false ) {
			return true;
		}

		// Check for Playground request header.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header existence check only.
		if ( isset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] ) ) {
			return true;
		}

		// Check for common Playground indicators in the URL.
		if ( strpos( $site_url, 'wasm' ) !== false || strpos( $site_url, 'playground' ) !== false ) {
			return true;
		}

		return false;
	}

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
			return new WP_Error(
				'documentate_fs_unavailable',
				'No se pudo inicializar el sistema de archivos de WordPress.'
			);
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
			return 'Configura la URL base del servicio Collabora Online en los ajustes.';
		}

		return '';
	}

	/**
	 * Convert a document using the configured Collabora endpoint.
	 *
	 * @param string $input_path    Absolute source path.
	 * @param string $output_path   Absolute destination path.
	 * @param string $output_format Desired output extension.
	 * @param string $input_format  Optional hint with the input extension.
	 * @return string|WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format, $input_format = '' ) {
		self::log(
			'Starting conversion',
			array(
				'input_path'    => $input_path,
				'output_path'   => $output_path,
				'output_format' => $output_format,
				'input_format'  => $input_format,
				'is_playground' => self::is_playground(),
			)
		);

		$prepared = self::prepare_convert_request( $input_path, $output_path, $output_format, $input_format );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		self::log(
			'Sending request to Collabora',
			array(
				'endpoint'   => $prepared['endpoint'],
				'body_size'  => strlen( $prepared['args']['body'] ),
				'file_size'  => $prepared['file_size'],
				'timeout'    => $prepared['args']['timeout'],
				'ssl_verify' => $prepared['args']['sslverify'],
				'boundary'   => $prepared['boundary'],
			)
		);

		$response = wp_remote_post( $prepared['endpoint'], $prepared['args'] );
		return self::handle_convert_response( $response, $prepared['endpoint'], $prepared['fs'], $output_path );
	}

	/**
	 * Validate inputs and build the Collabora multipart request.
	 *
	 * @param string $input_path    Absolute source path.
	 * @param string $output_path   Absolute destination path.
	 * @param string $output_format Desired output extension.
	 * @param string $input_format  Optional input extension hint.
	 * @return array|WP_Error {
	 *     @type object $fs        WP_Filesystem instance.
	 *     @type string $endpoint  Collabora convert endpoint.
	 *     @type array  $args      Arguments for wp_remote_post().
	 *     @type string $boundary  Multipart boundary.
	 *     @type int    $file_size Source file size in bytes.
	 * }
	 */
	private static function prepare_convert_request( $input_path, $output_path, $output_format, $input_format ) {
		$fs = self::get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			self::log( 'Filesystem error', array( 'error' => $fs->get_error_message() ) );
			return $fs;
		}

		if ( ! $fs->exists( $input_path ) ) {
			self::log( 'Input file missing', array( 'path' => $input_path ) );
			return new WP_Error(
				'documentate_collabora_input_missing',
				'El fichero origen para la conversión no existe.'
			);
		}

		$base_url = self::get_base_url();
		if ( '' === $base_url ) {
			return new WP_Error(
				'documentate_collabora_not_configured',
				'Configura la URL del servicio Collabora Online para convertir documentos.'
			);
		}

		$output_format = sanitize_key( $output_format );
		if ( ! in_array( $output_format, array( 'pdf', 'docx', 'odt' ), true ) ) {
			return new WP_Error(
				'documentate_collabora_invalid_target',
				'Formato de salida no soportado por Collabora.'
			);
		}

		self::ensure_output_path_ready( $fs, $output_path );

		$file_body = $fs->get_contents( $input_path );
		if ( false === $file_body ) {
			return new WP_Error(
				'documentate_collabora_read_failed',
				'No se pudo leer el fichero de entrada para la conversión.'
			);
		}

		$boundary = wp_generate_password( 24, false );
		$body     = self::build_convert_multipart_body(
			$boundary,
			basename( $input_path ),
			self::guess_mime_type( $input_format, $input_path ),
			$file_body,
			self::get_language()
		);

		return array(
			'fs'        => $fs,
			'endpoint'  => untrailingslashit( $base_url ) . '/cool/convert-to/' . rawurlencode( $output_format ),
			'boundary'  => $boundary,
			'file_size' => strlen( $file_body ),
			'args'      => array(
				'timeout'   => apply_filters( 'documentate_collabora_timeout', 120 ),
				'sslverify' => ! self::is_ssl_verification_disabled(),
				'headers'   => array(
					'Accept'       => 'application/octet-stream',
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'      => $body,
			),
		);
	}

	/**
	 * Ensure the destination directory exists and remove a prior output file.
	 *
	 * @param object $fs          WP_Filesystem instance.
	 * @param string $output_path Destination path.
	 * @return void
	 */
	private static function ensure_output_path_ready( $fs, $output_path ) {
		$dir = dirname( $output_path );
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( $fs->exists( $output_path ) ) {
			wp_delete_file( $output_path );
		}
	}

	/**
	 * Build a multipart/form-data body for the Collabora convert endpoint.
	 *
	 * @param string $boundary Multipart boundary.
	 * @param string $filename Uploaded file name.
	 * @param string $mime     MIME type.
	 * @param string $file_body Raw file contents.
	 * @param string $lang     Language code.
	 * @return string
	 */
	private static function build_convert_multipart_body( $boundary, $filename, $mime, $file_body, $lang ) {
		$eol  = "\r\n";
		$body = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="data"; filename="' . $filename . '"' . $eol;
		$body .= 'Content-Type: ' . $mime . $eol . $eol;
		$body .= $file_body . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="lang"' . $eol . $eol;
		$body .= $lang . $eol;
		$body .= '--' . $boundary . '--' . $eol;
		return $body;
	}

	/**
	 * Process the Collabora HTTP response and write the converted file.
	 *
	 * @param array|WP_Error $response  Result of wp_remote_post().
	 * @param string         $endpoint  Request endpoint (for error context).
	 * @param object         $fs        WP_Filesystem instance.
	 * @param string         $output_path Destination path.
	 * @return string|WP_Error Output path on success.
	 */
	private static function handle_convert_response( $response, $endpoint, $fs, $output_path ) {
		if ( is_wp_error( $response ) ) {
			self::log(
				'Request failed',
				array(
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
				)
			);
			return new WP_Error(
				'documentate_collabora_request_failed',
				sprintf(
					'Error al conectar con Collabora Online: %s',
					$response->get_error_message()
				),
				array(
					'code'     => $response->get_error_code(),
					'endpoint' => $endpoint,
				)
			);
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = (string) wp_remote_retrieve_body( $response );

		self::log(
			'Response received',
			array(
				'status_code'  => $status,
				'body_size'    => strlen( $resp_body ),
				'body_preview' => substr( $resp_body, 0, 200 ),
				'headers'      => wp_remote_retrieve_headers( $response )->getAll(),
			)
		);

		if ( $status < 200 || $status >= 300 ) {
			self::log(
				'HTTP error response',
				array(
					'status' => $status,
					'body'   => substr( $resp_body, 0, 500 ),
				)
			);
			return new WP_Error(
				'documentate_collabora_http_error',
				sprintf(
					'Collabora Online devolvió el código HTTP %d durante la conversión.',
					$status
				),
				array(
					'status'   => $status,
					'body'     => substr( $resp_body, 0, 500 ),
					'endpoint' => $endpoint,
				)
			);
		}

		// A 200 with no payload would otherwise be written out as a zero byte
		// document and reported as a successful conversion.
		if ( '' === $resp_body ) {
			self::log( 'Empty response body', array( 'endpoint' => $endpoint ) );
			return new WP_Error(
				'documentate_collabora_empty_response',
				'Collabora devolvió una respuesta vacía.',
				array( 'endpoint' => $endpoint )
			);
		}

		if ( false === $fs->put_contents( $output_path, $resp_body, FS_CHMOD_FILE ) ) {
			self::log( 'Write failed', array( 'output_path' => $output_path ) );
			return new WP_Error(
				'documentate_collabora_write_failed',
				'No se pudo guardar el fichero convertido en el disco.'
			);
		}

		self::log( 'Conversion successful', array( 'output_path' => $output_path ) );
		return $output_path;
	}

	/**
	 * Retrieve the configured base URL.
	 *
	 * @return string
	 */
	private static function get_base_url() {
		$options = get_option( 'documentate_settings', array() );
		$value = isset( $options['collabora_base_url'] ) ? trim( (string) $options['collabora_base_url'] ) : '';
		if ( '' === $value && defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
			$value = trim( (string) DOCUMENTATE_COLLABORA_DEFAULT_URL );
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
		$options = get_option( 'documentate_settings', array() );
		$lang = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'es-ES';
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
		// Never skip TLS certificate verification on production, even if the
		// option is enabled — official documents must not travel over an
		// unverified connection. The toggle stays available for local testing.
		if ( 'production' === wp_get_environment_type() ) {
			return false;
		}

		$options = get_option( 'documentate_settings', array() );
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

		if ( isset( self::$mime_type_map[ $input_format ] ) ) {
			return self::$mime_type_map[ $input_format ];
		}

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream';
		return $mime ? $mime : 'application/octet-stream';
	}
}
