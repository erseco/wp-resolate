<?php
/**
 * Comment-flow tweaks for the documents CPT.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Allow comment replies on draft and pending documents.
 *
 * WordPress core's wp_ajax_replyto_comment() rejects replies whenever the
 * target post is in 'draft', 'pending' or 'trash' status. For documents that
 * defeats the whole purpose of the comments metabox: editorial notes need to
 * flow while the document is still being drafted or reviewed. This handler
 * runs before the core action and serves the request itself when the target
 * post is a draft/pending documentate_document, leaving every other post
 * type untouched.
 */
class Documents_Comments_Handler {
	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		// Priority 0 runs before WP core's wp_ajax_replyto_comment (priority 10).
		add_action( 'wp_ajax_replyto-comment', array( $this, 'maybe_handle_draft_reply' ), 0 );
	}

	/**
	 * Take over the AJAX request when replying on a draft/pending document.
	 *
	 * On match the method serves the response and exits via wp_die(), so the
	 * core handler never runs. On miss it returns silently and the core
	 * handler proceeds as usual.
	 *
	 * @return void
	 */
	public function maybe_handle_draft_reply() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// Shape check only: do not fail other post types on a missing nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['comment_post_ID'] ) ? absint( wp_unslash( $_POST['comment_post_ID'] ) ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'documentate_document' !== $post->post_type ) {
			return;
		}

		if ( ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			return;
		}

		// Full nonce verification before any privileged work on our CPT.
		check_ajax_referer( 'replyto-comment', '_ajax_nonce-replyto-comment' );
		$this->handle_replyto_comment( $post );
	}

	/**
	 * Insert the comment and emit the WP_Ajax_Response WP core's JS expects.
	 *
	 * Mirrors the relevant slice of wp_ajax_replyto_comment() but skips the
	 * post-status guard that blocks draft/pending posts.
	 *
	 * Nonce must already be verified by the caller (maybe_handle_draft_reply).
	 *
	 * @param \WP_Post $post Target post.
	 * @return void
	 */
	private function handle_replyto_comment( $post ) {
		$user        = $this->assert_can_reply( $post );
		$commentdata = $this->build_commentdata_from_request( $post, $user );

		$comment_id = wp_new_comment( $commentdata, true );
		if ( is_wp_error( $comment_id ) ) {
			wp_die( esc_html( $comment_id->get_error_message() ) );
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			wp_die( 1 );
		}

		$this->send_comment_response( $comment );
	}

	/**
	 * Verify the current user can reply to a comment on this post.
	 *
	 * @param \WP_Post $post Target post.
	 * @return \WP_User Current user.
	 */
	private function assert_can_reply( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( -1 );
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			wp_die( esc_html( 'Debes iniciar sesión para responder a un comentario.' ) );
		}

		return $user;
	}

	/**
	 * Read $_POST and build the wp_new_comment() payload.
	 *
	 * Caller must verify the AJAX nonce before invoking this method.
	 *
	 * @param \WP_Post $post Target post.
	 * @param \WP_User $user Current user.
	 * @return array Comment data ready for wp_new_comment().
	 */
	private function build_commentdata_from_request( $post, $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in maybe_handle_draft_reply() via check_ajax_referer().
		// Content is further sanitized by wp_new_comment() / wp_filter_comment().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$comment_content = isset( $_POST['content'] ) ? trim( wp_unslash( $_POST['content'] ) ) : '';
		if ( '' === $comment_content ) {
			wp_die( esc_html( 'Escribe el texto de tu comentario.' ) );
		}

		$comment_parent = isset( $_POST['comment_ID'] ) ? absint( wp_unslash( $_POST['comment_ID'] ) ) : 0;
		$comment_type   = isset( $_POST['comment_type'] )
			? self::sanitize_comment_type( sanitize_key( wp_unslash( $_POST['comment_type'] ) ) )
			: 'comment';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'comment_post_ID'      => $post->ID,
			'comment_author'       => wp_slash( $user->display_name ),
			'comment_author_email' => wp_slash( $user->user_email ),
			'comment_author_url'   => wp_slash( $user->user_url ),
			'comment_content'      => $comment_content,
			'comment_type'         => $comment_type,
			'comment_parent'       => $comment_parent,
			'user_id'              => $user->ID,
			'comment_approved'     => 1,
		);
	}

	/**
	 * Restrict the comment type to a safe, known set.
	 *
	 * Any value outside the allowed list falls back to the default 'comment'
	 * type, preventing arbitrary comment types from being stored through the
	 * reply endpoint.
	 *
	 * @param string $comment_type Sanitized comment type from the request.
	 * @return string A whitelisted comment type.
	 */
	public static function sanitize_comment_type( $comment_type ) {
		$allowed = array( 'comment', 'pingback', 'trackback' );

		return in_array( (string) $comment_type, $allowed, true ) ? (string) $comment_type : 'comment';
	}

	/**
	 * Emit the WP_Ajax_Response payload that wp-admin/js/edit-comments.js expects.
	 *
	 * @param \WP_Comment $comment Newly created comment.
	 * @return void
	 */
	private function send_comment_response( $comment ) {
		// Nonce verified in maybe_handle_draft_reply() via check_ajax_referer().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$position = isset( $_POST['position'] )
			? (int) sanitize_text_field( wp_unslash( $_POST['position'] ) )
			: -1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		ob_start();
		$wp_list_table = _get_list_table( 'WP_Post_Comments_List_Table', array( 'screen' => 'edit-comments' ) );
		$wp_list_table->single_row( $comment );
		$comment_list_item = ob_get_clean();

		$x = new \WP_Ajax_Response();
		$x->add(
			array(
				'what'     => 'comment',
				'id'       => $comment->comment_ID,
				'data'     => $comment_list_item,
				'position' => $position,
			)
		);
		$x->send();
	}
}
