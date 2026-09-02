<?php
/**
 * Class Documentate_Disable_Comment_Notifications
 *
 * Disables automatic comment notification emails for the documentate_document custom post type.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Disable_Comment_Notifications
 *
 * Hooks into WordPress comment notifications and returns an empty recipient
 * list for 'documentate_document' posts and for workflow events
 * ('documentate_evento' comments). Prevents any email notifications on them.
 */
class Documentate_Disable_Comment_Notifications {
	/**
	 * Constructor.
	 *
	 * Initializes filters to disable comment notifications and moderation emails
	 * for the custom post type 'documentate_document'.
	 */
	public function __construct() {
		add_filter( 'comment_notification_recipients', array( $this, 'disable_comment_notifications' ), 10, 2 );
		add_filter( 'comment_moderation_recipients', array( $this, 'disable_comment_notifications' ), 10, 2 );
	}

	/**
	 * Disables comment notification emails for documentate_document.
	 *
	 * @param string[] $emails List of email addresses scheduled to be notified.
	 * @param int      $comment_id The comment ID.
	 * @return string[] Filtered list of email recipients (empty array if documentate_document).
	 */
	public function disable_comment_notifications( $emails, $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return $emails;
		}

		if ( 'documentate_evento' === $comment->comment_type ) {
			// Workflow events are never mailed, whatever post they hang from.
			return array();
		}

		if ( 'documentate_document' === get_post_type( $comment->comment_post_ID ) ) {
			// Return an empty array to disable all notifications for this CPT.
			return array();
		}

		return $emails;
	}
}

// Instantiate the class (this line can be in your main plugin file or here).
if ( class_exists( 'Documentate_Disable_Comment_Notifications' ) ) {
	new Documentate_Disable_Comment_Notifications();
}
