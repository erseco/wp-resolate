<?php
/**
 * Tests for the gestión documental rules of Documentate_Scope_Filter.
 *
 * Gestión reaches every document that entered the pipeline (anything but a
 * draft) whatever área it belongs to, on top of its own scope.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Scope_Filter
 */
class DocumentateScopeGestionTest extends WP_UnitTestCase {

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
	 * Gestión documental user ID (editor).
	 *
	 * @var int
	 */
	private $gestion_id;

	/**
	 * Category of the gestión user's scope.
	 *
	 * @var int
	 */
	private $cat_a;

	/**
	 * Category of another área.
	 *
	 * @var int
	 */
	private $cat_b;

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

		Documentate_Estados::registrar();
		Documentate_Roles::ensure_caps( true );
		$this->filter = new Documentate_Scope_Filter();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$a = wp_insert_term( 'Área A', 'category' );
		$b = wp_insert_term( 'Área B', 'category' );
		$this->cat_a = (int) $a['term_id'];
		$this->cat_b = (int) $b['term_id'];
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $this->cat_a );

		$tipo = wp_insert_term( 'Resolución S', 'documentate_doc_type' );
		$tipo_id = (int) $tipo['term_id'];

		$this->docs = array(
			'draft_a' => $this->make_doc( 'draft', $this->cat_a, $tipo_id ),
			'draft_b' => $this->make_doc( 'draft', $this->cat_b, $tipo_id ),
			'gestion_b' => $this->make_doc( 'en_gestion', $this->cat_b, $tipo_id ),
			'pending_none' => $this->make_doc( 'pending', 0, $tipo_id ),
			'publish_b' => $this->make_doc( 'publish', $this->cat_b, $tipo_id ),
			'archived_b' => $this->make_doc( 'archived', $this->cat_b, $tipo_id ),
			'auto_draft_b' => $this->make_doc( 'auto-draft', $this->cat_b, $tipo_id ),
			'trash_b' => $this->make_doc( 'trash', $this->cat_b, $tipo_id ),
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
	 * @param int    $tipo_id Document type term ID.
	 * @return int
	 */
	private function make_doc( $status, $cat_id, $tipo_id ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $status . ' ' . $cat_id,
				'post_status' => $status,
				'post_author' => $this->admin_id,
				'tax_input' => array( 'documentate_doc_type' => array( $tipo_id ) ),
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
	 * Gestión opens everything in the pipeline; drafts and trash of other áreas stay closed.
	 */
	public function test_user_can_access_document_bypass_for_pipeline() {
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['draft_a'], $this->gestion_id ) );
		$this->assertFalse( $this->filter->user_can_access_document( $this->docs['draft_b'], $this->gestion_id ) );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['gestion_b'], $this->gestion_id ) );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['pending_none'], $this->gestion_id ) );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['publish_b'], $this->gestion_id ) );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['archived_b'], $this->gestion_id ) );
		$this->assertFalse( $this->filter->user_can_access_document( $this->docs['trash_b'], $this->gestion_id ), 'Trash is outside the pipeline.' );
		$this->assertFalse( user_can( $this->gestion_id, 'edit_post', $this->docs['trash_b'] ) );
		// Auto-drafts are open to every scoped user by the stub rule (they have no category yet).
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['auto_draft_b'], $this->gestion_id ) );
		$this->assertContains( 'auto-draft', Documentate_Scope_Filter::OUTSIDE_PIPELINE_STATUSES );

		// Without the bypass the pure scope rule applies.
		$this->assertFalse( $this->filter->user_can_access_document( $this->docs['gestion_b'], $this->gestion_id, false ) );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['draft_a'], $this->gestion_id, false ) );

		// The current user is used when no ID is given.
		wp_set_current_user( $this->gestion_id );
		$this->assertTrue( $this->filter->user_can_access_document( $this->docs['pending_none'] ) );
	}

	/**
	 * Object caps: edit and read pass for pipeline documents, delete keeps the scope rule.
	 */
	public function test_map_meta_cap_bypass_covers_edit_and_read_only() {
		$this->assertTrue( user_can( $this->gestion_id, 'edit_post', $this->docs['gestion_b'] ) );
		$this->assertTrue( user_can( $this->gestion_id, 'read_post', $this->docs['gestion_b'] ) );
		$this->assertFalse( user_can( $this->gestion_id, 'delete_post', $this->docs['gestion_b'] ) );

		$this->assertTrue( user_can( $this->gestion_id, 'edit_post', $this->docs['pending_none'] ) );
		$this->assertFalse( user_can( $this->gestion_id, 'delete_post', $this->docs['pending_none'] ) );

		$this->assertFalse( user_can( $this->gestion_id, 'edit_post', $this->docs['draft_b'] ) );
		$this->assertTrue( user_can( $this->gestion_id, 'edit_post', $this->docs['draft_a'] ) );
		$this->assertTrue( user_can( $this->gestion_id, 'delete_post', $this->docs['draft_a'] ) );
	}

	/**
	 * The admin list of a gestión user: own scope OR pipeline, drafts of others excluded.
	 */
	public function test_list_query_ors_scope_with_pipeline_statuses() {
		wp_set_current_user( $this->gestion_id );

		$esperados = array(
			$this->docs['draft_a'],
			$this->docs['gestion_b'],
			$this->docs['pending_none'],
			$this->docs['publish_b'],
			$this->docs['archived_b'],
		);
		sort( $esperados );

		$this->assertSame( $esperados, $this->listed_ids() );
		$this->assertFalse( has_filter( 'posts_where', array( $this->filter, 'gestion_posts_where' ) ), 'The clause is removed after the query.' );
	}

	/**
	 * Gestión without a scope still sees the pipeline, and nothing in draft.
	 */
	public function test_list_query_for_gestion_without_scope() {
		delete_user_meta( $this->gestion_id, Documentate_Scope_Filter::SCOPE_META_KEY );
		wp_set_current_user( $this->gestion_id );

		$esperados = array(
			$this->docs['gestion_b'],
			$this->docs['pending_none'],
			$this->docs['publish_b'],
			$this->docs['archived_b'],
		);
		sort( $esperados );

		$this->assertSame( $esperados, $this->listed_ids() );
	}

	/**
	 * The clause only touches the query it was bound to, and survives until that query runs.
	 */
	public function test_gestion_posts_where_ignores_other_queries() {
		wp_set_current_user( $this->gestion_id );
		$query = new WP_Query();
		$query->set( 'post_type', 'documentate_document' );
		$query->set( 'post_status', array( 'draft', 'en_gestion', 'pending', 'publish', 'archived' ) );
		$query->set( 'posts_per_page', -1 );
		$query->set( 'fields', 'ids' );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $query;
		$this->filter->filter_documents_by_scope( $query );
		$this->assertNotFalse( has_filter( 'posts_where', array( $this->filter, 'gestion_posts_where' ) ) );

		// A foreign query in between (another post type, or a document query that is not the list) passes untouched.
		$otra = new WP_Query();
		$otra->set( 'post_type', 'post' );
		$this->assertSame( ' AND 1=1', $this->filter->gestion_posts_where( ' AND 1=1', $otra ) );
		$this->assertNotFalse( has_filter( 'posts_where', array( $this->filter, 'gestion_posts_where' ) ), 'Still armed for the list query.' );

		$borradores = new WP_Query(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'posts_per_page' => -1,
				'fields' => 'ids',
			)
		);
		$todos = array_map( 'intval', $borradores->posts );
		sort( $todos );
		$this->assertSame( array( $this->docs['draft_a'], $this->docs['draft_b'] ), $todos, 'Not consumed by a query it was not bound to.' );
		$this->assertNotFalse( has_filter( 'posts_where', array( $this->filter, 'gestion_posts_where' ) ) );

		// The bound query still gets its clause, and the filter is gone afterwards.
		$esperados = array(
			$this->docs['draft_a'],
			$this->docs['gestion_b'],
			$this->docs['pending_none'],
			$this->docs['publish_b'],
			$this->docs['archived_b'],
		);
		sort( $esperados );
		$ids = array_map( 'intval', (array) $query->get_posts() );
		sort( $ids );
		$this->assertSame( $esperados, $ids );
		$this->assertFalse( has_filter( 'posts_where', array( $this->filter, 'gestion_posts_where' ) ) );
	}

	/**
	 * The view counters of a gestión user match the rows the list shows.
	 */
	public function test_view_counts_for_gestion() {
		wp_set_current_user( $this->gestion_id );

		$views = array();
		foreach ( array( 'all', 'mine', 'draft', 'en_gestion', 'pending', 'publish', 'archived', 'trash' ) as $key ) {
			$views[ $key ] = '<a href="#">' . $key . ' <span class="count">(99)</span></a>';
		}

		$result = $this->filter->filter_view_counts( $views );

		$this->assertSame( 4, $this->count_of( $result['all'] ), 'draft A + en_gestion B + pending + publish B (archived has its own view).' );
		$this->assertSame( 1, $this->count_of( $result['draft'] ) );
		$this->assertSame( 1, $this->count_of( $result['en_gestion'] ) );
		$this->assertSame( 1, $this->count_of( $result['pending'] ) );
		$this->assertSame( 1, $this->count_of( $result['publish'] ) );
		$this->assertSame( 1, $this->count_of( $result['archived'] ) );
		$this->assertArrayNotHasKey( 'mine', $result, 'The gestión user authored nothing.' );
		$this->assertArrayNotHasKey( 'trash', $result );
	}

	/**
	 * Counters for gestión without a scope only carry the pipeline.
	 */
	public function test_view_counts_for_gestion_without_scope() {
		delete_user_meta( $this->gestion_id, Documentate_Scope_Filter::SCOPE_META_KEY );
		wp_set_current_user( $this->gestion_id );

		$views = array(
			'all' => '<a href="#">all <span class="count">(99)</span></a>',
			'draft' => '<a href="#">draft <span class="count">(99)</span></a>',
			'archived' => '<a href="#">archived <span class="count">(99)</span></a>',
		);

		$result = $this->filter->filter_view_counts( $views );

		$this->assertSame( 3, $this->count_of( $result['all'] ) );
		$this->assertArrayNotHasKey( 'draft', $result );
		$this->assertSame( 1, $this->count_of( $result['archived'] ) );
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
