<?php
/**
 * Tests for Documentate_Field_Roles.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_Field_Roles
 */
class DocumentateFieldRolesTest extends WP_UnitTestCase {

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
	private $management_id;

	/**
	 * Área (author).
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Users for the three roles.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Reset the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * field_role() normalises the attribute and its alias.
	 *
	 * @dataProvider role_provider
	 *
	 * @param array  $field    Row or raw record.
	 * @param string $expected Expected rol.
	 */
	public function test_field_role( $field, $expected ) {
		$this->assertSame( $expected, Documentate_Field_Roles::field_role( $field ) );
	}

	/**
	 * Cases for field_role().
	 *
	 * @return array
	 */
	public function role_provider() {
		return array(
			'no role' => array( array( 'slug' => 'objeto' ), 'area' ),
			'area' => array( array( 'rol' => 'area' ), 'area' ),
			'gestion' => array( array( 'rol' => 'gestion' ), 'gestion' ),
			'uppercase and accent' => array( array( 'rol' => ' GESTIÓN ' ), 'gestion' ),
			'alias role' => array( array( 'role' => 'gestion' ), 'gestion' ),
			'rol wins over the alias' => array( array( 'rol' => 'area', 'role' => 'gestion' ), 'area' ),
			'unknown value' => array( array( 'rol' => 'otro' ), 'area' ),
			'not a string' => array( array( 'rol' => true ), 'area' ),
		);
	}

	/**
	 * Gestión and administración see everything; the área only its rows.
	 */
	public function test_can_view_by_role() {
		$area = array(
			'slug' => 'objeto',
			'rol' => 'area',
		);
		$management = array(
			'slug' => 'numero_resolucion',
			'rol' => 'gestion',
		);

		wp_set_current_user( $this->area_id );
		$this->assertTrue( Documentate_Field_Roles::can_view( $area ) );
		$this->assertFalse( Documentate_Field_Roles::can_view( $management ) );

		wp_set_current_user( $this->management_id );
		$this->assertTrue( Documentate_Field_Roles::can_view( $area ) );
		$this->assertTrue( Documentate_Field_Roles::can_view( $management ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Field_Roles::can_view( $area ) );
		$this->assertTrue( Documentate_Field_Roles::can_view( $management ) );

		wp_set_current_user( 0 );
		$this->assertTrue( Documentate_Field_Roles::can_view( $area ) );
		$this->assertFalse( Documentate_Field_Roles::can_view( $management ) );
	}

	/**
	 * An explicit user ID is checked instead of the current user.
	 */
	public function test_can_view_with_an_explicit_user() {
		$management = array( 'rol' => 'gestion' );

		wp_set_current_user( $this->area_id );
		$this->assertTrue( Documentate_Field_Roles::can_view( $management, $this->management_id ) );
		$this->assertTrue( Documentate_Field_Roles::can_view( $management, $this->admin_id ) );

		wp_set_current_user( $this->admin_id );
		$this->assertFalse( Documentate_Field_Roles::can_view( $management, $this->area_id ) );
	}

	/**
	 * group_by_role() splits by rol and keeps the order inside each group.
	 */
	public function test_group_by_role_keeps_the_order() {
		$rows = array(
			array( 'slug' => 'a' ),
			array(
				'slug' => 'b',
				'rol' => 'gestion',
			),
			'no es una fila',
			array(
				'slug' => 'c',
				'rol' => 'area',
			),
			array(
				'slug' => 'd',
				'role' => 'gestion',
			),
		);

		$groups = Documentate_Field_Roles::group_by_role( $rows );

		$this->assertSame( array( 'area', 'gestion' ), array_keys( $groups ) );
		$this->assertSame( array( 'a', 'c' ), array_column( $groups['area'], 'slug' ) );
		$this->assertSame( array( 'b', 'd' ), array_column( $groups['gestion'], 'slug' ) );

		$this->assertSame(
			array(
				'area' => array(),
				'gestion' => array(),
			),
			Documentate_Field_Roles::group_by_role( array() )
		);
	}

	/**
	 * type_has_management(): the term meta flag or any gestión field in the schema.
	 */
	public function test_type_has_management_by_meta_and_by_schema() {
		$term = wp_insert_term( 'Tipo rol ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		$storage = new SchemaStorage();

		$this->assertFalse( Documentate_Field_Roles::type_has_management( 0 ) );
		$this->assertFalse( Documentate_Field_Roles::type_has_management( $term_id ) );

		update_term_meta( $term_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ) );
		delete_term_meta( $term_id, 'documentate_type_con_gestion' );
		$this->assertFalse( Documentate_Field_Roles::type_has_management( $term_id ) );

		$storage->save_schema( $term_id, $this->schema( array( 'rol' => 'area' ), array(), array() ) );
		$this->assertFalse( Documentate_Field_Roles::type_has_management( $term_id ), 'A schema without gestión fields does not activate the step.' );

		$storage->save_schema( $term_id, $this->schema( array( 'rol' => 'gestion' ), array(), array() ) );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ), 'A gestión field activates the step.' );

		$storage->save_schema( $term_id, $this->schema( array(), array( 'rol' => 'gestion' ), array() ) );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ), 'A gestión block activates the step.' );

		$storage->save_schema( $term_id, $this->schema( array(), array(), array( 'rol' => 'gestion' ) ) );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ), 'A gestión field inside an área block activates the step.' );

		$this->assertTrue( Documentate_Document_Data::type_has_management( $term_id ) );
		$doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento con gestión',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $doc_id, array( $term_id ), 'documentate_doc_type', false );
		$this->assertTrue( Documentate_Document_Data::has_management( $doc_id ) );
	}

	/**
	 * The schema answer is memoised per type; the type flag is always fresh.
	 */
	public function test_type_has_management_memoises_the_schema_until_the_type_is_forgotten() {
		$term = wp_insert_term( 'Tipo memo ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];

		( new SchemaStorage() )->save_schema( $term_id, $this->schema( array( 'rol' => 'gestion' ), array(), array() ) );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ) );

		// Removing the schema behind the storage class leaves the answer memoised.
		delete_term_meta( $term_id, '_documentate_schema_v2' );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ), 'The schema is walked once per request.' );

		Documentate_Field_Roles::forget_type( $term_id );
		$this->assertFalse( Documentate_Field_Roles::type_has_management( $term_id ) );

		// The type flag is read on every call, memoised schema or not.
		update_term_meta( $term_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Field_Roles::type_has_management( $term_id ) );

		Documentate_Field_Roles::forget_type();
		delete_term_meta( $term_id, 'documentate_type_con_gestion' );
		$this->assertFalse( Documentate_Field_Roles::type_has_management( $term_id ), 'Forgetting every type re-reads the schema.' );
	}

	/**
	 * A block hides the columns of its item schema the user may not see.
	 */
	public function test_filter_item_schema_drops_the_management_columns() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$filtered = Documentate_Field_Roles::filter_item_schema(
			array(
				'code' => array( 'type' => 'single' ),
				'importe' => array( 'type' => 'single', 'rol' => 'gestion' ),
				'conceptos' => array(
					'type' => 'array',
					'item_schema' => array(
						'concepto' => array( 'type' => 'single' ),
						'total' => array( 'type' => 'single', 'rol' => 'gestion' ),
					),
				),
				'suelto' => 'no es un array',
			)
		);

		$this->assertSame( array( 'code', 'conceptos' ), array_keys( $filtered ) );
		$this->assertSame( array( 'concepto' ), array_keys( $filtered['conceptos']['item_schema'] ) );
	}

	/**
	 * Build a v2 schema with one field, one repeater and one repeater field.
	 *
	 * @param array $field_extra    Extra keys of the scalar field.
	 * @param array $repeater_extra Extra keys of the repeater.
	 * @param array $sub_extra      Extra keys of the repeater field.
	 * @return array
	 */
	private function schema( array $field_extra, array $repeater_extra, array $sub_extra ) {
		return array(
			'version' => 2,
			'fields' => array(
				array_merge(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'type' => 'text',
					),
					$field_extra
				),
			),
			'repeaters' => array(
				array_merge(
					array(
						'name' => 'anexos',
						'slug' => 'anexos',
						'fields' => array(
							array_merge(
								array(
									'name' => 'code',
									'slug' => 'code',
									'type' => 'text',
								),
								$sub_extra
							),
						),
					),
					$repeater_extra
				),
			),
			'meta' => array(
				'template_type' => 'odt',
				'template_name' => 'rol.odt',
				'hash' => md5( 'rol' ),
				'parsed_at' => current_time( 'mysql' ),
			),
		);
	}
}
