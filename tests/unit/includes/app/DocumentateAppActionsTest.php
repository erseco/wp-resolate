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
 * @covers Documentate_App_Actions
 */
class DocumentateAppActionsTest extends WP_UnitTestCase {

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
	private $management_id;

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
	private $management_type;

	/**
	 * Document type that goes straight to administración.
	 *
	 * @var int
	 */
	private $direct_type;

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
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$area = wp_insert_term( 'Área acciones ' . uniqid(), 'category' );
		$this->cat_id = (int) $area['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );

		$this->management_type_id = $this->create_type( 'Resolución acciones', 'RES', true );
		$this->direct_type_id = $this->create_type( 'Convocatoria acciones', 'CONV', false );
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
	 * @param string $name File name.
	 * @return void
	 */
	private function post_file( $name ) {
		$path = wp_tempnam( $name );
		file_put_contents( $path, self::PDF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$_FILES = array(
			'documentate_app_adjunto' => array(
				'name' => $name,
				'type' => 'application/pdf',
				'tmp_name' => $path,
				'error' => UPLOAD_ERR_OK,
				'size' => filesize( $path ),
			),
		);

		add_filter( 'documentate_app_adjunto_es_subida', '__return_true' );
	}

	/**
	 * Create a document type with a schema, a prefix and a rol.
	 *
	 * @param string $name      Type name.
	 * @param string $prefix     Type prefix.
	 * @param bool   $has_management Whether the schema carries a gestión field.
	 * @return int Term ID.
	 */
	private function create_type( $name, $prefix, $has_management ) {
		$term = wp_insert_term( $name . ' ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, Documentate_Document_Data::TERM_META_PREFIX, $prefix );

		$fields = array(
			array(
				'name' => 'objeto',
				'slug' => 'objeto',
				'title' => 'Objeto',
				'type' => 'text',
			),
		);
		if ( $has_management ) {
			$fields[] = array(
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
				'fields' => $fields,
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'acciones.odt',
					'hash' => md5( $name . $prefix ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		return $term_id;
	}

	/**
	 * Create a document of a type in a status, authored by the área.
	 *
	 * @param string $title  Title.
	 * @param int    $type_id Document type term ID.
	 * @param string $status  Post status.
	 * @return int Document ID.
	 */
	private function create_document( $title, $type_id, $status = 'draft' ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( (int) $type_id ) ),
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
	private function capture( callable $handler ) {
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
	private function post_save( $doc_id, array $extra = array() ) {
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
	private function events( $doc_id ) {
		return wp_list_pluck( Documentate_Activity::entries( $doc_id ), 'text' );
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
			'documentate_app_tipo' => (string) $this->management_type_id,
		);

		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_create_document' ) );

		$this->assertStringContainsString( 'vista=nuevo', $target );
		$this->assertStringContainsString( 'error=datos', $target );
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
			'documentate_app_tipo' => (string) $this->management_type_id,
		);

		$target = $this->capture( array( $this->app, 'handle_create_document' ) );
		$doc_id = (int) ( new WP_Query(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'fields' => 'ids',
				'posts_per_page' => 1,
			)
		) )->posts[0];

		$this->assertStringContainsString( 'doc=' . $doc_id, $target );
		$this->assertSame( 'Material aulas', Documentate_Document_Data::internal_name( $doc_id ) );
		$this->assertSame( 'RES · Material aulas', Documentate_Document_Data::short_name( $doc_id ) );
		$this->assertContains( 'creó el borrador', $this->events( $doc_id ) );
	}

	/**
	 * Saving stores the internal name; only gestión may store the notes.
	 */
	public function test_save_stores_name_and_notes_by_role() {
		$doc_id = $this->create_document( 'Borrador con notas', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_anotaciones' => 'Nota del área' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $target );
		$this->assertStringContainsString( 'vista=editar', $target );
		$this->assertSame( 'Material aulas', Documentate_Document_Data::internal_name( $doc_id ) );
		$this->assertSame( '', Documentate_Document_Data::notes( $doc_id ), 'The área cannot write the notes.' );
		$this->assertSame( 'Resolución de material para las aulas', get_post_field( 'post_title', $doc_id ) );

		wp_set_current_user( $this->management_id );
		$this->post_save( $doc_id, array( 'documentate_app_anotaciones' => 'Falta el anexo' ) );
		$this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertSame( 'Falta el anexo', Documentate_Document_Data::notes( $doc_id ) );
	}

	/**
	 * Saving never posts a status: the document stays where it was.
	 */
	public function test_save_keeps_the_status() {
		$doc_id = $this->create_document( 'En gestión guardado', $this->management_type_id, 'en_gestion' );

		wp_set_current_user( $this->management_id );
		$this->post_save( $doc_id );
		$this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
	}

	/**
	 * Sending a document of a type with gestión lands it in "En gestión".
	 */
	public function test_save_with_transition_sends_to_management() {
		$doc_id = $this->create_document( 'Borrador para gestión', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_transicion' => 'enviar_gestion' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'doc=' . $doc_id, $target );
		$this->assertStringContainsString( 'enviado=1', $target );
		$this->assertStringNotContainsString( 'vista=editar', $target );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertContains( 'envió el documento a gestión', $this->events( $doc_id ) );
	}

	/**
	 * A transition that does not apply to the status comes back with an error.
	 */
	public function test_save_with_unavailable_transition_fails() {
		$doc_id = $this->create_document( 'Borrador sin aprobar', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_transicion' => 'aprobar' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=transicion', $target );
		$this->assertSame( 'draft', get_post_status( $doc_id ) );
	}

	/**
	 * A return without a reason is refused and says why.
	 */
	public function test_return_without_reason_is_refused() {
		$doc_id = $this->create_document( 'En gestión sin motivo', $this->management_type_id, 'en_gestion' );

		wp_set_current_user( $this->management_id );
		$this->post_save(
			$doc_id,
			array(
				'documentate_app_transicion' => 'devolver_area',
				'documentate_app_motivo' => '  ',
			)
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=motivo', $target );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertNull( Documentate_Document_Data::returned( $doc_id ) );
	}

	/**
	 * Gestión returns a document to its área and lands back in its tray.
	 */
	public function test_management_returns_to_the_area() {
		$doc_id = $this->create_document( 'En gestión devuelto', $this->management_type_id, 'en_gestion' );

		wp_set_current_user( $this->management_id );
		$this->post_save(
			$doc_id,
			array(
				'documentate_app_transicion' => 'devolver_area',
				'documentate_app_motivo' => 'Falta el anexo firmado',
			)
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'bandeja=revisar', $target );
		$this->assertStringContainsString( 'devuelto=1', $target );
		$this->assertSame( 'draft', get_post_status( $doc_id ) );

		$returned = Documentate_Document_Data::returned( $doc_id );
		$this->assertSame( 'Falta el anexo firmado', $returned['motivo'] );
		$this->assertSame( 'gestion', $returned['desde'] );
		$this->assertSame( 'area', $returned['a'] );
	}

	/**
	 * Administración returns a document and lands back in its own tray.
	 */
	public function test_administration_returns_from_its_tray() {
		$doc_id = $this->create_document( 'En revisión devuelto', $this->management_type_id, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'devolver_gestion',
			'documentate_app_motivo' => 'Falta el número de expediente',
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_transition' ) );

		$this->assertStringContainsString( 'bandeja=revision', $target );
		$this->assertStringContainsString( 'devuelto=1', $target );
		$this->assertSame( 'en_gestion', get_post_status( $doc_id ) );
		$this->assertSame( 'administracion', Documentate_Document_Data::returned( $doc_id )['desde'] );
	}

	/**
	 * Approving from the document view lands on the document with its flag.
	 */
	public function test_transition_approves_from_the_document_view() {
		$doc_id = $this->create_document( 'Pendiente de aprobar', $this->management_type_id, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'aprobar',
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_transition' ) );

		$this->assertStringContainsString( 'aprobado=1', $target );
		$this->assertSame( 'publish', get_post_status( $doc_id ) );
		$this->assertContains( 'aprobó y publicó el documento', $this->events( $doc_id ) );
	}

	/**
	 * Archiving belongs to wp-admin, even for whoever posts its key by hand.
	 */
	public function test_the_application_never_archives_a_document() {
		$doc_id = $this->create_document( 'Aprobado y publicado', $this->management_type_id, 'publish' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'archivar',
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_transition' ) );

		$this->assertStringContainsString( 'error=transicion', $target );
		$this->assertSame( 'publish', get_post_status( $doc_id ) );
	}

	/**
	 * The transition handler refuses a bad nonce.
	 */
	public function test_transition_rejects_a_bad_nonce() {
		$doc_id = $this->create_document( 'Pendiente nonce', $this->management_type_id, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => 'nope',
			'documentate_app_transicion' => 'aprobar',
		);

		$this->expectException( 'WPDieException' );
		Documentate_App_Actions::handle_transition();
	}

	/**
	 * The transition handler refuses a document outside the user's scope.
	 */
	public function test_transition_refuses_a_document_out_of_scope() {
		$other = wp_insert_term( 'Otra área ' . uniqid(), 'category' );
		$doc_id = $this->create_document( 'Fuera de ámbito', $this->management_type_id, 'pending' );
		wp_set_object_terms( $doc_id, array( (int) $other['term_id'] ), 'category' );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
			'documentate_app_transicion' => 'aprobar',
		);

		$this->expectException( 'WPDieException' );
		Documentate_App_Actions::handle_transition();
	}

	/**
	 * The transition handler says so when no action was posted.
	 */
	public function test_transition_without_a_key_reports_the_error() {
		$doc_id = $this->create_document( 'Sin acción', $this->management_type_id, 'pending' );

		wp_set_current_user( $this->admin_id );
		$_POST = array(
			'documentate_app_accion' => 'transicion',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_transicion_' . $doc_id ),
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_transition' ) );

		$this->assertStringContainsString( 'error=transicion', $target );
		$this->assertSame( 'pending', get_post_status( $doc_id ) );
	}

	/**
	 * A comment is stored and shown in the activity.
	 */
	public function test_comment_is_stored_and_comes_back_to_the_view() {
		$doc_id = $this->create_document( 'Con comentario', $this->management_type_id, 'en_gestion' );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'comentar',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_comentar_' . $doc_id ),
			'documentate_app_comentario' => 'El anexo va en la última página del ODT',
			'documentate_app_redirect_to' => 'detalle',
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_comment' ) );

		$this->assertStringContainsString( 'comentado=1', $target );
		$this->assertStringNotContainsString( 'vista=editar', $target );
		$this->assertContains( 'El anexo va en la última página del ODT', $this->events( $doc_id ) );
	}

	/**
	 * An empty comment comes back to the edit view with an error.
	 */
	public function test_empty_comment_is_refused_and_returns_to_the_editor() {
		$doc_id = $this->create_document( 'Comentario vacío', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$_POST = array(
			'documentate_app_accion' => 'comentar',
			'documentate_app_doc' => (string) $doc_id,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_comentar_' . $doc_id ),
			'documentate_app_comentario' => '   ',
			'documentate_app_redirect_to' => 'editar',
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_comment' ) );

		$this->assertStringContainsString( 'vista=editar', $target );
		$this->assertStringContainsString( 'error=comentario', $target );
		$this->assertSame( array(), $this->events( $doc_id ) );
	}

	/**
	 * Ticking "Quitar" detaches the file and records it.
	 */
	public function test_save_removes_the_attachment_when_asked_to() {
		$doc_id = $this->create_document( 'Con adjunto', $this->management_type_id );
		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'resolucion.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
				'post_title' => 'resolucion.pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment ) );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_quitar_adjunto' => '1' ) );
		$this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertNull( Documentate_Document_Data::attachment( $doc_id ) );
		$this->assertContains( 'quitó el fichero «resolucion.pdf»', $this->events( $doc_id ) );
	}

	/**
	 * An empty internal name keeps the stored one instead of erasing it.
	 *
	 * "Guardar" is formnovalidate, so the box can reach the handler empty.
	 */
	public function test_save_falls_back_to_the_stored_internal_name() {
		$doc_id = $this->create_document( 'Borrador con nombre', $this->management_type_id );
		Documentate_Document_Data::save_internal_name( $doc_id, 'Material aulas' );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_nombre' => '   ' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'guardado=1', $target );
		$this->assertSame( 'Material aulas', Documentate_Document_Data::internal_name( $doc_id ), 'The stored name is untouched.' );
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
		$doc_id = $this->create_document( 'Creado en wp-admin', $this->management_type_id );
		Documentate_Document_Data::save_internal_name( $doc_id, '' );

		wp_set_current_user( $this->area_id );
		$this->post_save(
			$doc_id,
			array(
				'documentate_app_nombre' => '',
				'documentate_app_titulo' => 'Resolución con todo el bloque I',
				'documentate_field_objeto' => 'Objeto que costó media hora escribir',
			)
		);
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=nombre', $target );
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
		$doc_id = $this->create_document( 'En gestión desde la bandeja', $this->management_type_id, 'en_gestion' );

		wp_set_current_user( $this->management_id );
		$this->post_save( $doc_id, array( 'documentate_app_bandeja' => 'revisar' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'bandeja=revisar', $target );
		$this->assertStringContainsString( 'guardado=1', $target );
	}

	/**
	 * A tray this person cannot open is not carried over.
	 */
	public function test_save_ignores_a_tray_that_is_not_theirs() {
		$doc_id = $this->create_document( 'Borrador del área', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_bandeja' => 'revision' ) );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringNotContainsString( 'bandeja=', $target );
	}

	/**
	 * Saving with a file stores it and records it in the activity.
	 */
	public function test_save_stores_the_uploaded_file() {
		$doc_id = $this->create_document( 'Con fichero nuevo', $this->management_type_id );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id );
		$this->post_file( 'resolucion-app.pdf' );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$attachment = Documentate_Document_Data::attachment( $doc_id );

		$this->assertStringContainsString( 'guardado=1', $target );
		$this->assertNotNull( $attachment );
		$this->assertSame( array( $attachment->ID ), get_post_meta( $doc_id, Documentate_Document_Data::META_ATTACHMENTS, true ) );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Attachments::name( $attachment->ID ) . '»',
			$this->events( $doc_id )
		);
	}

	/**
	 * A file of another format is refused and the current one is kept.
	 */
	public function test_a_refused_file_never_costs_the_current_one() {
		$doc_id = $this->create_document( 'Con fichero previo', $this->management_type_id );
		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'previo.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment ) );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id, array( 'documentate_app_quitar_adjunto' => '1' ) );
		$this->post_file( 'hoja.xlsx' );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=adjunto', $target );
		$this->assertStringNotContainsString( 'error=adjunto_permiso', $target );
		$this->assertSame( (int) $attachment, Documentate_Document_Data::attachment( $doc_id )->ID, 'The old file is still there.' );
	}

	/**
	 * A user who cannot upload is told so, not that the format is wrong.
	 */
	public function test_a_user_without_uploads_is_told_the_real_reason() {
		$doc_id = $this->create_document( 'Sin permiso de subida', $this->management_type_id );

		// remove_cap() only drops caps stored on the user, and this one comes
		// from the role: denying it explicitly is what overrides the role.
		( new WP_User( $this->area_id ) )->add_cap( 'upload_files', false );

		wp_set_current_user( $this->area_id );
		$this->post_save( $doc_id );
		$this->post_file( 'resolucion-sin-permiso.pdf' );
		$target = $this->capture( array( 'Documentate_App_Actions', 'handle_save_document' ) );

		$this->assertStringContainsString( 'error=adjunto_permiso', $target );
		$this->assertNull( Documentate_Document_Data::attachment( $doc_id ) );
		$this->assertStringContainsString(
			'Tu usuario no puede subir ficheros',
			Documentate_App_Detail::error_text( 'adjunto_permiso' )
		);
	}

	/**
	 * Handlers ignore requests that do not carry their action.
	 */
	public function test_handlers_ignore_other_requests() {
		wp_set_current_user( $this->admin_id );
		$_POST = array( 'documentate_app_accion' => 'otra_cosa' );

		Documentate_App_Actions::handle_create_document();
		Documentate_App_Actions::handle_save_document();
		Documentate_App_Actions::handle_transition();
		Documentate_App_Actions::handle_comment();

		$this->assertSame( 'otra_cosa', $_POST['documentate_app_accion'] );
	}
}
