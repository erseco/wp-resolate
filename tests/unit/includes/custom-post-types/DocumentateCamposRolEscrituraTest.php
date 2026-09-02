<?php
/**
 * Write enforcement of the fields by rol.
 *
 * Hiding a gestión field from the área is not enough: a crafted request could
 * still post it. Both the meta saver and the content writer must treat a
 * field the current user cannot see as not posted, keeping the stored value.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * @covers Documentate_Document_Meta_Saver
 * @covers Documentate_Document_Content_Writer
 */
class DocumentateCamposRolEscrituraTest extends WP_UnitTestCase {

	/**
	 * Document under test (draft, owned by the área user).
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Gestión documental (editor).
	 *
	 * @var int
	 */
	private $gestion_id;

	/**
	 * Área (author).
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Type with an área field, a gestión field and a gestión block; a document with stored values.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		// Restricted users only reach documents inside their scope (área category).
		$area = wp_insert_term( 'Área escritura ' . uniqid(), 'category' );
		$area_cat = (int) $area['term_id'];
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $area_cat );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $area_cat );

		$term = wp_insert_term( 'Resolución escritura ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'type' => 'text',
					),
					array(
						'name' => 'numero_resolucion',
						'slug' => 'numero_resolucion',
						'type' => 'text',
						'rol' => 'gestion',
					),
				),
				'repeaters' => array(
					array(
						'name' => 'servicios',
						'slug' => 'servicios',
						'rol' => 'gestion',
						'fields' => array(
							array(
								'name' => 'proveedor',
								'slug' => 'proveedor',
								'type' => 'text',
								'rol' => 'gestion',
							),
						),
					),
					// Área block with one column gestión documental owns.
					array(
						'name' => 'anexos',
						'slug' => 'anexos',
						'fields' => array(
							array(
								'name' => 'code',
								'slug' => 'code',
								'type' => 'text',
							),
							array(
								'name' => 'importe',
								'slug' => 'importe',
								'type' => 'text',
								'rol' => 'gestion',
							),
						),
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'escritura.odt',
					'hash' => md5( 'escritura' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento escritura',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_post_terms( $this->doc_id, array( $term_id ), 'documentate_doc_type', false );
		wp_set_post_terms( $this->doc_id, array( $area_cat ), 'category', false );
		$this->restore_stored_values();
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
	 * Put the original values back in meta.
	 *
	 * @return void
	 */
	private function restore_stored_values() {
		update_post_meta( $this->doc_id, 'documentate_field_objeto', 'Original' );
		update_post_meta( $this->doc_id, 'documentate_field_numero_resolucion', '118/2026' );
		update_post_meta(
			$this->doc_id,
			'documentate_field_servicios',
			Documentate_Document_Content_Writer::encode_array_field_items( array( array( 'proveedor' => 'Acme' ) ) )
		);
		update_post_meta(
			$this->doc_id,
			'documentate_field_anexos',
			Documentate_Document_Content_Writer::encode_array_field_items( array( array( 'code' => 'A1', 'importe' => '500' ) ) )
		);
	}

	/**
	 * Post every field, including the gestión ones, as the given user.
	 *
	 * @param int $user_id Acting user.
	 * @return void
	 */
	private function post_as( $user_id ) {
		wp_set_current_user( $user_id );
		$_POST = array(
			'documentate_sections_nonce' => wp_create_nonce( 'documentate_sections_nonce' ),
			'documentate_field_objeto' => 'Modificado',
			'documentate_field_numero_resolucion' => '999/2099',
			'tpl_fields' => array(
				'servicios' => array(
					array( 'proveedor' => 'Intruso' ),
				),
				'anexos' => array(
					array(
						'code' => 'A2',
						'importe' => '9999',
					),
				),
			),
		);
	}

	/**
	 * Compose the post_content the current request would store.
	 *
	 * @param string $post_content Content the request carries, if any.
	 * @return string
	 */
	private function composed_content( $post_content = '' ) {
		$data = Documentate_Document_Content_Writer::filter_post_data_compose_content(
			array( 'post_type' => 'documentate_document' ),
			array(
				'ID' => $this->doc_id,
				'post_content' => $post_content,
			)
		);

		return (string) $data['post_content'];
	}

	/**
	 * Rows of a repeater in a composed post_content.
	 *
	 * @param string $content Composed post_content.
	 * @param string $slug    Repeater slug.
	 * @return array<int,array<string,string>>
	 */
	private function rows_in( $content, $slug ) {
		$parsed = Documents_Meta_Handler::parse_structured_content( $content );
		if ( ! isset( $parsed[ $slug ]['value'] ) ) {
			return array();
		}

		// The writer slashes the JSON for wp_insert_post(), which unslashes it
		// again; calling the filter directly leaves it slashed.
		return Documents_Meta_Handler::decode_array_field_value( wp_unslash( (string) $parsed[ $slug ]['value'] ) );
	}

	/**
	 * Stored provider names of the servicios block.
	 *
	 * @return string[]
	 */
	private function stored_providers() {
		$items = Documents_Meta_Handler::decode_array_field_value( get_post_meta( $this->doc_id, 'documentate_field_servicios', true ) );

		return array_column( (array) $items, 'proveedor' );
	}

	/**
	 * The saver keeps the gestión values when the área posts them.
	 */
	public function test_saver_ignores_gestion_fields_posted_by_area() {
		$this->post_as( $this->area_id );
		$this->assertTrue( current_user_can( 'edit_post', $this->doc_id ), 'The área owns the draft.' );

		( new Documentate_Document_Meta_Saver() )->save_meta_boxes( $this->doc_id );

		$this->assertSame( 'Modificado', get_post_meta( $this->doc_id, 'documentate_field_objeto', true ), 'Área fields are saved.' );
		$this->assertSame( '118/2026', get_post_meta( $this->doc_id, 'documentate_field_numero_resolucion', true ), 'Gestión fields keep their value.' );
		$this->assertSame( array( 'Acme' ), $this->stored_providers(), 'Gestión blocks keep their rows.' );
	}

	/**
	 * The saver writes the gestión values for gestión and administración.
	 *
	 * @dataProvider gestion_users
	 *
	 * @param string $who Property holding the user ID.
	 */
	public function test_saver_writes_gestion_fields_for( $who ) {
		$this->post_as( $this->$who );

		( new Documentate_Document_Meta_Saver() )->save_meta_boxes( $this->doc_id );

		$this->assertSame( 'Modificado', get_post_meta( $this->doc_id, 'documentate_field_objeto', true ) );
		$this->assertSame( '999/2099', get_post_meta( $this->doc_id, 'documentate_field_numero_resolucion', true ) );
		$this->assertSame( array( 'Intruso' ), $this->stored_providers() );
	}

	/**
	 * The writer composes the stored gestión values when the área posts them.
	 */
	public function test_writer_keeps_stored_gestion_values_for_area() {
		$this->post_as( $this->area_id );

		$data = Documentate_Document_Content_Writer::filter_post_data_compose_content(
			array( 'post_type' => 'documentate_document' ),
			array(
				'ID' => $this->doc_id,
				'post_content' => '',
			)
		);
		$content = (string) $data['post_content'];
		$parsed = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertSame( 'Modificado', $parsed['objeto']['value'] );
		$this->assertSame( '118/2026', $parsed['numero_resolucion']['value'] );
		$this->assertStringContainsString( 'Acme', $content );
		$this->assertStringNotContainsString( 'Intruso', $content );
	}

	/**
	 * The writer composes the posted gestión values for gestión and administración.
	 *
	 * @dataProvider gestion_users
	 *
	 * @param string $who Property holding the user ID.
	 */
	public function test_writer_takes_posted_gestion_values_for( $who ) {
		$this->post_as( $this->$who );

		$data = Documentate_Document_Content_Writer::filter_post_data_compose_content(
			array( 'post_type' => 'documentate_document' ),
			array(
				'ID' => $this->doc_id,
				'post_content' => '',
			)
		);
		$content = (string) $data['post_content'];
		$parsed = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertSame( 'Modificado', $parsed['objeto']['value'] );
		$this->assertSame( '999/2099', $parsed['numero_resolucion']['value'] );
		$this->assertStringContainsString( 'Intruso', $content );
		$this->assertStringNotContainsString( 'Acme', $content );
	}

	/**
	 * Naming another document type does not change which fields are protected.
	 *
	 * The schema is resolved from the type the document carries, so a request
	 * pointing at a type that does not exist (or at another one) cannot leave
	 * the gestión fields unguarded.
	 */
	public function test_writer_ignores_a_document_type_posted_by_the_area() {
		$this->post_as( $this->area_id );
		$_POST['documentate_doc_type'] = 999999;

		$content = $this->composed_content();
		$parsed = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertSame( 'Modificado', $parsed['objeto']['value'], 'Área fields are still saved.' );
		$this->assertSame( '118/2026', $parsed['numero_resolucion']['value'], 'The gestión field keeps its value.' );
		$this->assertStringNotContainsString( 'Intruso', $content );
	}

	/**
	 * A field of another type the área may not write never reaches the content.
	 *
	 * The slug is unknown to the document's own schema, so it would otherwise
	 * travel through the carried-over/posted paths.
	 */
	public function test_writer_drops_a_gestion_field_of_another_type() {
		$otro = wp_insert_term( 'Propuesta escritura ' . uniqid(), 'documentate_doc_type' );
		$otro_id = (int) $otro['term_id'];
		( new SchemaStorage() )->save_schema(
			$otro_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'partida',
						'slug' => 'partida',
						'type' => 'text',
						'rol' => 'gestion',
					),
				),
			)
		);

		$this->post_as( $this->area_id );
		$_POST['documentate_doc_type'] = $otro_id;
		$_POST['documentate_field_partida'] = '18.02.322C.227.06';

		$content = $this->composed_content();

		$this->assertStringNotContainsString( '18.02.322C.227.06', $content );
	}

	/**
	 * A crafted post_content does not become the "stored" value of a hidden field.
	 *
	 * Core copies $_POST['content'] into post_content and kses keeps HTML
	 * comments, so the request can carry a forged field fragment.
	 */
	public function test_writer_ignores_gestion_values_forged_in_the_posted_content() {
		$this->post_as( $this->area_id );

		$forged = Documents_Meta_Handler::build_structured_field_fragment( 'numero_resolucion', 'single', 'RES-999/2099' )
			. "\n\n"
			. Documents_Meta_Handler::build_structured_field_fragment( 'servicios', 'array', wp_json_encode( array( array( 'proveedor' => 'Intruso' ) ) ) );

		$content = $this->composed_content( $forged );
		$parsed = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertSame( '118/2026', $parsed['numero_resolucion']['value'] );
		$this->assertSame( 'Modificado', $parsed['objeto']['value'] );
		$this->assertSame( array( 'Acme' ), array_column( $this->rows_in( $content, 'servicios' ), 'proveedor' ) );
	}

	/**
	 * Inside a block the área owns, a gestión column still keeps its value.
	 */
	public function test_writer_keeps_gestion_columns_of_an_area_block() {
		$this->post_as( $this->area_id );

		$rows = $this->rows_in( $this->composed_content(), 'anexos' );

		$this->assertSame( 'A2', $rows[0]['code'], 'The área column is saved.' );
		$this->assertSame( '500', $rows[0]['importe'], 'The gestión column keeps its value.' );
	}

	/**
	 * Gestión does write the columns it owns inside an área block.
	 */
	public function test_writer_writes_gestion_columns_of_an_area_block_for_gestion() {
		$this->post_as( $this->gestion_id );

		$rows = $this->rows_in( $this->composed_content(), 'anexos' );

		$this->assertSame( 'A2', $rows[0]['code'] );
		$this->assertSame( '9999', $rows[0]['importe'] );
	}

	/**
	 * The saver applies the same rule to the columns of an área block.
	 */
	public function test_saver_keeps_gestion_columns_of_an_area_block() {
		$this->post_as( $this->area_id );

		( new Documentate_Document_Meta_Saver() )->save_meta_boxes( $this->doc_id );

		$rows = Documents_Meta_Handler::decode_array_field_value( get_post_meta( $this->doc_id, 'documentate_field_anexos', true ) );

		$this->assertSame( 'A2', $rows[0]['code'] );
		$this->assertSame( '500', $rows[0]['importe'] );
	}

	/**
	 * The repeater editor does not draw the columns the área may not write.
	 */
	public function test_repeater_hides_gestion_columns_from_the_area() {
		wp_set_current_user( $this->area_id );
		$schema = Documents_Meta_Handler::normalize_array_item_schema(
			array(
				'item_schema' => array(
					'code' => array( 'label' => 'Código', 'type' => 'single' ),
					'importe' => array( 'label' => 'Importe', 'type' => 'single', 'rol' => 'gestion' ),
				),
			)
		);

		ob_start();
		Documentate_Document_Repeater_Field::render_array_field( 'anexos', 'Anexos', '', $schema, array( array( 'code' => 'A1', 'importe' => '500' ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'tpl_fields[anexos][0][code]', $html );
		$this->assertStringNotContainsString( 'tpl_fields[anexos][0][importe]', $html );
	}

	/**
	 * Users allowed to write gestión fields.
	 *
	 * @return array
	 */
	public function gestion_users() {
		return array(
			'gestión' => array( 'gestion_id' ),
			'administración' => array( 'admin_id' ),
		);
	}
}
