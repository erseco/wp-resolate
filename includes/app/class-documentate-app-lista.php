<?php
/**
 * Document list view of the front-end application.
 *
 * Mirrors the admin list scope rules: administrators see every document, a
 * scoped user sees the documents of their category (and descendants), and a
 * restricted user without a scope sees nothing.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the "my documents" / "all documents" list.
 */
class Documentate_App_Lista {

	/**
	 * Statuses the list shows, in workflow order.
	 *
	 * @return array<string,string> Status => label.
	 */
	private static function estados() {
		return array(
			'draft' => __( 'Draft', 'documentate' ),
			'pending' => __( 'In Review', 'documentate' ),
			'publish' => __( 'Approved', 'documentate' ),
			'archived' => __( 'Archived', 'documentate' ),
		);
	}

	/**
	 * Scope term IDs of the current user.
	 *
	 * Same contract as Documentate_Scope_Filter::get_scope_term_ids(), inlined
	 * here because that class registers hooks on construction.
	 *
	 * @return int[]|null Null for unrestricted users; empty array when the user
	 *                    is restricted but has no scope assigned.
	 */
	private static function scope_term_ids() {
		if ( current_user_can( 'manage_options' ) ) {
			return null;
		}

		$scope_term = absint( get_user_meta( get_current_user_id(), 'documentate_scope_term_id', true ) );
		if ( 0 === $scope_term ) {
			return array();
		}

		$term_ids = array( $scope_term );
		$children = get_term_children( $scope_term, 'category' );
		if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
			$term_ids = array_merge( $term_ids, $children );
		}

		return array_map( 'absint', $term_ids );
	}

	/**
	 * Count visible documents in one status.
	 *
	 * @param string     $status   Post status.
	 * @param int[]|null $term_ids Scope restriction.
	 * @return int
	 */
	private static function contar( $status, $term_ids ) {
		if ( is_array( $term_ids ) && empty( $term_ids ) ) {
			return 0;
		}

		$args = array(
			'post_type' => 'documentate_document',
			'post_status' => $status,
			'fields' => 'ids',
			'posts_per_page' => 1,
			'no_found_rows' => false,
			'ignore_sticky_posts' => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( is_array( $term_ids ) ) {
			$args['tax_query'] = self::scope_tax_query( $term_ids ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scope restriction, bounded list.
		}

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Taxonomy clause restricting a query to the user's scope.
	 *
	 * @param int[] $term_ids Scope term IDs.
	 * @return array
	 */
	private static function scope_tax_query( array $term_ids ) {
		return array(
			array(
				'taxonomy' => 'category',
				'field' => 'term_id',
				'terms' => $term_ids,
				'include_children' => false,
			),
		);
	}

	/**
	 * Render the list view.
	 *
	 * @return string
	 */
	public static function render() {
		$es_admin = current_user_can( 'manage_options' );
		$term_ids = self::scope_term_ids();
		$estados = self::estados();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$filtro = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';
		if ( ! isset( $estados[ $filtro ] ) ) {
			$filtro = '';
		}

		$html = Documentate_App_Shell::abrir(
			'lista',
			$es_admin ? __( 'All documents', 'documentate' ) : __( 'My documents', 'documentate' ),
			$es_admin
				? __( 'Every area, every status.', 'documentate' )
				: __( 'The documents of your area, with their status.', 'documentate' )
		);

		if ( is_array( $term_ids ) && empty( $term_ids ) ) {
			return $html
				. '<div class="dcta-aviso">'
				. esc_html__( 'Your user has no scope category assigned. Contact an administrator.', 'documentate' )
				. '</div>'
				. Documentate_App_Shell::cerrar();
		}

		$html .= self::render_cifras( $term_ids, $estados );
		$html .= self::render_filtros( $estados, $filtro );
		$html .= self::render_tabla( $term_ids, $estados, $filtro );

		return $html . Documentate_App_Shell::cerrar();
	}

	/**
	 * Render the status counters.
	 *
	 * @param int[]|null           $term_ids Scope restriction.
	 * @param array<string,string> $estados  Status labels.
	 * @return string
	 */
	private static function render_cifras( $term_ids, array $estados ) {
		$html = '<div class="dcta-cifras">';
		$primero = true;
		foreach ( $estados as $status => $label ) {
			$html .= '<div class="dcta-cifra' . ( $primero ? ' dcta-cifra-acento' : '' ) . '">'
				. '<b>' . esc_html( (string) self::contar( $status, $term_ids ) ) . '</b>'
				. '<span>' . esc_html( $label ) . '</span>'
				. '</div>';
			$primero = false;
		}

		return $html . '</div>';
	}

	/**
	 * Render the status filter chips.
	 *
	 * @param array<string,string> $estados Status labels.
	 * @param string               $filtro  Active status filter.
	 * @return string
	 */
	private static function render_filtros( array $estados, $filtro ) {
		$html = '<div class="dcta-filtros">';
		$html .= '<a class="dcta-fchip' . ( '' === $filtro ? ' dcta-fchip-on' : '' ) . '" href="'
			. esc_url( Documentate_App_Shell::page_url() ) . '">'
			. esc_html__( 'All', 'documentate' ) . '</a>';

		foreach ( $estados as $status => $label ) {
			$html .= '<a class="dcta-fchip' . ( $status === $filtro ? ' dcta-fchip-on' : '' ) . '" href="'
				. esc_url( Documentate_App_Shell::page_url( array( 'estado' => $status ) ) ) . '">'
				. esc_html( $label ) . '</a>';
		}

		return $html . '</div>';
	}

	/**
	 * Render the document rows.
	 *
	 * @param int[]|null           $term_ids Scope restriction.
	 * @param array<string,string> $estados  Status labels.
	 * @param string               $filtro   Active status filter.
	 * @return string
	 */
	private static function render_tabla( $term_ids, array $estados, $filtro ) {
		$args = array(
			'post_type' => 'documentate_document',
			'post_status' => '' !== $filtro ? $filtro : array_keys( $estados ),
			'posts_per_page' => 100,
			'orderby' => 'modified',
			'order' => 'DESC',
			'ignore_sticky_posts' => true,
		);

		if ( is_array( $term_ids ) ) {
			$args['tax_query'] = self::scope_tax_query( $term_ids ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scope restriction, bounded list.
		}

		$query = new WP_Query( $args );

		$html = '<div class="dcta-tabla">';
		$html .= '<div class="dcta-fila dcta-fila-cab">'
			. '<span>' . esc_html__( 'Document', 'documentate' ) . '</span>'
			. '<span>' . esc_html__( 'Type', 'documentate' ) . '</span>'
			. '<span>' . esc_html__( 'Updated', 'documentate' ) . '</span>'
			. '<span>' . esc_html__( 'Status', 'documentate' ) . '</span>'
			. '<span></span>'
			. '</div>';

		if ( ! $query->have_posts() ) {
			$html .= '<div class="dcta-vacio">' . esc_html__( 'No documents yet. Create the first one from “New document”.', 'documentate' ) . '</div>';

			return $html . '</div>';
		}

		foreach ( $query->posts as $post ) {
			$html .= self::render_fila( $post );
		}

		return $html . '</div>';
	}

	/**
	 * Render one document row.
	 *
	 * @param WP_Post $post Document.
	 * @return string
	 */
	private static function render_fila( $post ) {
		$chip = Documentate_App_Shell::estado_chip( $post->post_status );
		$tipos = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'names' ) );
		$tipo = ! is_wp_error( $tipos ) && ! empty( $tipos ) ? $tipos[0] : '—';
		$detalle_url = Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) );

		$editable = 'draft' === $post->post_status && current_user_can( 'edit_post', $post->ID );
		$accion_url = $editable ? Documentate_App_Editar::url( $post->ID ) : $detalle_url;
		$accion = $editable ? __( 'Continue', 'documentate' ) : __( 'View', 'documentate' );

		return '<div class="dcta-fila">'
			. '<div class="dcta-doc-nombre"><a href="' . esc_url( $detalle_url ) . '">' . esc_html( get_the_title( $post ) ) . '</a></div>'
			. '<span class="dcta-doc-tipo">' . esc_html( $tipo ) . '</span>'
			. '<span class="dcta-doc-fecha">' . esc_html( get_the_modified_date( 'j M', $post ) ) . '</span>'
			. '<span><span class="' . esc_attr( $chip['clase'] ) . '">' . esc_html( $chip['texto'] ) . '</span></span>'
			. '<a class="dcta-mini" href="' . esc_url( (string) $accion_url ) . '">' . esc_html( $accion ) . '</a>'
			. '</div>';
	}
}
