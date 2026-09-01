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
	 * Document type term ID.
	 *
	 * @var int
	 */
	private $tipo_id;

	/**
	 * Set up fixtures: users, categories, a doc type and the app page.
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
	}

	/**
	 * Tear down: reset user and request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Create a document in a category with a status.
	 *
	 * @param string $title  Title.
	 * @param int    $cat_id Category term ID.
	 * @param string $status Post status.
	 * @return int
	 */
	private function crear_documento( $title, $cat_id, $status = 'draft' ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => $status,
				// The workflow forces unclassified documents to draft, so the
				// type must travel with the insert for publish to stick.
				'tax_input' => array( 'documentate_doc_type' => array( (int) $this->tipo_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $cat_id ), 'category' );
		wp_set_current_user( 0 );

		return $post_id;
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
	 * Creating a document stores the type, locks it and redirects to the editor.
	 */
	public function test_create_document_handler_creates_a_typed_draft() {
		wp_set_current_user( $this->editor_id );

		$_POST['documentate_app_accion'] = 'crear_documento';
		$_POST['documentate_app_nonce'] = wp_create_nonce( 'documentate_app_crear' );
		$_POST['documentate_app_titulo'] = 'PG · Material aulas';
		$_POST['documentate_app_tipo'] = (string) $this->tipo_id;

		$destino = '';
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$destino ) {
				$destino = $location;
				throw new RuntimeException( 'redirigido' );
			}
		);

		try {
			$this->app->handle_create_document();
			$this->fail( 'The handler must redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirigido', $e->getMessage() );
		}

		$this->assertStringContainsString( 'post.php', $destino );

		$posts = get_posts(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'title' => 'PG · Material aulas',
			)
		);
		$this->assertCount( 1, $posts );

		$doc = $posts[0];
		$tipos = wp_get_post_terms( $doc->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->tipo_id, $tipos );
		$this->assertSame( $this->tipo_id, absint( get_post_meta( $doc->ID, 'documentate_locked_doc_type', true ) ) );

		// The scope fallback files the new document under the editor's scope.
		$cats = wp_get_post_terms( $doc->ID, 'category', array( 'fields' => 'ids' ) );
		$this->assertContains( $this->cat_scope, $cats );
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
	 * Status chips map every workflow status.
	 */
	public function test_estado_chip_maps_statuses() {
		$this->assertSame( 'dcta-estado dcta-estado-borrador', Documentate_App_Shell::estado_chip( 'draft' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-pendiente', Documentate_App_Shell::estado_chip( 'pending' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-aprobado', Documentate_App_Shell::estado_chip( 'publish' )['clase'] );
		$this->assertSame( 'dcta-estado dcta-estado-archivado', Documentate_App_Shell::estado_chip( 'archived' )['clase'] );
	}
}
