<?php
/**
 * Activity of a document: workflow events and comments.
 *
 * Events ("envió el documento a gestión", "devolvió el documento al área:
 * «…»") are stored as comments of a dedicated type so they live next to the
 * document, survive exports and show up in the same feed as the comments
 * people write. They never send mail.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Activity
 *
 * Static helpers to record and list what happened to a document.
 */
class Documentate_Activity {

	/**
	 * Comment type of workflow events.
	 *
	 * @var string
	 */
	const EVENT_TYPE = 'documentate_evento';

	/**
	 * Comment meta holding the reason attached to a return event.
	 *
	 * @var string
	 */
	const META_REASON = 'documentate_motivo';

	/**
	 * Maximum length of a comment written by a person.
	 *
	 * @var int
	 */
	const COMMENT_MAX = 2000;

	/**
	 * Record a workflow event on a document.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $text    What happened, in the third person ("aprobó y publicó el documento").
	 * @param string $reason  Optional reason stored as comment meta.
	 * @return int Comment ID, or 0 when it could not be stored.
	 */
	public static function record_event( $post_id, $text, $reason = '' ) {
		$user = wp_get_current_user();

		$comment_id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID' => (int) $post_id,
					'comment_content' => sanitize_textarea_field( (string) $text ),
					'comment_type' => self::EVENT_TYPE,
					'user_id' => (int) $user->ID,
					'comment_author' => $user->ID > 0 ? (string) $user->display_name : 'Sistema',
					'comment_author_email' => $user->ID > 0 ? (string) $user->user_email : '',
					'comment_approved' => 1,
					'comment_agent' => 'documentate',
				)
			)
		);

		if ( ! $comment_id ) {
			return 0;
		}

		$reason = trim( (string) $reason );
		if ( '' !== $reason ) {
			// Slashed: add_comment_meta() unslashes, which would eat backslashes.
			add_comment_meta( $comment_id, self::META_REASON, wp_slash( sanitize_textarea_field( $reason ) ), true );
		}

		return (int) $comment_id;
	}

	/**
	 * Store a comment written by the current user.
	 *
	 * Only on a document the current user may edit (área inside its scope,
	 * gestión on the pipeline, administración anywhere): the handler's nonce
	 * proves the request, this check proves the right. Uses
	 * wp_insert_comment() rather than wp_new_comment(): the latter runs
	 * flood control and dies on failure, neither of which fits a form handler
	 * that redirects with a flag.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $text    Raw comment (already unslashed).
	 * @return int|WP_Error Comment ID, or an error when the text is empty or
	 *                      the post is not a document the user may edit.
	 */
	public static function add_comment( $post_id, $text ) {
		$post = Documentate_Document_Data::post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'sin_permiso', 'No puedes comentar en este documento.' );
		}

		$text = trim( sanitize_textarea_field( (string) $text ) );
		if ( '' === $text ) {
			return new WP_Error( 'comentario_vacio', 'Escribe algo antes de comentar.' );
		}

		$text = mb_substr( $text, 0, self::COMMENT_MAX );
		$user = wp_get_current_user();

		$comment_id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID' => (int) $post_id,
					'comment_content' => $text,
					'comment_type' => 'comment',
					'user_id' => (int) $user->ID,
					'comment_author' => (string) $user->display_name,
					'comment_author_email' => (string) $user->user_email,
					'comment_approved' => 1,
					'comment_agent' => 'documentate',
				)
			)
		);

		if ( ! $comment_id ) {
			return new WP_Error( 'comentario_error', 'No se pudo guardar el comentario.' );
		}

		return (int) $comment_id;
	}

	/**
	 * Events and comments of a document, newest first.
	 *
	 * @param int $post_id Document ID.
	 * @return array<int,array{type:string,author:string,text:string,reason:string,date:string,relative:string}>
	 */
	public static function entries( $post_id ) {
		$comments = get_comments(
			array(
				'post_id' => (int) $post_id,
				'type__in' => array( 'comment', self::EVENT_TYPE ),
				'status' => 'approve',
				'orderby' => 'comment_date_gmt',
				'order' => 'DESC',
			)
		);

		$rows = array();
		foreach ( (array) $comments as $comment ) {
			$rows[] = self::row( $comment );
		}

		return $rows;
	}

	/**
	 * Shape one comment as an activity row.
	 *
	 * @param WP_Comment $comment Comment.
	 * @return array{type:string,author:string,text:string,reason:string,date:string,relative:string}
	 */
	private static function row( $comment ) {
		$is_event = self::EVENT_TYPE === $comment->comment_type;
		$reason = $is_event ? (string) get_comment_meta( $comment->comment_ID, self::META_REASON, true ) : '';
		$gmt = strtotime( $comment->comment_date_gmt . ' UTC' );

		return array(
			'type' => $is_event ? 'evento' : 'comentario',
			'author' => (string) $comment->comment_author,
			'text' => (string) $comment->comment_content,
			'reason' => $reason,
			'date' => (string) $comment->comment_date,
			'relative' => false === $gmt ? '' : 'hace ' . human_time_diff( $gmt, time() ),
		);
	}

	/**
	 * Date of the latest event whose text starts with a prefix (for the stepper).
	 *
	 * @param int    $post_id     Document ID.
	 * @param string $text_prefix Beginning of the event text ("envió el documento").
	 * @return string Local date "Y-m-d H:i:s", or empty when no such event exists.
	 */
	public static function event_date( $post_id, $text_prefix ) {
		$events = get_comments(
			array(
				'post_id' => (int) $post_id,
				'type' => self::EVENT_TYPE,
				'status' => 'approve',
				'orderby' => 'comment_date_gmt',
				'order' => 'DESC',
			)
		);

		foreach ( (array) $events as $event ) {
			if ( str_starts_with( (string) $event->comment_content, $text_prefix ) ) {
				return (string) $event->comment_date;
			}
		}

		return '';
	}
}
