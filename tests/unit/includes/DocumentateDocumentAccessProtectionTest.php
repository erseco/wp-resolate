<?php
/**
 * Tests for Document Access Protection.
 *
 * Ensures that documentate_document posts and their comments are not accessible
 * to anonymous users or subscribers via web or REST API.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Document_Access_Protection.
 */
class DocumentateDocumentAccessProtectionTest extends WP_UnitTestCase {

	/**
	 * Protection instance.
	 *
	 * @var Documentate_Document_Access_Protection
	 */
	protected $protection;

	/**
	 * Administrator user ID.
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
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_user_id;

	/**
	 * Test document ID.
	 *
	 * @var int
	 */
	protected $document_id;

	/**
	 * Test regular post ID.
	 *
	 * @var int
	 */
	protected $regular_post_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Ensure CPT is registered.
		register_post_type(
			'documentate_document',
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		// Create test users.
		$this->admin_user_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create test document as admin.
		wp_set_current_user( $this->admin_user_id );
		$this->document_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Test Document',
				'post_status' => 'publish',
			)
		);

		// Create regular post for comparison.
		$this->regular_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);

		// Create protection instance.
		$this->protection = new Documentate_Document_Access_Protection();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// =========================================================================
	// USER ACCESS PERMISSION TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot access documents.
	 */
	public function test_anonymous_user_cannot_access() {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->protection->user_can_access() );
	}

	/**
	 * Test that subscribers cannot access documents.
	 */
	public function test_subscriber_cannot_access() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( $this->protection->user_can_access() );
	}

	/**
	 * Test that editors can access documents.
	 */
	public function test_editor_can_access() {
		wp_set_current_user( $this->editor_user_id );
		$this->assertTrue( $this->protection->user_can_access() );
	}

	/**
	 * Test that administrators can access documents.
	 */
	public function test_admin_can_access() {
		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( $this->protection->user_can_access() );
	}

	// =========================================================================
	// QUERY FILTERING TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot query documents directly.
	 *
	 * Using post_status => 'any' to test our protection, not just WP's defaults.
	 */
	public function test_anonymous_cannot_query_documents() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);

		$this->assertEmpty( $query->posts, 'Anonymous users should not get any documents from query.' );
	}

	/**
	 * Test that subscribers cannot query documents directly.
	 *
	 * Using post_status => 'any' to test our protection, not just WP's defaults.
	 */
	public function test_subscriber_cannot_query_documents() {
		wp_set_current_user( $this->subscriber_user_id );

		$query = new WP_Query(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);

		$this->assertEmpty( $query->posts, 'Subscribers should not get any documents from query.' );
	}

	/**
	 * Test that editors can query documents.
	 *
	 * Note: Documents without doc_type are forced to draft by workflow.
	 * We use post_status => 'any' to test our protection, not WP's default behavior.
	 */
	public function test_editor_can_query_documents() {
		wp_set_current_user( $this->editor_user_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertContains( $this->document_id, $query->posts, 'Editors should be able to query documents.' );
	}

	/**
	 * Test that administrators can query documents.
	 *
	 * Note: Documents without doc_type are forced to draft by workflow.
	 * We use post_status => 'any' to test our protection, not WP's default behavior.
	 */
	public function test_admin_can_query_documents() {
		wp_set_current_user( $this->admin_user_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertContains( $this->document_id, $query->posts, 'Admins should be able to query documents.' );
	}

	/**
	 * Test that anonymous users querying 'any' post type excludes documents.
	 */
	public function test_anonymous_any_query_excludes_documents() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertNotContains(
			$this->document_id,
			$query->posts,
			'Anonymous users querying "any" post type should not get documents.'
		);
	}

	/**
	 * Test that anonymous users can still query regular posts.
	 */
	public function test_anonymous_can_query_regular_posts() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);

		$this->assertContains(
			$this->regular_post_id,
			$query->posts,
			'Anonymous users should still be able to query regular posts.'
		);
	}

	// =========================================================================
	// DIRECT POST ACCESS TESTS
	// =========================================================================

	/**
	 * Test that get_post still returns document (needed for internal use).
	 *
	 * Note: get_post() bypasses WP_Query so it will return the document.
	 * The protection happens at template_redirect and REST API level.
	 */
	public function test_get_post_returns_document_for_internal_use() {
		wp_set_current_user( 0 );

		$post = get_post( $this->document_id );

		// get_post() should still work as it's needed internally.
		// Protection is at query/template level.
		$this->assertNotNull( $post );
		$this->assertEquals( $this->document_id, $post->ID );
	}

	// =========================================================================
	// COMMENTS PROTECTION TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot see comments on documents.
	 */
	public function test_anonymous_cannot_see_document_comments() {
		// Create a comment on the document as admin.
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// Switch to anonymous user.
		wp_set_current_user( 0 );

		// Query comments for this document.
		$comments = get_comments(
			array(
				'post_id' => $this->document_id,
			)
		);

		$this->assertEmpty( $comments, 'Anonymous users should not see comments on documents.' );
	}

	/**
	 * Test that subscribers cannot see comments on documents.
	 */
	public function test_subscriber_cannot_see_document_comments() {
		// Create a comment on the document as admin.
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// Switch to subscriber.
		wp_set_current_user( $this->subscriber_user_id );

		// Query comments for this document.
		$comments = get_comments(
			array(
				'post_id' => $this->document_id,
			)
		);

		$this->assertEmpty( $comments, 'Subscribers should not see comments on documents.' );
	}

	/**
	 * Whoever may edit the document reads its activity; whoever may not, does not.
	 *
	 * The gate is the document, not the capability to edit posts in general:
	 * every área author has edit_posts and none of them has any business
	 * reading the workflow events — which carry the reason of every return —
	 * of another área's document.
	 */
	public function test_document_comments_follow_the_document_permission() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// An editor outside the scope of the document cannot edit it.
		wp_set_current_user( $this->editor_user_id );
		$this->assertFalse( current_user_can( 'edit_post', $this->document_id ) );
		$this->assertEmpty(
			get_comments( array( 'post_id' => $this->document_id ) ),
			'A document nobody may edit hands over no activity.'
		);

		// Gestión documental may open every document that entered the pipeline
		// (a document with no type never leaves draft, so it gets one first).
		$type = wp_insert_term( 'Tipo comentarios ' . uniqid(), 'documentate_doc_type' );
		wp_set_object_terms( $this->document_id, array( (int) $type['term_id'] ), 'documentate_doc_type' );
		wp_set_current_user( $this->admin_user_id );
		wp_update_post(
			array(
				'ID' => $this->document_id,
				'post_status' => 'pending',
			)
		);
		( new WP_User( $this->editor_user_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		wp_set_current_user( $this->editor_user_id );

		$this->assertSame( 'pending', get_post_status( $this->document_id ) );
		$this->assertTrue( current_user_can( 'edit_post', $this->document_id ) );

		$comments = get_comments( array( 'post_id' => $this->document_id ) );
		$this->assertNotEmpty( $comments, 'Gestión documental reads the activity of the document.' );
		$this->assertEquals( $comment_id, $comments[0]->comment_ID );
	}

	/**
	 * A workflow event never turns up in a comment query that names no document.
	 *
	 * wp_dashboard_recent_comments() and the Recent Comments widget run exactly
	 * this query for anybody with edit_posts, so the return reasons written into
	 * the event text would otherwise be readable site-wide.
	 */
	public function test_events_are_absent_from_a_bare_comment_query() {
		wp_set_current_user( $this->admin_user_id );
		Documentate_Activity::record_event(
			$this->document_id,
			'devolvió el documento al área: «Falta el anexo firmado»',
			'Falta el anexo firmado'
		);
		$regular = wp_insert_comment(
			array(
				'comment_post_ID' => $this->regular_post_id,
				'comment_content' => 'Comentario de una entrada normal',
				'comment_approved' => 1,
			)
		);

		foreach ( array( $this->admin_user_id, $this->editor_user_id, $this->subscriber_user_id ) as $user_id ) {
			wp_set_current_user( $user_id );

			$contents = wp_list_pluck( get_comments( array( 'status' => 'approve' ) ), 'comment_content' );

			$this->assertNotContains(
				'devolvió el documento al área: «Falta el anexo firmado»',
				$contents,
				'The activity of a document never leaks into a general comment query.'
			);
			$this->assertContains( 'Comentario de una entrada normal', $contents );
		}

		wp_delete_comment( $regular, true );
	}

	/**
	 * Test that comments_open returns false for anonymous users on documents.
	 */
	public function test_comments_closed_for_anonymous_on_documents() {
		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be closed for anonymous users on documents.'
		);
	}

	/**
	 * Test that comments_open returns false for subscribers on documents.
	 */
	public function test_comments_closed_for_subscriber_on_documents() {
		wp_set_current_user( $this->subscriber_user_id );

		$this->assertFalse(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be closed for subscribers on documents.'
		);
	}

	/**
	 * Test that comments_open returns original value for editors on documents.
	 */
	public function test_comments_open_for_editor_on_documents() {
		wp_set_current_user( $this->editor_user_id );

		$this->assertTrue(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be open for editors on documents (if originally open).'
		);
	}

	/**
	 * Test that comments on regular posts are not affected.
	 */
	public function test_comments_on_regular_posts_unaffected() {
		wp_set_current_user( 0 );

		$this->assertTrue(
			$this->protection->filter_comments_open( true, $this->regular_post_id ),
			'Comments on regular posts should not be affected by document protection.'
		);
	}

	// =========================================================================
	// PROTECTED POST TYPES FILTER TESTS
	// =========================================================================

	/**
	 * Test that documentate_document is added to protected comment post types.
	 */
	public function test_document_added_to_protected_comment_types() {
		$protected_types = $this->protection->add_document_to_protected_types( array() );

		$this->assertContains(
			'documentate_document',
			$protected_types,
			'documentate_document should be in protected post types.'
		);
	}

	/**
	 * Test that existing protected types are preserved.
	 */
	public function test_existing_protected_types_preserved() {
		$existing_types  = array( 'documentate_task', 'some_other_type' );
		$protected_types = $this->protection->add_document_to_protected_types( $existing_types );

		$this->assertContains( 'documentate_task', $protected_types );
		$this->assertContains( 'some_other_type', $protected_types );
		$this->assertContains( 'documentate_document', $protected_types );
	}

	/**
	 * Test that duplicates are not added to protected types.
	 */
	public function test_no_duplicate_protected_types() {
		$existing_types  = array( 'documentate_document' );
		$protected_types = $this->protection->add_document_to_protected_types( $existing_types );

		$count = array_count_values( $protected_types );
		$this->assertEquals(
			1,
			$count['documentate_document'],
			'documentate_document should not be duplicated.'
		);
	}

	// =========================================================================
	// REST API PROTECTION TESTS
	// =========================================================================

	/**
	 * Test that REST API blocks anonymous access to document route.
	 */
	public function test_rest_api_blocks_anonymous_document_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API blocks subscriber access to document route.
	 */
	public function test_rest_api_blocks_subscriber_document_access() {
		wp_set_current_user( $this->subscriber_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API allows editor access to document route.
	 */
	public function test_rest_api_allows_editor_document_access() {
		wp_set_current_user( $this->editor_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Editors should not be blocked from document REST routes.' );
	}

	/**
	 * Test that REST API blocks anonymous access to single document by ID.
	 */
	public function test_rest_api_blocks_anonymous_single_document_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->document_id );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API does not block access to regular posts.
	 */
	public function test_rest_api_allows_regular_post_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->regular_post_id );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Regular posts should not be blocked.' );
	}

	/**
	 * Test REST post query filtering excludes documents for anonymous users.
	 */
	public function test_rest_post_query_excludes_documents_for_anonymous() {
		wp_set_current_user( 0 );

		$args = array(
			'post_type' => 'documentate_document',
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertArrayHasKey( 'post__in', $filtered_args );
		$this->assertEquals( array( 0 ), $filtered_args['post__in'] );
	}

	/**
	 * Test REST post query filtering excludes documents from array for anonymous users.
	 */
	public function test_rest_post_query_excludes_documents_from_array() {
		wp_set_current_user( 0 );

		$args = array(
			'post_type' => array( 'post', 'documentate_document', 'page' ),
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertIsArray( $filtered_args['post_type'] );
		$this->assertNotContains( 'documentate_document', $filtered_args['post_type'] );
		$this->assertContains( 'post', $filtered_args['post_type'] );
		$this->assertContains( 'page', $filtered_args['post_type'] );
	}

	/**
	 * Test REST post query filtering allows documents for editors.
	 */
	public function test_rest_post_query_allows_documents_for_editor() {
		wp_set_current_user( $this->editor_user_id );

		$args = array(
			'post_type' => 'documentate_document',
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertEquals( 'documentate_document', $filtered_args['post_type'] );
		$this->assertArrayNotHasKey( 'post__in', $filtered_args );
	}

	// =========================================================================
	// ADDITIONAL COVERAGE TESTS
	// =========================================================================

	/**
	 * Test constructor registers all hooks.
	 */
	public function test_constructor_registers_hooks() {
		$protection = new Documentate_Document_Access_Protection();

		$this->assertNotFalse( has_action( 'template_redirect', array( $protection, 'block_frontend_access' ) ) );
		$this->assertNotFalse( has_action( 'pre_get_posts', array( $protection, 'filter_queries' ) ) );
		$this->assertNotFalse( has_action( 'rest_api_init', array( $protection, 'register_rest_protection' ) ) );
		$this->assertNotFalse( has_filter( 'documentate/protected_comment_post_types', array( $protection, 'add_document_to_protected_types' ) ) );
		$this->assertNotFalse( has_filter( 'comments_open', array( $protection, 'filter_comments_open' ) ) );
		$this->assertNotFalse( has_filter( 'comments_pre_query', array( $protection, 'filter_comment_queries' ) ) );
	}

	/**
	 * Test filter_queries with array of post types removes documents.
	 */
	public function test_filter_queries_array_post_type() {
		wp_set_current_user( 0 );

		$query = new WP_Query();
		$query->set( 'post_type', array( 'post', 'documentate_document', 'page' ) );

		$this->protection->filter_queries( $query );

		$post_types = $query->get( 'post_type' );
		$this->assertNotContains( 'documentate_document', $post_types );
		$this->assertContains( 'post', $post_types );
	}

	/**
	 * Test filter_queries does nothing in admin context.
	 */
	public function test_filter_queries_admin_context() {
		wp_set_current_user( 0 );
		set_current_screen( 'edit-post' );

		$query = new WP_Query();
		$query->set( 'post_type', 'documentate_document' );

		$this->protection->filter_queries( $query );

		// In admin context, query should not be modified even for anonymous.
		$this->assertSame( 'documentate_document', $query->get( 'post_type' ) );

		set_current_screen( 'front' );
	}

	/**
	 * Test filter_queries does nothing for authorized users.
	 */
	public function test_filter_queries_authorized_user() {
		wp_set_current_user( $this->editor_user_id );

		$query = new WP_Query();
		$query->set( 'post_type', 'documentate_document' );

		$this->protection->filter_queries( $query );

		$this->assertSame( 'documentate_document', $query->get( 'post_type' ) );
	}

	/**
	 * Test get_public_post_types via reflection.
	 */
	public function test_get_public_post_types() {
		$ref = new ReflectionClass( $this->protection );
		$method = $ref->getMethod( 'get_public_post_types' );
		$method->setAccessible( true );

		$types = $method->invoke( $this->protection );

		$this->assertIsArray( $types );
		$this->assertNotContains( 'documentate_document', $types );
		$this->assertContains( 'post', $types );
	}

	/**
	 * Test register_rest_protection adds filters.
	 */
	public function test_register_rest_protection() {
		$this->protection->register_rest_protection();

		$this->assertNotFalse( has_filter( 'rest_pre_dispatch', array( $this->protection, 'block_rest_access' ) ) );
		$this->assertNotFalse( has_filter( 'rest_post_query', array( $this->protection, 'filter_rest_post_query' ) ) );
	}

	/**
	 * Test filter_comment_queries with general query adds clause filter.
	 */
	public function test_filter_comment_queries_general() {
		wp_set_current_user( 0 );

		$query = new WP_Comment_Query();
		$query->query_vars = array();

		$result = $this->protection->filter_comment_queries( null, $query );

		// Should return null (continue with query) but add the clause filter.
		$this->assertNull( $result );
		$this->assertNotFalse( has_filter( 'comments_clauses', array( $this->protection, 'exclude_document_comments_clause' ) ) );
	}

	/**
	 * Test filter_comment_queries for a user who may edit the document.
	 */
	public function test_filter_comment_queries_authorized() {
		wp_set_current_user( $this->admin_user_id );

		$query = new WP_Comment_Query();
		$query->query_vars = array( 'post_id' => $this->document_id );

		$result = $this->protection->filter_comment_queries( null, $query );

		// Should return null unchanged for authorized users.
		$this->assertNull( $result );

		// And an empty result for anybody else, whatever they may do elsewhere.
		wp_set_current_user( $this->editor_user_id );
		$this->assertSame( array(), $this->protection->filter_comment_queries( null, $query ) );
	}

	/**
	 * The site-wide comment feed never carries document comments or events.
	 */
	public function test_comment_feed_excludes_document_comments_and_events() {
		wp_set_current_user( $this->admin_user_id );
		$doc_comment = wp_insert_comment(
			array(
				'comment_post_ID'  => $this->document_id,
				'comment_content'  => 'Comentario en documento',
				'comment_approved' => 1,
				'user_id'          => $this->admin_user_id,
			)
		);
		$event = Documentate_Activity::record_event( $this->document_id, 'aprobó y publicó el documento' );
		$post_comment = wp_insert_comment(
			array(
				'comment_post_ID'  => $this->regular_post_id,
				'comment_content'  => 'Comentario en entrada',
				'comment_approved' => 1,
				'user_id'          => $this->admin_user_id,
			)
		);
		wp_set_current_user( 0 );

		$feed = new WP_Query(
			array(
				'feed'         => 'rss2',
				'withcomments' => 1,
			)
		);

		$ids = array_map( 'intval', wp_list_pluck( (array) $feed->comments, 'comment_ID' ) );
		$this->assertContains( $post_comment, $ids, 'Regular post comments stay in the feed.' );
		$this->assertNotContains( $doc_comment, $ids, 'Document comments must not leak into the feed.' );
		$this->assertNotContains( $event, $ids, 'Workflow events must not leak into the feed.' );
	}

	/**
	 * The comment feed of a single document is empty; other posts keep theirs.
	 */
	public function test_single_comment_feed_where_clause() {
		$query = new WP_Query();
		$query->is_singular = true;
		$query->posts = array( get_post( $this->document_id ) );
		$this->assertStringEndsWith( ' AND 1 = 0', $this->protection->exclude_documents_from_comment_feed( 'WHERE 1=1', $query ) );

		$query->posts = array( get_post( $this->regular_post_id ) );
		$this->assertSame( 'WHERE 1=1', $this->protection->exclude_documents_from_comment_feed( 'WHERE 1=1', $query ) );

		$this->assertNotFalse( has_filter( 'comment_feed_where', array( $this->protection, 'exclude_documents_from_comment_feed' ) ) );
	}

	/**
	 * Test exclude_document_comments_clause adds proper SQL.
	 */
	public function test_exclude_document_comments_clause() {
		$clauses = array(
			'join' => '',
			'where' => ' AND 1=1',
		);

		$result = $this->protection->exclude_document_comments_clause( $clauses );

		$this->assertStringContainsString( 'dap_posts', $result['join'] );
		$this->assertStringContainsString( 'documentate_document', $result['where'] );
	}

	/**
	 * Test exclude_document_comments_clause with existing join.
	 */
	public function test_exclude_document_comments_clause_existing_join() {
		global $wpdb;

		$clauses = array(
			'join' => " LEFT JOIN {$wpdb->posts} ON something",
			'where' => ' AND 1=1',
		);

		$result = $this->protection->exclude_document_comments_clause( $clauses );

		// Should not add duplicate posts join.
		$this->assertStringNotContainsString( 'dap_posts', $result['join'] );
	}

	/**
	 * Test REST API blocks with document ID in route.
	 */
	public function test_rest_api_blocks_document_with_subpath() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document/123' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test filter_rest_post_query with empty post_type.
	 */
	public function test_filter_rest_post_query_empty_type() {
		wp_set_current_user( 0 );

		$args = array();
		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		// Should pass through unchanged.
		$this->assertEmpty( $filtered_args );
	}

	/**
	 * Test constants are defined.
	 */
	public function test_class_constants() {
		$this->assertSame( 'documentate_document', Documentate_Document_Access_Protection::POST_TYPE );
		$this->assertSame( 'edit_posts', Documentate_Document_Access_Protection::REQUIRED_CAPABILITY );
	}
}
