<?php
/**
 * Document Access Protection for Documentate.
 *
 * Ensures that documentate_document posts and their comments are not accessible
 * to anonymous users or subscribers via web or REST API.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Document_Access_Protection
 *
 * Provides comprehensive access protection for documentate_document CPT:
 * - Blocks frontend access for unauthorized users
 * - Filters queries to exclude documents from unauthorized users
 * - Adds extra REST API protection layer
 * - Ensures comments are protected
 */
class Documentate_Document_Access_Protection {
	/**
	 * The post type to protect.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Minimum capability required to access documents.
	 *
	 * @var string
	 */
	const REQUIRED_CAPABILITY = 'edit_posts';

	/**
	 * Initialize the class and register hooks.
	 */
	public function __construct() {
		// Frontend protection.
		add_action( 'template_redirect', array( $this, 'block_frontend_access' ) );

		// Query filtering.
		add_action( 'pre_get_posts', array( $this, 'filter_queries' ) );

		// REST API protection (extra layer even though show_in_rest is false).
		add_action( 'rest_api_init', array( $this, 'register_rest_protection' ) );

		// Register protected post types for comment protection.
		add_filter( 'documentate/protected_comment_post_types', array( $this, 'add_document_to_protected_types' ) );

		// Block comment form display for unauthorized users.
		add_filter( 'comments_open', array( $this, 'filter_comments_open' ), 10, 2 );

		// Filter comment queries to exclude document comments for unauthorized users.
		add_filter( 'comments_pre_query', array( $this, 'filter_comment_queries' ), 10, 2 );

		// Comment feeds (RSS) are public: keep document comments and events out of them.
		add_filter( 'comment_feed_where', array( $this, 'exclude_documents_from_comment_feed' ), 10, 2 );
	}

	/**
	 * Check if current user can access documents.
	 *
	 * @return bool True if user can access, false otherwise.
	 */
	public function user_can_access() {
		return is_user_logged_in() && current_user_can( self::REQUIRED_CAPABILITY );
	}

	/**
	 * Block frontend access to single documents for unauthorized users.
	 *
	 * @return void
	 */
	public function block_frontend_access() {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		if ( $this->user_can_access() ) {
			return;
		}

		// Return 404 for unauthorized access attempts.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		// Try to use theme's 404 template.
		$template = get_404_template();
		if ( $template ) {
			include $template;
			exit();
		}

		// Fallback if no 404 template.
		wp_die(
			esc_html__( 'You are not authorized to access this resource.', 'documentate' ),
			esc_html__( 'Access Denied', 'documentate' ),
			array( 'response' => 404 ),
		);
	}

	/**
	 * Filter queries to exclude documents for unauthorized users.
	 *
	 * @param WP_Query $query The query object.
	 * @return void
	 */
	public function filter_queries( $query ) {
		// Don't filter admin queries or if user has access.
		if ( is_admin() || $this->user_can_access() ) {
			return;
		}

		// Get current post types being queried.
		$post_type = $query->get( 'post_type' );

		// If querying our post type specifically, set to return nothing.
		if ( self::POST_TYPE === $post_type ) {
			$query->set( 'post__in', array( 0 ) );
			return;
		}

		// If querying 'any' or array of post types including ours, exclude it.
		if ( 'any' === $post_type ) {
			$query->set( 'post_type', $this->get_public_post_types() );
			return;
		}

		if ( is_array( $post_type ) && in_array( self::POST_TYPE, $post_type, true ) ) {
			$query->set( 'post_type', array_diff( $post_type, array( self::POST_TYPE ) ) );
		}
	}

	/**
	 * Get list of public post types excluding our protected type.
	 *
	 * @return array Array of post type names.
	 */
	private function get_public_post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types[ self::POST_TYPE ] );
		return array_values( $types );
	}

	/**
	 * Register REST API protection hooks.
	 *
	 * @return void
	 */
	public function register_rest_protection() {
		// Block any attempts to access documents via REST API.
		add_filter( 'rest_pre_dispatch', array( $this, 'block_rest_access' ), 10, 3 );

		// Filter post queries in REST context.
		add_filter( 'rest_post_query', array( $this, 'filter_rest_post_query' ), 10, 2 );
	}

	/**
	 * Block REST API access to documents for unauthorized users.
	 *
	 * @param mixed           $result  Dispatch result.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request object.
	 * @return mixed WP_Error if blocked, original result otherwise.
	 */
	public function block_rest_access( $result, $server, $request ) {
		if ( $this->user_can_access() ) {
			return $result;
		}

		$route = $request->get_route();

		// Block direct access to document endpoints (shouldn't exist but extra safety).
		if ( preg_match( '#^/wp/v2/' . self::POST_TYPE . '(?:/|$)#', $route ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to access this resource.', 'documentate' ),
				array( 'status' => rest_authorization_required_code() ),
			);
		}

		// Block access to single posts by ID if they are our post type.
		if ( preg_match( '#^/wp/v2/posts/(\d+)#', $route, $matches ) ) {
			$post_id = (int) $matches[1];
			if ( get_post_type( $post_id ) === self::POST_TYPE ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'You are not authorized to access this resource.', 'documentate' ),
					array( 'status' => rest_authorization_required_code() ),
				);
			}
		}

		return $result;
	}

	/**
	 * Filter REST post queries to exclude documents.
	 *
	 * @param array           $args    Query arguments.
	 * @param WP_REST_Request $request Request object.
	 * @return array Modified query arguments.
	 */
	public function filter_rest_post_query( $args, $request ) {
		if ( $this->user_can_access() ) {
			return $args;
		}

		// Ensure our post type is excluded.
		if ( ! empty( $args['post_type'] ) ) {
			if ( is_array( $args['post_type'] ) ) {
				$args['post_type'] = array_diff( $args['post_type'], array( self::POST_TYPE ) );
			} elseif ( self::POST_TYPE === $args['post_type'] ) {
				$args['post__in'] = array( 0 );
			}
		}

		return $args;
	}

	/**
	 * Add documentate_document to the list of protected post types for comment protection.
	 *
	 * @param array $post_types Current list of protected post types.
	 * @return array Modified list including documentate_document.
	 */
	public function add_document_to_protected_types( $post_types ) {
		if ( ! in_array( self::POST_TYPE, $post_types, true ) ) {
			$post_types[] = self::POST_TYPE;
		}
		return $post_types;
	}

	/**
	 * Filter whether comments are open for documents based on user access.
	 *
	 * @param bool $open    Whether comments are open.
	 * @param int  $post_id Post ID.
	 * @return bool False if user cannot access the document, original value otherwise.
	 */
	public function filter_comments_open( $open, $post_id ) {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return $open;
		}

		if ( ! $this->user_can_access() ) {
			return false;
		}

		return $open;
	}

	/**
	 * Filter comment queries to exclude document comments for unauthorized users.
	 *
	 * @param array|null       $comments Return value. Default null to continue with query.
	 * @param WP_Comment_Query $query    Comment query object.
	 * @return array|null Empty array to block, null to continue.
	 */
	public function filter_comment_queries( $comments, $query ) {
		if ( $this->user_can_access() ) {
			return $comments;
		}

		// Check if query is for a specific post.
		$query_vars = $query->query_vars;
		if ( ! empty( $query_vars['post_id'] ) ) {
			$post_id = (int) $query_vars['post_id'];
			if ( get_post_type( $post_id ) === self::POST_TYPE ) {
				return array();
			}
		}

		// For general queries, add filter to exclude document comments.
		add_filter( 'comments_clauses', array( $this, 'exclude_document_comments_clause' ) );

		return $comments;
	}

	/**
	 * Keep document comments (and workflow events) out of the comment feeds.
	 *
	 * The site-wide feed joins the posts table, so the post type can be
	 * excluded directly; the single-post feed does not join it, so the
	 * document's own feed is emptied instead.
	 *
	 * @param string   $where WHERE clause of the comment feed query.
	 * @param WP_Query $query The query being built.
	 * @return string
	 */
	public function exclude_documents_from_comment_feed( $where, $query ) {
		global $wpdb;

		if ( $query instanceof WP_Query && $query->is_singular() ) {
			$post = isset( $query->posts[0] ) ? $query->posts[0] : null;

			return $post instanceof WP_Post && self::POST_TYPE === $post->post_type ? $where . ' AND 1 = 0' : $where;
		}

		return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_type <> %s", self::POST_TYPE );
	}

	/**
	 * Add SQL clause to exclude comments from documents.
	 *
	 * @param array $clauses Query clauses.
	 * @return array Modified clauses.
	 */
	public function exclude_document_comments_clause( $clauses ) {
		// Remove immediately to avoid affecting other queries.
		remove_filter( 'comments_clauses', array( $this, 'exclude_document_comments_clause' ) );

		global $wpdb;

		// Add JOIN to posts table if not already present.
		if ( false === strpos( $clauses['join'], "{$wpdb->posts}" ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->posts} AS dap_posts ON dap_posts.ID = {$wpdb->comments}.comment_post_ID";
		}

		// Exclude comments from our post type.
		$clauses['where'] .= $wpdb->prepare(
			' AND (dap_posts.post_type IS NULL OR dap_posts.post_type != %s)',
			self::POST_TYPE,
		);

		return $clauses;
	}
}

// Instantiate the protection class.
new Documentate_Document_Access_Protection();
