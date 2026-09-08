<?php
/**
 * What a save starts from: document type, stored values and write guard.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * @covers Documentate_Document_Save_Context
 */
class DocumentateDocumentSaveContextTest extends WP_UnitTestCase {

	/**
	 * Document with a type and stored values.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Type of the document: one área field, one gestión field.
	 *
	 * @var int
	 */
	private $type_id;

	/**
	 * Another type, whose gestión field the document does not declare.
	 *
	 * @var int
	 */
	private $other_type_id;

	/**
	 * Two document types and a document assigned to the first one.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );

		$this->type_id = $this->create_type(
			array(
				array( 'name' => 'objeto', 'slug' => 'objeto', 'type' => 'text' ),
				array( 'name' => 'numero', 'slug' => 'numero', 'type' => 'text', 'rol' => 'gestion' ),
			)
		);
		$this->other_type_id = $this->create_type(
			array(
				array( 'name' => 'partida', 'slug' => 'partida', 'type' => 'text', 'rol' => 'gestion' ),
			)
		);

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento contexto',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $this->doc_id, array( $this->type_id ), 'documentate_doc_type', false );
		update_post_meta( $this->doc_id, 'documentate_field_objeto', 'Almacenado' );
	}

	/**
	 * Reset the request and the user.
	 */
	public function tear_down(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a document type with the given v2 fields.
	 *
	 * @param array $fields Schema fields.
	 * @return int Term ID.
	 */
	private function create_type( array $fields ) {
		$term = wp_insert_term( 'Tipo contexto ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => $fields,
			)
		);

		return $term_id;
	}

	/**
	 * The type assigned to the document wins over the one the request names.
	 */
	public function test_term_id_prefers_the_assigned_type() {
		$_POST['documentate_doc_type'] = $this->other_type_id;

		$this->assertSame( $this->type_id, Documentate_Document_Save_Context::term_id( $this->doc_id ) );
	}

	/**
	 * A document without a type takes the one the request names, or none.
	 */
	public function test_term_id_falls_back_to_the_posted_type() {
		$without_type = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Sin tipo',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 0, Documentate_Document_Save_Context::term_id( $without_type ) );
		$this->assertSame( 0, Documentate_Document_Save_Context::term_id( 0 ) );

		$_POST['documentate_doc_type'] = $this->other_type_id;
		$this->assertSame( $this->other_type_id, Documentate_Document_Save_Context::term_id( $without_type ) );

		$_POST['documentate_doc_type'] = '-3';
		$this->assertSame( 0, Documentate_Document_Save_Context::term_id( $without_type ) );
	}

	/**
	 * The request values and the stored ones are kept apart.
	 */
	public function test_existing_values_keeps_the_request_and_the_database_apart() {
		$forged = Documents_Meta_Handler::build_structured_field_fragment( 'objeto', 'single', 'Del request' );

		$values = Documentate_Document_Save_Context::existing_values(
			array( 'post_content' => $forged ),
			$this->doc_id
		);

		$this->assertSame( 'Del request', $values['request']['objeto']['value'] );
		$this->assertSame( 'Almacenado', $values['stored']['objeto']['value'] );
	}

	/**
	 * With nothing in the request both maps are the stored one.
	 */
	public function test_existing_values_falls_back_to_the_stored_map() {
		$values = Documentate_Document_Save_Context::existing_values( array( 'post_content' => '' ), $this->doc_id );

		$this->assertSame( 'Almacenado', $values['request']['objeto']['value'] );
		$this->assertSame( $values['stored'], $values['request'] );

		$is_empty = Documentate_Document_Save_Context::existing_values( array(), 0 );
		$this->assertSame( array(), $is_empty['stored'] );
		$this->assertSame( array(), $is_empty['request'] );
	}

	/**
	 * The stored map reads post_content when it carries the fields.
	 */
	public function test_existing_values_reads_the_stored_post_content() {
		global $wpdb;

		// Written straight to the row: wp_update_post() would recompose the
		// content through the writer this class feeds.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => Documents_Meta_Handler::build_structured_field_fragment( 'objeto', 'single', 'En el contenido' ) ),
			array( 'ID' => $this->doc_id )
		);
		clean_post_cache( $this->doc_id );

		$values = Documentate_Document_Save_Context::existing_values( array(), $this->doc_id );

		$this->assertSame( 'En el contenido', $values['stored']['objeto']['value'] );
	}

	/**
	 * The área may not write the gestión slugs of its type nor of another one.
	 */
	public function test_hidden_slugs_covers_the_posted_type_too() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_POST['documentate_doc_type'] = $this->other_type_id;

		$hidden_slugs = Documentate_Document_Save_Context::hidden_slugs(
			Documents_Meta_Handler::get_term_schema( $this->type_id )
		);

		$this->assertArrayHasKey( 'numero', $hidden_slugs, 'Its own gestión field.' );
		$this->assertArrayHasKey( 'partida', $hidden_slugs, 'And the one of the type the request names.' );
		$this->assertArrayNotHasKey( 'objeto', $hidden_slugs );
	}

	/**
	 * Gestión documental hides nothing, and rows without a slug are skipped.
	 */
	public function test_hidden_slugs_is_empty_for_management() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame(
			array(),
			Documentate_Document_Save_Context::hidden_slugs( Documents_Meta_Handler::get_term_schema( $this->type_id ) )
		);
		$this->assertSame(
			array(),
			Documentate_Document_Save_Context::hidden_slugs( array( 'no es una fila', array( 'slug' => '' ) ) )
		);
	}

	/**
	 * A stored entry keeps its type; an unknown one becomes rich.
	 */
	public function test_entry_normalises_the_stored_type() {
		$this->assertSame(
			array( 'type' => 'single', 'value' => 'x' ),
			Documentate_Document_Save_Context::entry( array( 'type' => 'single', 'value' => 'x' ) )
		);
		$this->assertSame(
			array( 'type' => 'rich', 'value' => '' ),
			Documentate_Document_Save_Context::entry( array( 'type' => 'inventado' ) )
		);
	}

	/**
	 * Rows are paired by index; anything else yields no stored row.
	 */
	public function test_item_at_pairs_rows_by_index() {
		$rows = array( array( 'a' => '1' ), array( 'a' => '2' ) );

		$this->assertSame( array( 'a' => '2' ), Documentate_Document_Save_Context::item_at( $rows, '1' ) );
		$this->assertSame( array(), Documentate_Document_Save_Context::item_at( $rows, 'x' ) );
		$this->assertSame( array(), Documentate_Document_Save_Context::item_at( $rows, 7 ) );
		$this->assertSame( array(), Documentate_Document_Save_Context::item_at( array( 0 => 'no es fila' ), 0 ) );
	}

	/**
	 * A column the request may not write keeps its shape.
	 */
	public function test_column_keeps_the_shape_of_the_stored_value() {
		$this->assertSame( 'x', Documentate_Document_Save_Context::column( 'x', 'single' ) );
		$this->assertSame( '', Documentate_Document_Save_Context::column( null, 'single' ) );
		$this->assertSame( array( array( 'a' => '1' ) ), Documentate_Document_Save_Context::column( array( array( 'a' => '1' ) ), 'array' ) );
		$this->assertSame( array(), Documentate_Document_Save_Context::column( 'x', 'array' ) );
	}
}
