<?php
/**
 * Document metadata accessor helper.
 *
 * @package Resolate
 */

namespace Resolate\Document\Meta;

/**
 * Provides an accessor for document metadata stored in post meta.
 */
class Document_Meta {

	/**
	 * Retrieve metadata for a document.
	 *
	 * @param int $post_id Document post ID.
	 * @return array<string,string> Associative array of metadata values.
	 */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );

		if ( $post_id <= 0 ) {
			return array(
				'title'    => '',
				'subject'  => '',
				'author'   => '',
				'keywords' => '',
			);
		}

		return array(
			'title'    => get_the_title( $post_id ),
			'subject'  => (string) get_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, true ),
			'author'   => (string) get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ),
			'keywords' => (string) get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true ),
		);
	}
}
