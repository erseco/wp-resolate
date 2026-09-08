<?php
/**
 * Tray decisions behind the document list of the front-end application.
 *
 * The list view asks this class three things, and prints none of them: which
 * tray the request means, which filters are active inside it, and which query
 * arguments — and counts — that combination stands for. Every tray keeps the
 * scope rules of the admin list: a scoped user sees the documents of their
 * category and its descendants, and the people who review and approve for
 * several áreas do so because their category sits above them in the tree.
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
	 * Statuses of every tray, in workflow order.
	 *
	 * @param string $tray Tray key.
	 * @return string[]
	 */
	public static function statuses( $tray ) {
		$all = array_keys( Documentate_Statuses::labels() );

		// The review trays start where the pipeline starts: a draft belongs to
		// its área until it is sent.
		return 'mis' === $tray || 'todos' === $tray
			? $all
			: array_values( array_diff( $all, array( 'draft' ) ) );
	}

	/**
	 * Trays this person may open, and the one they land on.
	 *
	 * Whoever reviews or approves lands on every document of their scope
	 * ("todos"), with the tray of what waits for them beside it; the área
	 * only has its own documents.
	 *
	 * @return string[] First element is the default tray.
	 */
	public static function trays() {
		if ( Documentate_Roles::is_head() ) {
			return array( 'todos', 'revision' );
		}

		return Documentate_Roles::is_management() ? array( 'todos', 'revisar' ) : array( 'mis' );
	}

	/**
	 * Tray asked for by the request, falling back to the default one.
	 *
	 * @return string
	 */
	public static function current() {
		$trays = self::trays();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$tray = isset( $_GET['bandeja'] ) ? sanitize_key( wp_unslash( $_GET['bandeja'] ) ) : '';

		return in_array( $tray, $trays, true ) ? $tray : $trays[0];
	}

	/**
	 * Status chip pre-selected in a tray when the request names none.
	 *
	 * @param string $tray Tray key.
	 * @return string Status key, or empty for "every status".
	 */
	private static function default_status( $tray ) {
		$defaults = array(
			'revisar' => 'en_gestion',
			'revision' => 'pending',
		);

		return isset( $defaults[ $tray ] ) ? $defaults[ $tray ] : '';
	}

	/**
	 * Status filter asked for by the request.
	 *
	 * "todos" is the explicit way of clearing the chip a tray pre-selects.
	 *
	 * @param string $tray Tray key.
	 * @return string Status key, "devuelto", or empty for every status.
	 */
	public static function current_status( $tray ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';

		if ( 'todos' === $status ) {
			return '';
		}

		$valid_values = array_merge( self::statuses( $tray ), array( 'devuelto' ) );

		return in_array( $status, $valid_values, true ) ? $status : self::default_status( $tray );
	}

	/**
	 * Área filter asked for by the request (administración only).
	 *
	 * @return int Category term ID, 0 when there is no filter.
	 */
	public static function current_area() {
		if ( ! Documentate_Roles::is_administration() ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return isset( $_GET['area'] ) ? absint( $_GET['area'] ) : 0;
	}

	/**
	 * Whether the trays cannot show anything because the user has no ámbito.
	 *
	 * Every tray is scoped; a restricted account without a category of its
	 * own has nothing to look at, and the list says so instead of drawing an
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
	 * Query arguments of a tray.
	 *
	 * @param string $tray   Tray key (mis, revisar, revision, todos).
	 * @param string $status Status chip, "devuelto", or empty for every status.
	 * @param int    $area   Category term ID to narrow by, 0 for every área.
	 * @return array<string,mixed>
	 */
	public static function query_args( $tray, $status, $area = 0 ) {
		$args = array(
			'post_type' => self::POST_TYPE,
			'post_status' => self::statuses( $tray ),
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
			// The tray statuses would hide exactly those, so the counter and
			// the chip would promise a set the tray cannot show.
			$args['post_status'] = array_keys( Documentate_Statuses::labels() );
		} elseif ( '' !== $status ) {
			$args['post_status'] = $status;
		}

		$term_ids = Documentate_Scope_Filter::get_scope_term_ids();
		if ( $area > 0 ) {
			$term_ids = array( $area );
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
		// Rendering one tray asks for the same set several times over — the
		// counter row, the "devueltos" suffix, the filter chips and the tab
		// badge all count documents, and several of them count the very same
		// documents. Each ask is a SQL_CALC_FOUND_ROWS query, so the repeats
		// are worth remembering for the rest of the request.
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
