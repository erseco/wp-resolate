<?php
/**
 * Class ResolateKnowledgeBaseIntegrationTest
 *
 * @package Resolate
 */

class ResolateKnowledgeBaseIntegrationTest extends Resolate_Test_Base {

	public function test_cpt_registration() {
		$post_type = get_post_type_object( 'resolate_kb' );
		$this->assertTrue( post_type_exists( 'resolate_kb' ) );
		$this->assertEquals( 'Knowledge Base', $post_type->label );
	}

	public function test_taxonomy_connection() {
		$taxonomy = get_taxonomy( 'resolate_label' );
		$this->assertContains( 'resolate_kb', $taxonomy->object_type );
	}

	public function test_editor_support() {
		$post_type = get_post_type_object( 'resolate_kb' );
		$this->assertTrue( post_type_supports( 'resolate_kb', 'editor' ) );
		$this->assertTrue( $post_type->show_in_rest );
	}
}
