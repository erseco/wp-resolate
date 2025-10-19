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

use Resolate\DocType\SchemaConverter;
use Resolate\DocType\SchemaStorage;

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
		add_action( 'save_post_resolate_document', array( $this, 'save_meta_boxes' ) );

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

		/**
		 * Lock document type after the first assignment.
		 * Reapplies the original term if an attempt to change it is detected.
		 */
		add_action( 'set_object_terms', array( $this, 'enforce_locked_doc_type' ), 10, 6 );

		add_action( 'admin_head-post.php', array( $this, 'hide_submit_box_controls' ) );
		add_action( 'admin_head-post-new.php', array( $this, 'hide_submit_box_controls' ) );

		$this->register_revision_ui();
	}

	/**
	 * Enforce that a document's type cannot change after it is first set.
	 *
	 * @param int    $object_id  Object (post) ID.
	 * @param array  $terms      Term IDs or slugs being set.
	 * @param array  $tt_ids     Term taxonomy IDs being set.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms are being appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public function enforce_locked_doc_type( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $tt_ids, $append );
		$taxonomy = (string) $taxonomy;
		if ( 'resolate_doc_type' !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || 'resolate_document' !== $post->post_type ) {
			return;
		}

		static $lock_guard = false;
		if ( $lock_guard ) {
			return;
		}

		$locked = intval( get_post_meta( $object_id, 'resolate_locked_doc_type', true ) );

		// If not yet locked, lock to the current assigned term (if any) on first set.
		if ( $locked <= 0 ) {
			$assigned = wp_get_post_terms( $object_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				update_post_meta( $object_id, 'resolate_locked_doc_type', intval( $assigned[0] ) );
			}
			return;
		}

		// Already locked: ensure the post keeps the locked term.
		$current = wp_get_post_terms( $object_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $current ) ) {
			return;
		}
		$current_one = ( ! empty( $current ) ) ? intval( $current[0] ) : 0;
		if ( $current_one === $locked && count( $current ) === 1 ) {
			return;
		}

		// If old assignment existed, or current differs, reapply the locked term.
		$lock_guard = true;
		wp_set_post_terms( $object_id, array( $locked ), 'resolate_doc_type', false );
		$lock_guard = false;
	}

	/**
	 * Return the list of custom meta keys used by this CPT for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function get_meta_fields_for_post( $post_id ) {
		$fields = array();
		$known  = array();

		$dynamic = $this->get_dynamic_fields_schema_for_post( $post_id );
		if ( ! empty( $dynamic ) ) {
			foreach ( $dynamic as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$key = 'resolate_field_' . sanitize_key( $def['slug'] );
				if ( '' === $key ) {
					continue;
				}
				$fields[]    = $key;
				$known[ $key ] = true;
			}
		}

		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( ! empty( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $values ) {
					unset( $values );
					if ( 0 !== strpos( $meta_key, 'resolate_field_' ) ) {
						continue;
					}
					if ( isset( $known[ $meta_key ] ) ) {
						continue;
					}
					$fields[] = $meta_key;
				}
			}
		}

		return array_values( array_unique( $fields ) );
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
		if ( ! $parent || 'resolate_document' !== $parent->post_type ) {
			return;
		}

		// Collect dynamic meta keys from schema and from existing post meta as fallback.
		$keys = $this->get_meta_fields_for_post( $post_id );
		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( is_array( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $unused ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					unset( $unused );
					if ( is_string( $meta_key ) && 0 === strpos( $meta_key, 'resolate_field_' ) ) {
						$keys[] = $meta_key;
					}
				}
			}
		}
		$keys = array_values( array_unique( $keys ) );

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			// Store only if it has something meaningful (empty array/string skipped).
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			}
			// Ensure a clean single value on the revision row.
			delete_metadata( 'post', $revision_id, $key );
			add_metadata( 'post', $revision_id, $key, $value, true );
		}

		// Bust the meta cache for the revision to ensure immediate reads reflect the copy.
		wp_cache_delete( $revision_id, 'post_meta' );
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
		if ( ! $parent || 'resolate_document' !== $parent->post_type ) {
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
		if ( $post && 'resolate_document' === $post->post_type ) {
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
		if ( $post && 'resolate_document' === $post->post_type ) {
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
	 * Register the Documents custom post type and attach core categories.
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
			'all_items'             => __( 'Todos los documentos', 'resolate' ),
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
			'taxonomies'        => array( 'category' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'show_in_rest'       => false,
		);

		register_post_type( 'resolate_document', $args );
		register_taxonomy_for_object_type( 'category', 'resolate_document' );
	}

	/**
	 * Hide visibility and publish date controls for documents submit box.
	 *
	 * @return void
	 */
	public function hide_submit_box_controls() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'resolate_document' !== $screen->post_type ) {
			return;
		}

		$css  = '<style id="resolate-document-submitbox-controls">';
		$css .= '.post-type-resolate_document #visibility,';
		$css .= '.post-type-resolate_document .misc-pub-visibility,';
		$css .= '.post-type-resolate_document .misc-pub-curtime,';
		$css .= '.post-type-resolate_document #timestampdiv,';
		$css .= '.post-type-resolate_document #password-span,';
		$css .= '.post-type-resolate_document .edit-visibility,';
		$css .= '.post-type-resolate_document .edit-timestamp';
		$css .= ' {display:none!important;}';
		$css .= '</style>';

		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
				array( 'resolate_document' ),
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
		if ( 'resolate_document' === $post_type ) {
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
			'resolate_document',
			'side',
			'high'
		);

		add_meta_box(
			'resolate_sections',
			__( 'Secciones del documento', 'resolate' ),
			array( $this, 'render_sections_metabox' ),
			'resolate_document',
			'normal',
			'default'
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

		$schema     = $this->get_dynamic_fields_schema_for_post( $post->ID );
		$raw_schema = $this->get_raw_schema_for_post( $post->ID );
		$raw_fields = isset( $raw_schema['fields'] ) && is_array( $raw_schema['fields'] ) ? $raw_schema['fields'] : array();
		// Load the raw schema so we can expose placeholders, constraints and help text.

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
		echo '<table class="form-table"><tbody>';

		foreach ( $schema as $row ) {
			if ( empty( $row['slug'] ) || empty( $row['label'] ) ) {
				continue;
			}

			$slug  = sanitize_key( $row['slug'] );
			$label = sanitize_text_field( $row['label'] );

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			if ( 'post_title' === $slug ) {
				$known_meta_keys[] = 'resolate_field_' . $slug;
				// Let WordPress handle the native title field.
				continue;
			}

			$type       = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
			$raw_field  = isset( $raw_fields[ $slug ] ) ? $raw_fields[ $slug ] : array();
			$field_type = isset( $raw_field['type'] ) ? sanitize_key( $raw_field['type'] ) : '';
			$data_type  = isset( $row['data_type'] ) ? sanitize_key( $row['data_type'] ) : '';
			$type       = $this->resolve_field_control_type( $type, $raw_field );
			$field_title = $this->get_field_title( $raw_field );
			if ( '' !== $field_title ) {
				$label = $field_title;
			}
			$field_title_attribute = $this->get_field_pattern_message( $raw_field );
			if ( '' === $field_title_attribute ) {
				$field_title_attribute = $field_title;
			}

			if ( 'array' === $type ) {
				$item_schema = $this->normalize_array_item_schema( $row );
				$items       = array();
				$raw_repeater = isset( $raw_schema['repeaters'][ $slug ] ) && is_array( $raw_schema['repeaters'][ $slug ] ) ? $raw_schema['repeaters'][ $slug ] : array();
				$repeater_source = isset( $raw_repeater['definition'] ) ? $raw_repeater['definition'] : array();
				$repeater_title  = $this->get_field_title( $repeater_source );
				if ( '' !== $repeater_title ) {
					$label = $repeater_title;
				}
				$repeater_title_attribute = $this->get_field_pattern_message( $repeater_source );
				if ( '' === $repeater_title_attribute ) {
					$repeater_title_attribute = $repeater_title;
				}

				// Mark repeater meta key as known so it does not appear under unknown fields.
				$known_meta_keys[] = 'resolate_field_' . $slug;

				if ( isset( $stored_fields[ $slug ] ) && isset( $stored_fields[ $slug ]['type'] ) && 'array' === $stored_fields[ $slug ]['type'] ) {
					$items = $this->get_array_field_items_from_structured( $stored_fields[ $slug ] );
				}

				if ( empty( $items ) ) {
					$items = array( array() );
				}

				$description = $this->get_field_description( $raw_field );
				$validation  = $this->get_field_validation_message( $raw_field );

				echo '<tr class="resolate-field resolate-field-array resolate-field-' . esc_attr( $slug ) . '">';
				echo '<th scope="row"><label';
				if ( '' !== $repeater_title_attribute ) {
					echo ' title="' . esc_attr( $repeater_title_attribute ) . '"';
				}
				echo '>' . esc_html( $label ) . '</label></th>';
				echo '<td>';
				$this->render_array_field( $slug, $label, $item_schema, $items, $raw_repeater );
				if ( '' !== $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $validation ) {
					echo '<p class="description resolate-field-validation" data-resolate-validation-message="true">' . esc_html( $validation ) . '</p>';
				}
				echo '</td></tr>';
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

			$description    = $this->get_field_description( $raw_field );
			$validation     = $this->get_field_validation_message( $raw_field );
			$description_id = '' !== $description ? $meta_key . '-description' : '';
			$validation_id  = '' !== $validation ? $meta_key . '-validation' : '';
			$describedby    = array();

			if ( '' !== $description_id ) {
				$describedby[] = $description_id;
			}
			if ( '' !== $validation_id ) {
				$describedby[] = $validation_id;
			}

			echo '<tr class="resolate-field resolate-field-' . esc_attr( $slug ) . ' resolate-field-control-' . esc_attr( $type ) . '">';
			echo '<th scope="row"><label for="' . esc_attr( $meta_key ) . '"';
			if ( '' !== $field_title_attribute ) {
				echo ' title="' . esc_attr( $field_title_attribute ) . '"';
			}
			echo '>' . esc_html( $label ) . '</label></th>';
			echo '<td>';

			if ( 'single' === $type ) {
				// Map schema hints into the appropriate HTML control and attributes.
				$input_type       = $this->map_single_input_type( $field_type, $data_type );
				$normalized_value = $this->normalize_scalar_value( $value, $input_type );
				$attributes       = $this->build_scalar_input_attributes( $raw_field, $input_type );

				if ( ! empty( $describedby ) ) {
					$attributes['aria-describedby'] = implode( ' ', $describedby );
				}
				if ( '' !== $validation ) {
					$attributes['data-validation-message'] = $validation;
				}

				$attributes['class'] = $this->build_input_class( $input_type );
				$attribute_string    = $this->format_field_attributes( $attributes );

				if ( 'select' === $input_type ) {
					$options     = $this->parse_select_options( $raw_field );
					$placeholder = $this->get_select_placeholder( $raw_field );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<select id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" ' . $attribute_string . '>';
					if ( '' !== $placeholder ) {
						echo '<option value="">' . esc_html( $placeholder ) . '</option>';
					} elseif ( empty( $attributes['required'] ) ) {
						echo '<option value="">' . esc_html__( 'Selecciona una opción…', 'resolate' ) . '</option>';
					}
					foreach ( $options as $option_value => $option_label ) {
						echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $option_value, $normalized_value, false ) . '>' . esc_html( $option_label ) . '</option>';
					}
					echo '</select>';
				} elseif ( 'checkbox' === $input_type ) {
					// Hidden field guarantees we persist an explicit "0" when unchecked.
					echo '<input type="hidden" name="' . esc_attr( $meta_key ) . '" value="0" />';
					echo '<label class="resolate-checkbox-wrapper">';
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<input type="checkbox" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="1" ' . checked( '1', $normalized_value, false ) . ' ' . $attribute_string . ' />';
					echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
					echo '</label>';
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $normalized_value ) . '" ' . $attribute_string . ' />';
				}
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
				$attributes = $this->build_scalar_input_attributes( $raw_field, 'textarea' );
				if ( ! empty( $describedby ) ) {
					$attributes['aria-describedby'] = implode( ' ', $describedby );
				}
				if ( '' !== $validation ) {
					$attributes['data-validation-message'] = $validation;
				}
				if ( ! isset( $attributes['rows'] ) ) {
					$attributes['rows'] = 6;
				}
				$attributes['class'] = $this->build_input_class( 'textarea' );
				$attribute_string    = $this->format_field_attributes( $attributes );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
				echo '<textarea id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" ' . $attribute_string . '>' . esc_textarea( $value ) . '</textarea>';
			}

			if ( '' !== $description ) {
				echo '<p id="' . esc_attr( $description_id ) . '" class="description">' . esc_html( $description ) . '</p>';
			}
			if ( '' !== $validation ) {
				echo '<p id="' . esc_attr( $validation_id ) . '" class="description resolate-field-validation" data-resolate-validation-message="true">' . esc_html( $validation ) . '</p>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$unknown = $this->collect_unknown_dynamic_fields( $post->ID, $known_meta_keys );
		$this->render_unknown_dynamic_fields_ui( $unknown );
		echo '</div>';
	}

	/**
	 * Retrieve raw schema data for the current document type.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,array>> Indexed schema details.
	 */
	private function get_raw_schema_for_post( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( $term_id <= 0 ) {
			return array();
		}

		$storage   = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );
		if ( ! is_array( $schema_v2 ) ) {
			return array();
		}

		$fields_index    = array();
		$repeaters_index = array();
		if ( isset( $schema_v2['fields'] ) && is_array( $schema_v2['fields'] ) ) {
			foreach ( $schema_v2['fields'] as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$slug = '';
				if ( isset( $field['slug'] ) ) {
					$slug = sanitize_key( $field['slug'] );
				} elseif ( isset( $field['name'] ) ) {
					$slug = sanitize_key( $field['name'] );
				}
				if ( '' === $slug ) {
					continue;
				}
				$fields_index[ $slug ] = $field;
			}
		}

		if ( isset( $schema_v2['repeaters'] ) && is_array( $schema_v2['repeaters'] ) ) {
			foreach ( $schema_v2['repeaters'] as $repeater ) {
				if ( ! is_array( $repeater ) ) {
					continue;
				}

				$slug = '';
				if ( isset( $repeater['slug'] ) ) {
					$slug = sanitize_key( $repeater['slug'] );
				} elseif ( isset( $repeater['name'] ) ) {
					$slug = sanitize_key( $repeater['name'] );
				}

				if ( '' === $slug ) {
					continue;
				}

				$fields = array();
				if ( isset( $repeater['fields'] ) && is_array( $repeater['fields'] ) ) {
					foreach ( $repeater['fields'] as $field ) {
						if ( ! is_array( $field ) ) {
							continue;
						}
						$field_slug = '';
						if ( isset( $field['slug'] ) ) {
							$field_slug = sanitize_key( $field['slug'] );
						} elseif ( isset( $field['name'] ) ) {
							$field_slug = sanitize_key( $field['name'] );
						}
						if ( '' === $field_slug ) {
							continue;
						}
						$fields[ $field_slug ] = $field;
					}
				}

				$repeaters_index[ $slug ] = array(
					'definition' => $repeater,
					'fields'     => $fields,
				);
			}
		}

		return array(
			'fields'    => $fields_index,
			'repeaters' => $repeaters_index,
		);
	}

	/**
	 * Decide the UI control to use based on schema hints.
	 *
	 * @param string     $legacy_type Legacy control type.
	 * @param array|null $raw_field   Raw schema definition.
	 * @return string Control identifier: single|textarea|rich|array.
	 */
	private function resolve_field_control_type( $legacy_type, $raw_field ) {
		$legacy_type = sanitize_key( $legacy_type );
		if ( '' === $legacy_type ) {
			$legacy_type = 'textarea';
		}
		if ( 'array' === $legacy_type ) {
			return 'array';
		}

		if ( ! in_array( $legacy_type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$legacy_type = 'textarea';
		}

		$raw_type = '';
		if ( is_array( $raw_field ) ) {
			if ( isset( $raw_field['type'] ) ) {
				$raw_type = sanitize_key( $raw_field['type'] );
			} elseif ( isset( $raw_field['parameters']['type'] ) ) {
				$raw_type = sanitize_key( $raw_field['parameters']['type'] );
			}
		}

		if ( '' === $raw_type ) {
			return ( 'rich' === $legacy_type ) ? 'rich' : 'textarea';
		}

		if ( in_array( $raw_type, array( 'html', 'rich', 'tinymce', 'editor' ), true ) ) {
			return 'rich';
		}

		if ( in_array( $raw_type, array( 'textarea', 'text-area', 'text_area' ), true ) ) {
			return 'textarea';
		}

		if ( in_array(
			$raw_type,
			array(
				'text',
				'string',
				'varchar',
				'email',
				'url',
				'link',
				'number',
				'numeric',
				'int',
				'integer',
				'float',
				'decimal',
				'date',
				'datetime',
				'datetime-local',
				'time',
				'tel',
				'phone',
				'boolean',
				'bool',
				'checkbox',
				'select',
				'dropdown',
				'choice',
			),
			true
		) ) {
			return 'single';
		}

		// Fall back to the legacy control type (usually textarea) for plain text fields.
		return $legacy_type;
	}

	/**
	 * Retrieve the field description from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_description( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['description'] ) && is_string( $raw_field['description'] ) && '' !== $raw_field['description'] ) {
			return sanitize_text_field( $raw_field['description'] );
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			foreach ( array( 'description', 'help', 'hint' ) as $key ) {
				if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
					return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Retrieve the validation message associated with the field.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_validation_message( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['patternmsg'] ) && is_string( $raw_field['patternmsg'] ) && '' !== $raw_field['patternmsg'] ) {
			return sanitize_text_field( $raw_field['patternmsg'] );
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			foreach ( array( 'validation_message', 'validation-message', 'invalid', 'error' ) as $key ) {
				if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
					return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Retrieve the field title from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_title( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['title'] ) && is_string( $raw_field['title'] ) && '' !== $raw_field['title'] ) {
			return sanitize_text_field( $raw_field['title'] );
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			if ( isset( $raw_field['parameters']['title'] ) && '' !== $raw_field['parameters']['title'] ) {
				return sanitize_text_field( (string) $raw_field['parameters']['title'] );
			}
		}

		return '';
	}

	/**
	 * Retrieve pattern validation message from raw schema.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_pattern_message( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['patternmsg'] ) && is_string( $raw_field['patternmsg'] ) && '' !== $raw_field['patternmsg'] ) {
			return sanitize_text_field( $raw_field['patternmsg'] );
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			foreach ( array( 'patternmsg', 'pattern_message', 'pattern-message' ) as $key ) {
				if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
					return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Map schema type hints to concrete HTML input types.
	 *
	 * @param string $field_type Original schema field type.
	 * @param string $data_type  Normalized data type.
	 * @return string
	 */
	private function map_single_input_type( $field_type, $data_type ) {
		$field_type = strtolower( (string) $field_type );
		$data_type  = strtolower( (string) $data_type );

		switch ( $field_type ) {
			case 'text':
			case 'string':
			case 'varchar':
				return 'text';
			case 'number':
			case 'numeric':
			case 'int':
			case 'integer':
			case 'float':
			case 'decimal':
				return 'number';
			case 'email':
				return 'email';
			case 'url':
			case 'link':
				return 'url';
			case 'tel':
			case 'phone':
				return 'tel';
			case 'date':
				return 'date';
			case 'datetime':
			case 'datetime-local':
			case 'datetime_local':
				return 'datetime-local';
			case 'time':
				return 'time';
			case 'boolean':
			case 'bool':
			case 'checkbox':
				return 'checkbox';
			case 'select':
			case 'dropdown':
			case 'choice':
				return 'select';
			default:
				break;
		}

		switch ( $data_type ) {
			case 'number':
				return 'number';
			case 'date':
				return 'date';
			case 'boolean':
				return 'checkbox';
		}

		return 'text';
	}

	/**
	 * Normalize stored value for the selected HTML control type.
	 *
	 * @param string $value      Stored value.
	 * @param string $input_type Target input type.
	 * @return string
	 */
	private function normalize_scalar_value( $value, $input_type ) {
		$value      = is_scalar( $value ) ? (string) $value : '';
		$input_type = sanitize_key( $input_type );

		if ( 'checkbox' === $input_type ) {
			return $this->is_truthy( $value ) ? '1' : '0';
		}

		if ( 'datetime-local' === $input_type ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d\TH:i', $timestamp );
			}
			return $value;
		}

		if ( 'date' === $input_type ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d', $timestamp );
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Build common HTML attributes from raw schema metadata.
	 *
	 * @param array  $raw_field  Raw field definition.
	 * @param string $input_type Input type being rendered.
	 * @return array<string,string>
	 */
	private function build_scalar_input_attributes( $raw_field, $input_type ) {
		$attributes        = array();
		$input_type        = sanitize_key( $input_type );
		$allows_placeholder = ! in_array( $input_type, array( 'checkbox', 'select' ), true );

		if ( ! is_array( $raw_field ) ) {
			return $attributes;
		}

		if ( $allows_placeholder && ! empty( $raw_field['placeholder'] ) ) {
			$attributes['placeholder'] = sanitize_text_field( $raw_field['placeholder'] );
		}

		if ( $allows_placeholder && ! empty( $raw_field['pattern'] ) ) {
			$attributes['pattern'] = (string) $raw_field['pattern'];
		}

		if ( $allows_placeholder && isset( $raw_field['length'] ) ) {
			$length = intval( $raw_field['length'] );
			if ( $length > 0 ) {
				$attributes['maxlength'] = (string) $length;
			}
		}

		if ( isset( $raw_field['minvalue'] ) && in_array( $input_type, array( 'number', 'range', 'date', 'datetime-local', 'time' ), true ) ) {
			$attributes['min'] = (string) $raw_field['minvalue'];
		}

		if ( isset( $raw_field['maxvalue'] ) && in_array( $input_type, array( 'number', 'range', 'date', 'datetime-local', 'time' ), true ) ) {
			$attributes['max'] = (string) $raw_field['maxvalue'];
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			$params = $raw_field['parameters'];

			if ( isset( $params['required'] ) && $this->is_truthy( $params['required'] ) ) {
				$attributes['required'] = 'required';
			} elseif ( isset( $params['is_required'] ) && $this->is_truthy( $params['is_required'] ) ) {
				$attributes['required'] = 'required';
			}

			if ( $allows_placeholder && empty( $attributes['placeholder'] ) && isset( $params['placeholder'] ) ) {
				$attributes['placeholder'] = sanitize_text_field( (string) $params['placeholder'] );
			}

			if ( isset( $params['step'] ) && in_array( $input_type, array( 'number', 'range' ), true ) ) {
				$attributes['step'] = (string) $params['step'];
			}

			if ( isset( $params['min'] ) && ! isset( $attributes['min'] ) && in_array( $input_type, array( 'number', 'range', 'date', 'datetime-local', 'time' ), true ) ) {
				$attributes['min'] = (string) $params['min'];
			}

			if ( isset( $params['max'] ) && ! isset( $attributes['max'] ) && in_array( $input_type, array( 'number', 'range', 'date', 'datetime-local', 'time' ), true ) ) {
				$attributes['max'] = (string) $params['max'];
			}

			if ( 'textarea' === $input_type && isset( $params['rows'] ) ) {
				$rows = intval( $params['rows'] );
				if ( $rows > 0 ) {
					$attributes['rows'] = (string) $rows;
				}
			}
		}

		if ( ! isset( $attributes['title'] ) ) {
			$title_attribute = $this->get_field_pattern_message( $raw_field );
			if ( '' === $title_attribute ) {
				$title_attribute = $this->get_field_title( $raw_field );
			}
			if ( '' !== $title_attribute ) {
				$attributes['title'] = $title_attribute;
			}
		}

		return $attributes;
	}

	/**
	 * Build CSS classes for rendered controls following WP admin conventions.
	 *
	 * @param string $input_type Input type.
	 * @return string
	 */
	private function build_input_class( $input_type ) {
		$input_type = sanitize_key( $input_type );
		$classes    = array(
			'resolate-field-input',
			'resolate-field-input-' . $input_type,
		);

		switch ( $input_type ) {
			case 'textarea':
				$classes[] = 'large-text';
				break;
			case 'checkbox':
				$classes[] = 'resolate-field-checkbox';
				break;
			case 'select':
				$classes[] = 'regular-text';
				break;
			default:
				$classes[] = 'regular-text';
				break;
		}

		$classes = array_filter(
			array_map(
				static function ( $class ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', (string) $class );
				},
				array_unique( $classes )
			)
		);

		return implode( ' ', $classes );
	}

	/**
	 * Convert attribute arrays into HTML attribute strings.
	 *
	 * @param array<string,string> $attributes Attribute map.
	 * @return string
	 */
	private function format_field_attributes( $attributes ) {
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return '';
		}

		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );
			$name = preg_replace( '/[^a-z0-9_\-:]/', '', $name );
			if ( '' === $name ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				if ( $value ) {
					$parts[] = esc_attr( $name );
				}
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			$parts[] = esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Parse select options from schema parameters.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return array<string,string>
	 */
	private function parse_select_options( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return array();
		}

		$options = array();

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			$params    = $raw_field['parameters'];
			$source    = '';
			$candidate = null;

			foreach ( array( 'options', 'choices', 'values' ) as $key ) {
				if ( isset( $params[ $key ] ) && '' !== $params[ $key ] ) {
					$candidate = $params[ $key ];
					break;
				}
			}

			if ( is_array( $candidate ) ) {
				foreach ( $candidate as $value => $label ) {
					$option_value = is_int( $value ) ? (string) $label : (string) $value;
					$option_label = is_int( $value ) ? (string) $label : (string) $label;
					$options[ sanitize_text_field( $option_value ) ] = sanitize_text_field( $option_label );
				}
			} elseif ( is_string( $candidate ) && '' !== $candidate ) {
				$source = $candidate;
			}

			if ( '' !== $source ) {
				$delimiter = ( false !== strpos( $source, '|' ) ) ? '|' : ',';
				$pieces    = array_map( 'trim', explode( $delimiter, $source ) );
				foreach ( $pieces as $piece ) {
					if ( '' === $piece ) {
						continue;
					}
					if ( false !== strpos( $piece, ':' ) ) {
						list( $value, $label ) = array_map( 'trim', explode( ':', $piece, 2 ) );
					} else {
						$value = $piece;
						$label = $piece;
					}
					$options[ sanitize_text_field( $value ) ] = sanitize_text_field( $label );
				}
			}
		}

		return $options;
	}

	/**
	 * Determine select placeholder text if provided.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_select_placeholder( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['placeholder'] ) && '' !== $raw_field['placeholder'] ) {
			return sanitize_text_field( $raw_field['placeholder'] );
		}

		if ( isset( $raw_field['parameters'] ) && is_array( $raw_field['parameters'] ) ) {
			foreach ( array( 'placeholder', 'prompt', 'empty', 'empty_label' ) as $key ) {
				if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
					return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
				}
			}
		}

		return '';
	}

	/**
	 * Evaluate truthy values commonly used in schema flags.
	 *
	 * @param mixed $value Value to evaluate.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
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
	 * @param string $slug         Field slug.
	 * @param string $label        Field label.
	 * @param array  $item_schema  Item schema definition.
	 * @param array  $items        Current values.
	 * @param array  $raw_repeater Raw schema definition for this repeater.
	 * @return void
	 */
	private function render_array_field( $slug, $label, $item_schema, $items, $raw_repeater = array() ) {
		$slug        = sanitize_key( $slug );
		$label       = sanitize_text_field( $label );
		$repeater_source = isset( $raw_repeater['definition'] ) ? $raw_repeater['definition'] : array();
		$repeater_title  = $this->get_field_title( $repeater_source );
		if ( '' !== $repeater_title ) {
			$label = $repeater_title;
		}
		$repeater_title_attribute = $this->get_field_pattern_message( $repeater_source );
		if ( '' === $repeater_title_attribute ) {
			$repeater_title_attribute = $repeater_title;
		}
		$field_id    = 'resolate-array-' . $slug;
		$items       = is_array( $items ) ? $items : array();
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$raw_fields  = array();
		if ( isset( $raw_repeater['fields'] ) && is_array( $raw_repeater['fields'] ) ) {
			$raw_fields = $raw_repeater['fields'];
		}

		echo '<div class="resolate-array-field" data-array-field="' . esc_attr( $slug ) . '" style="margin-bottom:24px;">';
		echo '<div class="resolate-array-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">';
		echo '<span class="resolate-array-title" style="font-weight:600;font-size:15px;"';
		if ( '' !== $repeater_title_attribute ) {
			echo ' title="' . esc_attr( $repeater_title_attribute ) . '"';
		}
		echo '>' . esc_html( $label ) . '</span>';
		echo '<button type="button" class="button button-secondary resolate-array-add" data-array-target="' . esc_attr( $slug ) . '">' . esc_html__( 'Añadir elemento', 'resolate' ) . '</button>';
		echo '</div>';

		echo '<div class="resolate-array-items" id="' . esc_attr( $field_id ) . '" data-field="' . esc_attr( $slug ) . '">';
		foreach ( $items as $index => $values ) {
			$values = is_array( $values ) ? $values : array();
			$this->render_array_field_item( $slug, (string) $index, $item_schema, $values, false, $raw_fields );
		}
		echo '</div>';

		echo '<template class="resolate-array-template" data-field="' . esc_attr( $slug ) . '">';
		$this->render_array_field_item( $slug, '__INDEX__', $item_schema, array(), true, $raw_fields );
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
	 * @param array  $raw_fields   Raw schema definitions for the repeater items.
	 * @return void
	 */
	private function render_array_field_item( $slug, $index, $item_schema, $values, $is_template = false, $raw_fields = array() ) {
		$slug        = sanitize_key( $slug );
		$index_attr  = (string) $index;
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$values      = is_array( $values ) ? $values : array();
		$raw_fields  = is_array( $raw_fields ) ? $raw_fields : array();

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
			$raw_field  = isset( $raw_fields[ $item_key ] ) ? $raw_fields[ $item_key ] : array();
			$type       = $this->resolve_field_control_type( $type, $raw_field );
			$value      = isset( $values[ $item_key ] ) ? (string) $values[ $item_key ] : '';
			$field_title = $this->get_field_title( $raw_field );
			if ( '' !== $field_title ) {
				$label = $field_title;
			}
			$field_title_attribute = $this->get_field_pattern_message( $raw_field );
			if ( '' === $field_title_attribute ) {
				$field_title_attribute = $field_title;
			}

			echo '<div class="resolate-array-field-control" style="margin-bottom:12px;">';
			echo '<label for="' . esc_attr( $field_id ) . '" style="font-weight:600;display:block;margin-bottom:4px;"';
			if ( '' !== $field_title_attribute ) {
				echo ' title="' . esc_attr( $field_title_attribute ) . '"';
			}
			echo '>' . esc_html( $label ) . '</label>';

			if ( 'single' === $type ) {
				$raw_field_type   = isset( $raw_field['type'] ) ? sanitize_key( $raw_field['type'] ) : '';
				$raw_data_type    = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : '';
				$input_type       = $this->map_single_input_type( $raw_field_type, $raw_data_type );
				$normalized_value = $this->normalize_scalar_value( $value, $input_type );
				$attributes       = $this->build_scalar_input_attributes( $raw_field, $input_type );
				$description      = $this->get_field_description( $raw_field );
				$validation       = $this->get_field_validation_message( $raw_field );
				$description_id   = '' !== $description ? $field_id . '-description' : '';
				$validation_id    = '' !== $validation ? $field_id . '-validation' : '';
				$describedby      = array();
				if ( '' !== $description_id ) {
					$describedby[] = $description_id;
				}
				if ( '' !== $validation_id ) {
					$describedby[] = $validation_id;
				}
				if ( ! empty( $describedby ) ) {
					$attributes['aria-describedby'] = implode( ' ', $describedby );
				}
				if ( '' !== $validation ) {
					$attributes['data-validation-message'] = $validation;
				}
				$attributes['class'] = $this->build_input_class( $input_type );
				$attribute_string    = $this->format_field_attributes( $attributes );

				if ( 'select' === $input_type ) {
					$options     = $this->parse_select_options( $raw_field );
					$placeholder = $this->get_select_placeholder( $raw_field );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" ' . $attribute_string . '>';
					if ( '' !== $placeholder ) {
						echo '<option value="">' . esc_html( $placeholder ) . '</option>';
					} elseif ( empty( $attributes['required'] ) ) {
						echo '<option value="">' . esc_html__( 'Selecciona una opción…', 'resolate' ) . '</option>';
					}
					foreach ( $options as $option_value => $option_label ) {
						echo '<option value="' . esc_attr( $option_value ) . '" ' . selected( $option_value, $normalized_value, false ) . '>' . esc_html( $option_label ) . '</option>';
					}
					echo '</select>';
				} elseif ( 'checkbox' === $input_type ) {
					echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="0" />';
					echo '<label class="resolate-checkbox-wrapper">';
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<input type="checkbox" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="1" ' . checked( '1', $normalized_value, false ) . ' ' . $attribute_string . ' />';
					echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
					echo '</label>';
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
					echo '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $normalized_value ) . '" ' . $attribute_string . ' />';
				}

				if ( '' !== $description ) {
					echo '<p id="' . esc_attr( $description_id ) . '" class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $validation ) {
					echo '<p id="' . esc_attr( $validation_id ) . '" class="description resolate-field-validation" data-resolate-validation-message="true">' . esc_html( $validation ) . '</p>';
				}
			} elseif ( 'rich' === $type ) {
				$description    = $this->get_field_description( $raw_field );
				$validation     = $this->get_field_validation_message( $raw_field );
				$description_id = '' !== $description ? $field_id . '-description' : '';
				$validation_id  = '' !== $validation ? $field_id . '-validation' : '';
				$describedby    = array();
				if ( '' !== $description_id ) {
					$describedby[] = $description_id;
				}
				if ( '' !== $validation_id ) {
					$describedby[] = $validation_id;
				}
				$attributes = $this->build_scalar_input_attributes( $raw_field, 'textarea' );
				if ( ! empty( $describedby ) ) {
					$attributes['aria-describedby'] = implode( ' ', $describedby );
				}
				if ( '' !== $validation ) {
					$attributes['data-validation-message'] = $validation;
				}
				if ( ! isset( $attributes['rows'] ) ) {
					$attributes['rows'] = 8;
				}
				$classes = trim(
					$this->build_input_class( 'textarea' ) . ' resolate-array-rich' . ( $is_template ? ' resolate-array-rich-template' : '' )
				);
				$attributes['class'] = $classes;
				$attributes['data-editor-initialized'] = 'false';
				$attribute_string = $this->format_field_attributes( $attributes );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
				echo '<textarea ' . $attribute_string . ' id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '">' . esc_textarea( $value ) . '</textarea>';
				if ( '' !== $description ) {
					echo '<p id="' . esc_attr( $description_id ) . '" class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $validation ) {
					echo '<p id="' . esc_attr( $validation_id ) . '" class="description resolate-field-validation" data-resolate-validation-message="true">' . esc_html( $validation ) . '</p>';
				}
			} else {
				$attributes  = $this->build_scalar_input_attributes( $raw_field, 'textarea' );
				$description = $this->get_field_description( $raw_field );
				$validation  = $this->get_field_validation_message( $raw_field );
				$description_id = '' !== $description ? $field_id . '-description' : '';
				$validation_id  = '' !== $validation ? $field_id . '-validation' : '';
				$describedby    = array();
				if ( '' !== $description_id ) {
					$describedby[] = $description_id;
				}
				if ( '' !== $validation_id ) {
					$describedby[] = $validation_id;
				}
				if ( ! empty( $describedby ) ) {
					$attributes['aria-describedby'] = implode( ' ', $describedby );
				}
				if ( '' !== $validation ) {
					$attributes['data-validation-message'] = $validation;
				}
				if ( ! isset( $attributes['rows'] ) ) {
					$attributes['rows'] = 6;
				}
				$attributes['class'] = $this->build_input_class( 'textarea' );
				$attribute_string    = $this->format_field_attributes( $attributes );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
				echo '<textarea ' . $attribute_string . ' id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '">' . esc_textarea( $value ) . '</textarea>';
				if ( '' !== $description ) {
					echo '<p id="' . esc_attr( $description_id ) . '" class="description">' . esc_html( $description ) . '</p>';
				}
				if ( '' !== $validation ) {
					echo '<p id="' . esc_attr( $validation_id ) . '" class="description resolate-field-validation" data-resolate-validation-message="true">' . esc_html( $validation ) . '</p>';
				}
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Sanitize rich text content by stripping disallowed blocks completely.
	 *
	 * @param string $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_rich_text_value( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		$patterns = array(
			'#<script\b[^>]*>.*?</script>#is',
			'#<style\b[^>]*>.*?</style>#is',
			'#<iframe\b[^>]*>.*?</iframe>#is',
		);

		$clean = preg_replace( $patterns, '', $value );
		if ( null === $clean ) {
			$clean = $value;
		}

		return wp_kses_post( $clean );
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
							$value = $this->sanitize_rich_text_value( $raw );
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

		if ( function_exists( 'wp_unslash' ) ) {
			$value = wp_unslash( $value );
		}

		if ( false !== strpos( $value, '&' ) ) {
			$value = wp_specialchars_decode( $value, ENT_QUOTES );
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
			$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			$current  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
			if ( $current > 0 ) {
				wp_set_post_terms( $post_id, array( $current ), 'resolate_doc_type', false );
			} elseif ( isset( $_POST['resolate_doc_type'] ) ) {
				$posted = intval( wp_unslash( $_POST['resolate_doc_type'] ) );
				if ( $posted > 0 ) {
					wp_set_post_terms( $post_id, array( $posted ), 'resolate_doc_type', false );
				}
			}
		}

		$this->save_dynamic_fields_meta( $post_id );

		// post_content is composed in wp_insert_post_data filter; avoid recursion here.
	}

	/**
	 * Persist dynamic field values posted from the sections metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_dynamic_fields_meta( $post_id ) {
		$schema = $this->get_dynamic_fields_schema_for_post( $post_id );

		$post_values = array();
		if ( isset( $_POST ) && is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$post_values = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$known_meta_keys     = array();
		$posted_array_fields = array();
		if ( isset( $post_values['tpl_fields'] ) && is_array( $post_values['tpl_fields'] ) ) {
			$posted_array_fields = $post_values['tpl_fields'];
		}

		// Persist fields defined by the current schema (when available).
		foreach ( (array) $schema as $definition ) {
			if ( empty( $definition['slug'] ) ) {
				continue;
			}

			$slug = sanitize_key( $definition['slug'] );
			if ( '' === $slug ) {
				continue;
			}

			$type     = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
			$meta_key = 'resolate_field_' . $slug;
			$known_meta_keys[ $meta_key ] = true;

			if ( 'array' === $type ) {
				if ( isset( $posted_array_fields[ $slug ] ) ) {
					$items = $this->sanitize_array_field_items( $posted_array_fields[ $slug ], $definition );
					if ( empty( $items ) ) {
						delete_post_meta( $post_id, $meta_key );
					} else {
						update_post_meta( $post_id, $meta_key, wp_json_encode( $items ) );
					}
				}
				continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			if ( ! array_key_exists( $meta_key, $post_values ) ) {
				continue;
			}

			$raw_value = $post_values[ $meta_key ];
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

			switch ( $type ) {
				case 'single':
					$value = sanitize_text_field( $raw_value );
					break;
				case 'rich':
					$value = $this->sanitize_rich_text_value( $raw_value );
					break;
				default:
					$value = sanitize_textarea_field( $raw_value );
					break;
			}

			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Persist unknown dynamic fields posted that are not part of the schema
		// (or when no schema is currently available for the post's type).
		foreach ( $post_values as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, 'resolate_field_' ) ) {
				continue;
			}
			if ( isset( $known_meta_keys[ $key ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}

			$raw_value = wp_unslash( $value );
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';
			$sanitized = $this->sanitize_rich_text_value( $raw_value );

			if ( '' === $sanitized ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $sanitized );
			}
		}
	}

		/**
		 * Filter post data before save to compose a Gutenberg-friendly post_content.
		 *
		 * @param array $data    Sanitized post data to be inserted.
		 * @param array $postarr Raw post data.
		 * @return array
		 */
	public function filter_post_data_compose_content( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || 'resolate_document' !== $data['post_type'] ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : 0;
		$status_before_filter = isset( $data['post_status'] ) ? $data['post_status'] : '';
		$should_force_private = ! in_array( $status_before_filter, array( 'auto-draft', 'trash' ), true );

		if ( $should_force_private ) {
			$data['post_status']   = 'private';
			$data['post_password'] = '';

			if ( $post_id > 0 ) {
				$current_post = get_post( $post_id );
				if ( $current_post && 'resolate_document' === $current_post->post_type ) {
					$data['post_date']     = $current_post->post_date;
					$data['post_date_gmt'] = $current_post->post_date_gmt;
				}
			} else {
				$now     = current_time( 'mysql' );
				$now_gmt = current_time( 'mysql', true );
				$data['post_date']     = $now;
				$data['post_date_gmt'] = $now_gmt;
			}
		} else {
			$data['post_password'] = '';
		}

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
				$raw_input = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$raw_input = is_scalar( $raw_input ) ? (string) $raw_input : '';

				if ( 'single' === $type ) {
					$value = sanitize_text_field( $raw_input );
				} elseif ( 'rich' === $type ) {
							$value = $this->sanitize_rich_text_value( $raw_input );
				} else {
						$value = sanitize_textarea_field( $raw_input );
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
					$val = is_scalar( $val ) ? (string) $val : '';
					$unknown_fields[ $slug ] = array(
						'type'  => 'rich',
						'value' => $this->sanitize_rich_text_value( $val ),
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
			$raw_value = wp_unslash( $value ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';
			$unknown_fields[ $slug ] = array(
				'type'  => 'rich',
				'value' => $this->sanitize_rich_text_value( $raw_value ),
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
		$storage   = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );

		if ( is_array( $schema_v2 ) && ! empty( $schema_v2 ) ) {
			return SchemaConverter::to_legacy( $schema_v2 );
		}

		return array();
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

// Initialize.
new Resolate_Documents();
