<?php
/**
 * Tests for Documentate_Campos_Rol.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_Campos_Rol
 */
class DocumentateCamposRolTest extends WP_UnitTestCase {

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
	 * Users for the three roles.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
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
	 * rol_del_campo() normalises the attribute and its alias.
	 *
	 * @dataProvider rol_provider
	 *
	 * @param array  $campo    Row or raw record.
	 * @param string $esperado Expected rol.
	 */
	public function test_rol_del_campo( $campo, $esperado ) {
		$this->assertSame( $esperado, Documentate_Campos_Rol::rol_del_campo( $campo ) );
	}

	/**
	 * Cases for rol_del_campo().
	 *
	 * @return array
	 */
	public function rol_provider() {
		return array(
			'sin rol' => array( array( 'slug' => 'objeto' ), 'area' ),
			'area' => array( array( 'rol' => 'area' ), 'area' ),
			'gestion' => array( array( 'rol' => 'gestion' ), 'gestion' ),
			'mayúsculas y acento' => array( array( 'rol' => ' GESTIÓN ' ), 'gestion' ),
			'alias role' => array( array( 'role' => 'gestion' ), 'gestion' ),
			'rol gana al alias' => array( array( 'rol' => 'area', 'role' => 'gestion' ), 'area' ),
			'valor desconocido' => array( array( 'rol' => 'otro' ), 'area' ),
			'no es texto' => array( array( 'rol' => true ), 'area' ),
		);
	}

	/**
	 * Gestión and administración see everything; the área only its rows.
	 */
	public function test_puede_ver_por_rol() {
		$area = array(
			'slug' => 'objeto',
			'rol' => 'area',
		);
		$gestion = array(
			'slug' => 'numero_resolucion',
			'rol' => 'gestion',
		);

		wp_set_current_user( $this->area_id );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $area ) );
		$this->assertFalse( Documentate_Campos_Rol::puede_ver( $gestion ) );

		wp_set_current_user( $this->gestion_id );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $area ) );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $gestion ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $area ) );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $gestion ) );

		wp_set_current_user( 0 );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $area ) );
		$this->assertFalse( Documentate_Campos_Rol::puede_ver( $gestion ) );
	}

	/**
	 * An explicit user ID is checked instead of the current user.
	 */
	public function test_puede_ver_con_usuario_explicito() {
		$gestion = array( 'rol' => 'gestion' );

		wp_set_current_user( $this->area_id );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $gestion, $this->gestion_id ) );
		$this->assertTrue( Documentate_Campos_Rol::puede_ver( $gestion, $this->admin_id ) );

		wp_set_current_user( $this->admin_id );
		$this->assertFalse( Documentate_Campos_Rol::puede_ver( $gestion, $this->area_id ) );
	}

	/**
	 * agrupar() splits by rol and keeps the order inside each group.
	 */
	public function test_agrupar_mantiene_el_orden() {
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

		$grupos = Documentate_Campos_Rol::agrupar( $rows );

		$this->assertSame( array( 'area', 'gestion' ), array_keys( $grupos ) );
		$this->assertSame( array( 'a', 'c' ), array_column( $grupos['area'], 'slug' ) );
		$this->assertSame( array( 'b', 'd' ), array_column( $grupos['gestion'], 'slug' ) );

		$this->assertSame(
			array(
				'area' => array(),
				'gestion' => array(),
			),
			Documentate_Campos_Rol::agrupar( array() )
		);
	}

	/**
	 * tipo_con_gestion(): the term meta flag or any gestión field in the schema.
	 */
	public function test_tipo_con_gestion_por_meta_y_por_esquema() {
		$term = wp_insert_term( 'Tipo rol ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		$storage = new SchemaStorage();

		$this->assertFalse( Documentate_Campos_Rol::tipo_con_gestion( 0 ) );
		$this->assertFalse( Documentate_Campos_Rol::tipo_con_gestion( $term_id ) );

		update_term_meta( $term_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Campos_Rol::tipo_con_gestion( $term_id ) );
		delete_term_meta( $term_id, 'documentate_type_con_gestion' );
		$this->assertFalse( Documentate_Campos_Rol::tipo_con_gestion( $term_id ) );

		$storage->save_schema( $term_id, $this->schema( array( 'rol' => 'area' ), array(), array() ) );
		$this->assertFalse( Documentate_Campos_Rol::tipo_con_gestion( $term_id ), 'A schema without gestión fields does not activate the step.' );

		$storage->save_schema( $term_id, $this->schema( array( 'rol' => 'gestion' ), array(), array() ) );
		$this->assertTrue( Documentate_Campos_Rol::tipo_con_gestion( $term_id ), 'A gestión field activates the step.' );

		$storage->save_schema( $term_id, $this->schema( array(), array( 'rol' => 'gestion' ), array() ) );
		$this->assertTrue( Documentate_Campos_Rol::tipo_con_gestion( $term_id ), 'A gestión block activates the step.' );

		$storage->save_schema( $term_id, $this->schema( array(), array(), array( 'rol' => 'gestion' ) ) );
		$this->assertTrue( Documentate_Campos_Rol::tipo_con_gestion( $term_id ), 'A gestión field inside an área block activates the step.' );

		$this->assertTrue( Documentate_Documento::tipo_con_gestion( $term_id ) );
		$doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento con gestión',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $doc_id, array( $term_id ), 'documentate_doc_type', false );
		$this->assertTrue( Documentate_Documento::con_gestion( $doc_id ) );
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
