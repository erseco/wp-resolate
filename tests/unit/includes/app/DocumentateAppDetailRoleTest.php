<?php
/**
 * The document view of the app shows each rol what it may see.
 *
 * The editor hides the fields revisión completes and the writer
 * refuses to let the área write them; the read-only view must not hand them
 * over either.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_App_Detail
 */
class DocumentateAppDetailRoleTest extends WP_UnitTestCase {

	/**
	 * Document under test.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Revisión (editor with the capability).
	 *
	 * @var int
	 */
	private $management_id;

	/**
	 * Área (author of the document).
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * A document in gestión with an área value and a gestión value.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();

		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Revisión is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$area = wp_insert_term( 'Área detalle ' . uniqid(), 'category' );
		$area_cat = (int) $area['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $area_cat );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $area_cat );

		$term = wp_insert_term( 'Resolución detalle ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'title' => 'Objeto',
						'type' => 'text',
					),
					array(
						'name' => 'numero_resolucion',
						'slug' => 'numero_resolucion',
						'title' => 'Nº de resolución',
						'type' => 'text',
						'rol' => 'gestion',
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'detalle.odt',
					'hash' => md5( 'detalle' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento detalle',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_post_terms( $this->doc_id, array( $term_id ), 'documentate_doc_type', false );
		wp_set_post_terms( $this->doc_id, array( $area_cat ), 'category', false );

		update_post_meta( $this->doc_id, 'documentate_field_objeto', 'Compra de material' );
		update_post_meta( $this->doc_id, 'documentate_field_numero_resolucion', '118/2026' );

		// The type goes through revisión, so this is where the área
		// waits while the official data is completed.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		wp_update_post(
			array(
				'ID' => $this->doc_id,
				'post_status' => 'en_gestion',
			)
		);
		wp_set_current_user( 0 );
	}

	/**
	 * Reset the user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The área sees its own data and nothing of the official one.
	 */
	public function test_area_does_not_see_the_management_fields() {
		wp_set_current_user( $this->area_id );

		$html = Documentate_App_Detail::render( $this->doc_id );

		$this->assertSame( 'en_gestion', get_post_status( $this->doc_id ), 'The document waits in gestión.' );
		$this->assertStringContainsString( 'Compra de material', $html, 'The área data is shown.' );
		$this->assertStringNotContainsString( '118/2026', $html, 'The resolution number is not.' );
		$this->assertStringNotContainsString( 'Nº de resolución', $html );
	}

	/**
	 * Revisión sees both.
	 */
	public function test_management_sees_the_management_fields() {
		wp_set_current_user( $this->management_id );

		$html = Documentate_App_Detail::render( $this->doc_id );

		$this->assertStringContainsString( 'Compra de material', $html );
		$this->assertStringContainsString( '118/2026', $html );
	}

	/**
	 * Where the site draws the PDF, the document itself heads the view.
	 *
	 * The frame points at the preview handler the export buttons already use,
	 * so the file is streamed behind the same `edit_post` check and the same
	 * nonce; the view opens no way in of its own.
	 */
	public function test_the_document_is_drawn_into_the_view_under_the_native_engine() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );
		wp_set_current_user( $this->management_id );

		$html = Documentate_App_Detail::render( $this->doc_id );

		$this->assertStringContainsString( 'dcta-pdf-visor', $html );
		$this->assertStringContainsString( 'action=documentate_preview', $html );
		$this->assertStringContainsString(
			'dcta-campos-plegados',
			$html,
			'The field summary is kept, folded under the document.'
		);
		$this->assertStringContainsString( 'Compra de material', $html, 'And it still carries the values.' );
		$this->assertStringNotContainsString(
			'Previsualizar PDF',
			$html,
			'A button that opens what is already on screen is one more thing to read.'
		);
		$this->assertStringContainsString( 'Descargar PDF', $html, 'Taking the file away still needs a button.' );
	}

	/**
	 * A converter reaches a service or the browser to produce a PDF, which is
	 * not something to hang a page render on, so the view stays as it was.
	 */
	public function test_a_converter_engine_keeps_the_field_summary_alone() {
		update_option(
			'documentate_settings',
			array(
				'conversion_engine' => 'collabora',
				'collabora_base_url' => 'https://collabora.example.org',
			)
		);
		wp_set_current_user( $this->management_id );

		$html = Documentate_App_Detail::render( $this->doc_id );

		$this->assertStringNotContainsString( 'dcta-pdf-visor', $html );
		$this->assertStringNotContainsString( 'dcta-campos-plegados', $html );
		$this->assertStringContainsString( 'Compra de material', $html );
		$this->assertStringContainsString(
			'Previsualizar PDF',
			$html,
			'With nothing drawn into the page, the preview button is the only way to see it.'
		);
	}

	/**
	 * A document with no type has nothing to draw, whatever the engine.
	 *
	 * The type cannot be taken off a document that has one — the plugin locks
	 * it on first assignment and puts it back — so this is a document that
	 * never got one, which is what a draft looks like before its type is
	 * picked.
	 */
	public function test_a_document_without_a_type_is_not_drawn() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );
		wp_set_current_user( $this->management_id );

		$untyped = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Sin tipo todavía',
				'post_status' => 'draft',
				'post_author' => $this->management_id,
			)
		);

		$html = Documentate_App_Detail::render( $untyped );

		$this->assertStringNotContainsString( 'dcta-pdf-visor', $html );
	}
}
