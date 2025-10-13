<?php
/**
 * Tests for Resolate document types (taxonomy) and dynamic fields.
 */

class ResolateDocumentTypesTest extends WP_UnitTestCase {

    public function set_up() : void {
        parent::set_up();
        // Ensure CPT and taxonomies are registered.
        register_post_type( 'resolate_document', array( 'public' => false ) );
        register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
    }

    public function test_registers_document_type_taxonomy() {
        $this->assertTrue( taxonomy_exists( 'resolate_doc_type' ), 'Taxonomy resolate_doc_type should exist.' );
    }

    public function test_type_meta_schema_saved() {
        $term = wp_insert_term( 'Tipo A', 'resolate_doc_type' );
        $this->assertIsArray( $term );
        $tid = intval( $term['term_id'] );

        $_POST['resolate_type_docx_template'] = '123';
        $_POST['resolate_type_odt_template']  = '456';
        $_POST['resolate_type_logos']         = '10, 20 , 30';
        $_POST['resolate_type_font_name']     = 'Georgia';
        $_POST['resolate_type_font_size']     = '14';
        $_POST['resolate_type_fields_json']   = wp_json_encode( array(
            array( 'slug' => 'campo1', 'label' => 'Campo 1', 'type' => 'single' ),
            array( 'slug' => 'campo2', 'label' => 'Campo 2', 'type' => 'rich' ),
        ) );

        $admin = new Resolate_Doc_Types_Admin();
        $admin->save_term( $tid );

        $this->assertEquals( 123, intval( get_term_meta( $tid, 'resolate_type_docx_template', true ) ) );
        $this->assertEquals( 456, intval( get_term_meta( $tid, 'resolate_type_odt_template', true ) ) );
        $this->assertEquals( array( 10, 20, 30 ), get_term_meta( $tid, 'resolate_type_logos', true ) );
        $this->assertEquals( 'Georgia', get_term_meta( $tid, 'resolate_type_font_name', true ) );
        $this->assertEquals( 14, intval( get_term_meta( $tid, 'resolate_type_font_size', true ) ) );
        $schema = get_term_meta( $tid, 'resolate_type_fields', true );
        $this->assertIsArray( $schema );
        $this->assertCount( 2, $schema );
        $this->assertEquals( 'campo1', $schema[0]['slug'] );
        $this->assertEquals( 'Campo 1', $schema[0]['label'] );
        $this->assertEquals( 'single', $schema[0]['type'] );
    }

    public function test_document_type_locked_after_first_save() {
        $termA = wp_insert_term( 'Tipo A', 'resolate_doc_type' );
        $termB = wp_insert_term( 'Tipo B', 'resolate_doc_type' );
        $tidA = intval( $termA['term_id'] );
        $tidB = intval( $termB['term_id'] );

        $post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc', 'post_status' => 'draft' ) );
        $this->assertNotWPError( $post_id );

        $doc = new Resolate_Documents();
        $_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
        $_POST['resolate_type_nonce']     = wp_create_nonce( 'resolate_type_nonce' );
        $_POST['resolate_doc_type']       = (string) $tidA;
        $doc->save_meta_boxes( $post_id );

        $assigned = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
        $this->assertEquals( array( $tidA ), array_map( 'intval', $assigned ) );

        // Try to change type to B.
        $_POST['resolate_doc_type']       = (string) $tidB;
        $doc->save_meta_boxes( $post_id );
        $assigned2 = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
        $this->assertEquals( array( $tidA ), array_map( 'intval', $assigned2 ), 'Document type should remain locked to initial value.' );
    }

    public function test_dynamic_fields_saved_and_revision_copied() {
        // Create a type with one field.
        $term = wp_insert_term( 'Tipo X', 'resolate_doc_type' );
        $tid  = intval( $term['term_id'] );
        update_term_meta( $tid, 'resolate_type_fields', array( array( 'slug' => 'campo1', 'label' => 'Campo 1', 'type' => 'textarea' ) ) );

        $post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc', 'post_status' => 'draft' ) );
        wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

        $doc = new Resolate_Documents();
        $_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
        $_POST['resolate_field_campo1']   = 'Valor X';
        $doc->save_meta_boxes( $post_id );

        $this->assertEquals( 'Valor X', get_post_meta( $post_id, 'resolate_field_campo1', true ) );

        // Create a revision and trigger copying meta.
        $rev_id = wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $post_id, 'post_title' => 'Rev' ) );
        do_action( 'wp_save_post_revision', $post_id, $rev_id );
        $this->assertEquals( 'Valor X', get_metadata( 'post', $rev_id, 'resolate_field_campo1', true ) );
    }
}
