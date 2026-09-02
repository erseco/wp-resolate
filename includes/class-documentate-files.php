<?php
/**
 * The one place that turns an attachment ID into a path worth touching.
 *
 * Every filesystem read the plugin does on behalf of a request starts with an
 * ID that travelled in that request. The ID is an integer and the path comes
 * from the database, so there is no traversal to speak of — but a database is
 * not a promise: a row edited by hand, a migration from another site or a
 * plugin that writes `_wp_attached_file` can point anywhere the web server can
 * read. Resolving the path here, and refusing anything outside the uploads
 * directory, keeps that promise in one readable function.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Files
 */
class Documentate_Files {

	/**
	 * Path of an attachment, when it is a real file inside the uploads folder.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Absolute path, or an empty string when there is nothing
	 *                safe to read.
	 */
	public static function attachment_path( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return '';
		}

		return self::path_inside_uploads( (string) get_attached_file( $attachment_id ) );
	}

	/**
	 * The same path, resolved and checked against the uploads directory.
	 *
	 * @param string $path Candidate path.
	 * @return string Absolute path, or an empty string.
	 */
	public static function path_inside_uploads( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! file_exists( $path ) || is_dir( $path ) ) {
			return '';
		}

		$real = realpath( $path );
		$uploads = wp_get_upload_dir();
		$base = isset( $uploads['basedir'] ) ? realpath( (string) $uploads['basedir'] ) : false;

		if ( false === $real || false === $base ) {
			return '';
		}

		// A file of the uploads folder, not merely a path that starts like one:
		// the separator keeps "/uploads-otra-cosa" out.
		return 0 === strpos( $real, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) ? $real : '';
	}

	/**
	 * A file name safe to put in a Content-Disposition header.
	 *
	 * Quotes and line breaks would end the header early, so the name that
	 * reaches the browser is the same one WordPress would have given the file
	 * on disk.
	 *
	 * @param string $name File name as a person wrote it.
	 * @return string Empty string when nothing usable is left.
	 */
	public static function header_file_name( $name ) {
		return sanitize_file_name( (string) $name );
	}
}
