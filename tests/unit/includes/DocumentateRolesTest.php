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
	 * Editor user ID (gestión documental).
	 *
	 * @var int
	 */
	private $editor_id;

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
		// role never carries the capability (see test_a_plain_editor_is_not_gestion).
		( new WP_User( $this->editor_id ) )->add_cap( Documentate_Roles::CAP_GESTION );
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
	public function test_caps_granted_to_the_gestion_role_and_administrators() {
		$gestion = get_role( Documentate_Roles::ROL_GESTION );

		$this->assertNotNull( $gestion, 'ensure_caps() creates the gestión documental role.' );
		$this->assertTrue( $gestion->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertTrue( $gestion->has_cap( 'edit_others_posts' ), 'Without it the capability does nothing.' );
		$this->assertTrue( $gestion->has_cap( 'upload_files' ) );
		$this->assertFalse( $gestion->has_cap( 'publish_posts' ), 'Gestión never publishes.' );

		$this->assertTrue( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertFalse( get_role( 'author' )->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * The stock editor role is left alone: updating the plugin must not hand
	 * the documents of every área to whoever edits the content of the site.
	 */
	public function test_a_plain_editor_is_not_gestion() {
		// Roles live in the options table and survive between tests, and a site
		// may have been given the capability by hand: what is asserted here is
		// what ensure_caps() does, starting from a role that does not have it.
		get_role( 'editor' )->remove_cap( Documentate_Roles::CAP_GESTION );

		Documentate_Roles::ensure_caps( true );

		$this->assertNotContains( 'editor', Documentate_Roles::roles_con_gestion() );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_GESTION ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $editor, Documentate_Roles::CAP_GESTION ) );
		$this->assertFalse( Documentate_Roles::es_gestion( $editor ) );
		$this->assertSame( 'Edición', Documentate_Roles::etiqueta_rol( $editor ) );
	}

	/**
	 * A site that already ran version 1 gets the editor grant taken back.
	 */
	public function test_the_version_1_grant_to_the_editor_role_is_undone() {
		get_role( 'editor' )->add_cap( Documentate_Roles::CAP_GESTION );
		update_option( Documentate_Roles::OPTION_VERSION, Documentate_Roles::VERSION_EDITOR_GRANT );

		Documentate_Roles::ensure_caps();

		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertSame( Documentate_Roles::VERSION, get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * A site that says the capability belongs to another role keeps it there.
	 */
	public function test_the_roles_list_is_filterable() {
		$filtro = static function () {
			return array( 'editor' );
		};
		add_filter( 'documentate_roles_con_gestion', $filtro );

		Documentate_Roles::ensure_caps( true );
		$this->assertContains( 'editor', Documentate_Roles::roles_con_gestion() );
		$this->assertTrue( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_GESTION ) );

		remove_filter( 'documentate_roles_con_gestion', $filtro );
		get_role( 'editor' )->remove_cap( Documentate_Roles::CAP_GESTION );
	}

	/**
	 * ensure_caps() runs once per version unless forced.
	 */
	public function test_ensure_caps_runs_once_per_version_unless_forced() {
		$rol = static function () {
			return get_role( Documentate_Roles::ROL_GESTION );
		};

		$rol()->remove_cap( Documentate_Roles::CAP_GESTION );
		$this->assertFalse( $rol()->has_cap( Documentate_Roles::CAP_GESTION ) );

		// Version already applied: nothing happens.
		Documentate_Roles::ensure_caps();
		$this->assertFalse( $rol()->has_cap( Documentate_Roles::CAP_GESTION ) );

		// Forced: granted again.
		Documentate_Roles::ensure_caps( true );
		$this->assertTrue( $rol()->has_cap( Documentate_Roles::CAP_GESTION ) );

		// A missing (older) version grants without forcing.
		$rol()->remove_cap( Documentate_Roles::CAP_GESTION );
		delete_option( Documentate_Roles::OPTION_VERSION );
		Documentate_Roles::ensure_caps();
		$this->assertTrue( $rol()->has_cap( Documentate_Roles::CAP_GESTION ) );
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
		get_role( 'editor' )->add_cap( Documentate_Roles::CAP_GESTION );

		Documentate_Roles::remove_caps();

		$this->assertNull( get_role( Documentate_Roles::ROL_GESTION ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Documentate_Roles::CAP_GESTION ), 'A site that moved the capability to the editor role is cleaned up too.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Documentate_Roles::CAP_GESTION ) );
		$this->assertFalse( get_option( Documentate_Roles::OPTION_VERSION ) );
	}

	/**
	 * Role detection by explicit user ID.
	 */
	public function test_role_detection_by_user_id() {
		$this->assertTrue( Documentate_Roles::es_administracion( $this->admin_id ) );
		$this->assertTrue( Documentate_Roles::es_gestion( $this->admin_id ), 'Admins count as gestión for capability purposes.' );
		$this->assertFalse( Documentate_Roles::es_area( $this->admin_id ) );

		$this->assertFalse( Documentate_Roles::es_administracion( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::es_gestion( $this->editor_id ) );
		$this->assertFalse( Documentate_Roles::es_area( $this->editor_id ) );

		$this->assertFalse( Documentate_Roles::es_administracion( $this->author_id ) );
		$this->assertFalse( Documentate_Roles::es_gestion( $this->author_id ) );
		$this->assertTrue( Documentate_Roles::es_area( $this->author_id ) );

		$this->assertFalse( Documentate_Roles::es_gestion( $this->subscriber_id ) );
		$this->assertFalse( Documentate_Roles::es_area( $this->subscriber_id ) );
		$this->assertFalse( Documentate_Roles::es_area( 0 ) );
	}

	/**
	 * Role detection falls back to the current user.
	 */
	public function test_role_detection_uses_current_user_by_default() {
		$this->assertFalse( Documentate_Roles::es_gestion() );
		$this->assertFalse( Documentate_Roles::es_area() );

		wp_set_current_user( $this->editor_id );
		$this->assertTrue( Documentate_Roles::es_gestion() );
		$this->assertFalse( Documentate_Roles::es_administracion() );

		wp_set_current_user( $this->author_id );
		$this->assertTrue( Documentate_Roles::es_area() );
	}

	/**
	 * CAP_GESTION alone is not enough: edit_others_posts is required too.
	 */
	public function test_gestion_requires_edit_others_posts() {
		$user = new WP_User( $this->author_id );
		$user->add_cap( Documentate_Roles::CAP_GESTION );

		$this->assertTrue( user_can( $this->author_id, Documentate_Roles::CAP_GESTION ) );
		$this->assertFalse( Documentate_Roles::es_gestion( $this->author_id ) );
		$this->assertTrue( Documentate_Roles::es_area( $this->author_id ) );

		// And an account denied the capability stops being gestión.
		( new WP_User( $this->editor_id ) )->add_cap( Documentate_Roles::CAP_GESTION, false );
		$this->assertFalse( Documentate_Roles::es_gestion( $this->editor_id ) );
		$this->assertTrue( Documentate_Roles::es_area( $this->editor_id ) );
	}

	/**
	 * The role label per role and scope.
	 */
	public function test_etiqueta_rol() {
		$this->assertSame( 'Administración', Documentate_Roles::etiqueta_rol( $this->admin_id ) );
		$this->assertSame( 'Gestión documental', Documentate_Roles::etiqueta_rol( $this->editor_id ) );
		$this->assertSame( 'Edición', Documentate_Roles::etiqueta_rol( $this->author_id ) );

		update_user_meta( $this->author_id, 'documentate_scope_term_id', $this->cat_id );
		$this->assertSame( 'Área · Departamento de Proyectos', Documentate_Roles::etiqueta_rol( $this->author_id ) );

		// A scope pointing at a deleted term falls back to the generic label.
		update_user_meta( $this->author_id, 'documentate_scope_term_id', 999999 );
		$this->assertSame( 'Edición', Documentate_Roles::etiqueta_rol( $this->author_id ) );

		// Gestión keeps its label even with a scope.
		update_user_meta( $this->editor_id, 'documentate_scope_term_id', $this->cat_id );
		$this->assertSame( 'Gestión documental', Documentate_Roles::etiqueta_rol( $this->editor_id ) );

		// Defaults to the current user.
		update_user_meta( $this->author_id, 'documentate_scope_term_id', $this->cat_id );
		wp_set_current_user( $this->author_id );
		$this->assertSame( 'Área · Departamento de Proyectos', Documentate_Roles::etiqueta_rol() );
	}
}
