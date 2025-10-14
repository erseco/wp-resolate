<?php
/**
 * Tests for Resolate_Template_Parser schema building.
 */

class ResolateTemplateParserTest extends WP_UnitTestCase {

	/**
	 * It should detect array fields and build item schema entries.
	 */
	public function test_build_schema_from_field_definitions_detects_arrays() {
		$fields = array(
			array(
				'placeholder' => 'annexes[*].number',
				'slug'        => 'annexes_number',
				'label'       => 'Annex Number',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'annexes[*].title',
				'slug'        => 'annexes_title',
				'label'       => 'Annex Title',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'annexes[*].content',
				'slug'        => 'annexes_content',
				'label'       => 'Annex Content',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'onshow',
				'slug'        => 'onshow',
				'label'       => 'On Show',
				'parameters'  => array( 'repeat' => 'annexes' ),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'resolution_title',
				'slug'        => 'resolution_title',
				'label'       => 'Resolution Title',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
		);

		$schema = Resolate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertNotEmpty( $schema, 'La definición de esquema no debe estar vacía.' );

		$array_field = null;
		foreach ( $schema as $entry ) {
			if ( isset( $entry['slug'] ) && 'annexes' === $entry['slug'] ) {
				$array_field = $entry;
				break;
			}
		}

		$this->assertIsArray( $array_field, 'Se debe detectar el campo de anexos.' );
		$this->assertSame( 'array', $array_field['type'] );
		$this->assertSame( 'array', $array_field['data_type'] );
		$this->assertArrayHasKey( 'item_schema', $array_field );
		$this->assertArrayHasKey( 'number', $array_field['item_schema'] );
		$this->assertArrayHasKey( 'title', $array_field['item_schema'] );
		$this->assertArrayHasKey( 'content', $array_field['item_schema'] );
		$this->assertSame( 'single', $array_field['item_schema']['number']['type'] );
		$this->assertSame( 'rich', $array_field['item_schema']['content']['type'] );

		$scalar_field = null;
		foreach ( $schema as $entry ) {
			if ( isset( $entry['slug'] ) && 'resolution_title' === $entry['slug'] ) {
				$scalar_field = $entry;
				break;
			}
		}

		$this->assertIsArray( $scalar_field );
		$this->assertSame( 'textarea', $scalar_field['type'] );
		$this->assertSame( 'text', $scalar_field['data_type'] );
	}
}
