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
	 * Version 1 granted CAP_GESTION to the stock `editor` role, which turned
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
	const ROL_GESTION = 'documentate_gestion';

	/**
	 * Stock roles that receive CAP_GESTION on top of ROL_GESTION.
	 *
	 * Administrators are administración already, so the capability only makes
	 * their role explicit. No other role of the site is touched: gestión
	 * documental reads and writes documents of every área, which is a
	 * decision the site owner takes account by account.
	 *
	 * @var string[]
	 */
	const ROLES_CON_GESTION = array( 'administrator' );

	/**
	 * Capabilities the gestión documental role carries.
	 *
	 * Everything it needs to open, complete and hand on a document of any
	 * área, and nothing else: it never publishes and never deletes.
	 *
	 * @var array<string,bool>
	 */
	const CAPS_ROL_GESTION = array(
		'read' => true,
		'upload_files' => true,
		'edit_posts' => true,
		'edit_others_posts' => true,
		'edit_published_posts' => true,
		'read_private_posts' => true,
		self::CAP_GESTION => true,
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
	 * Roles that carry CAP_GESTION on this site.
	 *
	 * @return string[]
	 */
	public static function roles_con_gestion() {
		$roles = array_merge( array( self::ROL_GESTION ), self::ROLES_CON_GESTION );

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
	 * Create the gestión documental role and grant it CAP_GESTION.
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
		$aplicada = (string) get_option( self::OPTION_VERSION );
		if ( ! $force && self::VERSION === $aplicada ) {
			return;
		}

		self::registrar_rol_gestion();

		$roles = self::roles_con_gestion();
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( self::CAP_GESTION );
			}
		}

		self::revocar_grant_de_editor( $aplicada, $roles );

		update_option( self::OPTION_VERSION, self::VERSION );
	}

	/**
	 * Create (or refresh) the gestión documental role.
	 *
	 * @return void
	 */
	private static function registrar_rol_gestion() {
		$role = get_role( self::ROL_GESTION );
		if ( ! $role ) {
			add_role( self::ROL_GESTION, 'Gestión documental', self::CAPS_ROL_GESTION );
			return;
		}

		foreach ( array_keys( self::CAPS_ROL_GESTION ) as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Take CAP_GESTION back from the stock editor role of a version 1 site.
	 *
	 * Version 1 granted it on activation with no way of saying no, so an
	 * update is the only chance to undo it. A site that put the capability
	 * back on purpose says so through the filter, and is left alone.
	 *
	 * @param string   $aplicada Version of the capability set already applied.
	 * @param string[] $roles    Roles that carry the capability now.
	 * @return void
	 */
	private static function revocar_grant_de_editor( $aplicada, array $roles ) {
		if ( self::VERSION_EDITOR_GRANT !== $aplicada || in_array( 'editor', $roles, true ) ) {
			return;
		}

		$role = get_role( 'editor' );
		if ( $role ) {
			$role->remove_cap( self::CAP_GESTION );
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
	public static function conceder_gestion( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( ! $user->has_cap( self::CAP_GESTION ) ) {
			$user->add_cap( self::CAP_GESTION );
		}

		return true;
	}

	/**
	 * Remove CAP_GESTION from the roles and forget the version (uninstall).
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$roles = array_merge( self::roles_con_gestion(), array( 'editor' ) );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP_GESTION );
			}
		}

		if ( get_role( self::ROL_GESTION ) ) {
			remove_role( self::ROL_GESTION );
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
