<?php
/**
 * Base export handler for Documentate documents.
 *
 * @package Documentate
 * @subpackage Export
 * @since 1.0.0
 */

namespace Documentate\Export;

/**
 * Abstract base class for document export handlers.
 *
 * Eliminates duplicate code in DOCX, ODT, and PDF export handlers.
 */
abstract class Export_Handler {
	/**
	 * Get the export format (docx, odt, pdf).
	 *
	 * @return string
	 */
	abstract protected function get_format();

	/**
	 * Get the MIME type for the format.
	 *
	 * @return string
	 */
	abstract protected function get_mime_type();

	/**
	 * Generate the document file.
	 *
	 * @param int $post_id Post ID.
	 * @return string|\WP_Error File path or error.
	 */
	abstract protected function generate( $post_id );

	/**
	 * Handle the export request.
	 *
	 * @return void
	 */
	public function handle() {
		$post_id = $this->get_post_id_from_request();

		if ( ! $this->validate_request( $post_id ) ) {
			return;
		}

		$result = $this->generate( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->handle_error( $result, $post_id );
			return;
		}

		$stream = $this->stream_file_download( $result );
		if ( is_wp_error( $stream ) ) {
			wp_die( esc_html( $stream->get_error_message() ), '', array( 'back_link' => true ) );
		}

		exit();
	}

	/**
	 * Get post ID from request.
	 *
	 * @return int
	 */
	protected function get_post_id_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
	}

	/**
	 * Validate the export request.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function validate_request( $post_id ) {
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html( 'Permisos insuficientes.' ) );
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_export_' . $post_id )
		) {
			wp_die( esc_html( 'Nonce no válido.' ) );
			return false;
		}

		return true;
	}

	/**
	 * Handle generation error.
	 *
	 * @param \WP_Error $error   Error object.
	 * @param int       $post_id Post ID.
	 * @return void
	 */
	protected function handle_error( $error, $post_id ) {
		$msg = $error->get_error_message();
		wp_safe_redirect( add_query_arg( 'documentate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
		exit();
	}

	/**
	 * Stream file for download.
	 *
	 * @param string $file_path Path to file.
	 * @return bool|\WP_Error
	 */
	protected function stream_file_download( $file_path ) {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new \WP_Error(
				'documentate_fs_unavailable',
				'No se pudo inicializar el sistema de archivos de WordPress.'
			);
		}

		if ( ! $wp_filesystem->exists( $file_path ) || ! $wp_filesystem->is_readable( $file_path ) ) {
			return new \WP_Error( 'documentate_file_not_found', 'Fichero generado no encontrado.' );
		}

		$filesize = (int) $wp_filesystem->size( $file_path );
		$download_name = wp_basename( $file_path );
		$encoded_name = rawurlencode( $download_name );
		$disposition = 'attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name;

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: ' . $this->get_mime_type() );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		// Stream the file straight to the output instead of buffering the whole
		// document in memory. WP_Filesystem has no chunked-read API, so readfile()
		// is used for the local uploads path.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a validated local binary file to the browser.
		readfile( $file_path );

		return true;
	}
}
