<?php
/**
 * Tests for <hr /> normalization.
 */

class ResolateHrSanitizeTest extends WP_UnitTestCase {

	public function set_up() : void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	public function test_hr_variants_normalized() {
		$term = wp_insert_term( 'Tipo HR', 'resolate_doc_type' );
		$tid  = intval( $term['term_id'] );
		$schema = array(
			array( 'slug' => 'campo', 'label' => 'Campo', 'type' => 'rich' ),
		);
		update_term_meta( $tid, 'schema', $schema );
		update_term_meta( $tid, 'resolate_type_fields', $schema );

		$post_id = wp_insert_post( array( 'post_type' => 'resolate_document', 'post_title' => 'Doc HR', 'post_status' => 'draft' ) );
		wp_set_post_terms( $post_id, array( $tid ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_field_campo']    = 'A<hr>B<hr/>C<hr />D';
		$doc->save_meta_boxes( $post_id );

		$stored = get_post_meta( $post_id, 'resolate_field_campo', true );
		$this->assertSame( 'A<hr />B<hr />C<hr />D', $stored );
	}
}

