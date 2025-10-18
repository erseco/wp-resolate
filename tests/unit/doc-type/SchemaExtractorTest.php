<?php
/**
 * Tests for the schema extractor working with bundled fixtures.
 */

use Resolate\DocType\SchemaConverter;
use Resolate\DocType\SchemaExtractor;

class SchemaExtractorTest extends WP_UnitTestCase {

	/**
	 * Ensure the demo ODT fixture is parsed with all expected fields and metadata.
	 */
	public function test_demo_fixture_schema_parsed_correctly() {
        $extractor = new SchemaExtractor();
        $schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-resolate.odt' );

		$this->assertNotWPError( $schema, 'Se esperaba un esquema válido al analizar la plantilla demo ODT.' );
		$this->assertIsArray( $schema );
		$this->assertSame( 2, $schema['version'], 'La versión del esquema debe ser 2.' );
		$this->assertSame( 'odt', $schema['meta']['template_type'], 'El tipo de plantilla detectado debe ser odt.' );

		$fields = $this->index_fields( $schema['fields'] );

		$this->assertArrayHasKey( 'nombrecompleto', $fields, 'El campo Nombre completo debe existir.' );
		$this->assertSame( 'text', $fields['nombrecompleto']['type'] );
		$this->assertSame( 'Tu nombre y apellidos', $fields['nombrecompleto']['placeholder'] );
		$this->assertSame( '120', $fields['nombrecompleto']['length'] );

		$this->assertArrayHasKey( 'email', $fields, 'El campo Email debe existir.' );
		$this->assertSame( 'email', $fields['email']['type'] );
            $this->assertSame(
                '^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{{2,}}$',
                $fields['email']['pattern'],
                'El campo Email debe conservar el patrón completo.'
            );
		$this->assertSame(
			'Introduce un email válido (usuario@dominio.tld)',
			$fields['email']['patternmsg']
		);

		$this->assertArrayHasKey( 'telfono', $fields, 'El campo Teléfono debe existir.' );
		$this->assertSame( '^\\+?\\d[\\d\\s\\-]{{7,}}$', $fields['telfono']['pattern'] );
		$this->assertSame( 'Formato de teléfono no válido', $fields['telfono']['patternmsg'] );

		$this->assertArrayHasKey( 'unidades', $fields, 'El campo Unidades debe existir.' );
		$this->assertSame( 'number', $fields['unidades']['type'] );
		$this->assertSame( '0', $fields['unidades']['minvalue'] );
		$this->assertSame( '20', $fields['unidades']['maxvalue'] );

		$this->assertArrayHasKey( 'observaciones', $fields, 'El campo Observaciones debe existir.' );
		$this->assertSame( 'textarea', $fields['observaciones']['type'] );

		$this->assertArrayHasKey( 'sitioweb', $fields, 'El campo Sitio web debe existir.' );
		$this->assertSame( 'url', $fields['sitioweb']['type'] );
		$this->assertSame( '^https?://.+$', $fields['sitioweb']['pattern'] );

		$this->assertArrayHasKey( 'fechalmite', $fields, 'El campo Fecha límite debe existir.' );
		$this->assertSame( 'date', $fields['fechalmite']['type'] );
		$this->assertSame( '2025-01-01', $fields['fechalmite']['minvalue'] );
		$this->assertSame( '2030-12-31', $fields['fechalmite']['maxvalue'] );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'items', $repeaters, 'El bloque repetible items debe existir.' );
		$this->assertArrayHasKey( 'ttulotem', $repeaters['items'], 'El campo de título del ítem debe existir.' );
		$this->assertSame( 'text', $repeaters['items']['ttulotem']['type'] );
		$this->assertArrayHasKey( 'contenidotemhtml', $repeaters['items'], 'El campo HTML del ítem debe existir.' );
		$this->assertSame( 'html', $repeaters['items']['contenidotemhtml']['type'] );

		$legacy = SchemaConverter::to_legacy( $schema );
		$this->assertIsArray( $legacy, 'La conversión a legado debe crear una matriz.' );
		$this->assertNotEmpty( $legacy );
		$legacy_items = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'items' === $entry['slug'] ) {
				$legacy_items = $entry;
				break;
			}
		}
		$this->assertNotNull( $legacy_items, 'El bloque repetible debe mantenerse en la conversión legado.' );
		$this->assertArrayHasKey( 'item_schema', $legacy_items );
		$this->assertArrayHasKey( 'contenidotemhtml', $legacy_items['item_schema'] );
		$this->assertSame( 'rich', $legacy_items['item_schema']['contenidotemhtml']['type'] );
	}

	/**
	 * Index fields by slug.
	 *
	 * @param array $fields Schema fields.
	 * @return array<string,array>
	 */
	private function index_fields( $fields ) {
		$indexed = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['slug'] ) ) {
				$indexed[ $field['slug'] ] = $field;
			}
		}
		return $indexed;
	}

	/**
	 * Index repeater item schemas by slug.
	 *
	 * @param array $repeaters Schema repeaters.
	 * @return array<string,array<string,array>>
	 */
	private function index_repeaters( $repeaters ) {
		$indexed = array();
		foreach ( $repeaters as $repeater ) {
			if ( ! is_array( $repeater ) || empty( $repeater['slug'] ) || empty( $repeater['fields'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $repeater['fields'] as $field ) {
				if ( is_array( $field ) && isset( $field['slug'] ) ) {
					$items[ $field['slug'] ] = $field;
				}
			}
			$indexed[ $repeater['slug'] ] = $items;
		}
		return $indexed;
	}
}
