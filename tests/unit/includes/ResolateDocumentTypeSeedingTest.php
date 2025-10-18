<?php
/**
 * Tests for default document type seeding.
 */

class ResolateDocumentTypeSeedingTest extends WP_UnitTestCase {

    public function set_up() : void {
        parent::set_up();
        register_post_type( 'resolate_document', array( 'public' => false ) );
        register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
    }

    /**
     * Ensure default document types are created with templates.
     */
    public function test_default_document_types_seeded() {
        $this->delete_term_if_exists( 'resolate-demo-odt' );
        $this->delete_term_if_exists( 'resolate-demo-docx' );
        $this->delete_term_if_exists( 'resolate-demo-wp-resolate-odt' );
        $this->delete_term_if_exists( 'resolate-demo-wp-resolate-docx' );

        resolate_ensure_default_media();
        resolate_maybe_seed_default_doc_types();

        $odt = get_term_by( 'slug', 'resolate-demo-odt', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $odt );
        $odt_template_id = intval( get_term_meta( $odt->term_id, 'resolate_type_template_id', true ) );
        $this->assertGreaterThan( 0, $odt_template_id );
        $this->assertSame( 'odt', get_term_meta( $odt->term_id, 'resolate_type_template_type', true ) );
        $this->assertSame( 'resolate-demo-odt', get_term_meta( $odt->term_id, '_resolate_fixture', true ) );
        $odt_schema = get_term_meta( $odt->term_id, 'schema', true );
        $this->assertIsArray( $odt_schema );
        $this->assertContainsPlaceholders( $odt_schema );

        $docx = get_term_by( 'slug', 'resolate-demo-docx', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $docx );
        $docx_template_id = intval( get_term_meta( $docx->term_id, 'resolate_type_template_id', true ) );
        $this->assertGreaterThan( 0, $docx_template_id );
        $this->assertSame( 'docx', get_term_meta( $docx->term_id, 'resolate_type_template_type', true ) );
        $this->assertSame( 'resolate-demo-docx', get_term_meta( $docx->term_id, '_resolate_fixture', true ) );
        $docx_schema = get_term_meta( $docx->term_id, 'schema', true );
        $this->assertIsArray( $docx_schema );
        $this->assertContainsPlaceholders( $docx_schema );

        $advanced_odt = get_term_by( 'slug', 'resolate-demo-wp-resolate-odt', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $advanced_odt );
        $advanced_odt_template_id = intval( get_term_meta( $advanced_odt->term_id, 'resolate_type_template_id', true ) );
        $this->assertGreaterThan( 0, $advanced_odt_template_id );
        $this->assertSame( 'odt', get_term_meta( $advanced_odt->term_id, 'resolate_type_template_type', true ) );
        $this->assertSame( 'resolate-demo-wp-resolate-odt', get_term_meta( $advanced_odt->term_id, '_resolate_fixture', true ) );
        $advanced_odt_schema = get_term_meta( $advanced_odt->term_id, 'schema', true );
        $this->assertIsArray( $advanced_odt_schema );
        $this->assertNotEmpty( $advanced_odt_schema );

        $advanced_docx = get_term_by( 'slug', 'resolate-demo-wp-resolate-docx', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $advanced_docx );
        $advanced_docx_template_id = intval( get_term_meta( $advanced_docx->term_id, 'resolate_type_template_id', true ) );
        $this->assertGreaterThan( 0, $advanced_docx_template_id );
        $this->assertSame( 'docx', get_term_meta( $advanced_docx->term_id, 'resolate_type_template_type', true ) );
        $this->assertSame( 'resolate-demo-wp-resolate-docx', get_term_meta( $advanced_docx->term_id, '_resolate_fixture', true ) );
        $advanced_docx_schema = get_term_meta( $advanced_docx->term_id, 'schema', true );
        $this->assertIsArray( $advanced_docx_schema );
        $this->assertNotEmpty( $advanced_docx_schema );

        resolate_maybe_seed_default_doc_types();
        $odt_after = get_term_by( 'slug', 'resolate-demo-odt', 'resolate_doc_type' );
        $this->assertSame( $odt->term_id, $odt_after->term_id );
        $advanced_odt_after = get_term_by( 'slug', 'resolate-demo-wp-resolate-odt', 'resolate_doc_type' );
        $this->assertSame( $advanced_odt->term_id, $advanced_odt_after->term_id );
        $advanced_docx_after = get_term_by( 'slug', 'resolate-demo-wp-resolate-docx', 'resolate_doc_type' );
        $this->assertSame( $advanced_docx->term_id, $advanced_docx_after->term_id );
    }

    /**
     * Remove a term by slug if present.
     *
     * @param string $slug Term slug.
     * @return void
     */
    private function delete_term_if_exists( $slug ) {
        $term = get_term_by( 'slug', $slug, 'resolate_doc_type' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_delete_term( $term->term_id, 'resolate_doc_type' );
        }
    }

    /**
     * Assert that schema contains expected placeholders.
     *
     * @param array $schema Schema array.
     * @return void
     */
    private function assertContainsPlaceholders( $schema ) {
        $slugs = array();
        foreach ( $schema as $item ) {
            if ( is_array( $item ) && isset( $item['slug'] ) ) {
                $slugs[] = (string) $item['slug'];
            }
        }

        sort( $slugs );
        $expected = array( 'antecedentes', 'dispositivo', 'fundamentos', 'objeto', 'post_title' );
        sort( $expected );
        $this->assertSame( $expected, $slugs );
    }
}
