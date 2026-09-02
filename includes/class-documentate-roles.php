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
	 * primitive capability. Granting CAP_MANAGEMENT alone does nothing.
	 *
	 * @var string
	 */
	const CAP_MANAGEMENT = 'documentate_gestionar';

	/**
	 * Option holding the version of the capability set already applied.
	 *
	 * @var string
	 */
	const OPTION_VERSION = 'documentate_roles_version';

	/**
	 * Current version of the capability set.
	 *
	 * Version 1 granted CAP_MANAGEMENT to the stock `editor` role, which turned
	 * every content editor of the site into gestión documental; version 2
	 * moves it to a role of its own and takes it back from `editor`.
	 *
	 * @var string
	 */
	const VERSION = '2';

	/**
	 * Version whose grant to the stock editor role has to be undone.
	 *
	 * @var string
	 */
	const VERSION_EDITOR_GRANT = '1';

	/**
	 * Dedicated role for the people who complete the official data.
	 *
	 * @var string
	 */
	const ROLE_MANAGEMENT = 'documentate_gestion';

	/**
	 * Stock roles that receive CAP_MANAGEMENT on top of ROLE_MANAGEMENT.
	 *
	 * Administrators are administración already, so the capability only makes
	 * their role explicit. No other role of the site is touched: gestión
	 * documental reads and writes documents of every área, which is a
	 * decision the site owner takes account by account.
	 *
	 * @var string[]
	 */
	const ROLES_WITH_MANAGEMENT = array( 'administrator' );

	/**
	 * Capabilities the gestión documental role carries.
	 *
	 * Everything it needs to open, complete and hand on a document of any
	 * área, and nothing else: it never publishes and never deletes.
	 *
	 * @var array<string,bool>
	 */
	const CAPS_MANAGEMENT_ROLE = array(
		'read' => true,
		'upload_files' => true,
		'edit_posts' => true,
		'edit_others_posts' => true,
		'edit_published_posts' => true,
		'read_private_posts' => true,
		self::CAP_MANAGEMENT => true,
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_caps' ), 1 );
	}

	/**
	 * Roles that carry CAP_MANAGEMENT on this site.
	 *
	 * @return string[]
	 */
	public static function management_roles() {
		$roles = array_merge( array( self::ROLE_MANAGEMENT ), self::ROLES_WITH_MANAGEMENT );

		/**
		 * Filter the roles the gestión documental capability is granted to.
		 *
		 * A site that keeps its documental management in another role can
		 * name it here; returning fewer roles narrows the grant.
		 *
		 * @param string[] $roles Role names.
		 */
		$roles = (array) apply_filters( 'documentate_roles_con_gestion', $roles );

		return array_values( array_unique( array_map( 'strval', $roles ) ) );
	}

	/**
	 * Create the gestión documental role and grant it CAP_MANAGEMENT.
	 *
	 * Runs once per VERSION unless forced (activation forces it so a site
	 * that reactivates the plugin always ends up with the capability).
	 * No stock role other than administrator is given the capability: an
	 * update must never hand the documents of every área to the people who
	 * happen to be editors of the site.
	 *
	 * @param bool $force Apply even when the stored version is current.
	 * @return void
	 */
	public static function ensure_caps( $force = false ) {
		$applied = (string) get_option( self::OPTION_VERSION );
		if ( ! $force && self::VERSION === $applied ) {
			return;
		}

		self::register_management_role();

		$roles = self::management_roles();
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP_MANAGEMENT );
			}
		}

		self::revoke_editor_grant( $applied, $roles );

		update_option( self::OPTION_VERSION, self::VERSION );
	}

	/**
	 * Create (or refresh) the gestión documental role.
	 *
	 * @return void
	 */
	private static function register_management_role() {
		$role = get_role( self::ROLE_MANAGEMENT );
		if ( ! $role ) {
			add_role( self::ROLE_MANAGEMENT, 'Gestión documental', self::CAPS_MANAGEMENT_ROLE );
			return;
		}

		foreach ( array_keys( self::CAPS_MANAGEMENT_ROLE ) as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Take CAP_MANAGEMENT back from the stock editor role of a version 1 site.
	 *
	 * Version 1 granted it on activation with no way of saying no, so an
	 * update is the only chance to undo it. A site that put the capability
	 * back on purpose says so through the filter, and is left alone.
	 *
	 * @param string   $applied Version of the capability set already applied.
	 * @param string[] $roles    Roles that carry the capability now.
	 * @return void
	 */
	private static function revoke_editor_grant( $applied, array $roles ) {
		if ( self::VERSION_EDITOR_GRANT !== $applied || in_array( 'editor', $roles, true ) ) {
			return;
		}

		$role = get_role( 'editor' );
		if ( $role ) {
			$role->remove_cap( self::CAP_MANAGEMENT );
		}
	}

	/**
	 * Make one account gestión documental.
	 *
	 * The capability is granted to the account, never to a stock role of the
	 * site: who completes the official data of every área is a decision taken
	 * person by person.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the account carries the capability now.
	 */
	public static function grant_management( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( ! $user->has_cap( self::CAP_MANAGEMENT ) ) {
			$user->add_cap( self::CAP_MANAGEMENT );
		}

		return true;
	}

	/**
	 * Remove CAP_MANAGEMENT from the roles and forget the version (uninstall).
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$roles = array_merge( self::management_roles(), array( 'editor' ) );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP_MANAGEMENT );
			}
		}

		if ( get_role( self::ROLE_MANAGEMENT ) ) {
			remove_role( self::ROLE_MANAGEMENT );
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
	private static function has_cap( $user_id, $cap ) {
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
	public static function is_administration( $user_id = null ) {
		return self::has_cap( $user_id, 'manage_options' );
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
	public static function is_management( $user_id = null ) {
		if ( self::is_administration( $user_id ) ) {
			return true;
		}

		return self::has_cap( $user_id, self::CAP_MANAGEMENT ) && self::has_cap( $user_id, 'edit_others_posts' );
	}

	/**
	 * Whether the user is área: can edit documents but is neither gestión nor administración.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function is_area( $user_id = null ) {
		return self::has_cap( $user_id, 'edit_posts' ) && ! self::is_management( $user_id );
	}

	/**
	 * Human label of the user's role, for the application header.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return string "Administración", "Gestión documental", "Área · <categoría>" or "Edición".
	 */
	public static function role_label( $user_id = null ) {
		if ( self::is_administration( $user_id ) ) {
			return 'Administración';
		}

		if ( self::is_management( $user_id ) ) {
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
