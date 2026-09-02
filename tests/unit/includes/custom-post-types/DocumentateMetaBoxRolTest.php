<?php
/**
 * Tests for the grouping by rol of the sections metabox.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_Document_Meta_Boxes
 */
class DocumentateMetaBoxRolTest extends WP_UnitTestCase {

	/**
	 * Document under test.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Área user (author).
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Gestión user (editor).
	 *
	 * @var int
	 */
	private $gestion_id;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * A type with área and gestión fields and a stored gestión value.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->gestion_id ) )->add_cap( Documentate_Roles::CAP_GESTION );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$term = wp_insert_term( 'Resolución rol ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'numero_resolucion',
						'slug' => 'numero_resolucion',
						'type' => 'text',
						'title' => 'Nº de resolución',
						'rol' => 'gestion',
					),
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'type' => 'textarea',
						'title' => 'Objeto',
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
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'rol.odt',
					'hash' => md5( 'rol-metabox' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento rol',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_post_terms( $this->doc_id, array( $term_id ), 'documentate_doc_type', false );
		update_post_meta( $this->doc_id, 'documentate_field_numero_resolucion', 'SECRETO-118/2026' );
		update_post_meta( $this->doc_id, 'documentate_field_objeto', 'Objeto visible' );
	}

	/**
	 * Reset the current user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Render the sections metabox for the current user.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		( new Documentate_Document_Meta_Boxes() )->render_sections_metabox( get_post( $this->doc_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Gestión sees the área rows first, then the heading and the gestión rows.
	 */
	public function test_gestion_sees_both_groups_with_heading() {
		wp_set_current_user( $this->gestion_id );
		$html = $this->render();

		$this->assertStringContainsString( '<h3 class="documentate-seccion-rol">Datos oficiales · los completa gestión documental</h3>', $html );
		$this->assertStringContainsString( 'SECRETO-118/2026', $html );
		$this->assertStringContainsString( 'name="documentate_field_numero_resolucion"', $html );

		$objeto = strpos( $html, 'documentate-field-objeto' );
		$heading = strpos( $html, 'documentate-seccion-rol' );
		$numero = strpos( $html, 'documentate-field-numero_resolucion' );
		$servicios = strpos( $html, 'documentate-field-servicios' );
		$this->assertLessThan( $heading, $objeto, 'Área rows come before the heading.' );
		$this->assertLessThan( $numero, $heading, 'Gestión rows come after the heading.' );
		$this->assertLessThan( $servicios, $heading );

		$this->assertMatchesRegularExpression( '/<tr class="documentate-field documentate-field-numero_resolucion[^"]* documentate-campo-gestion">/', $html );
		$this->assertMatchesRegularExpression( '/<tr class="documentate-field documentate-field-array documentate-field-servicios documentate-campo-gestion">/', $html );
		$this->assertDoesNotMatchRegularExpression( '/documentate-field-objeto[^"]*documentate-campo-gestion/', $html );
	}

	/**
	 * Administración sees the same as gestión.
	 */
	public function test_admin_sees_the_gestion_group() {
		wp_set_current_user( $this->admin_id );
		$html = $this->render();

		$this->assertStringContainsString( 'documentate-seccion-rol', $html );
		$this->assertStringContainsString( 'documentate-campo-gestion', $html );
		$this->assertStringContainsString( 'SECRETO-118/2026', $html );
	}

	/**
	 * The área gets its rows only: no heading, no gestión rows, no leaked value.
	 */
	public function test_area_does_not_see_gestion_rows_nor_their_values() {
		wp_set_current_user( $this->area_id );
		$html = $this->render();

		$this->assertStringContainsString( 'name="documentate_field_objeto"', $html );
		$this->assertStringContainsString( 'Objeto visible', $html );
		$this->assertStringNotContainsString( 'documentate-seccion-rol', $html );
		$this->assertStringNotContainsString( 'documentate-campo-gestion', $html );
		$this->assertStringNotContainsString( 'documentate_field_numero_resolucion', $html );
		$this->assertStringNotContainsString( 'documentate-field-servicios', $html );
		$this->assertStringNotContainsString( 'SECRETO-118/2026', $html, 'Hidden values must not surface, not even as unknown fields.' );
		$this->assertStringNotContainsString( 'documentate-unknown-dynamic', $html );
	}

	/**
	 * A type with gestión fields only renders nothing for the área but the nonce.
	 */
	public function test_area_with_only_gestion_fields_gets_no_table() {
		$term = wp_insert_term( 'Solo gestión ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'expediente',
						'slug' => 'expediente',
						'type' => 'text',
						'rol' => 'gestion',
					),
				),
				'repeaters' => array(),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'g.odt',
					'hash' => md5( 'g' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);
		// The type of an existing document is locked, so a fresh one is used.
		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Solo gestión',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_post_terms( $this->doc_id, array( $term_id ), 'documentate_doc_type', false );
		update_post_meta( $this->doc_id, 'documentate_field_expediente', 'EXP-OCULTO' );

		wp_set_current_user( $this->area_id );
		$html = $this->render();

		$this->assertStringContainsString( 'documentate_sections_nonce', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringNotContainsString( 'documentate_field_expediente', $html );
		$this->assertStringNotContainsString( 'EXP-OCULTO', $html );

		wp_set_current_user( $this->gestion_id );
		$html = $this->render();
		$this->assertStringContainsString( 'documentate-seccion-rol', $html );
		$this->assertStringContainsString( 'EXP-OCULTO', $html );
	}
}
