<?php
/**
 * Custom factory for Resolate Documents.
 *
 * @package Resolate
 */

/**
 * Class WP_UnitTest_Factory_For_Resolate_Document
 *
 * This factory creates and updates posts of the 'resolate_document' post type.
 * It also supports assigning a document type term (resolate_doc_type).
 */
class WP_UnitTest_Factory_For_Resolate_Document extends WP_UnitTest_Factory_For_Post {

	/**
	 * Constructor.
	 *
	 * Initializes the default generation definitions for creating resolate_document posts.
	 *
	 * @param object $factory Global factory that can be used to create other objects.
	 */
	public function __construct( $factory = null ) {
		parent::__construct( $factory );

		$this->default_generation_definitions = array(
			'post_title'   => new WP_UnitTest_Generator_Sequence( 'Resolate Document %s' ),
			'post_status'  => 'private',
			'post_type'    => 'resolate_document',
			'post_content' => '',
			'meta_input'   => array(),
		);
	}

	/**
	 * Create a resolate_document post object.
	 *
	 * @param array $args Arguments for the post creation.
	 *                    Optional keys: 'post_title', 'post_content', 'meta_input', 'doc_type'.
	 * @return int|WP_Error The post ID on success, or WP_Error on failure.
	 */
	public function create_object( $args ) {
		$defaults = array(
			'post_title'   => 'Untitled Document',
			'post_status'  => 'private',
			'post_type'    => 'resolate_document',
			'post_content' => '',
			'post_author'  => 0,
			'meta_input'   => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$original_user_id = get_current_user_id();
		$switched_user    = false;
		$author_id        = (int) $args['post_author'];

		if ( ! current_user_can( 'edit_posts' ) && $author_id && user_can( $author_id, 'edit_posts' ) ) {
			wp_set_current_user( $author_id );
			$switched_user = true;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			if ( $switched_user ) {
				wp_set_current_user( $original_user_id );
			}

			return new WP_Error(
				'rest_forbidden',
				'Insufficient permissions to create resolate_document post.',
				array( 'status' => 403 )
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $args['post_title'],
				'post_status'  => $args['post_status'],
				'post_type'    => $args['post_type'],
				'post_content' => $args['post_content'],
				'post_author'  => $author_id,
				'meta_input'   => $args['meta_input'],
			),
			true
		);

		if ( $switched_user ) {
			wp_set_current_user( $original_user_id );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( isset( $args['doc_type'] ) && $args['doc_type'] ) {
			$term_id = (int) $args['doc_type'];
			if ( term_exists( $term_id, 'resolate_doc_type' ) ) {
				wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );
			}
		}

		return $post_id;
	}

    /**
     * Update a resolate_document post object.
     *
     * @param int   $post_id Post ID to update.
     * @param array $fields  Fields to update.
     *                       Can include 'post_title', 'post_content', 'meta_input', 'doc_type'.
     * @return int|WP_Error Updated post ID on success, or WP_Error on failure.
     */
    public function update_object( $post_id, $fields ) {

        // Ensure current user can edit posts.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return new WP_Error(
                'rest_forbidden',
                'Insufficient permissions to update resolate_document post.',
                array( 'status' => 403 )
            );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'resolate_document' !== $post->post_type ) {
            return new WP_Error( 'invalid_post', 'Invalid resolate_document post ID provided.' );
        }

        $update_args = array(
            'ID' => $post_id,
        );

        if ( isset( $fields['post_title'] ) ) {
            $update_args['post_title'] = $fields['post_title'];
        }

        if ( isset( $fields['post_content'] ) ) {
            $update_args['post_content'] = $fields['post_content'];
        }

        if ( isset( $fields['meta_input'] ) && is_array( $fields['meta_input'] ) ) {
            foreach ( $fields['meta_input'] as $key => $value ) {
                update_post_meta( $post_id, sanitize_key( $key ), $value );
            }
        }

        // Update the post if needed.
        if ( count( $update_args ) > 1 ) {
            $result = wp_update_post( $update_args, true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        // Update taxonomy term if provided.
        if ( isset( $fields['doc_type'] ) ) {
            $term_id = (int) $fields['doc_type'];
            if ( term_exists( $term_id, 'resolate_doc_type' ) ) {
                wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );
            }
        }

        return $post_id;
    }
}
