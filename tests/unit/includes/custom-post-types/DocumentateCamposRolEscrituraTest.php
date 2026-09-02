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
			),
		);
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
