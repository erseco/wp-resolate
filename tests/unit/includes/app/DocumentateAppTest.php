<?php
/**
 * Tests for the front-end application under /documentate/.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_App
 * @covers Documentate_App_Shell
 * @covers Documentate_App_Lista
 * @covers Documentate_App_Detalle
 * @covers Documentate_App_Editar
 */
class DocumentateAppTest extends WP_UnitTestCase {

	/**
	 * Application instance under test.
	 *
	 * @var Documentate_App
	 */
	private $app;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Scoped editor user ID.
	 *
	 * @var int
	 */
	private $editor_id;

	/**
	 * Scope category term ID.
	 *
	 * @var int
	 */
	private $cat_scope;

	/**
	 * Out-of-scope category term ID.
	 *
	 * @var int
	 */
	private $cat_other;

	/**
	 * Document type term ID (no schema).
	 *
	 * @var int
	 */
	private $tipo_id;

	/**
	 * Document type term ID with fields and a repeater.
	 *
	 * @var int
	 */
	private $tipo_esquema_id;

	/**
	 * Set up fixtures: users, categories, doc types and the app page.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->app = new Documentate_App();
		$this->app->ensure_page();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		$scope = wp_insert_term( 'Ámbito App', 'category' );
		$other = wp_insert_term( 'Otro Ámbito App', 'category' );
		$this->cat_scope = $scope['term_id'];
		$this->cat_other = $other['term_id'];
		update_user_meta( $this->editor_id, 'documentate_scope_term_id', $this->cat_scope );

		$tipo = wp_insert_term( 'Resolución App', 'documentate_doc_type' );
		$this->tipo_id = $tipo['term_id'];

		$con_esquema = wp_insert_term( 'Propuesta App', 'documentate_doc_type' );
		$this->tipo_esquema_id = $con_esquema['term_id'];
		( new Documentate\DocType\SchemaStorage() )->save_schema(
			$this->tipo_esquema_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'Asunto',
						'slug' => 'asunto',
						'type' => 'text',
						'title' => 'Asunto',
					),
					array(
						'name' => 'Cuerpo',
						'slug' => 'cuerpo',
						'type' => 'html',
						'title' => 'Cuerpo',
					),
				),
				'repeaters' => array(
					array(
						'name' => 'anexos',
						'slug' => 'anexos',
						'fields' => array(
							array(
								'name' => 'Título',
								'slug' => 'titulo',
								'type' => 'text',
								'title' => 'Título',
							),
						),
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'app-test.odt',
					'hash' => md5( 'app-schema' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);
	}

	/**
	 * Tear down: reset user and request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		$_GET = array();
		wp_dequeue_script( 'documentate-calculos' );
		wp_dequeue_script( 'documentate-annexes' );
		wp_dequeue_style( 'documentate-app' );
		parent::tear_down();
	}

	/**
	 * Create a document in a category with a status.
	 *
	 * @param string $title   Title.
	 * @param int    $cat_id  Category term ID.
	 * @param string $status  Post status.
	 * @param int    $tipo_id Document type term ID (defaults to the type without schema).
	 * @return int
	 */
	private function crear_documento( $title, $cat_id, $status = 'draft', $tipo_id = 0 ) {
		$tipo_id = $tipo_id > 0 ? $tipo_id : $this->tipo_id;

		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => $status,
				// The workflow forces unclassified documents to draft, so the
				// type must travel with the insert for publish to stick.
				'tax_input' => array( 'documentate_doc_type' => array( (int) $tipo_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		wp_set_current_user( 0 );

		return $post_id;
	}

	/**
	 * Run a redirecting handler and return where it would have sent the browser.
	 *
	 * @param callable $handler Handler to run.
	 * @return string Redirect target.
	 */
	private function capturar_redireccion( callable $handler ) {
		$interceptor = function ( $location ) {
			throw new Documentate_Exit_Exception( $location );
		};
		add_filter( 'wp_redirect', $interceptor );

		try {
			$handler();
			$this->fail( 'The handler must redirect.' );
		} catch ( Documentate_Exit_Exception $e ) {
			return $e->get_location();
		} finally {
			remove_filter( 'wp_redirect', $interceptor );
		}

		return '';
	}

	/**
	 * Fill the save form request for a document as the current user would post it.
	 *
	 * @param int    $doc_id Document ID.
	 * @param string $titulo Title to post.
	 * @param string $estado Button pressed: guardar or enviar.
	 * @return void
	 */
	private function preparar_guardado( $doc_id, $titulo, $estado = 'guardar' ) {
		$_POST = array(
			'documentate_app_accion' => 'guardar_documento',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_guardar_' . $doc_id ),
			'documentate_app_titulo' => $titulo,
			'documentate_app_estado' => $estado,
			'documentate_sections_nonce' => wp_create_nonce( 'documentate_sections_nonce' ),
		);
	}

	/**
	 * ensure_page creates the /documentate/ page once and adopts it after.
	 */
	public function test_ensure_page_is_idempotent() {
		$page = get_page_by_path( Documentate_App_Shell::PAGE_SLUG );

		$this->assertInstanceOf( WP_Post::class, $page );
		$this->assertStringContainsString( '[documentate_app]', $page->post_content );

		$this->app->ensure_page();
		$this->assertSame( $page->ID, absint( get_option( Documentate_App::OPTION_PAGE_ID ) ) );
	}

	/**
	 * ensure_page adopts a page that already carries the slug.
	 */
	public function test_ensure_page_adopts_an_existing_page() {
		$page = get_page_by_path( Documentate_App_Shell::PAGE_SLUG );
		delete_option( Documentate_App::OPTION_PAGE_ID );

		$this->app->ensure_page();

		$this->assertSame( $page->ID, absint( get_option( Documentate_App::OPTION_PAGE_ID ) ) );
	}

	/**
	 * Logged-out visitors get the sign-in notice, not the list.
	 */
	public function test_logged_out_visitors_are_asked_to_sign_in() {
		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-aviso', $html );
		$this->assertStringContainsString( 'wp-login.php', $html );
		$this->assertStringNotContainsString( 'dcta-tabla', $html );
	}

	/**
	 * Users who cannot edit documents get a notice.
	 */
	public function test_users_without_edit_posts_are_rejected() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-aviso', $html );
		$this->assertStringNotContainsString( 'dcta-tabla', $html );
	}

	/**
	 * A scoped editor sees the documents of their scope and nothing else.
	 */
	public function test_scoped_editor_sees_only_their_scope() {
		$dentro = $this->crear_documento( 'Documento dentro', $this->cat_scope );
		$fuera = $this->crear_documento( 'Documento fuera', $this->cat_other );

		wp_set_current_user( $this->editor_id );
		$html = $this->app->render();

		$this->assertStringContainsString( 'Documento dentro', $html );
		$this->assertStringNotContainsString( 'Documento fuera', $html );
		$this->assertStringContainsString( 'dcta-estado-borrador', $html );
		unset( $dentro, $fuera );
	}

	/**
	 * A restricted editor without a scope sees the no-scope notice.
	 */
	public function test_editor_without_scope_sees_notice() {
		delete_user_meta( $this->editor_id, 'documentate_scope_term_id' );
		wp_set_current_user( $this->editor_id );

		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-aviso', $html );
		$this->assertStringNotContainsString( 'dcta-tabla', $html );
	}

	/**
	 * Administrators see every document under the "all documents" heading.
	 */
	public function test_admin_sees_every_document() {
		$this->crear_documento( 'Documento dentro', $this->cat_scope );
		$this->crear_documento( 'Documento fuera', $this->cat_other );

		wp_set_current_user( $this->admin_id );
		$html = $this->app->render();

		$this->assertStringContainsString( 'Documento dentro', $html );
		$this->assertStringContainsString( 'Documento fuera', $html );
	}

	/**
	 * An empty list shows the empty state instead of rows.
	 */
	public function test_empty_list_shows_empty_state() {
		wp_set_current_user( $this->editor_id );

		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-vacio', $html );
	}

	/**
	 * The status filter narrows the rows.
	 */
	public function test_status_filter_narrows_the_list() {
		$this->crear_documento( 'Borrador visible', $this->cat_scope, 'draft' );
		$this->crear_documento( 'Aprobado oculto', $this->cat_scope, 'publish' );

		wp_set_current_user( $this->editor_id );
		$_GET['estado'] = 'draft';
		$html = $this->app->render();

		$this->assertStringContainsString( 'Borrador visible', $html );
		$this->assertStringNotContainsString( 'Aprobado oculto', $html );
	}

	/**
	 * A draft row leads to the in-app editor; a reviewed row leads to the detail.
	 */
	public function test_list_rows_link_to_editor_or_detail() {
		$borrador = $this->crear_documento( 'Borrador continuar', $this->cat_scope, 'draft' );
		$aprobado = $this->crear_documento( 'Aprobado ver', $this->cat_scope, 'publish' );

		wp_set_current_user( $this->editor_id );
		$html = $this->app->render();

		$this->assertStringContainsString( 'doc=' . $borrador . '&#038;vista=editar', $html );
		$this->assertStringNotContainsString( 'doc=' . $aprobado . '&#038;vista=editar', $html );
	}

	/**
	 * The detail view shows an in-scope document and hides an out-of-scope one.
	 */
	public function test_detail_respects_scope() {
		$dentro = $this->crear_documento( 'Documento dentro', $this->cat_scope );
		$fuera = $this->crear_documento( 'Documento fuera', $this->cat_other );

		wp_set_current_user( $this->editor_id );

		$_GET['doc'] = (string) $dentro;
		$html = $this->app->render();
		$this->assertStringContainsString( 'Documento dentro', $html );
		$this->assertStringContainsString( 'post.php', $html );

		$_GET['doc'] = (string) $fuera;
		$html = $this->app->render();
		$this->assertStringNotContainsString( 'Documento fuera', $html );
		$this->assertStringContainsString( 'dcta-aviso', $html );
	}

	/**
	 * The detail offers the edit button for drafts only, and shows the sent notice.
	 */
	public function test_detail_offers_edit_for_drafts_and_shows_sent_notice() {
		$borrador = $this->crear_documento( 'Borrador detalle', $this->cat_scope, 'draft' );
		$pendiente = $this->crear_documento( 'Pendiente detalle', $this->cat_scope, 'pending' );

		wp_set_current_user( $this->editor_id );

		$_GET['doc'] = (string) $borrador;
		$html = $this->app->render();
		$this->assertStringContainsString( 'vista=editar', $html );
		$this->assertStringNotContainsString( 'dcta-aviso-ok', $html );

		$_GET['doc'] = (string) $pendiente;
		$_GET['enviado'] = '1';
		$html = $this->app->render();
		$this->assertStringNotContainsString( 'vista=editar', $html );
		$this->assertStringContainsString( 'dcta-aviso-ok', $html );
	}

	/**
	 * The detail summarises the stored fields: scalars, long values and repeater counts.
	 */
	public function test_detail_summarises_fields() {
		$doc = $this->crear_documento( 'Propuesta detalle', $this->cat_scope, 'draft', $this->tipo_esquema_id );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, 'Propuesta detalle' );
		$_POST['documentate_field_asunto'] = 'Material para las aulas';
		$_POST['documentate_field_cuerpo'] = '<p>' . str_repeat( 'Texto largo. ', 40 ) . '</p>';
		$_POST['tpl_fields'] = array(
			'anexos' => array(
				array( 'titulo' => 'Anexo I' ),
				array( 'titulo' => 'Anexo II' ),
				array( 'titulo' => 'Anexo III' ),
			),
		);
		$this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$_POST = array();
		$_GET['doc'] = (string) $doc;
		$html = $this->app->render();

		$this->assertStringContainsString( 'Material para las aulas', $html );
		$this->assertStringContainsString( '…', $html );
		$this->assertStringContainsString( '3 items', $html );
	}

	/**
	 * A document whose type has no fields says so.
	 */
	public function test_detail_without_schema_shows_empty_fields_notice() {
		$doc = $this->crear_documento( 'Sin campos', $this->cat_scope );

		wp_set_current_user( $this->editor_id );
		$_GET['doc'] = (string) $doc;
		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-ficha', $html );
		$this->assertStringContainsString( '<dd>', $html );
	}

	/**
	 * The new-document form lists the types.
	 */
	public function test_new_document_form_lists_types() {
		wp_set_current_user( $this->editor_id );
		$_GET['vista'] = 'nuevo';

		$html = $this->app->render();

		$this->assertStringContainsString( 'documentate_app_titulo', $html );
		$this->assertStringContainsString( 'Resolución App', $html );
	}

	/**
	 * The new-document form reports a failed attempt.
	 */
	public function test_new_document_form_shows_error_flag() {
		wp_set_current_user( $this->editor_id );
		$_GET['vista'] = 'nuevo';
		$_GET['error'] = 'datos';

		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-aviso', $html );
		$this->assertStringContainsString( 'documentate_app_titulo', $html );
	}

	/**
	 * Creating a document stores the type, locks it and redirects to the in-app editor.
	 */
	public function test_create_document_handler_creates_a_typed_draft() {
		wp_set_current_user( $this->editor_id );

		$_POST['documentate_app_accion'] = 'crear_documento';
		$_POST['documentate_app_nonce'] = wp_create_nonce( 'documentate_app_crear' );
		$_POST['documentate_app_titulo'] = 'PG · Material aulas';
		$_POST['documentate_app_tipo'] = (string) $this->tipo_id;

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_create_document' ) );

		$posts = get_posts(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'title' => 'PG · Material aulas',
			)
		);
		$this->assertCount( 1, $posts );

		$doc = $posts[0];
		$this->assertStringContainsString( 'vista=editar', $destino );
		$this->assertStringContainsString( 'doc=' . $doc->ID, $destino );

		$tipos = wp_get_post_terms( $doc->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->tipo_id, $tipos );
		$this->assertSame( $this->tipo_id, absint( get_post_meta( $doc->ID, 'documentate_locked_doc_type', true ) ) );

		// The scope fallback files the new document under the editor's scope.
		$cats = wp_get_post_terms( $doc->ID, 'category', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->cat_scope, $cats );
	}

	/**
	 * The create handler sends incomplete forms back with an error flag.
	 */
	public function test_create_document_handler_rejects_missing_type() {
		wp_set_current_user( $this->editor_id );

		$_POST['documentate_app_accion'] = 'crear_documento';
		$_POST['documentate_app_nonce'] = wp_create_nonce( 'documentate_app_crear' );
		$_POST['documentate_app_titulo'] = 'Sin tipo';
		$_POST['documentate_app_tipo'] = '0';

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_create_document' ) );

		$this->assertStringContainsString( 'error=datos', $destino );
		$this->assertStringContainsString( 'vista=nuevo', $destino );
	}

	/**
	 * The create handler rejects a bad nonce.
	 */
	public function test_create_document_handler_rejects_bad_nonce() {
		wp_set_current_user( $this->editor_id );

		$_POST['documentate_app_accion'] = 'crear_documento';
		$_POST['documentate_app_nonce'] = 'nope';

		$this->expectException( 'WPDieException' );
		$this->app->handle_create_document();
	}

	/**
	 * The edit view draws the sections form with the schema fields.
	 */
	public function test_edit_view_renders_the_fields_form() {
		$doc = $this->crear_documento( 'Propuesta editable', $this->cat_scope, 'draft', $this->tipo_esquema_id );

		wp_set_current_user( $this->editor_id );
		$_GET['doc'] = (string) $doc;
		$_GET['vista'] = 'editar';
		$html = $this->app->render();

		$this->assertStringContainsString( 'documentate_sections_nonce', $html );
		$this->assertStringContainsString( 'name="documentate_app_nonce"', $html );
		$this->assertStringContainsString( 'name="documentate_app_titulo"', $html );
		$this->assertStringContainsString( 'documentate_field_asunto', $html );
		$this->assertStringContainsString( 'tpl_fields[anexos]', $html );
		$this->assertStringContainsString( 'value="enviar"', $html );
		$this->assertStringContainsString( 'Propuesta App', $html );
	}

	/**
	 * Administrators editing a reviewed document get a plain save button.
	 */
	public function test_edit_view_for_admin_on_pending_document_keeps_status() {
		$doc = $this->crear_documento( 'Pendiente admin', $this->cat_scope, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_GET['doc'] = (string) $doc;
		$_GET['vista'] = 'editar';
		$html = $this->app->render();

		$this->assertStringContainsString( 'documentate_sections_nonce', $html );
		$this->assertStringNotContainsString( 'value="enviar"', $html );

		$this->preparar_guardado( $doc, 'Pendiente admin corregido' );
		$destino = $this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $destino );
		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertSame( 'Pendiente admin corregido', get_post_field( 'post_title', $doc ) );
	}

	/**
	 * The edit view refuses a document the workflow has locked for this user.
	 */
	public function test_edit_view_of_locked_document_shows_notice() {
		$doc = $this->crear_documento( 'Pendiente bloqueado', $this->cat_scope, 'pending' );

		wp_set_current_user( $this->editor_id );
		$_GET['doc'] = (string) $doc;
		$_GET['vista'] = 'editar';
		$html = $this->app->render();

		$this->assertStringContainsString( 'dcta-aviso', $html );
		$this->assertStringNotContainsString( 'documentate_sections_nonce', $html );
	}

	/**
	 * The edit view hides an out-of-scope document.
	 */
	public function test_edit_view_respects_scope() {
		$fuera = $this->crear_documento( 'Documento fuera', $this->cat_other );

		wp_set_current_user( $this->editor_id );
		$_GET['doc'] = (string) $fuera;
		$_GET['vista'] = 'editar';
		$html = $this->app->render();

		$this->assertStringNotContainsString( 'Documento fuera', $html );
		$this->assertStringNotContainsString( 'documentate_sections_nonce', $html );
	}

	/**
	 * The edit view echoes the outcome of the previous save.
	 */
	public function test_edit_view_shows_feedback_flags() {
		$doc = $this->crear_documento( 'Con avisos', $this->cat_scope );

		wp_set_current_user( $this->editor_id );
		$_GET['doc'] = (string) $doc;
		$_GET['vista'] = 'editar';

		$_GET['guardado'] = '1';
		$this->assertStringContainsString( 'dcta-aviso-ok', $this->app->render() );
		unset( $_GET['guardado'] );

		$_GET['error'] = 'titulo';
		$this->assertStringContainsString( 'dcta-aviso-mal', $this->app->render() );

		$_GET['error'] = 'guardar';
		$this->assertStringContainsString( 'dcta-aviso-mal', $this->app->render() );
		unset( $_GET['error'] );

		$this->assertStringNotContainsString( 'dcta-aviso-', $this->app->render() );
	}

	/**
	 * Saving stores the title and the fields through the regular pipeline.
	 */
	public function test_save_document_handler_stores_fields_and_keeps_draft() {
		$doc = $this->crear_documento( 'Propuesta guardar', $this->cat_scope, 'draft', $this->tipo_esquema_id );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, 'Propuesta guardada' );
		$_POST['documentate_field_asunto'] = 'Material para las aulas';
		$_POST['tpl_fields'] = array(
			'anexos' => array(
				array( 'titulo' => 'Anexo I' ),
			),
		);

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $destino );
		$this->assertStringContainsString( 'vista=editar', $destino );
		$this->assertSame( 'draft', get_post_status( $doc ) );
		$this->assertSame( 'Propuesta guardada', get_post_field( 'post_title', $doc ) );
		$this->assertSame( 'Material para las aulas', get_post_meta( $doc, 'documentate_field_asunto', true ) );

		$content = get_post_field( 'post_content', $doc );
		$this->assertStringContainsString( 'slug="asunto"', $content );
		$this->assertStringContainsString( 'Anexo I', $content );
	}

	/**
	 * Sending a draft for review moves it to pending and lands on the detail.
	 */
	public function test_save_document_handler_sends_draft_for_review() {
		$doc = $this->crear_documento( 'Propuesta enviar', $this->cat_scope, 'draft', $this->tipo_esquema_id );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, 'Propuesta enviada', 'enviar' );

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$this->assertStringContainsString( 'enviado=1', $destino );
		$this->assertStringNotContainsString( 'vista=editar', $destino );
		$this->assertSame( 'pending', get_post_status( $doc ) );
	}

	/**
	 * Saving without a title sends the user back with the title flag.
	 */
	public function test_save_document_handler_requires_a_title() {
		$doc = $this->crear_documento( 'Propuesta sin título', $this->cat_scope );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, '   ' );

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=titulo', $destino );
		$this->assertSame( 'Propuesta sin título', get_post_field( 'post_title', $doc ) );
	}

	/**
	 * A non-admin cannot save a document the workflow has locked.
	 */
	public function test_save_document_handler_refuses_locked_document() {
		$doc = $this->crear_documento( 'Pendiente guardar', $this->cat_scope, 'pending' );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, 'Intento de cambio' );

		$destino = $this->capturar_redireccion( array( $this->app, 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=bloqueado', $destino );
		$this->assertSame( 'Pendiente guardar', get_post_field( 'post_title', $doc ) );
	}

	/**
	 * The save handler rejects a bad nonce.
	 */
	public function test_save_document_handler_rejects_bad_nonce() {
		$doc = $this->crear_documento( 'Propuesta nonce', $this->cat_scope );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $doc, 'Propuesta nonce' );
		$_POST['documentate_app_nonce'] = 'nope';

		$this->expectException( 'WPDieException' );
		$this->app->handle_save_document();
	}

	/**
	 * The save handler rejects a document outside the user's scope.
	 */
	public function test_save_document_handler_rejects_out_of_scope_document() {
		$fuera = $this->crear_documento( 'Documento fuera', $this->cat_other );

		wp_set_current_user( $this->editor_id );
		$this->preparar_guardado( $fuera, 'Documento fuera cambiado' );

		$this->expectException( 'WPDieException' );
		$this->app->handle_save_document();
	}

	/**
	 * The save handler ignores requests that are not its form.
	 */
	public function test_save_document_handler_ignores_other_requests() {
		$_POST['documentate_app_accion'] = 'otra_cosa';

		$this->app->handle_save_document();
		$this->app->handle_create_document();

		$this->assertSame( 'otra_cosa', $_POST['documentate_app_accion'] );
	}

	/**
	 * The admin bar gets a shortcut for users who can use the app.
	 */
	public function test_admin_bar_node_links_to_the_app() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$bar = new WP_Admin_Bar();
		$this->app->admin_bar_node( $bar );
		$this->assertNull( $bar->get_node( 'documentate-app' ) );

		wp_set_current_user( $this->editor_id );
		$bar = new WP_Admin_Bar();
		$this->app->admin_bar_node( $bar );

		$node = $bar->get_node( 'documentate-app' );
		$this->assertNotNull( $node );
		$this->assertSame( Documentate_App_Shell::page_url(), $node->href );
	}

	/**
	 * The stylesheet loads on the app page; the editor only on the edit view.
	 */
	public function test_enqueue_assets_loads_editor_only_on_edit_view() {
		$doc = $this->crear_documento( 'Propuesta assets', $this->cat_scope );
		wp_set_current_user( $this->editor_id );

		$this->go_to( home_url( '/' ) );
		$this->app->enqueue_assets();
		$this->assertFalse( wp_style_is( 'documentate-app', 'enqueued' ) );

		$this->go_to( Documentate_App_Shell::page_url( array( 'doc' => $doc ) ) );
		$this->app->enqueue_assets();
		$this->assertTrue( wp_style_is( 'documentate-app', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-annexes', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-calculos', 'enqueued' ) );

		$this->go_to( Documentate_App_Editar::url( $doc ) );
		$this->app->enqueue_assets();
		$this->assertTrue( wp_script_is( 'documentate-annexes', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-calculos', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'editor', 'enqueued' ) );
	}

	/**
	 * The body class marks the app page only.
	 */
	public function test_body_class_marks_the_app_page() {
		$this->go_to( home_url( '/' ) );
		$this->assertNotContains( 'documentate-app', Documentate_App_Shell::body_class( array() ) );

		$this->go_to( Documentate_App_Shell::page_url() );
		$this->assertContains( 'documentate-app', Documentate_App_Shell::body_class( array() ) );
	}

	/**
	 * The role chip names the role and, for área users, their scope.
	 */
	public function test_rol_reflects_the_user() {
		wp_set_current_user( $this->admin_id );
		$this->assertSame( 'Administración', Documentate_App_Shell::rol() );

		// An editor carries the gestión capability.
		wp_set_current_user( $this->editor_id );
		$this->assertSame( 'Gestión documental', Documentate_App_Shell::rol() );

		// An author is área: the chip names their scope.
		$autor = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $autor, 'documentate_scope_term_id', $this->cat_scope );
		wp_set_current_user( $autor );
		$this->assertSame( 'Área · Ámbito App', Documentate_App_Shell::rol() );

		delete_user_meta( $autor, 'documentate_scope_term_id' );
		$this->assertSame( 'Edición', Documentate_App_Shell::rol() );
	}

	/**
	 * Status chips map every workflow status.
	 */
	public function test_estado_chip_maps_statuses() {
		$this->assertSame( 'dcta-estado dcta-estado-borrador', Documentate_App_Shell::estado_chip( 'draft' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-pendiente', Documentate_App_Shell::estado_chip( 'pending' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-gestion', Documentate_App_Shell::estado_chip( 'en_gestion' )['clase'] );
		$this->assertSame( 'En gestión', Documentate_App_Shell::estado_chip( 'en_gestion' )['texto'] );
		$this->assertSame( 'dcta-estado dcta-estado-aprobado', Documentate_App_Shell::estado_chip( 'publish' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-archivado', Documentate_App_Shell::estado_chip( 'archived' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-borrador', Documentate_App_Shell::estado_chip( 'unknown' )['clase'] );
	}
}
