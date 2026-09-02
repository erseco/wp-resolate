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
 * Class Documentate_Field_Roles
 *
 * Static helpers over the "rol" attribute of schema rows.
 */
class Documentate_Field_Roles {

	/**
	 * Rol of the fields the área fills in (default).
	 *
	 * @var string
	 */
	const ROLE_AREA = 'area';

	/**
	 * Rol of the fields gestión documental fills in.
	 *
	 * @var string
	 */
	const ROLE_MANAGEMENT = 'gestion';

	/**
	 * Whether the stored schema of a type declares a gestión field, by term ID.
	 *
	 * Walking the whole schema on every call is wasteful: the answer is asked
	 * for once per row of every list. It only changes when the schema is
	 * stored again, which calls forget_type().
	 *
	 * @var array<int,bool>
	 */
	private static $management_schema_cache = array();

	/**
	 * Rol of a field, from a legacy schema row or a raw placeholder record.
	 *
	 * Reads `rol` (alias `role`); anything but "gestion" counts as área.
	 *
	 * @param array $field Schema row, raw schema entry or placeholder parameters.
	 * @return string ROLE_AREA or ROLE_MANAGEMENT.
	 */
	public static function field_role( array $field ) {
		$raw = '';
		foreach ( array( 'rol', 'role' ) as $key ) {
			if ( isset( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
				$raw = $field[ $key ];
				break;
			}
		}

		return self::ROLE_MANAGEMENT === strtolower( trim( remove_accents( $raw ) ) ) ? self::ROLE_MANAGEMENT : self::ROLE_AREA;
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
	public static function can_view( array $row, $user_id = null ) {
		if ( self::ROLE_MANAGEMENT !== self::field_role( $row ) ) {
			return true;
		}

		return Documentate_Roles::is_management( $user_id );
	}

	/**
	 * Drop the columns of a repeater item schema the current user cannot see.
	 *
	 * Recurses into nested repeaters, so a gestión column inside an área
	 * block is neither drawn nor accepted from the request.
	 *
	 * @param array $schema Normalized item schema.
	 * @return array<string,array>
	 */
	public static function filter_item_schema( array $schema ) {
		$visible = array();

		foreach ( $schema as $key => $settings ) {
			if ( ! is_array( $settings ) || ! self::can_view( $settings ) ) {
				continue;
			}

			if ( isset( $settings['item_schema'] ) && is_array( $settings['item_schema'] ) ) {
				$settings['item_schema'] = self::filter_item_schema( $settings['item_schema'] );
			}

			$visible[ $key ] = $settings;
		}

		return $visible;
	}

	/**
	 * Split schema rows by rol, keeping their order inside each group.
	 *
	 * @param array $schema_rows Legacy schema rows.
	 * @return array{area:array,gestion:array}
	 */
	public static function group_by_role( array $schema_rows ) {
		$groups = array(
			self::ROLE_AREA => array(),
			self::ROLE_MANAGEMENT => array(),
		);

		foreach ( $schema_rows as $row ) {
			if ( is_array( $row ) ) {
				$groups[ self::field_role( $row ) ][] = $row;
			}
		}

		return $groups;
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
	public static function type_has_management( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return false;
		}

		if ( '1' === (string) get_term_meta( $term_id, Documentate_Document_Data::TERM_META_HAS_MANAGEMENT, true ) ) {
			return true;
		}

		if ( ! isset( self::$management_schema_cache[ $term_id ] ) ) {
			self::$management_schema_cache[ $term_id ] = self::schema_declares_management( $term_id );
		}

		return self::$management_schema_cache[ $term_id ];
	}

	/**
	 * Forget the memoised answer of a type, or of every type.
	 *
	 * Called when a schema is stored or removed, which is the only thing that
	 * can change it within a request.
	 *
	 * @param int $term_id Document type term ID, or 0 for all of them.
	 * @return void
	 */
	public static function forget_type( $term_id = 0 ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			self::$management_schema_cache = array();
			return;
		}

		unset( self::$management_schema_cache[ $term_id ] );
	}

	/**
	 * Whether the stored schema of a type declares any gestión field.
	 *
	 * @param int $term_id Document type term ID.
	 * @return bool
	 */
	private static function schema_declares_management( $term_id ) {
		$schema = ( new SchemaStorage() )->get_schema( $term_id );
		if ( ! is_array( $schema ) ) {
			return false;
		}

		$entries = array_merge(
			isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array(),
			isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array()
		);

		return self::any_management( $entries );
	}

	/**
	 * Whether any entry (or nested field) declares rol = gestion.
	 *
	 * @param array $entries Raw schema entries.
	 * @return bool
	 */
	private static function any_management( array $entries ) {
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( self::ROLE_MANAGEMENT === self::field_role( $entry ) ) {
				return true;
			}

			if ( isset( $entry['fields'] ) && is_array( $entry['fields'] ) && self::any_management( $entry['fields'] ) ) {
				return true;
			}
		}

		return false;
	}
}
