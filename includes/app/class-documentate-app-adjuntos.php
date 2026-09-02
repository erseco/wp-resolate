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
	 * Attachment meta holding the file name the person chose.
	 *
	 * The name on disk carries random entropy (see nombre_en_disco()), so the
	 * readable one is kept apart for the lists and the activity.
	 *
	 * @var string
	 */
	const META_NOMBRE = '_documentate_nombre_original';

	/**
	 * Action of admin-post.php that serves the file of a document.
	 *
	 * @var string
	 */
	const ACCION_SERVIR = 'documentate_adjunto';

	/**
	 * Register the hooks that serve attachments.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACCION_SERVIR, array( __CLASS__, 'servir' ) );
	}

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

		if ( ! self::origen_aceptable( (string) $archivo['tmp_name'] ) ) {
			return new WP_Error( 'sin_subida', 'El fichero no procede de esta subida.' );
		}

		// The sideload helpers live in wp-admin and the application runs on the
		// front end, so they are pulled in here rather than at load time.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$nombre = sanitize_file_name( (string) $archivo['name'] );
		$datos = array(
			'name' => self::nombre_en_disco( $nombre ),
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

		update_post_meta( (int) $attachment_id, self::META_NOMBRE, $nombre );
		update_post_meta( $post_id, Documentate_Documento::META_ADJUNTOS, array( (int) $attachment_id ) );

		Documentate_Actividad::registrar_evento( $post_id, 'adjuntó el fichero «' . self::nombre( (int) $attachment_id ) . '»' );

		return (int) $attachment_id;
	}

	/**
	 * Whether a path may be moved into the media library.
	 *
	 * The media_handle_sideload() helper — unlike wp_handle_upload() — does
	 * not check that the path really is an upload of this request, so anything reaching
	 * it from a posted tmp_name would copy an arbitrary readable file of the
	 * server to a public URL. A real upload is always accepted; a path the
	 * plugin wrote itself (the demo seeder and the test fixtures use
	 * wp_tempnam()) only where demo content is allowed at all, which is never
	 * a production site.
	 *
	 * @param string $ruta Temporary path handed to guardar().
	 * @return bool
	 */
	private static function origen_aceptable( $ruta ) {
		if ( '' === $ruta ) {
			return false;
		}

		if ( is_uploaded_file( $ruta ) ) {
			return true;
		}

		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return false;
		}

		$temporal = realpath( get_temp_dir() );
		$real = realpath( $ruta );

		return false !== $temporal && false !== $real && str_starts_with( $real, trailingslashit( $temporal ) );
	}

	/**
	 * The name the uploaded file is stored under.
	 *
	 * The uploads folder is served straight by the web server, so a document
	 * whose file keeps its own name ("resolucion.pdf") is one guess away from
	 * anybody on the internet. The readable name is kept in META_NOMBRE and
	 * the file itself gets a random token nobody can guess. url() is what the
	 * views link to, and it checks the capability before serving anything.
	 *
	 * @param string $nombre Sanitized file name chosen by the person.
	 * @return string
	 */
	private static function nombre_en_disco( $nombre ) {
		$extension = strtolower( (string) pathinfo( $nombre, PATHINFO_EXTENSION ) );
		$base = (string) pathinfo( $nombre, PATHINFO_FILENAME );
		$base = '' === $base ? 'documento' : $base;

		$token = substr( bin2hex( random_bytes( 8 ) ), 0, 16 );

		return '' === $extension ? $base . '-' . $token : $base . '-' . $token . '.' . $extension;
	}

	/**
	 * URL that serves the file of a document, checking the capability first.
	 *
	 * @param int $post_id Document ID.
	 * @return string Empty when the document carries no file.
	 */
	public static function url( $post_id ) {
		$post = Documentate_Documento::post( $post_id );
		$adjunto = null === $post ? null : Documentate_Documento::adjunto( $post );
		if ( null === $post || null === $adjunto ) {
			return '';
		}

		return add_query_arg(
			array(
				'action' => self::ACCION_SERVIR,
				'doc' => $post->ID,
				'adjunto' => $adjunto->ID,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Serve the file of a document to whoever may open that document.
	 *
	 * @return void
	 */
	public static function servir() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request authorised by the capability check below; a nonce would only expire the link.
		$doc_id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request authorised by the capability check below.
		$adjunto_id = isset( $_GET['adjunto'] ) ? absint( $_GET['adjunto'] ) : 0;

		$ruta = self::ruta_servible( $doc_id, $adjunto_id );
		if ( '' === $ruta ) {
			wp_die( esc_html( 'No tienes permiso para abrir este fichero.' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . get_post_mime_type( $adjunto_id ) );
		header( 'Content-Length: ' . filesize( $ruta ) );
		header( 'Content-Disposition: inline; filename="' . self::nombre( $adjunto_id ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a file to the browser; WP_Filesystem would read it all into memory.
		readfile( $ruta );
		exit;
	}

	/**
	 * Path of the file, when this person may open that document.
	 *
	 * @param int $doc_id     Document ID.
	 * @param int $adjunto_id Attachment ID.
	 * @return string Empty when the request may not be served.
	 */
	private static function ruta_servible( $doc_id, $adjunto_id ) {
		if ( $doc_id <= 0 || $adjunto_id <= 0 || ! current_user_can( 'edit_post', $doc_id ) ) {
			return '';
		}

		$adjunto = Documentate_Documento::adjunto( $doc_id );
		if ( null === $adjunto || $adjunto->ID !== $adjunto_id ) {
			return '';
		}

		$ruta = (string) get_attached_file( $adjunto_id );

		return '' !== $ruta && file_exists( $ruta ) ? $ruta : '';
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
	 * File name of an attachment, as the person who uploaded it wrote it.
	 *
	 * Falls back to the name on disk for files attached before the readable
	 * name was kept apart from it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function nombre( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$nombre = (string) get_post_meta( $attachment_id, self::META_NOMBRE, true );
		if ( '' !== $nombre ) {
			return $nombre;
		}

		$ruta = (string) get_attached_file( $attachment_id );

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
