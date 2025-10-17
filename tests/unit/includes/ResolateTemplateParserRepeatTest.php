<?php
/**
 * Tests for detecting array items inside onshow repeat blocks during schema parse.
 */

class ResolateTemplateParserRepeatTest extends WP_UnitTestCase {

	public function test_fields_inside_repeat_are_mapped_to_array_items() {
		$fields = array(
			array(
				'placeholder' => 'onshow',
				'slug'        => 'onshow',
				'label'       => 'onshow',
				'parameters'  => array( 'block' => 'begin', 'repeat' => 'annexes' ),
				'index'       => 0,
				'raw'         => 'onshow;block=begin;repeat=annexes',
				'raw_field'   => 'onshow;block=begin;repeat=annexes',
				'raw_params'  => 'block=begin;repeat=annexes',
				'raw_placeholder' => 'onshow',
			),
			array( 'placeholder' => 'number',  'slug' => 'number',  'label' => '', 'parameters' => array(), 'index' => 1 ),
			array( 'placeholder' => 'title',   'slug' => 'title',   'label' => '', 'parameters' => array(), 'index' => 2 ),
			array( 'placeholder' => 'content', 'slug' => 'content', 'label' => '', 'parameters' => array(), 'index' => 3 ),
			array(
				'placeholder' => 'onshow',
				'slug'        => 'onshow',
				'label'       => 'onshow',
				'parameters'  => array( 'block' => 'end' ),
				'index'       => 4,
				'raw'         => 'onshow;block=end',
				'raw_field'   => 'onshow;block=end',
				'raw_params'  => 'block=end',
				'raw_placeholder' => 'onshow',
			),
		);

		$schema = Resolate_Template_Parser::build_schema_from_field_definitions( $fields );
		$this->assertIsArray( $schema );
		$this->assertNotEmpty( $schema );
		// Expect an array field 'annexes' with item_schema keys.
		$annex = null;
		foreach ( $schema as $row ) {
			if ( isset( $row['slug'] ) && 'annexes' === $row['slug'] ) {
				$annex = $row;
				break;
			}
		}
		$this->assertNotNull( $annex, 'Debe existir el campo de array annexes.' );
		$this->assertSame( 'array', $annex['type'] );
		$this->assertArrayHasKey( 'item_schema', $annex );
		$this->assertArrayHasKey( 'number', $annex['item_schema'] );
		$this->assertArrayHasKey( 'title', $annex['item_schema'] );
		$this->assertArrayHasKey( 'content', $annex['item_schema'] );
	}
}

