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
 * Class Documentate_Actividad
 *
 * Static helpers to record and list what happened to a document.
 */
class Documentate_Actividad {

	/**
	 * Comment type of workflow events.
	 *
	 * @var string
	 */
	const TIPO_EVENTO = 'documentate_evento';

	/**
	 * Comment meta holding the reason attached to a return event.
	 *
	 * @var string
	 */
	const META_MOTIVO = 'documentate_motivo';

	/**
	 * Maximum length of a comment written by a person.
	 *
	 * @var int
	 */
	const COMENTARIO_MAX = 2000;

	/**
	 * Record a workflow event on a document.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $texto   What happened, in the third person ("aprobó y publicó el documento").
	 * @param string $motivo  Optional reason stored as comment meta.
	 * @return int Comment ID, or 0 when it could not be stored.
	 */
	public static function registrar_evento( $post_id, $texto, $motivo = '' ) {
		$usuario = wp_get_current_user();

		$comment_id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID' => (int) $post_id,
					'comment_content' => sanitize_textarea_field( (string) $texto ),
					'comment_type' => self::TIPO_EVENTO,
					'user_id' => (int) $usuario->ID,
					'comment_author' => $usuario->ID > 0 ? (string) $usuario->display_name : 'Sistema',
					'comment_author_email' => $usuario->ID > 0 ? (string) $usuario->user_email : '',
					'comment_approved' => 1,
					'comment_agent' => 'documentate',
				)
			)
		);

		if ( ! $comment_id ) {
			return 0;
		}

		$motivo = trim( (string) $motivo );
		if ( '' !== $motivo ) {
			// Slashed: add_comment_meta() unslashes, which would eat backslashes.
			add_comment_meta( $comment_id, self::META_MOTIVO, wp_slash( sanitize_textarea_field( $motivo ) ), true );
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
	 * @param string $texto   Raw comment (already unslashed).
	 * @return int|WP_Error Comment ID, or an error when the text is empty or
	 *                      the post is not a document the user may edit.
	 */
	public static function comentar( $post_id, $texto ) {
		$post = Documentate_Documento::post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'sin_permiso', 'No puedes comentar en este documento.' );
		}

		$texto = trim( sanitize_textarea_field( (string) $texto ) );
		if ( '' === $texto ) {
			return new WP_Error( 'comentario_vacio', 'Escribe algo antes de comentar.' );
		}

		$texto = mb_substr( $texto, 0, self::COMENTARIO_MAX );
		$usuario = wp_get_current_user();

		$comment_id = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID' => (int) $post_id,
					'comment_content' => $texto,
					'comment_type' => 'comment',
					'user_id' => (int) $usuario->ID,
					'comment_author' => (string) $usuario->display_name,
					'comment_author_email' => (string) $usuario->user_email,
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
	 * @return array<int,array{tipo:string,autor:string,texto:string,motivo:string,fecha:string,relativa:string}>
	 */
	public static function listar( $post_id ) {
		$comentarios = get_comments(
			array(
				'post_id' => (int) $post_id,
				'type__in' => array( 'comment', self::TIPO_EVENTO ),
				'status' => 'approve',
				'orderby' => 'comment_date_gmt',
				'order' => 'DESC',
			)
		);

		$filas = array();
		foreach ( (array) $comentarios as $comentario ) {
			$filas[] = self::fila( $comentario );
		}

		return $filas;
	}

	/**
	 * Shape one comment as an activity row.
	 *
	 * @param WP_Comment $comentario Comment.
	 * @return array{tipo:string,autor:string,texto:string,motivo:string,fecha:string,relativa:string}
	 */
	private static function fila( $comentario ) {
		$es_evento = self::TIPO_EVENTO === $comentario->comment_type;
		$motivo = $es_evento ? (string) get_comment_meta( $comentario->comment_ID, self::META_MOTIVO, true ) : '';
		$gmt = strtotime( $comentario->comment_date_gmt . ' UTC' );

		return array(
			'tipo' => $es_evento ? 'evento' : 'comentario',
			'autor' => (string) $comentario->comment_author,
			'texto' => (string) $comentario->comment_content,
			'motivo' => $motivo,
			'fecha' => (string) $comentario->comment_date,
			'relativa' => false === $gmt ? '' : 'hace ' . human_time_diff( $gmt, time() ),
		);
	}

	/**
	 * Date of the latest event whose text starts with a prefix (for the stepper).
	 *
	 * @param int    $post_id       Document ID.
	 * @param string $texto_prefijo Beginning of the event text ("envió el documento").
	 * @return string Local date "Y-m-d H:i:s", or empty when no such event exists.
	 */
	public static function fecha_evento( $post_id, $texto_prefijo ) {
		$eventos = get_comments(
			array(
				'post_id' => (int) $post_id,
				'type' => self::TIPO_EVENTO,
				'status' => 'approve',
				'orderby' => 'comment_date_gmt',
				'order' => 'DESC',
			)
		);

		foreach ( (array) $eventos as $evento ) {
			if ( str_starts_with( (string) $evento->comment_content, $texto_prefijo ) ) {
				return (string) $evento->comment_date;
			}
		}

		return '';
	}
}
