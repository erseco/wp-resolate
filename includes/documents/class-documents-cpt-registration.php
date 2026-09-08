<?php
/**
 * CPT Registration for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Handles Custom Post Type and Taxonomy registration for documents.
 */
class Documents_CPT_Registration {
	/**
	 * Register hooks for CPT/taxonomy registration.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_filter( 'get_default_comment_status', array( $this, 'set_default_comment_status_open' ), 10, 3 );
	}

	/**
	 * Register the Documents custom post type and attach core categories.
	 */
	public function register_post_type() {
		$labels = array(
			'name' => 'Documentos',
			'singular_name' => 'Documento',
			'menu_name' => 'Documentos',
			'name_admin_bar' => 'Documento',
			'add_new' => 'Añadir nuevo',
			'add_new_item' => 'Añadir nuevo documento',
			'new_item' => 'Nuevo documento',
			'edit_item' => 'Editar documento',
			'view_item' => 'Ver documento',
			'all_items' => 'Todos los documentos',
			'search_items' => 'Buscar documentos',
			'not_found' => 'No se encontraron documentos.',
			'not_found_in_trash' => 'No se encontraron documentos en la papelera.',
		);

		$args = array(
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'menu_position' => 25,
			'menu_icon' => 'dashicons-media-document',
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'supports' => array( 'title', 'author', 'revisions', 'comments' ),
			'taxonomies' => array( 'category' ),
			'has_archive' => false,
			'rewrite' => false,
			'show_in_rest' => false,
		);

		register_post_type( 'documentate_document', $args );
		register_taxonomy_for_object_type( 'category', 'documentate_document' );
	}

	/**
	 * Register taxonomies used by the documents CPT.
	 */
	public function register_taxonomies() {
		// Document types (define templates and custom fields for the document).
		$types_labels = array(
			'name' => 'Tipos de documento',
			'singular_name' => 'Tipo de documento',
			'search_items' => 'Buscar tipos',
			'all_items' => 'Todos los tipos',
			'edit_item' => 'Editar tipo',
			'update_item' => 'Actualizar tipo',
			'add_new_item' => 'Añadir nuevo tipo',
			'new_item_name' => 'Nuevo tipo',
			'menu_name' => 'Tipos de documento',
		);

		register_taxonomy(
			'documentate_doc_type',
			array( 'documentate_document' ),
			array(
				'hierarchical' => false,
				'labels' => $types_labels,
				'show_ui' => true,
				'show_admin_column' => true,
				'query_var' => true,
				'rewrite' => false,
				'show_in_rest' => false,
				// Only administrators can manage document types.
				'capabilities' => array(
					'manage_terms' => 'manage_options',
					'edit_terms' => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				),
				// We'll use a custom metabox to prevent editing after first save.
				'meta_box_cb' => false,
			),
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
		if ( 'documentate_document' === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Set default comment status to open for documentate_document.
	 *
	 * @param string $status       Default comment status ('open' or 'closed').
	 * @param string $post_type    Post type being queried.
	 * @param string $comment_type Comment type.
	 * @return string Modified default comment status.
	 */
	public function set_default_comment_status_open( $status, $post_type, $comment_type ) {
		if ( 'documentate_document' === $post_type ) {
			return 'open';
		}
		return $status;
	}
}
