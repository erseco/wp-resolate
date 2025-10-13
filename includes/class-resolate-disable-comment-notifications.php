<?php
/**
 * Class Resolate_Disable_Comment_Notifications
 *
 * Disables automatic comment notification emails for the resolate_task custom post type.
 *
 * @package Resolate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Resolate_Disable_Comment_Notifications
 *
 * Hooks into WordPress comment notifications and returns an empty recipient
 * list for 'resolate_task' posts. Prevents any email notifications on new comments.
 */
class Resolate_Disable_Comment_Notifications {

	/**
	 * Constructor.
	 *
	 * Initializes filters to disable comment notifications and moderation emails
	 * for the custom post type 'resolate_task'.
	 */
	public function __construct() {
		add_filter( 'comment_notification_recipients', array( $this, 'disable_comment_notifications' ), 10, 2 );
		add_filter( 'comment_moderation_recipients', array( $this, 'disable_comment_notifications' ), 10, 2 );
	}

	/**
	 * Disables comment notification emails for resolate_task.
	 *
	 * @param string[] $emails List of email addresses scheduled to be notified.
	 * @param int      $comment_id The comment ID.
	 * @return string[] Filtered list of email recipients (empty array if resolate_task).
	 */
	public function disable_comment_notifications( $emails, $comment_id ) {

		$comment = get_comment( $comment_id );
		if ( $comment && 'resolate_task' === get_post_type( $comment->comment_post_ID ) ) {
			// Return an empty array to disable all notifications for this CPT.
			return array();
		}

		return $emails;
	}
}

// Instantiate the class (this line can be in your main plugin file or here).
if ( class_exists( 'Resolate_Disable_Comment_Notifications' ) ) {
	new Resolate_Disable_Comment_Notifications();
}
