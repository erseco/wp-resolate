<?php
/**
 * Tests for SchemaConverter class.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaConverter;

/**
 * Test class for SchemaConverter.
 */
class SchemaConverterTest extends WP_UnitTestCase {

	/**
	 * Helper to find field by slug in legacy array.
	 *
	 * @param array  $legacy Legacy schema array.
	 * @param string $slug   Field slug to find.
	 * @return array|null
	 */
	private function find_field_by_slug( $legacy, $slug ) {
		foreach ( $legacy as $field ) {
			if ( isset( $field['slug'] ) && $field['slug'] === $slug ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Test to_legacy converts v2 schema to legacy format.
	 */
	public function test_to_legacy_basic() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array(
					'slug'        => 'title',
					'type'        => 'text',
					'title'       => 'Title Field',
					'placeholder' => 'Enter title',
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$field = $this->find_field_by_slug( $result, 'title' );
		$this->assertNotNull( $field );
		$this->assertSame( 'Title Field', $field['label'] );
	}

	/**
	 * Test to_legacy handles empty schema.
	 */
	public function test_to_legacy_empty() {
		$result = SchemaConverter::to_legacy( array() );
		$this->assertSame( array(), $result );

		$result = SchemaConverter::to_legacy( array( 'version' => 2 ) );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test to_legacy works without version (processes fields anyway).
	 */
	public function test_to_legacy_without_version() {
		$v2_schema = array(
			'fields' => array(
				array( 'slug' => 'test', 'type' => 'text' ),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test data type mapping via reflection.
	 *
	 * @dataProvider data_type_provider
	 *
	 * @param string $input    Input type.
	 * @param string $expected Expected mapped type.
	 */
	public function test_map_data_type( $input, $expected ) {
		$method = new ReflectionMethod( SchemaConverter::class, 'map_data_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $input );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for data type mapping.
	 *
	 * @return array Test cases.
	 */
	public function data_type_provider() {
		return array(
			'number'          => array( 'number', 'number' ),
			'date'            => array( 'date', 'date' ),
			'boolean'         => array( 'boolean', 'boolean' ),
			'email'           => array( 'email', 'text' ),
			'url'             => array( 'url', 'text' ),
			'text'            => array( 'text', 'text' ),
			'html'            => array( 'html', 'text' ),
			'textarea'        => array( 'textarea', 'text' ),
			'unknown'         => array( 'unknown', 'text' ),
			'empty'           => array( '', 'text' ),
			'case_insensitive' => array( 'NUMBER', 'number' ),
		);
	}

	/**
	 * Test to_legacy with number field.
	 */
	public function test_to_legacy_number_field() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array(
					'slug' => 'quantity',
					'type' => 'number',
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );
		$field  = $this->find_field_by_slug( $result, 'quantity' );

		$this->assertNotNull( $field );
		$this->assertSame( 'number', $field['data_type'] );
	}

	/**
	 * Test to_legacy with date field.
	 */
	public function test_to_legacy_date_field() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array(
					'slug' => 'birthdate',
					'type' => 'date',
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );
		$field  = $this->find_field_by_slug( $result, 'birthdate' );

		$this->assertNotNull( $field );
		$this->assertSame( 'date', $field['data_type'] );
	}

	/**
	 * Test to_legacy with boolean field.
	 */
	public function test_to_legacy_boolean_field() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array(
					'slug' => 'active',
					'type' => 'boolean',
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );
		$field  = $this->find_field_by_slug( $result, 'active' );

		$this->assertNotNull( $field );
		$this->assertSame( 'boolean', $field['data_type'] );
	}

	/**
	 * Test to_legacy with array field uses repeaters section.
	 */
	public function test_to_legacy_array_field() {
		$v2_schema = array(
			'version'   => 2,
			'fields'    => array(),
			'repeaters' => array(
				array(
					'slug'        => 'items',
					'item_schema' => array(
						array(
							'slug' => 'name',
							'type' => 'text',
						),
						array(
							'slug' => 'price',
							'type' => 'number',
						),
					),
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );
		$field  = $this->find_field_by_slug( $result, 'items' );

		$this->assertNotNull( $field );
		$this->assertSame( 'array', $field['type'] );
		$this->assertArrayHasKey( 'item_schema', $field );
	}

	/**
	 * A placeholder name with no title of its own is humanized, not shown raw.
	 *
	 * The block placeholders of the propuesta de gasto are called "servicios",
	 * "suministros" and "expertos"; without this the editor headed them with
	 * the raw lowercase slug next to properly titled fields.
	 */
	public function test_to_legacy_humanizes_a_one_word_placeholder_name() {
		$v2_schema = array(
			'version' => 2,
			'fields' => array(
				array(
					'slug' => 'objeto',
					'name' => 'objeto',
					'type' => 'text',
				),
			),
			'repeaters' => array(
				array(
					'slug' => 'servicios',
					'name' => 'servicios',
					'fields' => array(
						array( 'slug' => 'proveedor', 'type' => 'text' ),
					),
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );

		$this->assertSame( 'Objeto', $this->find_field_by_slug( $result, 'objeto' )['label'] );
		$this->assertSame( 'Servicios', $this->find_field_by_slug( $result, 'servicios' )['label'] );
	}

	/**
	 * A title written by a person is used exactly as written.
	 */
	public function test_to_legacy_keeps_a_written_title_verbatim() {
		$v2_schema = array(
			'version' => 2,
			'fields' => array(
				array(
					'slug' => 'gasto_letra',
					'name' => 'gasto_letra',
					'title' => 'Gasto total (en letra)',
					'type' => 'text',
				),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );

		$this->assertSame(
			'Gasto total (en letra)',
			$this->find_field_by_slug( $result, 'gasto_letra' )['label']
		);
	}

	/**
	 * Test to_legacy with multiple fields.
	 */
	public function test_to_legacy_multiple_fields() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array( 'slug' => 'field1', 'type' => 'text' ),
				array( 'slug' => 'field2', 'type' => 'number' ),
				array( 'slug' => 'field3', 'type' => 'date' ),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );

		$this->assertCount( 3, $result );
		$this->assertNotNull( $this->find_field_by_slug( $result, 'field1' ) );
		$this->assertNotNull( $this->find_field_by_slug( $result, 'field2' ) );
		$this->assertNotNull( $this->find_field_by_slug( $result, 'field3' ) );
	}

	/**
	 * Test to_legacy skips fields without slug.
	 */
	public function test_to_legacy_skips_without_slug() {
		$v2_schema = array(
			'version' => 2,
			'fields'  => array(
				array( 'type' => 'text' ), // No slug.
				array( 'slug' => '', 'type' => 'text' ), // Empty slug.
				array( 'slug' => 'valid', 'type' => 'text' ),
			),
		);

		$result = SchemaConverter::to_legacy( $v2_schema );

		$this->assertCount( 1, $result );
		$this->assertNotNull( $this->find_field_by_slug( $result, 'valid' ) );
	}

	/**
	 * The rol of fields, blocks and block items reaches the legacy rows (default area).
	 */
	public function test_to_legacy_passes_rol_through() {
		$schema = array(
			'version' => 2,
			'fields' => array(
				array( 'slug' => 'objeto', 'type' => 'textarea' ),
				array( 'slug' => 'numero', 'type' => 'text', 'rol' => 'gestion' ),
				array( 'slug' => 'raro', 'type' => 'text', 'rol' => 'otro' ),
			),
			'repeaters' => array(
				array(
					'slug' => 'servicios',
					'name' => 'servicios',
					'rol' => 'gestion',
					'fields' => array(
						array( 'slug' => 'proveedor', 'type' => 'text', 'rol' => 'gestion' ),
						array(
							'slug' => 'conceptos',
							'type' => 'array',
							'rol' => 'gestion',
							'fields' => array(
								array( 'slug' => 'total', 'type' => 'number', 'rol' => 'gestion' ),
							),
						),
					),
				),
				array(
					'slug' => 'anexos',
					'name' => 'anexos',
					'fields' => array(
						array( 'slug' => 'code', 'type' => 'text' ),
					),
				),
			),
		);

		$rows = array();
		foreach ( SchemaConverter::to_legacy( $schema ) as $row ) {
			$rows[ $row['slug'] ] = $row;
		}

		$this->assertSame( 'area', $rows['objeto']['rol'] );
		$this->assertSame( 'gestion', $rows['numero']['rol'] );
		$this->assertSame( 'area', $rows['raro']['rol'], 'Unknown values fall back to area.' );

		$this->assertSame( 'gestion', $rows['servicios']['rol'] );
		$this->assertSame( 'gestion', $rows['servicios']['item_schema']['proveedor']['rol'] );
		$this->assertSame( 'gestion', $rows['servicios']['item_schema']['conceptos']['rol'] );
		$this->assertSame( 'gestion', $rows['servicios']['item_schema']['conceptos']['item_schema']['total']['rol'] );

		$this->assertSame( 'area', $rows['anexos']['rol'] );
		$this->assertSame( 'area', $rows['anexos']['item_schema']['code']['rol'] );
	}

	/**
	 * The rol is read the same way everywhere: alias, case and spacing included.
	 *
	 * A schema written by hand, by an older version or by a test does not go
	 * through the extractor's normalisation, so the converter must not be
	 * stricter than Documentate_Campos_Rol::rol_del_campo().
	 */
	public function test_to_legacy_normalises_the_rol_like_the_single_normaliser() {
		$schema = array(
			'version' => 2,
			'fields' => array(
				array( 'slug' => 'mayusculas', 'type' => 'text', 'rol' => ' GESTIÓN ' ),
				array( 'slug' => 'alias', 'type' => 'text', 'role' => 'gestion' ),
			),
		);

		$rows = array();
		foreach ( SchemaConverter::to_legacy( $schema ) as $row ) {
			$rows[ $row['slug'] ] = $row;
		}

		$this->assertSame( 'gestion', $rows['mayusculas']['rol'] );
		$this->assertSame( 'gestion', $rows['alias']['rol'] );
	}

	/**
	 * The entries of a block inherit its rol when they declare none.
	 */
	public function test_to_legacy_inherits_the_block_rol_in_the_item_schema() {
		$schema = array(
			'version' => 2,
			'repeaters' => array(
				array(
					'slug' => 'servicios',
					'name' => 'servicios',
					'rol' => 'gestion',
					'fields' => array(
						array( 'slug' => 'proveedor', 'type' => 'text' ),
						array( 'slug' => 'nota', 'type' => 'text', 'rol' => 'area' ),
						array(
							'slug' => 'conceptos',
							'type' => 'array',
							'fields' => array(
								array( 'slug' => 'total', 'type' => 'number' ),
							),
						),
					),
				),
			),
		);

		$rows = array();
		foreach ( SchemaConverter::to_legacy( $schema ) as $row ) {
			$rows[ $row['slug'] ] = $row;
		}
		$items = $rows['servicios']['item_schema'];

		$this->assertSame( 'gestion', $items['proveedor']['rol'], 'Inherited from the block.' );
		$this->assertSame( 'area', $items['nota']['rol'], 'Its own rol wins.' );
		$this->assertSame( 'gestion', $items['conceptos']['rol'] );
		$this->assertSame( 'gestion', $items['conceptos']['item_schema']['total']['rol'], 'Inherited down the nesting.' );
	}
}
