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

        $upload = wp_upload_dir();
        wp_mkdir_p( $upload['basedir'] );
        $file   = trailingslashit( $upload['basedir'] ) . 'resolate-test-template.docx';

        if ( file_exists( $file ) ) {
            unlink( $file );
        }

        $zip = new ZipArchive();
        $created = $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        $this->assertTrue( $created );
        $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>[Campo_1]</w:t></w:r></w:p><w:p><w:r><w:t>[campo2]</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString( 'word/document.xml', $xml );
        $zip->close();

        $filetype = wp_check_filetype( basename( $file ), null );
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => 'Plantilla DOCX',
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment( $attachment, $file );
        $this->assertGreaterThan( 0, $attach_id );

        $_POST['resolate_type_template_id'] = (string) $attach_id;
        $_POST['resolate_type_color']       = '#123abc';

        $admin = new Resolate_Doc_Types_Admin();
        $admin->save_term( $tid );

        $this->assertEquals( $attach_id, intval( get_term_meta( $tid, 'resolate_type_template_id', true ) ) );
        $this->assertEquals( 'docx', get_term_meta( $tid, 'resolate_type_template_type', true ) );
        $this->assertEquals( '#123abc', get_term_meta( $tid, 'resolate_type_color', true ) );

        $schema = get_term_meta( $tid, 'schema', true );
        $this->assertIsArray( $schema );
        $this->assertCount( 2, $schema );
        $this->assertEquals( 'campo_1', $schema[0]['slug'] );
        $this->assertEquals( 'Campo 1', $schema[0]['label'] );
        $this->assertEquals( 'textarea', $schema[0]['type'] );
        $this->assertEquals( 'campo2', $schema[1]['slug'] );
        $this->assertEquals( 'Campo2', $schema[1]['label'] );

        $legacy = get_term_meta( $tid, 'resolate_type_fields', true );
        $this->assertEquals( $schema, $legacy );

        $_POST = array();
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
        $schema = array( array( 'slug' => 'campo1', 'label' => 'Campo 1', 'type' => 'textarea' ) );
        update_term_meta( $tid, 'schema', $schema );
        update_term_meta( $tid, 'resolate_type_fields', $schema );

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
