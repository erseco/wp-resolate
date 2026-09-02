<?php
/**
 * Tests for the form handlers of the front-end application.
 *
 * Every handler is checked the way the browser drives it: fill $_POST, run the
 * handler, catch the redirect and look at what was stored.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_App_Acciones
 */
class DocumentateAppAccionesTest extends WP_UnitTestCase {

	/**
	 * The smallest valid PDF the mime check accepts.
	 *
	 * @var string
	 */
	const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

	/**
	 * Application instance (for its thin delegates).
	 *
	 * @var Documentate_App
	 */
	private $app;

	/**
	 * Administración user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Gestión documental user ID.
	 *
	 * @var int
	 */
	private $gestion_id;

	/**
	 * Área user ID.
	 *
	 * @var int
	 */
	private $area_id;

	/**
	 * Scope category term ID.
	 *
	 * @var int
	 */
	private $cat_id;

	/**
	 * Document type that goes through gestión documental.
	 *
	 * @var int
	 */
	private $tipo_gestion;

	/**
	 * Document type that goes straight to administración.
	 *
	 * @var int
	 */
	private $tipo_directo;

	/**
	 * Users, scope, types and the application page.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();

		$this->app = new Documentate_App();
		$this->app->ensure_page();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->gestion_id ) )->add_cap( Documentate_Roles::CAP_GESTION );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$area = wp_insert_term( 'Área acciones ' . uniqid(), 'category' );
		$this->cat_id = (int) $area['term_id'];
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );

		$this->tipo_gestion = $this->crear_tipo( 'Resolución acciones', 'RES', true );
		$this->tipo_directo = $this->crear_tipo( 'Convocatoria acciones', 'CONV', false );
	}

	/**
	 * Reset the request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		$_GET = array();
		$_FILES = array();
		parent::tear_down();
	}

	/**
	 * Post a file the way the browser does, backed by a real temporary file.
	 *
	 * The fixture is not a genuine HTTP upload, so the boundary check of the
	 * handler is told to accept it; everything else runs for real.
	 *
	 * @param string $nombre File name.
	 * @return void
	 */
	private function post_fichero( $nombre ) {
		$ruta = wp_tempnam( $nombre );
		file_put_contents( $ruta, self::PDF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$_FILES = array(
			'documentate_app_adjunto' => array(
				'name' => $nombre,
				'type' => 'application/pdf',
				'tmp_name' => $ruta,
				'error' => UPLOAD_ERR_OK,
				'size' => filesize( $ruta ),
			),
		);

		add_filter( 'documentate_app_adjunto_es_subida', '__return_true' );
	}

	/**
	 * Create a document type with a schema, a prefix and a rol.
	 *
	 * @param string $nombre      Type name.
	 * @param string $prefijo     Type prefix.
	 * @param bool   $con_gestion Whether the schema carries a gestión field.
	 * @return int Term ID.
	 */
	private function crear_tipo( $nombre, $prefijo, $con_gestion ) {
		$term = wp_insert_term( $nombre . ' ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, Documentate_Documento::TERM_META_PREFIJO, $prefijo );

		$campos = array(
			array(
				'name' => 'objeto',
				'slug' => 'objeto',
				'title' => 'Objeto',
				'type' => 'text',
			),
		);
		if ( $con_gestion ) {
			$campos[] = array(
				'name' => 'numero_resolucion',
				'slug' => 'numero_resolucion',
				'title' => 'Nº de resolución',
				'type' => 'text',
				'rol' => 'gestion',
			);
		}

		( new SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => $campos,
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'acciones.odt',
					'hash' => md5( $nombre . $prefijo ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		return $term_id;
	}

	/**
	 * Create a document of a type in a status, authored by the área.
	 *
	 * @param string $titulo  Title.
	 * @param int    $tipo_id Document type term ID.
	 * @param string $status  Post status.
	 * @return int Document ID.
	 */
	private function crear_documento( $titulo, $tipo_id, $status = 'draft' ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $titulo,
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( (int) $tipo_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		wp_set_current_user( 0 );

		return (int) $post_id;
	}

	/**
	 * Run a redirecting handler and return where it would have sent the browser.
	 *
	 * @param callable $handler Handler to run.
	 * @return string Redirect target.
	 */
	private function capturar( callable $handler ) {
		$interceptor = static function ( $location ) {
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
	 * Fill the save form as the browser posts it.
	 *
	 * @param int   $doc_id Document ID.
	 * @param array $extra  Extra fields.
	 * @return void
	 */
	private function post_guardar( $doc_id, array $extra = array() ) {
		$_POST = array_merge(
			array(
				'documentate_app_accion' => 'guardar_documento',
				'documentate_app_doc' => (string) $doc_id,
				'documentate_app_nonce' => wp_create_nonce( 'documentate_app_guardar_' . $doc_id ),
				'documentate_app_nombre' => 'Material aulas',
				'documentate_app_titulo' => 'Resolución de material para las aulas',
				'documentate_sections_nonce' => wp_create_nonce( 'documentate_sections_nonce' ),
			),
			$extra
		);
	}

	/**
	 * The activity texts of a document.
	 *
	 * @param int $doc_id Document ID.
	 * @return string[]
	 */
	private function eventos( $doc_id ) {
		return wp_list_pluck( Documentate_Actividad::listar( $doc_id ), 'texto' );
	}

	/**
	 * Creating without an internal name comes back with an error flag.
	 */
	public function test_create_requires_the_internal_name() {
		wp_set_current_user( $this->area_id );

		$_POST = array(
			'documentate_app_accion' => 'crear_documento',
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_crear' ),
			'documentate_app_titulo' => 'Resolución sin nombre corto',
			'documentate_app_nombre' => '',
			'documentate_app_tipo' => (string) $this->tipo_gestion,
		);

		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_create_document' ) );

		$this->assertStringContainsString( 'vista=nuevo', $destino );
		$this->assertStringContainsString( 'error=datos', $destino );
	}

	/**
	 * Creating stores the internal name and records the first event.
	 */
	public function test_create_stores_the_internal_name_and_the_event() {
		wp_set_current_user( $this->area_id );

		$_POST = array(
			'documentate_app_accion' => 'crear_documento',
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_crear' ),
			'documentate_app_titulo' => 'Resolución de material para las aulas',
			'documentate_app_nombre' => 'Material aulas',
			'documentate_app_tipo' => (string) $this->tipo_gestion,
		);

		$destino = $this->capturar( array( $this->app, 'handle_create_document' ) );
		$doc_id = (int) ( new WP_Query(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'fields' => 'ids',
				'posts_per_page' => 1,
			)
		) )->posts[0];

		$this->assertStringContainsString( 'doc=' . $doc_id, $destino );
		$this->assertSame( 'Material aulas', Documentate_Documento::nombre_interno( $doc_id ) );
		$this->assertSame( 'RES · Material aulas', Documentate_Documento::nombre_corto( $doc_id ) );
		$this->assertContains( 'creó el borrador', $this->eventos( $doc_id ) );
	}

	/**
	 * Saving stores the internal name; only gestión may store the notes.
	 */
	public function test_save_stores_name_and_notes_by_rol() {
		$doc_id = $this->crear_documento( 'Borrador con notas', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_anotaciones' => 'Nota del área' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $destino );
		$this->assertStringContainsString( 'vista=editar', $destino );
		$this->assertSame( 'Material aulas', Documentate_Documento::nombre_interno( $doc_id ) );
		$this->assertSame( '', Documentate_Documento::anotaciones( $doc_id ), 'The área cannot write the notes.' );
		$this->assertSame( 'Resolución de material para las aulas', get_post_field( 'post_title', $doc_id ) );

		wp_set_current_user( $this->gestion_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_anotaciones' => 'Falta el anexo' ) );
		$this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertSame( 'Falta el anexo', Documentate_Documento::anotaciones( $doc_id ) );
	}

	/**
	 * Saving never posts a status: the document stays where it was.
	 */
	public function test_save_keeps_the_status() {
		$doc_id = $this->crear_documento( 'En gestión guardado', $this->tipo_gestion, 'en_gestion' );

		wp_set_current_user( $this->gestion_id );
		$this->post_guardar( $doc_id );
		$this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
	}

	/**
	 * Sending a document of a type with gestión lands it in "En gestión".
	 */
	public function test_save_with_transition_sends_to_gestion() {
		$doc_id = $this->crear_documento( 'Borrador para gestión', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_transicion' => 'enviar_gestion' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'doc=' . $doc_id, $destino );
		$this->assertStringContainsString( 'enviado=1', $destino );
		$this->assertStringNotContainsString( 'vista=editar', $destino );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertContains( 'envió el documento a gestión', $this->eventos( $doc_id ) );
	}

	/**
	 * A transition that does not apply to the status comes back with an error.
	 */
	public function test_save_with_unavailable_transition_fails() {
		$doc_id = $this->crear_documento( 'Borrador sin aprobar', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_transicion' => 'aprobar' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=transicion', $destino );
		$this->assertSame( 'draft', get_post_status( $doc_id ) );
	}

	/**
	 * A return without a reason is refused and says why.
	 */
	public function test_return_without_reason_is_refused() {
		$doc_id = $this->crear_documento( 'En gestión sin motivo', $this->tipo_gestion, 'en_gestion' );

		wp_set_current_user( $this->gestion_id );
		$this->post_guardar(
			$doc_id,
			array(
				'documentate_app_transicion' => 'devolver_area',
				'documentate_app_motivo' => '  ',
			)
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=motivo', $destino );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertNull( Documentate_Documento::devuelto( $doc_id ) );
	}

	/**
	 * Gestión returns a document to its área and lands back in its tray.
	 */
	public function test_gestion_returns_to_the_area() {
		$doc_id = $this->crear_documento( 'En gestión devuelto', $this->tipo_gestion, 'en_gestion' );

		wp_set_current_user( $this->gestion_id );
		$this->post_guardar(
			$doc_id,
			array(
				'documentate_app_transicion' => 'devolver_area',
				'documentate_app_motivo' => 'Falta el anexo firmado',
			)
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'bandeja=revisar', $destino );
		$this->assertStringContainsString( 'devuelto=1', $destino );
		$this->assertSame( 'draft', get_post_status( $doc_id ) );

		$devuelto = Documentate_Documento::devuelto( $doc_id );
		$this->assertSame( 'Falta el anexo firmado', $devuelto['motivo'] );
		$this->assertSame( 'gestion', $devuelto['desde'] );
		$this->assertSame( 'area', $devuelto['a'] );
	}

	/**
	 * Administración returns a document and lands back in its own tray.
	 */
	public function test_administracion_returns_from_its_tray() {
		$doc_id = $this->crear_documento( 'En revisión devuelto', $this->tipo_gestion, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'devolver_gestion',
			'documentate_app_motivo' => 'Falta el número de expediente',
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_transition' ) );

		$this->assertStringContainsString( 'bandeja=revision', $destino );
		$this->assertStringContainsString( 'devuelto=1', $destino );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertSame( 'administracion', Documentate_Documento::devuelto( $doc_id )['desde'] );
	}

	/**
	 * Approving from the document view lands on the document with its flag.
	 */
	public function test_transition_approves_from_the_document_view() {
		$doc_id = $this->crear_documento( 'Pendiente de aprobar', $this->tipo_gestion, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'aprobar',
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_transition' ) );

		$this->assertStringContainsString( 'aprobado=1', $destino );
		$this->assertSame( 'publish', get_post_status( $doc_id ) );
		$this->assertContains( 'aprobó y publicó el documento', $this->eventos( $doc_id ) );
	}

	/**
	 * Archiving belongs to wp-admin, even for whoever posts its key by hand.
	 */
	public function test_the_application_never_archives_a_document() {
		$doc_id = $this->crear_documento( 'Aprobado y publicado', $this->tipo_gestion, 'publish' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'archivar',
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_transition' ) );

		$this->assertStringContainsString( 'error=transicion', $destino );
		$this->assertSame( 'publish', get_post_status( $doc_id ) );
	}

	/**
	 * The transition handler refuses a bad nonce.
	 */
	public function test_transition_rejects_a_bad_nonce() {
		$doc_id = $this->crear_documento( 'Pendiente nonce', $this->tipo_gestion, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => 'nope',
			'documentate_app_transicion' => 'aprobar',
		);

		$this->expectException( 'WPDieException' );
		Documentate_App_Acciones::handle_transition();
	}

	/**
	 * The transition handler refuses a document outside the user's scope.
	 */
	public function test_transition_refuses_a_document_out_of_scope() {
		$otra = wp_insert_term( 'Otra área ' . uniqid(), 'category' );
		$doc_id = $this->crear_documento( 'Fuera de ámbito', $this->tipo_gestion, 'pending' );
		wp_set_object_terms( $doc_id, array( (int) $otra['term_id'] ), 'category' );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'aprobar',
		);

		$this->expectException( 'WPDieException' );
		Documentate_App_Acciones::handle_transition();
	}

	/**
	 * The transition handler says so when no action was posted.
	 */
	public function test_transition_without_a_key_reports_the_error() {
		$doc_id = $this->crear_documento( 'Sin acción', $this->tipo_gestion, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_transition' ) );

		$this->assertStringContainsString( 'error=transicion', $destino );
		$this->assertSame( 'pending', get_post_status( $doc_id ) );
	}

	/**
	 * A comment is stored and shown in the activity.
	 */
	public function test_comment_is_stored_and_comes_back_to_the_view() {
		$doc_id = $this->crear_documento( 'Con comentario', $this->tipo_gestion, 'en_gestion' );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'comentar',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_comentar_' . $doc_id ),
			'documentate_app_comentario' => 'El anexo va en la última página del ODT',
			'documentate_app_redirect_to' => 'detalle',
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_comment' ) );

		$this->assertStringContainsString( 'comentado=1', $destino );
		$this->assertStringNotContainsString( 'vista=editar', $destino );
		$this->assertContains( 'El anexo va en la última página del ODT', $this->eventos( $doc_id ) );
	}

	/**
	 * An empty comment comes back to the edit view with an error.
	 */
	public function test_empty_comment_is_refused_and_returns_to_the_editor() {
		$doc_id = $this->crear_documento( 'Comentario vacío', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'comentar',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_comentar_' . $doc_id ),
			'documentate_app_comentario' => '   ',
			'documentate_app_redirect_to' => 'editar',
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_comment' ) );

		$this->assertStringContainsString( 'vista=editar', $destino );
		$this->assertStringContainsString( 'error=comentario', $destino );
		$this->assertSame( array(), $this->eventos( $doc_id ) );
	}

	/**
	 * Ticking "Quitar" detaches the file and records it.
	 */
	public function test_save_removes_the_attachment_when_asked_to() {
		$doc_id = $this->crear_documento( 'Con adjunto', $this->tipo_gestion );
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file' => 'resolucion.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
				'post_title' => 'resolucion.pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Documento::META_ADJUNTOS, array( (int) $adjunto ) );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_quitar_adjunto' => '1' ) );
		$this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertNull( Documentate_Documento::adjunto( $doc_id ) );
		$this->assertContains( 'quitó el fichero «resolucion.pdf»', $this->eventos( $doc_id ) );
	}

	/**
	 * An empty internal name keeps the stored one instead of erasing it.
	 *
	 * "Guardar" is formnovalidate, so the box can reach the handler empty.
	 */
	public function test_save_falls_back_to_the_stored_internal_name() {
		$doc_id = $this->crear_documento( 'Borrador con nombre', $this->tipo_gestion );
		Documentate_Documento::guardar_nombre_interno( $doc_id, 'Material aulas' );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_nombre' => '   ' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $destino );
		$this->assertSame( 'Material aulas', Documentate_Documento::nombre_interno( $doc_id ), 'The stored name is untouched.' );
	}

	/**
	 * A document that never had an internal name reports it — after saving the
	 * rest, not instead of saving it.
	 *
	 * Documents created in wp-admin carry no internal name at all, so the app
	 * pre-fills that box empty: bailing before wp_update_post() would throw
	 * away every field the person had just filled in.
	 */
	public function test_save_stores_the_fields_even_when_the_name_is_missing() {
		$doc_id = $this->crear_documento( 'Creado en wp-admin', $this->tipo_gestion );
		Documentate_Documento::guardar_nombre_interno( $doc_id, '' );

		wp_set_current_user( $this->area_id );
		$this->post_guardar(
			$doc_id,
			array(
				'documentate_app_nombre' => '',
				'documentate_app_titulo' => 'Resolución con todo el bloque I',
				'documentate_field_objeto' => 'Objeto que costó media hora escribir',
			)
		);
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=nombre', $destino );
		$this->assertSame(
			'Objeto que costó media hora escribir',
			get_post_meta( $doc_id, 'documentate_field_objeto', true ),
			'The fields of the form are stored before the missing datum is reported.'
		);
		$this->assertSame( 'Resolución con todo el bloque I', get_post_field( 'post_title', $doc_id ) );
	}

	/**
	 * The tray the document was opened from survives a save.
	 */
	public function test_save_comes_back_to_the_tray_it_came_from() {
		$doc_id = $this->crear_documento( 'En gestión desde la bandeja', $this->tipo_gestion, 'en_gestion' );

		wp_set_current_user( $this->gestion_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_bandeja' => 'revisar' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'bandeja=revisar', $destino );
		$this->assertStringContainsString( 'guardado=1', $destino );
	}

	/**
	 * A tray this person cannot open is not carried over.
	 */
	public function test_save_ignores_a_tray_that_is_not_theirs() {
		$doc_id = $this->crear_documento( 'Borrador del área', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_bandeja' => 'revision' ) );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringNotContainsString( 'bandeja=', $destino );
	}

	/**
	 * Saving with a file stores it and records it in the activity.
	 */
	public function test_save_stores_the_uploaded_file() {
		$doc_id = $this->crear_documento( 'Con fichero nuevo', $this->tipo_gestion );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id );
		$this->post_fichero( 'resolucion-app.pdf' );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$adjunto = Documentate_Documento::adjunto( $doc_id );

		$this->assertStringContainsString( 'guardado=1', $destino );
		$this->assertNotNull( $adjunto );
		$this->assertSame( array( $adjunto->ID ), get_post_meta( $doc_id, Documentate_Documento::META_ADJUNTOS, true ) );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Adjuntos::nombre( $adjunto->ID ) . '»',
			$this->eventos( $doc_id )
		);
	}

	/**
	 * A file of another format is refused and the current one is kept.
	 */
	public function test_a_refused_file_never_costs_the_current_one() {
		$doc_id = $this->crear_documento( 'Con fichero previo', $this->tipo_gestion );
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file' => 'previo.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Documento::META_ADJUNTOS, array( (int) $adjunto ) );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id, array( 'documentate_app_quitar_adjunto' => '1' ) );
		$this->post_fichero( 'hoja.xlsx' );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=adjunto', $destino );
		$this->assertStringNotContainsString( 'error=adjunto_permiso', $destino );
		$this->assertSame( (int) $adjunto, Documentate_Documento::adjunto( $doc_id )->ID, 'The old file is still there.' );
	}

	/**
	 * A user who cannot upload is told so, not that the format is wrong.
	 */
	public function test_a_user_without_uploads_is_told_the_real_reason() {
		$doc_id = $this->crear_documento( 'Sin permiso de subida', $this->tipo_gestion );

		// remove_cap() only drops caps stored on the user, and this one comes
		// from the role: denying it explicitly is what overrides the role.
		( new WP_User( $this->area_id ) )->add_cap( 'upload_files', false );

		wp_set_current_user( $this->area_id );
		$this->post_guardar( $doc_id );
		$this->post_fichero( 'resolucion-sin-permiso.pdf' );
		$destino = $this->capturar( array( 'Documentate_App_Acciones', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=adjunto_permiso', $destino );
		$this->assertNull( Documentate_Documento::adjunto( $doc_id ) );
		$this->assertStringContainsString(
			'Tu usuario no puede subir ficheros',
			Documentate_App_Detalle::texto_error( 'adjunto_permiso' )
		);
	}

	/**
	 * Handlers ignore requests that do not carry their action.
	 */
	public function test_handlers_ignore_other_requests() {
		wp_set_current_user( $this->admin_id );
		$_POST = array( 'documentate_app_accion' => 'otra_cosa' );

		Documentate_App_Acciones::handle_create_document();
		Documentate_App_Acciones::handle_save_document();
		Documentate_App_Acciones::handle_transition();
		Documentate_App_Acciones::handle_comment();

		$this->assertSame( 'otra_cosa', $_POST['documentate_app_accion'] );
	}
}
