<?php
/**
 * Filter decisions behind the document list of the front-end application.
 *
 * The list view asks this class two things, and prints neither: which filters
 * the request means, and which query arguments — and counts — they stand for.
 * The list keeps the scope rules of the admin one: a scoped user sees the
 * documents of their category and its descendants, and the people who review
 * and approve for several áreas do so because their category sits above them
 * in the tree.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Filters and query arguments of the document list.
 */
class Documentate_App_Tray {

	/**
	 * Document counts already answered in this request, keyed by query args.
	 *
	 * @var array<string,int>
	 */
	private static $counts = array();

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
	const PER_PAGE = 100;

	/**
	 * Statuses a document can be in, in workflow order.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array_keys( Documentate_Statuses::labels() );
	}

	/**
	 * Status chip a list opens on, which is what waits for that rol.
	 *
	 * The jefatura de servicio opens on what waits for its approval, revisión
	 * on what waits for review and the área on what it has still to send.
	 *
	 * @return string Status key.
	 */
	public static function default_status() {
		if ( Documentate_Roles::is_head() ) {
			return 'pending';
		}

		return Documentate_Roles::is_management() ? 'en_gestion' : 'draft';
	}

	/**
	 * Status filter asked for by the request.
	 *
	 * "todos" is the explicit way of clearing the chip the list pre-selects.
	 *
	 * @return string Status key, "devuelto", or empty for every status.
	 */
	public static function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';

		if ( 'todos' === $status ) {
			return '';
		}

		$valid_values = array_merge( self::statuses(), array( 'devuelto' ) );

		return in_array( $status, $valid_values, true ) ? $status : self::default_status();
	}

	/**
	 * Área filter asked for by the request.
	 *
	 * Whoever looks after several áreas has one: revisión, jefatura de
	 * servicio and administración. A term outside the user's own scope is
	 * ignored, so the filter only ever narrows the list — it can never reach
	 * past the ámbito the list already stands for.
	 *
	 * @return int Category term ID, 0 when there is no filter.
	 */
	public static function current_area() {
		if ( ! Documentate_Roles::is_management() ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$area = isset( $_GET['area'] ) ? absint( $_GET['area'] ) : 0;

		return self::area_in_scope( $area ) ? $area : 0;
	}

	/**
	 * Whether a category is one the current user may narrow their list to.
	 *
	 * @param int $area Category term ID.
	 * @return bool
	 */
	private static function area_in_scope( $area ) {
		if ( $area <= 0 ) {
			return false;
		}

		$term_ids = Documentate_Scope_Filter::get_scope_term_ids();

		return null === $term_ids || in_array( (int) $area, $term_ids, true );
	}

	/**
	 * The áreas the current user may narrow their list to.
	 *
	 * The categories of their ámbito, or every one of them for
	 * administración. A single category is nothing to narrow: an área has
	 * only its own, and the select would be a control that changes nothing.
	 *
	 * @return WP_Term[]
	 */
	public static function areas() {
		$term_ids = Documentate_Scope_Filter::get_scope_term_ids();
		if ( is_array( $term_ids ) && count( $term_ids ) < 2 ) {
			return array();
		}

		$args = array(
			'taxonomy' => 'category',
			'hide_empty' => false,
			'orderby' => 'name',
		);
		if ( is_array( $term_ids ) ) {
			$args['include'] = $term_ids;
		}

		$terms = get_terms( $args );

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Whether the list cannot show anything because the user has no ámbito.
	 *
	 * The list is scoped; a restricted account without a category of its own
	 * has nothing to look at, and the list says so instead of drawing an
	 * empty table.
	 *
	 * @return bool
	 */
	public static function without_scope() {
		$term_ids = Documentate_Scope_Filter::get_scope_term_ids();

		return is_array( $term_ids ) && empty( $term_ids );
	}

	/**
	 * Taxonomy clause restricting a query to a set of categories.
	 *
	 * @param int[] $term_ids Category term IDs.
	 * @return array
	 */
	private static function category_tax_query( array $term_ids ) {
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
	 * Query arguments of the list.
	 *
	 * @param string $status Status chip, "devuelto", or empty for every status.
	 * @param int    $area   Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function query_args( $status, $area = 0 ) {
		$args = array(
			'post_type' => self::POST_TYPE,
			'post_status' => self::statuses(),
			'posts_per_page' => self::PER_PAGE,
			'orderby' => 'modified',
			'order' => 'DESC',
			'ignore_sticky_posts' => true,
		);

		if ( 'devuelto' === $status ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The returned mark is only written on returns, so the set is small.
			$args['meta_key'] = Documentate_Document_Data::META_RETURNED;
			$args['meta_compare'] = 'EXISTS';
			// A return leaves the document wherever it was sent back to, and
			// the most common one (administración → área) lands in a draft.
			// Narrowing by status would hide exactly those, so the chip would
			// promise a set the list cannot show.
			$args['post_status'] = array_keys( Documentate_Statuses::labels() );
		} elseif ( '' !== $status ) {
			$args['post_status'] = $status;
		}

		$term_ids = Documentate_Scope_Filter::get_scope_term_ids();
		if ( self::area_in_scope( $area ) ) {
			$term_ids = array( (int) $area );
		}

		if ( is_array( $term_ids ) ) {
			$args['tax_query'] = self::category_tax_query( $term_ids ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Scope restriction, bounded list.
		}

		return $args;
	}

	/**
	 * Count the documents a set of query arguments matches.
	 *
	 * @param array<string,mixed> $extra Query arguments on top of the defaults.
	 * @return int
	 */
	public static function count_documents( array $extra ) {
		// Rendering the list asks for the same set several times over: every
		// filter chip counts documents, and "Todos" counts what the chips
		// counted again. Each ask is a SQL_CALC_FOUND_ROWS query, so the
		// repeats are worth remembering for the rest of the request.
		// The posts "last changed" stamp is part of the key, so anything that
		// writes a post invalidates the memo the way core invalidates its own
		// query caches — no hook of ours to keep in step with.
		$cache_key = md5( wp_cache_get_last_changed( 'posts' ) . '|' . (string) wp_json_encode( $extra ) );
		if ( isset( self::$counts[ $cache_key ] ) ) {
			return self::$counts[ $cache_key ];
		}

		$args = array_merge(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => array_keys( Documentate_Statuses::labels() ),
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

		self::$counts[ $cache_key ] = (int) $query->found_posts;

		return self::$counts[ $cache_key ];
	}
}
