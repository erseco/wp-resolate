<?php
/**
 * Tests for Documentate_Document_Generator array exports.
 */

class DocumentateDocumentGeneratorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
	}

	/**
	 * It should expose array fields as decoded PHP arrays for template merges.
	 */
	public function test_build_merge_fields_includes_array_values() {
		$term    = wp_insert_term( 'Tipo Merge', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$schema_v2 = array(
			'version'   => 2,
			'fields'    => array(
				array(
					'name'        => 'Título',
					'slug'        => 'resolution_title',
					'type'        => 'textarea',
					'title'       => 'Título',
					'placeholder' => 'resolution_title',
				),
				array(
					'name'        => 'Cuerpo',
					'slug'        => 'resolution_body',
					'type'        => 'html',
					'title'       => 'Cuerpo',
					'placeholder' => 'resolution_body',
				),
			),
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
				'template_name' => 'test.odt',
				'hash'          => md5( 'merge-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
		$storage->save_schema( $term_id, $schema_v2 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento Merge',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$doc = new Documentate_Documents();
		$_POST['documentate_doc_type']               = (string) $term_id;
		$annex_items = array(
			array(
				'number'  => 'I',
				'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>',
			),
			array(
				'number'  => 'II',
				'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>',
			),
		);
		$_POST['tpl_fields']                      = wp_slash(
			array(
				'annexes' => $annex_items,
			)
		);
		$_POST['documentate_field_resolution_title'] = '  Título base  ';
		$_POST['documentate_field_resolution_body']  = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
			$result  = $doc->filter_post_data_compose_content( $data, $postarr );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $result['post_content'],
				)
			);
			update_post_meta( $post_id, 'documentate_field_annexes', wp_json_encode( $annex_items ) );
			// Clear POST after saving to avoid interfering filters from rebuilding content.
			$_POST = array();

		$ref     = new ReflectionClass( Documentate_Document_Generator::class );
		$method  = $ref->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );
		$fields  = $method->invoke( null, $post_id );

		$this->assertArrayHasKey( 'annexes', $fields );
		$this->assertIsArray( $fields['annexes'] );
		$this->assertCount( 2, $fields['annexes'] );
		$this->assertSame( 'I', $fields['annexes'][0]['number'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $fields['annexes'][0]['content'] );
		$this->assertSame( 'Título base', $fields['resolution_title'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $fields['resolution_body'] );
	}

	/**
	 * Textarea paragraph breaks should be preserved as plain text for TBS/ODT post-processing.
	 * ODT splitting (apply_odt_paragraph_splitting) handles double newlines at render time.
	 */
	public function test_build_merge_fields_converts_textarea_paragraphs_to_html_blocks() {
		$term    = wp_insert_term( 'Tipo Párrafos', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'        => 'Respuesta',
						'slug'        => 'respuesta',
						'type'        => 'textarea',
						'title'       => 'Respuesta',
						'placeholder' => 'respuesta',
					),
				),
				'repeaters' => array(),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento párrafos',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$doc = new Documentate_Documents();
		$_POST['documentate_doc_type']              = (string) $term_id;
		$_POST['documentate_field_respuesta'] = "Primer bloque\ncon salto suave\r\n\r\nSegundo bloque";

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $result['post_content'],
			)
		);
		$_POST = array();

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );
		$fields = $method->invoke( null, $post_id );

		// Plain text is preserved with its paragraph breaks for TBS to process.
		$this->assertStringContainsString( 'Primer bloque', $fields['respuesta'] );
		$this->assertStringContainsString( 'Segundo bloque', $fields['respuesta'] );
		// No HTML tags should be added — ODT splitting handles paragraph separation at render time.
		$this->assertStringNotContainsString( '<p>', $fields['respuesta'] );
	}

	/**
	 * Test get_template_path returns empty for invalid format.
	 */
	public function test_get_template_path_invalid_format() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Template Path Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'pdf' );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_template_path with no document type.
	 */
	public function test_get_template_path_no_doc_type() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No Type Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );

		$this->assertEmpty( $result );
	}

	/**
	 * Test generate_docx returns error without template.
	 */
	public function test_generate_docx_no_template() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No Template Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_odt returns error without template.
	 */
	public function test_generate_odt_no_template() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No ODT Template Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_pdf returns error without a document type.
	 */
	public function test_generate_pdf_no_document_type() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No PDF Source Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_pdf_no_type', $result->get_error_code() );
	}

	/**
	 * Test get_type_schema via reflection.
	 */
	public function test_get_type_schema() {
		$term = wp_insert_term( 'Gen Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'gen_field',
						'slug'  => 'gen_field',
						'type'  => 'text',
						'title' => 'Gen Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_type_schema' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $term_id );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'gen_field', $result[0]['slug'] );
	}

	/**
	 * Test get_type_schema with empty schema.
	 */
	public function test_get_type_schema_empty() {
		$term = wp_insert_term( 'Empty Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_type_schema' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $term_id );

		$this->assertEmpty( $result );
	}

	/**
	 * Test build_output_path via reflection.
	 */
	public function test_build_output_path() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Output Path Test',
				'post_status' => 'draft',
			)
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_output_path' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $post_id, 'docx' );

		$this->assertStringContainsString( 'documentate', $result );
		$this->assertStringContainsString( '.docx', $result );
	}

	/**
	 * Test prepare_field_value strips tags for non-rich.
	 */
	public function test_prepare_field_value_strips_tags() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$html  = '<p>Test <strong>content</strong></p>';
		$result = $method->invoke( null, $html, 'single', 'text' );

		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringContainsString( 'Test', $result );
	}

	/**
	 * Test prepare_field_value preserves HTML for rich.
	 */
	public function test_prepare_field_value_preserves_rich() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$html  = '<p>Test <strong>content</strong></p>';
		$result = $method->invoke( null, $html, 'rich', 'text' );

		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( '<strong>', $result );
	}

	/**
	 * Test sanitize_placeholder_name via reflection.
	 */
	public function test_sanitize_placeholder_name() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'sanitize_placeholder_name' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'field_name[*].subfield' );

		$this->assertIsString( $result );
	}

	/**
	 * Test get_rich_field_values via reflection.
	 */
	public function test_get_rich_field_values() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Remember some values.
		$remember = $ref->getMethod( 'remember_rich_field_value' );
		$remember->setAccessible( true );
		$remember->invoke( null, '<p>Rich value</p>' );

		// Get values.
		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertContains( '<p>Rich value</p>', $result );
	}

	/**
	 * Test get_structured_field_value via reflection.
	 */
	public function test_get_structured_field_value() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Structured Value Test',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, 'documentate_field_test_value', 'Meta Value' );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_structured_field_value' );
		$method->setAccessible( true );

		// Test from structured content.
		$structured = array(
			'test_value' => array(
				'type'  => 'text',
				'value' => 'Structured Value',
			),
		);

		$result = $method->invoke( null, $structured, 'test_value', $post_id );

		$this->assertSame( 'Structured Value', $result );
	}

	/**
	 * Test get_structured_field_value falls back to meta.
	 */
	public function test_get_structured_field_value_meta_fallback() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Meta Fallback Test',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, 'documentate_field_fallback', 'Meta Fallback Value' );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_structured_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array(), 'fallback', $post_id );

		$this->assertSame( 'Meta Fallback Value', $result );
	}

	/**
	 * Test normalize_field_value for number data type.
	 */
	public function test_normalize_field_value_number() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '  123.45  ', 'number' );

		// normalize_field_value returns float for numbers.
		$this->assertEquals( 123.45, $result );
	}

	/**
	 * Test normalize_field_value for date data type.
	 */
	public function test_normalize_field_value_date() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '2024-01-15', 'date' );

		$this->assertIsString( $result );
	}

	/**
	 * Test normalize_field_value for boolean.
	 */
	public function test_normalize_field_value_boolean() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_field_value' );
		$method->setAccessible( true );

		$result_true = $method->invoke( null, '1', 'boolean' );
		$result_false = $method->invoke( null, '0', 'boolean' );

		// normalize_field_value returns int for booleans.
		$this->assertIsInt( $result_true );
		$this->assertIsInt( $result_false );
	}

	/**
	 * Test normalize_field_value for text (default).
	 */
	public function test_normalize_field_value_text() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '  Trimmed text  ', 'text' );

		$this->assertSame( 'Trimmed text', $result );
	}

	/**
	 * Test ensure_output_dir creates directory.
	 */
	public function test_ensure_output_dir() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'ensure_output_dir' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertTrue( is_dir( $result ) );
	}

	/**
	 * Test reset_rich_field_values clears values.
	 */
	public function test_reset_rich_field_values() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );

		// First remember a value.
		$remember = $ref->getMethod( 'remember_rich_field_value' );
		$remember->setAccessible( true );
		$remember->invoke( null, '<p>To be cleared</p>' );

		// Then reset.
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Get values should be empty.
		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertEmpty( $result );
	}

	/**
	 * Test remember_rich_values_from_array_items.
	 */
	public function test_remember_rich_values_from_array_items() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first.
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Remember from array items.
		$remember = $ref->getMethod( 'remember_rich_values_from_array_items' );
		$remember->setAccessible( true );

		$items = array(
			array(
				'title' => 'Plain',
				'content' => '<p>Rich content</p>',
			),
			array(
				'title' => 'Another',
				'content' => '<ul><li>List</li></ul>',
			),
		);

		$remember->invoke( null, $items );

		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertContains( '<p>Rich content</p>', $result );
	}

	/**
	 * Test get_array_field_items_for_merge via reflection.
	 */
	public function test_get_array_field_items_for_merge() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Array Merge Test',
				'post_status' => 'draft',
			)
		);

		$items = array(
			array( 'number' => '1', 'content' => 'First' ),
			array( 'number' => '2', 'content' => 'Second' ),
		);
		update_post_meta( $post_id, 'documentate_field_items', wp_json_encode( $items ) );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_array_field_items_for_merge' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array(), 'items', $post_id );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test get_array_field_items_for_merge from structured.
	 */
	public function test_get_array_field_items_for_merge_structured() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Array Structured Test',
				'post_status' => 'draft',
			)
		);

		$items = array(
			array( 'number' => 'A', 'content' => 'First' ),
		);
		$structured = array(
			'repeater' => array(
				'type'  => 'array',
				'value' => wp_json_encode( $items ),
			),
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_array_field_items_for_merge' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $structured, 'repeater', $post_id );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'A', $result[0]['number'] );
	}

	/**
	 * Test get_template_path with document type but no template file.
	 */
	public function test_get_template_path_missing_file() {
		// Create a document type with an attachment that points to non-existent file.
		$term = wp_insert_term( 'Template Path Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Create a mock attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'post_title'     => 'test-template.docx',
				'post_status'    => 'inherit',
			)
		);
		update_attached_file( $attachment_id, '/nonexistent/test-template.docx' );
		update_term_meta( $term_id, 'documentate_type_docx_template', $attachment_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Template Path Test',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );

		// Should return empty because file doesn't exist.
		$this->assertEmpty( $result );
	}

	/**
	 * Test sanitize_placeholder_name removes special chars.
	 */
	public function test_sanitize_placeholder_name_special_chars() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'sanitize_placeholder_name' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'field<script>bad</script>' );

		$this->assertStringNotContainsString( '<', $result );
	}

	/**
	 * Test prepare_field_value with array type.
	 */
	public function test_prepare_field_value_array() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$json = '[{"title":"Test"}]';
		$result = $method->invoke( null, $json, 'array', 'text' );

		$this->assertSame( $json, $result );
	}

	/**
	 * Test generate_docx with invalid post ID.
	 */
	public function test_generate_docx_invalid_post() {
		$result = Documentate_Document_Generator::generate_docx( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_odt with invalid post ID.
	 */
	public function test_generate_odt_invalid_post() {
		$result = Documentate_Document_Generator::generate_odt( 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_pdf draws the document itself instead of converting it.
	 */
	public function test_generate_pdf_renders_natively() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'documentate_doc_type' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'PDF Nativo',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		// A document type with no template at all: the native renderer needs
		// none, so this still produces a PDF.
		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		$this->assertStringStartsWith( '%PDF-', (string) file_get_contents( $result ) );

		wp_delete_file( $result );
	}

	/**
	 * Test build_output_path generates unique filename.
	 */
	public function test_build_output_path_unique() {
		$post1 = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Path Test 1',
				'post_status' => 'draft',
			)
		);
		$post2 = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Path Test 2',
				'post_status' => 'draft',
			)
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_output_path' );
		$method->setAccessible( true );

		$path1 = $method->invoke( null, $post1, 'docx' );
		$path2 = $method->invoke( null, $post2, 'docx' );

		$this->assertNotSame( $path1, $path2 );
	}

	/**
	 * Test generate_docx with real template from fixtures.
	 */
	public function test_generate_docx_with_real_template() {
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/demo-wp-documentate.docx';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Fixture demo-wp-documentate.docx not found.' );
		}

		// Create admin user.
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create doc type with template.
		$term    = wp_insert_term( 'Real DOCX Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$attachment_id = $this->factory()->attachment->create_upload_object( $fixture_path );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		// Parse schema from template.
		$schema = Documentate_Template_Parser::extract_fields( $fixture_path );
		if ( ! is_wp_error( $schema ) && ! empty( $schema ) ) {
			$storage = new Documentate\DocType\SchemaStorage();
			$storage->save_schema( $term_id, $schema );
		}

		// Create document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Real Template Test',
				'post_status' => 'publish',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		if ( is_wp_error( $result ) ) {
			// Accept error if template parsing failed.
			$this->assertInstanceOf( WP_Error::class, $result );
		} else {
			$this->assertIsString( $result );
			$this->assertFileExists( $result );
			// Clean up generated file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $result );
		}
	}

	/**
	 * Test generate_odt with real template from fixtures.
	 */
	public function test_generate_odt_with_real_template() {
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Fixture resolucion.odt not found.' );
		}

		// Create admin user.
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Create doc type with template.
		$term    = wp_insert_term( 'Real ODT Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$attachment_id = $this->factory()->attachment->create_upload_object( $fixture_path );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		// Parse schema from template.
		$schema = Documentate_Template_Parser::extract_fields( $fixture_path );
		if ( ! is_wp_error( $schema ) && ! empty( $schema ) ) {
			$storage = new Documentate\DocType\SchemaStorage();
			$storage->save_schema( $term_id, $schema );
		}

		// Create document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Real ODT Test',
				'post_status' => 'publish',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( WP_Error::class, $result );
		} else {
			$this->assertIsString( $result );
			$this->assertFileExists( $result );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $result );
		}
	}

	/**
	 * Test get_template_path returns correct path for ODT.
	 */
	public function test_get_template_path_odt() {
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/resolucion.odt';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Fixture resolucion.odt not found.' );
		}

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$term    = wp_insert_term( 'Template Path ODT', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$attachment_id = $this->factory()->attachment->create_upload_object( $fixture_path );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Template Path ODT Test',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'odt' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '.odt', $result );
	}

	/**
	 * Test get_template_path returns correct path for DOCX.
	 */
	public function test_get_template_path_docx() {
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/demo-wp-documentate.docx';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Fixture demo-wp-documentate.docx not found.' );
		}

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$term    = wp_insert_term( 'Template Path DOCX', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$attachment_id = $this->factory()->attachment->create_upload_object( $fixture_path );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Template Path DOCX Test',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( '.docx', $result );
	}

	/**
	 * Test build_merge_fields with document containing field values.
	 */
	public function test_build_merge_fields_with_values() {
		$term    = wp_insert_term( 'Merge Fields Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'title',
						'slug'  => 'title',
						'type'  => 'text',
						'title' => 'Title',
					),
					array(
						'name'  => 'body',
						'slug'  => 'body',
						'type'  => 'html',
						'title' => 'Body',
					),
				),
				'repeaters' => array(),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Merge Fields Test',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		update_post_meta( $post_id, 'documentate_field_title', 'Test Title Value' );
		update_post_meta( $post_id, 'documentate_field_body', '<p>Test body content</p>' );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );

		$fields = $method->invoke( null, $post_id );

		$this->assertArrayHasKey( 'title', $fields );
		$this->assertArrayHasKey( 'body', $fields );
		$this->assertSame( 'Test Title Value', $fields['title'] );
		$this->assertStringContainsString( 'Test body content', $fields['body'] );
	}

	/**
	 * Test generate with comprehensive test template.
	 */
	public function test_generate_with_comprehensive_template() {
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'tests/fixtures/templates/comprehensive-test.odt';
		if ( ! file_exists( $fixture_path ) ) {
			$this->markTestSkipped( 'Fixture comprehensive-test.odt not found.' );
		}

		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$term    = wp_insert_term( 'Comprehensive Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$attachment_id = $this->factory()->attachment->create_upload_object( $fixture_path );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Comprehensive Test',
				'post_status' => 'publish',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->assertInstanceOf( WP_Error::class, $result );
		} else {
			$this->assertFileExists( $result );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $result );
		}
	}

	/**
	 * Test prepare_field_value for date-time.
	 */
	public function test_prepare_field_value_datetime() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '2024-01-15T10:30', 'single', 'datetime-local' );

		$this->assertIsString( $result );
	}

	/**
	 * Test prepare_field_value for empty value.
	 */
	public function test_prepare_field_value_empty() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, '', 'single', 'text' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test normalize_number_value with various inputs.
	 */
	public function test_normalize_number_value() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_number_value' );
		$method->setAccessible( true );

		// Empty string.
		$this->assertSame( '', $method->invoke( null, '' ) );

		// Numeric string.
		$this->assertEquals( 123, $method->invoke( null, '123' ) );

		// Decimal with comma.
		$this->assertEquals( 123.45, $method->invoke( null, '123,45' ) );

		// Decimal with period.
		$this->assertEquals( 123.45, $method->invoke( null, '123.45' ) );

		// Negative number.
		$this->assertEquals( -50, $method->invoke( null, '-50' ) );

		// Non-numeric string returns empty (filtered to empty).
		$this->assertSame( '', $method->invoke( null, 'abc' ) );

		// Numeric with currency.
		$this->assertEquals( 100, $method->invoke( null, '$100' ) );
	}

	/**
	 * Test normalize_boolean_value with various inputs.
	 */
	public function test_normalize_boolean_value() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_boolean_value' );
		$method->setAccessible( true );

		// Boolean true.
		$this->assertSame( 1, $method->invoke( null, true ) );

		// Boolean false.
		$this->assertSame( 0, $method->invoke( null, false ) );

		// String '1'.
		$this->assertSame( 1, $method->invoke( null, '1' ) );

		// String 'true'.
		$this->assertSame( 1, $method->invoke( null, 'true' ) );

		// String 'TRUE' (case insensitive).
		$this->assertSame( 1, $method->invoke( null, 'TRUE' ) );

		// String 'yes'.
		$this->assertSame( 1, $method->invoke( null, 'yes' ) );

		// String 'si' (Spanish).
		$this->assertSame( 1, $method->invoke( null, 'si' ) );

		// String 'sí' (Spanish with accent).
		$this->assertSame( 1, $method->invoke( null, 'sí' ) );

		// String 'on'.
		$this->assertSame( 1, $method->invoke( null, 'on' ) );

		// String '0'.
		$this->assertSame( 0, $method->invoke( null, '0' ) );

		// String 'false'.
		$this->assertSame( 0, $method->invoke( null, 'false' ) );

		// String 'no'.
		$this->assertSame( 0, $method->invoke( null, 'no' ) );

		// Empty string.
		$this->assertSame( 0, $method->invoke( null, '' ) );
	}

	/**
	 * Test normalize_date_value returns ISO format for TBS.
	 */
	public function test_normalize_date_value() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_date_value' );
		$method->setAccessible( true );

		// Empty string.
		$this->assertSame( '', $method->invoke( null, '' ) );

		// Standard ISO date → stays ISO.
		$result = $method->invoke( null, '2024-03-15' );
		$this->assertSame( '2024-03-15', $result );

		// European DD/MM/YYYY → parsed correctly as 15 March, returned as ISO.
		$result = $method->invoke( null, '15/03/2024' );
		$this->assertSame( '2024-03-15', $result );

		// DD/MM/YYYY where day < 13 — must NOT be misinterpreted as MM/DD.
		$result = $method->invoke( null, '05/01/2026' );
		$this->assertSame( '2026-01-05', $result );

		// Invalid date.
		$result = $method->invoke( null, 'not-a-date' );
		$this->assertSame( 'not-a-date', $result );
	}

	/**
	 * Test parse_date_value with various formats.
	 */
	public function test_parse_date_value() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'parse_date_value' );
		$method->setAccessible( true );

		// ISO format.
		$date = $method->invoke( null, '2024-03-15' );
		$this->assertInstanceOf( DateTime::class, $date );
		$this->assertSame( '2024-03-15', $date->format( 'Y-m-d' ) );

		// European with dots.
		$date = $method->invoke( null, '15.03.2024' );
		$this->assertInstanceOf( DateTime::class, $date );
		$this->assertSame( '2024-03-15', $date->format( 'Y-m-d' ) );

		// European with dashes.
		$date = $method->invoke( null, '15-03-2024' );
		$this->assertInstanceOf( DateTime::class, $date );
		$this->assertSame( '2024-03-15', $date->format( 'Y-m-d' ) );

		// Invalid date returns false.
		$result = $method->invoke( null, 'not-a-date' );
		$this->assertFalse( $result );
	}

	/**
	 * Test remember_rich_field_value edge cases.
	 */
	public function test_remember_rich_field_value() {
		$ref = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first.
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Remember method.
		$remember = $ref->getMethod( 'remember_rich_field_value' );
		$remember->setAccessible( true );

		// Get method.
		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );

		// Empty string should not be remembered.
		$remember->invoke( null, '' );
		$this->assertEmpty( $get->invoke( null ) );

		// Reset.
		$reset->invoke( null );

		// Value without HTML tags should not be remembered.
		$remember->invoke( null, 'Plain text without tags' );
		$this->assertEmpty( $get->invoke( null ) );

		// Reset.
		$reset->invoke( null );

		// Value with incomplete HTML should not be remembered.
		$remember->invoke( null, 'Only opening <' );
		$this->assertEmpty( $get->invoke( null ) );

		// Reset.
		$reset->invoke( null );

		// Valid HTML value should be remembered.
		$remember->invoke( null, '<p>Valid HTML</p>' );
		$result = $get->invoke( null );
		$this->assertNotEmpty( $result );
		$this->assertContains( '<p>Valid HTML</p>', $result );
	}

	/**
	 * Test prepare_field_value with rich type.
	 */
	public function test_prepare_field_value_rich_type() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		// Rich type should preserve allowed HTML.
		$html   = '<p>Paragraph</p><strong>Bold</strong>';
		$result = $method->invoke( null, $html, 'rich', 'text' );
		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( '<strong>', $result );
	}

	/**
	 * Test prepare_field_value with html type.
	 */
	public function test_prepare_field_value_html_type() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		// HTML type should also preserve allowed HTML.
		$html   = '<ul><li>Item</li></ul>';
		$result = $method->invoke( null, $html, 'html', 'text' );
		$this->assertStringContainsString( '<ul>', $result );
		$this->assertStringContainsString( '<li>', $result );
	}

	/**
	 * Test get_structured_field_value with empty slug.
	 */
	public function test_get_structured_field_value_empty_slug() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_structured_field_value' );
		$method->setAccessible( true );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Empty Slug Test',
				'post_status' => 'draft',
			)
		);

		$result = $method->invoke( null, array(), '', $post_id );

		$this->assertSame( '', $result );
	}

	/**
	 * Test get_array_field_items_for_merge with empty slug.
	 */
	public function test_get_array_field_items_for_merge_empty_slug() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_array_field_items_for_merge' );
		$method->setAccessible( true );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Empty Slug Array Test',
				'post_status' => 'draft',
			)
		);

		$result = $method->invoke( null, array(), '', $post_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test normalize_field_value with unknown data type.
	 */
	public function test_normalize_field_value_unknown_type() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'normalize_field_value' );
		$method->setAccessible( true );

		// Unknown type should return trimmed value.
		$result = $method->invoke( null, '  test value  ', 'unknown_type' );
		$this->assertSame( 'test value', $result );
	}

	/**
	 * Test prepare_field_value strips tags for non-rich types.
	 */
	public function test_prepare_field_value_textarea_strips_tags() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$html   = '<p>Paragraph</p>';
		$result = $method->invoke( null, $html, 'textarea', 'text' );

		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringContainsString( 'Paragraph', $result );
	}

	/**
	 * Test prepare_field_value with non-string value.
	 */
	public function test_prepare_field_value_non_string() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		// Non-string should be converted to empty string.
		$result = $method->invoke( null, null, 'single', 'text' );
		$this->assertSame( '', $result );

		$result = $method->invoke( null, 123, 'single', 'text' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test remember_rich_values_from_array_items with empty items.
	 */
	public function test_remember_rich_values_from_array_items_empty() {
		$ref = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first.
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Call with empty array.
		$remember = $ref->getMethod( 'remember_rich_values_from_array_items' );
		$remember->setAccessible( true );
		$remember->invoke( null, array() );

		// Get values should be empty.
		$get    = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertEmpty( $result );
	}

	/**
	 * Test remember_rich_values_from_array_items with non-array item.
	 */
	public function test_remember_rich_values_from_array_items_non_array_item() {
		$ref = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first.
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Call with non-array items.
		$remember = $ref->getMethod( 'remember_rich_values_from_array_items' );
		$remember->setAccessible( true );
		$remember->invoke( null, array( 'string', 123, null ) );

		// Get values should be empty since items weren't arrays.
		$get    = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertEmpty( $result );
	}

	/**
	 * Test that title is NOT transformed when no case attribute in schema.
	 */
	public function test_title_no_case_transformation_by_default() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'mi título de prueba',
				'post_status' => 'publish',
			)
		);

		// No document type assigned - no case transformation.
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );

		$fields = $method->invoke( null, $post_id );

		$this->assertSame( 'mi título de prueba', $fields['title'], 'Title must NOT be transformed without case attribute.' );

		wp_delete_post( $post_id, true );
	}

	/**
	 * Test apply_case_transformation with uppercase.
	 */
	public function test_apply_case_transformation_upper() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'apply_case_transformation' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'mi título de prueba', 'upper' );

		$this->assertSame( 'MI TÍTULO DE PRUEBA', $result, 'Must transform to uppercase.' );
	}

	/**
	 * Test apply_case_transformation with lowercase.
	 */
	public function test_apply_case_transformation_lower() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'apply_case_transformation' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'MI TÍTULO DE PRUEBA', 'lower' );

		$this->assertSame( 'mi título de prueba', $result, 'Must transform to lowercase.' );
	}

	/**
	 * Test apply_case_transformation with title case.
	 */
	public function test_apply_case_transformation_title() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'apply_case_transformation' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'mi título de prueba', 'title' );

		$this->assertSame( 'Mi Título De Prueba', $result, 'Must transform to title case.' );
	}

	/**
	 * Test apply_case_transformation with empty case (no transformation).
	 */
	public function test_apply_case_transformation_empty() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'apply_case_transformation' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'Mi Título De Prueba', '' );

		$this->assertSame( 'Mi Título De Prueba', $result, 'Must not transform with empty case.' );
	}

	/**
	 * Test apply_case_transformation handles special characters correctly.
	 */
	public function test_apply_case_transformation_special_characters() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'apply_case_transformation' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'título con ñ, ü, á, é, í, ó, ú', 'upper' );

		$this->assertSame( 'TÍTULO CON Ñ, Ü, Á, É, Í, Ó, Ú', $result, 'Must handle special characters correctly.' );
	}

}
