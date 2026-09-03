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
	 * IDs of the documents this seeder marks as its own.
	 *
	 * @return int[]
	 */
	private function demo_app_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_documentate_demo_app'
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Documents keyed by their internal name, for readable assertions.
	 *
	 * @param int[] $ids Document IDs.
	 * @return array<string,WP_Post>
	 */
	private function by_name( array $ids ) {
		$map = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$map[ Documentate_Document_Data::internal_name( $post ) ] = $post;
		}

		return $map;
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
		$first = Documentate_Demo_App::seed();
		$second = Documentate_Demo_App::seed();

		$this->assertSame( $first, $second );

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
		$first = Documentate_Demo_App::seed();
		$map = $this->by_name( $first );
		$previous_attachment = Documentate_Document_Data::attachment( $map['Material aulas digitales'] );
		$this->assertNotNull( $previous_attachment );

		$second = Documentate_Demo_App::reseed();

		$this->assertCount( 12, $second );
		$this->assertEmpty( array_intersect( $first, $second ), 'reseed() must not reuse the previous IDs.' );
		$this->assertNull( get_post( $first[0] ), 'The old documents must be gone.' );
		$this->assertNull( get_post( $previous_attachment->ID ), 'A force-deleted document takes its attachment with it.' );
	}

	/**
	 * Every status the demo set touches, the devuelto marks and who owns each
	 * document are exactly as the spec's document table describes.
	 */
	public function test_every_status_and_returned_mark_matches_the_spec() {
		$map = $this->by_name( Documentate_Demo_App::seed() );
		$author1 = get_user_by( 'login', 'author1' );
		$editor1 = get_user_by( 'login', 'editor1' );

		// author1 / Departamento de Proyectos.
		$this->assertSame( 'draft', $map['Material aulas digitales']->post_status );
		$this->assertNull( Documentate_Document_Data::returned( $map['Material aulas digitales'] ) );
		$this->assertSame( (int) $author1->ID, (int) $map['Material aulas digitales']->post_author );

		$this->assertSame( 'draft', $map['Jornadas competencia digital']->post_status );

		$this->assertSame( 'draft', $map['Certificación tribunal materiales']->post_status );
		$returned_hc = Documentate_Document_Data::returned( $map['Certificación tribunal materiales'] );
		$this->assertNotNull( $returned_hc );
		$this->assertSame( 'Falta el anexo firmado por la dirección', $returned_hc['motivo'] );
		$this->assertSame( 'gestion', $returned_hc['desde'] );
		$this->assertSame( (int) $editor1->ID, $returned_hc['por'] );

		$this->assertSame( 'en_gestion', $map['Listado definitivo piloto innovación']->post_status );
		$this->assertNull( Documentate_Document_Data::returned( $map['Listado definitivo piloto innovación'] ) );

		$this->assertSame( 'en_gestion', $map['Dotación biblioteca escolar']->post_status );
		$this->assertEmpty( get_post_meta( $map['Dotación biblioteca escolar']->ID, 'documentate_field_gasto_numero', true ), 'Gestión fields must stay empty.' );

		$this->assertSame( 'pending', $map['Formación profesorado metodologías']->post_status );
		$this->assertNotEmpty( get_post_meta( $map['Formación profesorado metodologías']->ID, 'documentate_field_gasto_numero', true ), 'Gestión fields must be filled.' );

		$this->assertSame( 'publish', $map['Bases programa piloto innovación']->post_status );

		// editor1 / Subdirección de Administración.
		$this->assertSame( 'en_gestion', $map['Calendario de admisión 2027']->post_status );
		$returned_res = Documentate_Document_Data::returned( $map['Calendario de admisión 2027'] );
		$this->assertNotNull( $returned_res );
		$this->assertSame( 'Falta el número de expediente', $returned_res['motivo'] );
		$this->assertSame( 'administracion', $returned_res['desde'] );
		$this->assertEmpty( get_post_meta( $map['Calendario de admisión 2027']->ID, 'documentate_field_expediente', true ) );

		$this->assertSame( 'pending', $map['Comisión formación septiembre']->post_status );
		$this->assertSame( 'publish', $map['Bases plan de formación 2026-27']->post_status );

		$this->assertSame( 'draft', $map['Renovación licencias aulas virtuales']->post_status );
		$returned_pg = Documentate_Document_Data::returned( $map['Renovación licencias aulas virtuales'] );
		$this->assertNotNull( $returned_pg );
		$this->assertSame( 'Revisar la partida presupuestaria', $returned_pg['motivo'] );
		$this->assertSame( 'administracion', $returned_pg['desde'] );
		$this->assertSame( 'area', $returned_pg['a'] );

		// admin.
		$this->assertSame( 'archived', $map['Instrucciones inicio de curso 2025-26']->post_status );
	}

	/**
	 * Every document's history matches the state it ends in.
	 */
	public function test_events_are_consistent_with_each_state() {
		$map = $this->by_name( Documentate_Demo_App::seed() );

		// Documentate_Document_Access_Protection hides a document's comments
		// (events included) from anyone not logged in with edit_posts, and
		// seed() now correctly restores the anonymous caller it started as
		// (see test_seed_restores_the_caller_user()) rather than leaking the
		// last demo actor's session: reading the activity here needs its own
		// login, exactly as any real caller would.
		wp_set_current_user( get_user_by( 'login', 'admin' )->ID );

		$texts = static function ( $post ) {
			return wp_list_pluck( Documentate_Activity::entries( $post->ID ), 'text' );
		};

		$pdf = Documentate_Document_Data::attachment( $map['Material aulas digitales'] );
		$this->assertContains( 'creó el borrador', $texts( $map['Material aulas digitales'] ) );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Attachments::name( $pdf->ID ) . '»',
			$texts( $map['Material aulas digitales'] )
		);

		$odt = Documentate_Document_Data::attachment( $map['Listado definitivo piloto innovación'] );
		$events_final_list = $texts( $map['Listado definitivo piloto innovación'] );
		$this->assertContains( 'creó el borrador', $events_final_list );
		$this->assertContains(
			'adjuntó el fichero «' . Documentate_App_Attachments::name( $odt->ID ) . '»',
			$events_final_list
		);
		$this->assertContains( 'envió el documento a gestión', $events_final_list );

		$events_rules = $texts( $map['Bases programa piloto innovación'] );
		$this->assertContains( 'envió el documento a gestión', $events_rules );
		$this->assertContains( 'pasó el documento a administración', $events_rules );
		$this->assertContains( 'aprobó y publicó el documento', $events_rules );

		$events_hc = $texts( $map['Certificación tribunal materiales'] );
		$this->assertContains( 'devolvió el documento al área: «Falta el anexo firmado por la dirección»', $events_hc );

		$events_calendar = $texts( $map['Calendario de admisión 2027'] );
		$this->assertContains( 'devolvió el documento a gestión: «Falta el número de expediente»', $events_calendar );

		$events_licences = $texts( $map['Renovación licencias aulas virtuales'] );
		$this->assertContains( 'devolvió el documento al área: «Revisar la partida presupuestaria»', $events_licences );

		$events_archived = $texts( $map['Instrucciones inicio de curso 2025-26'] );
		$this->assertContains( 'archivó el documento', $events_archived );
		$this->assertContains( 'aprobó y publicó el documento', $events_archived );
	}

	/**
	 * Two documents carry a real attachment: a PDF and an ODT.
	 */
	public function test_two_documents_carry_an_attachment() {
		$map = $this->by_name( Documentate_Demo_App::seed() );

		$pdf = Documentate_Document_Data::attachment( $map['Material aulas digitales'] );
		$this->assertNotNull( $pdf );
		$this->assertSame( 'application/pdf', $pdf->post_mime_type );

		$odt = Documentate_Document_Data::attachment( $map['Listado definitivo piloto innovación'] );
		$this->assertNotNull( $odt );
		$this->assertSame( 'application/vnd.oasis.opendocument.text', $odt->post_mime_type );

		$this->assertNull( Documentate_Document_Data::attachment( $map['Jornadas competencia digital'] ) );
	}

	/**
	 * The en_gestion resolución carries one área comment.
	 */
	public function test_one_comment_on_the_en_gestion_resolucion_document() {
		$map = $this->by_name( Documentate_Demo_App::seed() );

		// See the comment on test_events_are_consistent_with_each_state():
		// reading a document's activity needs its own authorized login.
		wp_set_current_user( get_user_by( 'login', 'admin' )->ID );

		$rows = Documentate_Activity::entries( $map['Listado definitivo piloto innovación']->ID );
		$comments = array_values( array_filter( $rows, static fn( $row ) => 'comentario' === $row['type'] ) );

		$this->assertCount( 1, $comments );
		$this->assertSame( 'El anexo con el listado va en la última página del ODT.', $comments[0]['text'] );
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
		$map = $this->by_name( Documentate_Demo_App::seed() );
		$post_id = $map['Formación profesorado metodologías']->ID;

		foreach ( array( 'servicios', 'suministros', 'expertos' ) as $block ) {
			$json = get_post_meta( $post_id, 'documentate_field_' . $block, true );
			$rows = json_decode( (string) $json, true );

			$this->assertIsArray( $rows, "$block must decode to an array." );
			$this->assertNotEmpty( $rows, "$block must have at least one provider row." );

			foreach ( $rows as $row ) {
				$this->assertIsArray( $row['conceptos'], "Every $block row must carry an array conceptos sub-repeater, not a flat string." );
				$this->assertNotEmpty( $row['conceptos'] );
			}
		}
	}

	/**
	 * set_status() writes the status and the devuelto mark with no regard
	 * for who is logged in, including nobody at all.
	 */
	public function test_set_status_ignores_permissions() {
		Documentate_Demo_App::ensure_environment();
		$type = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
		$this->assertInstanceOf( WP_Term::class, $type );

		wp_set_current_user( 0 );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'set_status sin permisos',
				'post_status' => 'draft',
			)
		);
		wp_set_object_terms( $post_id, $type->term_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $type->term_id );

		Documentate_Demo_App::set_status(
			$post_id,
			'en_gestion',
			array( 'motivo' => 'Motivo de prueba', 'desde' => 'gestion', 'a' => 'area' )
		);

		$this->assertSame( 'en_gestion', get_post_status( $post_id ) );
		$returned = Documentate_Document_Data::returned( $post_id );
		$this->assertSame( 'Motivo de prueba', $returned['motivo'] );

		Documentate_Demo_App::set_status( $post_id, 'publish', null );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertNull( Documentate_Document_Data::returned( $post_id ), 'A null devuelto clears the mark.' );
	}

	/**
	 * ensure_environment() creates the demo categories and users even when the
	 * "seed on activation" option was already consumed, and leaves it as it
	 * found it afterwards.
	 */
	public function test_ensure_environment_creates_prerequisites_regardless_of_the_option() {
		delete_option( 'documentate_seed_demo_documents' );
		$this->assertFalse( get_user_by( 'login', 'author1' ) );

		Documentate_Demo_App::ensure_environment();

		$this->assertInstanceOf( WP_User::class, get_user_by( 'login', 'author1' ) );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'login', 'editor1' ) );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'name', 'Departamento de Proyectos', 'category' ) );
		$this->assertInstanceOf( WP_Term::class, get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' ) );
		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ), 'The option must be left exactly as it was found (unset).' );
	}

	/**
	 * A site that already carries the older demo documents still gets these.
	 *
	 * The one-per-type set is seeded once and then guards itself; before this,
	 * that same guard skipped the whole pass, so a site updated from an earlier
	 * version never saw a single document of the workflow.
	 */
	public function test_an_older_demo_site_still_gets_the_workflow_documents() {
		$older = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución de ejemplo de la versión anterior',
			)
		);
		update_post_meta( $older, '_documentate_demo_type_id', '7' );

		update_option( 'documentate_seed_demo_documents', true );
		Documentate_Demo_Data::maybe_seed_demo_documents();

		$this->assertCount(
			12,
			$this->demo_app_ids(),
			'The workflow documents are seeded even next to the older set.'
		);
		$this->assertFalse(
			get_option( 'documentate_seed_demo_documents', false ),
			'The flag is spent once the pass is done.'
		);
	}

	/**
	 * The older demo documents come out with a short name of their own.
	 */
	public function test_the_older_demo_documents_get_an_internal_name() {
		$largo = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Ejemplo: Resolución de la Dirección General por la que se publica el listado definitivo de centros admitidos en el programa piloto',
			)
		);
		update_post_meta( $largo, '_documentate_demo_type_id', '7' );

		$corto = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Convocatoria de reunión',
			)
		);
		update_post_meta( $corto, '_documentate_demo_key', 'convocatoria' );

		Documentate_Demo_App::seed();

		$nombre_largo = Documentate_Document_Data::internal_name( $largo );
		$this->assertNotSame( '', $nombre_largo, 'A demo document without a name gets one.' );
		$this->assertLessThanOrEqual( 60, mb_strlen( $nombre_largo ), 'The name is short enough for a list row.' );
		$this->assertStringStartsWith( 'Resolución de la Dirección General', $nombre_largo, 'The "Ejemplo:" prefix is dropped and the words survive.' );
		$this->assertStringEndsNotWith( ' ', $nombre_largo );

		$this->assertSame(
			'Convocatoria de reunión',
			Documentate_Document_Data::internal_name( $corto ),
			'A title that already reads as a name is kept whole.'
		);
	}

	/**
	 * A name somebody wrote is never overwritten by the seeder.
	 */
	public function test_an_existing_internal_name_is_left_alone() {
		$post_id = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución de ejemplo',
			)
		);
		update_post_meta( $post_id, '_documentate_demo_type_id', '7' );
		Documentate_Document_Data::save_internal_name( $post_id, 'El que escribió alguien' );

		Documentate_Demo_App::seed();

		$this->assertSame( 'El que escribió alguien', Documentate_Document_Data::internal_name( $post_id ) );
	}
}
