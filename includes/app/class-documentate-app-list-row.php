<?php
/**
 * One row of the document list of the front-end application.
 *
 * A row is more than a line of cells: it carries the text the quick filter
 * matches against, the paper-clip of a document with a file, the sublines that
 * say whose it is, and the single action the reader is most likely to want.
 * All of that lives here so the list itself only has to place the rows.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders one document row of the list.
 */
class Documentate_App_List_Row {

	/**
	 * Render one document row.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	public static function render( $post ) {
		$chip = Documentate_App_Shell::chip( $post );
		$returned = Documentate_App_Shell::returned_text( $post );
		$type = Documentate_Document_Data::type( $post );
		$action = self::action( $post );
		$detail_url = self::detail_url( $post->ID );

		$html = '<div class="dcta-fila' . ( '' !== $returned ? ' dcta-fila-devuelta' : '' ) . '"'
			. ' data-dcta-texto="' . esc_attr( self::searchable_text( $post, $type, $chip['text'], $returned ) ) . '">';
		$html .= '<div class="dcta-doc-nombre">'
			. '<a href="' . esc_url( $detail_url ) . '">' . esc_html( Documentate_Document_Data::short_name( $post ) ) . '</a>'
			. self::attachment_icon( $post )
			. self::sublines( $post )
			. ( '' !== $returned ? '<small class="dcta-doc-motivo">' . esc_html( $returned ) . '</small>' : '' )
			. '</div>';
		$html .= '<span class="dcta-doc-tipo">' . esc_html( $type ? $type->name : '—' ) . '</span>';
		$html .= '<span class="dcta-doc-fecha">' . esc_html( get_the_modified_date( 'j M', $post ) ) . '</span>';
		$html .= '<span><span class="' . esc_attr( $chip['class'] ) . '">' . esc_html( $chip['text'] ) . '</span></span>';
		$html .= '<a class="dcta-mini" href="' . esc_url( $action[1] ) . '">' . esc_html( $action[0] ) . '</a>';

		return $html . '</div>';
	}

	/**
	 * Everything the quick filter matches a row against.
	 *
	 * Whatever the row shows, so typing "gasto", "RES" or a status all narrow
	 * the list. The status chip alone is not enough for "devuelto": a document
	 * returned to revisión stays in `en_gestion` and its chip says so, while
	 * the row does carry the "Devuelto por …" line — which is added here with
	 * its reason. The área is only drawn for whoever looks after several of
	 * them, and only there does it join the text.
	 *
	 * @param WP_Post      $post     Document.
	 * @param WP_Term|null $type     Document type.
	 * @param string       $status   Status label.
	 * @param string       $returned The "Devuelto por … : «…»" line, empty when there is none.
	 * @return string
	 */
	private static function searchable_text( $post, $type, $status, $returned = '' ) {
		$parts = array(
			Documentate_Document_Data::short_name( $post ),
			wp_strip_all_tags( (string) $post->post_title ),
			$type ? $type->name : '',
			$status,
			$returned,
			Documentate_Document_Data::person( $post ),
		);

		if ( Documentate_Roles::is_management() ) {
			$parts[] = Documentate_Document_Data::area( $post );
		}

		return trim( implode( ' ', array_filter( $parts ) ) );
	}

	/**
	 * The paper-clip mark of a document that carries a file.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function attachment_icon( $post ) {
		if ( null === Documentate_Document_Data::attachment( $post ) ) {
			return '';
		}

		return '<span class="dcta-doc-adjunto dashicons dashicons-paperclip" title="Con fichero adjunto" aria-label="Con fichero adjunto" role="img"></span>';
	}

	/**
	 * The lines under the name: the official title, and who it belongs to.
	 *
	 * The área is drawn for whoever looks after several of them; for an área,
	 * where every row carries the same one, only the person is.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function sublines( $post ) {
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		if ( mb_strlen( $title ) > 90 ) {
			$title = mb_substr( $title, 0, 89 ) . '…';
		}

		$html = '' !== $title ? '<small class="dcta-doc-sub">' . esc_html( $title ) . '</small>' : '';

		$who = array( Documentate_Document_Data::person( $post ) );
		if ( Documentate_Roles::is_management() ) {
			array_unshift( $who, Documentate_Document_Data::area( $post ) );
		}
		$who = array_filter( $who );

		return '' === implode( '', $who )
			? $html
			: $html . '<small class="dcta-doc-sub">' . esc_html( implode( ' · ', $who ) ) . '</small>';
	}

	/**
	 * Label and destination of the row action.
	 *
	 * @param WP_Post $post Document.
	 * @return array{0:string,1:string}
	 */
	private static function action( $post ) {
		if ( self::opens_the_editor( $post ) ) {
			return array( 'Editar', Documentate_App_Edit::url( $post->ID ) );
		}

		// No anchor: the document view opens with the PDF itself where this
		// site draws it, so jumping to the export block would scroll past it.
		return array( 'Ver', self::detail_url( $post->ID ) );
	}

	/**
	 * Whether the row's action takes this person to the editor.
	 *
	 * There are two of them, `Editar` and `Ver`, and this is the question that
	 * tells them apart. It is narrower than "may they edit it": jefatura de
	 * servicio may edit a document sitting in revisión, but it is not theirs
	 * to work on yet — revisión has not finished with it — so their row says
	 * `Ver` and the document opens read-only, as it does for everybody else
	 * waiting.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	private static function opens_the_editor( $post ) {
		if ( ! Documentate_App_Edit::can_edit( $post ) ) {
			return false;
		}

		return null !== Documentate_Document_Data::returned( $post )
			|| 'draft' === $post->post_status
			|| self::is_waiting_for( $post );
	}

	/**
	 * Whether the document is waiting for this rol to review it.
	 *
	 * Jefatura de servicio may edit a document in revisión, but it is not
	 * theirs to review yet: revisión has not finished with it.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	private static function is_waiting_for( $post ) {
		$expected = Documentate_Roles::is_head() ? 'pending' : 'en_gestion';

		return $expected === $post->post_status;
	}

	/**
	 * URL of the document view.
	 *
	 * @param int $doc_id Document ID.
	 * @return string
	 */
	private static function detail_url( $doc_id ) {
		return Documentate_App_Shell::page_url( array( 'doc' => $doc_id ) );
	}
}
