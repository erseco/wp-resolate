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
     * Check whether the converter has enough configuration to run.
     *
     * @return bool
     */
    public static function is_available() {
        if ( '' === self::get_base_url() ) {
            return false;
        }

        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_file_create' ) ) {
            return false;
        }

        return true;
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

        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_file_create' ) ) {
            return __( 'Activa la extensión cURL de PHP para contactar con Collabora Online.', 'resolate' );
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
        if ( ! file_exists( $input_path ) ) {
            return new WP_Error( 'resolate_collabora_input_missing', __( 'El fichero origen para la conversión no existe.', 'resolate' ) );
        }

        $base_url = self::get_base_url();
        if ( '' === $base_url ) {
            return new WP_Error( 'resolate_collabora_not_configured', __( 'Configura la URL del servicio Collabora Online para convertir documentos.', 'resolate' ) );
        }

        if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_file_create' ) ) {
            return new WP_Error( 'resolate_collabora_missing_curl', __( 'La extensión cURL de PHP es necesaria para usar Collabora Online.', 'resolate' ) );
        }

        $supported_formats = array( 'pdf', 'docx', 'odt' );
        $output_format     = sanitize_key( $output_format );
        if ( ! in_array( $output_format, $supported_formats, true ) ) {
            return new WP_Error( 'resolate_collabora_invalid_target', __( 'Formato de salida no soportado por Collabora.', 'resolate' ) );
        }

        $endpoint = untrailingslashit( $base_url ) . '/cool/convert-to/' . rawurlencode( $output_format );

        $dir = dirname( $output_path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        if ( file_exists( $output_path ) ) {
            @unlink( $output_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $mime = self::guess_mime_type( $input_format, $input_path );

        $post_fields = array(
            'data' => curl_file_create( $input_path, $mime, basename( $input_path ) ),
            'lang' => self::get_language(),
        );

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endpoint );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $post_fields );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_HEADER, false );
        curl_setopt( $ch, CURLOPT_TIMEOUT, apply_filters( 'resolate_collabora_timeout', 120 ) );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Accept: application/octet-stream' ) );

        if ( self::is_ssl_verification_disabled() ) {
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
        }

        $body = curl_exec( $ch );
        if ( false === $body ) {
            $error = curl_error( $ch );
            $code  = curl_errno( $ch );
            curl_close( $ch );
            return new WP_Error(
                'resolate_collabora_request_failed',
                sprintf(
                    /* translators: %s: error message returned by curl_error(). */
                    __( 'Error al conectar con Collabora Online: %s', 'resolate' ),
                    $error
                ),
                array( 'code' => $code )
            );
        }

        $status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

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

        $written = file_put_contents( $output_path, $body );
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
