<?php
/**
 * The file a document carries: the signed PDF, the ODT it was written in, the
 * DOCX somebody sent by mail.
 *
 * One file per document (the meta keeps a list for backwards compatibility,
 * but only the first entry is used). Uploads go through the media library with
 * media_handle_sideload(), so the attachment is a normal WordPress attachment
 * with the document as its parent.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Validate, store and remove the file attached to a document.
 */
class Documentate_App_Adjuntos {

	/**
	 * Hard limit of the application, in bytes (20 MB).
	 *
	 * @var int
	 */
	const MAX_BYTES = 20971520;

	/**
	 * Name of the file input of the edit form.
	 *
	 * @var string
	 */
	const CAMPO = 'documentate_app_adjunto';

	/**
	 * Extensions the application accepts, and the mime type of each.
	 *
	 * @return array<string,string>
	 */
	public static function tipos_permitidos() {
		return array(
			'pdf' => 'application/pdf',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);
	}

	/**
	 * Largest file this site accepts: the application limit or the server one.
	 *
	 * @return int Bytes.
	 */
	public static function tamano_maximo() {
		$servidor = (int) wp_max_upload_size();

		return $servidor > 0 ? min( self::MAX_BYTES, $servidor ) : self::MAX_BYTES;
	}

	/**
	 * Whether an uploaded file may become the file of a document.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return true|WP_Error
	 */
	public static function validar( array $archivo ) {
		$error = self::error_de_subida( $archivo );
		if ( null === $error ) {
			$error = self::error_de_fichero( $archivo );
		}
		if ( null === $error ) {
			$error = self::error_de_tipo( $archivo );
		}

		return null === $error ? true : $error;
	}

	/**
	 * What PHP says about the upload itself.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function error_de_subida( array $archivo ) {
		$error = isset( $archivo['error'] ) ? (int) $archivo['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'sin_fichero', 'No se ha seleccionado ningún fichero.' );
		}

		return UPLOAD_ERR_OK === $error ? null : new WP_Error( 'subida', 'No se pudo subir el fichero.' );
	}

	/**
	 * Whether the file is really there and small enough.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function error_de_fichero( array $archivo ) {
		$ruta = self::ruta( $archivo );
		if ( '' === $ruta || '' === self::nombre_enviado( $archivo ) ) {
			return new WP_Error( 'sin_fichero', 'No se ha seleccionado ningún fichero.' );
		}

		$tamano = isset( $archivo['size'] ) ? (int) $archivo['size'] : (int) filesize( $ruta );

		return $tamano > 0 && $tamano <= self::tamano_maximo()
			? null
			: new WP_Error( 'tamano', 'El fichero supera el tamaño máximo permitido.' );
	}

	/**
	 * Whether the extension and the real content agree on an accepted format.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function error_de_tipo( array $archivo ) {
		$tipos = self::tipos_permitidos();
		$comprobado = wp_check_filetype_and_ext( self::ruta( $archivo ), self::nombre_enviado( $archivo ), $tipos );
		$extension = isset( $comprobado['ext'] ) ? (string) $comprobado['ext'] : '';

		return isset( $tipos[ $extension ] )
			? null
			: new WP_Error( 'tipo', 'Solo se admiten ficheros PDF, ODT o DOCX.' );
	}

	/**
	 * Temporary path of an upload, when it exists on disk.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return string Empty when there is nothing to read.
	 */
	private static function ruta( array $archivo ) {
		$ruta = isset( $archivo['tmp_name'] ) ? (string) $archivo['tmp_name'] : '';

		return '' !== $ruta && file_exists( $ruta ) ? $ruta : '';
	}

	/**
	 * File name of an upload, sanitised.
	 *
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return string
	 */
	private static function nombre_enviado( array $archivo ) {
		return isset( $archivo['name'] ) ? sanitize_file_name( (string) $archivo['name'] ) : '';
	}

	/**
	 * Attach an uploaded file to a document, replacing the previous one.
	 *
	 * @param int                 $post_id Document ID.
	 * @param array<string,mixed> $archivo One entry of $_FILES.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function guardar( $post_id, array $archivo ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'sin_permiso', 'No puedes adjuntar ficheros a este documento.' );
		}

		$valido = self::validar( $archivo );
		if ( is_wp_error( $valido ) ) {
			return $valido;
		}

		// The sideload helpers live in wp-admin and the application runs on the
		// front end, so they are pulled in here rather than at load time.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$datos = array(
			'name' => sanitize_file_name( (string) $archivo['name'] ),
			'type' => isset( $archivo['type'] ) ? sanitize_mime_type( (string) $archivo['type'] ) : '',
			'tmp_name' => (string) $archivo['tmp_name'],
			'error' => 0,
			'size' => isset( $archivo['size'] ) ? (int) $archivo['size'] : (int) filesize( (string) $archivo['tmp_name'] ),
		);

		$attachment_id = media_handle_sideload( $datos, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $datos['tmp_name'] ) ) {
				wp_delete_file( $datos['tmp_name'] );
			}

			return $attachment_id;
		}

		update_post_meta( $post_id, Documentate_Documento::META_ADJUNTOS, array( (int) $attachment_id ) );

		// The stored name, not the posted one: WordPress renames a file when
		// another one of the same name is already there, and the activity must
		// name what quitar() will name later.
		Documentate_Actividad::registrar_evento( $post_id, 'adjuntó el fichero «' . self::nombre( (int) $attachment_id ) . '»' );

		return (int) $attachment_id;
	}

	/**
	 * Detach the file of a document.
	 *
	 * The attachment itself is kept in the media library: it may be linked
	 * from a revision or from somebody's mailbox, and nothing else in the
	 * plugin deletes uploads behind the user's back.
	 *
	 * @param int $post_id Document ID.
	 * @return bool Whether a file was detached.
	 */
	public static function quitar( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$adjunto = Documentate_Documento::adjunto( $post_id );
		delete_post_meta( $post_id, Documentate_Documento::META_ADJUNTOS );

		if ( null === $adjunto ) {
			return false;
		}

		Documentate_Actividad::registrar_evento( $post_id, 'quitó el fichero «' . self::nombre( $adjunto->ID ) . '»' );

		return true;
	}

	/**
	 * File name of an attachment, as it is stored in the uploads folder.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function nombre( $attachment_id ) {
		$ruta = (string) get_attached_file( (int) $attachment_id );

		return '' === $ruta ? '' : basename( $ruta );
	}

	/**
	 * Size of an attachment, ready to print ("1 MB").
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty when the file is gone.
	 */
	public static function tamano_legible( $attachment_id ) {
		$ruta = get_attached_file( (int) $attachment_id );
		if ( ! $ruta || ! file_exists( $ruta ) ) {
			return '';
		}

		$bytes = (int) filesize( $ruta );

		return $bytes > 0 ? (string) size_format( $bytes ) : '';
	}
}
