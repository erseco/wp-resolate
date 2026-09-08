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
class Documentate_App_Attachments {

	/**
	 * Hard limit of the application, in bytes (20 MB).
	 *
	 * @var int
	 */
	const MAX_BYTES = 20971520;

	/**
	 * Attachment meta holding the file name the person chose.
	 *
	 * The name on disk carries random entropy (see name_on_disk()), so the
	 * readable one is kept apart for the lists and the activity.
	 *
	 * @var string
	 */
	const META_NAME = '_documentate_nombre_original';

	/**
	 * Action of admin-post.php that serves the file of a document.
	 *
	 * @var string
	 */
	const SERVE_ACTION = 'documentate_adjunto';

	/**
	 * Register the hooks that serve attachments.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::SERVE_ACTION, array( __CLASS__, 'serve' ) );
	}

	/**
	 * Extensions the application accepts, and the mime type of each.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_types() {
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
	public static function max_size() {
		$server = (int) wp_max_upload_size();

		return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
	}

	/**
	 * Whether an uploaded file may become the file of a document.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return true|WP_Error
	 */
	public static function validate( array $file ) {
		$error = self::upload_error( $file );
		if ( null === $error ) {
			$error = self::file_error( $file );
		}
		if ( null === $error ) {
			$error = self::type_error( $file );
		}

		return null === $error ? true : $error;
	}

	/**
	 * What PHP says about the upload itself.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function upload_error( array $file ) {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new WP_Error( 'sin_fichero', 'No se ha seleccionado ningún fichero.' );
		}

		return UPLOAD_ERR_OK === $error ? null : new WP_Error( 'subida', 'No se pudo subir el fichero.' );
	}

	/**
	 * Whether the file is really there and small enough.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function file_error( array $file ) {
		$path = self::path( $file );
		if ( '' === $path || '' === self::posted_name( $file ) ) {
			return new WP_Error( 'sin_fichero', 'No se ha seleccionado ningún fichero.' );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $path );

		return $size > 0 && $size <= self::max_size()
			? null
			: new WP_Error( 'tamano', 'El fichero supera el tamaño máximo permitido.' );
	}

	/**
	 * Whether the extension and the real content agree on an accepted format.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return WP_Error|null
	 */
	private static function type_error( array $file ) {
		$types = self::allowed_types();
		$checked = wp_check_filetype_and_ext( self::path( $file ), self::posted_name( $file ), $types );
		$extension = isset( $checked['ext'] ) ? (string) $checked['ext'] : '';

		return isset( $types[ $extension ] )
			? null
			: new WP_Error( 'tipo', 'Solo se admiten ficheros PDF, ODT o DOCX.' );
	}

	/**
	 * Temporary path of an upload, when it exists on disk.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return string Empty when there is nothing to read.
	 */
	private static function path( array $file ) {
		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		return '' !== $path && file_exists( $path ) ? $path : '';
	}

	/**
	 * File name of an upload, sanitised.
	 *
	 * @param array<string,mixed> $file One entry of $_FILES.
	 * @return string
	 */
	private static function posted_name( array $file ) {
		return isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
	}

	/**
	 * Attach an uploaded file to a document, replacing the previous one.
	 *
	 * @param int                 $post_id Document ID.
	 * @param array<string,mixed> $file    One entry of $_FILES.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function store( $post_id, array $file ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'sin_permiso', 'No puedes adjuntar ficheros a este documento.' );
		}

		$valid = self::validate( $file );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( ! self::source_acceptable( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'sin_subida', 'El fichero no procede de esta subida.' );
		}

		// The sideload helpers live in wp-admin and the application runs on the
		// front end, so they are pulled in here rather than at load time.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$name = sanitize_file_name( (string) $file['name'] );
		$data = array(
			'name' => self::name_on_disk( $name ),
			'type' => isset( $file['type'] ) ? sanitize_mime_type( (string) $file['type'] ) : '',
			'tmp_name' => (string) $file['tmp_name'],
			'error' => 0,
			'size' => isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( (string) $file['tmp_name'] ),
		);

		$attachment_id = media_handle_sideload( $data, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $data['tmp_name'] ) ) {
				wp_delete_file( $data['tmp_name'] );
			}

			return $attachment_id;
		}

		update_post_meta( (int) $attachment_id, self::META_NAME, $name );
		update_post_meta( $post_id, Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment_id ) );

		Documentate_Activity::record_event( $post_id, 'adjuntó el fichero «' . self::name( (int) $attachment_id ) . '»' );

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
	 * @param string $path Temporary path handed to store().
	 * @return bool
	 */
	private static function source_acceptable( $path ) {
		if ( '' === $path ) {
			return false;
		}

		if ( is_uploaded_file( $path ) ) {
			return true;
		}

		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return false;
		}

		$temp_dir = realpath( get_temp_dir() );
		$real = realpath( $path );

		return false !== $temp_dir && false !== $real && str_starts_with( $real, trailingslashit( $temp_dir ) );
	}

	/**
	 * The name the uploaded file is stored under.
	 *
	 * The uploads folder is served straight by the web server, so a document
	 * whose file keeps its own name ("resolucion.pdf") is one guess away from
	 * anybody on the internet. The readable name is kept in META_NAME and
	 * the file itself gets a random token nobody can guess. url() is what the
	 * views link to, and it checks the capability before serving anything.
	 *
	 * @param string $name Sanitized file name chosen by the person.
	 * @return string
	 */
	private static function name_on_disk( $name ) {
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$base = (string) pathinfo( $name, PATHINFO_FILENAME );
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
		$post = Documentate_Document_Data::post( $post_id );
		$attachment = null === $post ? null : Documentate_Document_Data::attachment( $post );
		if ( null === $post || null === $attachment ) {
			return '';
		}

		return add_query_arg(
			array(
				'action' => self::SERVE_ACTION,
				'doc' => $post->ID,
				'adjunto' => $attachment->ID,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Serve the file of a document to whoever may open that document.
	 *
	 * @return void
	 */
	public static function serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request authorised by the capability check below; a nonce would only expire the link.
		$doc_id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request authorised by the capability check below.
		$attachment_id = isset( $_GET['adjunto'] ) ? absint( $_GET['adjunto'] ) : 0;

		$path = self::servable_path( $doc_id, $attachment_id );
		if ( '' === $path ) {
			wp_die( esc_html( 'No tienes permiso para abrir este fichero.' ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . get_post_mime_type( $attachment_id ) );
		// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- $path is not a name the request carries: the request only names a document and an attachment ID (both absint), servable_path() refuses unless the reader may edit that document and the ID is the very attachment that document holds, and Documentate_Files resolves the stored path with realpath() and refuses anything outside the uploads directory.
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . Documentate_Files::header_file_name( self::name( $attachment_id ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- Same guarded path as the header above.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a file to the browser; WP_Filesystem would read it all into memory.
		exit;
	}

	/**
	 * Path of the file, when this person may open that document.
	 *
	 * @param int $doc_id     Document ID.
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty when the request may not be served.
	 */
	private static function servable_path( $doc_id, $attachment_id ) {
		if ( $doc_id <= 0 || $attachment_id <= 0 || ! current_user_can( 'edit_post', $doc_id ) ) {
			return '';
		}

		$attachment = Documentate_Document_Data::attachment( $doc_id );
		if ( null === $attachment || $attachment->ID !== $attachment_id ) {
			return '';
		}

		return Documentate_Files::attachment_path( $attachment_id );
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
	public static function remove( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		$attachment = Documentate_Document_Data::attachment( $post_id );
		delete_post_meta( $post_id, Documentate_Document_Data::META_ATTACHMENTS );

		if ( null === $attachment ) {
			return false;
		}

		Documentate_Activity::record_event( $post_id, 'quitó el fichero «' . self::name( $attachment->ID ) . '»' );

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
	public static function name( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$name = (string) get_post_meta( $attachment_id, self::META_NAME, true );
		if ( '' !== $name ) {
			return $name;
		}

		$path = (string) get_attached_file( $attachment_id );

		return '' === $path ? '' : basename( $path );
	}

	/**
	 * Size of an attachment, ready to print ("1 MB").
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Empty when the file is gone.
	 */
	public static function readable_size( $attachment_id ) {
		$path = get_attached_file( (int) $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		$bytes = (int) filesize( $path );

		return $bytes > 0 ? (string) size_format( $bytes ) : '';
	}
}
