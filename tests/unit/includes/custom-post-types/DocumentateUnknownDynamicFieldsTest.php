<?php
/**
 * Tests for the "unknown dynamic fields" warning of the sections meta box.
 *
 * When a document carries `documentate_field_*` values that the currently
 * selected document type does not define - because the type changed, or the
 * template was reparsed - the editor must surface them instead of silently
 * dropping the content.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Document_Meta_Boxes
 */
class DocumentateUnknownDynamicFieldsTest extends Documentate_Test_Base {

	/**
	 * Meta boxes instance under test.
	 *
	 * @var Documentate_Document_Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Set up an administrator and the meta box renderer.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->meta_boxes = new Documentate_Document_Meta_Boxes();
	}

	/**
	 * Clear the submitted field values.
	 */
	public function tear_down() {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Render the sections meta box for a post and return its markup.
	 *
	 * @param int $post_id Document post ID.
	 * @return string Rendered markup.
	 */
	private function render_sections( $post_id ) {
		ob_start();
		$this->meta_boxes->render_sections_metabox( get_post( $post_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Create a document with the given content.
	 *
	 * @param string $content Post content.
	 * @return int Post ID.
	 */
	private function create_document( $content = '' ) {
		return wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Unknown fields document',
				'post_content' => $content,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * A document with no type shows only the "configure a type" hint.
	 */
	public function test_document_without_a_type_shows_the_configuration_hint() {
		$markup = $this->render_sections( $this->create_document() );

		$this->assertStringContainsString( 'Configura un tipo de documento con campos', $markup );
		$this->assertStringNotContainsString( 'documentate-unknown-dynamic', $markup );
	}

	/**
	 * Submitted field values that no schema claims are rendered back with a
	 * warning, so the editor can rescue their content.
	 */
	public function test_submitted_orphan_fields_are_surfaced_with_a_warning() {
		$post_id = $this->create_document();
		$_POST['documentate_field_motivo_baja'] = '<p>Texto heredado</p>';

		$markup = $this->render_sections( $post_id );

		$this->assertStringContainsString( 'documentate-unknown-dynamic', $markup );
		$this->assertStringContainsString( 'no pertenecen al tipo seleccionado', $markup );
		$this->assertStringContainsString( 'Motivo Baja', $markup );
		$this->assertStringContainsString( 'documentate_field_motivo_baja', $markup );
	}

	/**
	 * Values already stored in the document content are surfaced too, even when
	 * nothing was submitted.
	 */
	public function test_stored_orphan_fields_are_surfaced() {
		$content = '<!-- documentate-field slug="antiguo" type="text" -->Valor guardado<!-- /documentate-field -->';
		$post_id = $this->create_document( $content );

		$markup = $this->render_sections( $post_id );

		$this->assertStringContainsString( 'documentate-unknown-dynamic', $markup );
		$this->assertStringContainsString( 'documentate_field_antiguo', $markup );
	}

	/**
	 * Array values are not editable as orphan fields and must be skipped.
	 */
	public function test_submitted_array_values_are_not_rendered_as_orphan_fields() {
		$post_id = $this->create_document();
		$_POST['documentate_field_anexos'] = array( array( 'titulo' => 'Anexo I' ) );

		$markup = $this->render_sections( $post_id );

		$this->assertStringNotContainsString( 'documentate-unknown-dynamic', $markup );
	}

	/**
	 * Request keys outside the dynamic field namespace are ignored.
	 */
	public function test_unrelated_request_keys_are_ignored() {
		$post_id = $this->create_document();
		$_POST['post_title'] = 'Something';
		$_POST['unrelated_field_x'] = 'value';

		$markup = $this->render_sections( $post_id );

		$this->assertStringNotContainsString( 'documentate-unknown-dynamic', $markup );
	}

	/**
	 * Fields the selected type defines are rendered as normal controls, not as
	 * orphans.
	 */
	public function test_schema_fields_are_not_reported_as_orphans() {
		$term = wp_insert_term( 'Orphan Check Type', 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		$storage = new \Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'asunto',
						'slug' => 'asunto',
						'title' => 'Asunto',
						'type' => 'text',
					),
				),
				'repeaters' => array(),
				'meta' => array( 'template_type' => 'odt' ),
			)
		);

		$post_id = $this->create_document();
		wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );
		$_POST['documentate_field_asunto'] = 'Solicitud';
		$_POST['documentate_field_desconocido'] = 'Huérfano';

		$markup = $this->render_sections( $post_id );

		$this->assertStringContainsString( 'documentate-unknown-dynamic', $markup );
		$this->assertStringContainsString( 'documentate_field_desconocido', $markup );
		$this->assertStringNotContainsString(
			'documentate-field-warning documentate_field_asunto',
			$markup
		);
	}
}
