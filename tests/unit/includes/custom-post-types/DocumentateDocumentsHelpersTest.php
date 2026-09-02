<?php
/**
 * Tests for the helpers extracted from Documentate_Documents.
 *
 * These pin the behaviour the extractions had to preserve: how a schema entry
 * gets its slug, which entries are dropped, how repeater rows are judged empty
 * and encoded, and what the list table filters render.
 *
 * @covers Documentate_Documents
 * @covers Documentate_Document_Meta_Boxes
 * @covers Documentate_Document_Scalar_Field
 * @covers Documentate_Document_Repeater_Field
 * @covers Documentate_Document_Field_Help
 * @covers Documentate_Document_Meta_Saver
 * @covers Documentate_Document_Content_Writer
 * @covers Documentate_Document_Admin_List
 */

class DocumentateDocumentsHelpersTest extends WP_UnitTestCase {

	/**
	 * Field persistence.
	 *
	 * @var Documentate_Document_Meta_Saver
	 */
	private $meta_saver;

	/**
	 * Admin list table behaviour.
	 *
	 * @var Documentate_Document_Admin_List
	 */
	private $admin_list;

	/**
	 * Metabox renderer, which now owns the rendering internals.
	 *
	 * @var Documentate_Document_Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Instance under test.
	 *
	 * @var Documentate_Documents
	 */
	private $documents;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->documents = new Documentate_Documents();
		$this->meta_boxes = new Documentate_Document_Meta_Boxes();
		$this->meta_saver = new Documentate_Document_Meta_Saver();
		$this->admin_list = new Documentate_Document_Admin_List();
	}

	/**
	 * Find whichever collaborator declares a method.
	 *
	 * The CPT was split into a renderer, a saver and an admin-list class, so a
	 * helper under test now lives on one of four objects.
	 *
	 * @param string $name Method name.
	 * @return object
	 */
	private function owner_of( $name ) {
		foreach ( array( $this->documents, $this->meta_boxes, $this->meta_saver, $this->admin_list, 'Documentate_Document_Content_Writer', 'Documentate_Document_Field_Help', 'Documentate_Document_Repeater_Field', 'Documentate_Document_Scalar_Field' ) as $candidate ) {
			if ( method_exists( $candidate, $name ) ) {
				return $candidate;
			}
		}

		$this->fail( 'No collaborator declares ' . $name );
	}

	/**
	 * Invoke a private method on the instance under test.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke( $name, array $args = array() ) {
		$target = $this->owner_of( $name );
		$method = new ReflectionMethod( $target, $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $method->isStatic() ? null : $target, $args );
	}

	/**
	 * A schema entry takes its slug from slug, then name.
	 */
	public function test_schema_entry_slug_prefers_slug_then_name() {
		$this->assertSame(
			'campo',
			$this->invoke( 'schema_entry_slug', array( array( 'slug' => 'campo', 'name' => 'Otro' ) ) )
		);

		// sanitize_key() drops characters outside a-z0-9_-, so spaces collapse
		// rather than becoming separators.
		$this->assertSame(
			'solonombre',
			$this->invoke( 'schema_entry_slug', array( array( 'name' => 'Solo Nombre' ) ) )
		);

		$this->assertSame( '', $this->invoke( 'schema_entry_slug', array( array() ) ) );
	}

	/**
	 * The slug is sanitised, not taken verbatim.
	 */
	public function test_schema_entry_slug_is_sanitised() {
		$this->assertSame(
			'mi-campo',
			$this->invoke( 'schema_entry_slug', array( array( 'slug' => 'Mi-Campo' ) ) )
		);
	}

	/**
	 * Entries that cannot be identified are dropped rather than indexed.
	 */
	public function test_index_schema_entries_skips_unusable_entries() {
		$index = $this->invoke(
			'index_schema_entries',
			array(
				array(
					array( 'slug' => 'uno' ),
					'no soy un array',
					array( 'sin_slug_ni_nombre' => 1 ),
					array( 'name' => 'Dos' ),
				),
			)
		);

		$this->assertSame( array( 'uno', 'dos' ), array_keys( $index ) );
	}

	/**
	 * A non-array payload yields an empty index instead of an error.
	 */
	public function test_index_schema_entries_tolerates_non_arrays() {
		$this->assertSame( array(), $this->invoke( 'index_schema_entries', array( null ) ) );
		$this->assertSame( array(), $this->invoke( 'index_schema_entries', array( 'texto' ) ) );
	}

	/**
	 * Repeaters keep their definition and get their own fields indexed.
	 */
	public function test_index_schema_repeaters_indexes_nested_fields() {
		$index = $this->invoke(
			'index_schema_repeaters',
			array(
				array(
					array(
						'slug' => 'anexos',
						'fields' => array(
							array( 'slug' => 'numero' ),
							array( 'name' => 'Contenido' ),
							'basura',
						),
					),
					array( 'no_identificable' => true ),
				),
			)
		);

		$this->assertSame( array( 'anexos' ), array_keys( $index ) );
		$this->assertSame( array( 'numero', 'contenido' ), array_keys( $index['anexos']['fields'] ) );
		$this->assertSame( 'anexos', $index['anexos']['definition']['slug'] );
	}

	/**
	 * A repeater without fields still indexes, with an empty field list.
	 */
	public function test_index_schema_repeaters_without_fields() {
		$index = $this->invoke( 'index_schema_repeaters', array( array( array( 'slug' => 'vacio' ) ) ) );

		$this->assertSame( array(), $index['vacio']['fields'] );
	}

	/**
	 * No rows encodes to an empty string, which callers treat as "delete".
	 */
	public function test_encode_array_field_items_empty() {
		$this->assertSame( '', $this->invoke( 'encode_array_field_items', array( array() ) ) );
	}

	/**
	 * Quotes and accents are escaped as \uXXXX so WordPress slashing is safe.
	 */
	public function test_encode_array_field_items_escapes_quotes_and_accents() {
		$json = $this->invoke(
			'encode_array_field_items',
			array( array( array( 'texto' => 'Añadió "algo"' ) ) )
		);

		$this->assertStringNotContainsString( '"algo"', $json );
		$this->assertStringNotContainsString( 'ñ', $json );
		$this->assertSame( 'Añadió "algo"', json_decode( $json, true )[0]['texto'] );
	}

	/**
	 * An empty value removes the meta key instead of storing a blank.
	 */
	public function test_write_or_delete_meta_removes_on_empty() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'documentate_field_x', 'algo' );

		$this->invoke( 'write_or_delete_meta', array( $post_id, 'documentate_field_x', '' ) );

		$this->assertSame( '', get_post_meta( $post_id, 'documentate_field_x', true ) );
	}

	/**
	 * A non-empty value is stored.
	 */
	public function test_write_or_delete_meta_stores_value() {
		$post_id = self::factory()->post->create();

		$this->invoke( 'write_or_delete_meta', array( $post_id, 'documentate_field_x', 'valor' ) );

		$this->assertSame( 'valor', get_post_meta( $post_id, 'documentate_field_x', true ) );
	}

	/**
	 * A repeater row holding only empty markup counts as blank.
	 */
	public function test_array_item_has_content_ignores_empty_markup() {
		$schema = array( 'cuerpo' => array( 'type' => 'rich' ) );

		$this->assertFalse(
			$this->invoke( 'array_item_has_content', array( array( 'cuerpo' => '<p></p>' ), $schema ) )
		);
		$this->assertTrue(
			$this->invoke( 'array_item_has_content', array( array( 'cuerpo' => '<p>Hola</p>' ), $schema ) )
		);
	}

	/**
	 * Plain values are judged on their trimmed text.
	 */
	public function test_array_item_has_content_trims_plain_values() {
		$schema = array( 'titulo' => array( 'type' => 'single' ) );

		$this->assertFalse(
			$this->invoke( 'array_item_has_content', array( array( 'titulo' => '   ' ), $schema ) )
		);
		$this->assertTrue(
			$this->invoke( 'array_item_has_content', array( array( 'titulo' => ' x ' ), $schema ) )
		);
	}

	/**
	 * Keys absent from the row are filled in, and unknown keys dropped.
	 */
	public function test_sanitize_array_item_follows_the_schema() {
		$filtered = $this->invoke(
			'sanitize_array_item',
			array(
				array( 'titulo' => 'Uno', 'colado' => 'fuera' ),
				array(
					'titulo' => array( 'type' => 'single' ),
					'ausente' => array( 'type' => 'single' ),
				),
			)
		);

		$this->assertSame( array( 'titulo', 'ausente' ), array_keys( $filtered ) );
		$this->assertSame( '', $filtered['ausente'] );
	}

	/**
	 * Each control type gets its sanitizer, and unknown types the textarea one.
	 *
	 * "single" is deliberately single-line: sanitize_text_field() collapses
	 * newlines, which is what a single-line control can carry. The meta saver
	 * and the content writer share this method so both store the same value.
	 *
	 * @dataProvider sanitizer_provider
	 *
	 * @param string $raw      Submitted value.
	 * @param string $type     Control type.
	 * @param string $expected Stored value.
	 */
	public function test_sanitize_field_by_type_per_control_type( $raw, $type, $expected ) {
		$this->assertSame( $expected, Documentate_Document_Content_Writer::sanitize_field_by_type( $raw, $type ) );
	}

	/**
	 * Values, types and what each one stores.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function sanitizer_provider() {
		return array(
			'single collapses newlines' => array( "a\nb", 'single', 'a b' ),
			'textarea keeps them' => array( "a\nb", 'textarea', "a\nb" ),
			'unknown types fall back to textarea' => array( "a\nb", 'number', "a\nb" ),
			'non scalar values are dropped' => array( array( 'x' ), 'single', '' ),
		);
	}

	/**
	 * Rich values only lose the dangerous elements.
	 */
	public function test_sanitize_field_by_type_keeps_rich_markup() {
		$clean = Documentate_Document_Content_Writer::sanitize_field_by_type( '<script>x</script><p>y</p>', 'rich' );

		$this->assertStringNotContainsString( '<script>', $clean );
		$this->assertStringContainsString( '<p>y</p>', $clean );
	}

	/**
	 * A repeater column of type single is single-line too, end to end.
	 */
	public function test_sanitize_array_field_items_collapses_newlines_in_single_columns() {
		$items = Documentate_Document_Content_Writer::sanitize_array_field_items(
			array( array( 'titulo' => "Uno\ndos", 'cuerpo' => "Uno\ndos" ) ),
			array(
				'item_schema' => array(
					'titulo' => array( 'label' => 'Título', 'type' => 'single' ),
					'cuerpo' => array( 'label' => 'Cuerpo', 'type' => 'textarea' ),
				),
			)
		);

		$this->assertSame( 'Uno dos', $items[0]['titulo'] );
		$this->assertSame( "Uno\ndos", $items[0]['cuerpo'] );
	}

	/**
	 * A filter with no options renders nothing at all.
	 */
	public function test_filter_select_renders_nothing_when_empty() {
		ob_start();
		$this->invoke(
			'render_admin_filter_select',
			array(
				array(
					'name' => 'author',
					'id' => 'filter-by-author',
					'all_label' => 'Todos',
					'current' => '',
					'options' => array(),
				),
			)
		);

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The option matching the current value is the selected one.
	 */
	public function test_filter_select_marks_the_current_option() {
		ob_start();
		$this->invoke(
			'render_admin_filter_select',
			array(
				array(
					'name' => 'documentate_doc_type',
					'id' => 'filter-by-doc-type',
					'all_label' => 'Todos los tipos',
					'current' => 'resolucion',
					'options' => array(
						'resolucion' => 'Resolución',
						'informe' => 'Informe',
					),
				),
			)
		);
		$markup = ob_get_clean();

		$this->assertStringContainsString( '<select name="documentate_doc_type" id="filter-by-doc-type">', $markup );
		$this->assertStringContainsString( '<option value="">Todos los tipos</option>', $markup );
		$this->assertMatchesRegularExpression( '/<option value="resolucion" selected/', $markup );
		$this->assertMatchesRegularExpression( '/<option value="informe">/', $markup );
	}

	/**
	 * Option labels and values are escaped.
	 */
	public function test_filter_select_escapes_output() {
		ob_start();
		$this->invoke(
			'render_admin_filter_select',
			array(
				array(
					'name' => 'category_name',
					'id' => 'filter-by-category',
					'all_label' => 'Todas',
					'current' => '',
					'options' => array( 'x"><script>' => '<b>malo</b>' ),
				),
			)
		);
		$markup = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringNotContainsString( '<b>malo</b>', $markup );
	}
}
