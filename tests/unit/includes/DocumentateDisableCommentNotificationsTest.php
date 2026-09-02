<?php
/**
 * Tests for Documentate_Disable_Comment_Notifications class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Disable_Comment_Notifications
 */
class DocumentateDisableCommentNotificationsTest extends WP_UnitTestCase {

	/**
	 * Notification handler instance.
	 *
	 * @var Documentate_Disable_Comment_Notifications
	 */
	private $handler;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Register the document post type.
		register_post_type(
			'documentate_document',
			array(
				'public'   => false,
				'supports' => array( 'comments' ),
			)
		);

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-disable-comment-notifications.php';
		$this->handler = new Documentate_Disable_Comment_Notifications();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertNotFalse(
			has_filter( 'comment_notification_recipients', array( $this->handler, 'disable_comment_notifications' ) )
		);
		$this->assertNotFalse(
			has_filter( 'comment_moderation_recipients', array( $this->handler, 'disable_comment_notifications' ) )
		);
	}

	/**
	 * Test disables notifications for documentate_document.
	 */
	public function test_disable_notifications_for_task() {
		wp_set_current_user( $this->admin_user_id );

		// Create a document post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Test Document',
				'post_status' => 'publish',
			)
		);

		// Create a comment.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		$emails = array( 'admin@example.com', 'user@example.com' );

		$result = $this->handler->disable_comment_notifications( $emails, $comment_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test allows notifications for regular posts.
	 */
	public function test_allows_notifications_for_regular_posts() {
		wp_set_current_user( $this->admin_user_id );

		// Create a regular post.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Test Post',
				'post_status' => 'publish',
			)
		);

		// Create a comment.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		$emails = array( 'admin@example.com', 'user@example.com' );

		$result = $this->handler->disable_comment_notifications( $emails, $comment_id );

		$this->assertSame( $emails, $result );
	}

	/**
	 * Workflow events are never mailed, even when they hang from a regular post.
	 */
	public function test_disables_notifications_for_workflow_events() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular post with event',
				'post_status' => 'publish',
			)
		);

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $post_id,
				'comment_content' => 'envió el documento a gestión',
				'comment_type'    => 'documentate_evento',
				'user_id'         => $this->admin_user_id,
			)
		);

		$result = $this->handler->disable_comment_notifications( array( 'admin@example.com' ), $comment_id );

		$this->assertSame( array(), $result );
	}

	/**
	 * Test handles non-existent comment gracefully.
	 */
	public function test_handles_nonexistent_comment() {
		$emails = array( 'admin@example.com' );

		$result = $this->handler->disable_comment_notifications( $emails, 999999 );

		$this->assertSame( $emails, $result );
	}
}
