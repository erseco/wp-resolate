<?php
/**
 * Read helpers over one document of the workflow.
 *
 * Wraps the post meta and taxonomy lookups that the lists, the detail view,
 * the notifier and the transitions share: internal name, type prefix,
 * "devuelto" mark, whether the type goes through gestión documental, the
 * attached file, the área and the person behind the document.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

use Documentate\Documents\Documents_Meta_Handler;

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Document_Data
 *
 * Static accessors; every method accepts a post ID or a WP_Post.
 */
class Documentate_Document_Data {

	/**
	 * Post meta: short internal name (stored without the type prefix).
	 *
	 * @var string
	 */
	const META_NAME = '_documentate_nombre_interno';

	/**
	 * Post meta: internal notes, visible to gestión and administración only.
	 *
	 * @var string
	 */
	const META_NOTES = '_documentate_anotaciones';

	/**
	 * Post meta: JSON of the last return (who, when, why, from, to).
	 *
	 * @var string
	 */
	const META_RETURNED = '_documentate_devuelto';

	/**
	 * Post meta: attachment IDs (owned by the attachments meta box).
	 *
	 * @var string
	 */
	const META_ATTACHMENTS = '_documentate_attachments';

	/**
	 * Term meta of the type: prefix shown before the internal name.
	 *
	 * @var string
	 */
	const TERM_META_PREFIX = 'documentate_type_prefijo';

	/**
	 * Term meta of the type: '1' when the type goes through gestión.
	 *
	 * @var string
	 */
	const TERM_META_HAS_MANAGEMENT = 'documentate_type_con_gestion';

	/**
	 * Maximum length of the internal name.
	 *
	 * @var int
	 */
	const NAME_MAX = 80;

	/**
	 * Resolve the document.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Post|null Null when it is not a document.
	 */
	public static function post( $post ) {
		// get_post() falls back to the global post for an empty ID: never resolve one by accident.
		if ( empty( $post ) ) {
			return null;
		}

		$post = get_post( $post );

		return $post instanceof WP_Post && 'documentate_document' === $post->post_type ? $post : null;
	}

	/**
	 * Document type term.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Term|null
	 */
	public static function type( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return null;
		}

		$terms = wp_get_post_terms( $post->ID, 'documentate_doc_type' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$locked = absint( get_post_meta( $post->ID, 'documentate_locked_doc_type', true ) );
			$term = $locked > 0 ? get_term( $locked, 'documentate_doc_type' ) : null;

			return $term instanceof WP_Term ? $term : null;
		}

		return $terms[0];
	}

	/**
	 * Prefix of the document type (uppercase, at most 6 characters).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string Empty when the type has no prefix.
	 */
	public static function type_prefix( $post ) {
		$type = self::type( $post );

		return $type ? self::prefix_for_type( $type->term_id ) : '';
	}

	/**
	 * Prefix of a document type ("RES"), upper-cased and capped at 6 characters.
	 *
	 * @param int $term_id Document type term ID.
	 * @return string
	 */
	public static function prefix_for_type( $term_id ) {
		$prefix = (string) get_term_meta( (int) $term_id, self::TERM_META_PREFIX, true );

		return mb_strtoupper( mb_substr( trim( $prefix ), 0, 6 ) );
	}

	/**
	 * Internal name as stored (no prefix).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function internal_name( $post ) {
		$post = self::post( $post );

		return $post ? (string) get_post_meta( $post->ID, self::META_NAME, true ) : '';
	}

	/**
	 * Store the internal name, trimmed to NAME_MAX characters.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $name    Raw name (already unslashed).
	 * @return string The stored value.
	 */
	public static function save_internal_name( $post_id, $name ) {
		$name = mb_substr( sanitize_text_field( (string) $name ), 0, self::NAME_MAX );

		if ( '' === $name ) {
			delete_post_meta( $post_id, self::META_NAME );
		} else {
			// Slashed: update_post_meta() unslashes, which would eat backslashes.
			update_post_meta( $post_id, self::META_NAME, wp_slash( $name ) );
		}

		return $name;
	}

	/**
	 * Internal notes (gestión / administración only).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function notes( $post ) {
		$post = self::post( $post );

		return $post ? (string) get_post_meta( $post->ID, self::META_NOTES, true ) : '';
	}

	/**
	 * Store the internal notes.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $text    Raw notes (already unslashed).
	 * @return string The stored value.
	 */
	public static function save_notes( $post_id, $text ) {
		$text = sanitize_textarea_field( (string) $text );

		if ( '' === $text ) {
			delete_post_meta( $post_id, self::META_NOTES );
		} else {
			// Slashed: update_post_meta() unslashes, which would eat backslashes.
			update_post_meta( $post_id, self::META_NOTES, wp_slash( $text ) );
		}

		return $text;
	}

	/**
	 * Short name shown in lists: "PREFIJO · nombre interno".
	 *
	 * Falls back to the official title, truncated to 60 characters, when the
	 * document has no internal name.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function short_name( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return '';
		}

		$name = self::internal_name( $post );
		if ( '' === $name ) {
			$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

			return mb_strlen( $title ) > 60 ? mb_substr( $title, 0, 59 ) . '…' : $title;
		}

		$prefix = self::type_prefix( $post );

		return '' === $prefix ? $name : $prefix . ' · ' . $name;
	}

	/**
	 * The "devuelto" mark, when the document was returned.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return array{por:int,fecha:string,motivo:string,desde:string,a:string}|null
	 */
	public static function returned( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return null;
		}

		$raw = get_post_meta( $post->ID, self::META_RETURNED, true );
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}

		return array(
			'por' => isset( $data['por'] ) ? (int) $data['por'] : 0,
			'fecha' => isset( $data['fecha'] ) ? (string) $data['fecha'] : '',
			'motivo' => isset( $data['motivo'] ) ? (string) $data['motivo'] : '',
			'desde' => isset( $data['desde'] ) ? (string) $data['desde'] : '',
			'a' => isset( $data['a'] ) ? (string) $data['a'] : '',
		);
	}

	/**
	 * Write the "devuelto" mark.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $reason  Reason given by whoever returned it.
	 * @param string $from    Who returns it: "gestion" or "administracion".
	 * @param string $to      Who receives it: "area" or "gestion".
	 * @param int    $by      User ID of whoever returns it (defaults to the current user).
	 * @return array The stored mark.
	 */
	public static function mark_returned( $post_id, $reason, $from, $to, $by = 0 ) {
		$data = array(
			'por' => $by > 0 ? (int) $by : get_current_user_id(),
			'fecha' => current_time( 'mysql' ),
			'motivo' => sanitize_textarea_field( (string) $reason ),
			'desde' => 'administracion' === $from ? 'administracion' : 'gestion',
			'a' => 'gestion' === $to ? 'gestion' : 'area',
		);

		// Slashed: update_post_meta() unslashes, which would eat the JSON escapes (\u00fa, \n).
		update_post_meta( $post_id, self::META_RETURNED, wp_slash( wp_json_encode( $data ) ) );

		return $data;
	}

	/**
	 * Remove the "devuelto" mark (every forward transition clears it).
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	public static function clear_returned( $post_id ) {
		delete_post_meta( $post_id, self::META_RETURNED );
	}

	/**
	 * Whether the document type goes through gestión documental.
	 *
	 * True when the type is flagged, or when its template has any field with
	 * rol = gestion (resolved by Documentate_Field_Roles).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return bool
	 */
	public static function has_management( $post ) {
		$type = self::type( $post );
		if ( ! $type ) {
			return false;
		}

		return self::type_has_management( $type->term_id );
	}

	/**
	 * Whether the document being saved goes through gestión documental.
	 *
	 * Reads the document's type; when it has none yet (first save from
	 * wp-admin, creation through the API) the type posted with the save
	 * decides, so a document cannot skip gestión by being born in the
	 * pipeline.
	 *
	 * @param int   $post_id Document ID, or 0 when it does not exist yet.
	 * @param array $postarr Post data being saved (tax_input may carry the type).
	 * @return bool
	 */
	public static function has_management_on_save( $post_id, array $postarr ) {
		if ( $post_id > 0 && self::type( $post_id ) ) {
			return self::has_management( $post_id );
		}

		$posted_types = isset( $postarr['tax_input']['documentate_doc_type'] ) ? (array) $postarr['tax_input']['documentate_doc_type'] : array();
		foreach ( $posted_types as $posted_type ) {
			$posted_type = is_numeric( $posted_type ) ? (int) $posted_type : $posted_type;
			$exists = is_int( $posted_type ) || is_string( $posted_type ) ? term_exists( $posted_type, 'documentate_doc_type' ) : null;
			if ( is_array( $exists ) ) {
				return self::type_has_management( (int) $exists['term_id'] );
			}
		}

		return false;
	}

	/**
	 * Whether a document type goes through gestión documental.
	 *
	 * Resolved by Documentate_Field_Roles: the type is flagged, or its schema
	 * has any field with rol = gestion.
	 *
	 * @param int $term_id Document type term ID.
	 * @return bool
	 */
	public static function type_has_management( $term_id ) {
		return Documentate_Field_Roles::type_has_management( (int) $term_id );
	}

	/**
	 * The attached file (first attachment ID), if any.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Post|null
	 */
	public static function attachment( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return null;
		}

		$ids = get_post_meta( $post->ID, self::META_ATTACHMENTS, true );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return null;
		}

		$attachment = get_post( absint( reset( $ids ) ) );

		return $attachment instanceof WP_Post && 'attachment' === $attachment->post_type ? $attachment : null;
	}

	/**
	 * Name of the área (first category) the document belongs to.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function area( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return '';
		}

		$terms = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'names' ) );

		return ! is_wp_error( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
	}

	/**
	 * Display name of the person who created the document.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function person( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return '';
		}

		$author = get_userdata( (int) $post->post_author );

		return $author ? (string) $author->display_name : '';
	}

	/**
	 * Value of the "curso" field, when the type's schema has one.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string Empty when the schema has no curso field or it is unset.
	 */
	public static function course( $post ) {
		$post = self::post( $post );
		if ( ! $post || ! class_exists( Documents_Meta_Handler::class ) ) {
			return '';
		}

		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		foreach ( (array) $schema as $field ) {
			if ( isset( $field['slug'] ) && 'curso' === $field['slug'] ) {
				return sanitize_text_field( (string) get_post_meta( $post->ID, 'documentate_field_curso', true ) );
			}
		}

		return '';
	}
}
