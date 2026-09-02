<?php
/**
 * Tests for document workflow status control.
 *
 * Tests the workflow restrictions:
 * - Force draft when no doc_type assigned
 * - Editors can only save as draft/pending (cannot publish)
 * - Admins have full control
 * - Published documents locked for non-admins
 * - Pending documents locked for non-admins
 * - Unified Document Management meta box
 *
 * @package Documentate
 */

class DocumentateWorkflowTest extends WP_UnitTestCase {

	/**
	 * Workflow handler instance.
	 *
	 * @var Documentate_Workflow
	 */
	protected $workflow;

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
	 * Document type term ID.
	 *
	 * @var int
	 */
	protected $doc_type_id;

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Register post type and taxonomy.
		register_post_type(
			'documentate_document',
			array(
				'public'       => false,
				'map_meta_cap' => true,
			)
		);
		register_taxonomy(
			'documentate_doc_type',
			'documentate_document',
			array( 'public' => false )
		);

		// Create users.
		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Set admin user before creating doc_type.
		wp_set_current_user( $this->admin_user_id );

		// Create a document type using WordPress core function.
		$term_result = wp_insert_term( 'Test Resolution', 'documentate_doc_type' );

		if ( is_wp_error( $term_result ) ) {
			// Term might already exist, get existing term.
			$existing = get_term_by( 'name', 'Test Resolution', 'documentate_doc_type' );
			$this->doc_type_id = $existing ? $existing->term_id : 0;
		} else {
			$this->doc_type_id = $term_result['term_id'];
		}

		// Reset current user for tests.
		wp_set_current_user( 0 );

		// Initialize workflow handler.
		$this->workflow = new Documentate_Workflow();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to publish.
	 */
	public function test_no_doc_type_forces_draft_on_publish() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to set pending.
	 */
	public function test_no_doc_type_forces_draft_on_pending() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'pending',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to set private.
	 */
	public function test_no_doc_type_forces_draft_on_private() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'private',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type can be saved as draft.
	 */
	public function test_no_doc_type_allows_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should remain draft.' );
	}

	/**
	 * Test that admin can publish document with doc_type.
	 */
	public function test_admin_can_publish_with_doc_type() {
		wp_set_current_user( $this->admin_user_id );

		// Create document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document with type',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Now try to publish.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Admin should be able to publish document with doc_type.' );
	}

	/**
	 * Test that editor cannot publish (forced to pending).
	 */
	public function test_editor_cannot_publish_forced_to_pending() {
		wp_set_current_user( $this->editor_user_id );

		// Create document as draft first.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Try to publish as editor.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should not be able to publish; status should be pending.' );
	}

	/**
	 * Test that editor cannot set private status (forced to pending).
	 */
	public function test_editor_cannot_set_private_forced_to_pending() {
		wp_set_current_user( $this->editor_user_id );

		// Create document as draft first.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Try to set private as editor.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'private',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should not be able to set private; status should be pending.' );
	}

	/**
	 * Test that editor can save as draft.
	 */
	public function test_editor_can_save_draft() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor draft document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Update and keep as draft.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => 'Updated title',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Editor should be able to save as draft.' );
	}

	/**
	 * Test that editor can save as pending.
	 */
	public function test_editor_can_save_pending() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor pending document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Change to pending.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should be able to save as pending.' );
	}

	/**
	 * Test that admin can revert published document to draft.
	 */
	public function test_admin_can_revert_published_to_draft() {
		wp_set_current_user( $this->admin_user_id );

		// Create and publish document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Published document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type and publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Verify it's published.
		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status );

		// Now revert to draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Admin should be able to revert published document to draft.' );
	}

	/**
	 * Test that editor cannot modify published document status.
	 */
	public function test_editor_cannot_modify_published_document() {
		// First, admin creates and publishes document.
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Published document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type and publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Verify it's published.
		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status );

		// Now switch to editor and try to modify.
		wp_set_current_user( $this->editor_user_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Editor should not be able to modify published document status.' );
	}

	/**
	 * Non-admins must not change title/content of published documents (server-side).
	 */
	public function test_editor_cannot_change_published_document_content() {
		global $wpdb;

		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Original title',
				'post_status' => 'draft',
			)
		);
		$this->assertNotWPError( $post_id );

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Seed known content without going through the compose filter.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'   => 'Original title',
				'post_content' => 'Original content',
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		wp_set_current_user( $this->editor_user_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Hijacked title',
				'post_content' => 'Hijacked content',
				'post_status'  => 'publish',
			)
		);

		$stored = get_post( $post_id );
		$this->assertSame( 'Original title', $stored->post_title, 'Published title must stay frozen for non-admins.' );
		$this->assertSame( 'Original content', $stored->post_content, 'Published content must stay frozen for non-admins.' );
		$this->assertSame( 'publish', $stored->post_status );
	}

	/**
	 * status_locks_content_for_non_admins covers publish/pending/archived only.
	 */
	public function test_status_locks_content_for_non_admins() {
		$this->assertTrue( Documentate_Workflow::status_locks_content_for_non_admins( 'publish' ) );
		$this->assertTrue( Documentate_Workflow::status_locks_content_for_non_admins( 'pending' ) );
		$this->assertTrue( Documentate_Workflow::status_locks_content_for_non_admins( 'archived' ) );
		$this->assertFalse( Documentate_Workflow::status_locks_content_for_non_admins( 'draft' ) );
		$this->assertFalse( Documentate_Workflow::status_locks_content_for_non_admins( 'auto-draft' ) );
	}

	/**
	 * current_user_can_modify_document is true for admins and unlocked drafts.
	 */
	public function test_current_user_can_modify_document() {
		wp_set_current_user( $this->admin_user_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Mod check',
				'post_status' => 'draft',
			)
		);
		$this->assertNotWPError( $post_id );
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		$this->assertTrue( Documentate_Workflow::current_user_can_modify_document( $post_id ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Admin can still modify published docs.
		$this->assertTrue( Documentate_Workflow::current_user_can_modify_document( $post_id ) );

		wp_set_current_user( $this->editor_user_id );
		$this->assertFalse(
			Documentate_Workflow::current_user_can_modify_document( $post_id ),
			'Non-admin cannot modify published document content.'
		);

		// Non-documentate posts are unrestricted by this helper.
		$regular = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular',
				'post_status' => 'publish',
			)
		);
		$this->assertTrue( Documentate_Workflow::current_user_can_modify_document( $regular ) );
	}

	/**
	 * freeze_locked_document_data early-returns for admins and wrong post types.
	 */
	public function test_freeze_locked_document_data_early_returns() {
		wp_set_current_user( $this->admin_user_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Freeze early',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$data = array(
			'post_type'    => 'documentate_document',
			'post_title'   => 'Changed by admin',
			'post_content' => 'admin content',
			'post_excerpt' => '',
			'post_status'  => 'publish',
			'post_name'    => 'freeze-early',
			'post_author'  => (string) $this->admin_user_id,
		);

		// Admin is unrestricted.
		$result = $this->workflow->freeze_locked_document_data( $data, array( 'ID' => $post_id ) );
		$this->assertSame( 'Changed by admin', $result['post_title'] );

		// Wrong post type is ignored.
		$data['post_type'] = 'post';
		wp_set_current_user( $this->editor_user_id );
		$result = $this->workflow->freeze_locked_document_data( $data, array( 'ID' => $post_id ) );
		$this->assertSame( 'Changed by admin', $result['post_title'] );

		// Missing post ID is ignored.
		$data['post_type'] = 'documentate_document';
		$result = $this->workflow->freeze_locked_document_data( $data, array( 'ID' => 0 ) );
		$this->assertSame( 'Changed by admin', $result['post_title'] );
	}

	/**
	 * freeze_locked_document_data freezes pending documents for non-admins.
	 */
	public function test_freeze_locked_document_data_pending() {
		global $wpdb;

		wp_set_current_user( $this->admin_user_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Pending original',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		// Seed title/content outside the compose filter.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'   => 'Pending original',
				'post_content' => 'Pending body',
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		wp_set_current_user( $this->editor_user_id );
		$data = array(
			'post_type'    => 'documentate_document',
			'post_title'   => wp_slash( 'Hijacked pending' ),
			'post_content' => wp_slash( 'Hijacked body' ),
			'post_excerpt' => '',
			'post_status'  => 'pending',
			'post_name'    => 'pending-original',
			'post_author'  => (string) $this->admin_user_id,
		);

		$result = $this->workflow->freeze_locked_document_data( $data, array( 'ID' => $post_id ) );
		$this->assertSame( 'pending', $result['post_status'] );
		$this->assertSame( wp_slash( 'Pending original' ), $result['post_title'] );
		$this->assertSame( wp_slash( 'Pending body' ), $result['post_content'] );
	}

	/**
	 * freeze_locked_document_data freezes archived documents for non-admins.
	 */
	public function test_freeze_locked_document_data_archived() {
		global $wpdb;

		wp_set_current_user( $this->admin_user_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Archived original',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_title'   => 'Archived original',
				'post_content' => 'Archived body',
			),
			array( 'ID' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		clean_post_cache( $post_id );

		wp_set_current_user( $this->editor_user_id );
		$data = array(
			'post_type'    => 'documentate_document',
			'post_title'   => wp_slash( 'Hijacked archived' ),
			'post_content' => wp_slash( 'Hijacked archived body' ),
			'post_excerpt' => '',
			'post_status'  => 'archived',
			'post_name'    => 'archived-original',
			'post_author'  => (string) $this->admin_user_id,
		);

		$result = $this->workflow->freeze_locked_document_data( $data, array( 'ID' => $post_id ) );
		$this->assertSame( 'archived', $result['post_status'] );
		$this->assertSame( wp_slash( 'Archived original' ), $result['post_title'] );
	}

	/**
	 * Test that other post types are not affected by workflow.
	 */
	public function test_other_post_types_not_affected() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular post',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Regular posts should not be affected by workflow restrictions.' );
	}

	/**
	 * Test that workflow hooks are registered.
	 */
	public function test_workflow_hooks_registered() {
		$this->assertNotFalse(
			has_filter( 'wp_insert_post_data', array( $this->workflow, 'control_post_status' ) ),
			'wp_insert_post_data hook should be registered.'
		);
	}

	/**
	 * Test display_workflow_notices on wrong screen.
	 */
	public function test_display_workflow_notices_wrong_screen() {
		set_current_screen( 'edit-post' );

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test display_workflow_notices with no_classification reason.
	 */
	public function test_display_workflow_notices_no_classification() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'no_classification',
				'original_status' => 'publish',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'tipo de documento', $output );
	}

	/**
	 * Test display_workflow_notices with editor_no_publish reason.
	 */
	public function test_display_workflow_notices_editor_no_publish() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'editor_no_publish',
				'original_status' => 'publish',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'revisión', $output );
	}

	/**
	 * Test display_workflow_notices with published_locked reason.
	 */
	public function test_display_workflow_notices_published_locked() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'published_locked',
				'original_status' => 'draft',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'administración', $output );
	}

	/**
	 * Test enqueue_workflow_assets on wrong hook.
	 */
	public function test_enqueue_workflow_assets_wrong_hook() {
		wp_dequeue_script( 'documentate-workflow' );
		wp_dequeue_style( 'documentate-workflow' );

		$this->workflow->enqueue_workflow_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test enqueue_workflow_assets on wrong post type.
	 */
	public function test_enqueue_workflow_assets_wrong_post_type() {
		wp_dequeue_script( 'documentate-workflow' );

		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test enqueue_workflow_assets on correct screen.
	 */
	public function test_enqueue_workflow_assets_correct_screen() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-workflow', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test add_workflow_metabox registers the document management metabox.
	 */
	public function test_add_workflow_metabox() {
		global $wp_meta_boxes;

		$this->workflow->add_workflow_metabox();

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['documentate_document']['side'] );
		$this->assertArrayHasKey( 'documentate_document_management', $wp_meta_boxes['documentate_document']['side']['high'] );
	}

	/**
	 * Test render_document_management_metabox for draft shows stepper and buttons.
	 */
	public function test_render_document_management_metabox_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-stepper', $output );
		$this->assertStringContainsString( 'Borrador', $output );
		$this->assertStringContainsString( 'Guardar borrador', $output );
		$this->assertStringContainsString( 'Enviar a revisión', $output );
		$this->assertStringContainsString( 'data-estado="pending"', $output );
		$this->assertStringContainsString( 'post_status', $output );
		$this->assertStringContainsString( 'documentate_workflow_nonce', $output );
	}

	/**
	 * Test render_document_management_metabox for a new document (auto-draft)
	 * hides Send to Review until the draft has been saved.
	 */
	public function test_render_document_management_metabox_auto_draft_hides_send_review() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-save-draft', $output );
		$this->assertStringNotContainsString( 'documentate-send-review', $output );
	}

	/**
	 * Test render_document_management_metabox for pending as admin.
	 */
	public function test_render_document_management_metabox_pending_admin() {
		wp_set_current_user( $this->admin_user_id );

		// Create draft first.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can change status.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Update to pending.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'pending', $post->post_status, 'Post should be pending' );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'En revisión', $output );
		$this->assertStringContainsString( 'Aprobar y publicar', $output );
		$this->assertStringContainsString( 'Devolver al área', $output );
		$this->assertStringContainsString( 'documentate-save-pending', $output );
		$this->assertStringContainsString( 'id="documentate-return-draft-motivo"', $output );
		$this->assertStringContainsString( 'name="documentate_motivo"', $output );
		$this->assertStringContainsString( 'Motivo de la devolución', $output );
		// The type does not go through gestión: no "Devolver a gestión".
		$this->assertStringNotContainsString( 'documentate-return-gestion', $output );
	}

	/**
	 * Test render_document_management_metabox for pending as editor (locked).
	 */
	public function test_render_document_management_metabox_pending_editor_locked() {
		// Admin creates and sets to pending.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'pending', $post->post_status );

		// Switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'en revisión', $output );
		$this->assertStringContainsString( 'documentate-mgmt-locked-notice', $output );
		$this->assertStringNotContainsString( 'Aprobar y publicar', $output );
		$this->assertStringNotContainsString( 'Guardar borrador', $output );
	}

	/**
	 * Test render_document_management_metabox for published as admin.
	 */
	public function test_render_document_management_metabox_published_admin() {
		wp_set_current_user( $this->admin_user_id );

		// Create draft first.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Publish.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Aprobado', $output );
		$this->assertStringContainsString( 'solo lectura', $output );
		$this->assertStringContainsString( 'Devolver a revisión', $output );
		$this->assertStringContainsString( 'Archivar', $output );
	}

	/**
	 * Test render_document_management_metabox for published as editor.
	 */
	public function test_render_document_management_metabox_published_editor() {
		// Admin creates and publishes the post first.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Publish as admin.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		// Now switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Contacta con administración', $output );
		$this->assertStringContainsString( 'documentate-mgmt-locked-notice', $output );
	}

	/**
	 * Test render_document_management_metabox for draft as editor.
	 */
	public function test_render_document_management_metabox_draft_editor() {
		wp_set_current_user( $this->editor_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Envía a revisión', $output );
		$this->assertStringContainsString( 'documentate-mgmt-message--draft', $output );
		$this->assertStringContainsString( 'Guardar borrador', $output );
		$this->assertStringContainsString( 'Enviar a revisión', $output );
		// No Publish button — flow is always Draft → Review → Approved.
		$this->assertStringNotContainsString( 'documentate-publish', $output );
	}

	/**
	 * Test render_document_management_metabox for draft as admin has no Publish shortcut.
	 *
	 * Admins must follow the same workflow: Draft → Review → Approved.
	 */
	public function test_render_document_management_metabox_draft_admin_no_publish() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-save-draft', $output );
		$this->assertStringContainsString( 'Enviar a revisión', $output );
		$this->assertStringNotContainsString( 'documentate-publish', $output );
	}

	/**
	 * Test store_status_change_notice sets transient.
	 */
	public function test_store_status_change_notice() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		// Trigger a status change to set the reason.
		$data = array(
			'post_type'   => 'documentate_document',
			'post_status' => 'publish',
		);
		$this->workflow->control_post_status( $data, array( 'ID' => 0 ) );

		// Now call store_status_change_notice.
		$this->workflow->store_status_change_notice( $post->ID, $post, true );

		$transient = get_transient( 'documentate_workflow_notice_' . get_current_user_id() );
		$this->assertIsArray( $transient );
		$this->assertSame( 'no_classification', $transient['reason'] );
	}

	/**
	 * Test check_publish_capability passes through.
	 */
	public function test_check_publish_capability() {
		$result = $this->workflow->check_publish_capability( false, array() );
		$this->assertFalse( $result );

		$result = $this->workflow->check_publish_capability( true, array() );
		$this->assertTrue( $result );
	}

	/**
	 * Test that archived post status is registered.
	 */
	public function test_archived_status_registered() {
		$this->workflow->register_archived_status();

		$status = get_post_status_object( 'archived' );
		$this->assertNotNull( $status, 'Archived status should be registered.' );
		$this->assertFalse( $status->public, 'Archived status should not be public.' );
		$this->assertTrue( $status->show_in_admin_status_list, 'Archived status should show in admin status list.' );
	}

	/**
	 * Test that only admin can archive a document.
	 */
	public function test_only_admin_can_archive() {
		// Admin creates and publishes document.
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document to archive',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type and publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Verify it's published.
		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status );

		// Switch to editor and try to archive.
		wp_set_current_user( $this->editor_user_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Editor should not be able to archive documents.' );

		// Now admin archives.
		wp_set_current_user( $this->admin_user_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'archived', $stored->post_status, 'Admin should be able to archive documents.' );
	}

	/**
	 * Test that only published documents can be archived.
	 */
	public function test_only_published_can_be_archived() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Draft document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Try to archive from draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Draft documents should not be archivable.' );

		// Set to pending and try to archive.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Pending documents should not be archivable.' );

		// Publish and then archive.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'archived', $stored->post_status, 'Published documents should be archivable.' );
	}

	/**
	 * Test that archived documents are locked for non-admins.
	 */
	public function test_archived_documents_locked_for_non_admins() {
		// Admin creates, publishes, and archives document.
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Archived document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'archived', $stored->post_status );

		// Editor tries to modify.
		wp_set_current_user( $this->editor_user_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'archived', $stored->post_status, 'Editor should not be able to modify archived documents.' );
	}

	/**
	 * Test that admin can unarchive to publish only.
	 */
	public function test_admin_can_unarchive_to_publish_only() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document to unarchive',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'archived', $stored->post_status );

		// Try to unarchive to draft (should become publish).
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Admin should only be able to unarchive to publish.' );

		// Re-archive.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		// Unarchive to publish.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Admin should be able to unarchive to publish.' );
	}

	/**
	 * Test render_document_management_metabox for archived as admin.
	 */
	public function test_render_document_management_metabox_archived_admin() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'archived', $post->post_status, 'Post should be archived' );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'archivado', $output );
		$this->assertStringContainsString( 'Desarchivar', $output );
	}

	/**
	 * Test render_document_management_metabox for archived as editor.
	 */
	public function test_render_document_management_metabox_archived_editor() {
		// Admin creates and archives the post.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'archived',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'archived', $post->post_status, 'Post should be archived' );

		// Switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'archivado', $output );
		$this->assertStringContainsString( 'Contacta con administración', $output );
		$this->assertStringNotContainsString( 'Desarchivar', $output );
	}

	/**
	 * Test render_document_management_metabox shows archive link for published document.
	 */
	public function test_render_document_management_metabox_shows_archive_link() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Archivar', $output );
	}

	/**
	 * Test display_workflow_notices with archived_locked reason.
	 */
	public function test_display_workflow_notices_archived_locked() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'archived_locked',
				'original_status' => 'draft',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'archivados', $output );
	}

	/**
	 * Test display_workflow_notices with archive_requires_publish reason.
	 */
	public function test_display_workflow_notices_archive_requires_publish() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'archive_requires_publish',
				'original_status' => 'archived',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'aprobados', $output );
	}

	/**
	 * Test display_workflow_notices with archive_admin_only reason.
	 */
	public function test_display_workflow_notices_archive_admin_only() {
		wp_set_current_user( $this->editor_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'archive_admin_only',
				'original_status' => 'archived',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'administración', $output );
	}

	/**
	 * Test stepper only has 3 steps (Draft, In Review, Approved).
	 */
	public function test_stepper_has_three_steps() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Borrador', $output );
		$this->assertStringContainsString( 'En revisión', $output );
		$this->assertStringContainsString( 'Aprobado', $output );
		// Neither the archived step nor "En gestión" (type without gestión) appear.
		$this->assertStringNotContainsString( 'is-status-archived', $output );
		$this->assertStringNotContainsString( 'En gestión', $output );
	}

	/**
	 * Test stepper highlights only the current step (draft = red class).
	 */
	public function test_stepper_current_step_has_status_class() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'is-current is-status-draft', $output );
		// No is-completed class should exist.
		$this->assertStringNotContainsString( 'is-completed', $output );
	}

	/**
	 * Test non-admin cannot trash pending documents.
	 */
	public function test_non_admin_cannot_trash_pending() {
		// Admin creates pending doc.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'pending', $post->post_status );

		// Switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Mover a la papelera', $output );
	}

	/**
	 * Test admin can trash pending documents.
	 */
	public function test_admin_can_trash_pending() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'pending', $post->post_status );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Mover a la papelera', $output );
	}

	/**
	 * Test revision link is rendered for documents with revisions.
	 */
	public function test_render_revision_link() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_title'  => 'Revision Test',
			)
		);

		// Create a revision by updating.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Revision Test Updated',
			)
		);

		$post = get_post( $post_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-mgmt-revisions', $output );
		$this->assertStringContainsString( 'revision.php', $output );
	}

	/**
	 * Test restrict_revision_restore hook is registered.
	 */
	public function test_revision_restore_hook_registered() {
		$this->assertNotFalse(
			has_action( 'wp_restore_post_revision', array( $this->workflow, 'restrict_revision_restore' ) ),
			'wp_restore_post_revision hook should be registered.'
		);
	}

	/**
	 * Test pending non-admin is locked in localized data.
	 */
	public function test_pending_non_admin_is_locked() {
		wp_set_current_user( $this->editor_user_id );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Set to pending (admin sets it).
		wp_set_current_user( $this->admin_user_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		// Switch back to editor.
		wp_set_current_user( $this->editor_user_id );

		$GLOBALS['post'] = get_post( $post_id );

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$data = wp_scripts()->get_data( 'documentate-workflow', 'data' );
		// wp_localize_script outputs "1" for true values.
		$this->assertStringContainsString( '"isLocked":"1"', $data );
	}

	/**
	 * Test pending admin is not locked in localized data.
	 */
	public function test_pending_admin_not_locked() {
		wp_set_current_user( $this->admin_user_id );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$GLOBALS['post'] = get_post( $post_id );

		// Reset enqueued to re-localize.
		wp_dequeue_script( 'documentate-workflow' );
		wp_deregister_script( 'documentate-workflow' );

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$data = wp_scripts()->get_data( 'documentate-workflow', 'data' );
		// wp_localize_script outputs "" for false values.
		$this->assertStringContainsString( '"isLocked":""', $data );
	}

	/**
	 * Test render_document_management_metabox includes doc type section.
	 */
	public function test_render_document_management_metabox_includes_doc_type_section() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-doc-type-section', $output );
		$this->assertStringContainsString( 'documentate_type_nonce', $output );
		$this->assertStringContainsString( 'Selecciona un tipo', $output );
	}

	/**
	 * Test render_document_management_metabox shows locked doc type.
	 */
	public function test_render_document_management_metabox_shows_locked_doc_type() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );

		$post = get_post( $post_id );

		ob_start();
		$this->workflow->render_document_management_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-doc-type-section', $output );
		$this->assertStringContainsString( 'Tipo seleccionado:', $output );
		$this->assertStringNotContainsString( '<select', $output );
	}
}
