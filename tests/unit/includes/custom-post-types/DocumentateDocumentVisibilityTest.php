<?php
/**
 * Tests for document visibility and status behavior.
 *
 * Note: The old behavior of forcing all documents to 'private' has been replaced
 * with a proper workflow system. See DocumentateWorkflowTest.php for workflow tests.
 *
 * This file now tests basic document creation and status persistence.
 *
 * @package Documentate
 */

class DocumentateDocumentVisibilityTest extends WP_UnitTestCase {

	/**
	 * Document handler instance.
	 *
	 * @var Documentate_Documents
	 */
	protected $documents;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', 'documentate_document', array( 'public' => false ) );
		$this->documents = new Documentate_Documents();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that new documents can be saved as draft.
	 */
	public function test_new_documents_can_be_saved_as_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento borrador',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document should be saved as draft.' );
		$this->assertSame( '', $stored->post_password, 'Document should not have a password.' );
	}

	/**
	 * Test that documents without doc_type cannot be published.
	 */
	public function test_documents_without_doc_type_forced_to_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento sin tipo',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that document dates are preserved on update.
	 *
	 * The date is given explicitly. A draft inserted without one carries a
	 * zeroed `post_date_gmt`, which WordPress reads as "this date is still
	 * floating" and refreshes on every save — so the assertion below held only
	 * while the insert and the update landed in the same second, and CI failed
	 * whenever they did not. With the date pinned, any drift is the plugin's
	 * doing, which is what this test is about.
	 */
	public function test_existing_document_preserves_dates() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento inicial',
				'post_status' => 'draft',
				'post_date' => '2026-02-03 09:15:00',
				'post_date_gmt' => '2026-02-03 09:15:00',
			)
		);

		$this->assertNotWPError( $post_id );

		$original = get_post( $post_id );

		// Update the document.
		$updated_id = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Título actualizado',
			)
		);

		$this->assertSame( $post_id, $updated_id, 'Updated ID should match.' );

		$reloaded = get_post( $post_id );
		$this->assertEquals( $original->post_date, $reloaded->post_date, 'Original date should be preserved.' );
		$this->assertEquals( $original->post_date_gmt, $reloaded->post_date_gmt, 'Original GMT date should be preserved.' );
	}
}
