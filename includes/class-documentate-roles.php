<?php
/**
 * Role detection for the Documentate workflow.
 *
 * Three concepts share the WordPress roles: "área" (creates and sends
 * documents inside its own scope), "revisión" (the person next to the head
 * of service who reviews the documents of the scope and completes their
 * official data) and "jefatura de servicio" (approves and publishes, or
 * returns). Site administrators count as every one of them. Detection is
 * capability based so the site owner can move it to other roles later.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Roles
 *
 * Static helpers to tell the workflow roles apart and to keep their
 * capabilities granted to the roles that carry them.
 */
class Documentate_Roles {

	/**
	 * Capability that marks a user as "revisión".
	 *
	 * It only takes effect together with `edit_others_posts`: revisión must be
	 * able to open documents from every área of its scope, which WordPress
	 * gates with that primitive capability. Granting CAP_MANAGEMENT alone
	 * does nothing.
	 *
	 * @var string
	 */
	const CAP_MANAGEMENT = 'documentate_gestionar';

	/**
	 * Capability that marks a user as "jefatura de servicio".
	 *
	 * Same rule as CAP_MANAGEMENT: it needs `edit_others_posts` beside it.
	 *
	 * @var string
	 */
	const CAP_HEAD = 'documentate_aprobar';

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
	 * moved it to a role of its own and took it back from `editor`; version 3
	 * adds the jefatura de servicio role and renames the other one "Revisión".
	 *
	 * @var string
	 */
	const VERSION = '3';

	/**
	 * Version whose grant to the stock editor role has to be undone.
	 *
	 * @var string
	 */
	const VERSION_EDITOR_GRANT = '1';

	/**
	 * Dedicated role for the people who review and complete the official data.
	 *
	 * The slug predates the name: it was "Gestión documental" until version 3.
	 *
	 * @var string
	 */
	const ROLE_MANAGEMENT = 'documentate_gestion';

	/**
	 * Dedicated role for the heads of service who approve.
	 *
	 * @var string
	 */
	const ROLE_HEAD = 'documentate_jefatura';

	/**
	 * Display names of the two roles, as the site shows them.
	 *
	 * @var string
	 */
	const LABEL_MANAGEMENT = 'Revisión';

	/**
	 * Display name of the head of service role.
	 *
	 * @var string
	 */
	const LABEL_HEAD = 'Jefatura de servicio';

	/**
	 * Stock roles that receive both capabilities on top of the dedicated roles.
	 *
	 * Administrators are administración already, so the capabilities only
	 * make their role explicit. No other role of the site is touched: who
	 * reviews and who approves is a decision the site owner takes account by
	 * account.
	 *
	 * @var string[]
	 */
	const ROLES_WITH_MANAGEMENT = array( 'administrator' );

	/**
	 * Capabilities the revisión role carries.
	 *
	 * Everything it needs to open, complete and hand on a document of any área
	 * of its scope, and nothing else: it never publishes and never deletes.
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
	 * Capabilities the jefatura de servicio role carries.
	 *
	 * The same as revisión plus `publish_posts`, which is what approving a
	 * document from wp-admin amounts to. It never deletes either.
	 *
	 * @var array<string,bool>
	 */
	const CAPS_HEAD_ROLE = array(
		'read' => true,
		'upload_files' => true,
		'edit_posts' => true,
		'edit_others_posts' => true,
		'edit_published_posts' => true,
		'publish_posts' => true,
		'read_private_posts' => true,
		self::CAP_HEAD => true,
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
		 * Filter the roles the revisión capability is granted to.
		 *
		 * A site that keeps its reviewers in another role can name it here;
		 * returning fewer roles narrows the grant.
		 *
		 * @param string[] $roles Role names.
		 */
		$roles = (array) apply_filters( 'documentate_roles_con_gestion', $roles );

		return array_values( array_unique( array_map( 'strval', $roles ) ) );
	}

	/**
	 * Roles that carry CAP_HEAD on this site.
	 *
	 * @return string[]
	 */
	public static function head_roles() {
		return array_merge( array( self::ROLE_HEAD ), self::ROLES_WITH_MANAGEMENT );
	}

	/**
	 * Create the two workflow roles and grant their capabilities.
	 *
	 * Runs once per VERSION unless forced (activation forces it so a site
	 * that reactivates the plugin always ends up with the capabilities).
	 * No stock role other than administrator is given either capability: an
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

		self::register_role( self::ROLE_MANAGEMENT, self::LABEL_MANAGEMENT, self::CAPS_MANAGEMENT_ROLE );
		self::register_role( self::ROLE_HEAD, self::LABEL_HEAD, self::CAPS_HEAD_ROLE );

		$roles = self::management_roles();
		self::grant_to_roles( $roles, self::CAP_MANAGEMENT );
		self::grant_to_roles( self::head_roles(), self::CAP_HEAD );

		self::revoke_editor_grant( $applied, $roles );

		update_option( self::OPTION_VERSION, self::VERSION );
	}

	/**
	 * Grant one capability to a list of roles that exist on the site.
	 *
	 * @param string[] $roles Role names.
	 * @param string   $cap   Capability.
	 * @return void
	 */
	private static function grant_to_roles( array $roles, $cap ) {
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Create (or refresh) one of the workflow roles.
	 *
	 * An existing role gets any capability it is missing and its display
	 * name brought up to date: the reviewers' role was created as "Gestión
	 * documental" by earlier versions.
	 *
	 * @param string             $slug  Role slug.
	 * @param string             $label Display name.
	 * @param array<string,bool> $caps  Capabilities the role carries.
	 * @return void
	 */
	private static function register_role( $slug, $label, array $caps ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			add_role( $slug, $label, $caps );
			return;
		}

		foreach ( array_keys( $caps ) as $cap ) {
			$role->add_cap( $cap );
		}

		$wp_roles = wp_roles();
		if ( isset( $wp_roles->roles[ $slug ] ) && $label !== $wp_roles->roles[ $slug ]['name'] ) {
			$wp_roles->roles[ $slug ]['name'] = $label;
			$wp_roles->role_names[ $slug ] = $label;
			update_option( $wp_roles->role_key, $wp_roles->roles );
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
	 * Make one account revisión.
	 *
	 * The capability is granted to the account, never to a stock role of the
	 * site: who reviews the documents of a scope is a decision taken person
	 * by person.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the account carries the capability now.
	 */
	public static function grant_management( $user_id ) {
		return self::grant( $user_id, self::CAP_MANAGEMENT );
	}

	/**
	 * Make one account jefatura de servicio.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether the account carries the capability now.
	 */
	public static function grant_head( $user_id ) {
		return self::grant( $user_id, self::CAP_HEAD );
	}

	/**
	 * Grant one workflow capability to one account.
	 *
	 * @param int    $user_id User ID.
	 * @param string $cap     Capability.
	 * @return bool Whether the account carries the capability now.
	 */
	private static function grant( $user_id, $cap ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( ! $user->has_cap( $cap ) ) {
			$user->add_cap( $cap );
		}

		return true;
	}

	/**
	 * Remove both capabilities from the roles and forget the version (uninstall).
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$roles = array_unique( array_merge( self::management_roles(), self::head_roles(), array( 'editor' ) ) );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP_MANAGEMENT );
				$role->remove_cap( self::CAP_HEAD );
			}
		}

		self::remove_role( self::ROLE_MANAGEMENT );
		self::remove_role( self::ROLE_HEAD );

		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Remove one of the workflow roles, moving its members to the default role.
	 *
	 * WordPress only forgets the definition: a user left on the role keeps a
	 * wp_capabilities entry pointing at nothing and ends up with no
	 * capabilities at all, not even `read`, so they can log in to an empty
	 * admin and no further. They get the default role first, which is what
	 * a site without this plugin would have.
	 *
	 * @param string $slug Role slug.
	 * @return void
	 */
	private static function remove_role( $slug ) {
		if ( ! get_role( $slug ) ) {
			return;
		}

		$fallback = (string) get_option( 'default_role', 'subscriber' );
		$fallback = ( '' !== $fallback && get_role( $fallback ) ) ? $fallback : 'subscriber';

		$members = get_users(
			array(
				'role' => $slug,
				'fields' => 'ID',
			)
		);

		foreach ( $members as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user instanceof WP_User ) {
				$user->set_role( $fallback );
			}
		}

		remove_role( $slug );
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
	 * Whether the user is administración (a site administrator).
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function is_administration( $user_id = null ) {
		return self::has_cap( $user_id, 'manage_options' );
	}

	/**
	 * Whether the user is jefatura de servicio.
	 *
	 * Administrators count as jefatura: everything the head of service may
	 * do, administración may do too.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function is_head( $user_id = null ) {
		if ( self::is_administration( $user_id ) ) {
			return true;
		}

		return self::has_cap( $user_id, self::CAP_HEAD ) && self::has_cap( $user_id, 'edit_others_posts' );
	}

	/**
	 * Whether the user is revisión.
	 *
	 * Jefatura de servicio (and so administración) counts as revisión:
	 * everything the reviewer may do, the head of service may do too.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return bool
	 */
	public static function is_management( $user_id = null ) {
		if ( self::is_head( $user_id ) ) {
			return true;
		}

		return self::has_cap( $user_id, self::CAP_MANAGEMENT ) && self::has_cap( $user_id, 'edit_others_posts' );
	}

	/**
	 * Whether the user is área: can edit documents but is neither revisión nor jefatura.
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
	 * @return string "Administración", "Jefatura de servicio", "Revisión" or "Área".
	 */
	public static function role_label( $user_id = null ) {
		if ( self::is_administration( $user_id ) ) {
			return 'Administración';
		}

		if ( self::is_head( $user_id ) ) {
			return self::LABEL_HEAD;
		}

		return self::is_management( $user_id ) ? self::LABEL_MANAGEMENT : 'Área';
	}

	/**
	 * Human label of the user's scope, for the application header.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return string The scope category, "Todos los ámbitos" for administración
	 *                or "Sin ámbito asignado".
	 */
	public static function scope_label( $user_id = null ) {
		if ( self::is_administration( $user_id ) ) {
			return 'Todos los ámbitos';
		}

		$id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$scope_term = absint( get_user_meta( $id, 'documentate_scope_term_id', true ) );
		if ( $scope_term > 0 ) {
			$term = get_term( $scope_term, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term->name;
			}
		}

		return 'Sin ámbito asignado';
	}
}
