<?php
/**
 * The file that defines the Documents custom post type for Resolate.
 *
 * This CPT is the base for generating official documents with structured
 * sections stored as post meta, and two taxonomies: ámbitos and leyes.
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


    $this->register_revision_ui();


}

    /**
     * Return the list of custom meta keys used by this CPT for a given post.
     *
     * Includes static section fields and dynamic fields defined by the selected
     * document type, if any. Also includes annexes array.
     *
     * @param int $post_id Post ID.
     * @return string[]
     */
    private function get_meta_fields_for_post( $post_id ) {
        $fields = array(
            'resolate_objeto',
            'resolate_antecedentes',
            'resolate_fundamentos',
            'resolate_dispositivo',
            'resolate_firma',
            'resolate_annexes',
        );

        $dynamic = $this->get_dynamic_fields_schema_for_post( $post_id );
        if ( ! empty( $dynamic ) ) {
            foreach ( $dynamic as $def ) {
                if ( empty( $def['slug'] ) ) {
                    continue;
                }
                $fields[] = 'resolate_field_' . sanitize_key( $def['slug'] );
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
    if ( ! $parent || 'resolate_document' !== $parent->post_type ) {
        return;
    }

    foreach ( $this->get_meta_fields_for_post( $post_id ) as $key ) {
        $value = get_post_meta( $post_id, $key, true );
        // Store only if it has something meaningful (empty array/string skipped).
        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                continue;
            }
        } else {
            if ( '' === trim( (string) $value ) ) {
                continue;
            }
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
 * @param bool     $post_has_changed Default change detection.
 * @param WP_Post  $last_revision    Last revision object.
 * @param WP_Post  $post             Current post object.
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

    // One provider per field to fetch the value from the revision post.
    add_filter( '_wp_post_revision_field_resolate_objeto', array( $this, 'revision_field_value' ), 10, 2 );
    add_filter( '_wp_post_revision_field_resolate_antecedentes', array( $this, 'revision_field_value' ), 10, 2 );
    add_filter( '_wp_post_revision_field_resolate_fundamentos', array( $this, 'revision_field_value' ), 10, 2 );
    add_filter( '_wp_post_revision_field_resolate_dispositivo', array( $this, 'revision_field_value' ), 10, 2 );
    add_filter( '_wp_post_revision_field_resolate_firma', array( $this, 'revision_field_value' ), 10, 2 );
    add_filter( '_wp_post_revision_field_resolate_annexes', array( $this, 'revision_field_annexes' ), 10, 2 );
}

/**
 * Add custom meta fields to the revisions UI.
 *
 * @param array $fields Existing fields.
 * @return array
 */
public function add_revision_fields( $fields, $post ) {
    $fields['resolate_objeto']       = __( 'Objeto', 'resolate' );
    $fields['resolate_antecedentes'] = __( 'Antecedentes', 'resolate' );
    $fields['resolate_fundamentos']  = __( 'Fundamentos de derecho', 'resolate' );
    $fields['resolate_dispositivo']  = __( 'Parte dispositiva (Resuelvo)', 'resolate' );
    $fields['resolate_firma']        = __( 'Firma / Pie', 'resolate' );
    $fields['resolate_annexes']      = __( 'Anexos', 'resolate' );
    // Add dynamic fields based on the document type.
    $ref = $post;
    // Normalize to WP_Post if needed.
    if ( is_array( $ref ) ) {
        if ( isset( $ref['ID'] ) && is_numeric( $ref['ID'] ) ) {
            $ref = get_post( intval( $ref['ID'] ) );
        } else {
            $ref = null;
        }
    } elseif ( is_numeric( $ref ) ) {
        $ref = get_post( intval( $ref ) );
    }
    if ( $ref && 'revision' === $ref->post_type && ! empty( $ref->post_parent ) ) {
        $ref = get_post( $ref->post_parent );
    }
    if ( $ref && is_object( $ref ) && 'resolate_document' === $ref->post_type ) {
        $schema = $this->get_dynamic_fields_schema_for_post( $ref->ID );
        if ( ! empty( $schema ) ) {
            foreach ( $schema as $def ) {
                if ( empty( $def['slug'] ) || empty( $def['label'] ) ) {
                    continue;
                }
                $key = 'resolate_field_' . sanitize_key( $def['slug'] );
                $fields[ $key ] = sanitize_text_field( $def['label'] );
                // Register provider dynamically for this request.
                add_filter( '_wp_post_revision_field_' . $key, array( $this, 'revision_field_value' ), 10, 2 );
            }
        }
    }
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
 * Provider for annexes (array) in revisions diff.
 *
 * @param string  $value     Current value (unused).
 * @param WP_Post $revision  Revision post object.
 * @return string
 */
public function revision_field_annexes( $value, $revision = null ) {
    // Resolve revision ID similar to revision_field_value.
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
    $annexes = get_metadata( 'post', $rev_id, 'resolate_annexes', true );
    if ( ! is_array( $annexes ) || empty( $annexes ) ) {
        return '';
    }
    $lines = array();
    foreach ( $annexes as $i => $anx ) {
        $title = isset( $anx['title'] ) ? (string) $anx['title'] : '';
        $text  = isset( $anx['text'] ) ? (string) $anx['text'] : '';
        $lines[] = sprintf(
            /* translators: 1: annex number, 2: annex title */
            __( 'Annex %1$d: %2$s', 'resolate' ),
            ( $i + 1 ),
            $title
        );
        $lines[] = $this->normalize_html_for_diff( $text );
        $lines[] = str_repeat( '-', 40 );
    }
    return implode( "\n", $lines );
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

        register_post_type( 'resolate_document', $args );
    }

    /**
     * Register taxonomies: ámbitos (hierarchical) and leyes (non-hierarchical).
     */
    public function register_taxonomies() {
        // Ámbitos.
        $ambitos_labels = array(
            'name'              => __( 'Ámbitos', 'resolate' ),
            'singular_name'     => __( 'Ámbito', 'resolate' ),
            'search_items'      => __( 'Buscar ámbitos', 'resolate' ),
            'all_items'         => __( 'Todos los ámbitos', 'resolate' ),
            'edit_item'         => __( 'Editar ámbito', 'resolate' ),
            'update_item'       => __( 'Actualizar ámbito', 'resolate' ),
            'add_new_item'      => __( 'Añadir nuevo ámbito', 'resolate' ),
            'new_item_name'     => __( 'Nuevo ámbito', 'resolate' ),
            'menu_name'         => __( 'Ámbitos', 'resolate' ),
        );
        register_taxonomy(
            'resolate_ambito',
            array( 'resolate_document' ),
            array(
                'hierarchical'      => true,
                'labels'            => $ambitos_labels,
                'show_ui'           => true,
                'show_admin_column' => true,
                'query_var'         => true,
                'rewrite'           => false,
                'show_in_rest'      => false,
            )
        );

        // Leyes.
        $leyes_labels = array(
            'name'                       => __( 'Leyes', 'resolate' ),
            'singular_name'              => __( 'Ley', 'resolate' ),
            'search_items'               => __( 'Buscar leyes', 'resolate' ),
            'popular_items'              => __( 'Leyes frecuentes', 'resolate' ),
            'all_items'                  => __( 'Todas las leyes', 'resolate' ),
            'edit_item'                  => __( 'Editar ley', 'resolate' ),
            'update_item'                => __( 'Actualizar ley', 'resolate' ),
            'add_new_item'               => __( 'Añadir nueva ley', 'resolate' ),
            'new_item_name'              => __( 'Nueva ley', 'resolate' ),
            'separate_items_with_commas' => __( 'Separar leyes con comas', 'resolate' ),
            'add_or_remove_items'        => __( 'Añadir o eliminar leyes', 'resolate' ),
            'choose_from_most_used'      => __( 'Elegir entre las más usadas', 'resolate' ),
            'menu_name'                  => __( 'Leyes', 'resolate' ),
        );
        register_taxonomy(
            'resolate_ley',
            array( 'resolate_document' ),
            array(
                'hierarchical'      => false,
                'labels'            => $leyes_labels,
                'show_ui'           => true,
                'show_admin_column' => false,
                'query_var'         => true,
                'rewrite'           => false,
                'show_in_rest'      => false,
            )
        );

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

        add_meta_box(
            'resolate_annexes',
            __( 'Anexos', 'resolate' ),
            array( $this, 'render_annexes_metabox' ),
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

        $terms = get_terms( array(
            'taxonomy'   => 'resolate_doc_type',
            'hide_empty' => false,
        ) );

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
        if ( ! empty( $schema ) ) {
            echo '<div class="resolate-sections">';
            foreach ( $schema as $def ) {
                $slug  = sanitize_key( isset( $def['slug'] ) ? $def['slug'] : '' );
                $label = isset( $def['label'] ) ? (string) $def['label'] : '';
                $type  = isset( $def['type'] ) ? (string) $def['type'] : 'textarea';
                if ( '' === $slug || '' === trim( $label ) ) {
                    continue;
                }
                $meta_key = 'resolate_field_' . $slug;
                $value    = get_post_meta( $post->ID, $meta_key, true );

                echo '<div class="resolate-field" style="margin-bottom:16px;">';
                echo '<label for="' . esc_attr( $meta_key ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( $label ) . '</label>';

                if ( 'single' === $type ) {
                    echo '<input type="text" class="widefat" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( (string) $value ) . '" />';
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
                    echo '<textarea class="widefat" rows="6" id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
                }
                echo '</div>';
            }
            echo '</div>';
            return;
        }

        // Fallback legacy sections when no tipo is selected or has no schema.
        $fields = array(
            'resolate_objeto'       => __( 'Objeto', 'resolate' ),
            'resolate_antecedentes' => __( 'Antecedentes', 'resolate' ),
            'resolate_fundamentos'  => __( 'Fundamentos de derecho', 'resolate' ),
            'resolate_dispositivo'  => __( 'Parte dispositiva (Resuelvo)', 'resolate' ),
            'resolate_firma'        => __( 'Firma / Pie', 'resolate' ),
        );

        echo '<div class="resolate-sections">';
        foreach ( $fields as $key => $label ) {
            $value = get_post_meta( $post->ID, $key, true );
            echo '<div class="resolate-field" style="margin-bottom:16px;">';
            echo '<label for="' . esc_attr( $key ) . '" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html( $label ) . '</label>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
            wp_editor(
                $value,
                $key,
                array(
                    'textarea_name' => $key,
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
            echo '</div>';
        }
        echo '</div>';
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

        // Save dynamic fields if the selected type defines them; otherwise fallback to legacy.
        $schema = $this->get_dynamic_fields_schema_for_post( $post_id );
        if ( ! empty( $schema ) ) {
            foreach ( $schema as $def ) {
                if ( empty( $def['slug'] ) ) { continue; }
                $slug = sanitize_key( $def['slug'] );
                $type = isset( $def['type'] ) ? (string) $def['type'] : 'textarea';
                $key  = 'resolate_field_' . $slug;
                if ( isset( $_POST[ $key ] ) ) {
                    if ( 'rich' === $type ) {
                        $value = wp_kses_post( wp_unslash( $_POST[ $key ] ) );
                    } else {
                        $value = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
                    }
                    if ( '' !== $value && ! ( is_array( $value ) && empty( $value ) ) ) {
                        update_post_meta( $post_id, $key, $value );
                    } else {
                        delete_post_meta( $post_id, $key );
                    }
                }
            }
        } else {
            $fields = array(
                'resolate_objeto',
                'resolate_antecedentes',
                'resolate_fundamentos',
                'resolate_dispositivo',
                'resolate_firma',
            );
            foreach ( $fields as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $value = wp_kses_post( wp_unslash( $_POST[ $key ] ) );
                    update_post_meta( $post_id, $key, $value );
                }
            }
        }

        // Save annexes.
        $annexes = array();
        if ( isset( $_POST['resolate_annexes'] ) && is_array( $_POST['resolate_annexes'] ) ) {
            $raw = $_POST['resolate_annexes']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            foreach ( $raw as $idx => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $title = isset( $item['title'] ) ? sanitize_text_field( wp_unslash( $item['title'] ) ) : '';
                $text  = isset( $item['text'] ) ? wp_kses_post( wp_unslash( $item['text'] ) ) : '';
                if ( '' === trim( (string) $title ) && '' === trim( (string) $text ) ) {
                    continue;
                }
                $annexes[] = array(
                    'title' => $title,
                    'text'  => $text,
                );
            }
        }
        if ( ! empty( $annexes ) ) {
            update_post_meta( $post_id, 'resolate_annexes', $annexes );
        } else {
            delete_post_meta( $post_id, 'resolate_annexes' );
        }

        // post_content is composed in wp_insert_post_data filter; avoid recursion here.
    }

    /**
     * Render the annexes meta box.
     *
     * @param WP_Post $post Current post.
     * @return void
     */
    public function render_annexes_metabox( $post ) {
        // Reuse the same nonce as sections box.
        if ( ! isset( $_POST['resolate_sections_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            wp_nonce_field( 'resolate_sections_nonce', 'resolate_sections_nonce' );
        }

        $annexes = get_post_meta( $post->ID, 'resolate_annexes', true );
        if ( ! is_array( $annexes ) ) {
            $annexes = array();
        }

        echo '<div id="resolate-annexes" class="resolate-annexes">';
        echo '<p class="description" style="margin-bottom:12px;">' . esc_html__( 'Añade anexos como páginas adicionales. Cada anexo tiene su propio título y texto.', 'resolate' ) . '</p>';
        echo '<div id="resolate-annex-list">';

        foreach ( $annexes as $i => $anx ) {
            $t = isset( $anx['title'] ) ? (string) $anx['title'] : '';
            $c = isset( $anx['text'] ) ? (string) $anx['text'] : '';
            echo '<div class="resolate-annex-item" data-index="' . esc_attr( (string) $i ) . '" style="border:1px solid #e5e5e5;padding:12px;margin-bottom:12px;background:#fff;">';
            echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px;">';
            echo '<strong>' . esc_html( sprintf( /* translators: %d: annex number */ __( 'Anexo %d', 'resolate' ), ( $i + 1 ) ) ) . '</strong>';
            echo '<button type="button" class="button button-link-delete resolate-annex-remove" aria-label="' . esc_attr__( 'Eliminar anexo', 'resolate' ) . '">&times; ' . esc_html__( 'Eliminar', 'resolate' ) . '</button>';
            echo '</div>';
            echo '<label>' . esc_html__( 'Título del anexo', 'resolate' ) . '</label>';
            echo '<input type="text" class="widefat" name="resolate_annexes[' . esc_attr( (string) $i ) . '][title]" value="' . esc_attr( $t ) . '" />';
            echo '<label style="margin-top:8px;display:block;">' . esc_html__( 'Texto del anexo', 'resolate' ) . '</label>';
            // Classic Editor instance per annex text.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
            wp_editor(
                $c,
                'resolate_annex_text_' . (string) $i,
                array(
                    'textarea_name' => 'resolate_annexes[' . (string) $i . '][text]',
                    'textarea_rows' => 6,
                    'media_buttons' => false,
                    'teeny'         => false,
                    'tinymce'       => array(
                        'toolbar1' => 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,undo,redo,removeformat',
                    ),
                    'quicktags'     => true,
                    'editor_height' => 170,
                )
            );
            echo '</div>';
        }

        echo '</div>'; // list
        echo '<p><button type="button" class="button" id="resolate-add-annex">' . esc_html__( 'Añadir anexo', 'resolate' ) . '</button></p>';

        // Template.
        echo '<script type="text/template" id="resolate-annex-template">';
        echo '<div class=\"resolate-annex-item\" data-index=\"__i__\" style=\"border:1px solid #e5e5e5;padding:12px;margin-bottom:12px;background:#fff;\">';
        echo '<div style=\"display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px;\">';
        echo '<strong>' . esc_html__( 'Anexo __n__', 'resolate' ) . '</strong>';
        echo '<button type=\"button\" class=\"button button-link-delete resolate-annex-remove\" aria-label=\"' . esc_attr__( 'Eliminar anexo', 'resolate' ) . '\">&times; ' . esc_html__( 'Eliminar', 'resolate' ) . '</button>';
        echo '</div>';
        echo '<label>' . esc_html__( 'Título del anexo', 'resolate' ) . '</label>';
        echo '<input type=\"text\" class=\"widefat\" name=\"resolate_annexes[__i__][title]\" value=\"\" />';
        echo '<label style=\"margin-top:8px;display:block;\">' . esc_html__( 'Texto del anexo', 'resolate' ) . '</label>';
        echo '<textarea id=\"resolate_annex_text___i__\" class=\"widefat\" rows=\"6\" name=\"resolate_annexes[__i__][text]\"></textarea>';
        echo '</div>';
        echo '</script>';

        echo '</div>'; // wrapper
    }

    /**
     * Build a Gutenberg-compatible post_content from current fields so core revisions UI shows diffs.
     *
     * The content is composed of Heading blocks (labels) and Paragraph/HTML blocks (values),
     * and includes Annexes as additional sections. Meta remains the source of truth for generators.
     *
     * @param int $post_id Post ID.
     * @return void
     */
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

        // Determine the schema. Prefer the type posted; fallback to current assigned; then legacy.
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
        $schema = array();
        if ( $term_id > 0 ) {
            $schema = $this->get_schema_for_term( $term_id );
        }
        if ( empty( $schema ) ) {
            $schema = array(
                array( 'slug' => 'objeto',       'label' => __( 'Objeto', 'resolate' ),                   'type' => 'rich' ),
                array( 'slug' => 'antecedentes', 'label' => __( 'Antecedentes', 'resolate' ),             'type' => 'rich' ),
                array( 'slug' => 'fundamentos',  'label' => __( 'Fundamentos de derecho', 'resolate' ),   'type' => 'rich' ),
                array( 'slug' => 'dispositivo',  'label' => __( 'Parte dispositiva (Resuelvo)', 'resolate' ), 'type' => 'rich' ),
                array( 'slug' => 'firma',        'label' => __( 'Firma / Pie', 'resolate' ),              'type' => 'rich' ),
            );
        }

        $blocks = array();
        foreach ( $schema as $row ) {
            if ( empty( $row['slug'] ) || empty( $row['label'] ) ) { continue; }
            $slug  = sanitize_key( $row['slug'] );
            $label = sanitize_text_field( $row['label'] );
            $type  = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';

            // Prefer posted value (most up-to-date); fallback to stored meta.
            $meta_key = empty( $term_id ) ? 'resolate_' . $slug : 'resolate_field_' . $slug;
            $posted_key = empty( $term_id ) ? $meta_key : $meta_key; // same variable name.
            $val = '';
            if ( isset( $_POST[ $posted_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( 'single' === $type ) {
                    $val = sanitize_text_field( wp_unslash( $_POST[ $posted_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                } else {
                    $val = wp_kses_post( wp_unslash( $_POST[ $posted_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
                }
            } elseif ( $post_id > 0 ) {
                $val = get_post_meta( $post_id, $meta_key, true );
            }

            $blocks[] = '<!-- wp:heading {"level":2} -->' . "\n" . '<h2>' . esc_html( $label ) . '</h2>' . "\n" . '<!-- /wp:heading -->';
            if ( 'single' === $type ) {
                $blocks[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . esc_html( (string) $val ) . '</p>' . "\n" . '<!-- /wp:paragraph -->';
            } else {
                $blocks[] = '<!-- wp:html -->' . "\n" . (string) $val . "\n" . '<!-- /wp:html -->';
            }
        }

        // Annexes from POST; fallback to saved.
        $annexes = array();
        if ( isset( $_POST['resolate_annexes'] ) && is_array( $_POST['resolate_annexes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $annexes = array();
            foreach ( $_POST['resolate_annexes'] as $item ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                if ( ! is_array( $item ) ) { continue; }
                $annexes[] = array(
                    'title' => isset( $item['title'] ) ? sanitize_text_field( wp_unslash( $item['title'] ) ) : '',
                    'text'  => isset( $item['text'] ) ? wp_kses_post( wp_unslash( $item['text'] ) ) : '',
                );
            }
        } elseif ( $post_id > 0 ) {
            $saved = get_post_meta( $post_id, 'resolate_annexes', true );
            if ( is_array( $saved ) ) { $annexes = $saved; }
        }
        if ( ! empty( $annexes ) ) {
            $blocks[] = '<!-- wp:heading {"level":2} -->' . "\n" . '<h2>' . esc_html__( 'Anexos', 'resolate' ) . '</h2>' . "\n" . '<!-- /wp:heading -->';
            $roman = function( $num ) {
                $map = array( 'M'=>1000,'CM'=>900,'D'=>500,'CD'=>400,'C'=>100,'XC'=>90,'L'=>50,'XL'=>40,'X'=>10,'IX'=>9,'V'=>5,'IV'=>4,'I'=>1 );
                $res = '';
                foreach ( $map as $rom => $int ) { while ( $num >= $int ) { $res .= $rom; $num -= $int; } }
                return $res;
            };
            foreach ( $annexes as $i => $anx ) {
                $title = isset( $anx['title'] ) ? sanitize_text_field( $anx['title'] ) : '';
                $text  = isset( $anx['text'] ) ? wp_kses_post( $anx['text'] ) : '';
                $blocks[] = '<!-- wp:heading {"level":3} -->' . "\n" . '<h3>' . esc_html( sprintf( 'Anexo %s', $roman( $i + 1 ) ) ) . ( $title ? ': ' . esc_html( $title ) : '' ) . '</h3>' . "\n" . '<!-- /wp:heading -->';
                $blocks[] = '<!-- wp:html -->' . "\n" . $text . "\n" . '<!-- /wp:html -->';
            }
        }

        $data['post_content'] = implode( "\n\n", $blocks );
        return $data;
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
        return $this->get_schema_for_term( $term_id );
    }

    /**
     * Get sanitized schema array for a document type term.
     *
     * @param int $term_id Term ID.
     * @return array[]
     */
    private function get_schema_for_term( $term_id ) {
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
            $slug  = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
            $label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
            $type  = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
            if ( '' === $slug || '' === $label ) {
                continue;
            }
            if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
                $type = 'textarea';
            }
            $out[] = array(
                'slug'  => $slug,
                'label' => $label,
                'type'  => $type,
            );
        }
        return $out;
    }
}

// Initialize.
new Resolate_Documents();
