<?php
/**
 * The file that defines the Documents custom post type for Resolate.
 *
 * This CPT is the base for generating official documents with structured
 * sections stored as post meta and a document type taxonomy that defines
 * the available template fields.
 *
 * @link       https://github.com/erseco/wp-resolate
 * @since      0.1.0
 *
 * @package    resolate
 * @subpackage Resolate/includes/custom-post-types
 */

/**
 * Class to handle the Resolate Documents custom post type
 */
class Resolate_Documents {

		/**
		 * Maximum number of items allowed per array field.
		 */
		const ARRAY_FIELD_MAX_ITEMS = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Define hooks.
	 */
	private function define_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );

		// Meta boxes.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_resolate_doc', array( $this, 'save_meta_boxes' ) );
		add_action( 'save_post_resolate_doc', array( $this, 'save_dynamic_fields' ) );

		add_action( 'admin_notices', array( $this, 'display_save_errors' ) );

		/**
		 * Revisions: copy meta to the single revision WordPress creates.
		 * Do NOT call wp_save_post_revision() manually.
		 */
		add_action( 'wp_save_post_revision', array( $this, 'copy_meta_to_revision' ), 10, 2 );

		/**
		 * Revisions: restore meta from a selected revision.
		 */
		add_action( 'wp_restore_post_revision', array( $this, 'restore_meta_from_revision' ), 10, 2 );

		/**
		 * Optional: limit number of revisions for this CPT only.
		 */
		add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions_for_cpt' ), 10, 2 );
		// Compose Gutenberg-friendly content before saving to ensure revision UI diffs.
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data_compose_content' ), 10, 2 );
		// Ensure a revision is created even if only meta fields change.
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'force_revision_on_meta' ), 10, 3 );

		$this->register_revision_ui();
	}

		/**
		 * Return the list of custom meta keys used by this CPT for a given post.
		 *
		 * Includes static section fields and dynamic fields defined by the selected
		 * document type, if any.
		 *
		 * @param int $post_id Post ID.
		 * @return string[]
		 */
	private function get_meta_fields_for_post( $post_id ) {
		   $fields = array();

		$dynamic = $this->get_dynamic_fields_schema_for_post( $post_id );
		$known   = array();
		if ( ! empty( $dynamic ) ) {
			foreach ( $dynamic as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$key        = 'resolate_field_' . sanitize_key( $def['slug'] );
				$fields[]   = $key;
				$known[ $key ] = true;
			}
		}

		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			foreach ( $all_meta as $meta_key => $values ) {
				if ( 0 !== strpos( $meta_key, 'resolate_field_' ) ) {
					continue;
				}
				if ( isset( $known[ $meta_key ] ) ) {
					continue;
				}
				$fields[] = $meta_key;
			}
		}

		return $fields;
	}

	/**
	 * Copy custom meta to the newly created revision.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Revision post ID.
	 * @return void
	 */
	public function copy_meta_to_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'resolate_doc' !== $parent->post_type ) {
			return;
		}

		foreach ( $this->get_meta_fields_for_post( $post_id ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			// Store only if it has something meaningful (empty array/string skipped).
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( '' === trim( (string) $value ) ) {
					continue;
			}
			// Store meta on the revision row, not on the parent.
			add_metadata( 'post', $revision_id, $key, $value, true );
		}
	}

	/**
	 * Restore custom meta when a revision is restored.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 * @return void
	 */
	public function restore_meta_from_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'resolate_doc' !== $parent->post_type ) {
			return;
		}

		foreach ( $this->get_meta_fields_for_post( $post_id ) as $key ) {
			$value = get_metadata( 'post', $revision_id, $key, true );
			if ( null !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Limit number of revisions for this CPT (optional).
	 *
	 * @param int     $num  Default number of revisions.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions_for_cpt( $num, $post ) {
		if ( $post && 'resolate_doc' === $post->post_type ) {
			return 15; // Adjust to your needs.
		}
		return $num;
	}

	/**
	 * Force creating a revision on save even if core fields don't change.
	 *
	 * @param bool    $post_has_changed Default change detection.
	 * @param WP_Post $last_revision    Last revision object.
	 * @param WP_Post $post             Current post object.
	 * @return bool
	 */
	public function force_revision_on_meta( $post_has_changed, $last_revision, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $post && 'resolate_doc' === $post->post_type ) {
			return true;
		}
		return $post_has_changed;
	}




	/**
	 * Register revision UI fields and providers so the diff shows meta changes.
	 *
	 * Hook this in define_hooks().
	 */
	private function register_revision_ui() {
				add_filter( '_wp_post_revision_fields', array( $this, 'add_revision_fields' ), 10, 2 );
	}

		/**
		 * Add custom meta fields to the revisions UI.
		 *
		 * @param array   $fields Existing fields.
		 * @param WP_Post $post   Post being compared.
		 * @return array
		 */
	public function add_revision_fields( $fields, $post ) {
		   return $fields;
	}

	/**
	 * Generic provider for WYSIWYG meta fields in revisions diff.
	 *
	 * @param string  $value     Current value (unused).
	 * @param WP_Post $revision  Revision post object.
	 * @return string
	 */
	public function revision_field_value( $value, $revision = null ) {
		$field = str_replace( '_wp_post_revision_field_', '', current_filter() );
		// Resolve revision ID from variable callback signatures.
		$rev_id = 0;
		$args = func_get_args();
		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && isset( $arg->ID ) ) {
				$rev_id = intval( $arg->ID );
				break;
			}
			if ( is_array( $arg ) && isset( $arg['ID'] ) && is_numeric( $arg['ID'] ) ) {
				$maybe = get_post( intval( $arg['ID'] ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
			if ( is_numeric( $arg ) ) {
				$maybe = get_post( intval( $arg ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
		}
		if ( $rev_id <= 0 ) {
			return '';
		}
		// Get the meta stored on the REVISION row.
		$raw = get_metadata( 'post', $rev_id, $field, true );
		return $this->normalize_html_for_diff( $raw );
	}

	/**
	 * Normalize HTML to plain text to improve wp_text_diff visibility.
	 *
	 * @param string $html HTML input.
	 * @return string
	 */
	private function normalize_html_for_diff( $html ) {
		if ( '' === $html ) {
			return '';
		}
		// Decode entities, strip tags, collapse whitespace, keep line breaks sensibly.
		$text = wp_specialchars_decode( (string) $html );
		// Preserve basic block separations.
		$text = preg_replace( '/<(?:p|div|br|li|h[1-6])[^>]*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}




	/**
	 * Register the Documents custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => __( 'Documentos', 'resolate' ),
			'singular_name'         => __( 'Documento', 'resolate' ),
			'menu_name'             => __( 'Documentos', 'resolate' ),
			'name_admin_bar'        => __( 'Documento', 'resolate' ),
			'add_new'               => __( 'Añadir nuevo', 'resolate' ),
			'add_new_item'          => __( 'Añadir nuevo documento', 'resolate' ),
			'new_item'              => __( 'Nuevo documento', 'resolate' ),
			'edit_item'             => __( 'Editar documento', 'resolate' ),
			'view_item'             => __( 'Ver documento', 'resolate' ),
			'all_items'             => __( 'Todas los documentos', 'resolate' ),
			'search_items'          => __( 'Buscar documentos', 'resolate' ),
			'not_found'             => __( 'No se han encontrado documentos.', 'resolate' ),
			'not_found_in_trash'    => __( 'No hay documentos en la papelera.', 'resolate' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_position'      => 25,
			'menu_icon'          => 'dashicons-media-document',
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'revisions', 'comments' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'show_in_rest'       => false,
		);

		register_post_type( 'resolate_doc', $args );
	}

		/**
		 * Register taxonomies used by the documents CPT.
		 */
	public function register_taxonomies() {
			// Tipos de documento (definen plantillas y campos personalizados para el documento).
			$types_labels = array(
				'name'              => __( 'Tipos de documento', 'resolate' ),
				'singular_name'     => __( 'Tipo de documento', 'resolate' ),
				'search_items'      => __( 'Buscar tipos', 'resolate' ),
				'all_items'         => __( 'Todos los tipos', 'resolate' ),
				'edit_item'         => __( 'Editar tipo', 'resolate' ),
				'update_item'       => __( 'Actualizar tipo', 'resolate' ),
				'add_new_item'      => __( 'Añadir nuevo tipo', 'resolate' ),
				'new_item_name'     => __( 'Nuevo tipo', 'resolate' ),
				'menu_name'         => __( 'Tipos de documento', 'resolate' ),
			);
			register_taxonomy(
				'resolate_doc_type',
				array( 'resolate_doc' ),
				array(
					'hierarchical'      => false,
					'labels'            => $types_labels,
					'show_ui'           => true,
					'show_admin_column' => true,
					'query_var'         => true,
					'rewrite'           => false,
					'show_in_rest'      => false,
					// We'll use a custom metabox to prevent editing after first save.
					'meta_box_cb'       => false,
				)
			);
	}

	/**
	 * Disable block editor for this CPT (use classic meta boxes).
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		if ( 'resolate_doc' === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Register admin meta boxes for document sections.
	 */
	public function register_meta_boxes() {
		// Tipo de documento selector (bloqueado tras la creación inicial).
		add_meta_box(
			'resolate_doc_type',
			__( 'Tipo de documento', 'resolate' ),
			array( $this, 'render_type_metabox' ),
			'resolate_doc',
			'side',
			'high'
		);

		add_meta_box(
			'resolate_sections',
			__( 'Secciones del documento', 'resolate' ),
			array( $this, 'render_sections_metabox' ),
			'resolate_doc',
			'normal',
			'default'
		);

		add_meta_box(
			'resolate_dynamic_fields',
			__( 'Campos del Documento (ODT)', 'wp-resolate' ),
			array( $this, 'render_dynamic_fields_metabox' ),
			'resolate_doc',
			'normal',
			'high'
		);
	}

	/**
	 * Render the document type selector metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_type_metabox( $post ) {
		wp_nonce_field( 'resolate_type_nonce', 'resolate_type_nonce' );

		$assigned = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$current  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy'   => 'resolate_doc_type',
				'hide_empty' => false,
			)
		);

		if ( ! $terms || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html__( 'No hay tipos de documento definidos. Crea uno en Tipos de documento.', 'resolate' ) . '</p>';
			return;
		}

		$locked = ( $current > 0 && 'auto-draft' !== $post->post_status );
		echo '<p class="description">' . esc_html__( 'Elige el tipo al crear el documento. No se podrá cambiar más tarde.', 'resolate' ) . '</p>';
		if ( $locked ) {
			$term = get_term( $current, 'resolate_doc_type' );
			echo '<p><strong>' . esc_html__( 'Tipo seleccionado:', 'resolate' ) . '</strong> ' . esc_html( $term ? $term->name : '' ) . '</p>';
			echo '<input type="hidden" name="resolate_doc_type" value="' . esc_attr( (string) $current ) . '" />';
		} else {
			echo '<select name="resolate_doc_type" class="widefat">';
			echo '<option value="">' . esc_html__( 'Selecciona un tipo…', 'resolate' ) . '</option>';
			foreach ( $terms as $t ) {
				echo '<option value="' . esc_attr( (string) $t->term_id ) . '" ' . selected( $current, $t->term_id, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			echo '</select>';
		}
	}

	/**
	 * Render the sections meta box (dynamic by document type, with legacy fallback).
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_sections_metabox( $post ) {
			wp_nonce_field( 'resolate_sections_nonce', 'resolate_sections_nonce' );

			$schema = $this->get_dynamic_fields_schema_for_post( $post->ID );
		if ( empty( $schema ) ) {
				echo '<div class="resolate-sections">';
				echo '<p class="description">' . esc_html__( 'Configura un tipo de documento con campos para poder editar su contenido.', 'resolate' ) . '</p>';
				$unknown = $this->collect_unknown_dynamic_fields( $post->ID, array() );
				$this->render_unknown_dynamic_fields_ui( $unknown );
				echo '</div>';
				return;
		}

			$stored_fields   = $this->get_structured_field_values( $post->ID );
			$known_meta_keys = array();

			echo '<div class="resolate-sections">';
		foreach ( $schema as $row ) {
			if ( empty( $row['slug'] ) || empty( $row['label'] ) ) {
					continue;
			}

				$slug  = sanitize_key( $row['slug'] );
				$label = sanitize_text_field( $row['label'] );

			if ( '' === $slug || '' === $label ) {
					continue;
			}

				$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';

			if ( 'array' === $type ) {
					$item_schema = $this->normalize_array_item_schema( $row );
					$items       = array();

				if ( isset( $stored_fields[ $slug ] ) && isset( $stored_fields[ $slug ]['type'] ) && 'array' === $stored_fields[ $slug ]['type'] ) {
						$items = $this->get_array_field_items_from_structured( $stored_fields[ $slug ] );
				}

				if ( empty( $items ) ) {
						$items = array( array() );
				}

					$this->render_array_field( $slug, $label, $item_schema, $items );
					continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
			}

				$meta_key          = 'resolate_field_' . $slug;
				$known_meta_keys[] = $meta_key;
				$value             = '';

			if ( isset( $stored_fields[ $slug ] ) ) {
					$value = (string) $stored_fields[ $slug ]['value'];
			}

				echo '<div class="resolate-field" style="margin-bottom:16px;">';
				echo '<label for="' . esc_attr( $meta_key ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( $label ) . '</label>';

			if ( 'single' === $type ) {
					echo '<input type="text" class="widefat" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $value ) . '" />';
			} elseif ( 'rich' === $type ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
					wp_editor(
						$value,
						$meta_key,
						array(
							'textarea_name' => $meta_key,
							'textarea_rows' => 8,
							'media_buttons' => false,
							'teeny'         => false,
							'tinymce'       => array(
								'toolbar1' => 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,undo,redo,removeformat',
							),
							'quicktags'     => true,
							'editor_height' => 220,
						)
					);
			} else {
					echo '<textarea class="widefat" rows="6" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '">' . esc_textarea( $value ) . '</textarea>';
			}

				echo '</div>';
		}

			$unknown = $this->collect_unknown_dynamic_fields( $post->ID, $known_meta_keys );
			$this->render_unknown_dynamic_fields_ui( $unknown );
			echo '</div>';
	}

		/**
		 * Normalize the item schema for an array field definition.
		 *
		 * @param array $definition Field definition from the schema.
		 * @return array<string, array{label:string,type:string,data_type:string}>
		 */
	private function normalize_array_item_schema( $definition ) {
			$schema = array();

		if ( isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] ) ) {
			foreach ( $definition['item_schema'] as $key => $item ) {
				$item_key = sanitize_key( $key );
				if ( '' === $item_key ) {
						continue;
				}

				$label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : $this->humanize_unknown_field_label( $item_key );
				$type  = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
				if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
						$type = 'textarea';
				}

				$data_type = isset( $item['data_type'] ) ? sanitize_key( $item['data_type'] ) : 'text';
				if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
						$data_type = 'text';
				}

				$schema[ $item_key ] = array(
					'label'     => $label,
					'type'      => $type,
					'data_type' => $data_type,
				);
			}
		}

		if ( empty( $schema ) ) {
				$schema['content'] = array(
					'label'     => __( 'Contenido', 'resolate' ),
					'type'      => 'textarea',
					'data_type' => 'text',
				);
		}

			return $schema;
	}

		/**
		 * Render an array field with repeatable items.
		 *
		 * @param string $slug        Field slug.
		 * @param string $label       Field label.
		 * @param array  $item_schema Item schema definition.
		 * @param array  $items       Current values.
		 * @return void
		 */
	private function render_array_field( $slug, $label, $item_schema, $items ) {
			$slug        = sanitize_key( $slug );
			$label       = sanitize_text_field( $label );
			$field_id    = 'resolate-array-' . $slug;
			$items       = is_array( $items ) ? $items : array();
			$item_schema = is_array( $item_schema ) ? $item_schema : array();

			echo '<div class="resolate-array-field" data-array-field="' . esc_attr( $slug ) . '" style="margin-bottom:24px;">';
			echo '<div class="resolate-array-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">';
			echo '<span class="resolate-array-title" style="font-weight:600;font-size:15px;">' . esc_html( $label ) . '</span>';
			echo '<button type="button" class="button button-secondary resolate-array-add" data-array-target="' . esc_attr( $slug ) . '">' . esc_html__( 'Añadir elemento', 'resolate' ) . '</button>';
			echo '</div>';

			echo '<div class="resolate-array-items" id="' . esc_attr( $field_id ) . '" data-field="' . esc_attr( $slug ) . '">';
		foreach ( $items as $index => $values ) {
				$values = is_array( $values ) ? $values : array();
				$this->render_array_field_item( $slug, (string) $index, $item_schema, $values );
		}
			echo '</div>';

			echo '<template class="resolate-array-template" data-field="' . esc_attr( $slug ) . '">';
			$this->render_array_field_item( $slug, '__INDEX__', $item_schema, array(), true );
			echo '</template>';
			echo '</div>';
	}

		/**
		 * Render a single repeatable array item row.
		 *
		 * @param string $slug         Field slug.
		 * @param string $index        Item index.
		 * @param array  $item_schema  Item schema definition.
		 * @param array  $values       Current values.
		 * @param bool   $is_template  Whether the row is a template placeholder.
		 * @return void
		 */
	private function render_array_field_item( $slug, $index, $item_schema, $values, $is_template = false ) {
			$slug        = sanitize_key( $slug );
			$index_attr  = (string) $index;
			$item_schema = is_array( $item_schema ) ? $item_schema : array();
			$values      = is_array( $values ) ? $values : array();

			echo '<div class="resolate-array-item" data-index="' . esc_attr( $index_attr ) . '" draggable="true" style="border:1px solid #e5e5e5;padding:16px;margin-bottom:12px;background:#fff;">';
			echo '<div class="resolate-array-item-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;">';
			echo '<span class="resolate-array-handle" role="button" tabindex="0" aria-label="' . esc_attr__( 'Mover elemento', 'resolate' ) . '" style="cursor:move;user-select:none;">≡</span>';
			echo '<button type="button" class="button-link-delete resolate-array-remove">' . esc_html__( 'Eliminar', 'resolate' ) . '</button>';
			echo '</div>';

		foreach ( $item_schema as $key => $definition ) {
				$item_key = sanitize_key( $key );
			if ( '' === $item_key ) {
				continue;
			}

				$field_name = 'tpl_fields[' . $slug . '][' . $index_attr . '][' . $item_key . ']';
				$field_id   = 'resolate-' . $slug . '-' . $item_key . '-' . $index_attr;
				$label      = isset( $definition['label'] ) ? sanitize_text_field( $definition['label'] ) : $this->humanize_unknown_field_label( $item_key );
				$type       = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
				$value      = isset( $values[ $item_key ] ) ? (string) $values[ $item_key ] : '';

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
			}

				echo '<div class="resolate-array-field-control" style="margin-bottom:12px;">';
				echo '<label for="' . esc_attr( $field_id ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( $label ) . '</label>';

			if ( 'single' === $type ) {
					echo '<input type="text" class="widefat" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $value ) . '" />';
			} else {
					$rows = ( 'rich' === $type ) ? 8 : 4;
					echo '<textarea class="widefat" rows="' . esc_attr( (string) $rows ) . '" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '">' . esc_textarea( $value ) . '</textarea>';
			}

				echo '</div>';
		}

			echo '</div>';
	}

		/**
		 * Sanitize posted array field items against the schema definition.
		 *
		 * @param array $items      Raw submitted items.
		 * @param array $definition Schema definition for the field.
		 * @return array<int, array<string, string>>
		 */
	private function sanitize_array_field_items( $items, $definition ) {
		if ( ! is_array( $items ) ) {
				return array();
		}

			$schema = $this->normalize_array_item_schema( $definition );
			$clean  = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
					continue;
			}

				$filtered = array();
			foreach ( $schema as $key => $settings ) {
					$raw   = isset( $item[ $key ] ) ? $item[ $key ] : '';
					$raw   = is_scalar( $raw ) ? (string) $raw : '';
					$type  = isset( $settings['type'] ) ? $settings['type'] : 'textarea';
					$value = '';

				switch ( $type ) {
					case 'single':
						$value = sanitize_text_field( $raw );
						break;
					case 'rich':
							$value = wp_kses_post( $raw );
						break;
					default:
							$value = sanitize_textarea_field( $raw );
						break;
				}

					$filtered[ $key ] = $value;
			}

				$has_content = false;
			foreach ( $filtered as $key => $value ) {
					$type = isset( $schema[ $key ]['type'] ) ? $schema[ $key ]['type'] : 'textarea';
				if ( 'rich' === $type ) {
					if ( '' !== trim( wp_strip_all_tags( (string) $value ) ) ) {
						$has_content = true;
						break;
					}
				} elseif ( '' !== trim( (string) $value ) ) {
							$has_content = true;
							break;
				}
			}

			if ( $has_content ) {
					$clean[] = $filtered;
			}
		}

		if ( empty( $clean ) ) {
				return array();
		}

			$clean = array_slice( $clean, 0, self::ARRAY_FIELD_MAX_ITEMS );
			return array_values( $clean );
	}

		/**
		 * Decode stored structured field data into array items.
		 *
		 * @param array $entry Structured entry with type/value keys.
		 * @return array<int, array<string, string>>
		 */
	private function get_array_field_items_from_structured( $entry ) {
		if ( ! is_array( $entry ) ) {
				return array();
		}

			$value = isset( $entry['value'] ) ? (string) $entry['value'] : '';
			return self::decode_array_field_value( $value );
	}

		/**
		 * Decode a structured JSON value into array items.
		 *
		 * @param string $value JSON encoded string.
		 * @return array<int, array<string, string>>
		 */
	public static function decode_array_field_value( $value ) {
			$value = (string) $value;
		if ( '' === trim( $value ) ) {
				return array();
		}

			$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
				return array();
		}

			$items = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
					continue;
			}
				$normalized = array();
			foreach ( $item as $key => $val ) {
					$normalized[ sanitize_key( $key ) ] = is_scalar( $val ) ? (string) $val : '';
			}
				$items[] = $normalized;
		}

			return array_slice( $items, 0, self::ARRAY_FIELD_MAX_ITEMS );
	}

	/**
	 * Save meta box values.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_boxes( $post_id ) {
		if ( ! isset( $_POST['resolate_sections_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['resolate_sections_nonce'] ) ), 'resolate_sections_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Handle type selection (lock after set).
		if ( isset( $_POST['resolate_type_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['resolate_type_nonce'] ) ), 'resolate_type_nonce' ) ) {
			$posted   = isset( $_POST['resolate_doc_type'] ) ? intval( $_POST['resolate_doc_type'] ) : 0;
			$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			$current  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
			if ( $current <= 0 && $posted > 0 ) {
				wp_set_post_terms( $post_id, array( $posted ), 'resolate_doc_type', false );
			}
		}

		// post_content is composed in wp_insert_post_data filter; avoid recursion here.
	}

		/**
		 * Filter post data before save to compose a Gutenberg-friendly post_content.
		 *
		 * @param array $data    Sanitized post data to be inserted.
		 * @param array $postarr Raw post data.
		 * @return array
		 */
	public function filter_post_data_compose_content( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || 'resolate_doc' !== $data['post_type'] ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : 0;

		$term_id = 0;
		if ( isset( $_POST['resolate_doc_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$term_id = max( 0, intval( wp_unslash( $_POST['resolate_doc_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( $term_id <= 0 && $post_id > 0 ) {
			$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				$term_id = intval( $assigned[0] );
			}
		}

		$schema         = array();
		$dynamic_schema = array();
		if ( $term_id > 0 ) {
			$dynamic_schema = self::get_term_schema( $term_id );
			$schema         = $dynamic_schema;
		}

		$existing_structured = array();
		if ( isset( $postarr['post_content'] ) && '' !== $postarr['post_content'] ) {
			$existing_structured = self::parse_structured_content( (string) $postarr['post_content'] );
		}
		if ( empty( $existing_structured ) && $post_id > 0 ) {
			$current_content = get_post_field( 'post_content', $post_id, 'edit' );
			if ( is_string( $current_content ) && '' !== $current_content ) {
				$existing_structured = self::parse_structured_content( $current_content );
			}
			if ( empty( $existing_structured ) ) {
				$existing_structured = $this->get_structured_field_values( $post_id );
			}
		}

		$structured_fields   = array();
		$known_slugs         = array();
		$posted_array_fields = array();
		if ( isset( $_POST['tpl_fields'] ) && is_array( $_POST['tpl_fields'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$posted_array_fields = wp_unslash( $_POST['tpl_fields'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		foreach ( $schema as $row ) {
			if ( empty( $row['slug'] ) ) {
						continue;
			}
					$slug = sanitize_key( $row['slug'] );
			if ( '' === $slug ) {
				continue;
			}
					$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
				$known_slugs[ $slug ] = true;

			if ( 'array' === $type ) {
							$items = array();
				if ( isset( $posted_array_fields[ $slug ] ) && is_array( $posted_array_fields[ $slug ] ) ) {
						$items = $this->sanitize_array_field_items( $posted_array_fields[ $slug ], $row );
				} elseif ( isset( $existing_structured[ $slug ] ) && isset( $existing_structured[ $slug ]['type'] ) && 'array' === $existing_structured[ $slug ]['type'] ) {
						$items = $this->get_array_field_items_from_structured( $existing_structured[ $slug ] );
				}

							$structured_fields[ $slug ] = array(
								'type'  => 'array',
								'value' => ! empty( $items ) ? wp_json_encode( $items ) : '[]',
							);
							continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}
							$meta_key             = 'resolate_field_' . $slug;
							$value                = '';

			if ( isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( 'single' === $type ) {
					$value = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				} elseif ( 'rich' === $type ) {
							$value = wp_kses_post( wp_unslash( $_POST[ $meta_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				} else {
						$value = sanitize_textarea_field( wp_unslash( $_POST[ $meta_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
			} elseif ( isset( $existing_structured[ $slug ] ) ) {
				$value = (string) $existing_structured[ $slug ]['value'];
			}

							$structured_fields[ $slug ] = array(
								'type'  => $type,
								'value' => (string) $value,
							);
		}

		$unknown_fields = array();

		if ( ! empty( $existing_structured ) ) {
			foreach ( $existing_structured as $slug => $info ) {
				$slug = sanitize_key( $slug );
				if ( '' === $slug || isset( $known_slugs[ $slug ] ) || isset( $unknown_fields[ $slug ] ) ) {
					continue;
				}
				$meta_key = 'resolate_field_' . $slug;
				if ( isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$val = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$unknown_fields[ $slug ] = array(
						'type'  => 'rich',
						'value' => wp_kses_post( is_string( $val ) ? $val : '' ),
					);
				} else {
					$type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : 'rich';
					if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
						$type = 'rich';
					}
					$unknown_fields[ $slug ] = array(
						'type'  => $type,
						'value' => (string) $info['value'],
					);
				}
			}
		}

		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'resolate_field_' ) ) {
				continue;
			}
			$slug = sanitize_key( substr( $key, strlen( 'resolate_field_' ) ) );
			if ( '' === $slug || isset( $structured_fields[ $slug ] ) || isset( $unknown_fields[ $slug ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}
			$unknown_fields[ $slug ] = array(
				'type'  => 'rich',
				'value' => wp_kses_post( wp_unslash( $value ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			);
		}

		if ( empty( $structured_fields ) && empty( $unknown_fields ) ) {
			$data['post_content'] = '';
			return $data;
		}

		$fragments = array();
		foreach ( $structured_fields as $slug => $info ) {
			$fragments[] = $this->build_structured_field_fragment( $slug, $info['type'], $info['value'] );
		}
		if ( ! empty( $unknown_fields ) ) {
			foreach ( $unknown_fields as $slug => $info ) {
				$fragments[] = $this->build_structured_field_fragment( $slug, $info['type'], $info['value'] );
			}
		}

		$data['post_content'] = implode( "\n\n", $fragments );
		return $data;
	}

	/**
	 * Retrieve structured field values stored in post_content.
	 *
	 * Falls back to dynamic meta keys when the content has not been migrated yet.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array{value:string,type:string}>
	 */
	private function get_structured_field_values( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$fields = self::parse_structured_content( $post->post_content );
		if ( ! empty( $fields ) ) {
			return $fields;
		}

		$fallback = array();
			   $schema   = $this->get_dynamic_fields_schema_for_post( $post_id );
		if ( ! empty( $schema ) ) {
			foreach ( $schema as $row ) {
				if ( empty( $row['slug'] ) ) {
					continue;
				}
				$slug = sanitize_key( $row['slug'] );
				if ( '' === $slug ) {
					continue;
				}
				$meta_key = 'resolate_field_' . $slug;
				$value    = get_post_meta( $post_id, $meta_key, true );
				if ( '' === $value ) {
					continue;
				}
						$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
				if ( 'array' === $type ) {
						$encoded = '';
						$stored  = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
					if ( is_string( $stored ) && '' !== $stored ) {
								$encoded = (string) $stored;
					} else {
									$legacy = get_post_meta( $post_id, 'resolate_' . $slug, true );
						if ( empty( $legacy ) && 'annexes' === $slug ) {
								$legacy = get_post_meta( $post_id, 'resolate_annexes', true );
						}
						if ( is_array( $legacy ) && ! empty( $legacy ) ) {
								$encoded = wp_json_encode( $legacy );
						}
					}

					if ( '' !== $encoded ) {
						$fallback[ $slug ] = array(
							'value' => $encoded,
							'type'  => 'array',
						);
					}
									continue;
				}
				if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
						$type = 'textarea';
				}
				$fallback[ $slug ] = array(
					'value' => (string) $value,
					'type'  => $type,
				);
			}
		}

			   return $fallback;
	}

	/**
	 * Parse the structured post_content string into slug/value pairs.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function parse_structured_content( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return array();
		}

		$pattern = '/<!--\s*resolate-field\s+([^>]*)-->(.*?)<!--\s*\/resolate-field\s*-->/si';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$fields = array();
		foreach ( $matches as $match ) {
			$attrs = self::parse_structured_field_attributes( $match[1] );
			$slug  = isset( $attrs['slug'] ) ? sanitize_key( $attrs['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$type = isset( $attrs['type'] ) ? sanitize_key( $attrs['type'] ) : '';
			$fields[ $slug ] = array(
				'value' => trim( (string) $match[2] ),
				'type'  => $type,
			);
		}

		return $fields;
	}

	/**
	 * Parse attribute string from a structured field marker.
	 *
	 * @param string $attribute_string Raw attribute string.
	 * @return array<string,string>
	 */
	private static function parse_structured_field_attributes( $attribute_string ) {
		$result = array();
		$pattern = '/([a-zA-Z0-9_-]+)="([^"]*)"/';
		if ( preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key = strtolower( $match[1] );
				$result[ $key ] = $match[2];
			}
		}
		return $result;
	}

	/**
	 * Compose the HTML comment fragment that stores a field value.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	private function build_structured_field_fragment( $slug, $type, $value ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}
				$type = sanitize_key( $type );
		if ( ! in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
				$type = '';
		}

				$attributes = 'slug="' . esc_attr( $slug ) . '"';
		if ( '' !== $type ) {
			$attributes .= ' type="' . esc_attr( $type ) . '"';
		}

		$value = (string) $value;
		return '<!-- resolate-field ' . $attributes . " -->\n" . $value . "\n<!-- /resolate-field -->";
	}

	/**
	 * Get dynamic fields schema for the selected document type of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of field definitions with keys: slug, label, type.
	 */
	private function get_dynamic_fields_schema_for_post( $post_id ) {
		$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( $term_id <= 0 ) {
			return array();
		}
		return self::get_term_schema( $term_id );
	}

	/**
	 * Get sanitized schema array for a document type term.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	public static function get_term_schema( $term_id ) {
			$raw = get_term_meta( $term_id, 'schema', true );
		if ( ! is_array( $raw ) ) {
				$raw = get_term_meta( $term_id, 'resolate_type_fields', true );
		}
		if ( ! is_array( $raw ) ) {
				return array();
		}

			$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
					continue;
			}

				$slug        = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
				$label       = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
				$type        = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
				$placeholder = isset( $item['placeholder'] ) ? preg_replace( '/[^A-Za-z0-9._:-]/', '', (string) $item['placeholder'] ) : '';
				$data_type   = isset( $item['data_type'] ) ? sanitize_key( $item['data_type'] ) : '';

			if ( '' === $slug ) {
					continue;
			}

			if ( '' === $label ) {
					$label = self::humanize_schema_label( $slug );
			}

			if ( '' === $label ) {
					continue;
			}

			if ( '' === $placeholder ) {
					$placeholder = $slug;
			}

			if ( 'array' === $type ) {
					$item_schema = array();
				if ( isset( $item['item_schema'] ) && is_array( $item['item_schema'] ) ) {
					foreach ( $item['item_schema'] as $key => $definition ) {
						$item_key = sanitize_key( $key );
						if ( '' === $item_key ) {
									continue;
						}

						$item_label = isset( $definition['label'] ) ? sanitize_text_field( $definition['label'] ) : '';
						if ( '' === $item_label ) {
										$item_label = self::humanize_schema_label( $item_key );
						}

							$item_type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
						if ( ! in_array( $item_type, array( 'single', 'textarea', 'rich' ), true ) ) {
							$item_type = 'textarea';
						}

							$item_data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';
						if ( ! in_array( $item_data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
								$item_data_type = 'text';
						}

							$item_schema[ $item_key ] = array(
								'label'     => $item_label,
								'type'      => $item_type,
								'data_type' => $item_data_type,
							);
					}
				}

					$out[] = array(
						'slug'        => $slug,
						'label'       => $label,
						'type'        => 'array',
						'placeholder' => $placeholder,
						'data_type'   => 'array',
						'item_schema' => $item_schema,
					);
					continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
			}

			if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
					$data_type = 'text';
			}

				$out[] = array(
					'slug'        => $slug,
					'label'       => $label,
					'type'        => $type,
					'placeholder' => $placeholder,
					'data_type'   => $data_type,
				);
		}

			return $out;
	}

		/**
		 * Humanize a schema label from a slug.
		 *
		 * @param string $slug Field slug.
		 * @return string
		 */
	private static function humanize_schema_label( $slug ) {
			$slug = str_replace( array( '-', '_' ), ' ', (string) $slug );
			$slug = preg_replace( '/\s+/', ' ', $slug );
			$slug = trim( $slug );
		if ( '' === $slug ) {
				return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
				return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
			return ucwords( $slug );
	}

	/**
	 * Collect meta values whose keys start with resolate_field_ but are not part of the schema.
	 *
	 * @param int   $post_id         Post ID.
	 * @param array $known_meta_keys Dynamic meta keys defined by the current schema.
	 * @return array[] Array keyed by meta key with value/source data.
	 */
	private function collect_unknown_dynamic_fields( $post_id, $known_meta_keys ) {
		$known_lookup = array();
		if ( ! empty( $known_meta_keys ) ) {
			foreach ( $known_meta_keys as $meta_key ) {
				$known_lookup[ $meta_key ] = true;
			}
		}

		$unknown = array();
		$prefix  = 'resolate_field_';

		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! is_string( $key ) || 0 !== strpos( $key, $prefix ) ) {
				continue;
			}
			if ( isset( $known_lookup[ $key ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}
			$unknown[ $key ] = array(
				'value'  => wp_unslash( $value ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'source' => 'post',
			);
		}

		if ( $post_id > 0 ) {
			$stored = $this->get_structured_field_values( $post_id );
			if ( ! empty( $stored ) ) {
				foreach ( $stored as $slug => $info ) {
					$meta_key = $prefix . sanitize_key( $slug );
					if ( isset( $known_lookup[ $meta_key ] ) || isset( $unknown[ $meta_key ] ) ) {
						continue;
					}
					$value = isset( $info['value'] ) ? (string) $info['value'] : '';
					$unknown[ $meta_key ] = array(
						'value'  => $value,
						'source' => 'content',
					);
				}
			}
		}

		return $unknown;
	}

	/**
	 * Render UI controls for dynamic fields not defined in the selected taxonomy schema.
	 *
	 * @param array $unknown_fields Unknown field definitions.
	 * @return void
	 */
	private function render_unknown_dynamic_fields_ui( $unknown_fields ) {
		if ( empty( $unknown_fields ) ) {
			return;
		}

		echo '<div class="resolate-unknown-dynamic" style="margin-top:24px;">';
		echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">' . esc_html__( 'El documento contiene campos adicionales que no pertenecen al tipo seleccionado. Revisa su contenido antes de guardar.', 'resolate' ) . '</div>';

		foreach ( $unknown_fields as $meta_key => $data ) {
			$label = $this->humanize_unknown_field_label( $meta_key );
			$value = '';
			if ( isset( $data['value'] ) && is_string( $data['value'] ) ) {
				$value = wp_kses_post( $data['value'] );
			}
			echo '<div class="resolate-field resolate-field-warning" style="margin-bottom:16px;border:1px solid #dba617;padding:12px;background:#fffbea;">';
			/* translators: %s: detected dynamic field key. */
			echo '<label for="' . esc_attr( $meta_key ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( sprintf( __( 'Campo adicional: %s', 'resolate' ), $label ) ) . '</label>';
			echo '<p class="description" style="margin-top:0;margin-bottom:8px;">' . esc_html__( 'Este campo no está definido en la taxonomía de tipo de documento actual.', 'resolate' ) . '</p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles escaping.
			wp_editor(
				$value,
				$meta_key,
				array(
					'textarea_name' => $meta_key,
					'textarea_rows' => 6,
					'media_buttons' => false,
					'teeny'         => false,
					'tinymce'       => array(
						'toolbar1' => 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,undo,redo,removeformat',
					),
					'quicktags'     => true,
					'editor_height' => 200,
				)
			);
					echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Create a human readable label for an unknown dynamic field meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	private function humanize_unknown_field_label( $meta_key ) {
		$slug = str_replace( 'resolate_field_', '', (string) $meta_key );
		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', $slug ) );
		if ( '' === $slug ) {
			return (string) $meta_key;
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $slug );
	}
}

	public function render_dynamic_fields_metabox( $post ) {
		wp_nonce_field( 'resolate_dynamic_fields_nonce', 'resolate_dynamic_fields_nonce' );

		$assigned = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;

		if ( ! $term_id ) {
			echo '<p>' . esc_html__( 'Por favor, seleccione un tipo de documento y guarde para ver los campos.', 'wp-resolate' ) . '</p>';
			return;
		}

		$template_id = get_term_meta( $term_id, 'resolate_type_template_id', true );
		$template_path = get_attached_file( $template_id );

		if ( ! $template_path || ! file_exists( $template_path ) || 'odt' !== strtolower( pathinfo( $template_path, PATHINFO_EXTENSION ) ) ) {
			echo '<p>' . esc_html__( 'No hay una plantilla ODT asignada a este tipo de documento.', 'wp-resolate' ) . '</p>';
			return;
		}

		$schema = Resolate_Dynamic_Fields_Parser::parse( $template_path );

		if ( empty( $schema['fields'] ) ) {
			echo '<p>' . esc_html__( 'No se encontraron campos dinámicos en la plantilla ODT.', 'wp-resolate' ) . '</p>';
			return;
		}

		$saved_values = $this->parse_structured_content( $post->post_content );

		echo '<div class="resolate-dynamic-fields">';
		foreach ( $schema['fields'] as $field ) {
			$this->render_field( $post->ID, $field, $saved_values );
		}
		echo '</div>';
	}

	private function render_field( $post_id, $field, $saved_values ) {
		$meta_key = 'resolate_field_' . $field['name'];
		$value = isset( $saved_values[ $field['name'] ] ) ? $saved_values[ $field['name'] ]['value'] : '';

		echo '<div class="resolate-field" style="margin-bottom: 20px;">';
		echo '<label for="' . esc_attr( $meta_key ) . '" style="font-weight: bold; display: block; margin-bottom: 5px;">' . esc_html( $field['title'] ) . '</label>';

		$attributes = array(
			'id'          => $meta_key,
			'name'        => $meta_key,
			'class'       => 'widefat',
			'placeholder' => isset( $field['placeholder'] ) ? $field['placeholder'] : '',
			'pattern'     => isset( $field['pattern'] ) ? $field['pattern'] : null,
			'title'       => isset( $field['patternmsg'] ) ? $field['patternmsg'] : null,
			'maxlength'   => isset( $field['length'] ) ? $field['length'] : null,
			'min'         => isset( $field['minvalue'] ) ? $field['minvalue'] : null,
			'max'         => isset( $field['maxvalue'] ) ? $field['maxvalue'] : null,
		);

		$attr_string = '';
		foreach ( $attributes as $key => $attr_value ) {
			if ( $attr_value !== null ) {
				$attr_string .= ' ' . $key . '="' . esc_attr( $attr_value ) . '"';
			}
		}

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea ' . $attr_string . ' rows="5">' . esc_textarea( $value ) . '</textarea>';
				break;

			case 'html':
				wp_editor(
					$value,
					$meta_key,
					array(
						'textarea_name' => $meta_key,
						'editor_height' => 250,
					)
				);
				break;

			case 'number':
			case 'date':
			case 'email':
			case 'url':
				echo '<input type="' . esc_attr( $field['type'] ) . '" value="' . esc_attr( $value ) . '"' . $attr_string . '>';
				break;

			case 'text':
			default:
				echo '<input type="text" value="' . esc_attr( $value ) . '"' . $attr_string . '>';
				break;
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description" style="margin-top: 5px;">' . esc_html( $field['description'] ) . '</p>';
		}

		if ( ! empty( $field['is_duplicate'] ) ) {
			echo '<p class="description" style="color: #d63638;">' . esc_html__( 'Advertencia: Nombre de campo duplicado. El nombre del campo en la base de datos ha sido modificado a', 'wp-resolate' ) . ' <code>' . esc_html($field['name']) . '</code>.</p>';
		}

		echo '</div>';
	}

	public function save_dynamic_fields( $post_id ) {
		if ( ! isset( $_POST['resolate_dynamic_fields_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['resolate_dynamic_fields_nonce'] ) ), 'resolate_dynamic_fields_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( ! $term_id ) { return; }

		$template_id = get_term_meta( $term_id, 'resolate_type_template_id', true );
		$template_path = get_attached_file( $template_id );
		if ( ! $template_path ) { return; }

		$schema = Resolate_Dynamic_Fields_Parser::parse( $template_path );
		if ( empty( $schema['fields'] ) ) { return; }

		$errors = array();

		foreach ( $schema['fields'] as $field ) {
			$meta_key = 'resolate_field_' . $field['name'];
			$value = isset( $_POST[ $meta_key ] ) ? wp_unslash( $_POST[ $meta_key ] ) : '';

			$sanitized_value = $this->sanitize_value( $value, $field['type'] );
			$validation_error = $this->validate_value( $sanitized_value, $field );

			if ( $validation_error ) {
				$errors[] = '<strong>' . esc_html( $field['title'] ) . ':</strong> ' . $validation_error;
			}

			$structured_data[ $field['name'] ] = array(
				'value' => $sanitized_value,
				'type' => $field['type'],
			);
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'resolate_save_errors_' . $post_id, $errors, 45 );
			add_filter( 'redirect_post_location', array( $this, 'add_error_query_arg' ), 99 );

			// Don't update post_content if there are validation errors
			return;
		}

		$this->update_post_content_with_structured_data( $post_id, $structured_data );
	}

	public function add_error_query_arg( $location ) {
		remove_filter( 'redirect_post_location', array( $this, 'add_error_query_arg' ), 99 );
		return add_query_arg( 'resolate_error', 1, $location );
	}

	public function display_save_errors() {
		global $post;
		if ( ! $post || ! isset( $_GET['resolate_error'] ) ) {
			return;
		}

		$errors = get_transient( 'resolate_save_errors_' . $post->ID );
		if ( $errors ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . wp_kses_post( implode( '<br>', $errors ) ) . '</p></div>';
			delete_transient( 'resolate_save_errors_' . $post->ID );
		}
	}

	private function sanitize_value( $value, $type ) {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );
			case 'url':
				return esc_url_raw( $value );
			case 'number':
				return is_numeric( $value ) ? floatval( $value ) : '';
			case 'html':
				return wp_kses_post( $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	private function validate_value( $value, $field ) {
		if ( ! empty( $field['length'] ) && mb_strlen( $value ) > $field['length'] ) {
			return sprintf( __( 'El valor no puede superar los %d caracteres.', 'wp-resolate' ), $field['length'] );
		}

		if ( ! empty( $field['pattern'] ) && ! preg_match( '/'. str_replace('/', '\/', $field['pattern']) . '/', $value ) ) {
			return ! empty( $field['patternmsg'] ) ? $field['patternmsg'] : __( 'El formato del valor no es válido.', 'wp-resolate' );
		}

		if ( $field['type'] === 'number' ) {
			if ( isset( $field['minvalue'] ) && is_numeric($field['minvalue']) && $value < $field['minvalue'] ) {
				return sprintf( __( 'El valor mínimo permitido es %s.', 'wp-resolate' ), $field['minvalue'] );
			}
			if ( isset( $field['maxvalue'] ) && is_numeric($field['maxvalue']) && $value > $field['maxvalue'] ) {
				return sprintf( __( 'El valor máximo permitido es %s.', 'wp-resolate' ), $field['maxvalue'] );
			}
		}

		if ( $field['type'] === 'date' ) {
			$date_val = strtotime( $value );
			if ( ! empty( $field['minvalue'] ) && $date_val < strtotime( $field['minvalue'] ) ) {
				return sprintf( __( 'La fecha mínima permitida es %s.', 'wp-resolate' ), $field['minvalue'] );
			}
			if ( ! empty( $field['maxvalue'] ) && $date_val > strtotime( $field['maxvalue'] ) ) {
				return sprintf( __( 'La fecha máxima permitida es %s.', 'wp-resolate' ), $field['maxvalue'] );
			}
		}

		return null;
	}

	private function update_post_content_with_structured_data( $post_id, $data ) {
		$fragments = array();
		foreach ( $data as $slug => $field_data ) {
			$fragments[] = $this->build_structured_field_fragment( $slug, $field_data['type'], $field_data['value'] );
		}
		$content = implode( "\n\n", $fragments );

		// Unhook our save method to prevent recursion
		remove_action( 'save_post_resolate_doc', array( $this, 'save_dynamic_fields' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
		add_action( 'save_post_resolate_doc', array( $this, 'save_dynamic_fields' ) );
	}

	private function build_structured_field_fragment( $slug, $type, $value ) {
		$attributes = 'slug="' . esc_attr( $slug ) . '" type="' . esc_attr( $type ) . '"';
		return "<!-- resolate-field {$attributes} -->\n" . $value . "\n<!-- /resolate-field -->";
	}

	private function parse_structured_content( $content ) {
		if ( empty( $content ) ) {
			return array();
		}

		$fields = array();
		$pattern = '/<!--\s*resolate-field\s+([^>]*?)-->\s*(.*?)\s*<!--\s*\/resolate-field\s*-->/si';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			$attrs = $this->parse_structured_field_attributes( $match[1] );
			if ( ! empty( $attrs['slug'] ) ) {
				$fields[ $attrs['slug'] ] = array(
					'value' => $match[2],
					'type' => isset( $attrs['type'] ) ? $attrs['type'] : 'text',
				);
			}
		}
		return $fields;
	}

	private function parse_structured_field_attributes( $attr_string ) {
		$attributes = array();
		if ( preg_match_all( '/(\w+)=["\']([^"\']+)["\']/', $attr_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attributes[ $match[1] ] = $match[2];
			}
		}
		return $attributes;
	}

	public function get_merge_data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$structured_data = $this->parse_structured_content( $post->post_content );
		$merge_data = array();

		// Get the schema to map slugs back to original names
		$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( ! $term_id ) { return $merge_data; }

		$template_id = get_term_meta( $term_id, 'resolate_type_template_id', true );
		$template_path = get_attached_file( $template_id );
		if ( ! $template_path ) { return $merge_data; }

		$schema = Resolate_Dynamic_Fields_Parser::parse( $template_path );
		if ( empty( $schema['fields'] ) ) { return $merge_data; }

		$name_map = array();
		foreach( $schema['fields'] as $field ) {
			$name_map[$field['name']] = isset( $field['original_name'] ) ? $field['original_name'] : $field['name'];
		}

		foreach ( $structured_data as $slug => $data ) {
			if(isset($name_map[$slug])){
				$merge_key = $name_map[$slug];
				$merge_data[ $merge_key ] = $data['value'];
			}
		}

		return $merge_data;
	}
}

// Initialize.
new Resolate_Documents();
