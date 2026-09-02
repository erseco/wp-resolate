<?php
/**
 * Demo clock: spreads one demo document's activity entries (creation,
 * attachment, transitions, comment) over several days instead of landing
 * them all on the same timestamp.
 *
 * Split out of Documentate_Demo_App because adding it there pushed
 * PHPMD's ExcessiveClassComplexity from clean to over the 100 threshold
 * (phpmd.xml); this narrow, single-purpose helper stays in its own class
 * instead of growing the seeder further.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Demo_App_Reloj
 *
 * Static helper: a per-document clock used only while Documentate_Demo_App
 * seeds one document's activity (events and its optional comment).
 */
class Documentate_Demo_App_Reloj {

	/**
	 * Days spread between two consecutive activity entries of the same demo
	 * document, so a document's history reads as happening over days rather
	 * than landing in the same second.
	 *
	 * @var int
	 */
	const DIAS_ENTRE_PASOS = 2;

	/**
	 * GMT timestamp the NEXT activity entry (event or comment) will get.
	 * Reset per document by iniciar() and advanced by DIAS_ENTRE_PASOS after
	 * every entry, so a document's whole history is spread out and ends
	 * close to "now" instead of every step landing in the same second.
	 *
	 * @var int|null
	 */
	private static $actual = null;

	/**
	 * Reset the clock for one document: the number of activity entries it
	 * will get (creation + attachment + pasos + devuelto_directo +
	 * comentario) decides how many DIAS_ENTRE_PASOS-day steps back from "now"
	 * the first one (the creation event) starts, so the LAST entry lands
	 * close to "now".
	 *
	 * @param array $doc Document definition (see Documentate_Demo_App::documentos()).
	 * @return void
	 */
	public static function iniciar( array $doc ) {
		$entradas = 1; // "creó el borrador".
		$entradas += isset( $doc['adjunto'] ) ? 1 : 0;
		$entradas += count( $doc['pasos'] );
		$entradas += isset( $doc['devuelto_directo'] ) ? 1 : 0;
		$entradas += isset( $doc['comentario'] ) ? 1 : 0;

		self::$actual = time() - ( $entradas - 1 ) * self::DIAS_ENTRE_PASOS * DAY_IN_SECONDS;
	}

	/**
	 * Record a workflow event, then backdate it to the current clock
	 * position and advance the clock for the document's next entry.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $texto   Event text, as Documentate_Actividad::registrar_evento() expects.
	 * @param string $motivo  Optional reason stored as comment meta.
	 * @return void
	 */
	public static function registrar_evento( $post_id, $texto, $motivo = '' ) {
		self::marcar( Documentate_Actividad::registrar_evento( $post_id, $texto, $motivo ) );
	}

	/**
	 * Move one activity entry (event or comment) to the current clock
	 * position, then advance the clock for the document's next entry.
	 *
	 * Comments and events created during seeding otherwise all land on the
	 * same timestamp (whenever the request that runs the seeder happens to
	 * be), which leaves Documentate_Actividad::listar()'s DESC ordering to
	 * break ties however MySQL feels like, and shows the same "hace N
	 * minutos" on every step of the detalle stepper.
	 *
	 * @param int $comment_id Comment ID, or 0 when nothing was stored.
	 * @return void
	 */
	public static function marcar( $comment_id ) {
		if ( ! $comment_id || null === self::$actual ) {
			return;
		}

		$fecha_gmt = gmdate( 'Y-m-d H:i:s', self::$actual );
		wp_update_comment(
			array(
				'comment_ID' => $comment_id,
				'comment_date' => get_date_from_gmt( $fecha_gmt ),
				'comment_date_gmt' => $fecha_gmt,
			)
		);

		self::$actual += self::DIAS_ENTRE_PASOS * DAY_IN_SECONDS;
	}

	/**
	 * ID of the most recently recorded event of a document.
	 *
	 * Documentate_App_Adjuntos::guardar() records its own "adjuntó el
	 * fichero" event internally and does not hand back its comment ID, so
	 * the freshest event of a document (by ID, not by date — its date is
	 * what marcar() is about to change) is how the seeder finds it.
	 *
	 * @param int $post_id Document ID.
	 * @return int Comment ID, or 0 when the document has no event yet.
	 */
	public static function ultimo_evento_id( $post_id ) {
		$eventos = get_comments(
			array(
				'post_id' => (int) $post_id,
				'type' => Documentate_Actividad::TIPO_EVENTO,
				'status' => 'approve',
				'orderby' => 'comment_ID',
				'order' => 'DESC',
				'number' => 1,
			)
		);

		return empty( $eventos ) ? 0 : (int) $eventos[0]->comment_ID;
	}
}
