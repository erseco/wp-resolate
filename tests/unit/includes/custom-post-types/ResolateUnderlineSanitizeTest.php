<?php
/**
 * Tests for underline normalization in rich text fields.
 */

class ResolateUnderlineSanitizeTest extends WP_UnitTestCase {

	public function set_up() : void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	public function test_rich_field_span_underline_is_converted_to_u() {
		$term = wp_insert_term( 'Tipo Rich', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = 'Hola <span style="text-decoration: underline;">mundo</span>.';
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_campo', true );
		$this->assertStringContainsString( '<u>', $stored );
		$this->assertStringContainsString( '</u>', $stored );
		$this->assertStringNotContainsString( 'text-decoration: underline', $stored );
	}

	public function test_unknown_dynamic_field_span_underline_is_converted_to_u() {
		$term = wp_insert_term( 'Tipo Simple', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'solo', 'label' => 'Solo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc 2', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		// Campo no definido en el esquema (desconocido).
		$_POST['resolate_field_otro']     = 'Texto <span style="text-decoration: underline;">subrayado</span>.';
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_otro', true );
		$this->assertStringContainsString( '<u>', $stored );
		$this->assertStringContainsString( '</u>', $stored );
		$this->assertStringNotContainsString( 'text-decoration: underline', $stored );
	}
}

