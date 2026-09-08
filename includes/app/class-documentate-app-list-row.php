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
 * Renders one document row of a tray.
 */
class Documentate_App_List_Row {

	/**
	 * Render one document row.
	 *
	 * @param WP_Post $post Document.
	 * @param string  $tray Tray key.
	 * @return string
	 */
	public static function render( $post, $tray ) {
		$chip = Documentate_App_Shell::chip( $post );
		$returned = Documentate_App_Shell::returned_text( $post );
		$type = Documentate_Document_Data::type( $post );
		$action = self::action( $post, $tray );
		$detail_url = self::detail_url( $post->ID, $tray );

		$html = '<div class="dcta-fila' . ( '' !== $returned ? ' dcta-fila-devuelta' : '' ) . '"'
			. ' data-dcta-texto="' . esc_attr( self::searchable_text( $post, $type, $chip['text'], $returned, $tray ) ) . '">';
		$html .= '<div class="dcta-doc-nombre">'
			. '<a href="' . esc_url( $detail_url ) . '">' . esc_html( Documentate_Document_Data::short_name( $post ) ) . '</a>'
			. self::attachment_icon( $post )
			. self::sublines( $post, $tray )
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
	 * returned to gestión documental stays in `en_gestion` and its chip says so,
	 * while the row does carry the "Devuelto por …" line — which is added here
	 * with its reason. The área and the person are only drawn outside "mis
	 * documentos", and only there do they join the text.
	 *
	 * @param WP_Post      $post     Document.
	 * @param WP_Term|null $type     Document type.
	 * @param string       $status   Status label.
	 * @param string       $returned The "Devuelto por … : «…»" line, empty when there is none.
	 * @param string       $tray     Tray key.
	 * @return string
	 */
	private static function searchable_text( $post, $type, $status, $returned = '', $tray = 'mis' ) {
		$parts = array(
			Documentate_Document_Data::short_name( $post ),
			wp_strip_all_tags( (string) $post->post_title ),
			$type ? $type->name : '',
			$status,
			$returned,
		);

		if ( 'mis' !== $tray ) {
			$parts[] = Documentate_Document_Data::area( $post );
			$parts[] = Documentate_Document_Data::person( $post );
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
	 * @param WP_Post $post Document.
	 * @param string  $tray Tray key.
	 * @return string
	 */
	private static function sublines( $post, $tray ) {
		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		if ( mb_strlen( $title ) > 90 ) {
			$title = mb_substr( $title, 0, 89 ) . '…';
		}

		$html = '' !== $title ? '<small class="dcta-doc-sub">' . esc_html( $title ) . '</small>' : '';
		if ( 'mis' === $tray ) {
			return $html;
		}

		$who = array_filter( array( Documentate_Document_Data::area( $post ), Documentate_Document_Data::person( $post ) ) );

		return '' === implode( '', $who )
			? $html
			: $html . '<small class="dcta-doc-sub">' . esc_html( implode( ' · ', $who ) ) . '</small>';
	}

	/**
	 * Label and destination of the row action.
	 *
	 * @param WP_Post $post Document.
	 * @param string  $tray Tray key.
	 * @return array{0:string,1:string}
	 */
	private static function action( $post, $tray ) {
		$editable = Documentate_App_Edit::can_edit( $post );
		$edit_url = Documentate_App_Edit::url( $post->ID, $tray );

		if ( $editable && null !== Documentate_Document_Data::returned( $post ) ) {
			return array( 'Corregir', $edit_url );
		}

		if ( $editable && 'draft' === $post->post_status ) {
			return array( 'Continuar', $edit_url );
		}

		if ( $editable && self::is_waiting_for( $post ) ) {
			return array( 'Revisar', $edit_url );
		}

		if ( 'publish' === $post->post_status ) {
			return array( 'Ver PDF', self::detail_url( $post->ID, $tray ) . '#exportar' );
		}

		return array( 'Ver', self::detail_url( $post->ID, $tray ) );
	}

	/**
	 * Whether the document is waiting for this rol to review it.
	 *
	 * Administración may edit a document in gestión, but it is not theirs to
	 * review yet: gestión has not finished with it.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	private static function is_waiting_for( $post ) {
		$expected = Documentate_Roles::is_administration() ? 'pending' : 'en_gestion';

		return $expected === $post->post_status;
	}

	/**
	 * URL of the document view, remembering which tray it was opened from.
	 *
	 * @param int    $doc_id Document ID.
	 * @param string $tray   Tray key.
	 * @return string
	 */
	private static function detail_url( $doc_id, $tray ) {
		$args = array( 'doc' => $doc_id );
		if ( 'mis' !== $tray ) {
			$args['bandeja'] = $tray;
		}

		return Documentate_App_Shell::page_url( $args );
	}
}
