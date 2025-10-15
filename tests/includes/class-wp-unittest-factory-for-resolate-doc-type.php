<?php
/**
 * Custom factory for Resolate Document Type taxonomy terms.
 *
 * @package Resolate
 */

/**
 * Class WP_UnitTest_Factory_For_Resolate_Doc_Type
 *
 * This factory creates and updates terms in the 'resolate_doc_type' taxonomy.
 * It also handles setting the 'schema' meta for defining the document structure.
 */
class WP_UnitTest_Factory_For_Resolate_Doc_Type extends WP_UnitTest_Factory_For_Term {

    /**
     * Constructor.
     *
     * Initializes the default generation definitions for creating resolate_doc_type terms.
     *
     * @param object $factory Global factory that can be used to create other objects in the system.
     */
    public function __construct( $factory = null ) {
        parent::__construct( $factory, 'resolate_doc_type' );

        // Default term generation: a sequence for the name and a simple default schema.
        $this->default_generation_definitions = array(
            'name'        => new WP_UnitTest_Generator_Sequence( 'Document Type %s' ),
            'slug'        => new WP_UnitTest_Generator_Sequence( 'doctype-%s' ),
            'description' => 'Default document type for tests.',
            'schema'      => array(
                array(
                    'slug'        => 'title',
                    'label'       => 'Title',
                    'type'        => 'single',
                    'data_type'   => 'text',
                    'placeholder' => 'Document title',
                ),
                array(
                    'slug'        => 'content',
                    'label'       => 'Content',
                    'type'        => 'rich',
                    'data_type'   => 'text',
                    'placeholder' => 'Main content',
                ),
            ),
        );
    }

    /**
     * Create a resolate_doc_type term object.
     *
     * @param array $args Arguments for the term creation.
     *                    Must include 'name' key.
     *                    Optional: 'description', 'schema'.
     * @return int|WP_Error The term ID on success, or WP_Error on failure.
     */
    public function create_object( $args ) {

        // Ensure current user can manage terms.
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Insufficient permissions to create resolate_doc_type term.',
                array( 'status' => 403 )
            );
        }

        // Create term via parent.
        $term_id = parent::create_object(
            array(
                'name'        => $args['name'],
                'slug'        => $args['slug'],
                'description' => $args['description'],
            )
        );

        if ( is_wp_error( $term_id ) ) {
            return $term_id;
        }

        // Assign schema meta.
        if ( isset( $args['schema'] ) && is_array( $args['schema'] ) ) {
            update_term_meta( $term_id, 'schema', $args['schema'] );
        }

        return $term_id;
    }

    /**
     * Update a resolate_doc_type term object.
     *
     * @param int   $term_id Term ID to update.
     * @param array $fields  Fields to update.
     *                       Can include 'name', 'description', 'schema'.
     * @return int|WP_Error Updated term ID on success, or WP_Error on failure.
     */
    public function update_object( $term_id, $fields ) {

        // Ensure current user can manage terms.
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Insufficient permissions to update resolate_doc_type term.',
                array( 'status' => 403 )
            );
        }

        $term = get_term( $term_id, 'resolate_doc_type' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'invalid_term', 'Invalid resolate_doc_type term ID provided.' );
        }

        // Update basic fields.
        $update_args = array();
        if ( isset( $fields['name'] ) ) {
            $update_args['name'] = $fields['name'];
        }
        if ( isset( $fields['slug'] ) ) {
            $update_args['slug'] = $fields['slug'];
        }
        if ( isset( $fields['description'] ) ) {
            $update_args['description'] = $fields['description'];
        }

        if ( $update_args ) {
            $result = parent::update_object( $term_id, $update_args );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        // Update schema meta if provided.
        if ( isset( $fields['schema'] ) && is_array( $fields['schema'] ) ) {
            update_term_meta( $term_id, 'schema', $fields['schema'] );
        }

        return $term_id;
    }
}
