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
class Documentate_App_Lista_Fila {

	/**
	 * Render one document row.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return string
	 */
	public static function render( $post, $bandeja ) {
		$chip = Documentate_App_Shell::chip( $post );
		$devuelto = Documentate_App_Shell::texto_devuelto( $post );
		$tipo = Documentate_Documento::tipo( $post );
		$accion = self::accion( $post, $bandeja );
		$detalle_url = self::url_detalle( $post->ID, $bandeja );

		$html = '<div class="dcta-fila' . ( '' !== $devuelto ? ' dcta-fila-devuelta' : '' ) . '"'
			. ' data-dcta-texto="' . esc_attr( self::texto_buscable( $post, $tipo, $chip['texto'], $devuelto, $bandeja ) ) . '">';
		$html .= '<div class="dcta-doc-nombre">'
			. '<a href="' . esc_url( $detalle_url ) . '">' . esc_html( Documentate_Documento::nombre_corto( $post ) ) . '</a>'
			. self::icono_adjunto( $post )
			. self::sublineas( $post, $bandeja )
			. ( '' !== $devuelto ? '<small class="dcta-doc-motivo">' . esc_html( $devuelto ) . '</small>' : '' )
			. '</div>';
		$html .= '<span class="dcta-doc-tipo">' . esc_html( $tipo ? $tipo->name : '—' ) . '</span>';
		$html .= '<span class="dcta-doc-fecha">' . esc_html( get_the_modified_date( 'j M', $post ) ) . '</span>';
		$html .= '<span><span class="' . esc_attr( $chip['clase'] ) . '">' . esc_html( $chip['texto'] ) . '</span></span>';
		$html .= '<a class="dcta-mini" href="' . esc_url( $accion[1] ) . '">' . esc_html( $accion[0] ) . '</a>';

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
	 * @param WP_Term|null $tipo     Document type.
	 * @param string       $estado   Status label.
	 * @param string       $devuelto The "Devuelto por … : «…»" line, empty when there is none.
	 * @param string       $bandeja  Tray key.
	 * @return string
	 */
	private static function texto_buscable( $post, $tipo, $estado, $devuelto = '', $bandeja = 'mis' ) {
		$partes = array(
			Documentate_Documento::nombre_corto( $post ),
			wp_strip_all_tags( (string) $post->post_title ),
			$tipo ? $tipo->name : '',
			$estado,
			$devuelto,
		);

		if ( 'mis' !== $bandeja ) {
			$partes[] = Documentate_Documento::area( $post );
			$partes[] = Documentate_Documento::persona( $post );
		}

		return trim( implode( ' ', array_filter( $partes ) ) );
	}

	/**
	 * The paper-clip mark of a document that carries a file.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function icono_adjunto( $post ) {
		if ( null === Documentate_Documento::adjunto( $post ) ) {
			return '';
		}

		return '<span class="dcta-doc-adjunto dashicons dashicons-paperclip" title="Con fichero adjunto" aria-label="Con fichero adjunto" role="img"></span>';
	}

	/**
	 * The lines under the name: the official title, and who it belongs to.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return string
	 */
	private static function sublineas( $post, $bandeja ) {
		$titulo = trim( wp_strip_all_tags( (string) $post->post_title ) );
		if ( mb_strlen( $titulo ) > 90 ) {
			$titulo = mb_substr( $titulo, 0, 89 ) . '…';
		}

		$html = '' !== $titulo ? '<small class="dcta-doc-sub">' . esc_html( $titulo ) . '</small>' : '';
		if ( 'mis' === $bandeja ) {
			return $html;
		}

		$quien = array_filter( array( Documentate_Documento::area( $post ), Documentate_Documento::persona( $post ) ) );

		return '' === implode( '', $quien )
			? $html
			: $html . '<small class="dcta-doc-sub">' . esc_html( implode( ' · ', $quien ) ) . '</small>';
	}

	/**
	 * Label and destination of the row action.
	 *
	 * @param WP_Post $post    Document.
	 * @param string  $bandeja Tray key.
	 * @return array{0:string,1:string}
	 */
	private static function accion( $post, $bandeja ) {
		$editable = Documentate_App_Editar::puede_editar( $post );
		$editar = Documentate_App_Editar::url( $post->ID, $bandeja );

		if ( $editable && null !== Documentate_Documento::devuelto( $post ) ) {
			return array( 'Corregir', $editar );
		}

		if ( $editable && 'draft' === $post->post_status ) {
			return array( 'Continuar', $editar );
		}

		if ( $editable && self::esta_esperando_a( $post ) ) {
			return array( 'Revisar', $editar );
		}

		if ( 'publish' === $post->post_status ) {
			return array( 'Ver PDF', self::url_detalle( $post->ID, $bandeja ) . '#exportar' );
		}

		return array( 'Ver', self::url_detalle( $post->ID, $bandeja ) );
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
	private static function esta_esperando_a( $post ) {
		$esperado = Documentate_Roles::es_administracion() ? 'pending' : 'en_gestion';

		return $esperado === $post->post_status;
	}

	/**
	 * URL of the document view, remembering which tray it was opened from.
	 *
	 * @param int    $doc_id  Document ID.
	 * @param string $bandeja Tray key.
	 * @return string
	 */
	private static function url_detalle( $doc_id, $bandeja ) {
		$args = array( 'doc' => $doc_id );
		if ( 'mis' !== $bandeja ) {
			$args['bandeja'] = $bandeja;
		}

		return Documentate_App_Shell::page_url( $args );
	}
}
