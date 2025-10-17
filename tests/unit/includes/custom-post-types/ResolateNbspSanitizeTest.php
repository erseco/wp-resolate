<?php
/**
 * Tests for &nbsp; normalization in rich text fields.
 */

class ResolateNbspSanitizeTest extends WP_UnitTestCase {

	public function set_up() : void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	public function test_rich_field_nbsp_is_replaced_on_save() {
		$term = wp_insert_term( 'Tipo NBSP', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc NBSP', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = 'Hola&nbsp;mundo';
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_campo', true );
		$this->assertStringNotContainsString( '&nbsp;', $stored );
		$this->assertSame( 'Hola mundo', wp_strip_all_tags( $stored ) );
	}

	public function test_unknown_field_nbsp_is_replaced_on_save() {
		$term = wp_insert_term( 'Tipo NBSP 2', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'x', 'label' => 'X', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc NBSP 2', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_otro']     = 'Uno&nbsp;&nbsp;Dos';
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_otro', true );
		$this->assertStringNotContainsString( '&nbsp;', $stored );
		$this->assertSame( 'Uno  Dos', wp_strip_all_tags( $stored ) );
	}
}

