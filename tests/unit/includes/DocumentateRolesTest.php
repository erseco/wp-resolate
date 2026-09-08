<?php
/**
 * Tests for Documentate_Roles.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Roles
 */
class DocumentateRolesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Editor user ID (revisión).
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Editor user ID (jefatura de servicio).
	 *
	 * @var int
	 */
	private $head_id;

	/**
	 * Author user ID (área).
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Scope category term ID.
	 *
	 * @var int
	 */
	private $cat_id;

	/**
	 * Set up users and a scope category.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account; the stock editor
		// role never carries the capability (see test_a_plain_editor_is_not_management).
		( new WP_User( $this->editor_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->head_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new WP_User( $this->head_id ) )->add_cap( Documentate_Roles::CAP_HEAD );
		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat = wp_insert_term( 'Departamento de Proyectos', 'category' );
		$this->cat_id = (int) $cat['term_id'];
	}

	/**
	 * Roles live in memory across tests: put the capability back.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		Documentate_Roles::ensure_caps( true );
		parent::tear_down();
	}

	/**
	 * The capability lives in a role of its own, plus administrators.
	 */
	public function test_caps_granted_to_the_management_role_and_administrators() {
		$management = get_role( Documentate_Roles::ROLE_MANAGEMENT );

		$this->assertNotNull( $management, 'ensure_caps() creates the revisión role.' );
		$this->assertTrue( $management->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertTrue( $management->has_cap( 'edit_others_posts' ), 'Without it the capability does nothing.' );
		$this->assertTrue( $management->has_cap( 'upload_files' ) );
		$this->assertFalse( $management->has_cap( 'publish_posts' ), 'Revisión never publishes.' );
		$this->assertFalse( $management->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertSame( 'Revisión', wp_roles()->role_names[ Documentate_Roles::ROLE_MANAGEMENT ] );

		$this->assertTrue( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( get_role( 'author' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * The head of service has a role of its own, which publishes but never deletes.
	 */
	public function test_caps_granted_to_the_head_role_and_administrators() {
		$head = get_role( Documentate_Roles::ROLE_HEAD );

		$this->assertNotNull( $head, 'ensure_caps() creates the jefatura de servicio role.' );
		$this->assertSame( 'Jefatura de servicio', wp_roles()->role_names[ Documentate_Roles::ROLE_HEAD ] );
		$this->assertTrue( $head->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertTrue( $head->has_cap( 'edit_others_posts' ), 'Without it the capability does nothing.' );
		$this->assertTrue( $head->has_cap( 'publish_posts' ), 'Approving from wp-admin is publishing.' );
		$this->assertFalse( $head->has_cap( 'delete_posts' ) );
		$this->assertFalse( $head->has_cap( 'manage_options' ), 'The head of service is not a site administrator.' );
		$this->assertFalse( $head->has_cap( Documentate_Roles::CAP_MANAGEMENT ), 'is_management() already says yes to heads.' );

		$this->assertTrue( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertFalse( get_role( 'author' )->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertSame( array( Documentate_Roles::ROLE_HEAD, 'administrator' ), Documentate_Roles::head_roles() );
	}

	/**
	 * Version 3 renames the reviewers' role a version 2 site created as "Gestión documental".
	 */
	public function test_ensure_caps_renames_the_reviewers_role() {
		$wp_roles = wp_roles();
		$wp_roles->roles[ Documentate_Roles::ROLE_MANAGEMENT ]['name'] = 'Gestión documental';
		$wp_roles->role_names[ Documentate_Roles::ROLE_MANAGEMENT ] = 'Gestión documental';
		update_option( $wp_roles->role_key, $wp_roles->roles );
		update_option( Documentate_Roles::OPTION_VERSION, '2' );

		Documentate_Roles::ensure_caps();

		$this->assertSame( 'Revisión', wp_roles()->role_names[ Documentate_Roles::ROLE_MANAGEMENT ] );
		$this->assertSame( 'Revisión', get_option( wp_roles()->role_key )[ Documentate_Roles::ROLE_MANAGEMENT ]['name'], 'Persisted, not only in memory.' );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * The stock editor role is left alone: updating the plugin must not hand
	 * the documents of every área to whoever edits the content of the site.
	 */
	public function test_a_plain_editor_is_not_management() {
		// Roles live in the options table and survive between tests, and a site
		// may have been given the capability by hand: what is asserted here is
		// what ensure_caps() does, starting from a role that does not have it.
		get_role( 'editor' )->remove_cap( Documentate_Roles::CAP_MANAGEMENT );

		Documentate_Roles::ensure_caps( true );

		$this->assertNotContains( 'editor', Documentate_Roles::management_roles() );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $editor, Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( Documentate_Roles::is_management( $editor ) );
		$this->assertSame( 'Área', Documentate_Roles::role_label( $editor ) );
	}

	/**
	 * A site that already ran version 1 gets the editor grant taken back.
	 */
	public function test_the_version_1_grant_to_the_editor_role_is_undone() {
		get_role( 'editor' )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		update_option( Documentate_Roles::OPTION_VERSION, Documentate_Roles::VERSION_EDITOR_GRANT );

		Documentate_Roles::ensure_caps();

		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * A site that says the capability belongs to another role keeps it there.
	 */
	public function test_the_roles_list_is_filterable() {
		$filter = static function () {
			return array( 'editor' );
		};
		add_filter( 'documentate_roles_con_gestion', $filter );

		Documentate_Roles::ensure_caps( true );
		$this->assertContains( 'editor', Documentate_Roles::management_roles() );
		$this->assertTrue( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );

		remove_filter( 'documentate_roles_con_gestion', $filter );
		get_role( 'editor' )->remove_cap( Documentate_Roles::CAP_MANAGEMENT );
	}

	/**
	 * ensure_caps() runs once per version unless forced.
	 */
	public function test_ensure_caps_runs_once_per_version_unless_forced() {
		$role = static function () {
			return get_role( Documentate_Roles::ROLE_MANAGEMENT );
		};

		$role()->remove_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->assertFalse( $role()->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );

		// Version already applied: nothing happens.
		Documentate_Roles::ensure_caps();
		$this->assertFalse( $role()->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );

		// Forced: granted again.
		Documentate_Roles::ensure_caps( true );
		$this->assertTrue( $role()->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );

		// A missing (older) version grants without forcing.
		$role()->remove_cap( Documentate_Roles::CAP_MANAGEMENT );
		delete_option( Documentate_Roles::OPTION_VERSION );
		Documentate_Roles::ensure_caps();
		$this->assertTrue( $role()->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * init() hooks ensure_caps() early on init.
	 */
	public function test_init_hooks_ensure_caps_on_init() {
		Documentate_Roles::init();

		$this->assertSame( 1, has_action( 'init', array( 'Documentate_Roles', 'ensure_caps' ) ) );
	}

	/**
	 * remove_caps() drops the role, the capability and the version.
	 */
	public function test_remove_caps_strips_roles_and_option() {
		get_role( 'editor' )->add_cap( Documentate_Roles::CAP_MANAGEMENT );

		Documentate_Roles::remove_caps();

		$this->assertNull( get_role( Documentate_Roles::ROLE_MANAGEMENT ) );
		$this->assertNull( get_role( Documentate_Roles::ROLE_HEAD ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ), 'A site that moved the capability to the editor role is cleaned up too.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_HEAD ) );
		$this->assertFalse( get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * Removing the role never leaves its members without capabilities.
	 *
	 * remove_role() only forgets the definition: a user left on it keeps a
	 * wp_capabilities entry pointing at nothing and ends up with none at all,
	 * not even `read`, able to log in to an empty admin and no further.
	 */
	public function test_remove_caps_gives_the_role_members_somewhere_to_land() {
		$member = self::factory()->user->create( array( 'role' => Documentate_Roles::ROLE_MANAGEMENT ) );
		$head = self::factory()->user->create( array( 'role' => Documentate_Roles::ROLE_HEAD ) );

		Documentate_Roles::remove_caps();

		foreach ( array( $member, $head ) as $user_id ) {
			$user = get_userdata( $user_id );
			$this->assertNotContains( Documentate_Roles::ROLE_MANAGEMENT, $user->roles );
			$this->assertNotContains( Documentate_Roles::ROLE_HEAD, $user->roles );
			$this->assertSame( array( get_option( 'default_role', 'subscriber' ) ), $user->roles );
			$this->assertTrue( $user->has_cap( 'read' ), 'A member of the removed role can still use the site.' );
		}
	}

	/**
	 * Role detection by explicit user ID.
	 */
	public function test_role_detection_by_user_id() {
		$this->assertTrue( Documentate_Roles::is_administration( $this->admin_id ) );
		$this->assertTrue( Documentate_Roles::is_head( $this->admin_id ), 'Admins count as jefatura for capability purposes.' );
		$this->assertTrue( Documentate_Roles::is_management( $this->admin_id ), 'Admins count as revisión for capability purposes.' );
		$this->assertFalse( Documentate_Roles::is_area( $this->admin_id ) );

		$this->assertFalse( Documentate_Roles::is_administration( $this->head_id ) );
		$this->assertTrue( Documentate_Roles::is_head( $this->head_id ) );
		$this->assertTrue( Documentate_Roles::is_management( $this->head_id ), 'The head of service may do everything revisión does.' );
		$this->assertFalse( Documentate_Roles::is_area( $this->head_id ) );

		$this->assertFalse( Documentate_Roles::is_administration( $this->editor_id ) );
		$this->assertFalse( Documentate_Roles::is_head( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::is_management( $this->editor_id ) );
		$this->assertFalse( Documentate_Roles::is_area( $this->editor_id ) );

		$this->assertFalse( Documentate_Roles::is_administration( $this->author_id ) );
		$this->assertFalse( Documentate_Roles::is_management( $this->author_id ) );
		$this->assertTrue( Documentate_Roles::is_area( $this->author_id ) );

		$this->assertFalse( Documentate_Roles::is_head( $this->author_id ) );
		$this->assertFalse( Documentate_Roles::is_management( $this->subscriber_id ) );
		$this->assertFalse( Documentate_Roles::is_area( $this->subscriber_id ) );
		$this->assertFalse( Documentate_Roles::is_area( 0 ) );
		$this->assertFalse( Documentate_Roles::is_head( 0 ) );
	}

	/**
	 * Role detection falls back to the current user.
	 */
	public function test_role_detection_uses_current_user_by_default() {
		$this->assertFalse( Documentate_Roles::is_management() );
		$this->assertFalse( Documentate_Roles::is_area() );

		wp_set_current_user( $this->editor_id );
		$this->assertTrue( Documentate_Roles::is_management() );
		$this->assertFalse( Documentate_Roles::is_head() );
		$this->assertFalse( Documentate_Roles::is_administration() );

		wp_set_current_user( $this->head_id );
		$this->assertTrue( Documentate_Roles::is_head() );

		wp_set_current_user( $this->author_id );
		$this->assertTrue( Documentate_Roles::is_area() );
	}

	/**
	 * CAP_MANAGEMENT alone is not enough: edit_others_posts is required too.
	 */
	public function test_management_requires_edit_others_posts() {
		$user = new WP_User( $this->author_id );
		$user->add_cap( Documentate_Roles::CAP_MANAGEMENT );

		$this->assertTrue( user_can( $this->author_id, Documentate_Roles::CAP_MANAGEMENT ) );
		$this->assertFalse( Documentate_Roles::is_management( $this->author_id ) );
		$this->assertTrue( Documentate_Roles::is_area( $this->author_id ) );

		// And an account denied the capability stops being revisión.
		( new WP_User( $this->editor_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT, false );
		$this->assertFalse( Documentate_Roles::is_management( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::is_area( $this->editor_id ) );
	}

	/**
	 * CAP_HEAD alone is not enough either, and grant_head() hands it to one account.
	 */
	public function test_head_requires_edit_others_posts() {
		$this->assertTrue( Documentate_Roles::grant_head( $this->author_id ) );
		$this->assertTrue( user_can( $this->author_id, Documentate_Roles::CAP_HEAD ) );
		$this->assertFalse( Documentate_Roles::is_head( $this->author_id ), 'An author cannot open the documents of others.' );
		$this->assertTrue( Documentate_Roles::is_area( $this->author_id ) );

		$this->assertTrue( Documentate_Roles::grant_head( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::is_head( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::grant_head( $this->editor_id ), 'Granting twice is harmless.' );

		$this->assertFalse( Documentate_Roles::grant_head( 999999 ) );
		$this->assertFalse( Documentate_Roles::grant_management( 999999 ) );
	}

	/**
	 * The role label per role.
	 */
	public function test_role_label() {
		$this->assertSame( 'Administración', Documentate_Roles::role_label( $this->admin_id ) );
		$this->assertSame( 'Jefatura de servicio', Documentate_Roles::role_label( $this->head_id ) );
		$this->assertSame( 'Revisión', Documentate_Roles::role_label( $this->editor_id ) );
		$this->assertSame( 'Área', Documentate_Roles::role_label( $this->author_id ) );

		// The scope never changes the role label.
		update_user_meta( $this->author_id, 'documentate_scope_term_id', $this->cat_id );
		$this->assertSame( 'Área', Documentate_Roles::role_label( $this->author_id ) );
		update_user_meta( $this->editor_id, 'documentate_scope_term_id', $this->cat_id );
		$this->assertSame( 'Revisión', Documentate_Roles::role_label( $this->editor_id ) );

		// Defaults to the current user.
		wp_set_current_user( $this->head_id );
		$this->assertSame( 'Jefatura de servicio', Documentate_Roles::role_label() );
	}

	/**
	 * The ámbito label per scope.
	 */
	public function test_scope_label() {
		$this->assertSame( 'Todos los ámbitos', Documentate_Roles::scope_label( $this->admin_id ) );
		$this->assertSame( 'Sin ámbito asignado', Documentate_Roles::scope_label( $this->author_id ) );

		update_user_meta( $this->author_id, 'documentate_scope_term_id', $this->cat_id );
		$this->assertSame( 'Departamento de Proyectos', Documentate_Roles::scope_label( $this->author_id ) );

		// A scope pointing at a deleted term falls back to the generic label.
		update_user_meta( $this->head_id, 'documentate_scope_term_id', 999999 );
		$this->assertSame( 'Sin ámbito asignado', Documentate_Roles::scope_label( $this->head_id ) );

		// Defaults to the current user.
		wp_set_current_user( $this->author_id );
		$this->assertSame( 'Departamento de Proyectos', Documentate_Roles::scope_label() );
	}
}
