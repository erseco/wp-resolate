<?php
/**
 * Tests for Resolate document types (taxonomy) and dynamic fields.
 */

use Resolate\DocType\SchemaStorage;

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

        $storage = new SchemaStorage();
        $schema_v2 = $storage->get_schema( $tid );
        $this->assertIsArray( $schema_v2 );
        $this->assertSame( 2, $schema_v2['version'] );
        $this->assertCount( 2, $schema_v2['fields'], 'Se esperaban dos campos en la plantilla de prueba.' );
        $fields = array_column( $schema_v2['fields'], null, 'slug' );
        $this->assertArrayHasKey( 'campo_1', $fields );
		// Campo_1 sin parámetros específicos debe inferirse como 'text'.
		$this->assertSame( 'text', $fields['campo_1']['type'] );
        $this->assertArrayHasKey( 'campo2', $fields );
        $this->assertSame( 'text', $fields['campo2']['type'] );

        $ui_schema = Resolate_Documents::get_term_schema( $tid );
        $this->assertIsArray( $ui_schema );
        $this->assertCount( 2, $ui_schema );
        $this->assertSame( 'campo_1', $ui_schema[0]['slug'] );
        $this->assertSame( 'campo2', $ui_schema[1]['slug'] );

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
        // Asegura que hooks del CPT estén registrados (capabilities/meta).
        do_action( 'init' );
        // Asegura capacidades para salvar meta boxes.
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );
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
        $storage = new SchemaStorage();
        $schema_v2 = array(
            'version'   => 2,
            'fields'    => array(
                array(
                    'name'        => 'Campo 1',
                    'slug'        => 'campo1',
                    'type'        => 'text',
                    'title'       => 'Campo 1',
                    'placeholder' => '',
                    'description' => '',
                    'pattern'     => '',
                    'patternmsg'  => '',
                    'minvalue'    => '',
                    'maxvalue'    => '',
                    'length'      => '',
                    'parameters'  => array(),
                ),
            ),
            'repeaters' => array(),
            'meta'      => array(
                'template_type' => 'odt',
                'template_name' => 'prueba.odt',
                'hash'          => md5( 'campo1' ),
                'parsed_at'     => current_time( 'mysql' ),
            ),
        );
        $storage->save_schema( $tid, $schema_v2 );

        $post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc', 'post_status' => 'draft' ) );
        wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

        $doc = new Resolate_Documents();
        // Asegura que hooks del CPT estén registrados (capabilities/meta).
        do_action( 'init' );
        // Asegura capacidades para salvar meta boxes.
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );
        $_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
        $_POST['resolate_field_campo1']   = 'Valor X';
        $doc->save_meta_boxes( $post_id );

        $this->assertEquals( 'Valor X', get_post_meta( $post_id, 'resolate_field_campo1', true ) );

        // Create a revision and copy meta to it.
        $rev_id = wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $post_id, 'post_title' => 'Rev' ) );
        // Ejecuta la copia de metadatos de forma directa para evitar diferencias de hooks.
        $doc->copy_meta_to_revision( $post_id, $rev_id );
        $this->assertEquals( 'Valor X', get_metadata( 'post', $rev_id, 'resolate_field_campo1', true ) );
    }
}
