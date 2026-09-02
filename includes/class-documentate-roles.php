<?php
/**
 * Role detection for the Documentate workflow.
 *
 * Three concepts share the WordPress roles: "área" (creates and sends
 * documents inside its own scope), "gestión documental" (completes the
 * official data of every document that entered the pipeline) and
 * "administración" (approves and publishes). Detection is capability based
 * so the site owner can move it to other roles later.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Roles
 *
 * Static helpers to tell the three workflow roles apart and to keep the
 * gestión capability granted to the roles that carry it.
 */
class Documentate_Roles {

	/**
	 * Capability that marks a user as "gestión documental".
	 *
	 * It only takes effect together with `edit_others_posts`: gestión must be
	 * able to open documents from every área, which WordPress gates with that
	 * primitive capability. Granting CAP_GESTION alone does nothing.
	 *
	 * @var string
	 */
	const CAP_GESTION = 'documentate_gestionar';

	/**
	 * Option holding the version of the capability set already applied.
	 *
	 * @var string
	 */
	const OPTION_VERSION = 'documentate_roles_version';

	/**
	 * Current version of the capability set.
	 *
	 * @var string
	 */
	const VERSION = '1';

	/**
	 * Roles that receive CAP_GESTION.
	 *
	 * @var string[]
	 */
	const ROLES_CON_GESTION = array( 'editor', 'administrator' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_caps' ), 1 );
	}

	/**
	 * Grant CAP_GESTION to the editor and administrator roles.
	 *
	 * Runs once per VERSION unless forced (activation forces it so a site
	 * that reactivates the plugin always ends up with the capability).
	 *
	 * @param bool $force Apply even when the stored version is current.
	 * @return void
	 */
	public static function ensure_caps( $force = false ) {
		if ( ! $force && self::VERSION === (string) get_option( self::OPTION_VERSION ) ) {
			return;
		}

		foreach ( self::ROLES_CON_GESTION as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP_GESTION );
			}
		}

		update_option( self::OPTION_VERSION, self::VERSION );
	}

	/**
	 * Remove CAP_GESTION from the roles and forget the version (uninstall).
	 *
	 * @return void
	 */
	public static function remove_caps() {
		foreach ( self::ROLES_CON_GESTION as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP_GESTION );
			}
		}

		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Whether the user can do something.
	 *
	 * Filters receive a user ID, so the check must not depend on the current
	 * user when an ID is given.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @param string   $cap     Capability.
	 * @return bool
	 */
	private static function puede( $user_id, $cap ) {
		if ( null === $user_id ) {
			return current_user_can( $cap );
		}

		return $user_id > 0 && user_can( (int) $user_id, $cap );
	}

	/**
	 * Whether the user is administración.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function es_administracion( $user_id = null ) {
		return self::puede( $user_id, 'manage_options' );
	}

	/**
	 * Whether the user is gestión documental.
	 *
	 * Administrators count as gestión for capability purposes: everything
	 * gestión may do, administración may do too.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function es_gestion( $user_id = null ) {
		if ( self::es_administracion( $user_id ) ) {
			return true;
		}

		return self::puede( $user_id, self::CAP_GESTION ) && self::puede( $user_id, 'edit_others_posts' );
	}

	/**
	 * Whether the user is área: can edit documents but is neither gestión nor administración.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function es_area( $user_id = null ) {
		return self::puede( $user_id, 'edit_posts' ) && ! self::es_gestion( $user_id );
	}

	/**
	 * Human label of the user's role, for the application header.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return string "Administración", "Gestión documental", "Área · <categoría>" or "Edición".
	 */
	public static function etiqueta_rol( $user_id = null ) {
		if ( self::es_administracion( $user_id ) ) {
			return 'Administración';
		}

		if ( self::es_gestion( $user_id ) ) {
			return 'Gestión documental';
		}

		$id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$scope_term = absint( get_user_meta( $id, 'documentate_scope_term_id', true ) );
		if ( $scope_term > 0 ) {
			$term = get_term( $scope_term, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return sprintf( 'Área · %s', $term->name );
			}
		}

		return 'Edición';
	}
}
