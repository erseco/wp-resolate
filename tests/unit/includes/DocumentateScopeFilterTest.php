<?php
/**
 * Tests for Documentate_Scope_Filter.
 *
 * Verifies that non-admin users only see documents in their assigned scope
 * category (and descendants), while admins see all documents.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Scope_Filter
 */
class DocumentateScopeFilterTest extends WP_UnitTestCase {

	/**
	 * Scope filter instance.
	 *
	 * @var Documentate_Scope_Filter
	 */
	protected $filter;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_user_id;

	/**
	 * Parent category term ID.
	 *
	 * @var int
	 */
	protected $parent_cat_id;

	/**
	 * Child category term ID.
	 *
	 * @var int
	 */
	protected $child_cat_id;

	/**
	 * Other category term ID (outside scope).
	 *
	 * @var int
	 */
	protected $other_cat_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'documentate_document',
			array(
				'public'     => false,
				'taxonomies' => array( 'category' ),
			)
		);
		register_taxonomy_for_object_type( 'category', 'documentate_document' );

		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// The editor role carries the gestión capability; these tests model a
		// plain área user, so deny it for this user (gestión has its own tests).
		( new WP_User( $this->editor_user_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT, false );

		// Categories: parent -> child, and a separate other category.
		$parent = wp_insert_term( 'Scope Parent', 'category' );
		$child  = wp_insert_term( 'Scope Child', 'category', array( 'parent' => $parent['term_id'] ) );
		$other  = wp_insert_term( 'Other Category', 'category' );

		$this->parent_cat_id = $parent['term_id'];
		$this->child_cat_id  = $child['term_id'];
		$this->other_cat_id  = $other['term_id'];

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-scope-filter.php';
		$this->filter = new Documentate_Scope_Filter();

		// Set admin screen context so is_admin() returns true.
		set_current_screen( 'edit.php?post_type=documentate_document' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY );
		// Restore screen to avoid polluting other tests.
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Build a simulated main-query WP_Query object.
	 *
	 * Sets the global $wp_the_query so that WP_Query::is_main_query() returns
	 * true when called outside of the pre_get_posts hook.
	 *
	 * @param string $post_type Post type to query.
	 * @return WP_Query
	 */
	private function build_main_query( $post_type ) {
		$inner = new WP_Query();
		$inner->set( 'post_type', $post_type );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $inner;
		return $inner;
	}

	/**
	 * Helper: create a document in a category.
	 *
	 * @param int $cat_id Category term ID.
	 * @return int Post ID.
	 */
	private function create_document_in_category( $cat_id ) {
		wp_set_current_user( $this->admin_user_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Test Document',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, $cat_id, 'category' );
		return $post_id;
	}

	/**
	 * Test that filter registers pre_get_posts hook.
	 */
	public function test_filter_registers_hook() {
		$this->assertNotFalse(
			has_action( 'pre_get_posts', array( $this->filter, 'filter_documents_by_scope' ) )
		);
	}

	/**
	 * Test that admin user query is not modified (no tax_query or post__in added).
	 */
	public function test_admin_query_not_restricted() {
		wp_set_current_user( $this->admin_user_id );
		$inner = $this->build_main_query( 'documentate_document' );

		$this->filter->filter_documents_by_scope( $inner );

		$this->assertEmpty( $inner->get( 'tax_query' ), 'Admin query should not have tax_query added.' );
		$this->assertEmpty( $inner->get( 'post__in' ), 'Admin query should not have post__in restriction.' );
	}

	/**
	 * Test that non-admin with no scope assigned gets post__in = [0] (sees nothing).
	 */
	public function test_non_admin_no_scope_gets_empty_result() {
		wp_set_current_user( $this->editor_user_id );
		// No scope meta set.
		$inner = $this->build_main_query( 'documentate_document' );

		$this->filter->filter_documents_by_scope( $inner );

		$this->assertSame(
			array( 0 ),
			$inner->get( 'post__in' ),
			'User with no scope should get post__in set to [0].'
		);
	}

	/**
	 * Test that non-admin with scope gets a tax_query including the scope term.
	 */
	public function test_non_admin_with_scope_gets_tax_query() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		$inner = $this->build_main_query( 'documentate_document' );

		$this->filter->filter_documents_by_scope( $inner );

		$tax_query = $inner->get( 'tax_query' );
		$this->assertNotEmpty( $tax_query, 'tax_query should be set for scoped user.' );
		$this->assertSame( 'category', $tax_query[0]['taxonomy'] );
		$this->assertContains( $this->parent_cat_id, $tax_query[0]['terms'] );
	}

	/**
	 * Test that descendant terms are included in the scope tax_query.
	 */
	public function test_scope_includes_descendant_terms() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		$inner = $this->build_main_query( 'documentate_document' );

		$this->filter->filter_documents_by_scope( $inner );

		$tax_query = $inner->get( 'tax_query' );
		$this->assertContains(
			$this->child_cat_id,
			$tax_query[0]['terms'],
			'Descendant term should be included in scope.'
		);
	}

	/**
	 * Test that non-main queries are not filtered.
	 */
	public function test_non_main_query_not_filtered() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		// Build a query that is NOT the main query.
		$inner = new WP_Query();
		$inner->set( 'post_type', 'documentate_document' );
		// Do NOT set $GLOBALS['wp_the_query'] = $inner, so is_main_query() returns false.

		$this->filter->filter_documents_by_scope( $inner );

		$tax_query = $inner->get( 'tax_query' );
		$this->assertEmpty( $tax_query, 'Non-main queries should not be filtered.' );
	}

	/**
	 * A brand-new document stub (auto-draft) has no category yet, and must not
	 * be blocked or scoped users could never save a new document.
	 */
	public function test_scoped_user_can_access_uncategorized_auto_draft() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Auto Draft',
				'post_status' => 'auto-draft',
			)
		);

		$this->assertTrue(
			$this->filter->user_can_access_document( $post_id, $this->editor_user_id ),
			'Scoped user should be able to access a new auto-draft without categories.'
		);
		$this->assertTrue(
			user_can( $this->editor_user_id, 'edit_post', $post_id ),
			'Scoped editor should keep edit_post on a new auto-draft.'
		);
	}

	/**
	 * An uncategorized document that already left auto-draft stays out of scope.
	 */
	public function test_scoped_user_cannot_access_uncategorized_draft() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Uncategorized Draft',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, array(), 'category' );

		$this->assertFalse(
			$this->filter->user_can_access_document( $post_id, $this->editor_user_id )
		);
	}

	/**
	 * Saving a document without a category as a scoped user assigns the user's
	 * scope category, so the document stays visible and editable to them.
	 */
	public function test_save_without_category_assigns_scope_category() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor Draft',
				'post_status' => 'draft',
			)
		);

		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertContains(
			$this->parent_cat_id,
			$terms,
			'Scoped user saves should fall back to the scope category.'
		);
		$this->assertTrue(
			$this->filter->user_can_access_document( $post_id, $this->editor_user_id ),
			'The document should remain inside the editor scope after saving.'
		);
	}

	/**
	 * A category chosen explicitly on save is never overridden by the scope.
	 */
	public function test_save_with_explicit_category_is_not_overridden() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'documentate_document',
				'post_title'    => 'Categorized Draft',
				'post_status'   => 'draft',
				'post_category' => array( $this->child_cat_id ),
			)
		);

		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->child_cat_id, $terms );
		$this->assertNotContains(
			$this->parent_cat_id,
			$terms,
			'The scope fallback must not run when a category was selected.'
		);
	}

	/**
	 * Administrators are never forced into a scope category.
	 */
	public function test_admin_save_without_category_is_untouched() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Admin Draft',
				'post_status' => 'draft',
			)
		);

		$terms = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$this->assertEmpty( $terms, 'Admin documents should keep no category unless chosen.' );
	}

	/**
	 * Test that queries for a different post type are not filtered.
	 */
	public function test_other_post_type_query_not_filtered() {
		update_user_meta( $this->editor_user_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->parent_cat_id );
		wp_set_current_user( $this->editor_user_id );

		$inner = $this->build_main_query( 'post' );

		$this->filter->filter_documents_by_scope( $inner );

		$tax_query = $inner->get( 'tax_query' );
		$this->assertEmpty( $tax_query, 'Queries for other post types should not be filtered.' );
	}
}
