<?php
/**
 * Tray decisions behind the document list of the front-end application.
 *
 * The list view asks this class three things, and prints none of them: which
 * tray the request means, which filters are active inside it, and which query
 * arguments — and counts — that combination stands for. "Mis documentos" keeps
 * the scope rules of the admin list (a scoped user sees the documents of their
 * category and its descendants), while the review trays of gestión documental
 * and administración show every área, because reviewing is precisely the job of
 * looking outside your own.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Trays, filters and query arguments of the document list.
 */
class Documentate_App_Bandeja {

	/**
	 * Post type of the documents.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Documents drawn in one page of the list.
	 *
	 * @var int
	 */
	const POR_PAGINA = 100;

	/**
	 * Statuses of every tray, in workflow order.
	 *
	 * @param string $bandeja Tray key.
	 * @return string[]
	 */
	public static function estados( $bandeja ) {
		$todos = array_keys( Documentate_Estados::etiquetas() );

		// The review trays start where the pipeline starts: a draft belongs to
		// its área until it is sent.
		return 'mis' === $bandeja || 'todos' === $bandeja
			? $todos
			: array_values( array_diff( $todos, array( 'draft' ) ) );
	}

	/**
	 * Trays this person may open, and the one they land on.
	 *
	 * @return string[] First element is the default tray.
	 */
	public static function bandejas() {
		if ( Documentate_Roles::es_administracion() ) {
			return array( 'todos', 'revision' );
		}

		return Documentate_Roles::es_gestion() ? array( 'mis', 'revisar' ) : array( 'mis' );
	}

	/**
	 * Tray asked for by the request, falling back to the default one.
	 *
	 * @return string
	 */
	public static function actual() {
		$bandejas = self::bandejas();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$bandeja = isset( $_GET['bandeja'] ) ? sanitize_key( wp_unslash( $_GET['bandeja'] ) ) : '';

		return in_array( $bandeja, $bandejas, true ) ? $bandeja : $bandejas[0];
	}

	/**
	 * Status chip pre-selected in a tray when the request names none.
	 *
	 * @param string $bandeja Tray key.
	 * @return string Status key, or empty for "every status".
	 */
	private static function estado_por_defecto( $bandeja ) {
		$defectos = array(
			'revisar' => 'en_gestion',
			'revision' => 'pending',
		);

		return isset( $defectos[ $bandeja ] ) ? $defectos[ $bandeja ] : '';
	}

	/**
	 * Status filter asked for by the request.
	 *
	 * "todos" is the explicit way of clearing the chip a tray pre-selects.
	 *
	 * @param string $bandeja Tray key.
	 * @return string Status key, "devuelto", or empty for every status.
	 */
	public static function estado_actual( $bandeja ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$estado = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';

		if ( 'todos' === $estado ) {
			return '';
		}

		$validos = array_merge( self::estados( $bandeja ), array( 'devuelto' ) );

		return in_array( $estado, $validos, true ) ? $estado : self::estado_por_defecto( $bandeja );
	}

	/**
	 * Área filter asked for by the request (administración only).
	 *
	 * @return int Category term ID, 0 when there is no filter.
	 */
	public static function area_actual() {
		if ( ! Documentate_Roles::es_administracion() ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_GET['area'] ) ? absint( $_GET['area'] ) : 0;
	}

	/**
	 * Whether the tray cannot show anything because the user has no ámbito.
	 *
	 * Only "mis documentos" is scoped; a restricted account without a category
	 * of its own has nothing to look at there, and the list says so instead of
	 * drawing an empty table.
	 *
	 * @param string $bandeja Tray key.
	 * @return bool
	 */
	public static function sin_ambito( $bandeja ) {
		if ( 'mis' !== $bandeja ) {
			return false;
		}

		$term_ids = self::scope_term_ids();

		return is_array( $term_ids ) && empty( $term_ids );
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
	 * Taxonomy clause restricting a query to a set of categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return array
	 */
	private static function tax_query_categorias( array $term_ids ) {
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
	 * Query arguments of a tray.
	 *
	 * @param string $bandeja Tray key (mis, revisar, revision, todos).
	 * @param string $estado  Status chip, "devuelto", or empty for every status.
	 * @param int    $area    Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function argumentos_consulta( $bandeja, $estado, $area = 0 ) {
		$args = array(
			'post_type' => self::POST_TYPE,
			'post_status' => self::estados( $bandeja ),
			'posts_per_page' => self::POR_PAGINA,
			'orderby' => 'modified',
			'order' => 'DESC',
			'ignore_sticky_posts' => true,
		);

		if ( 'devuelto' === $estado ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The returned mark is only written on returns, so the set is small.
			$args['meta_key'] = Documentate_Documento::META_DEVUELTO;
			$args['meta_compare'] = 'EXISTS';
			// A return leaves the document wherever it was sent back to, and
			// the most common one (administración → área) lands in a draft.
			// The tray statuses would hide exactly those, so the counter and
			// the chip would promise a set the tray cannot show.
			$args['post_status'] = array_keys( Documentate_Estados::etiquetas() );
		} elseif ( '' !== $estado ) {
			$args['post_status'] = $estado;
		}

		$term_ids = 'mis' === $bandeja ? self::scope_term_ids() : null;
		if ( $area > 0 ) {
			$term_ids = array( $area );
		}

		if ( is_array( $term_ids ) ) {
			$args['tax_query'] = self::tax_query_categorias( $term_ids ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scope restriction, bounded list.
		}

		return $args;
	}

	/**
	 * Count the documents a set of query arguments matches.
	 *
	 * @param array<string,mixed> $extra Query arguments on top of the defaults.
	 * @return int
	 */
	public static function contar( array $extra ) {
		$args = array_merge(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array_keys( Documentate_Estados::etiquetas() ),
				'ignore_sticky_posts' => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			),
			$extra,
			array(
				'fields' => 'ids',
				'posts_per_page' => 1,
				'no_found_rows' => false,
			)
		);

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}
}
