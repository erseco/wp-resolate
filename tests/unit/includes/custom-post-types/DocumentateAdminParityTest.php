<?php
/**
 * Tests for the wp-admin parity additions of §7: the "Nombre interno" input
 * under the title, the admin list column, the "Anotaciones internas" and
 * "Actividad" metaboxes.
 *
 * @covers Documentate_Document_Admin_Extras
 * @covers Documentate_Document_Meta_Saver
 * @covers Documentate_Document_Admin_List
 */

class DocumentateAdminParityTest extends WP_UnitTestCase {

	/**
	 * Área author.
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Gestión documental user (editor + CAP_GESTION).
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
	 * Admin extras (nombre interno, anotaciones, actividad) under test.
	 *
	 * @var Documentate_Document_Admin_Extras
	 */
	private $admin_extras;

	/**
	 * Meta saver under test.
	 *
	 * @var Documentate_Document_Meta_Saver
	 */
	private $meta_saver;

	/**
	 * Admin list under test.
	 *
	 * @var Documentate_Document_Admin_List
	 */
	private $admin_list;

	/**
	 * Scope category shared by the área and gestión users, and every document
	 * created by crear_documento(): current_user_can( 'edit_post' ) routes
	 * through the scope filter, which denies it outside the user's own scope.
	 *
	 * @var int
	 */
	private $area_term_id;

	/**
	 * Users, a document type with a prefix and the collaborators under test.
	 */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();

		$area = wp_insert_term( 'Área admin parity ' . uniqid(), 'category' );
		$this->area_term_id = (int) $area['term_id'];

		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $this->area_id, Documentate_User_Scope::META_KEY, $this->area_term_id );
		update_user_meta( $this->gestion_id, Documentate_User_Scope::META_KEY, $this->area_term_id );

		$this->admin_extras = new Documentate_Document_Admin_Extras();
		$this->meta_saver = new Documentate_Document_Meta_Saver();
		$this->admin_list = new Documentate_Document_Admin_List();
	}

	/**
	 * Reset globals.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		parent::tear_down();
	}

	/**
	 * Create a document type term with a prefix.
	 *
	 * @param string $prefijo Type prefix.
	 * @return int Term ID.
	 */
	private function crear_tipo( $prefijo = 'RES' ) {
		$term = wp_insert_term( 'Tipo admin parity ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, Documentate_Documento::TERM_META_PREFIJO, $prefijo );

		return $term_id;
	}

	/**
	 * Create a document with a type assigned, created as admin so a status
	 * other than draft is not rerouted by the workflow's role rule.
	 *
	 * @param int    $author Post author.
	 * @param string $status Post status.
	 * @return int Document ID.
	 */
	private function crear_documento( $author, $status = 'draft' ) {
		$term_id = $this->crear_tipo();
		$previo = get_current_user_id();
		wp_set_current_user( $this->admin_id );

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento admin parity',
				'post_status' => 'draft',
				'post_author' => $author,
			)
		);
		wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $term_id );
		wp_set_object_terms( $post_id, $this->area_term_id, 'category' );

		if ( 'draft' !== $status ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) );
		}

		wp_set_current_user( $previo );

		return $post_id;
	}

	/**
	 * Render a method with output buffering.
	 *
	 * @param string $method Method name on $this->admin_extras.
	 * @param mixed  ...$args Arguments.
	 * @return string
	 */
	private function render( $method, ...$args ) {
		ob_start();
		$this->admin_extras->$method( ...$args );

		return (string) ob_get_clean();
	}

	/**
	 * The nombre interno field shows the prefix, the stored value and is enabled.
	 */
	public function test_nombre_interno_field_renders_value_and_prefix() {
		$post_id = $this->crear_documento( $this->area_id );
		Documentate_Documento::guardar_nombre_interno( $post_id, 'Mi documento corto' );
		wp_set_current_user( $this->area_id );

		$html = $this->render( 'render_nombre_interno_field', get_post( $post_id ) );

		$this->assertStringContainsString( 'name="documentate_nombre_interno"', $html );
		$this->assertStringContainsString( 'value="Mi documento corto"', $html );
		$this->assertStringContainsString( 'RES', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	/**
	 * A locked document (published, non-admin) disables the field.
	 */
	public function test_nombre_interno_field_disabled_when_locked() {
		$post_id = $this->crear_documento( $this->area_id, 'publish' );
		wp_set_current_user( $this->area_id );

		$html = $this->render( 'render_nombre_interno_field', get_post( $post_id ) );

		$this->assertStringContainsString( 'disabled', $html );
	}

	/**
	 * Other post types are ignored entirely.
	 */
	public function test_nombre_interno_field_ignores_other_post_types() {
		$post = self::factory()->post->create_and_get();

		$this->assertSame( '', $this->render( 'render_nombre_interno_field', $post ) );
		$this->assertSame( '', $this->render( 'render_nombre_interno_field', null ) );
	}

	/**
	 * Saving the sections metabox with the nonce stores the internal name.
	 */
	public function test_meta_saver_stores_nombre_interno() {
		$post_id = $this->crear_documento( $this->area_id );
		wp_set_current_user( $this->area_id );

		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_nombre_interno'] = 'Guardado desde wp-admin';

		$this->meta_saver->save_meta_boxes( $post_id );

		$this->assertSame( 'Guardado desde wp-admin', Documentate_Documento::nombre_interno( $post_id ) );
	}

	/**
	 * The role-aware lock (can_save_meta_boxes' gate) blocks the save on a
	 * published document for a non-admin, exactly like the dynamic fields.
	 */
	public function test_meta_saver_respects_the_role_lock() {
		$post_id = $this->crear_documento( $this->area_id, 'publish' );
		Documentate_Documento::guardar_nombre_interno( $post_id, 'Antes de bloquear' );
		wp_set_current_user( $this->area_id );

		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_nombre_interno'] = 'Intento bloqueado';

		$this->meta_saver->save_meta_boxes( $post_id );

		$this->assertSame( 'Antes de bloquear', Documentate_Documento::nombre_interno( $post_id ) );
	}

	/**
	 * Anotaciones: área may not save them even with the nonce, gestión may.
	 */
	public function test_meta_saver_stores_anotaciones_for_gestion_only() {
		$post_id = $this->crear_documento( $this->area_id );

		wp_set_current_user( $this->area_id );
		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_anotaciones'] = 'Nota de área, no debería guardarse';
		$this->meta_saver->save_meta_boxes( $post_id );
		$this->assertSame( '', Documentate_Documento::anotaciones( $post_id ) );

		wp_set_current_user( $this->gestion_id );
		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_anotaciones'] = 'Nota interna de gestión';
		$this->meta_saver->save_meta_boxes( $post_id );
		$this->assertSame( 'Nota interna de gestión', Documentate_Documento::anotaciones( $post_id ) );
	}

	/**
	 * The anotaciones metabox shows the stored value and its help text.
	 */
	public function test_anotaciones_metabox_renders_stored_value() {
		$post_id = $this->crear_documento( $this->gestion_id );
		Documentate_Documento::guardar_anotaciones( $post_id, 'Pendiente de revisar el anexo' );
		wp_set_current_user( $this->gestion_id );

		$html = $this->render( 'render_anotaciones_metabox', get_post( $post_id ) );

		$this->assertStringContainsString( 'name="documentate_anotaciones"', $html );
		$this->assertStringContainsString( 'Pendiente de revisar el anexo', $html );
		$this->assertStringContainsString( 'Solo las ven gestión y administración', $html );
	}

	/**
	 * register_meta_boxes() only adds "Anotaciones internas" for gestión/admin.
	 */
	public function test_anotaciones_metabox_registered_only_for_gestion() {
		global $wp_meta_boxes;

		wp_set_current_user( $this->area_id );
		$wp_meta_boxes = array();
		$this->admin_extras->register_meta_boxes();
		$this->assertArrayNotHasKey(
			'documentate_anotaciones',
			$wp_meta_boxes['documentate_document']['side']['low'] ?? array()
		);

		wp_set_current_user( $this->gestion_id );
		$wp_meta_boxes = array();
		$this->admin_extras->register_meta_boxes();
		$this->assertArrayHasKey( 'documentate_anotaciones', $wp_meta_boxes['documentate_document']['side']['low'] );

		wp_set_current_user( $this->admin_id );
		$wp_meta_boxes = array();
		$this->admin_extras->register_meta_boxes();
		$this->assertArrayHasKey( 'documentate_anotaciones', $wp_meta_boxes['documentate_document']['side']['low'] );
	}

	/**
	 * register_meta_boxes() always adds the read-only "Actividad" box.
	 */
	public function test_actividad_metabox_always_registered() {
		global $wp_meta_boxes;

		wp_set_current_user( $this->area_id );
		$wp_meta_boxes = array();
		$this->admin_extras->register_meta_boxes();

		$this->assertArrayHasKey( 'documentate_actividad', $wp_meta_boxes['documentate_document']['side']['low'] );
	}

	/**
	 * The activity metabox lists events read-only, newest first, no comment form.
	 */
	public function test_actividad_metabox_lists_events() {
		$post_id = $this->crear_documento( $this->area_id );
		wp_set_current_user( $this->area_id );
		$primero = Documentate_Actividad::registrar_evento( $post_id, 'creó el borrador' );
		wp_update_comment( array( 'comment_ID' => $primero, 'comment_date' => '2026-01-01 10:00:00', 'comment_date_gmt' => '2026-01-01 10:00:00' ) );
		$segundo = Documentate_Actividad::registrar_evento( $post_id, 'envió el documento a gestión' );
		wp_update_comment( array( 'comment_ID' => $segundo, 'comment_date' => '2026-01-02 10:00:00', 'comment_date_gmt' => '2026-01-02 10:00:00' ) );

		$html = $this->render( 'render_actividad_metabox', get_post( $post_id ) );

		$this->assertStringContainsString( 'creó el borrador', $html );
		$this->assertStringContainsString( 'envió el documento a gestión', $html );
		$this->assertLessThan(
			strpos( $html, 'creó el borrador' ),
			strpos( $html, 'envió el documento a gestión' ),
			'Newest event first.'
		);
		$this->assertStringNotContainsString( '<textarea', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * The activity metabox shows an empty state for a document with no history.
	 */
	public function test_actividad_metabox_empty_state() {
		$post_id = $this->crear_documento( $this->area_id );

		$html = $this->render( 'render_actividad_metabox', get_post( $post_id ) );

		$this->assertStringContainsString( 'Todavía no hay actividad.', $html );
	}

	/**
	 * The admin list column shows the nombre corto next to Title.
	 */
	public function test_admin_column_registered_after_title() {
		$columns = $this->admin_list->add_admin_columns( array( 'title' => 'Title', 'author' => 'Author' ) );

		$this->assertSame( array( 'title', 'nombre_interno', 'doc_type', 'author', 'doc_category' ), array_keys( $columns ) );
		$this->assertSame( 'Nombre interno', $columns['nombre_interno'] );
	}

	/**
	 * The admin list column prints the nombre corto, and an em dash when unset.
	 */
	public function test_admin_column_renders_nombre_corto() {
		$post_id = $this->crear_documento( $this->area_id );
		Documentate_Documento::guardar_nombre_interno( $post_id, 'Corto de lista' );

		ob_start();
		$this->admin_list->render_admin_column( 'nombre_interno', $post_id );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'RES · Corto de lista', $html );

		$sin_nombre = self::factory()->post->create( array( 'post_type' => 'documentate_document', 'post_title' => '' ) );
		ob_start();
		$this->admin_list->render_admin_column( 'nombre_interno', $sin_nombre );
		$this->assertSame( '—', ob_get_clean() );
	}

	/**
	 * A document with an official title but no internal name shows the em
	 * dash rather than a near-duplicate of the Title column: nombre_corto()
	 * would otherwise fall back to the title itself.
	 */
	public function test_admin_column_shows_em_dash_when_only_the_title_is_set() {
		$con_titulo_sin_nombre = $this->crear_documento( $this->area_id );
		$this->assertNotSame( '', get_post( $con_titulo_sin_nombre )->post_title, 'The fixture must set a title for this to be a real regression test.' );
		$this->assertSame( '', Documentate_Documento::nombre_interno( $con_titulo_sin_nombre ) );

		ob_start();
		$this->admin_list->render_admin_column( 'nombre_interno', $con_titulo_sin_nombre );
		$this->assertSame( '—', ob_get_clean() );
	}
}
