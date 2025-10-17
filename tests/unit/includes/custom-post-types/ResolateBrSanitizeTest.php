<?php
/**
 * Tests for <br /> normalization and newline conversion.
 */

class ResolateBrSanitizeTest extends WP_UnitTestCase {

	public function set_up() : void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	public function test_newlines_convert_to_br() {
		$term = wp_insert_term( 'Tipo BR', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc BR', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = "L1\nL2";
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_campo', true );
		$this->assertStringContainsString( 'L1<br />', $stored );
		$this->assertStringContainsString( 'L2', $stored );
	}

    public function test_br_variants_normalized() {
		$term = wp_insert_term( 'Tipo BR2', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc BR2', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = 'A<br>B<br/>C<br />D';
		$doc->save_meta_boxes( $post_id );

        $stored = get_post_meta( $post_id, 'resolate_field_campo', true );
        $this->assertSame( 'A<br />B<br />C<br />D', $stored );
    }

	public function test_newlines_not_injected_around_ul() {
		$term = wp_insert_term( 'Tipo BR3', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc BR3', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = "Lista:\n<ul>\n<li>Una</li>\n<li>Dos</li>\n</ul>";
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_campo', true );
		$this->assertStringNotContainsString( '<ul><br />', $stored );
		$this->assertStringNotContainsString( '<br /><ul>', $stored );
	}
}
