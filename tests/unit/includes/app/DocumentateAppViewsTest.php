<?php
/**
 * Tests for the document views of the front-end application.
 *
 * What each rol sees of a document, in each status: the notices, the cards,
 * the stepper, the actions and the dialogs.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_App_Detail
 * @covers Documentate_App_Edit
 * @covers Documentate_App
 */
class DocumentateAppViewsTest extends WP_UnitTestCase {

	/**
	 * Application instance under test.
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
	 * Roles, scope, types and the application page.
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
		$this->area_id = self::factory()->user->create(
			array(
				'role' => 'author',
				'display_name' => 'Ana Área',
			)
		);

		$area = wp_insert_term( 'Área vistas ' . uniqid(), 'category' );
		$this->cat_id = (int) $area['term_id'];
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );

		$this->management_type_id = $this->create_type( 'Resolución vistas', 'RES', true );
		$this->direct_type_id = $this->create_type( 'Convocatoria vistas', 'CONV', false );
	}

	/**
	 * Reset the request state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_GET = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * A document type with a schema, a prefix and maybe a gestión field.
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
					'template_name' => 'vistas.odt',
					'hash' => md5( $name . $prefix ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		return $term_id;
	}

	/**
	 * Create a document with its internal name and field values.
	 *
	 * @param string $status  Post status.
	 * @param int    $type_id Document type term ID.
	 * @return int
	 */
	private function create_document( $status, $type_id = 0 ) {
		$type_id = $type_id > 0 ? $type_id : $this->management_type_id;

		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución de material para las aulas digitales',
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( (int) $type_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		Documentate_Document_Data::save_internal_name( $post_id, 'Material aulas' );
		update_post_meta( $post_id, 'documentate_field_objeto', 'Compra de material' );
		update_post_meta( $post_id, 'documentate_field_numero_resolucion', '118/2026' );
		wp_set_current_user( 0 );

		return (int) $post_id;
	}

	/**
	 * Render the document view as a user.
	 *
	 * @param int   $user_id User to render as.
	 * @param int   $doc_id  Document ID.
	 * @param array $args    Extra query arguments.
	 * @return string HTML.
	 */
	private function detail_view( $user_id, $doc_id, array $args = array() ) {
		wp_set_current_user( $user_id );
		$_GET = array_merge( array( 'doc' => (string) $doc_id ), $args );

		return $this->app->render();
	}

	/**
	 * Render the edit view as a user.
	 *
	 * @param int $user_id User to render as.
	 * @param int $doc_id  Document ID.
	 * @return string HTML.
	 */
	private function edit_view( $user_id, $doc_id ) {
		wp_set_current_user( $user_id );
		$_GET = array(
			'doc' => (string) $doc_id,
			'vista' => 'editar',
		);

		return $this->app->render();
	}

	/**
	 * The document view names the document, its área and its person.
	 */
	public function test_the_document_view_introduces_the_document() {
		$doc_id = $this->create_document( 'draft' );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'RES · Material aulas', $html );
		$this->assertStringContainsString( 'Ana Área', $html );
		$this->assertStringContainsString( 'Datos básicos', $html );
		$this->assertStringContainsString( 'Resolución de material para las aulas digitales', $html );
		$this->assertStringContainsString( 'Compra de material', $html );
	}

	/**
	 * Each status explains itself to the área.
	 *
	 * @dataProvider status_notice_data
	 * @param string $status Post status.
	 * @param string $text  Fragment of the notice.
	 */
	public function test_each_status_explains_itself( $status, $text ) {
		$doc_id = $this->create_document( $status );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( $text, $html );
	}

	/**
	 * Statuses and the notice each one shows.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function status_notice_data() {
		return array(
			'in management' => array( 'en_gestion', 'están completando los datos oficiales' ),
			'in review' => array( 'pending', 'administración lo aprobará o lo devolverá' ),
			'approved' => array( 'publish', 'Puedes previsualizarlo y descargarlo' ),
			'archived' => array( 'archived', 'Archivado.' ),
		);
	}

	/**
	 * A returned document says who returned it and why.
	 */
	public function test_a_returned_document_shows_the_reason() {
		$doc_id = $this->create_document( 'draft' );
		Documentate_Document_Data::mark_returned( $doc_id, 'Falta el anexo firmado', 'gestion', 'area', $this->management_id );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'dcta-aviso-devuelto', $html );
		$this->assertStringContainsString( 'Devuelto por gestión documental', $html );
		$this->assertStringContainsString( 'Falta el anexo firmado', $html );
		// The reason and the instruction are two sentences, not one run-on line.
		$this->assertStringContainsString( '». Corrige lo que haga falta y vuelve a enviarlo.', $html );
		$this->assertStringContainsString( 'dcta-estado-devuelto', $html );
	}

	/**
	 * A return addressed to gestión documental is not shown to the área.
	 *
	 * Administración returning an en_gestion document writes a note to
	 * gestión: the área cannot even open the document while it is there, so
	 * telling them to correct it contradicts the status notice right below and
	 * leaks an internal note.
	 */
	public function test_a_return_addressed_to_management_stays_between_management_and_administration() {
		$doc_id = $this->create_document( 'en_gestion' );
		Documentate_Document_Data::mark_returned( $doc_id, 'Falta el número de expediente', 'administracion', 'gestion', $this->admin_id );

		$area = $this->detail_view( $this->area_id, $doc_id );
		$this->assertStringNotContainsString( 'dcta-aviso-devuelto', $area );
		$this->assertStringNotContainsString( 'Falta el número de expediente', $area );
		$this->assertStringNotContainsString( 'Corrige lo que haga falta', $area );
		$this->assertStringContainsString( 'En gestión documental: están completando los datos oficiales.', $area );

		$management = $this->detail_view( $this->management_id, $doc_id );
		$this->assertStringContainsString( 'dcta-aviso-devuelto', $management );
		$this->assertStringContainsString( 'Falta el número de expediente', $management );
		$this->assertStringContainsString( 'Corrige lo que haga falta y vuelve a enviarlo.', $management );
	}

	/**
	 * The call to action is only for whoever can act on it.
	 */
	public function test_a_returned_document_nobody_can_edit_gives_no_instructions() {
		$doc_id = $this->create_document( 'pending' );
		Documentate_Document_Data::mark_returned( $doc_id, 'Revisar la partida', 'gestion', 'area', $this->management_id );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'Revisar la partida', $html );
		$this->assertStringNotContainsString( 'Corrige lo que haga falta', $html, 'The document is locked for its área.' );
	}

	/**
	 * The stepper keeps the step the document is standing on.
	 *
	 * A type stops going through gestión documental (its flag is unchecked, or
	 * its template loses the rol="gestion" fields) while a document of that
	 * type is already in en_gestion: the rail must not answer "Borrador".
	 */
	public function test_the_stepper_keeps_a_step_the_type_no_longer_declares() {
		$doc_id = $this->create_document( 'en_gestion' );
		update_term_meta( $this->management_type_id, Documentate_Document_Data::TERM_META_HAS_MANAGEMENT, '' );
		( new SchemaStorage() )->save_schema(
			$this->management_type_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'objeto',
						'slug' => 'objeto',
						'title' => 'Objeto',
						'type' => 'text',
					),
				),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'vistas.odt',
					'hash' => md5( 'sin gestion' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);
		$this->assertFalse( Documentate_Document_Data::has_management( $doc_id ) );

		$html = $this->detail_view( $this->management_id, $doc_id );

		$this->assertStringContainsString( 'Completando datos oficiales', $html, 'The step it stands on is the current one.' );
		$this->assertStringNotContainsString( 'dcta-paso-actual"><span class="dcta-paso-punto" aria-hidden="true"></span><span class="dcta-paso-t">Borrador', $html );
	}

	/**
	 * Dates are written the Spanish way, whatever the site options say.
	 *
	 * WordPress ships US defaults for date_format, and a Spain-only interface
	 * that says "septiembre 2, 2026" reads as a bug.
	 */
	public function test_dates_are_written_in_spanish_order() {
		update_option( 'date_format', 'F j, Y' );
		update_option( 'time_format', 'g:i a' );
		$doc_id = $this->create_document( 'publish' );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$expected = get_the_modified_date( 'j \d\e F \d\e Y', get_post( $doc_id ) );
		$this->assertStringContainsString( 'actualizado el ' . $expected, $html );
		$this->assertStringContainsString( 'Aprobado el ' . $expected, $html );
		$this->assertStringNotContainsString( 'actualizado el ' . get_the_modified_date( 'F j, Y', get_post( $doc_id ) ), $html );
	}

	/**
	 * The stepper marks what is done, what is happening and what is left.
	 */
	public function test_the_stepper_places_the_document() {
		$doc_id = $this->create_document( 'en_gestion' );

		$html = $this->detail_view( $this->management_id, $doc_id );

		$this->assertStringContainsString( 'dcta-stepper', $html );
		$this->assertStringContainsString( '<h2 class="dcta-h2">Estado</h2>', $html );
		$this->assertStringContainsString( '<h2 class="dcta-h2">Acciones</h2>', $html );
		$this->assertStringContainsString( 'dcta-paso-hecho', $html );
		$this->assertStringContainsString( 'dcta-paso-actual', $html );
		$this->assertStringContainsString( 'dcta-paso-futuro', $html );
		$this->assertStringContainsString( 'Completando datos oficiales', $html );
		$this->assertStringContainsString( 'En gestión', $html );
	}

	/**
	 * A type that skips gestión has no gestión step.
	 */
	public function test_a_direct_type_has_no_management_step() {
		$doc_id = $this->create_document( 'draft', $this->direct_type_id );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'dcta-stepper', $html );
		$this->assertStringNotContainsString( 'Completando datos oficiales', $html );
	}

	/**
	 * The activity card lists what happened and takes comments.
	 */
	public function test_the_activity_card_lists_and_takes_comments() {
		$doc_id = $this->create_document( 'draft' );
		wp_set_current_user( $this->area_id );
		Documentate_Activity::record_event( $doc_id, 'creó el borrador' );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'Actividad', $html );
		$this->assertStringContainsString( 'creó el borrador', $html );
		$this->assertStringContainsString( 'name="documentate_app_comentario"', $html );
		$this->assertStringContainsString( 'value="comentar"', $html );
		$this->assertStringContainsString( 'id="dcta-app-comentario"', $html );
	}

	/**
	 * The file card names the file, its size and who attached it.
	 */
	public function test_the_file_card_describes_the_attachment() {
		$doc_id = $this->create_document( 'draft' );
		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $doc_id,
				'post_author' => $this->area_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment ) );

		$html = $this->detail_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'Fichero del documento', $html );
		$this->assertStringContainsString( 'anexo.pdf', $html );
		$this->assertStringContainsString( 'adjuntado por Ana Área', $html );
		$this->assertStringContainsString( 'Abrir', $html );
	}

	/**
	 * The rail carries the export block and the way back.
	 */
	public function test_the_rail_carries_exports_and_the_way_back() {
		$doc_id = $this->create_document( 'publish' );

		$html = $this->detail_view( $this->admin_id, $doc_id );

		$this->assertStringContainsString( 'id="exportar"', $html );
		$this->assertStringContainsString( 'dcta-exportar', $html );
		$this->assertStringContainsString( '← Todos los documentos', $html );
		$this->assertStringContainsString( 'post.php', $html, 'Administración can still open wp-admin.' );
	}

	/**
	 * The transitions offered on the document view depend on the rol.
	 */
	public function test_the_document_view_offers_the_transitions_of_the_role() {
		$doc_id = $this->create_document( 'en_gestion' );

		$management = $this->detail_view( $this->management_id, $doc_id );
		$this->assertStringContainsString( 'value="transicion"', $management );
		$this->assertStringContainsString( 'value="pasar_admin"', $management );
		$this->assertStringContainsString( 'value="devolver_area"', $management );
		$this->assertStringContainsString( 'data-motivo="1"', $management );
		$this->assertStringContainsString( 'data-confirmar="', $management );

		$area = $this->detail_view( $this->area_id, $doc_id );
		$this->assertStringNotContainsString( 'value="pasar_admin"', $area );
		$this->assertStringNotContainsString( 'value="devolver_area"', $area );
	}

	/**
	 * Archiving stays in wp-admin, where its links have always been.
	 */
	public function test_the_application_never_archives_a_document() {
		$published = $this->detail_view( $this->admin_id, $this->create_document( 'publish' ) );
		$this->assertStringNotContainsString( 'value="archivar"', $published );
		$this->assertStringNotContainsString( '>Archivar<', $published );

		$archived = $this->detail_view( $this->admin_id, $this->create_document( 'archived' ) );
		$this->assertStringNotContainsString( 'value="desarchivar"', $archived );
		$this->assertStringNotContainsString( '>Desarchivar<', $archived );
	}

	/**
	 * The dialogs are printed after the footer, disabled until JavaScript opens them.
	 */
	public function test_the_dialogs_are_printed_disabled_after_the_footer() {
		$doc_id = $this->create_document( 'en_gestion' );

		$html = $this->detail_view( $this->management_id, $doc_id );

		$this->assertStringContainsString( 'id="dcta-dialogo-motivo"', $html );
		$this->assertStringContainsString( 'id="dcta-dialogo-confirmar"', $html );
		$this->assertMatchesRegularExpression( '/dcta-pie.*dcta-dialogo-motivo/s', $html );
		$this->assertMatchesRegularExpression(
			'/id="dcta-dialogo-motivo-texto"[^>]*disabled/',
			$html,
			'The reason box never posts unless JavaScript enables it.'
		);
		$this->assertStringNotContainsString( 'required', substr( $html, strpos( $html, 'dcta-dialogo-motivo' ) ) );
	}

	/**
	 * Feedback flags are turned into sentences.
	 *
	 * @dataProvider flag_data
	 * @param string $flag Query argument.
	 * @param string $value   Its value.
	 * @param string $css_class   Notice class.
	 * @param string $text   Fragment of the text.
	 */
	public function test_feedback_flags_are_turned_into_sentences( $flag, $value, $css_class, $text ) {
		$doc_id = $this->create_document( 'pending' );

		$html = $this->detail_view( $this->admin_id, $doc_id, array( $flag => $value ) );

		$this->assertStringContainsString( $css_class, $html );
		$this->assertStringContainsString( $text, $html );
	}

	/**
	 * Flags and the sentence each one shows.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function flag_data() {
		return array(
			'sent' => array( 'enviado', '1', 'dcta-aviso-ok', 'Documento enviado a revisión' ),
			'approved' => array( 'aprobado', '1', 'dcta-aviso-ok', 'Documento aprobado y publicado.' ),
			'commented' => array( 'comentado', '1', 'dcta-aviso-ok', 'Comentario añadido.' ),
			'reason error' => array( 'error', 'motivo', 'dcta-aviso-mal', 'hay que decir por qué' ),
			'attachment error' => array( 'error', 'adjunto', 'dcta-aviso-mal', 'solo PDF, ODT o DOCX' ),
			'transition error' => array( 'error', 'transicion', 'dcta-aviso-mal', 'no está disponible' ),
		);
	}

	/**
	 * The error flag is read from the URI, not only from $_GET.
	 *
	 * "error" is a reserved query variable of WordPress: as soon as a rewrite
	 * rule matches, WP::parse_request() does unset( $error, $_GET['error'] ),
	 * so on a site with pretty permalinks the views would never see it.
	 */
	public function test_the_error_flag_is_read_from_the_request_uri() {
		$doc_id = $this->create_document( 'pending' );
		$previous_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		try {
			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id . '&error=motivo';
			$detail = $this->detail_view( $this->admin_id, $doc_id );
			$this->assertStringContainsString( 'dcta-aviso-mal', $detail );
			$this->assertStringContainsString( 'hay que decir por qué', $detail );

			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id . '&vista=editar&error=adjunto';
			$edit_view = $this->edit_view( $this->admin_id, $doc_id );
			$this->assertStringContainsString( 'dcta-aviso-mal', $edit_view );
			$this->assertStringContainsString( 'solo PDF, ODT o DOCX', $edit_view );

			// A request without the argument says nothing.
			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id;
			$this->assertStringNotContainsString(
				'dcta-aviso-mal',
				$this->detail_view( $this->admin_id, $doc_id )
			);
		} finally {
			if ( null === $previous_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_uri;
			}
		}
	}

	/**
	 * The edit view explains where this type of document goes.
	 */
	public function test_the_editor_explains_where_the_document_goes() {
		$has_management = $this->edit_view( $this->area_id, $this->create_document( 'draft' ) );
		$this->assertStringContainsString( 'dcta-aviso-info', $has_management );
		$this->assertStringContainsString( 'pasa por gestión documental', $has_management );

		$direct = $this->edit_view( $this->area_id, $this->create_document( 'draft', $this->direct_type_id ) );
		$this->assertStringContainsString( 'va directo a administración', $direct );
	}

	/**
	 * A document with no type can be given one from the editor.
	 *
	 * Documents saved in wp-admin without picking a type used to be a dead end
	 * in the application: no fields, a read-only type box and a "Enviar a
	 * revisión" button that could only fail.
	 */
	public function test_a_document_without_a_type_is_given_one_here() {
		wp_set_current_user( $this->admin_id );
		$doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Guardado en wp-admin sin tipo',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_object_terms( $doc_id, array( $this->cat_id ), 'category' );

		$html = $this->edit_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'id="documentate-app-tipo"', $html );
		$this->assertStringContainsString( 'name="documentate_doc_type"', $html );
		$this->assertStringContainsString( 'name="documentate_type_nonce"', $html );
		$this->assertStringNotContainsString( 'id="dcta-tipo-fijo"', $html );
		$this->assertStringContainsString( 'todavía no tiene tipo de documento', $html );
		$this->assertStringNotContainsString( 'value="enviar_revision"', $html, 'Nothing that could only fail is drawn.' );
	}

	/**
	 * The edit view asks for the internal name with the prefix of the type.
	 */
	public function test_the_editor_asks_for_the_internal_name_with_its_prefix() {
		$html = $this->edit_view( $this->area_id, $this->create_document( 'draft' ) );

		$this->assertStringContainsString( 'id="documentate-app-nombre"', $html );
		$this->assertStringContainsString( 'name="documentate_app_nombre"', $html );
		$this->assertStringContainsString( 'value="Material aulas"', $html );
		$this->assertStringContainsString( '<span class="dcta-prefijo">RES</span>', $html );
		$this->assertStringContainsString( 'id="documentate-app-titulo"', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
	}

	/**
	 * The área never sees the official fields, nor the internal notes.
	 */
	public function test_the_area_sees_neither_the_official_fields_nor_the_notes() {
		$doc_id = $this->create_document( 'draft' );
		Documentate_Document_Data::save_notes( $doc_id, 'Pendiente de expediente' );

		$html = $this->edit_view( $this->area_id, $doc_id );

		$this->assertStringNotContainsString( 'documentate_field_numero_resolucion', $html );
		$this->assertStringNotContainsString( 'documentate_app_anotaciones', $html );
		$this->assertStringNotContainsString( 'Pendiente de expediente', $html );
	}

	/**
	 * Gestión sees the official fields and writes the internal notes.
	 */
	public function test_management_sees_the_official_fields_and_the_notes() {
		$doc_id = $this->create_document( 'en_gestion' );
		Documentate_Document_Data::save_notes( $doc_id, 'Pendiente de expediente' );

		$html = $this->edit_view( $this->management_id, $doc_id );

		$this->assertStringContainsString( 'documentate_field_numero_resolucion', $html );
		$this->assertStringContainsString( 'documentate_app_anotaciones', $html );
		$this->assertStringContainsString( 'Pendiente de expediente', $html );
		$this->assertStringContainsString( 'Solo las ven gestión y administración', $html );
	}

	/**
	 * Gestión folds the área data away and writes its notes inside its own section.
	 */
	public function test_management_folds_the_area_data_and_keeps_the_notes_with_the_official_ones() {
		$doc_id = $this->create_document( 'en_gestion' );

		$html = $this->edit_view( $this->management_id, $doc_id );

		$this->assertStringContainsString( '<details class="dcta-seccion-area" open><summary>Datos del área</summary>', $html );
		$this->assertStringContainsString( 'Datos oficiales · los completa gestión documental', $html );

		$foldable = strpos( $html, 'dcta-seccion-area' );
		$official = strpos( $html, 'Datos oficiales · los completa gestión documental' );
		$notes = strpos( $html, 'documentate_app_anotaciones' );
		$this->assertLessThan( $official, $foldable, 'The área rows come first.' );
		$this->assertLessThan( $notes, $official, 'The notes belong to the official section.' );

		$area = $this->edit_view( $this->area_id, $this->create_document( 'draft' ) );
		$this->assertStringNotContainsString( 'dcta-seccion-area', $area, 'The área has nothing to fold away.' );
	}

	/**
	 * The file card of the editor takes a new file and offers to drop the old one.
	 */
	public function test_the_editor_takes_a_file_and_offers_to_drop_it() {
		$doc_id = $this->create_document( 'draft' );
		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( (int) $attachment ) );

		$html = $this->edit_view( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'dcta-dropzone', $html );
		$this->assertStringContainsString( 'Arrastra aquí el fichero del documento', $html );
		$this->assertStringContainsString( 'PDF, ODT o DOCX · máximo 20 MB', $html );
		$this->assertStringContainsString( 'name="documentate_app_adjunto"', $html );
		$this->assertStringContainsString( 'accept=".pdf,.odt,.docx"', $html );
		$this->assertStringContainsString( 'name="documentate_app_quitar_adjunto"', $html );
		$this->assertStringContainsString( 'anexo.pdf', $html );
	}

	/**
	 * The rail of the editor saves without validating and offers the transitions.
	 */
	public function test_the_rail_saves_and_sends() {
		$html = $this->edit_view( $this->area_id, $this->create_document( 'draft' ) );

		$this->assertStringContainsString( '<h2 class="dcta-h2">Acciones</h2>', $html );
		$this->assertStringContainsString( 'name="documentate_app_estado" value="guardar" formnovalidate', $html );
		$this->assertStringContainsString( 'value="enviar_gestion"', $html );
		$this->assertStringContainsString( 'Enviar a gestión', $html );
		$this->assertStringContainsString( 'id="exportar"', $html );
	}

	/**
	 * Administración returns a document with a single button and the dialog asks where to.
	 */
	public function test_administration_returns_with_one_button_and_a_choice() {
		$html = $this->edit_view( $this->admin_id, $this->create_document( 'pending' ) );

		$this->assertStringContainsString( 'data-destinos="1"', $html );
		$this->assertStringContainsString( 'Devolver…', $html );
		$this->assertStringContainsString( 'value="aprobar"', $html );
		$this->assertStringContainsString( 'dcta-motivo-fallback', $html );
		$this->assertStringContainsString( 'value="devolver_gestion"', $html );
		$this->assertStringContainsString( 'name="documentate_app_motivo"', $html );
		$this->assertStringNotContainsString( 'name="documentate_app_motivo" required', $html );
	}

	/**
	 * Gestión returns to the área with a plain reason button.
	 */
	public function test_management_returns_to_the_area_with_one_reason() {
		$html = $this->edit_view( $this->management_id, $this->create_document( 'en_gestion' ) );

		$this->assertStringContainsString( 'value="pasar_admin"', $html );
		$this->assertStringContainsString( 'value="devolver_area"', $html );
		$this->assertStringNotContainsString( 'data-destinos', $html );
	}

	/**
	 * A document out of the hands of the área is read-only for it.
	 */
	public function test_the_area_cannot_edit_a_document_in_management() {
		$html = $this->edit_view( $this->area_id, $this->create_document( 'en_gestion' ) );

		$this->assertStringContainsString( 'bloqueado', $html );
		$this->assertStringNotContainsString( 'documentate_sections_nonce', $html );
	}

	/**
	 * The new-document form offers the types with their prefix and their route.
	 */
	public function test_the_new_document_form_describes_the_types() {
		wp_set_current_user( $this->area_id );
		$_GET = array( 'vista' => 'nuevo' );

		$html = $this->app->render();

		$this->assertStringContainsString( 'id="documentate-app-tipo"', $html );
		$this->assertStringContainsString( 'data-prefijo="RES"', $html );
		$this->assertStringContainsString( 'data-gestion="1"', $html );
		$this->assertStringContainsString( 'data-prefijo="CONV"', $html );
		$this->assertStringContainsString( 'id="documentate-app-tipo-nota"', $html );
		$this->assertStringContainsString( 'id="documentate-app-prefijo"', $html );
		$this->assertStringContainsString( 'name="documentate_app_nombre"', $html );
		$this->assertStringContainsString( 'name="documentate_app_titulo"', $html );
		$this->assertStringContainsString( 'Crear borrador', $html );
	}
}
