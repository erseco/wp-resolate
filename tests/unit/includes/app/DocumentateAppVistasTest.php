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
 * @covers Documentate_App_Detalle
 * @covers Documentate_App_Editar
 * @covers Documentate_App
 */
class DocumentateAppVistasTest extends WP_UnitTestCase {

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
	 * Roles, scope, types and the application page.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Roles::ensure_caps( true );
		( new Documentate_Workflow() )->register_custom_statuses();

		$this->app = new Documentate_App();
		$this->app->ensure_page();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->area_id = self::factory()->user->create(
			array(
				'role' => 'author',
				'display_name' => 'Ana Área',
			)
		);

		$area = wp_insert_term( 'Área vistas ' . uniqid(), 'category' );
		$this->cat_id = (int) $area['term_id'];
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );

		$this->tipo_gestion = $this->crear_tipo( 'Resolución vistas', 'RES', true );
		$this->tipo_directo = $this->crear_tipo( 'Convocatoria vistas', 'CONV', false );
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
					'template_name' => 'vistas.odt',
					'hash' => md5( $nombre . $prefijo ),
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
	 * @param int    $tipo_id Document type term ID.
	 * @return int
	 */
	private function crear_documento( $status, $tipo_id = 0 ) {
		$tipo_id = $tipo_id > 0 ? $tipo_id : $this->tipo_gestion;

		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución de material para las aulas digitales',
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( (int) $tipo_id ) ),
			)
		);
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		Documentate_Documento::guardar_nombre_interno( $post_id, 'Material aulas' );
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
	private function detalle( $user_id, $doc_id, array $args = array() ) {
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
	private function editar( $user_id, $doc_id ) {
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
		$doc_id = $this->crear_documento( 'draft' );

		$html = $this->detalle( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'RES · Material aulas', $html );
		$this->assertStringContainsString( 'Ana Área', $html );
		$this->assertStringContainsString( 'Datos básicos', $html );
		$this->assertStringContainsString( 'Resolución de material para las aulas digitales', $html );
		$this->assertStringContainsString( 'Compra de material', $html );
	}

	/**
	 * Each status explains itself to the área.
	 *
	 * @dataProvider datos_avisos_de_estado
	 * @param string $status Post status.
	 * @param string $texto  Fragment of the notice.
	 */
	public function test_each_status_explains_itself( $status, $texto ) {
		$doc_id = $this->crear_documento( $status );

		$html = $this->detalle( $this->area_id, $doc_id );

		$this->assertStringContainsString( $texto, $html );
	}

	/**
	 * Statuses and the notice each one shows.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function datos_avisos_de_estado() {
		return array(
			'en gestión' => array( 'en_gestion', 'están completando los datos oficiales' ),
			'en revisión' => array( 'pending', 'administración lo aprobará o lo devolverá' ),
			'aprobado' => array( 'publish', 'Puedes previsualizarlo y descargarlo' ),
			'archivado' => array( 'archived', 'Archivado.' ),
		);
	}

	/**
	 * A returned document says who returned it and why.
	 */
	public function test_a_returned_document_shows_the_reason() {
		$doc_id = $this->crear_documento( 'draft' );
		Documentate_Documento::marcar_devuelto( $doc_id, 'Falta el anexo firmado', 'gestion', 'area', $this->gestion_id );

		$html = $this->detalle( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'dcta-aviso-devuelto', $html );
		$this->assertStringContainsString( 'Devuelto por gestión documental', $html );
		$this->assertStringContainsString( 'Falta el anexo firmado', $html );
		// The reason and the instruction are two sentences, not one run-on line.
		$this->assertStringContainsString( '». Corrige lo que haga falta y vuelve a enviarlo.', $html );
		$this->assertStringContainsString( 'dcta-estado-devuelto', $html );
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
		$doc_id = $this->crear_documento( 'publish' );

		$html = $this->detalle( $this->area_id, $doc_id );

		$esperada = get_the_modified_date( 'j \d\e F \d\e Y', get_post( $doc_id ) );
		$this->assertStringContainsString( 'actualizado el ' . $esperada, $html );
		$this->assertStringContainsString( 'Aprobado el ' . $esperada, $html );
		$this->assertStringNotContainsString( 'actualizado el ' . get_the_modified_date( 'F j, Y', get_post( $doc_id ) ), $html );
	}

	/**
	 * The stepper marks what is done, what is happening and what is left.
	 */
	public function test_the_stepper_places_the_document() {
		$doc_id = $this->crear_documento( 'en_gestion' );

		$html = $this->detalle( $this->gestion_id, $doc_id );

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
	public function test_a_direct_type_has_no_gestion_step() {
		$doc_id = $this->crear_documento( 'draft', $this->tipo_directo );

		$html = $this->detalle( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'dcta-stepper', $html );
		$this->assertStringNotContainsString( 'Completando datos oficiales', $html );
	}

	/**
	 * The activity card lists what happened and takes comments.
	 */
	public function test_the_activity_card_lists_and_takes_comments() {
		$doc_id = $this->crear_documento( 'draft' );
		wp_set_current_user( $this->area_id );
		Documentate_Actividad::registrar_evento( $doc_id, 'creó el borrador' );

		$html = $this->detalle( $this->area_id, $doc_id );

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
		$doc_id = $this->crear_documento( 'draft' );
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $doc_id,
				'post_author' => $this->area_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Documento::META_ADJUNTOS, array( (int) $adjunto ) );

		$html = $this->detalle( $this->area_id, $doc_id );

		$this->assertStringContainsString( 'Fichero del documento', $html );
		$this->assertStringContainsString( 'anexo.pdf', $html );
		$this->assertStringContainsString( 'adjuntado por Ana Área', $html );
		$this->assertStringContainsString( 'Abrir', $html );
	}

	/**
	 * The rail carries the export block and the way back.
	 */
	public function test_the_rail_carries_exports_and_the_way_back() {
		$doc_id = $this->crear_documento( 'publish' );

		$html = $this->detalle( $this->admin_id, $doc_id );

		$this->assertStringContainsString( 'id="exportar"', $html );
		$this->assertStringContainsString( 'dcta-exportar', $html );
		$this->assertStringContainsString( '← Todos los documentos', $html );
		$this->assertStringContainsString( 'post.php', $html, 'Administración can still open wp-admin.' );
	}

	/**
	 * The transitions offered on the document view depend on the rol.
	 */
	public function test_the_document_view_offers_the_transitions_of_the_rol() {
		$doc_id = $this->crear_documento( 'en_gestion' );

		$gestion = $this->detalle( $this->gestion_id, $doc_id );
		$this->assertStringContainsString( 'value="transicion"', $gestion );
		$this->assertStringContainsString( 'value="pasar_admin"', $gestion );
		$this->assertStringContainsString( 'value="devolver_area"', $gestion );
		$this->assertStringContainsString( 'data-motivo="1"', $gestion );
		$this->assertStringContainsString( 'data-confirmar="', $gestion );

		$area = $this->detalle( $this->area_id, $doc_id );
		$this->assertStringNotContainsString( 'value="pasar_admin"', $area );
		$this->assertStringNotContainsString( 'value="devolver_area"', $area );
	}

	/**
	 * Archiving stays in wp-admin, where its links have always been.
	 */
	public function test_the_application_never_archives_a_document() {
		$publicado = $this->detalle( $this->admin_id, $this->crear_documento( 'publish' ) );
		$this->assertStringNotContainsString( 'value="archivar"', $publicado );
		$this->assertStringNotContainsString( '>Archivar<', $publicado );

		$archivado = $this->detalle( $this->admin_id, $this->crear_documento( 'archived' ) );
		$this->assertStringNotContainsString( 'value="desarchivar"', $archivado );
		$this->assertStringNotContainsString( '>Desarchivar<', $archivado );
	}

	/**
	 * The dialogs are printed after the footer, disabled until JavaScript opens them.
	 */
	public function test_the_dialogs_are_printed_disabled_after_the_footer() {
		$doc_id = $this->crear_documento( 'en_gestion' );

		$html = $this->detalle( $this->gestion_id, $doc_id );

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
	 * @dataProvider datos_banderas
	 * @param string $bandera Query argument.
	 * @param string $valor   Its value.
	 * @param string $clase   Notice class.
	 * @param string $texto   Fragment of the text.
	 */
	public function test_feedback_flags_are_turned_into_sentences( $bandera, $valor, $clase, $texto ) {
		$doc_id = $this->crear_documento( 'pending' );

		$html = $this->detalle( $this->admin_id, $doc_id, array( $bandera => $valor ) );

		$this->assertStringContainsString( $clase, $html );
		$this->assertStringContainsString( $texto, $html );
	}

	/**
	 * Flags and the sentence each one shows.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function datos_banderas() {
		return array(
			'enviado' => array( 'enviado', '1', 'dcta-aviso-ok', 'Documento enviado a revisión' ),
			'aprobado' => array( 'aprobado', '1', 'dcta-aviso-ok', 'Documento aprobado y publicado.' ),
			'comentado' => array( 'comentado', '1', 'dcta-aviso-ok', 'Comentario añadido.' ),
			'error de motivo' => array( 'error', 'motivo', 'dcta-aviso-mal', 'hay que decir por qué' ),
			'error de adjunto' => array( 'error', 'adjunto', 'dcta-aviso-mal', 'solo PDF, ODT o DOCX' ),
			'error de transición' => array( 'error', 'transicion', 'dcta-aviso-mal', 'no está disponible' ),
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
		$doc_id = $this->crear_documento( 'pending' );
		$uri_previa = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		try {
			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id . '&error=motivo';
			$detalle = $this->detalle( $this->admin_id, $doc_id );
			$this->assertStringContainsString( 'dcta-aviso-mal', $detalle );
			$this->assertStringContainsString( 'hay que decir por qué', $detalle );

			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id . '&vista=editar&error=adjunto';
			$editar = $this->editar( $this->admin_id, $doc_id );
			$this->assertStringContainsString( 'dcta-aviso-mal', $editar );
			$this->assertStringContainsString( 'solo PDF, ODT o DOCX', $editar );

			// A request without the argument says nothing.
			$_SERVER['REQUEST_URI'] = '/documentate/?doc=' . $doc_id;
			$this->assertStringNotContainsString(
				'dcta-aviso-mal',
				$this->detalle( $this->admin_id, $doc_id )
			);
		} finally {
			if ( null === $uri_previa ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $uri_previa;
			}
		}
	}

	/**
	 * The edit view explains where this type of document goes.
	 */
	public function test_the_editor_explains_where_the_document_goes() {
		$con_gestion = $this->editar( $this->area_id, $this->crear_documento( 'draft' ) );
		$this->assertStringContainsString( 'dcta-aviso-info', $con_gestion );
		$this->assertStringContainsString( 'pasa por gestión documental', $con_gestion );

		$directo = $this->editar( $this->area_id, $this->crear_documento( 'draft', $this->tipo_directo ) );
		$this->assertStringContainsString( 'va directo a administración', $directo );
	}

	/**
	 * The edit view asks for the internal name with the prefix of the type.
	 */
	public function test_the_editor_asks_for_the_internal_name_with_its_prefix() {
		$html = $this->editar( $this->area_id, $this->crear_documento( 'draft' ) );

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
		$doc_id = $this->crear_documento( 'draft' );
		Documentate_Documento::guardar_anotaciones( $doc_id, 'Pendiente de expediente' );

		$html = $this->editar( $this->area_id, $doc_id );

		$this->assertStringNotContainsString( 'documentate_field_numero_resolucion', $html );
		$this->assertStringNotContainsString( 'documentate_app_anotaciones', $html );
		$this->assertStringNotContainsString( 'Pendiente de expediente', $html );
	}

	/**
	 * Gestión sees the official fields and writes the internal notes.
	 */
	public function test_gestion_sees_the_official_fields_and_the_notes() {
		$doc_id = $this->crear_documento( 'en_gestion' );
		Documentate_Documento::guardar_anotaciones( $doc_id, 'Pendiente de expediente' );

		$html = $this->editar( $this->gestion_id, $doc_id );

		$this->assertStringContainsString( 'documentate_field_numero_resolucion', $html );
		$this->assertStringContainsString( 'documentate_app_anotaciones', $html );
		$this->assertStringContainsString( 'Pendiente de expediente', $html );
		$this->assertStringContainsString( 'Solo las ven gestión y administración', $html );
	}

	/**
	 * Gestión folds the área data away and writes its notes inside its own section.
	 */
	public function test_gestion_folds_the_area_data_and_keeps_the_notes_with_the_official_ones() {
		$doc_id = $this->crear_documento( 'en_gestion' );

		$html = $this->editar( $this->gestion_id, $doc_id );

		$this->assertStringContainsString( '<details class="dcta-seccion-area" open><summary>Datos del área</summary>', $html );
		$this->assertStringContainsString( 'Datos oficiales · los completa gestión documental', $html );

		$foldable = strpos( $html, 'dcta-seccion-area' );
		$oficiales = strpos( $html, 'Datos oficiales · los completa gestión documental' );
		$notas = strpos( $html, 'documentate_app_anotaciones' );
		$this->assertLessThan( $oficiales, $foldable, 'The área rows come first.' );
		$this->assertLessThan( $notas, $oficiales, 'The notes belong to the official section.' );

		$area = $this->editar( $this->area_id, $this->crear_documento( 'draft' ) );
		$this->assertStringNotContainsString( 'dcta-seccion-area', $area, 'The área has nothing to fold away.' );
	}

	/**
	 * The file card of the editor takes a new file and offers to drop the old one.
	 */
	public function test_the_editor_takes_a_file_and_offers_to_drop_it() {
		$doc_id = $this->crear_documento( 'draft' );
		$adjunto = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $doc_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		update_post_meta( $doc_id, Documentate_Documento::META_ADJUNTOS, array( (int) $adjunto ) );

		$html = $this->editar( $this->area_id, $doc_id );

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
		$html = $this->editar( $this->area_id, $this->crear_documento( 'draft' ) );

		$this->assertStringContainsString( '<h2 class="dcta-h2">Acciones</h2>', $html );
		$this->assertStringContainsString( 'name="documentate_app_estado" value="guardar" formnovalidate', $html );
		$this->assertStringContainsString( 'value="enviar_gestion"', $html );
		$this->assertStringContainsString( 'Enviar a gestión', $html );
		$this->assertStringContainsString( 'id="exportar"', $html );
	}

	/**
	 * Administración returns a document with a single button and the dialog asks where to.
	 */
	public function test_administracion_returns_with_one_button_and_a_choice() {
		$html = $this->editar( $this->admin_id, $this->crear_documento( 'pending' ) );

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
	public function test_gestion_returns_to_the_area_with_one_reason() {
		$html = $this->editar( $this->gestion_id, $this->crear_documento( 'en_gestion' ) );

		$this->assertStringContainsString( 'value="pasar_admin"', $html );
		$this->assertStringContainsString( 'value="devolver_area"', $html );
		$this->assertStringNotContainsString( 'data-destinos', $html );
	}

	/**
	 * A document out of the hands of the área is read-only for it.
	 */
	public function test_the_area_cannot_edit_a_document_in_gestion() {
		$html = $this->editar( $this->area_id, $this->crear_documento( 'en_gestion' ) );

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
