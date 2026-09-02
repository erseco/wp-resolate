<?php
/**
 * Tests for scope-consistent admin list counters.
 *
 * Verifies that the documentate_document admin "view" counters (All, Mine,
 * Published, Drafts, Pending, ...) are calculated with the SAME scope
 * visibility rules used by the admin list query, so the counters and the
 * listed rows always match for scoped non-administrator users.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Scope_Filter
 */
class DocumentateScopeCountsTest extends WP_UnitTestCase {

	/**
	 * Scope filter instance.
	 *
	 * @var Documentate_Scope_Filter
	 */
	protected $filter;

	/**
	 * Admin user ID (also used as the "other" author).
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Scoped editor user ID.
	 *
	 * @var int
	 */
	protected $editor_id;

	/**
	 * Scope parent category term ID (assigned to the editor).
	 *
	 * @var int
	 */
	protected $cat_a;

	/**
	 * Scope child category term ID (descendant of cat_a).
	 *
	 * @var int
	 */
	protected $cat_a_child;

	/**
	 * Out-of-scope category term ID.
	 *
	 * @var int
	 */
	protected $cat_b;

	/**
	 * Document type term ID (classification, required to hold non-draft status).
	 *
	 * @var int
	 */
	protected $doc_type_id;

	/**
	 * Set up fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-scope-filter.php';
		$this->filter = new Documentate_Scope_Filter();

		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		// The editor role carries the gestión capability; these tests model a
		// plain área user, so deny it for this user (gestión has its own tests).
		( new WP_User( $this->editor_id ) )->add_cap( Documentate_Roles::CAP_GESTION, false );

		$parent = wp_insert_term( 'Scope A', 'category' );
		$child  = wp_insert_term( 'Scope A Child', 'category', array( 'parent' => $parent['term_id'] ) );
		$other  = wp_insert_term( 'Scope B', 'category' );

		$this->cat_a       = (int) $parent['term_id'];
		$this->cat_a_child = (int) $child['term_id'];
		$this->cat_b       = (int) $other['term_id'];

		$doc_type          = wp_insert_term( 'Resolution', 'documentate_doc_type' );
		$this->doc_type_id = (int) $doc_type['term_id'];

		// Build the data set (see assertions for the expected scoped counts):
		//   In scope (A or A-child):
		$this->make_doc( 'publish', $this->admin_id, $this->cat_a );        // 1
		$this->make_doc( 'draft', $this->editor_id, $this->cat_a );         // 2 (editor)
		$this->make_doc( 'pending', $this->admin_id, $this->cat_a_child );  // 3
		$this->make_doc( 'private', $this->editor_id, $this->cat_a );       // 4 (editor)
		//   Out of scope (B / uncategorized) -- some authored by the editor:
		$this->make_doc( 'publish', $this->admin_id, $this->cat_b );        // 5
		$this->make_doc( 'draft', $this->editor_id, $this->cat_b );         // 6 (editor, out of scope)
		$this->make_doc( 'draft', $this->editor_id, 0 );                    // 7 (editor, uncategorized)
		$this->make_doc( 'publish', $this->admin_id, 0 );                   // 8 (uncategorized)

		set_current_screen( 'edit-documentate_document' );
	}

	/**
	 * Tear down fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Status set that the default "All"/"Mine" admin list views display.
	 *
	 * @var string[]
	 */
	private const ALL_VIEW_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'en_gestion' );

	/**
	 * Create a documentate_document with a fixed status, author and category.
	 *
	 * Assigns a document type so the workflow does not force the status to
	 * draft for unclassified documents.
	 *
	 * @param string $status Post status.
	 * @param int    $author Author user ID.
	 * @param int    $cat_id Category term ID (0 = uncategorized).
	 * @return int Post ID.
	 */
	private function make_doc( $status, $author, $cat_id ) {
		$previous = get_current_user_id();
		wp_set_current_user( $this->admin_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Doc ' . $status . '-' . $author . '-' . $cat_id,
				'post_status' => $status,
				'post_author' => $author,
				'tax_input'   => array( 'documentate_doc_type' => array( $this->doc_type_id ) ),
			)
		);

		if ( $cat_id ) {
			wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		}

		wp_set_current_user( $previous );

		// Guard: the fixture must really have the requested status, otherwise
		// the counter assertions below would be meaningless.
		$this->assertSame( $status, get_post_status( $post_id ), 'Fixture status was altered on insert.' );

		return (int) $post_id;
	}

	/**
	 * Extract the integer inside the `<span class="count">(N)</span>` of a view link.
	 *
	 * @param string $view View link HTML.
	 * @return int|null
	 */
	private function extract_count( $view ) {
		if ( preg_match( '#<span class="count">\((\d+)\)</span>#', $view, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Build a simulated main-query WP_Query object.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Query
	 */
	private function build_main_query( $post_type ) {
		$query = new WP_Query();
		$query->set( 'post_type', $post_type );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $query;
		return $query;
	}

	/**
	 * The "All" counter counts only documents inside the user's scope.
	 */
	public function test_all_count_matches_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$count = $this->filter->count_visible_documents( self::ALL_VIEW_STATUSES );

		$this->assertSame( 4, $count, 'Scoped All count should include only in-scope documents.' );
	}

	/**
	 * The scoped count equals the number of rows the equivalent list query returns.
	 */
	public function test_count_matches_actual_list_query() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$count = $this->filter->count_visible_documents( self::ALL_VIEW_STATUSES );

		// Reproduce the actual scoped list query independently.
		$list = new WP_Query(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => self::ALL_VIEW_STATUSES,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'category',
						'field'            => 'term_id',
						'terms'            => array_merge(
							array( $this->cat_a ),
							get_term_children( $this->cat_a, 'category' )
						),
						'include_children' => false,
					),
				),
			)
		);

		$this->assertSame( count( $list->posts ), $count, 'Counter must equal the scoped list rows.' );
	}

	/**
	 * The "Mine" counter respects scope: documents the editor authored OUTSIDE
	 * their scope must not be counted (regression for "Mine (N) shows 0 rows").
	 */
	public function test_mine_count_respects_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$mine = $this->filter->count_visible_documents( self::ALL_VIEW_STATUSES, $this->editor_id );

		// The editor authored docs 2,4 (in scope) and 6,7 (out of scope).
		$this->assertSame( 2, $mine, 'Mine must only count in-scope documents authored by the user.' );
	}

	/**
	 * Draft and pending counters are scope-consistent.
	 */
	public function test_status_counts_respect_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$this->assertSame( 1, $this->filter->count_visible_documents( 'draft' ), 'Scoped Drafts.' );
		$this->assertSame( 1, $this->filter->count_visible_documents( 'pending' ), 'Scoped Pending.' );
		$this->assertSame( 1, $this->filter->count_visible_documents( 'publish' ), 'Scoped Published.' );
	}

	/**
	 * A user with no scope assigned sees (and counts) nothing.
	 */
	public function test_no_scope_counts_zero() {
		wp_set_current_user( $this->editor_id );

		$this->assertSame( 0, $this->filter->count_visible_documents( self::ALL_VIEW_STATUSES ) );
	}

	/**
	 * Administrators are unrestricted: counts cover every document.
	 */
	public function test_admin_counts_unrestricted() {
		wp_set_current_user( $this->admin_id );

		$this->assertSame( 8, $this->filter->count_visible_documents( self::ALL_VIEW_STATUSES ) );
	}

	/**
	 * The views filter rewrites each counter to its scoped value.
	 */
	public function test_filter_view_counts_rewrites_counts() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );
		$_GET = array();

		$views = array(
			'all'     => '<a href="edit.php?post_type=documentate_document" class="current">All <span class="count">(8)</span></a>',
			'mine'    => '<a href="edit.php?post_type=documentate_document&author=' . $this->editor_id . '">Mine <span class="count">(4)</span></a>',
			'publish' => '<a href="edit.php?post_status=publish">Published <span class="count">(3)</span></a>',
			'draft'   => '<a href="edit.php?post_status=draft">Drafts <span class="count">(3)</span></a>',
			'pending' => '<a href="edit.php?post_status=pending">Pending <span class="count">(1)</span></a>',
		);

		$result = $this->filter->filter_view_counts( $views );

		$this->assertSame( 4, $this->extract_count( $result['all'] ) );
		$this->assertSame( 2, $this->extract_count( $result['mine'] ) );
		$this->assertSame( 1, $this->extract_count( $result['publish'] ) );
		$this->assertSame( 1, $this->extract_count( $result['draft'] ) );
		$this->assertSame( 1, $this->extract_count( $result['pending'] ) );
	}

	/**
	 * Status views that drop to zero under scope are removed (mirroring how
	 * WordPress hides empty status filters), while "All" is kept.
	 */
	public function test_filter_view_counts_drops_empty_status_views() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );
		$_GET = array();

		$views = array(
			'all'    => '<a href="edit.php">All <span class="count">(8)</span></a>',
			'future' => '<a href="edit.php?post_status=future">Scheduled <span class="count">(5)</span></a>',
		);

		$result = $this->filter->filter_view_counts( $views );

		$this->assertArrayHasKey( 'all', $result );
		$this->assertArrayNotHasKey( 'future', $result, 'Empty status view should be removed.' );
	}

	/**
	 * Administrators keep WordPress' native counters untouched.
	 */
	public function test_filter_view_counts_leaves_admin_untouched() {
		wp_set_current_user( $this->admin_id );

		$views = array(
			'all'   => '<a href="edit.php">All <span class="count">(8)</span></a>',
			'draft' => '<a href="edit.php?post_status=draft">Drafts <span class="count">(3)</span></a>',
		);

		$this->assertSame( $views, $this->filter->filter_view_counts( $views ) );
	}

	/**
	 * An explicit category filter narrows WITHIN the scope instead of replacing
	 * it: the existing tax_query clause is preserved and AND-combined with the
	 * scope clause.
	 */
	public function test_explicit_category_narrows_within_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$query = $this->build_main_query( 'documentate_document' );
		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => array( $this->cat_b ),
				),
			)
		);

		$this->filter->filter_documents_by_scope( $query );

		$tax_query = $query->get( 'tax_query' );

		$this->assertSame( 'AND', $tax_query['relation'], 'Scope and explicit filter must be AND-combined.' );

		$terms_seen = array();
		foreach ( $tax_query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}
			$terms_seen[] = $clause['terms'];
		}

		// One clause keeps the explicit category (B); the other adds the scope (A).
		$flat = array_merge( ...$terms_seen );
		$this->assertContains( $this->cat_b, $flat, 'Explicit category filter must be preserved.' );
		$this->assertContains( $this->cat_a, $flat, 'Scope restriction must be applied.' );
	}

	/**
	 * The exposed status constant matches the documented "All" view status set.
	 */
	public function test_all_list_statuses_constant() {
		$this->assertSame(
			array( 'publish', 'future', 'draft', 'pending', 'private', 'en_gestion' ),
			Documentate_Scope_Filter::ALL_LIST_STATUSES
		);
	}

	/**
	 * Object-level access: editor may open in-scope documents and not out-of-scope ones.
	 */
	public function test_user_can_access_document_respects_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );

		$in_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_object_terms( $in_scope, array( $this->cat_a ), 'category' );

		$out_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_object_terms( $out_scope, array( $this->cat_b ), 'category' );

		$this->assertTrue(
			$this->filter->user_can_access_document( $in_scope, $this->editor_id ),
			'In-scope document should be accessible.'
		);
		$this->assertFalse(
			$this->filter->user_can_access_document( $out_scope, $this->editor_id ),
			'Out-of-scope document must be denied.'
		);
		$this->assertTrue(
			$this->filter->user_can_access_document( $out_scope, $this->admin_id ),
			'Administrators remain unrestricted.'
		);
	}

	/**
	 * map_meta_cap must deny edit_post for out-of-scope documents.
	 */
	public function test_map_meta_cap_denies_out_of_scope_edit() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$out_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_object_terms( $out_scope, array( $this->cat_b ), 'category' );

		$this->assertFalse(
			current_user_can( 'edit_post', $out_scope ),
			'Editor must not edit_post out-of-scope documents by ID.'
		);
	}

	/**
	 * map_meta_cap also blocks delete_post / read_post outside scope.
	 */
	public function test_map_meta_cap_denies_delete_and_read_out_of_scope() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$out_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_object_terms( $out_scope, array( $this->cat_b ), 'category' );

		$this->assertFalse( current_user_can( 'delete_post', $out_scope ) );
		$this->assertFalse( current_user_can( 'read_post', $out_scope ) );
	}

	/**
	 * map_meta_cap leaves unrelated capabilities untouched.
	 */
	public function test_map_meta_cap_ignores_unrelated_caps() {
		$caps = array( 'edit_posts' );
		$result = $this->filter->map_meta_cap_scope( $caps, 'edit_posts', $this->editor_id, array() );
		$this->assertSame( $caps, $result );

		$result = $this->filter->map_meta_cap_scope( $caps, 'edit_post', $this->editor_id, array() );
		$this->assertSame( $caps, $result, 'Empty post ID must not add do_not_allow.' );
	}

	/**
	 * Documents without a category are out of every non-admin scope.
	 */
	public function test_user_can_access_document_requires_category() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );

		$no_cat = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		// Ensure no categories.
		wp_set_object_terms( $no_cat, array(), 'category' );

		$this->assertFalse(
			$this->filter->user_can_access_document( $no_cat, $this->editor_id )
		);
		$this->assertFalse(
			$this->filter->user_can_access_document( 0, $this->editor_id )
		);

		// Non-documentate posts are not gated by scope.
		$regular = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$this->assertTrue(
			$this->filter->user_can_access_document( $regular, $this->editor_id )
		);
	}

	/**
	 * Editor with no scope assigned can access nothing.
	 */
	public function test_user_with_empty_scope_sees_nothing() {
		delete_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY );

		$in_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $in_scope, array( $this->cat_a ), 'category' );

		$this->assertFalse(
			$this->filter->user_can_access_document( $in_scope, $this->editor_id )
		);
		$this->assertSame( array(), $this->filter->get_scope_term_ids( $this->editor_id ) );
	}

	/**
	 * In-scope documents remain editable via map_meta_cap.
	 */
	public function test_map_meta_cap_allows_in_scope_edit() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );

		$in_scope = self::factory()->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_object_terms( $in_scope, array( $this->cat_a ), 'category' );

		$this->assertTrue(
			current_user_can( 'edit_post', $in_scope ),
			'Editor must retain edit_post for in-scope documents.'
		);
	}

	/**
	 * The view counters are computed with a single grouped query rather than one
	 * WP_Query per view (regression guard against the previous N+1 pattern), and
	 * the resulting counts remain correct.
	 */
	public function test_filter_view_counts_uses_single_grouped_query() {
		update_user_meta( $this->editor_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_a );
		wp_set_current_user( $this->editor_id );
		$_GET = array();

		$views = array(
			'all'     => '<a href="edit.php" class="current">All <span class="count">(8)</span></a>',
			'mine'    => '<a href="edit.php?author=' . $this->editor_id . '">Mine <span class="count">(4)</span></a>',
			'publish' => '<a href="edit.php?post_status=publish">Published <span class="count">(3)</span></a>',
			'future'  => '<a href="edit.php?post_status=future">Scheduled <span class="count">(0)</span></a>',
			'draft'   => '<a href="edit.php?post_status=draft">Drafts <span class="count">(3)</span></a>',
			'pending' => '<a href="edit.php?post_status=pending">Pending <span class="count">(1)</span></a>',
			'private' => '<a href="edit.php?post_status=private">Private <span class="count">(1)</span></a>',
			'trash'   => '<a href="edit.php?post_status=trash">Trash <span class="count">(0)</span></a>',
		);

		// Warm the term-hierarchy cache so we measure only the counting cost.
		$this->filter->filter_view_counts( $views );

		$before  = get_num_queries();
		$result  = $this->filter->filter_view_counts( $views );
		$queries = get_num_queries() - $before;

		// A single grouped query replaces the previous one-query-per-view pattern
		// (which issued ~8 COUNT queries for this view set).
		$this->assertLessThanOrEqual( 2, $queries, 'Scoped counters must not issue a query per view.' );

		// Counts remain correct after the optimization.
		$this->assertSame( 4, $this->extract_count( $result['all'] ) );
		$this->assertSame( 2, $this->extract_count( $result['mine'] ) );
		$this->assertSame( 1, $this->extract_count( $result['publish'] ) );
		$this->assertSame( 1, $this->extract_count( $result['draft'] ) );
		$this->assertSame( 1, $this->extract_count( $result['pending'] ) );
		$this->assertSame( 1, $this->extract_count( $result['private'] ) );
	}
}
