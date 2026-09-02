<?php
/**
 * Fields by role.
 *
 * A template placeholder may declare `rol='gestion'` (alias `role`). Those
 * fields — the official data: resolution number, budget line, provider
 * totals — are completed by gestión documental, never by the área that
 * drafts the document. Everything else is `area`. This class answers who may
 * see a field, groups schema rows by rol and tells whether a document type
 * has any gestión field at all (which puts it through the gestión step).
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

use Documentate\DocType\SchemaStorage;

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Campos_Rol
 *
 * Static helpers over the "rol" attribute of schema rows.
 */
class Documentate_Campos_Rol {

	/**
	 * Rol of the fields the área fills in (default).
	 *
	 * @var string
	 */
	const ROL_AREA = 'area';

	/**
	 * Rol of the fields gestión documental fills in.
	 *
	 * @var string
	 */
	const ROL_GESTION = 'gestion';

	/**
	 * Rol of a field, from a legacy schema row or a raw placeholder record.
	 *
	 * Reads `rol` (alias `role`); anything but "gestion" counts as área.
	 *
	 * @param array $campo Schema row, raw schema entry or placeholder parameters.
	 * @return string ROL_AREA or ROL_GESTION.
	 */
	public static function rol_del_campo( array $campo ) {
		$raw = '';
		foreach ( array( 'rol', 'role' ) as $key ) {
			if ( isset( $campo[ $key ] ) && is_string( $campo[ $key ] ) ) {
				$raw = $campo[ $key ];
				break;
			}
		}

		return self::ROL_GESTION === strtolower( trim( remove_accents( $raw ) ) ) ? self::ROL_GESTION : self::ROL_AREA;
	}

	/**
	 * Whether the user may see (and therefore post) a field.
	 *
	 * Gestión and administración see every field; the área only sees its own.
	 *
	 * @param array    $row     Schema row.
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function puede_ver( array $row, $user_id = null ) {
		if ( self::ROL_GESTION !== self::rol_del_campo( $row ) ) {
			return true;
		}

		return Documentate_Roles::es_gestion( $user_id );
	}

	/**
	 * Split schema rows by rol, keeping their order inside each group.
	 *
	 * @param array $schema_rows Legacy schema rows.
	 * @return array{area:array,gestion:array}
	 */
	public static function agrupar( array $schema_rows ) {
		$grupos = array(
			self::ROL_AREA => array(),
			self::ROL_GESTION => array(),
		);

		foreach ( $schema_rows as $row ) {
			if ( is_array( $row ) ) {
				$grupos[ self::rol_del_campo( $row ) ][] = $row;
			}
		}

		return $grupos;
	}

	/**
	 * Whether a document type goes through gestión documental.
	 *
	 * True when the type is flagged (term meta) or when its stored schema
	 * declares any field or block with rol = gestion.
	 *
	 * @param int $term_id Document type term ID.
	 * @return bool
	 */
	public static function tipo_con_gestion( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return false;
		}

		if ( '1' === (string) get_term_meta( $term_id, Documentate_Documento::TERM_META_CON_GESTION, true ) ) {
			return true;
		}

		$schema = ( new SchemaStorage() )->get_schema( $term_id );
		if ( ! is_array( $schema ) ) {
			return false;
		}

		$entries = array_merge(
			isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array(),
			isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array()
		);

		return self::alguno_de_gestion( $entries );
	}

	/**
	 * Whether any entry (or nested field) declares rol = gestion.
	 *
	 * @param array $entries Raw schema entries.
	 * @return bool
	 */
	private static function alguno_de_gestion( array $entries ) {
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( self::ROL_GESTION === self::rol_del_campo( $entry ) ) {
				return true;
			}

			if ( isset( $entry['fields'] ) && is_array( $entry['fields'] ) && self::alguno_de_gestion( $entry['fields'] ) ) {
				return true;
			}
		}

		return false;
	}
}
