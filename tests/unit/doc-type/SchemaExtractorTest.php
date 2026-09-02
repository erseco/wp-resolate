<?php
/**
 * Tests for the schema extractor working with bundled fixtures.
 */

use Documentate\DocType\SchemaConverter;
use Documentate\DocType\SchemaExtractor;

class SchemaExtractorTest extends WP_UnitTestCase {

	/**
	 * Ensure the demo ODT fixture is parsed with all expected fields and metadata.
	 */
	public function test_demo_fixture_schema_parsed_correctly() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-documentate.odt' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the demo ODT template.' );
		$this->assertIsArray( $schema );
		$this->assertSame( 2, $schema['version'], 'Schema version must be 2.' );
		$this->assertSame( 'odt', $schema['meta']['template_type'], 'Detected template type must be odt.' );

		$fields = $this->index_fields( $schema['fields'] );

		$this->assertArrayHasKey( 'nombrecompleto', $fields, 'Full name field must exist.' );
		$this->assertSame( 'text', $fields['nombrecompleto']['type'] );
		$this->assertSame( 'Tu nombre y apellidos', $fields['nombrecompleto']['placeholder'] );
		$this->assertSame( '120', $fields['nombrecompleto']['length'] );

		$this->assertArrayHasKey( 'email', $fields, 'Email field must exist.' );
		$this->assertSame( 'email', $fields['email']['type'] );
		$this->assertSame(
			'Enter a valid email (user@domain.tld)',
			$fields['email']['patternmsg']
		);

		$this->assertArrayHasKey( 'telfono', $fields, 'Phone field must exist.' );
		$this->assertSame( '^[+]?[1-9][0-9]{1,14}$', $fields['telfono']['pattern'] );
		$this->assertSame( 'Formato de teléfono no válido', $fields['telfono']['patternmsg'] );

		$this->assertArrayHasKey( 'unidades', $fields, 'Units field must exist.' );
		$this->assertSame( 'number', $fields['unidades']['type'] );
		$this->assertSame( '0', $fields['unidades']['minvalue'] );
		$this->assertSame( '20', $fields['unidades']['maxvalue'] );

		$this->assertArrayHasKey( 'observaciones', $fields, 'Observations field must exist.' );
		$this->assertSame( 'textarea', $fields['observaciones']['type'] );

		$this->assertArrayHasKey( 'web', $fields, 'Web field must exist.' );
		$this->assertSame( 'url', $fields['web']['type'] );

		$this->assertArrayHasKey( 'datelimit', $fields, 'Date limit field must exist.' );
		$this->assertSame( 'date', $fields['datelimit']['type'] );
		$this->assertSame( '2025-01-01', $fields['datelimit']['minvalue'] );
		$this->assertSame( '2030-12-31', $fields['datelimit']['maxvalue'] );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'items', $repeaters, 'Repeater block items must exist.' );
		$this->assertArrayHasKey( 'title', $repeaters['items'], 'Item title field must exist.' );
		$this->assertSame( 'text', $repeaters['items']['title']['type'] );
		$this->assertArrayHasKey( 'content', $repeaters['items'], 'Item HTML field must exist.' );
		$this->assertSame( 'html', $repeaters['items']['content']['type'] );

		$legacy = SchemaConverter::to_legacy( $schema );
		$this->assertIsArray( $legacy, 'Legacy conversion must return an array.' );
		$this->assertNotEmpty( $legacy );
		$legacy_items = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'items' === $entry['slug'] ) {
				$legacy_items = $entry;
				break;
			}
		}
		$this->assertNotNull( $legacy_items, 'Repeater block must be preserved in legacy conversion.' );
		$this->assertArrayHasKey( 'item_schema', $legacy_items );
		$this->assertArrayHasKey( 'content', $legacy_items['item_schema'] );
		$this->assertSame( 'rich', $legacy_items['item_schema']['content']['type'] );
	}

	/**
	 * Ensure the demo DOCX fixture is parsed with all expected fields and metadata.
	 */
	public function test_demo_docx_fixture_schema_parsed_correctly() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-documentate.docx' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the demo DOCX template.' );
		$this->assertIsArray( $schema );
		$this->assertSame( 2, $schema['version'], 'Schema version must be 2.' );
		$this->assertSame( 'docx', $schema['meta']['template_type'], 'Detected template type must be docx.' );

		$fields = $this->index_fields( $schema['fields'] );

		$this->assertArrayHasKey( 'nombrecompleto', $fields, 'Full name field must exist.' );
		$this->assertSame( 'text', $fields['nombrecompleto']['type'] );
		$this->assertSame( 'Tu nombre y apellidos', $fields['nombrecompleto']['placeholder'] );
		$this->assertSame( '120', $fields['nombrecompleto']['length'] );

		$this->assertArrayHasKey( 'email', $fields, 'Email field must exist.' );
		$this->assertSame( 'email', $fields['email']['type'] );
		$this->assertSame(
			'Enter a valid email (user@domain.tld)',
			$fields['email']['patternmsg']
		);

		$this->assertArrayHasKey( 'telfono', $fields, 'Phone field must exist.' );
		$this->assertSame( '^[+]?[1-9][0-9]{1,14}$', $fields['telfono']['pattern'] );
		$this->assertSame( 'Formato de teléfono no válido', $fields['telfono']['patternmsg'] );

		$this->assertArrayHasKey( 'unidades', $fields, 'Units field must exist.' );
		$this->assertSame( 'number', $fields['unidades']['type'] );
		$this->assertSame( '0', $fields['unidades']['minvalue'] );
		$this->assertSame( '20', $fields['unidades']['maxvalue'] );

		$this->assertArrayHasKey( 'observaciones', $fields, 'Observations field must exist.' );
		$this->assertSame( 'textarea', $fields['observaciones']['type'] );

		$this->assertArrayHasKey( 'web', $fields, 'Web field must exist.' );
		$this->assertSame( 'url', $fields['web']['type'] );

		$this->assertArrayHasKey( 'datelimit', $fields, 'Date limit field must exist.' );
		$this->assertSame( 'date', $fields['datelimit']['type'] );
		$this->assertSame( '2025-01-01', $fields['datelimit']['minvalue'] );
		$this->assertSame( '2030-12-31', $fields['datelimit']['maxvalue'] );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'items', $repeaters, 'Repeater block items must exist.' );
		$this->assertArrayHasKey( 'title', $repeaters['items'], 'Item title field must exist.' );
		$this->assertSame( 'text', $repeaters['items']['title']['type'] );
		$this->assertArrayHasKey( 'content', $repeaters['items'], 'Item HTML field must exist.' );
		$this->assertSame( 'html', $repeaters['items']['content']['type'] );

		$legacy = SchemaConverter::to_legacy( $schema );
		$this->assertIsArray( $legacy, 'Legacy conversion must return an array.' );
		$this->assertNotEmpty( $legacy );
		$legacy_items = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'items' === $entry['slug'] ) {
				$legacy_items = $entry;
				break;
			}
		}
		$this->assertNotNull( $legacy_items, 'Repeater block must be preserved in legacy conversion.' );
		$this->assertArrayHasKey( 'item_schema', $legacy_items );
		$this->assertArrayHasKey( 'content', $legacy_items['item_schema'] );
		$this->assertSame( 'rich', $legacy_items['item_schema']['content']['type'] );
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

	/**
	 * Test that tbs:row repeater fields are extracted with full attributes.
	 */
	public function test_tbs_row_repeater_extracts_field_attributes() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the autorizacionviaje ODT template.' );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'asistentes', $repeaters, 'Repeater asistentes must exist.' );

		// Verify field attributes are extracted.
		$this->assertArrayHasKey( 'apellido1', $repeaters['asistentes'], 'apellido1 field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['apellido1']['type'] );
		$this->assertSame( 'Primer apellido', $repeaters['asistentes']['apellido1']['title'] );

		$this->assertArrayHasKey( 'apellido2', $repeaters['asistentes'], 'apellido2 field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['apellido2']['type'] );
		$this->assertSame( 'Segundo apellido', $repeaters['asistentes']['apellido2']['title'] );

		$this->assertArrayHasKey( 'nombre', $repeaters['asistentes'], 'nombre field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['nombre']['type'] );
		$this->assertSame( 'Nombre', $repeaters['asistentes']['nombre']['title'] );
	}

	/**
	 * Test that tbs:row dotted fields do not appear as root fields.
	 */
	public function test_tbs_row_fields_not_duplicated_as_root() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema );

		$fields = $this->index_fields( $schema['fields'] );

		// Dotted fields (asistentes.X) should NOT appear as root fields.
		$this->assertArrayNotHasKey( 'asistentes.apellido1', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'asistentes.apellido2', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'asistentes.nombre', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'apellido1', $fields, 'Repeater sub-field should not be in root.' );
		$this->assertArrayNotHasKey( 'apellido2', $fields, 'Repeater sub-field should not be in root.' );
	}

	/**
	 * Test that duplicate fields are deduplicated.
	 */
	public function test_duplicate_fields_are_deduplicated() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema );

		// Count occurrences of each slug.
		$slug_counts = array();
		foreach ( $schema['fields'] as $field ) {
			$slug = isset( $field['slug'] ) ? $field['slug'] : '';
			if ( '' !== $slug ) {
				$slug_counts[ $slug ] = isset( $slug_counts[ $slug ] ) ? $slug_counts[ $slug ] + 1 : 1;
			}
		}

		// Each field should appear only once.
		foreach ( $slug_counts as $slug => $count ) {
			$this->assertSame( 1, $count, "Field '$slug' should appear only once, found $count times." );
		}
	}

	/**
	 * Test that tbs:row repeater legacy conversion includes item_schema.
	 */
	public function test_tbs_row_repeater_legacy_has_item_schema() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/gastossuplidos.odt' );

		$this->assertNotWPError( $schema );

		$legacy = SchemaConverter::to_legacy( $schema );

		// Find the 'gastos' repeater in legacy.
		$gastos_entry = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'gastos' === $entry['slug'] ) {
				$gastos_entry = $entry;
				break;
			}
		}

		$this->assertNotNull( $gastos_entry, 'Repeater gastos must exist in legacy.' );
		$this->assertSame( 'array', $gastos_entry['type'] );
		$this->assertArrayHasKey( 'item_schema', $gastos_entry );
		$this->assertArrayHasKey( 'proveedor', $gastos_entry['item_schema'], 'proveedor must be in item_schema.' );
		$this->assertArrayHasKey( 'cif', $gastos_entry['item_schema'], 'cif must be in item_schema.' );
		$this->assertArrayHasKey( 'factura', $gastos_entry['item_schema'], 'factura must be in item_schema.' );
		$this->assertArrayHasKey( 'fecha', $gastos_entry['item_schema'], 'fecha must be in item_schema.' );
		$this->assertArrayHasKey( 'importe', $gastos_entry['item_schema'], 'importe must be in item_schema.' );
	}

	/**
	 * Test that visibility blocks (onshow) are not treated as repeaters.
	 */
	public function test_visibility_blocks_not_treated_as_repeaters() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/propuestagasto.odt' );

		$this->assertNotWPError( $schema );

		// Check that no 'onshow' repeater exists.
		$repeater_names = array_column( $schema['repeaters'], 'name' );
		$this->assertNotContains( 'onshow', $repeater_names, 'onshow should NOT be a repeater (it is a visibility directive).' );
	}

	/**
	 * The propuesta de gasto providers are explicit blocks with a nested
	 * conceptos sub-repeater (TBS automatic sub-blocks, sub1=conceptos).
	 */
	public function test_provider_blocks_nest_their_conceptos_sub_repeater() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/propuestagasto.odt' );

		$this->assertNotWPError( $schema );

		$repeater_names = array_column( $schema['repeaters'], 'name' );

		foreach ( array( 'servicios', 'suministros', 'expertos' ) as $kind ) {
			$this->assertContains( $kind, $repeater_names, sprintf( '%s must be a repeater.', $kind ) );
			// The sub-block must not surface as a top-level repeater.
			$this->assertNotContains( $kind . '_sub1', $repeater_names, sprintf( '%s_sub1 must nest inside %s.', $kind, $kind ) );

			$repeater = null;
			foreach ( $schema['repeaters'] as $candidate ) {
				if ( $kind === $candidate['name'] ) {
					$repeater = $candidate;
					break;
				}
			}
			$this->assertNotNull( $repeater );

			$field_names = array_column( $repeater['fields'], 'name' );
			foreach ( array( 'proveedor', 'cif', 'bruto', 'total', 'conceptos' ) as $expected_field ) {
				$this->assertContains( $expected_field, $field_names, sprintf( '%s must carry the %s field.', $kind, $expected_field ) );
			}
			// The sub-block fields must not leak into the parent as dotted names.
			foreach ( $field_names as $field_name ) {
				$this->assertStringNotContainsString( '_sub1', (string) $field_name, sprintf( '%s must not leak sub-block fields.', $kind ) );
			}

			$conceptos = null;
			foreach ( $repeater['fields'] as $field ) {
				if ( isset( $field['name'] ) && 'conceptos' === $field['name'] ) {
					$conceptos = $field;
					break;
				}
			}
			$this->assertNotNull( $conceptos );
			$this->assertSame( 'array', $conceptos['type'], 'conceptos must be a nested array field.' );
			$sub_field_names = array_column( $conceptos['fields'], 'name' );
			foreach ( array( 'concepto', 'cantidad', 'unitario', 'total' ) as $expected_sub ) {
				$this->assertContains( $expected_sub, $sub_field_names, sprintf( 'conceptos of %s must carry %s.', $kind, $expected_sub ) );
			}
		}
	}

	/**
	 * Every field of the propuesta de gasto carries the rol the template declares.
	 */
	public function test_propuesta_gasto_fields_carry_their_rol() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/propuestagasto.odt' );

		$this->assertNotWPError( $schema );

		$fields = $this->index_fields( $schema['fields'] );
		foreach ( array( 'gasto_letra', 'gasto_numero', 'partida', 'servicios_igic_exento', 'suministros_igic_exento' ) as $slug ) {
			$this->assertSame( 'gestion', $fields[ $slug ]['rol'], sprintf( '%s is completed by gestión.', $slug ) );
		}
		$area = array( 'post_title', 'curso', 'letra_decreto', 'para', 'objeto', 'lineadeactuacion', 'destinatarios', 'alcance_centros', 'alcance_profesorado', 'alcance_alumnado', 'alcance_familias' );
		foreach ( $area as $slug ) {
			$this->assertSame( '', $fields[ $slug ]['rol'], sprintf( '%s is an área field.', $slug ) );
		}
		$this->assertSame( 'Escribe el total en letra.', $fields['gasto_letra']['description'] );

		$this->assertCount( 3, $schema['repeaters'] );
		foreach ( $schema['repeaters'] as $repeater ) {
			$this->assertSame( 'gestion', $repeater['rol'], $repeater['name'] );
			foreach ( $repeater['fields'] as $field ) {
				$this->assertSame( 'gestion', $field['rol'], $repeater['name'] . '.' . $field['name'] );
				if ( 'conceptos' === $field['name'] ) {
					$this->assertNotEmpty( $field['fields'] );
					foreach ( $field['fields'] as $sub_field ) {
						$this->assertSame( 'gestion', $sub_field['rol'], $repeater['name'] . '.conceptos.' . $sub_field['name'] );
					}
				}
			}
		}

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		foreach ( array( 'servicios', 'suministros' ) as $kind ) {
			$this->assertSame( 'Proveedor', $repeaters[ $kind ]['proveedor']['title'], $kind );
			$this->assertSame( 'CIF/NIF', $repeaters[ $kind ]['cif']['title'], $kind );
			$this->assertSame( 'Correo', $repeaters[ $kind ]['email']['title'], $kind );
			$this->assertSame( 'Teléfono', $repeaters[ $kind ]['telefono']['title'], $kind );
		}
		$this->assertSame( 'Proveedor/Experto', $repeaters['expertos']['proveedor']['title'] );
		$this->assertSame( 'CIF/NIF', $repeaters['expertos']['cif']['title'] );
		$this->assertSame( 'Correo', $repeaters['expertos']['email']['title'] );
		$this->assertSame( 'Teléfono', $repeaters['expertos']['telefono']['title'] );
		foreach ( array( 'servicios', 'suministros', 'expertos' ) as $kind ) {
			$conceptos = $this->index_fields( $repeaters[ $kind ]['conceptos']['fields'] );
			$this->assertSame( 'Total', $conceptos['total']['title'], $kind );
		}
	}

	/**
	 * The resolución declares its official data and bodies as gestión fields.
	 */
	public function test_resolucion_official_fields_are_gestion() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/resolucion.odt' );

		$this->assertNotWPError( $schema );
		$this->assertSame(
			array( 'post_title', 'objeto', 'numero_resolucion', 'fecha_resolucion', 'expediente', 'organo_firmante', 'antecedentes', 'fundamentos', 'resuelvo' ),
			array_column( $schema['fields'], 'slug' ),
			'The gestión line sits between the objeto and the antecedentes.'
		);

		$fields = $this->index_fields( $schema['fields'] );
		foreach ( array( 'numero_resolucion', 'fecha_resolucion', 'expediente', 'organo_firmante', 'antecedentes', 'fundamentos', 'resuelvo' ) as $slug ) {
			$this->assertSame( 'gestion', $fields[ $slug ]['rol'], $slug );
		}
		$this->assertSame( '', $fields['post_title']['rol'] );
		$this->assertSame( '', $fields['objeto']['rol'] );

		$this->assertSame( 'text', $fields['numero_resolucion']['type'] );
		$this->assertSame( 'Nº de resolución', $fields['numero_resolucion']['title'] );
		$this->assertSame( '118/2026', $fields['numero_resolucion']['placeholder'] );
		$this->assertSame( 'Se asigna del libro de resoluciones al pasar a administración.', $fields['numero_resolucion']['description'] );
		$this->assertSame( 'date', $fields['fecha_resolucion']['type'] );
		$this->assertSame( 'Fecha de la resolución', $fields['fecha_resolucion']['title'] );
		$this->assertSame( 'text', $fields['expediente']['type'] );
		$this->assertSame( 'select', $fields['organo_firmante']['type'] );
		$this->assertSame( 'Órgano firmante', $fields['organo_firmante']['title'] );
		$this->assertSame(
			'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación|Viceconsejería de Educación|Secretaría General Técnica',
			$fields['organo_firmante']['parameters']['values']
		);

		$this->assertCount( 1, $schema['repeaters'] );
		$this->assertSame( 'anexos', $schema['repeaters'][0]['name'] );
		$this->assertSame( '', $schema['repeaters'][0]['rol'], 'The anexos block stays with the área.' );
		foreach ( $schema['repeaters'][0]['fields'] as $field ) {
			$this->assertSame( '', $field['rol'], 'anexos.' . $field['name'] );
		}
	}

	/**
	 * The rol attribute (alias role) is normalised and a block's rol reaches
	 * its fields in both repeater paths: explicit blocks and tbs:row blocks,
	 * including TBS sub-blocks. A field's own rol wins over the block's.
	 */
	public function test_rol_alias_and_block_inheritance_in_both_repeater_paths() {
		$path = $this->build_odt(
			"[titulo;role='Gestión'] [malo;rol='otro'] [nada;rol] [libre] "
			. "[a.nombre;block=tbs:row;rol='gestion'] [a.importe;type='number'] [a.nota;rol='area'] "
			. "[d.k;block=tbs:row] [d.m] "
			. "[b;block=begin;rol='GESTION'] [b.x] [b.y;rol='area'] [b;block=end] "
			. "[c;block=begin;sub1=lineas;rol='gestion'] [c.z] [c_sub1.q;block=tbs:row] [c_sub1.w;rol='area'] [c;block=end]"
		);
		$schema = ( new SchemaExtractor() )->extract( $path );
		unlink( $path );

		$this->assertNotWPError( $schema );

		$fields = $this->index_fields( $schema['fields'] );
		$this->assertSame( 'gestion', $fields['titulo']['rol'], 'role is an alias and the value is normalised.' );
		$this->assertSame( '', $fields['malo']['rol'], 'Unknown values are dropped.' );
		$this->assertSame( '', $fields['nada']['rol'], 'A bare rol flag is dropped.' );
		$this->assertSame( '', $fields['libre']['rol'] );

		$repeaters = array();
		foreach ( $schema['repeaters'] as $repeater ) {
			$repeaters[ $repeater['slug'] ] = $repeater;
		}
		$this->assertSame( array( 'a', 'd', 'b', 'c' ), array_keys( $repeaters ) );

		$a = $this->index_fields( $repeaters['a']['fields'] );
		$this->assertSame( 'gestion', $repeaters['a']['rol'], 'tbs:row block rol.' );
		$this->assertSame( 'gestion', $a['nombre']['rol'] );
		$this->assertSame( 'gestion', $a['importe']['rol'], 'Inherited from the tbs:row block.' );
		$this->assertSame( 'area', $a['nota']['rol'], 'The field own rol wins.' );

		$d = $this->index_fields( $repeaters['d']['fields'] );
		$this->assertSame( '', $repeaters['d']['rol'] );
		$this->assertSame( '', $d['k']['rol'] );
		$this->assertSame( '', $d['m']['rol'] );

		$b = $this->index_fields( $repeaters['b']['fields'] );
		$this->assertSame( 'gestion', $repeaters['b']['rol'], 'Explicit block rol (normalised).' );
		$this->assertSame( 'gestion', $b['x']['rol'], 'Inherited from the explicit block.' );
		$this->assertSame( 'area', $b['y']['rol'] );

		$c = $this->index_fields( $repeaters['c']['fields'] );
		$this->assertSame( 'gestion', $repeaters['c']['rol'] );
		$this->assertSame( 'gestion', $c['z']['rol'] );
		$this->assertSame( 'array', $c['lineas']['type'] );
		$this->assertSame( 'gestion', $c['lineas']['rol'], 'The sub-block inherits the parent block rol.' );
		$lineas = $this->index_fields( $c['lineas']['fields'] );
		$this->assertSame( 'gestion', $lineas['q']['rol'] );
		$this->assertSame( 'area', $lineas['w']['rol'] );

		$legacy = array();
		foreach ( SchemaConverter::to_legacy( $schema ) as $row ) {
			$legacy[ $row['slug'] ] = $row;
		}
		$this->assertSame( 'gestion', $legacy['titulo']['rol'] );
		$this->assertSame( 'area', $legacy['libre']['rol'] );
		$this->assertSame( 'gestion', $legacy['c']['rol'] );
		$this->assertSame( 'gestion', $legacy['c']['item_schema']['lineas']['rol'] );
		$this->assertSame( 'area', $legacy['c']['item_schema']['lineas']['item_schema']['w']['rol'] );
	}

	/**
	 * Build a minimal ODT holding the given text in a temporary file.
	 *
	 * @param string $text Paragraph text with placeholders.
	 * @return string Path of the file.
	 */
	private function build_odt( $text ) {
		$path = trailingslashit( get_temp_dir() ) . 'documentate-rol-' . uniqid() . '.odt';
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'mimetype', 'application/vnd.oasis.opendocument.text' );
		$zip->addFromString(
			'content.xml',
			'<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>' . $text . '</text:p></office:text></office:body></office:document-content>'
		);
		$zip->close();

		return $path;
	}
}
