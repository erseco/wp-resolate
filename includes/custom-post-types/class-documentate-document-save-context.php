<?php
/**
 * What a document save starts from.
 *
 * Composing post_content needs three answers before it can begin: which
 * document type applies, what the request carries against what the database
 * holds, and which of those values the current user is not allowed to write.
 * They are read-only lookups with no state, so they live apart from
 * Documentate_Document_Content_Writer, which does the composing.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Meta_Handler;

/**
 * Reads the document type, the stored values and the write guard of a save.
 */
class Documentate_Document_Save_Context {

	/**
	 * Document type the content must be composed against.
	 *
	 * The type assigned to the document wins, exactly like in
	 * Documentate_Document_Meta_Saver::save_doc_type_selection(): a request
	 * naming another type must not decide which schema — and therefore which
	 * rol — applies to the values it carries. Only a document without a type
	 * yet falls back to the posted one.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function term_id( $post_id ) {
		if ( $post_id > 0 ) {
			$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				return intval( $assigned[0] );
			}
		}

		return self::posted_term_id();
	}
	/**
	 * Document type named by the request, if any.
	 *
	 * @return int
	 */
	public static function posted_term_id() {
		if ( ! isset( $_POST['documentate_doc_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return 0;
		}

		return max( 0, intval( wp_unslash( $_POST['documentate_doc_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	/**
	 * The values a save starts from, keeping the two sources apart.
	 *
	 * "request" is what the save carries (core copies $_POST['content'] into
	 * post_content, and kses preserves the HTML comments the fields live in),
	 * so it is only trustworthy for fields the current user may write.
	 * "stored" is what the database holds and is used for every other field.
	 *
	 * @param array<string,mixed> $postarr Raw post data.
	 * @param int                 $post_id Post ID.
	 * @return array{request:array<string,array{value:string,type:string}>,stored:array<string,array{value:string,type:string}>}
	 */
	public static function existing_values( $postarr, $post_id ) {
		$stored = self::stored_values( $post_id );

		$request = array();
		if ( isset( $postarr['post_content'] ) && '' !== $postarr['post_content'] ) {
			$request = Documents_Meta_Handler::parse_structured_content( (string) $postarr['post_content'] );
		}

		return array(
			'request' => empty( $request ) ? $stored : $request,
			'stored' => $stored,
		);
	}
	/**
	 * Structured values as the database holds them, ignoring the request.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array{value:string,type:string}>
	 */
	private static function stored_values( $post_id ) {
		if ( $post_id <= 0 ) {
			return array();
		}

		$current_content = get_post_field( 'post_content', $post_id, 'edit' );
		if ( is_string( $current_content ) && '' !== $current_content ) {
			$stored = Documents_Meta_Handler::parse_structured_content( $current_content );
			if ( ! empty( $stored ) ) {
				return $stored;
			}
		}

		return Documents_Meta_Handler::get_structured_field_values( $post_id );
	}
	/**
	 * Slugs of the request the current user is not allowed to write.
	 *
	 * Built from the schema the content is composed against and from the
	 * schema of any other type the request names, so a gestión field cannot
	 * reach post_content through the unknown-field paths by pointing the
	 * request at a different document type.
	 *
	 * @param array $schema Schema rows the content is composed against.
	 * @return array<string,bool>
	 */
	public static function hidden_slugs( $schema ) {
		$rows = (array) $schema;

		$posted_term = self::posted_term_id();
		if ( $posted_term > 0 ) {
			$rows = array_merge( $rows, (array) Documents_Meta_Handler::get_term_schema( $posted_term ) );
		}

		$hidden = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}

			$slug = sanitize_key( $row['slug'] );
			if ( '' !== $slug && ! Documentate_Campos_Rol::puede_ver( $row ) ) {
				$hidden[ $slug ] = true;
			}
		}

		return $hidden;
	}
	/**
	 * Slugs that belong to gestión documental in any document type of the site.
	 *
	 * The hidden_slugs() list answers for the schema a save is composed
	 * against, which is all the content writer needs. The unknown-field path
	 * of the meta saver has no schema to lean on: a value it stores was
	 * declared by some other type, so the question there is whether the slug
	 * is gestión-owned anywhere, not in this document.
	 *
	 * @return array<string,bool> Empty for gestión documental and administración.
	 */
	public static function gestion_slugs() {
		if ( Documentate_Roles::es_gestion() ) {
			return array();
		}

		$term_ids = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
				'fields' => 'ids',
			)
		);
		if ( is_wp_error( $term_ids ) ) {
			return array();
		}

		$slugs = array();
		foreach ( (array) $term_ids as $term_id ) {
			foreach ( (array) Documents_Meta_Handler::get_term_schema( (int) $term_id ) as $row ) {
				if ( ! is_array( $row ) || empty( $row['slug'] ) || Documentate_Campos_Rol::puede_ver( $row ) ) {
					continue;
				}

				$slug = sanitize_key( $row['slug'] );
				if ( '' !== $slug ) {
					$slugs[ $slug ] = true;
				}
			}
		}

		return $slugs;
	}
	/**
	 * Entry for a stored value, keeping the type it was stored with.
	 *
	 * @param array $info Stored entry with value/type keys.
	 * @return array{type:string,value:string}
	 */
	public static function entry( $info ) {
		$type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : 'rich';
		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'rich';
		}

		return array(
			'type' => $type,
			'value' => isset( $info['value'] ) ? (string) $info['value'] : '',
		);
	}
	/**
	 * Stored repeater row matching a submitted row index.
	 *
	 * @param array      $stored_items Rows already stored.
	 * @param int|string $key          Index of the submitted row.
	 * @return array<string,mixed>
	 */
	public static function item_at( array $stored_items, $key ) {
		if ( ! is_int( $key ) && ! ctype_digit( (string) $key ) ) {
			return array();
		}

		$index = (int) $key;

		return isset( $stored_items[ $index ] ) && is_array( $stored_items[ $index ] ) ? $stored_items[ $index ] : array();
	}
	/**
	 * Value kept for a repeater column the request may not write.
	 *
	 * @param mixed  $previous Stored value, or null when the row had none.
	 * @param string $type     Column type.
	 * @return array|string
	 */
	public static function column( $previous, $type ) {
		if ( 'array' === $type ) {
			return is_array( $previous ) ? $previous : array();
		}

		return is_scalar( $previous ) ? (string) $previous : '';
	}
}
