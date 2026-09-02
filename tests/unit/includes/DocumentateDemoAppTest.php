<?php
/**
 * Tests for Documentate_Demo_App: the twelve-document application demo set.
 *
 * @covers Documentate_Demo_App
 */
class DocumentateDemoAppTest extends WP_UnitTestCase {

	/**
	 * Captured wp_mail() calls.
	 *
	 * @var array
	 */
	private $mails = array();

	/**
	 * Register the custom statuses this suite creates documents in.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Documentate_Workflow() )->register_custom_statuses();

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Reset globals.
	 *
	 * Deliberately does NOT reset the current user itself (WP_UnitTestCase's
	 * own tear_down() already does): test_seed_restores_the_caller_user()
	 * needs the state seed() leaves behind to still be observable when it
	 * asserts, right after calling it.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		parent::tear_down();
	}

	/**
	 * Capture wp_mail() calls without dispatching them.
	 *
	 * @param mixed $return Short-circuit value.
	 * @param array $atts   Mail attributes.
	 * @return bool
	 */
	public function capture_mail( $return, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Documents keyed by their internal name, for readable assertions.
	 *
	 * @param int[] $ids Document IDs.
	 * @return array<string,WP_Post>
	 */
	private function por_nombre( array $ids ) {
		$mapa = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$mapa[ Documentate_Documento::nombre_interno( $post ) ] = $post;
		}

		return $mapa;
	}

	/**
	 * seed() creates the twelve documents, marked only with _documentate_demo_app.
	 */
	public function test_seed_creates_twelve_documents() {
		$ids = Documentate_Demo_App::seed();

		$this->assertCount( 12, $ids );

		foreach ( $ids as $id ) {
			$this->assertSame( '1', get_post_meta( $id, '_documentate_demo_app', true ) );
			$this->assertSame( '', get_post_meta( $id, '_documentate_demo_type_id', true ), 'DocumentateDemoDocumentsTest counts _documentate_demo_type_id; the app demo must never carry it.' );
		}
	}

	/**
	 * A second seed() call does not duplicate any document.
	 */
	public function test_seed_is_idempotent() {
		$primero = Documentate_Demo_App::seed();
		$segundo = Documentate_Demo_App::seed();

		$this->assertSame( $primero, $segundo );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = %s",
				'_documentate_demo_app'
			)
		);
		$this->assertSame( 12, $total );
	}

	/**
	 * reseed() deletes the previous set (and its attachments) and creates a fresh one.
	 */
	public function test_reseed_replaces_documents_and_their_attachments() {
		$primero = Documentate_Demo_App::seed();
		$mapa = $this->por_nombre( $primero );
		$adjunto_previo = Documentate_Documento::adjunto( $mapa['Material aulas digitales'] );
		$this->assertNotNull( $adjunto_previo );

		$segundo = Documentate_Demo_App::reseed();

		$this->assertCount( 12, $segundo );
		$this->assertEmpty( array_intersect( $primero, $segundo ), 'reseed() must not reuse the previous IDs.' );
		$this->assertNull( get_post( $primero[0] ), 'The old documents must be gone.' );
		$this->assertNull( get_post( $adjunto_previo->ID ), 'A force-deleted document takes its attachment with it.' );
	}

	/**
	 * Every status the demo set touches, the devuelto marks and who owns each
	 * document are exactly as the spec's document table describes.
	 */
	public function test_every_status_and_devuelto_mark_matches_the_spec() {
		$mapa = $this->por_nombre( Documentate_Demo_App::seed() );
		$author1 = get_user_by( 'login', 'author1' );
		$editor1 = get_user_by( 'login', 'editor1' );

		// author1 / Departamento de Proyectos.
		$this->assertSame( 'draft', $mapa['Material aulas digitales']->post_status );
		$this->assertNull( Documentate_Documento::devuelto( $mapa['Material aulas digitales'] ) );
		$this->assertSame( (int) $author1->ID, (int) $mapa['Material aulas digitales']->post_author );

		$this->assertSame( 'draft', $mapa['Jornadas competencia digital']->post_status );

		$this->assertSame( 'draft', $mapa['Certificación tribunal materiales']->post_status );
		$devuelto_hc = Documentate_Documento::devuelto( $mapa['Certificación tribunal materiales'] );
		$this->assertNotNull( $devuelto_hc );
		$this->assertSame( 'Falta el anexo firmado por la dirección', $devuelto_hc['motivo'] );
		$this->assertSame( 'gestion', $devuelto_hc['desde'] );
		$this->assertSame( (int) $editor1->ID, $devuelto_hc['por'] );

		$this->assertSame( 'en_gestion', $mapa['Listado definitivo piloto innovación']->post_status );
		$this->assertNull( Documentate_Documento::devuelto( $mapa['Listado definitivo piloto innovación'] ) );

		$this->assertSame( 'en_gestion', $mapa['Dotación biblioteca escolar']->post_status );
		$this->assertEmpty( get_post_meta( $mapa['Dotación biblioteca escolar']->ID, 'documentate_field_gasto_numero', true ), 'Gestión fields must stay empty.' );

		$this->assertSame( 'pending', $mapa['Formación profesorado metodologías']->post_status );
		$this->assertNotEmpty( get_post_meta( $mapa['Formación profesorado metodologías']->ID, 'documentate_field_gasto_numero', true ), 'Gestión fields must be filled.' );

		$this->assertSame( 'publish', $mapa['Bases programa piloto innovación']->post_status );

		// editor1 / Subdirección de Administración.
		$this->assertSame( 'en_gestion', $mapa['Calendario de admisión 2027']->post_status );
		$devuelto_res = Documentate_Documento::devuelto( $mapa['Calendario de admisión 2027'] );
		$this->assertNotNull( $devuelto_res );
		$this->assertSame( 'Falta el número de expediente', $devuelto_res['motivo'] );
		$this->assertSame( 'administracion', $devuelto_res['desde'] );
		$this->assertEmpty( get_post_meta( $mapa['Calendario de admisión 2027']->ID, 'documentate_field_expediente', true ) );

		$this->assertSame( 'pending', $mapa['Comisión formación septiembre']->post_status );
		$this->assertSame( 'publish', $mapa['Bases plan de formación 2026-27']->post_status );

		$this->assertSame( 'draft', $mapa['Renovación licencias aulas virtuales']->post_status );
		$devuelto_pg = Documentate_Documento::devuelto( $mapa['Renovación licencias aulas virtuales'] );
		$this->assertNotNull( $devuelto_pg );
		$this->assertSame( 'Revisar la partida presupuestaria', $devuelto_pg['motivo'] );
		$this->assertSame( 'administracion', $devuelto_pg['desde'] );
		$this->assertSame( 'area', $devuelto_pg['a'] );

		// admin.
		$this->assertSame( 'archived', $mapa['Instrucciones inicio de curso 2025-26']->post_status );
	}

	/**
	 * Every document's history matches the state it ends in.
	 */
	public function test_events_are_consistent_with_each_state() {
		$mapa = $this->por_nombre( Documentate_Demo_App::seed() );

		// Documentate_Document_Access_Protection hides a document's comments
		// (events included) from anyone not logged in with edit_posts, and
		// seed() now correctly restores the anonymous caller it started as
		// (see test_seed_restores_the_caller_user()) rather than leaking the
		// last demo actor's session: reading the activity here needs its own
		// login, exactly as any real caller would.
		wp_set_current_user( get_user_by( 'login', 'admin' )->ID );

		$textos = static function ( $post ) {
			return wp_list_pluck( Documentate_Actividad::listar( $post->ID ), 'texto' );
		};

		$pdf = Documentate_Documento::adjunto( $mapa['Material aulas digitales'] );
		$this->assertContains( 'creó el borrador', $textos( $mapa['Material aulas digitales'] ) );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Adjuntos::nombre( $pdf->ID ) . '»',
			$textos( $mapa['Material aulas digitales'] )
		);

		$odt = Documentate_Documento::adjunto( $mapa['Listado definitivo piloto innovación'] );
		$eventos_definitivo = $textos( $mapa['Listado definitivo piloto innovación'] );
		$this->assertContains( 'creó el borrador', $eventos_definitivo );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Adjuntos::nombre( $odt->ID ) . '»',
			$eventos_definitivo
		);
		$this->assertContains( 'envió el documento a gestión', $eventos_definitivo );

		$eventos_bases = $textos( $mapa['Bases programa piloto innovación'] );
		$this->assertContains( 'envió el documento a gestión', $eventos_bases );
		$this->assertContains( 'pasó el documento a administración', $eventos_bases );
		$this->assertContains( 'aprobó y publicó el documento', $eventos_bases );

		$eventos_hc = $textos( $mapa['Certificación tribunal materiales'] );
		$this->assertContains( 'devolvió el documento al área: «Falta el anexo firmado por la dirección»', $eventos_hc );

		$eventos_calendario = $textos( $mapa['Calendario de admisión 2027'] );
		$this->assertContains( 'devolvió el documento a gestión: «Falta el número de expediente»', $eventos_calendario );

		$eventos_licencias = $textos( $mapa['Renovación licencias aulas virtuales'] );
		$this->assertContains( 'devolvió el documento al área: «Revisar la partida presupuestaria»', $eventos_licencias );

		$eventos_archivado = $textos( $mapa['Instrucciones inicio de curso 2025-26'] );
		$this->assertContains( 'archivó el documento', $eventos_archivado );
		$this->assertContains( 'aprobó y publicó el documento', $eventos_archivado );
	}

	/**
	 * Two documents carry a real attachment: a PDF and an ODT.
	 */
	public function test_two_documents_carry_an_attachment() {
		$mapa = $this->por_nombre( Documentate_Demo_App::seed() );

		$pdf = Documentate_Documento::adjunto( $mapa['Material aulas digitales'] );
		$this->assertNotNull( $pdf );
		$this->assertSame( 'application/pdf', $pdf->post_mime_type );

		$odt = Documentate_Documento::adjunto( $mapa['Listado definitivo piloto innovación'] );
		$this->assertNotNull( $odt );
		$this->assertSame( 'application/vnd.oasis.opendocument.text', $odt->post_mime_type );

		$this->assertNull( Documentate_Documento::adjunto( $mapa['Jornadas competencia digital'] ) );
	}

	/**
	 * The en_gestion resolución carries one área comment.
	 */
	public function test_one_comment_on_the_en_gestion_resolucion() {
		$mapa = $this->por_nombre( Documentate_Demo_App::seed() );

		// See the comment on test_events_are_consistent_with_each_state():
		// reading a document's activity needs its own authorized login.
		wp_set_current_user( get_user_by( 'login', 'admin' )->ID );

		$filas = Documentate_Actividad::listar( $mapa['Listado definitivo piloto innovación']->ID );
		$comentarios = array_values( array_filter( $filas, static fn( $fila ) => 'comentario' === $fila['tipo'] ) );

		$this->assertCount( 1, $comentarios );
		$this->assertSame( 'El anexo con el listado va en la última página del ODT.', $comentarios[0]['texto'] );
	}

	/**
	 * Seeding the whole demo set never sends a notification mail.
	 */
	public function test_seeding_sends_no_mail() {
		Documentate_Demo_App::reseed();

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * seed() must not leave the request logged in as whichever demo actor
	 * performed the last step: it is reached from an ordinary request
	 * (Documentate_Demo_Data hooks it on init priority 60), so anything that
	 * runs afterwards in that same request has to see the caller's own
	 * identity, not the seeder's.
	 */
	public function test_seed_restores_the_caller_user() {
		wp_set_current_user( 0 );

		Documentate_Demo_App::seed();

		$this->assertSame( 0, get_current_user_id(), 'seed() must restore the anonymous caller, not leave the last demo actor logged in.' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		Documentate_Demo_App::reseed();

		$this->assertSame( $admin_id, get_current_user_id(), "reseed() must restore the caller's own user." );
	}

	/**
	 * The three provider blocks of the PG demo documents (servicios,
	 * suministros, expertos) all share the item_schema, so every provider row
	 * of every block must carry an array "conceptos" sub-repeater rather than
	 * a flat string.
	 */
	public function test_pg_provider_blocks_carry_an_array_conceptos_sub_repeater() {
		$mapa = $this->por_nombre( Documentate_Demo_App::seed() );
		$post_id = $mapa['Formación profesorado metodologías']->ID;

		foreach ( array( 'servicios', 'suministros', 'expertos' ) as $bloque ) {
			$json = get_post_meta( $post_id, 'documentate_field_' . $bloque, true );
			$filas = json_decode( (string) $json, true );

			$this->assertIsArray( $filas, "$bloque must decode to an array." );
			$this->assertNotEmpty( $filas, "$bloque must have at least one provider row." );

			foreach ( $filas as $fila ) {
				$this->assertIsArray( $fila['conceptos'], "Every $bloque row must carry an array conceptos sub-repeater, not a flat string." );
				$this->assertNotEmpty( $fila['conceptos'] );
			}
		}
	}

	/**
	 * poner_estado() writes the status and the devuelto mark with no regard
	 * for who is logged in, including nobody at all.
	 */
	public function test_poner_estado_ignores_permissions() {
		Documentate_Demo_App::asegurar_entorno();
		$tipo = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
		$this->assertInstanceOf( WP_Term::class, $tipo );

		wp_set_current_user( 0 );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'poner_estado sin permisos',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $tipo->term_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $tipo->term_id );

		Documentate_Demo_App::poner_estado(
			$post_id,
			'en_gestion',
			array( 'motivo' => 'Motivo de prueba', 'desde' => 'gestion', 'a' => 'area' )
		);

		$this->assertSame( 'en_gestion', get_post_status( $post_id ) );
		$devuelto = Documentate_Documento::devuelto( $post_id );
		$this->assertSame( 'Motivo de prueba', $devuelto['motivo'] );

		Documentate_Demo_App::poner_estado( $post_id, 'publish', null );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertNull( Documentate_Documento::devuelto( $post_id ), 'A null devuelto clears the mark.' );
	}

	/**
	 * asegurar_entorno() creates the demo categories and users even when the
	 * "seed on activation" option was already consumed, and leaves it as it
	 * found it afterwards.
	 */
	public function test_asegurar_entorno_creates_prerequisites_regardless_of_the_option() {
		delete_option( 'documentate_seed_demo_documents' );
		$this->assertFalse( get_user_by( 'login', 'author1' ) );

		Documentate_Demo_App::asegurar_entorno();

		$this->assertInstanceOf( WP_User::class, get_user_by( 'login', 'author1' ) );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'login', 'editor1' ) );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'name', 'Departamento de Proyectos', 'category' ) );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' ) );
		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ), 'The option must be left exactly as it was found (unset).' );
	}
}
