<?php
/**
 * Tests for Documentate_Actividad.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Actividad
 */
class DocumentateActividadTest extends WP_UnitTestCase {

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
	private $autor_id;

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
		$this->autor_id = self::factory()->user->create(
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
				'post_author' => $this->autor_id,
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
	public function test_registrar_evento_stores_a_typed_comment() {
		$id = Documentate_Actividad::registrar_evento( $this->doc_id, 'envió el documento a gestión' );

		$this->assertGreaterThan( 0, $id );
		$comment = get_comment( $id );
		$this->assertSame( Documentate_Actividad::TIPO_EVENTO, $comment->comment_type );
		$this->assertSame( 'envió el documento a gestión', $comment->comment_content );
		$this->assertSame( '1', $comment->comment_approved );
		$this->assertSame( 'documentate', $comment->comment_agent );
		$this->assertSame( 'Ana Admin', $comment->comment_author );
		$this->assertSame( 'ana@example.com', $comment->comment_author_email );
		$this->assertSame( $this->admin_id, (int) $comment->user_id );
		$this->assertSame( '', get_comment_meta( $id, Documentate_Actividad::META_MOTIVO, true ) );
	}

	/**
	 * The reason travels as comment meta; anonymous events are signed "Sistema".
	 */
	public function test_registrar_evento_stores_the_reason_and_system_author() {
		$id = Documentate_Actividad::registrar_evento( $this->doc_id, 'devolvió el documento al área: «Falta el anexo»', '  Falta el anexo ' );
		$this->assertSame( 'Falta el anexo', get_comment_meta( $id, Documentate_Actividad::META_MOTIVO, true ) );

		// Backslashes survive the meta round trip (add_comment_meta() unslashes).
		$ruta = Documentate_Actividad::registrar_evento( $this->doc_id, 'devolvió el documento al área: «ruta»', 'Falta \\\\srv\\docs\\anexo.pdf' );
		$this->assertSame( 'Falta \\\\srv\\docs\\anexo.pdf', get_comment_meta( $ruta, Documentate_Actividad::META_MOTIVO, true ) );

		wp_set_current_user( 0 );
		$anonimo = Documentate_Actividad::registrar_evento( $this->doc_id, 'archivó el documento' );
		$this->assertSame( 'Sistema', get_comment( $anonimo )->comment_author );
		$this->assertSame( 0, (int) get_comment( $anonimo )->user_id );
	}

	/**
	 * Events never trigger comment notification mails.
	 *
	 * The actor (admin) is not the author (editor), so core would otherwise
	 * mail the author: only the recipients filter empties the list.
	 */
	public function test_events_never_mail() {
		$id = Documentate_Actividad::registrar_evento( $this->doc_id, 'aprobó y publicó el documento' );

		$this->assertSame( array(), apply_filters( 'comment_notification_recipients', array( 'eva@example.com' ), $id ) );
		$this->assertFalse( wp_notify_postauthor( $id ), 'No recipients for the author notification.' );
		// wp_notify_moderator() always returns true; what matters is that nothing goes out.
		wp_notify_moderator( $id );
		$this->assertSame( array(), $this->mails );
	}

	/**
	 * A written comment is sanitised, trimmed and capped.
	 */
	public function test_comentar_sanitises_and_limits() {
		$id = Documentate_Actividad::comentar( $this->doc_id, "  <b>Hola</b>\n<em>x</em><script>alert(1)</script>  " );

		$this->assertIsInt( $id );
		$comment = get_comment( $id );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( "Hola\nx", $comment->comment_content );
		$this->assertSame( '1', $comment->comment_approved );
		$this->assertSame( 'Ana Admin', $comment->comment_author );

		$largo = Documentate_Actividad::comentar( $this->doc_id, str_repeat( 'a', 2500 ) );
		$this->assertSame( 2000, mb_strlen( get_comment( $largo )->comment_content ) );

		$vacio = Documentate_Actividad::comentar( $this->doc_id, "   \n " );
		$this->assertWPError( $vacio );
		$this->assertSame( 'comentario_vacio', $vacio->get_error_code() );
	}

	/**
	 * Only documents the current user may edit accept comments.
	 */
	public function test_comentar_requires_edit_post_on_a_document() {
		$entrada = self::factory()->post->create();
		$ajeno = Documentate_Actividad::comentar( $entrada, 'No es un documento' );
		$this->assertWPError( $ajeno );
		$this->assertSame( 'sin_permiso', $ajeno->get_error_code() );

		// An área user outside the document's scope (the document has no category).
		$area_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $area_id, 'documentate_scope_term_id', 1 );
		wp_set_current_user( $area_id );
		$this->assertFalse( current_user_can( 'edit_post', $this->doc_id ) );
		$fuera = Documentate_Actividad::comentar( $this->doc_id, 'Desde fuera' );
		$this->assertWPError( $fuera );
		$this->assertSame( 'sin_permiso', $fuera->get_error_code() );

		wp_set_current_user( 0 );
		$this->assertWPError( Documentate_Actividad::comentar( $this->doc_id, 'Anónimo' ) );
		$this->assertSame( array(), Documentate_Actividad::listar( $this->doc_id ), 'Nothing was stored.' );
	}

	/**
	 * listar() returns events and comments newest first, and nothing else.
	 */
	public function test_listar_orders_and_filters() {
		$viejo = Documentate_Actividad::registrar_evento( $this->doc_id, 'creó el borrador' );
		wp_update_comment(
			array(
				'comment_ID' => $viejo,
				'comment_date' => '2026-01-01 10:00:00',
				'comment_date_gmt' => '2026-01-01 10:00:00',
			)
		);
		$medio = Documentate_Actividad::comentar( $this->doc_id, 'Un comentario' );
		wp_update_comment(
			array(
				'comment_ID' => $medio,
				'comment_date' => '2026-02-01 10:00:00',
				'comment_date_gmt' => '2026-02-01 10:00:00',
			)
		);
		Documentate_Actividad::registrar_evento( $this->doc_id, 'devolvió el documento al área: «Falta»', 'Falta' );

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
		$otro_doc = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Otro documento',
				'post_status' => 'draft',
			)
		);
		Documentate_Actividad::comentar( $otro_doc, 'Otro documento' );

		$filas = Documentate_Actividad::listar( $this->doc_id );

		$this->assertCount( 3, $filas );
		$this->assertSame( 'evento', $filas[0]['tipo'] );
		$this->assertSame( 'devolvió el documento al área: «Falta»', $filas[0]['texto'] );
		$this->assertSame( 'Falta', $filas[0]['motivo'] );
		$this->assertSame( 'comentario', $filas[1]['tipo'] );
		$this->assertSame( 'Un comentario', $filas[1]['texto'] );
		$this->assertSame( '', $filas[1]['motivo'] );
		$this->assertSame( '2026-02-01 10:00:00', $filas[1]['fecha'] );
		$this->assertSame( 'creó el borrador', $filas[2]['texto'] );
		$this->assertSame( 'Ana Admin', $filas[2]['autor'] );
		$this->assertStringStartsWith( 'hace ', $filas[2]['relativa'] );
		$this->assertSame( array(), Documentate_Actividad::listar( 999999 ) );
	}

	/**
	 * fecha_evento() finds the latest event by text prefix.
	 */
	public function test_fecha_evento() {
		$primero = Documentate_Actividad::registrar_evento( $this->doc_id, 'envió el documento a gestión' );
		wp_update_comment(
			array(
				'comment_ID' => $primero,
				'comment_date' => '2026-03-01 09:00:00',
				'comment_date_gmt' => '2026-03-01 09:00:00',
			)
		);
		$segundo = Documentate_Actividad::registrar_evento( $this->doc_id, 'envió el documento a revisión' );
		wp_update_comment(
			array(
				'comment_ID' => $segundo,
				'comment_date' => '2026-04-01 09:00:00',
				'comment_date_gmt' => '2026-04-01 09:00:00',
			)
		);
		Documentate_Actividad::comentar( $this->doc_id, 'envió el documento (comentario, no evento)' );

		$this->assertSame( '2026-04-01 09:00:00', Documentate_Actividad::fecha_evento( $this->doc_id, 'envió el documento' ) );
		$this->assertSame( '2026-03-01 09:00:00', Documentate_Actividad::fecha_evento( $this->doc_id, 'envió el documento a gestión' ) );
		$this->assertSame( '', Documentate_Actividad::fecha_evento( $this->doc_id, 'aprobó' ) );
	}
}
