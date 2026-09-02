<?php
/**
 * Tests for Documentate_Estados.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Estados
 */
class DocumentateEstadosTest extends WP_UnitTestCase {

	/**
	 * Register the statuses before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Estados::registrar();
	}

	/**
	 * A document object in a status, as the list table hands it to the filters.
	 *
	 * The workflow keeps untyped documents in draft on save, so the status
	 * is set on the object rather than persisted.
	 *
	 * @param string $status Post status.
	 * @return WP_Post
	 */
	private function documento_en( $status ) {
		$doc = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$doc->post_status = $status;

		return $doc;
	}

	/**
	 * The en_gestion status is registered with the expected shape.
	 */
	public function test_en_gestion_status_registered() {
		$status = get_post_status_object( 'en_gestion' );

		$this->assertNotNull( $status );
		$this->assertSame( 'En gestión', $status->label );
		$this->assertFalse( $status->public );
		$this->assertTrue( $status->protected );
		$this->assertFalse( $status->exclude_from_search, 'Like pending: "any" queries must still find it.' );
		$this->assertTrue( $status->show_in_admin_all_list );
		$this->assertTrue( $status->show_in_admin_status_list );
		$this->assertSame( 'En gestión <span class="count">(%s)</span>', $status->label_count['singular'] );
		$this->assertSame( 'En gestión <span class="count">(%s)</span>', $status->label_count['plural'] );
		$this->assertSame( 'En gestión <span class="count">(%s)</span>', $status->label_count[0] );
		$this->assertNull( $status->label_count['context'] );
		$this->assertNull( $status->label_count['domain'] );
		$this->assertStringContainsString( '(3)', sprintf( translate_nooped_plural( $status->label_count, 3 ), 3 ) );
	}

	/**
	 * The archived status keeps its shape.
	 */
	public function test_archived_status_registered() {
		$status = get_post_status_object( 'archived' );

		$this->assertNotNull( $status );
		$this->assertSame( 'Archivado', $status->label );
		$this->assertFalse( $status->public );
		$this->assertFalse( $status->show_in_admin_all_list );
		$this->assertTrue( $status->show_in_admin_status_list );
		$this->assertContains( 'en_gestion', get_post_stati( array( 'show_in_admin_all_list' => true ) ) );
		$this->assertNotContains( 'archived', get_post_stati( array( 'show_in_admin_all_list' => true ) ) );
	}

	/**
	 * Labels come in workflow order.
	 */
	public function test_labels_in_workflow_order() {
		$this->assertSame(
			array( 'draft', 'en_gestion', 'pending', 'publish', 'archived' ),
			array_keys( Documentate_Estados::etiquetas() )
		);
		$this->assertSame( 'En revisión', Documentate_Estados::etiquetas()['pending'] );
	}

	/**
	 * The admin list names en_gestion documents; other statuses and post types are untouched.
	 */
	public function test_display_post_states() {
		$doc = $this->documento_en( 'en_gestion' );
		$this->assertSame( array( 'en_gestion' => 'En gestión' ), Documentate_Estados::display_post_states( array(), $doc ) );

		$draft = $this->documento_en( 'draft' );
		$this->assertSame( array( 'x' => 'y' ), Documentate_Estados::display_post_states( array( 'x' => 'y' ), $draft ) );

		$post = self::factory()->post->create_and_get();
		$post->post_status = 'en_gestion';
		$this->assertSame( array(), Documentate_Estados::display_post_states( array(), $post ) );
		$this->assertSame( array(), Documentate_Estados::display_post_states( array(), null ) );
	}

	/**
	 * The metabox message follows the status and the flag each row keys on.
	 */
	public function test_mensaje_metabox() {
		$this->assertSame(
			array( 'success', 'lock', 'El documento está bloqueado. Contacta con administración.' ),
			Documentate_Estados::mensaje_metabox( 'publish', false, false, false )
		);
		$this->assertStringContainsString( 'Devuélvelo a revisión', Documentate_Estados::mensaje_metabox( 'publish', true, false, true )[2] );
		$this->assertSame( 'archive', Documentate_Estados::mensaje_metabox( 'archived', true, false, true )[1] );
		$this->assertStringContainsString( 'Desarchívalo', Documentate_Estados::mensaje_metabox( 'archived', true, false, true )[2] );
		$this->assertStringContainsString( 'Contacta con administración', Documentate_Estados::mensaje_metabox( 'archived', false, false, false )[2] );
		$this->assertSame( 'pending', Documentate_Estados::mensaje_metabox( 'pending', true, true, true )[0] );
		$this->assertStringContainsString( 'Apruébalo o devuélvelo', Documentate_Estados::mensaje_metabox( 'pending', true, true, true )[2] );
		$this->assertStringContainsString( 'Administración lo aprobará', Documentate_Estados::mensaje_metabox( 'pending', false, true, false )[2] );
		$this->assertStringContainsString( 'Completa los datos oficiales', Documentate_Estados::mensaje_metabox( 'en_gestion', false, true, true )[2] );
		$this->assertStringContainsString( 'Ya no puedes modificarlo', Documentate_Estados::mensaje_metabox( 'en_gestion', false, true, false )[2] );
		$this->assertStringContainsString( 'Envía a gestión documental', Documentate_Estados::mensaje_metabox( 'draft', false, true, true )[2] );
		$this->assertStringContainsString( 'Envía a revisión', Documentate_Estados::mensaje_metabox( 'draft', false, false, true )[2] );
		$this->assertNull( Documentate_Estados::mensaje_metabox( 'trash', true, true, true ) );
	}

	/**
	 * Quick Edit disappears from document rows only.
	 */
	public function test_quick_edit_removed_for_documents() {
		$actions = array(
			'edit' => '<a>Editar</a>',
			'inline hide-if-no-js' => '<button>Edición rápida</button>',
			'trash' => '<a>Papelera</a>',
		);

		$doc = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$this->assertSame( array( 'edit', 'trash' ), array_keys( Documentate_Estados::quitar_quick_edit( $actions, $doc ) ) );

		$post = self::factory()->post->create_and_get();
		$this->assertSame( $actions, Documentate_Estados::quitar_quick_edit( $actions, $post ) );
	}

	/**
	 * init() registers both filters, and they run through the real hooks.
	 */
	public function test_init_registers_filters() {
		Documentate_Estados::init();

		$this->assertSame( 10, has_filter( 'display_post_states', array( 'Documentate_Estados', 'display_post_states' ) ) );
		$this->assertSame( 10, has_filter( 'post_row_actions', array( 'Documentate_Estados', 'quitar_quick_edit' ) ) );

		$doc = $this->documento_en( 'en_gestion' );
		$this->assertArrayHasKey( 'en_gestion', apply_filters( 'display_post_states', array(), $doc ) );
		$this->assertArrayNotHasKey( 'inline hide-if-no-js', apply_filters( 'post_row_actions', array( 'inline hide-if-no-js' => 'x' ), $doc ) );
	}
}
