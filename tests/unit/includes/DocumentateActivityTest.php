<?php
/**
 * Tests for Documentate_Activity.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Activity
 */
class DocumentateActivityTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Author of the document (never the actor, so author notifications would apply).
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Document ID.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Captured wp_mail() calls.
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Set up a user, a document and the mail spy.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
				'display_name' => 'Ana Admin',
				'user_email' => 'ana@example.com',
			)
		);
		$this->author_id = self::factory()->user->create(
			array(
				'role' => 'editor',
				'display_name' => 'Eva Editora',
				'user_email' => 'eva@example.com',
			)
		);
		wp_set_current_user( $this->admin_id );

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento con actividad',
				'post_status' => 'draft',
				'post_author' => $this->author_id,
			)
		);

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Reset state.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Capture wp_mail() calls without dispatching them.
	 *
	 * @param mixed $return Short-circuit value.
	 * @param array $atts   Mail attributes.
	 * @return bool
	 */
	public function capture_mail( $return, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * An event is stored as an approved comment of its own type, with the actor.
	 */
	public function test_record_event_stores_a_typed_comment() {
		$id = Documentate_Activity::record_event( $this->doc_id, 'envió el documento a gestión' );

		$this->assertGreaterThan( 0, $id );
		$comment = get_comment( $id );
		$this->assertSame( Documentate_Activity::EVENT_TYPE, $comment->comment_type );
		$this->assertSame( 'envió el documento a gestión', $comment->comment_content );
		$this->assertSame( '1', $comment->comment_approved );
		$this->assertSame( 'documentate', $comment->comment_agent );
		$this->assertSame( 'Ana Admin', $comment->comment_author );
		$this->assertSame( 'ana@example.com', $comment->comment_author_email );
		$this->assertSame( $this->admin_id, (int) $comment->user_id );
		$this->assertSame( '', get_comment_meta( $id, Documentate_Activity::META_REASON, true ) );
	}

	/**
	 * The reason travels as comment meta; anonymous events are signed "Sistema".
	 */
	public function test_record_event_stores_the_reason_and_system_author() {
		$id = Documentate_Activity::record_event( $this->doc_id, 'devolvió el documento al área: «Falta el anexo»', '  Falta el anexo ' );
		$this->assertSame( 'Falta el anexo', get_comment_meta( $id, Documentate_Activity::META_REASON, true ) );

		// Backslashes survive the meta round trip (add_comment_meta() unslashes).
		$path = Documentate_Activity::record_event( $this->doc_id, 'devolvió el documento al área: «ruta»', 'Falta \\\\srv\\docs\\anexo.pdf' );
		$this->assertSame( 'Falta \\\\srv\\docs\\anexo.pdf', get_comment_meta( $path, Documentate_Activity::META_REASON, true ) );

		wp_set_current_user( 0 );
		$anonymous = Documentate_Activity::record_event( $this->doc_id, 'archivó el documento' );
		$this->assertSame( 'Sistema', get_comment( $anonymous )->comment_author );
		$this->assertSame( 0, (int) get_comment( $anonymous )->user_id );
	}

	/**
	 * Events never trigger comment notification mails.
	 *
	 * The actor (admin) is not the author (editor), so core would otherwise
	 * mail the author: only the recipients filter empties the list.
	 */
	public function test_events_never_mail() {
		$id = Documentate_Activity::record_event( $this->doc_id, 'aprobó y publicó el documento' );

		$this->assertSame( array(), apply_filters( 'comment_notification_recipients', array( 'eva@example.com' ), $id ) );
		$this->assertFalse( wp_notify_postauthor( $id ), 'No recipients for the author notification.' );
		// wp_notify_moderator() always returns true; what matters is that nothing goes out.
		wp_notify_moderator( $id );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * A written comment is sanitised, trimmed and capped.
	 */
	public function test_add_comment_sanitises_and_limits() {
		$id = Documentate_Activity::add_comment( $this->doc_id, "  <b>Hola</b>\n<em>x</em><script>alert(1)</script>  " );

		$this->assertIsInt( $id );
		$comment = get_comment( $id );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( "Hola\nx", $comment->comment_content );
		$this->assertSame( '1', $comment->comment_approved );
		$this->assertSame( 'Ana Admin', $comment->comment_author );

		$long_text = Documentate_Activity::add_comment( $this->doc_id, str_repeat( 'a', 2500 ) );
		$this->assertSame( 2000, mb_strlen( get_comment( $long_text )->comment_content ) );

		$is_empty = Documentate_Activity::add_comment( $this->doc_id, "   \n " );
		$this->assertWPError( $is_empty );
		$this->assertSame( 'comentario_vacio', $is_empty->get_error_code() );
	}

	/**
	 * Only documents the current user may edit accept comments.
	 */
	public function test_add_comment_requires_edit_post_on_a_document() {
		$entry = self::factory()->post->create();
		$foreign_post = Documentate_Activity::add_comment( $entry, 'No es un documento' );
		$this->assertWPError( $foreign_post );
		$this->assertSame( 'sin_permiso', $foreign_post->get_error_code() );

		// An área user outside the document's scope (the document has no category).
		$area_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $area_id, 'documentate_scope_term_id', 1 );
		wp_set_current_user( $area_id );
		$this->assertFalse( current_user_can( 'edit_post', $this->doc_id ) );
		$outside = Documentate_Activity::add_comment( $this->doc_id, 'Desde fuera' );
		$this->assertWPError( $outside );
		$this->assertSame( 'sin_permiso', $outside->get_error_code() );

		wp_set_current_user( 0 );
		$this->assertWPError( Documentate_Activity::add_comment( $this->doc_id, 'Anónimo' ) );
		$this->assertSame( array(), Documentate_Activity::entries( $this->doc_id ), 'Nothing was stored.' );
	}

	/**
	 * entries() returns events and comments newest first, and nothing else.
	 */
	public function test_entries_orders_and_filters() {
		$old = Documentate_Activity::record_event( $this->doc_id, 'creó el borrador' );
		wp_update_comment(
			array(
				'comment_ID' => $old,
				'comment_date' => '2026-01-01 10:00:00',
				'comment_date_gmt' => '2026-01-01 10:00:00',
			)
		);
		$middle = Documentate_Activity::add_comment( $this->doc_id, 'Un comentario' );
		wp_update_comment(
			array(
				'comment_ID' => $middle,
				'comment_date' => '2026-02-01 10:00:00',
				'comment_date_gmt' => '2026-02-01 10:00:00',
			)
		);
		Documentate_Activity::record_event( $this->doc_id, 'devolvió el documento al área: «Falta»', 'Falta' );

		// Noise: a pingback, an unapproved comment and a comment on another post.
		wp_insert_comment(
			array(
				'comment_post_ID' => $this->doc_id,
				'comment_content' => 'ping',
				'comment_type' => 'pingback',
				'comment_approved' => 1,
			)
		);
		wp_insert_comment(
			array(
				'comment_post_ID' => $this->doc_id,
				'comment_content' => 'pendiente de moderar',
				'comment_approved' => 0,
			)
		);
		$other_doc = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Otro documento',
				'post_status' => 'draft',
			)
		);
		Documentate_Activity::add_comment( $other_doc, 'Otro documento' );

		$rows = Documentate_Activity::entries( $this->doc_id );

		$this->assertCount( 3, $rows );
		$this->assertSame( 'evento', $rows[0]['type'] );
		$this->assertSame( 'devolvió el documento al área: «Falta»', $rows[0]['text'] );
		$this->assertSame( 'Falta', $rows[0]['reason'] );
		$this->assertSame( 'comentario', $rows[1]['type'] );
		$this->assertSame( 'Un comentario', $rows[1]['text'] );
		$this->assertSame( '', $rows[1]['reason'] );
		$this->assertSame( '2026-02-01 10:00:00', $rows[1]['date'] );
		$this->assertSame( 'creó el borrador', $rows[2]['text'] );
		$this->assertSame( 'Ana Admin', $rows[2]['author'] );
		$this->assertStringStartsWith( 'hace ', $rows[2]['relative'] );
		$this->assertSame( array(), Documentate_Activity::entries( 999999 ) );
	}

	/**
	 * event_date() finds the latest event by text prefix.
	 */
	public function test_event_date() {
		$first = Documentate_Activity::record_event( $this->doc_id, 'envió el documento a gestión' );
		wp_update_comment(
			array(
				'comment_ID' => $first,
				'comment_date' => '2026-03-01 09:00:00',
				'comment_date_gmt' => '2026-03-01 09:00:00',
			)
		);
		$second = Documentate_Activity::record_event( $this->doc_id, 'envió el documento a revisión' );
		wp_update_comment(
			array(
				'comment_ID' => $second,
				'comment_date' => '2026-04-01 09:00:00',
				'comment_date_gmt' => '2026-04-01 09:00:00',
			)
		);
		Documentate_Activity::add_comment( $this->doc_id, 'envió el documento (comentario, no evento)' );

		$this->assertSame( '2026-04-01 09:00:00', Documentate_Activity::event_date( $this->doc_id, 'envió el documento' ) );
		$this->assertSame( '2026-03-01 09:00:00', Documentate_Activity::event_date( $this->doc_id, 'envió el documento a gestión' ) );
		$this->assertSame( '', Documentate_Activity::event_date( $this->doc_id, 'aprobó' ) );
	}
}
