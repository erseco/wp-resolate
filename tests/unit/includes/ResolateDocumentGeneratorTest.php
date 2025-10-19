<?php
/**
 * Tests for Resolate_Document_Generator array exports.
 */

class ResolateDocumentGeneratorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	/**
	 * It should expose array fields as decoded PHP arrays for template merges.
	 */
	public function test_build_merge_fields_includes_array_values() {
		$term    = wp_insert_term( 'Tipo Merge', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Resolate\DocType\SchemaStorage();
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
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento Merge',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		$doc = new Resolate_Documents();
		$_POST['resolate_doc_type']               = (string) $term_id;
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
		$_POST['resolate_field_resolution_title'] = '  Título base  ';
		$_POST['resolate_field_resolution_body']  = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';

		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
			$result  = $doc->filter_post_data_compose_content( $data, $postarr );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $result['post_content'],
				)
			);
			update_post_meta( $post_id, 'resolate_field_annexes', wp_json_encode( $annex_items ) );
			// Clear POST after saving to avoid interfering filters from rebuilding content.
			$_POST = array();

		$ref     = new ReflectionClass( Resolate_Document_Generator::class );
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
}
