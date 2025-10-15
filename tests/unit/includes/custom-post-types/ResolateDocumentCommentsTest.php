<?php
/**
 * Class Test_Resolate_Task_Comments
 *
 * @package Resolate
 */

class ResolateTaskCommentsTest extends Resolate_Test_Base {
	private $administrator;
	private $editor;
	private $subscriber;
	private $document_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();

		// Create users for testing using WordPress factory
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Ensure the CPT capability checks run under an administrator context.
		wp_set_current_user( $this->administrator );

		// Create a test document using our custom factory
		$documento_result = self::factory()->document->create(
			array(
				'post_title'  => 'Test Comments Document',
				'post_author' => $this->administrator,
			)
		);

		// Verify document creation was successful
		if ( is_wp_error( $documento_result ) ) {
			$this->fail( 'Failed to create document: ' . $documento_result->get_error_message() );
		}
		$this->document_id = $documento_result;

		wp_set_current_user( 0 );
	}

	/**
	 * Test creating, editing, and deleting comments by each role.
	 */
	public function test_comments_by_roles() {
		$roles = array(
			'administrator' => $this->administrator,
			'editor'        => $this->editor,
			'subscriber'    => $this->subscriber,
		);

		foreach ( $roles as $role => $user_id ) {
			wp_set_current_user( $user_id );

			// Create a comment using WordPress factory
			$comment_id = self::factory()->comment->create(
				array(
					'comment_post_ID' => $this->document_id,
					'comment_content' => "Test comment from $role",
					'user_id'         => $user_id,
				)
			);

			$this->assertNotEquals( 0, $comment_id, "Failed to create comment for role: $role" );
			$this->assertNotFalse( $comment_id, "Failed to create comment for role: $role" );

			$comment = get_comment( $comment_id );
			$this->assertEquals( "Test comment from $role", $comment->comment_content, "Incorrect content for role: $role" );

			// Edit the comment
			$comment->comment_content = "Edited comment by $role";
			wp_update_comment( (array) $comment );

			$updated_comment = get_comment( $comment_id );
			$this->assertEquals( "Edited comment by $role", $updated_comment->comment_content, "Failed to update comment for role: $role" );

			// Delete the comment
			$deleted = wp_delete_comment( $comment_id, true );
			$this->assertTrue( $deleted, "Failed to delete comment for role: $role" );
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tear_down() {
		wp_set_current_user( $this->administrator );
		wp_delete_post( $this->document_id, true );
		wp_delete_user( $this->editor );
		wp_delete_user( $this->subscriber );
		wp_delete_user( $this->administrator );
		parent::tear_down();
	}
}
