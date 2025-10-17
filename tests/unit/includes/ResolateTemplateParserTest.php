<?php
/**
 * Tests for Resolate_Template_Parser schema building.
 */

class ResolateTemplateParserTest extends WP_UnitTestCase {

/**
 * It should extract metadata for dynamic fields including validation parameters.
 */
public function test_build_schema_from_field_definitions_extracts_metadata() {
$fields = array(
array(
'placeholder' => 'nombre completo',
'parameters'  => array(
'type'        => 'text',
'title'       => 'Nombre completo',
'placeholder' => 'Escribe tu nombre',
'description' => 'Nombre tal y como figura en el DNI',
'length'      => '120',
'pattern'     => '^[A-ZÁÉÍÓÚÑ ]+$',
'patternmsg'  => 'Usa letras mayúsculas.',
'extra'       => 'valor',
),
),
array(
'placeholder' => 'importe total',
'parameters'  => array(
'type'      => 'number',
'minvalue'  => '0',
'maxvalue'  => '9999.99',
),
),
array(
'placeholder' => 'importe total',
'parameters'  => array(
'type' => 'number',
),
),
);

$schema = Resolate_Template_Parser::build_schema_from_field_definitions( $fields );

$this->assertCount( 3, $schema, 'Debe detectar los tres campos, incluyendo duplicados.' );

$names = wp_list_pluck( $schema, 'name' );
$this->assertContains( 'nombre completo', $names );
$this->assertContains( 'importe total', $names );
$this->assertContains( 'importe total__2', $names, 'Los duplicados deben recibir un sufijo.' );

$first = $this->find_field_by_name( $schema, 'nombre completo' );
$this->assertSame( 'nombre completo', $first['merge_key'] );
$this->assertSame( 'Nombre completo', $first['title'] );
$this->assertSame( 'text', $first['type'] );
$this->assertSame( 'nombre completo', $first['placeholder'] );
$this->assertSame( 'Escribe tu nombre', $first['input_placeholder'] );
$this->assertSame( 'Nombre tal y como figura en el DNI', $first['description'] );
$this->assertSame( '^[A-ZÁÉÍÓÚÑ ]+$', $first['pattern'] );
$this->assertSame( 'Usa letras mayúsculas.', $first['patternmsg'] );
$this->assertSame( 120, $first['length'] );
$this->assertArrayHasKey( 'parameters', $first );
$this->assertSame( 'valor', $first['parameters']['extra'] );

$number = $this->find_field_by_name( $schema, 'importe total' );
$this->assertSame( 'number', $number['type'] );
$this->assertSame( '0', $number['minvalue'] );
$this->assertSame( '9999.99', $number['maxvalue'] );

$duplicate = $this->find_field_by_name( $schema, 'importe total__2' );
$this->assertTrue( $duplicate['duplicate'], 'Los duplicados deben marcarse.' );
$this->assertSame( 'importe total', $duplicate['merge_key'] );
}

/**
 * Helper to locate a field by name.
 *
 * @param array  $schema Schema array.
 * @param string $name   Field name.
 * @return array
 */
private function find_field_by_name( $schema, $name ) {
foreach ( $schema as $field ) {
if ( isset( $field['name'] ) && $name === $field['name'] ) {
return $field;
}
}
return array();
}
}
