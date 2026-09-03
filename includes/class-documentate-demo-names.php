<?php
/**
 * Short internal names for the demo documents that predate the application.
 *
 * The one-per-type demo set was seeded when a document had only its official
 * title, and those titles are a paragraph long: a list of them reads as a wall
 * of truncated sentences, which is the very thing the internal name exists to
 * avoid. This gives each of them a name, and leaves the title alone — that is
 * what the generated document prints.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Demo_Names
 */
class Documentate_Demo_Names {

	/**
	 * Give the one-per-type demo documents a short name too.
	 *
	 * They were seeded before the application existed, so they carry only the
	 * official title — and a list of documents whose names are all truncated
	 * titles is exactly what the internal name exists to avoid. The title is
	 * left alone: it is what the generated document prints.
	 *
	 * @return void
	 */
	public static function apply_to_older_demo_documents() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off pass during demo seeding; WP_Query is filtered by the access protection when no user is logged in.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} marca ON marca.post_id = p.ID AND marca.meta_key IN ( %s, %s )
				LEFT JOIN {$wpdb->postmeta} nombre ON nombre.post_id = p.ID AND nombre.meta_key = %s
				WHERE p.post_type = %s AND p.post_status <> %s AND nombre.meta_id IS NULL",
				'_documentate_demo_key',
				'_documentate_demo_type_id',
				Documentate_Document_Data::META_NAME,
				'documentate_document',
				'trash'
			)
		);

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			Documentate_Document_Data::save_internal_name( $post->ID, self::short_name_from_title( $post->post_title ) );
		}
	}

	/**
	 * A short internal name taken from an official title.
	 *
	 * Cuts on a word boundary so the result reads as a name and not as a
	 * sentence that ran out of room.
	 *
	 * @param string $title Official title.
	 * @return string
	 */
	public static function short_name_from_title( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$title = preg_replace( '/^(Ejemplo|Demo)\s*:\s*/ui', '', $title );
		$title = trim( (string) $title );

		if ( '' === $title ) {
			return 'Documento de ejemplo';
		}

		if ( mb_strlen( $title ) <= 60 ) {
			return $title;
		}

		$corte = mb_substr( $title, 0, 60 );
		$espacio = mb_strrpos( $corte, ' ' );

		return rtrim( false === $espacio || $espacio < 30 ? $corte : mb_substr( $corte, 0, $espacio ), ' ,.;:-' );
	}
}
