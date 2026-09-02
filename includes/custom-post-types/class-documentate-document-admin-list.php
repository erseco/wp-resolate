<?php
/**
 * Admin list table behaviour for Documentate documents.
 *
 * Extracted from Documentate_Documents: the filters above the list, the
 * custom columns, the archived view and the styles that hold them together
 * are one job, and none of it is reached from the editor or the save path.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

/**
 * Builds the filters, columns and views on the documents list table.
 */
class Documentate_Document_Admin_List {

	/**
	 * Register the hooks this class answers to.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'restrict_manage_posts', array( $this, 'add_admin_filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_admin_filters' ) );
		add_filter( 'disable_categories_dropdown', array( $this, 'disable_native_categories_dropdown' ), 10, 2 );
		add_filter( 'manage_documentate_document_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_documentate_document_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
		add_filter( 'manage_edit-documentate_document_sortable_columns', array( $this, 'add_sortable_columns' ) );
		add_action( 'admin_head', array( $this, 'add_admin_list_styles' ) );
		add_filter( 'views_edit-documentate_document', array( $this, 'add_archived_view' ) );
	}

	/**
	 * Add custom columns to the admin list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		// Remove default taxonomy columns (we add custom sortable ones).
		unset( $columns['categories'] );
		unset( $columns['taxonomy-documentate_doc_type'] );

		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Insert doc_type and nombre_interno columns after title.
			if ( 'title' === $key ) {
				$new_columns['nombre_interno'] = 'Nombre interno';
				$new_columns['doc_type'] = 'Tipo de documento';
			}

			// Insert category column after author.
			if ( 'author' === $key ) {
				$new_columns['doc_category'] = 'Categoría';
			}
		}

		return $new_columns;
	}
	/**
	 * Add filter dropdowns to the admin list table.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Location of the extra table nav markup: 'top' or 'bottom'.
	 * @return void
	 */
	public function add_admin_filters( $post_type, $which ) {
		if ( 'documentate_document' !== $post_type || 'top' !== $which ) {
			return;
		}

		$this->render_author_filter();

		$this->render_term_filter(
			array(
				'taxonomy' => 'documentate_doc_type',
				'name' => 'documentate_doc_type',
				'id' => 'filter-by-doc-type',
				'all_label' => 'Todos los tipos de documento',
			)
		);

		// Cap the number of category options so the dropdown never
		// materialises an unbounded list of site categories.
		$this->render_term_filter(
			array(
				'taxonomy' => 'category',
				'name' => 'category_name',
				'id' => 'filter-by-category',
				'all_label' => 'Todas las categorías',
				'number' => 200,
			)
		);
	}
	/**
	 * Add CSS styles for the admin list table columns.
	 *
	 * @return void
	 */
	public function add_admin_list_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-documentate_document' !== $screen->id ) {
			return;
		}

		echo '<style>
			/* Column widths */
			.post-type-documentate_document .column-doc_type { width: 140px; }
			.post-type-documentate_document .column-author { width: 120px; }
			.post-type-documentate_document .column-doc_category { width: 120px; }
			.post-type-documentate_document .column-date { width: 100px; }

			/* Quick Edit: hide date, password, private and status fields */
			.post-type-documentate_document .inline-edit-row .inline-edit-date,
			.post-type-documentate_document .inline-edit-row .inline-edit-password-input,
			.post-type-documentate_document .inline-edit-row .inline-edit-private,
			.post-type-documentate_document .inline-edit-row .inline-edit-or,
			.post-type-documentate_document .inline-edit-row .inline-edit-status {
				display: none !important;
			}

			/* Quick Edit: make doc_type taxonomy read-only appearance */
			.post-type-documentate_document .inline-edit-row .inline-edit-col .inline-edit-group.documentate_doc_type-checklist {
				pointer-events: none;
				opacity: 0.6;
			}
		</style>';

		// JavaScript for Quick Edit behavior.
		?>
		<script>
		(function($) {
			// Hook into Quick Edit open.
			$(document).on('click', '.editinline', function() {
				var $row = $(this).closest('tr');
				var postId = $row.attr('id').replace('post-', '');
				var postStatus = $row.find('.post_status').text() || $row.find('.status').text();

				setTimeout(function() {
					var $editRow = $('#edit-' + postId);

					// Hide password field container.
					$editRow.find('input.inline-edit-password-input').closest('label').hide();

					// Make doc_type read-only (textarea and checkboxes).
					$editRow.find('textarea[data-wp-taxonomy="documentate_doc_type"]').prop('disabled', true).css('background', '#f0f0f0');
					$editRow.find('.documentate_doc_type-checklist input[type="checkbox"]').prop('disabled', true);

					// If post is published or archived, disable title.
					if (postStatus === 'publish' || postStatus === 'archived' || $row.hasClass('status-publish') || $row.hasClass('status-archived')) {
						$editRow.find('input[name="post_title"]').prop('readonly', true).css('background', '#f0f0f0');
					}
				}, 50);
			});
		})(jQuery);
		</script>
		<?php
	}
	/**
	 * Add "Archived" link to list table views.
	 *
	 * @param array $views Existing views.
	 * @return array Modified views.
	 */
	public function add_archived_view( $views ) {
		$count = wp_count_posts( 'documentate_document' );
		$archived_count = isset( $count->archived ) ? intval( $count->archived ) : 0;

		if ( $archived_count > 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = isset( $_GET['post_status'] ) && 'archived' === sanitize_key( $_GET['post_status'] )
				? ' class="current"'
				: '';
			$views['archived'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url(
					add_query_arg(
						array(
							'post_type' => 'documentate_document',
							'post_status' => 'archived',
						),
						admin_url( 'edit.php' )
					)
				),
				$current,
				esc_html( 'Archivado' ),
				$archived_count,
			);
		}

		return $views;
	}
	/**
	 * Add sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function add_sortable_columns( $columns ) {
		$columns['author'] = 'author_name';
		$columns['doc_type'] = 'doc_type';
		$columns['doc_category'] = 'category_name';

		return $columns;
	}
	/**
	 * Apply admin filters and sorting to the query.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public function apply_admin_filters( $query ) {
		if ( ! $this->is_documents_list_query( $query ) ) {
			return;
		}

		$this->hide_archived_by_default( $query );

		$orderby = $query->get( 'orderby' );

		// Handle sorting by author.
		if ( 'author_name' === $orderby ) {
			$query->set( 'orderby', 'author' );
		}

		// Sorting by a term name needs joins the default query cannot express.
		$sortable_taxonomies = array(
			'doc_type' => array( 'documentate_doc_type', 'dt' ),
			'category_name' => array( 'category', 'ct' ),
		);

		if ( isset( $sortable_taxonomies[ $orderby ] ) ) {
			list( $taxonomy, $alias ) = $sortable_taxonomies[ $orderby ];
			$this->sort_by_term_name( $orderby, $taxonomy, $alias );
		}
	}
	/**
	 * Disable WordPress' native category dropdown for the documents list table.
	 *
	 * The 'category' taxonomy is attached to documentate_document, so WordPress
	 * core renders a native `cat` dropdown (numeric term IDs). The plugin also
	 * renders its own `category_name` dropdown (slugs). Keeping both duplicates
	 * the control and can build conflicting taxonomy queries, so the native one
	 * is suppressed and the plugin's `category_name` filter is the single source
	 * of category filtering.
	 *
	 * @param bool   $disable   Whether to disable the categories dropdown.
	 * @param string $post_type Post type slug for the current list table.
	 * @return bool True to disable the native dropdown for our post type.
	 */
	public function disable_native_categories_dropdown( $disable, $post_type ) {
		if ( 'documentate_document' === $post_type ) {
			return true;
		}

		return $disable;
	}
	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'nombre_interno' === $column ) {
			// nombre_corto() falls back to the post title, which would just repeat
			// the adjacent Title column: only show it when an internal name is set.
			$nombre = '' === Documentate_Documento::nombre_interno( $post_id ) ? '' : Documentate_Documento::nombre_corto( $post_id );
			echo '' === $nombre ? '—' : esc_html( $nombre );
		}

		if ( 'doc_type' === $column ) {
			$terms = get_the_terms( $post_id, 'documentate_doc_type' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$term = $terms[0];
				$color = get_term_meta( $term->term_id, 'documentate_type_color', true );
				$style = $color ? 'background-color:' . esc_attr( $color ) . ';color:#fff;padding:2px 6px;border-radius:3px;' : '';
				printf(
					'<a href="%s" style="%s">%s</a>',
					esc_url( add_query_arg( 'documentate_doc_type', $term->slug, admin_url( 'edit.php?post_type=documentate_document' ) ) ),
					esc_attr( $style ),
					esc_html( $term->name ),
				);
			} else {
				echo '—';
			}
		}

		if ( 'doc_category' === $column ) {
			$terms = get_the_terms( $post_id, 'category' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$links = array();
				foreach ( $terms as $term ) {
					$links[] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( 'category_name', $term->slug, admin_url( 'edit.php?post_type=documentate_document' ) ) ),
						esc_html( $term->name ),
					);
				}
				echo wp_kses_post( implode( ', ', $links ) );
			} else {
				echo '—';
			}
		}
	}
	/**
	 * Exclude archived documents unless the view asks for them.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	private function hide_archived_by_default( $query ) {
		if ( ! empty( $query->get( 'post_status' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_status'] ) && 'archived' === sanitize_key( $_GET['post_status'] ) ) {
			return;
		}

		// Default view: exclude archived.
		$query->set( 'post_status', array( 'publish', 'pending', 'en_gestion', 'draft', 'private', 'future' ) );
	}
	/**
	 * Whether this query is the documents list table's main query.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function is_documents_list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'edit-documentate_document' === $screen->id;
	}
	/**
	 * Render one list table filter dropdown.
	 *
	 * Emits nothing when there is nothing to choose from, so an empty taxonomy
	 * does not leave a stray control in the toolbar.
	 *
	 * @param array $args name, id, all_label, current and options (value => label).
	 * @return void
	 */
	private function render_admin_filter_select( array $args ) {
		if ( empty( $args['options'] ) ) {
			return;
		}

		printf(
			'<select name="%s" id="%s">',
			esc_attr( $args['name'] ),
			esc_attr( $args['id'] )
		);
		printf( '<option value="">%s</option>', esc_html( $args['all_label'] ) );

		foreach ( $args['options'] as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( (string) $args['current'], (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}
	/**
	 * Render the author dropdown for the documents list table.
	 *
	 * @return void
	 */
	private function render_author_filter() {
		// The has_published_posts query joins on the posts table, so cache it
		// briefly; a slightly stale author dropdown is acceptable.
		$authors = get_transient( 'documentate_admin_author_filter' );
		if ( false === $authors ) {
			$authors = get_users(
				array(
					'has_published_posts' => array( 'documentate_document' ),
					'fields' => array( 'ID', 'display_name' ),
					'orderby' => 'display_name',
				)
			);
			set_transient( 'documentate_admin_author_filter', $authors, 5 * MINUTE_IN_SECONDS );
		}

		$options = array();
		foreach ( (array) $authors as $author ) {
			$options[ absint( $author->ID ) ] = $author->display_name;
		}

		$this->render_admin_filter_select(
			array(
				'name' => 'author',
				'id' => 'filter-by-author',
				'all_label' => 'Todos los autores',
				'current' => isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0,
				'options' => $options,
			)
		);
	}
	/**
	 * Render a taxonomy dropdown for the documents list table.
	 *
	 * @param array $args taxonomy, name, id, all_label and an optional number cap.
	 * @return void
	 */
	private function render_term_filter( array $args ) {
		$query = array(
			'taxonomy' => $args['taxonomy'],
			'hide_empty' => false,
		);
		if ( isset( $args['number'] ) ) {
			$query['number'] = $args['number'];
		}

		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$options = array();
		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		$this->render_admin_filter_select(
			array(
				'name' => $args['name'],
				'id' => $args['id'],
				'all_label' => $args['all_label'],
				'current' => isset( $_GET[ $args['name'] ] )
					? sanitize_text_field( wp_unslash( $_GET[ $args['name'] ] ) )
					: '',
				'options' => $options,
			)
		);
	}
	/**
	 * Order the list table by the name of a term in the given taxonomy.
	 *
	 * @param string $orderby  Value of the orderby query var this applies to.
	 * @param string $taxonomy Taxonomy whose term name to sort on.
	 * @param string $alias    Short prefix for the SQL table aliases.
	 * @return void
	 */
	private function sort_by_term_name( $orderby, $taxonomy, $alias ) {
		add_filter(
			'posts_clauses',
			static function ( $clauses, $wp_query ) use ( $orderby, $taxonomy, $alias ) {
				global $wpdb;

				if ( $wp_query->get( 'orderby' ) !== $orderby ) {
					return $clauses;
				}

				$order = 'ASC' === strtoupper( $wp_query->get( 'order' ) ) ? 'ASC' : 'DESC';

				$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS {$alias}r"
					. " ON ({$wpdb->posts}.ID = {$alias}r.object_id)";
				// $alias is an internal table alias taken from the hardcoded map
				// in apply_admin_filters(), never from a request; the taxonomy is
				// the only value that varies and it goes through a placeholder.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->term_taxonomy} AS {$alias}t"
					. " ON ({$alias}r.term_taxonomy_id = {$alias}t.term_taxonomy_id AND {$alias}t.taxonomy = %s)",
					$taxonomy
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$clauses['join'] .= " LEFT JOIN {$wpdb->terms} AS {$alias}n"
					. " ON ({$alias}t.term_id = {$alias}n.term_id)";
				$clauses['orderby'] = "{$alias}n.name {$order}, " . $clauses['orderby'];

				return $clauses;
			},
			10,
			2,
		);
	}
}
