<?php
/**
 * Read-only document view of the front-end application.
 *
 * Shows what the document carries and where it stands in the workflow; editing
 * still happens in the wp-admin editor until the app grows its own form.
 *
 * @package Documentate
 * @subpackage App
 */

use Documentate\Documents\Documents_Meta_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders one document.
 */
class Documentate_App_Detalle {

	/**
	 * Render the document view.
	 *
	 * @param int $doc_id Document post ID.
	 * @return string
	 */
	public static function render( $doc_id ) {
		$post = get_post( $doc_id );

		if (
			! $post instanceof WP_Post
			|| 'documentate_document' !== $post->post_type
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return Documentate_App_Shell::abrir( 'lista', __( 'Document', 'documentate' ), '' )
				. '<div class="dcta-aviso">'
				. esc_html__( 'This document does not exist or is outside your scope.', 'documentate' )
				. '</div>'
				. Documentate_App_Shell::cerrar();
		}

		$chip = Documentate_App_Shell::estado_chip( $post->post_status );
		$tipos = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'names' ) );
		$tipo = ! is_wp_error( $tipos ) && ! empty( $tipos ) ? $tipos[0] : '—';

		$html = Documentate_App_Shell::abrir(
			'lista',
			get_the_title( $post ),
			/* translators: 1: document type name, 2: last modified date */
			sprintf( __( '%1$s · updated on %2$s', 'documentate' ), $tipo, get_the_modified_date( '', $post ) )
		);

		$html .= '<div class="dcta-detalle">';
		$html .= self::render_ficha( $post );
		$html .= self::render_lado( $post, $chip );
		$html .= '</div>';

		return $html . Documentate_App_Shell::cerrar();
	}

	/**
	 * Render the document fields card.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_ficha( $post ) {
		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
		$valores = Documents_Meta_Handler::get_structured_field_values( $post->ID );

		$html = '<dl class="dcta-card dcta-ficha">';

		if ( empty( $schema ) ) {
			$html .= '<dt>' . esc_html__( 'Content', 'documentate' ) . '</dt><dd>'
				. esc_html__( 'This document has no fields yet.', 'documentate' ) . '</dd>';

			return $html . '</dl>';
		}

		foreach ( $schema as $campo ) {
			$slug = isset( $campo['slug'] ) ? sanitize_key( $campo['slug'] ) : '';
			if ( '' === $slug || 'post_title' === $slug ) {
				continue;
			}

			$etiqueta = isset( $campo['label'] ) && '' !== $campo['label']
				? $campo['label']
				: Documents_Meta_Handler::humanize_unknown_field_label( $slug );

			$html .= '<dt>' . esc_html( $etiqueta ) . '</dt>';
			$html .= '<dd>' . esc_html( self::resumen_valor( $campo, isset( $valores[ $slug ] ) ? $valores[ $slug ] : null ) ) . '</dd>';
		}

		return $html . '</dl>';
	}

	/**
	 * One-line summary of a stored field value.
	 *
	 * @param array      $campo Schema row.
	 * @param array|null $info  Stored entry (value/type), or null.
	 * @return string
	 */
	private static function resumen_valor( $campo, $info ) {
		if ( isset( $campo['type'] ) && 'array' === $campo['type'] ) {
			$items = null !== $info ? Documents_Meta_Handler::get_array_field_items_from_structured( $info ) : array();

			/* translators: %d: number of rows in a repeating field */
			return sprintf( _n( '%d item', '%d items', count( $items ), 'documentate' ), count( $items ) );
		}

		$valor = null !== $info && isset( $info['value'] ) ? (string) $info['value'] : '';
		$valor = trim( wp_strip_all_tags( $valor ) );

		if ( '' === $valor ) {
			return '—';
		}

		return mb_strlen( $valor ) > 240 ? mb_substr( $valor, 0, 240 ) . '…' : $valor;
	}

	/**
	 * Render the side rail: status and actions.
	 *
	 * @param WP_Post                          $post Document.
	 * @param array{clase:string,texto:string} $chip Status chip.
	 * @return string
	 */
	private static function render_lado( $post, $chip ) {
		$html = '<div class="dcta-lado">';

		$html .= '<div class="dcta-card">'
			. '<span class="' . esc_attr( $chip['clase'] ) . '">' . esc_html( $chip['texto'] ) . '</span>'
			. '</div>';

		$html .= '<div class="dcta-card">';

		// Capability was checked on entry; the link is built directly so it does
		// not depend on the post type object registered by the current request.
		$edit_url = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		$html .= '<a class="dcta-btn dcta-btn-pri" href="' . esc_url( $edit_url ) . '">'
			. esc_html__( 'Open in the editor', 'documentate' ) . '</a>';

		$html .= '<a class="dcta-btn dcta-btn-ton" style="margin-top:10px" href="'
			. esc_url( Documentate_App_Shell::page_url() ) . '">'
			. esc_html__( 'Back to the list', 'documentate' ) . '</a>';

		$html .= '</div></div>';

		return $html;
	}
}
