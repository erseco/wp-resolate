<?php
/**
 * Tests for the tree scope of Documentate_Scope_Filter.
 *
 * Revisión and jefatura de servicio reach the documents of several áreas
 * because their scope is a category above them, not because of any bypass:
 * the same rule as an área, applied higher up the tree.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Scope_Filter
 */
class DocumentateScopeManagementTest extends WP_UnitTestCase {

	/**
	 * Scope filter instance.
	 *
	 * @var Documentate_Scope_Filter
	 */
	private $filter;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Revisión user ID (editor), scoped on the service.
	 *
	 * @var int
	 */
	private $management_id;

	/**
	 * Jefatura de servicio user ID (editor), scoped on the service.
	 *
	 * @var int
	 */
	private $head_id;

	/**
	 * Category of the service, parent of áreas A and B.
	 *
	 * @var int
	 */
	private $cat_service;

	/**
	 * Category of área A.
	 *
	 * @var int
	 */
	private $cat_a;

	/**
	 * Category of área B.
	 *
	 * @var int
	 */
	private $cat_b;

	/**
	 * Category of another service, outside the scope.
	 *
	 * @var int
	 */
	private $cat_other;

	/**
	 * Documents keyed by a short name.
	 *
	 * @var array<string,int>
	 */
	private $docs = array();

	/**
	 * Set up users, categories and the data set.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Statuses::register();
		Documentate_Roles::ensure_caps( true );
		$this->filter = new Documentate_Scope_Filter();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->head_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new WP_User( $this->head_id ) )->add_cap( Documentate_Roles::CAP_HEAD );

		$service = wp_insert_term( 'Servicio', 'category' );
		$this->cat_service = (int) $service['term_id'];
		$a = wp_insert_term( 'Área A', 'category', array( 'parent' => $this->cat_service ) );
		$b = wp_insert_term( 'Área B', 'category', array( 'parent' => $this->cat_service ) );
		$other = wp_insert_term( 'Otro servicio', 'category' );
		$this->cat_a = (int) $a['term_id'];
		$this->cat_b = (int) $b['term_id'];
		$this->cat_other = (int) $other['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_service );
		update_user_meta( $this->head_id, 'documentate_scope_term_id', $this->cat_service );

		$type = wp_insert_term( 'Resolución S', 'documentate_doc_type' );
		$type_id = (int) $type['term_id'];

		$this->docs = array(
			'draft_a' => $this->make_doc( 'draft', $this->cat_a, $type_id ),
			'gestion_b' => $this->make_doc( 'en_gestion', $this->cat_b, $type_id ),
			'pending_b' => $this->make_doc( 'pending', $this->cat_b, $type_id ),
			'publish_a' => $this->make_doc( 'publish', $this->cat_a, $type_id ),
			'archived_b' => $this->make_doc( 'archived', $this->cat_b, $type_id ),
			'auto_draft_b' => $this->make_doc( 'auto-draft', $this->cat_b, $type_id ),
			'draft_other' => $this->make_doc( 'draft', $this->cat_other, $type_id ),
			'gestion_other' => $this->make_doc( 'en_gestion', $this->cat_other, $type_id ),
			'pending_none' => $this->make_doc( 'pending', 0, $type_id ),
		);

		set_current_screen( 'edit-documentate_document' );
	}

	/**
	 * Reset user, request and screen state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Create a document as the administrator.
	 *
	 * @param string $status  Post status.
	 * @param int    $cat_id  Category term ID (0 = none).
	 * @param int    $type_id Document type term ID.
	 * @return int
	 */
	private function make_doc( $status, $cat_id, $type_id ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $status . ' ' . $cat_id,
				'post_status' => $status,
				'post_author' => $this->admin_id,
				'tax_input' => array( 'documentate_doc_type' => array( $type_id ) ),
			)
		);
		if ( $cat_id > 0 ) {
			wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		}
		wp_set_current_user( 0 );

		return $post_id;
	}

	/**
	 * Run the admin list main query for the current user and return the IDs it lists.
	 *
	 * @return int[]
	 */
	private function listed_ids() {
		$query = new WP_Query();
		$query->set( 'post_type', 'documentate_document' );
		// The list table always sets explicit statuses ('any' would skip archived).
		$query->set( 'post_status', array( 'draft', 'en_gestion', 'pending', 'publish', 'archived' ) );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'fields', 'ids' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $query;

		$this->filter->filter_documents_by_scope( $query );
		$ids = array_map( 'intval', (array) $query->get_posts() );
		sort( $ids );

		return $ids;
	}

	/**
	 * Every document under the service, in every status; nothing of another service.
	 */
	private function expected_in_scope() {
		$expected = array(
			$this->docs['draft_a'],
			$this->docs['gestion_b'],
			$this->docs['pending_b'],
			$this->docs['publish_a'],
			$this->docs['archived_b'],
		);
		sort( $expected );

		return $expected;
	}

	/**
	 * A scope on the service covers both áreas, whatever the status; the rest is closed.
	 *
	 * @dataProvider provider_reviewing_roles
	 *
	 * @param string $role management or head.
	 */
	public function test_user_can_access_document_follows_the_tree( $role ) {
		$user_id = 'head' === $role ? $this->head_id : $this->management_id;

		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['draft_a'], $user_id ), 'A draft of an área below is theirs to see.' );
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['gestion_b'], $user_id ) );
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['pending_b'], $user_id ) );
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['publish_a'], $user_id ) );
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['archived_b'], $user_id ) );
		// Auto-drafts are open to every scoped user by the stub rule (they have no category yet).
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['auto_draft_b'], $user_id ) );

		$this->assertFalse( Documentate_Scope_Filter::user_can_access_document( $this->docs['draft_other'], $user_id ) );
		$this->assertFalse( Documentate_Scope_Filter::user_can_access_document( $this->docs['gestion_other'], $user_id ), 'Being in the pipeline opens nothing outside the scope.' );
		$this->assertFalse( Documentate_Scope_Filter::user_can_access_document( $this->docs['pending_none'], $user_id ), 'A document with no category is out of every scope.' );

		// The current user is used when no ID is given.
		wp_set_current_user( $user_id );
		$this->assertTrue( Documentate_Scope_Filter::user_can_access_document( $this->docs['pending_b'] ) );
		$this->assertFalse( Documentate_Scope_Filter::user_can_access_document( $this->docs['gestion_other'] ) );
	}

	/**
	 * The two roles that look after several áreas.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_reviewing_roles() {
		return array(
			'revisión' => array( 'management' ),
			'jefatura de servicio' => array( 'head' ),
		);
	}

	/**
	 * Object caps follow the scope, and deletion also follows the workflow lock.
	 */
	public function test_map_meta_cap_follows_the_tree() {
		$this->assertTrue( user_can( $this->management_id, 'edit_post', $this->docs['gestion_b'] ) );
		$this->assertTrue( user_can( $this->management_id, 'read_post', $this->docs['gestion_b'] ) );
		$this->assertTrue( user_can( $this->management_id, 'delete_post', $this->docs['gestion_b'] ), 'Revisión holds a document in revisión.' );
		$this->assertFalse( user_can( $this->management_id, 'delete_post', $this->docs['pending_b'] ), 'Locked for revisión once it moved on.' );
		$this->assertTrue( user_can( $this->head_id, 'delete_post', $this->docs['pending_b'] ), 'The head of service holds a document in aprobación.' );
		$this->assertFalse( user_can( $this->head_id, 'delete_post', $this->docs['publish_a'] ) );

		$this->assertTrue( user_can( $this->management_id, 'edit_post', $this->docs['draft_a'] ) );
		$this->assertTrue( user_can( $this->management_id, 'delete_post', $this->docs['draft_a'] ) );

		$this->assertFalse( user_can( $this->management_id, 'edit_post', $this->docs['gestion_other'] ) );
		$this->assertFalse( user_can( $this->management_id, 'read_post', $this->docs['gestion_other'] ) );
		$this->assertFalse( user_can( $this->head_id, 'edit_post', $this->docs['pending_none'] ) );
		$this->assertFalse( user_can( $this->head_id, 'edit_post', $this->docs['draft_other'] ) );
	}

	/**
	 * The admin list of a reviewing role is a plain scope query on its subtree.
	 */
	public function test_list_query_is_the_subtree() {
		wp_set_current_user( $this->management_id );
		$this->assertSame( $this->expected_in_scope(), $this->listed_ids() );

		wp_set_current_user( $this->head_id );
		$this->assertSame( $this->expected_in_scope(), $this->listed_ids() );
	}

	/**
	 * A reviewer without a scope sees nothing at all: no bypass, no pipeline.
	 */
	public function test_list_query_without_scope_is_empty() {
		delete_user_meta( $this->management_id, Documentate_Scope_Filter::SCOPE_META_KEY );
		wp_set_current_user( $this->management_id );

		$this->assertSame( array(), $this->listed_ids() );
		$this->assertFalse( Documentate_Scope_Filter::user_can_access_document( $this->docs['gestion_b'], $this->management_id ) );
	}

	/**
	 * The view counters of a reviewer match the rows the list shows.
	 */
	public function test_view_counts_follow_the_tree() {
		wp_set_current_user( $this->management_id );

		$views = array();
		foreach ( array( 'all', 'mine', 'draft', 'en_gestion', 'pending', 'publish', 'archived', 'trash' ) as $key ) {
			$views[ $key ] = '<a href="#">' . $key . ' <span class="count">(99)</span></a>';
		}

		$result = $this->filter->filter_view_counts( $views );

		$this->assertSame( 4, $this->count_of( $result['all'] ), 'draft A + en_gestion B + pending B + publish A (archived has its own view).' );
		$this->assertSame( 1, $this->count_of( $result['draft'] ) );
		$this->assertSame( 1, $this->count_of( $result['en_gestion'] ) );
		$this->assertSame( 1, $this->count_of( $result['pending'] ) );
		$this->assertSame( 1, $this->count_of( $result['publish'] ) );
		$this->assertSame( 1, $this->count_of( $result['archived'] ) );
		$this->assertArrayNotHasKey( 'mine', $result, 'The reviewer authored nothing.' );
		$this->assertArrayNotHasKey( 'trash', $result );
	}

	/**
	 * Counters of a reviewer without a scope are all zero.
	 */
	public function test_view_counts_without_scope_are_zero() {
		delete_user_meta( $this->management_id, Documentate_Scope_Filter::SCOPE_META_KEY );
		wp_set_current_user( $this->management_id );

		$views = array(
			'all' => '<a href="#">all <span class="count">(99)</span></a>',
			'draft' => '<a href="#">draft <span class="count">(99)</span></a>',
			'archived' => '<a href="#">archived <span class="count">(99)</span></a>',
		);

		$result = $this->filter->filter_view_counts( $views );

		$this->assertSame( 0, $this->count_of( $result['all'] ) );
		$this->assertArrayNotHasKey( 'draft', $result );
		$this->assertArrayNotHasKey( 'archived', $result );
	}

	/**
	 * Read the counter out of a view link.
	 *
	 * @param string $view View HTML.
	 * @return int
	 */
	private function count_of( $view ) {
		preg_match( '/\((\d+)\)/', $view, $m );

		return isset( $m[1] ) ? (int) $m[1] : -1;
	}
}
