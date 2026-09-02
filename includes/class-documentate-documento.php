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
 * Class Documentate_Documento
 *
 * Static accessors; every method accepts a post ID or a WP_Post.
 */
class Documentate_Documento {

	/**
	 * Post meta: short internal name (stored without the type prefix).
	 *
	 * @var string
	 */
	const META_NOMBRE = '_documentate_nombre_interno';

	/**
	 * Post meta: internal notes, visible to gestión and administración only.
	 *
	 * @var string
	 */
	const META_ANOTACIONES = '_documentate_anotaciones';

	/**
	 * Post meta: JSON of the last return (who, when, why, from, to).
	 *
	 * @var string
	 */
	const META_DEVUELTO = '_documentate_devuelto';

	/**
	 * Post meta: attachment IDs (owned by the attachments meta box).
	 *
	 * @var string
	 */
	const META_ADJUNTOS = '_documentate_attachments';

	/**
	 * Term meta of the type: prefix shown before the internal name.
	 *
	 * @var string
	 */
	const TERM_META_PREFIJO = 'documentate_type_prefijo';

	/**
	 * Term meta of the type: '1' when the type goes through gestión.
	 *
	 * @var string
	 */
	const TERM_META_CON_GESTION = 'documentate_type_con_gestion';

	/**
	 * Maximum length of the internal name.
	 *
	 * @var int
	 */
	const NOMBRE_MAX = 80;

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
	public static function tipo( $post ) {
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
	public static function prefijo_tipo( $post ) {
		$tipo = self::tipo( $post );

		return $tipo ? self::prefijo_de_tipo( $tipo->term_id ) : '';
	}

	/**
	 * Prefix of a document type ("RES"), upper-cased and capped at 6 characters.
	 *
	 * @param int $term_id Document type term ID.
	 * @return string
	 */
	public static function prefijo_de_tipo( $term_id ) {
		$prefijo = (string) get_term_meta( (int) $term_id, self::TERM_META_PREFIJO, true );

		return mb_strtoupper( mb_substr( trim( $prefijo ), 0, 6 ) );
	}

	/**
	 * Internal name as stored (no prefix).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function nombre_interno( $post ) {
		$post = self::post( $post );

		return $post ? (string) get_post_meta( $post->ID, self::META_NOMBRE, true ) : '';
	}

	/**
	 * Store the internal name, trimmed to NOMBRE_MAX characters.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $nombre  Raw name (already unslashed).
	 * @return string The stored value.
	 */
	public static function guardar_nombre_interno( $post_id, $nombre ) {
		$nombre = mb_substr( sanitize_text_field( (string) $nombre ), 0, self::NOMBRE_MAX );

		if ( '' === $nombre ) {
			delete_post_meta( $post_id, self::META_NOMBRE );
		} else {
			// Slashed: update_post_meta() unslashes, which would eat backslashes.
			update_post_meta( $post_id, self::META_NOMBRE, wp_slash( $nombre ) );
		}

		return $nombre;
	}

	/**
	 * Internal notes (gestión / administración only).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string
	 */
	public static function anotaciones( $post ) {
		$post = self::post( $post );

		return $post ? (string) get_post_meta( $post->ID, self::META_ANOTACIONES, true ) : '';
	}

	/**
	 * Store the internal notes.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $texto   Raw notes (already unslashed).
	 * @return string The stored value.
	 */
	public static function guardar_anotaciones( $post_id, $texto ) {
		$texto = sanitize_textarea_field( (string) $texto );

		if ( '' === $texto ) {
			delete_post_meta( $post_id, self::META_ANOTACIONES );
		} else {
			// Slashed: update_post_meta() unslashes, which would eat backslashes.
			update_post_meta( $post_id, self::META_ANOTACIONES, wp_slash( $texto ) );
		}

		return $texto;
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
	public static function nombre_corto( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return '';
		}

		$nombre = self::nombre_interno( $post );
		if ( '' === $nombre ) {
			$titulo = trim( wp_strip_all_tags( (string) $post->post_title ) );

			return mb_strlen( $titulo ) > 60 ? mb_substr( $titulo, 0, 59 ) . '…' : $titulo;
		}

		$prefijo = self::prefijo_tipo( $post );

		return '' === $prefijo ? $nombre : $prefijo . ' · ' . $nombre;
	}

	/**
	 * The "devuelto" mark, when the document was returned.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return array{por:int,fecha:string,motivo:string,desde:string,a:string}|null
	 */
	public static function devuelto( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return null;
		}

		$raw = get_post_meta( $post->ID, self::META_DEVUELTO, true );
		$datos = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $datos ) ) {
			return null;
		}

		return array(
			'por' => isset( $datos['por'] ) ? (int) $datos['por'] : 0,
			'fecha' => isset( $datos['fecha'] ) ? (string) $datos['fecha'] : '',
			'motivo' => isset( $datos['motivo'] ) ? (string) $datos['motivo'] : '',
			'desde' => isset( $datos['desde'] ) ? (string) $datos['desde'] : '',
			'a' => isset( $datos['a'] ) ? (string) $datos['a'] : '',
		);
	}

	/**
	 * Write the "devuelto" mark.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $motivo  Reason given by whoever returned it.
	 * @param string $desde   Who returns it: "gestion" or "administracion".
	 * @param string $a       Who receives it: "area" or "gestion".
	 * @param int    $por     User ID of whoever returns it (defaults to the current user).
	 * @return array The stored mark.
	 */
	public static function marcar_devuelto( $post_id, $motivo, $desde, $a, $por = 0 ) {
		$datos = array(
			'por' => $por > 0 ? (int) $por : get_current_user_id(),
			'fecha' => current_time( 'mysql' ),
			'motivo' => sanitize_textarea_field( (string) $motivo ),
			'desde' => 'administracion' === $desde ? 'administracion' : 'gestion',
			'a' => 'gestion' === $a ? 'gestion' : 'area',
		);

		// Slashed: update_post_meta() unslashes, which would eat the JSON escapes (\u00fa, \n).
		update_post_meta( $post_id, self::META_DEVUELTO, wp_slash( wp_json_encode( $datos ) ) );

		return $datos;
	}

	/**
	 * Remove the "devuelto" mark (every forward transition clears it).
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	public static function limpiar_devuelto( $post_id ) {
		delete_post_meta( $post_id, self::META_DEVUELTO );
	}

	/**
	 * Whether the document type goes through gestión documental.
	 *
	 * True when the type is flagged, or when its template has any field with
	 * rol = gestion (resolved by Documentate_Campos_Rol).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return bool
	 */
	public static function con_gestion( $post ) {
		$tipo = self::tipo( $post );
		if ( ! $tipo ) {
			return false;
		}

		return self::tipo_con_gestion( $tipo->term_id );
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
	public static function con_gestion_al_guardar( $post_id, array $postarr ) {
		if ( $post_id > 0 && self::tipo( $post_id ) ) {
			return self::con_gestion( $post_id );
		}

		$enviados = isset( $postarr['tax_input']['documentate_doc_type'] ) ? (array) $postarr['tax_input']['documentate_doc_type'] : array();
		foreach ( $enviados as $enviado ) {
			$enviado = is_numeric( $enviado ) ? (int) $enviado : $enviado;
			$existe = is_int( $enviado ) || is_string( $enviado ) ? term_exists( $enviado, 'documentate_doc_type' ) : null;
			if ( is_array( $existe ) ) {
				return self::tipo_con_gestion( (int) $existe['term_id'] );
			}
		}

		return false;
	}

	/**
	 * Whether a document type goes through gestión documental.
	 *
	 * Resolved by Documentate_Campos_Rol: the type is flagged, or its schema
	 * has any field with rol = gestion.
	 *
	 * @param int $term_id Document type term ID.
	 * @return bool
	 */
	public static function tipo_con_gestion( $term_id ) {
		return Documentate_Campos_Rol::tipo_con_gestion( (int) $term_id );
	}

	/**
	 * The attached file (first attachment ID), if any.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return WP_Post|null
	 */
	public static function adjunto( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return null;
		}

		$ids = get_post_meta( $post->ID, self::META_ADJUNTOS, true );
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return null;
		}

		$adjunto = get_post( absint( reset( $ids ) ) );

		return $adjunto instanceof WP_Post && 'attachment' === $adjunto->post_type ? $adjunto : null;
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
	public static function persona( $post ) {
		$post = self::post( $post );
		if ( ! $post ) {
			return '';
		}

		$autor = get_userdata( (int) $post->post_author );

		return $autor ? (string) $autor->display_name : '';
	}

	/**
	 * Value of the "curso" field, when the type's schema has one.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string Empty when the schema has no curso field or it is unset.
	 */
	public static function curso( $post ) {
		$post = self::post( $post );
		if ( ! $post || ! class_exists( Documents_Meta_Handler::class ) ) {
			return '';
		}

		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		foreach ( (array) $schema as $campo ) {
			if ( isset( $campo['slug'] ) && 'curso' === $campo['slug'] ) {
				return sanitize_text_field( (string) get_post_meta( $post->ID, 'documentate_field_curso', true ) );
			}
		}

		return '';
	}
}
