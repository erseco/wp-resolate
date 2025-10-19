<?php
/**
 * Tests for demo document seeding.
 */

class ResolateDemoDocumentsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	/**
	 * It should create one demo document per document type with structured content.
	 */
	public function test_demo_documents_seeded_per_type() {
		delete_option( 'resolate_seed_demo_documents' );
		update_option( 'resolate_seed_demo_documents', true );

		resolate_ensure_default_media();
		resolate_maybe_seed_default_doc_types();

		$terms = get_terms(
			array(
				'taxonomy'   => 'resolate_doc_type',
				'hide_empty' => false,
			)
		);

		$this->assertNotWPError( $terms );
		$this->assertNotEmpty( $terms );

		resolate_maybe_seed_demo_documents();

		foreach ( $terms as $term ) {
			$posts = get_posts(
				array(
					'post_type'      => 'resolate_document',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_resolate_demo_type_id',
					'meta_value'     => (string) $term->term_id,
				)
			);

			$this->assertCount( 1, $posts, 'Debe crear un único documento de prueba por cada tipo.' );

			$post_id = intval( $posts[0] );
			$this->assertGreaterThan( 0, $post_id );

			$assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			$this->assertNotWPError( $assigned );
			$this->assertContains( $term->term_id, $assigned, 'El documento de prueba debe estar asignado al tipo correspondiente.' );

			$structured = Resolate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
			$this->assertNotEmpty( $structured, 'El documento de prueba debe incluir contenido estructurado.' );
		}

		$this->assertFalse( get_option( 'resolate_seed_demo_documents', false ), 'La opción de sembrado debe eliminarse tras crear los documentos.' );
	}
}

