<?php
/**
 * Tests for the prefix, the gestión flag and the rol badges of the doc-types admin.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Doc_Type_Workflow_Fields
 * @covers Documentate_Doc_Types_Admin
 */
class DocumentateDocTypesRoleTest extends Documentate_Test_Base {

	/**
	 * Workflow fields instance.
	 *
	 * @var Documentate_Doc_Type_Workflow_Fields
	 */
	private $fields;

	/**
	 * Doc types admin instance (schema preview).
	 *
	 * @var Documentate_Doc_Types_Admin
	 */
	private $admin;

	/**
	 * Term under test.
	 *
	 * @var int
	 */
	private $term_id;

	/**
	 * Set up an administrator, the screen and a term.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-documentate_doc_type' );

		$this->fields = new Documentate_Doc_Type_Workflow_Fields();
		$this->admin = new Documentate_Doc_Types_Admin();

		$term = wp_insert_term( 'Tipo prefijo ' . uniqid(), 'documentate_doc_type' );
		$this->term_id = (int) $term['term_id'];
	}

	/**
	 * Reset the request and the user.
	 */
	public function tear_down() {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Post a term save with the core nonce.
	 *
	 * @param array $fields Posted fields.
	 * @return void
	 */
	private function save( array $fields ) {
		$_POST = array_merge(
			array( '_wpnonce' => wp_create_nonce( 'update-tag_' . $this->term_id ) ),
			$fields
		);
		$this->fields->save_term( $this->term_id );
	}

	/**
	 * The prefix is stored uppercase, sanitised and cut to 6 characters.
	 *
	 * @dataProvider prefix_provider
	 *
	 * @param string $posted   Posted prefix.
	 * @param string $expected Stored prefix.
	 */
	public function test_save_term_normalises_the_prefix( $posted, $expected ) {
		$this->save( array( 'documentate_type_prefijo' => $posted ) );

		$this->assertSame( $expected, get_term_meta( $this->term_id, 'documentate_type_prefijo', true ) );
	}

	/**
	 * Cases for the prefix.
	 *
	 * @return array
	 */
	public function prefix_provider() {
		return array(
			'lowercase' => array( 'res', 'RES' ),
			'too long' => array( 'resolucion', 'RESOLU' ),
			'spaces and symbols' => array( ' p-g · <b>1</b> ', 'PG1' ),
			'accents' => array( 'ñu', 'ÑU' ),
			'empty' => array( '', '' ),
		);
	}

	/**
	 * The management flag is '1' when checked and '' otherwise.
	 */
	public function test_save_term_stores_the_management_flag() {
		$this->save( array( 'documentate_type_con_gestion' => '1' ) );
		$this->assertSame( '1', get_term_meta( $this->term_id, 'documentate_type_con_gestion', true ) );
		$this->assertTrue( Documentate_Document_Data::type_has_management( $this->term_id ) );

		$this->save( array() );
		$this->assertSame( '', get_term_meta( $this->term_id, 'documentate_type_con_gestion', true ) );
		$this->assertFalse( Documentate_Document_Data::type_has_management( $this->term_id ) );
	}

	/**
	 * The fields hook into the taxonomy screens before the template fields.
	 */
	public function test_hooks_are_registered_before_the_template_fields() {
		$this->assertSame( 5, has_action( 'documentate_doc_type_add_form_fields', array( $this->fields, 'add_fields' ) ) );
		$this->assertSame( 5, has_action( 'documentate_doc_type_edit_form_fields', array( $this->fields, 'edit_fields' ) ) );
		$this->assertSame( 10, has_action( 'created_documentate_doc_type', array( $this->fields, 'save_term' ) ) );
		$this->assertSame( 10, has_action( 'edited_documentate_doc_type', array( $this->fields, 'save_term' ) ) );
	}

	/**
	 * The add-term nonce is accepted too.
	 */
	public function test_save_term_accepts_the_add_tag_nonce() {
		$_POST = array(
			'_wpnonce_add-tag' => wp_create_nonce( 'add-tag' ),
			'taxonomy' => 'documentate_doc_type',
			'documentate_type_prefijo' => 'hc',
			'documentate_type_con_gestion' => '1',
		);
		$this->fields->save_term( $this->term_id );

		$this->assertSame( 'HC', get_term_meta( $this->term_id, 'documentate_type_prefijo', true ) );
		$this->assertSame( '1', get_term_meta( $this->term_id, 'documentate_type_con_gestion', true ) );
	}

	/**
	 * The generic add-term nonce of another taxonomy is not enough.
	 */
	public function test_save_term_ignores_the_add_tag_nonce_of_another_taxonomy() {
		update_term_meta( $this->term_id, 'documentate_type_prefijo', 'RES' );
		update_term_meta( $this->term_id, 'documentate_type_con_gestion', '1' );

		$_POST = array(
			'_wpnonce_add-tag' => wp_create_nonce( 'add-tag' ),
			'taxonomy' => 'category',
			'documentate_type_prefijo' => 'XX',
		);
		$this->fields->save_term( $this->term_id );

		$this->assertSame( 'RES', get_term_meta( $this->term_id, 'documentate_type_prefijo', true ) );
		$this->assertSame( '1', get_term_meta( $this->term_id, 'documentate_type_con_gestion', true ) );
	}

	/**
	 * A user without the taxonomy capability writes nothing, nonce or not.
	 *
	 * An editor holds a valid "add-tag" nonce from the Categories screen, so
	 * the nonce alone is not an authorisation signal for this taxonomy.
	 */
	public function test_save_term_ignores_users_without_the_taxonomy_capability() {
		update_term_meta( $this->term_id, 'documentate_type_prefijo', 'RES' );
		update_term_meta( $this->term_id, 'documentate_type_con_gestion', '1' );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array(
			'_wpnonce_add-tag' => wp_create_nonce( 'add-tag' ),
			'taxonomy' => 'documentate_doc_type',
			'documentate_type_prefijo' => 'XX',
		);
		$this->fields->save_term( $this->term_id );

		$this->assertSame( 'RES', get_term_meta( $this->term_id, 'documentate_type_prefijo', true ) );
		$this->assertSame( '1', get_term_meta( $this->term_id, 'documentate_type_con_gestion', true ) );
	}

	/**
	 * Without a valid nonce nothing is written.
	 */
	public function test_save_term_ignores_requests_without_nonce() {
		$_POST = array( 'documentate_type_prefijo' => 'RES' );
		$this->fields->save_term( $this->term_id );

		$this->assertSame( '', get_term_meta( $this->term_id, 'documentate_type_prefijo', true ) );
	}

	/**
	 * The edit screen shows the stored prefix and the checked flag with its note.
	 */
	public function test_edit_fields_renders_prefix_and_management_controls() {
		update_term_meta( $this->term_id, 'documentate_type_prefijo', 'CONV' );
		update_term_meta( $this->term_id, 'documentate_type_con_gestion', '1' );

		ob_start();
		$this->fields->edit_fields( get_term( $this->term_id, 'documentate_doc_type' ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="documentate_type_prefijo" value="CONV" maxlength="6"', $html );
		$this->assertStringContainsString( 'Prefijo', $html );
		$this->assertMatchesRegularExpression( '/name="documentate_type_con_gestion" value="1" checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'Pasa por gestión documental', $html );
		$this->assertStringContainsString( 'Cualquier campo con rol=&#039;gestion&#039; en la plantilla activa este paso.', $html );
	}

	/**
	 * The add screen shows empty controls.
	 */
	public function test_add_fields_renders_prefix_and_management_controls() {
		ob_start();
		$this->fields->add_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="documentate_type_prefijo" value="" maxlength="6"', $html );
		$this->assertStringContainsString( 'name="documentate_type_con_gestion" value="1" />', $html );
		$this->assertStringNotContainsString( "checked='checked'", $html );
		$this->assertStringContainsString( 'Pasa por gestión documental', $html );
	}

	/**
	 * The schema preview marks the entries gestión documental fills in.
	 */
	public function test_schema_preview_shows_the_role_badge() {
		$schema = array(
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
					'title' => 'Nº de resolución',
					'rol' => 'gestion',
				),
			),
			'repeaters' => array(
				array(
					'name' => 'servicios',
					'slug' => 'servicios',
					'title' => 'Servicios',
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
			'meta' => array(),
		);

		$method = new ReflectionMethod( Documentate_Doc_Types_Admin::class, 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->admin, $schema );
		$html = ob_get_clean();

		$this->assertSame( 3, substr_count( $html, '<span class="documentate-field-rol">gestión</span>' ), 'Field, block and block field carry the badge.' );
		$this->assertMatchesRegularExpression( '/<li>Objeto <span class="documentate-field-type">\(single\)<\/span><\/li>/', $html, 'Área entries carry no badge.' );
		$this->assertMatchesRegularExpression( '/Nº de resolución <span class="documentate-field-type">\(single\)<\/span> <span class="documentate-field-rol">gestión<\/span>/', $html );
	}
}
