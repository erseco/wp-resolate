<?php
/**
 * Tests for array field persistence in Resolate_Documents.
 */

class ResolateDocumentsArrayFieldsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	/**
	 * It should sanitize and encode array fields into structured content JSON.
	 */
	public function test_filter_post_data_compose_content_saves_array_fields_as_json() {
		$term    = wp_insert_term( 'Tipo Anexos', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Resolate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();

		$_POST['resolate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => ' I ',
						'title'   => '  Marco  ',
						'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table><script>alert(1)</script>',
					),
					array(
						'number'  => '',
						'title'   => '',
						'content' => '',
					),
				),
			)
		);

		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		$structured = Resolate_Documents::parse_structured_content( $result['post_content'] );
		$this->assertArrayHasKey( 'annexes', $structured );
		$this->assertSame( 'array', $structured['annexes']['type'] );
		$decoded = json_decode( $structured['annexes']['value'], true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded, 'Solo el elemento con contenido debe persistir.' );
		$this->assertSame( 'I', $decoded[0]['number'] );
		$this->assertSame( 'Marco', $decoded[0]['title'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $decoded[0]['content'] );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should cap stored items to the configured maximum.
	 */
	public function test_filter_post_data_compose_content_limits_array_items() {
		$term    = wp_insert_term( 'Tipo Límite', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Resolate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		$items = array();
		for ( $i = 0; $i < Resolate_Documents::ARRAY_FIELD_MAX_ITEMS + 5; $i++ ) {
			$items[] = array(
				'number'  => 'N' . $i,
				'title'   => 'Título ' . $i,
				'content' => 'Contenido ' . $i,
			);
		}

		$doc = new Resolate_Documents();

		$_POST['resolate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => $items,
			)
		);

		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		$structured = Resolate_Documents::parse_structured_content( $result['post_content'] );
		$decoded    = json_decode( $structured['annexes']['value'], true );
		$this->assertCount( Resolate_Documents::ARRAY_FIELD_MAX_ITEMS, $decoded );
		$last_index = Resolate_Documents::ARRAY_FIELD_MAX_ITEMS - 1;
		$this->assertSame( 'N' . $last_index, $decoded[ $last_index ]['number'] );
		$this->assertSame( 'Título ' . $last_index, $decoded[ $last_index ]['title'] );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should persist repeater rich text fields without introducing spurious newline artifacts.
	 */
	public function test_repeater_rich_text_field_saves_without_corruption() {
		$term    = wp_insert_term( 'Tipo Contenido', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Resolate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		$complex_html = "<p>Intro</p>\r\n"
			. "<table>\r\n<thead><tr><th>Encabezado</th><th>Valor</th></tr></thead>\r\n"
			. "<tbody><tr><td>Fila 1</td><td>Dato 1</td></tr><tr><td>Fila 2</td><td>Dato 2</td></tr></tbody>\r\n"
			. "</table>\r\n<p>Conclusión con <strong>énfasis</strong> y <em>detalle</em>.</p>";

		$doc = new Resolate_Documents();

		$_POST['resolate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Tabla Compleja',
						'content' => $complex_html,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		$structured = Resolate_Documents::parse_structured_content( $result['post_content'] );
		$this->assertArrayHasKey( 'annexes', $structured );
		$decoded = json_decode( $structured['annexes']['value'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 1, count( $decoded ) );

		$stored_html = $decoded[0]['content'];
		$expected    = str_replace( array( "\r\n", "\r" ), "\n", wp_kses_post( $complex_html ) );

		$this->assertSame( $expected, $stored_html );
		$this->assertStringNotContainsString( '<p>rn</p>', $stored_html );
		$this->assertStringNotContainsString( '<p>n</p>', $stored_html );
		$this->assertStringContainsString( '<table>', $stored_html );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should strip literal newline markers (n/rn) accidentally injected between tags on save.
	 */
	public function test_repeater_rich_text_removes_spurious_n_artifacts() {
		$term    = wp_insert_term( 'Tipo Limpieza', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Resolate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		// Simulate a payload already contaminated with stray 'n' artifacts.
		$contaminated = '<h3>Encabezado de prueba</h3>'
			. '<p>n</p>'
			. '<p>Primer párrafo con texto de ejemplo.</p>'
			. '<p>n</p>'
			. '<p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p>'
			. '<p>n</p>'
			. '<ul>n<li>Elemento uno</li>n<li>Elemento dos</li>n</ul>'
			. '<p>n</p>'
			. '<table><tbody><tr><th>Col 1</th><th>Col 2</th></tr>'
			. '<tr><td>Dato A1</td><td>Dato A2</td></tr>'
			. '<tr><td>Dato B1</td><td>Dato B2</td></tr></tbody></table>';

		$doc = new Resolate_Documents();

		$_POST['resolate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Limpieza',
						'content' => $contaminated,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		$structured = Resolate_Documents::parse_structured_content( $result['post_content'] );
		$decoded    = json_decode( $structured['annexes']['value'], true );
		$stored     = $decoded[0]['content'];

		$this->assertStringNotContainsString( '<p>n</p>', $stored );
		$this->assertStringNotContainsString( '>n<' , $stored );
		$this->assertStringContainsString( '<table>', $stored );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * Helper to build the annex schema fixture.
	 *
	 * @return array
	 */
	private function get_annex_schema_v2() {
		return array(
			'version'   => 2,
			'fields'    => array(),
			'repeaters' => array(
				array(
					'name'   => 'annexes',
					'slug'   => 'annexes',
					'fields' => array(
						array(
							'name'  => 'Número',
							'slug'  => 'number',
							'type'  => 'text',
							'title' => 'Número',
						),
						array(
							'name'  => 'Título',
							'slug'  => 'title',
							'type'  => 'text',
							'title' => 'Título',
						),
						array(
							'name'  => 'Contenido',
							'slug'  => 'content',
							'type'  => 'html',
							'title' => 'Contenido',
						),
					),
				),
			),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'annex-test.odt',
				'hash'          => md5( 'annex-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}
}
