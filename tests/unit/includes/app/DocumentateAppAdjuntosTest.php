<?php
/**
 * Tests for the file a document carries.
 *
 * The uploads are real: a tiny PDF is written to a temporary file and handed
 * to the handler exactly as PHP hands over $_FILES, so media_handle_sideload()
 * and the mime checks run for real.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_App_Adjuntos
 */
class DocumentateAppAdjuntosTest extends WP_UnitTestCase {

	/**
	 * The smallest valid PDF the mime check accepts.
	 *
	 * @var string
	 */
	const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

	/**
	 * Document under test.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Author of the document.
	 *
	 * @var int
	 */
	private $autor_id;

	/**
	 * A document owned by an author who may upload files.
	 */
	public function set_up(): void {
		parent::set_up();

		$area = wp_insert_term( 'Área adjuntos ' . uniqid(), 'category' );
		$cat_id = (int) $area['term_id'];

		$this->autor_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $this->autor_id, 'documentate_scope_term_id', $cat_id );

		$this->doc_id = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento con fichero',
				'post_author' => $this->autor_id,
			)
		);
		wp_set_object_terms( $this->doc_id, array( $cat_id ), 'category' );

		wp_set_current_user( $this->autor_id );
	}

	/**
	 * Reset the user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Build a $_FILES entry backed by a real temporary file.
	 *
	 * @param string $nombre    File name.
	 * @param string $contenido File contents.
	 * @return array<string,mixed>
	 */
	private function fichero( $nombre, $contenido = self::PDF ) {
		$ruta = wp_tempnam( $nombre );
		file_put_contents( $ruta, $contenido ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name' => $nombre,
			'type' => 'application/pdf',
			'tmp_name' => $ruta,
			'error' => UPLOAD_ERR_OK,
			'size' => filesize( $ruta ),
		);
	}

	/**
	 * The activity texts of the document.
	 *
	 * @return string[]
	 */
	private function eventos() {
		return wp_list_pluck( Documentate_Actividad::listar( $this->doc_id ), 'texto' );
	}

	/**
	 * The limit never goes over the 20 MB of the application.
	 */
	public function test_the_limit_is_capped_at_twenty_megabytes() {
		$this->assertLessThanOrEqual( Documentate_App_Adjuntos::MAX_BYTES, Documentate_App_Adjuntos::tamano_maximo() );
		$this->assertGreaterThan( 0, Documentate_App_Adjuntos::tamano_maximo() );
	}

	/**
	 * An empty file input is not an error the user has to see.
	 */
	public function test_no_file_is_reported_as_such() {
		$error = Documentate_App_Adjuntos::validar( array( 'error' => UPLOAD_ERR_NO_FILE ) );

		$this->assertWPError( $error );
		$this->assertSame( 'sin_fichero', $error->get_error_code() );
	}

	/**
	 * A failed upload is refused.
	 */
	public function test_a_failed_upload_is_refused() {
		$error = Documentate_App_Adjuntos::validar( array( 'error' => UPLOAD_ERR_PARTIAL ) );

		$this->assertWPError( $error );
		$this->assertSame( 'subida', $error->get_error_code() );
	}

	/**
	 * A file over the limit is refused.
	 */
	public function test_a_file_over_the_limit_is_refused() {
		$archivo = $this->fichero( 'grande.pdf' );
		$archivo['size'] = Documentate_App_Adjuntos::MAX_BYTES + 1;

		$error = Documentate_App_Adjuntos::validar( $archivo );

		$this->assertWPError( $error );
		$this->assertSame( 'tamano', $error->get_error_code() );
	}

	/**
	 * Anything that is not PDF, ODT or DOCX is refused.
	 */
	public function test_another_format_is_refused() {
		$error = Documentate_App_Adjuntos::validar( $this->fichero( 'hoja.xlsx', 'no importa' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'tipo', $error->get_error_code() );
	}

	/**
	 * A real PDF passes.
	 */
	public function test_a_pdf_is_accepted() {
		$this->assertTrue( Documentate_App_Adjuntos::validar( $this->fichero( 'resolucion.pdf' ) ) );
	}

	/**
	 * Storing a file attaches it to the document and records the event.
	 */
	public function test_storing_a_file_attaches_it_and_records_the_event() {
		$attachment_id = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( $this->doc_id, (int) get_post_field( 'post_parent', $attachment_id ) );
		$this->assertSame(
			array( $attachment_id ),
			array_map( 'intval', (array) get_post_meta( $this->doc_id, Documentate_Documento::META_ADJUNTOS, true ) )
		);

		$adjunto = Documentate_Documento::adjunto( $this->doc_id );
		$this->assertInstanceOf( WP_Post::class, $adjunto );
		$this->assertSame( $attachment_id, $adjunto->ID );
		$nombre = Documentate_App_Adjuntos::nombre( $attachment_id );
		$this->assertStringStartsWith( 'resolucion', $nombre );
		$this->assertContains( 'adjuntó el fichero «' . $nombre . '»', $this->eventos() );
		$this->assertNotSame( '', Documentate_App_Adjuntos::tamano_legible( $attachment_id ) );
	}

	/**
	 * The name on disk is unguessable; the name on screen is the one chosen.
	 *
	 * The uploads folder is served by the web server with no capability check
	 * of any kind, so a document whose file kept its own name would be one
	 * guess away from anybody on the internet.
	 */
	public function test_the_file_is_stored_under_an_unguessable_name() {
		$attachment_id = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );

		$en_disco = basename( (string) get_attached_file( $attachment_id ) );

		$this->assertNotSame( 'resolucion.pdf', $en_disco );
		$this->assertMatchesRegularExpression( '/^resolucion-[0-9a-f]{16}\.pdf$/', $en_disco );
		$this->assertSame( 'resolucion.pdf', Documentate_App_Adjuntos::nombre( $attachment_id ), 'The readable name is what the lists and the activity show.' );
	}

	/**
	 * The file is served by a handler that checks the document permission.
	 */
	public function test_the_file_is_served_only_to_whoever_may_open_the_document() {
		$attachment_id = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );

		$url = Documentate_App_Adjuntos::url( $this->doc_id );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Documentate_App_Adjuntos::ACCION_SERVIR, $url );
		$this->assertStringContainsString( 'doc=' . $this->doc_id, $url );
		$this->assertStringContainsString( 'adjunto=' . $attachment_id, $url );

		$_GET = array(
			'doc' => (string) $this->doc_id,
			'adjunto' => (string) $attachment_id,
		);

		// Somebody from another área cannot read the file of this document.
		$intruso = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $intruso, 'documentate_scope_term_id', (int) wp_insert_term( 'Otra área ' . uniqid(), 'category' )['term_id'] );
		wp_set_current_user( $intruso );

		try {
			Documentate_App_Adjuntos::servir();
			$this->fail( 'The handler must refuse.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'No tienes permiso', $e->getMessage() );
		}

		$_GET = array();
	}

	/**
	 * A document with no file has no URL to serve.
	 */
	public function test_a_document_without_a_file_has_no_url() {
		$this->assertSame( '', Documentate_App_Adjuntos::url( $this->doc_id ) );
		$this->assertSame( '', Documentate_App_Adjuntos::url( 0 ) );
	}

	/**
	 * A path that is neither an upload nor a file the plugin wrote is refused.
	 *
	 * media_handle_sideload() — unlike wp_handle_upload() — skips the
	 * is_uploaded_file() test, so a posted tmp_name pointing anywhere the
	 * process can read would otherwise copy that file to a public URL.
	 */
	public function test_a_path_outside_the_temporary_folder_is_refused() {
		$fuera = trailingslashit( wp_upload_dir()['basedir'] ) . 'documentate-origen-' . uniqid() . '.pdf';
		file_put_contents( $fuera, self::PDF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$error = Documentate_App_Adjuntos::guardar(
			$this->doc_id,
			array(
				'name' => 'resolucion.pdf',
				'type' => 'application/pdf',
				'tmp_name' => $fuera,
				'error' => 0,
				'size' => (int) filesize( $fuera ),
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'sin_subida', $error->get_error_code() );
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );
		$this->assertFileExists( $fuera, 'The file it pointed at is left alone.' );

		wp_delete_file( $fuera );
	}

	/**
	 * A second file replaces the first one.
	 */
	public function test_a_second_file_replaces_the_first_one() {
		$primero = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'primera.pdf' ) );
		$segundo = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'segunda.pdf' ) );

		$this->assertNotSame( $primero, $segundo );
		$this->assertSame( $segundo, Documentate_Documento::adjunto( $this->doc_id )->ID );
	}

	/**
	 * An invalid file never reaches the media library.
	 */
	public function test_an_invalid_file_is_not_stored() {
		$error = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'hoja.xlsx', 'no importa' ) );

		$this->assertWPError( $error );
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );
		$this->assertSame( array(), $this->eventos() );
	}

	/**
	 * Whoever cannot edit the document cannot attach anything to it.
	 */
	public function test_a_stranger_cannot_attach_anything() {
		wp_set_current_user( 0 );

		$error = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'sin_permiso', $error->get_error_code() );
	}

	/**
	 * Removing the file detaches it and records the event.
	 */
	public function test_removing_the_file_records_the_event() {
		$attachment_id = Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );
		$nombre = Documentate_App_Adjuntos::nombre( $attachment_id );

		$this->assertTrue( Documentate_App_Adjuntos::quitar( $this->doc_id ) );
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );
		$this->assertContains( 'quitó el fichero «' . $nombre . '»', $this->eventos() );
	}

	/**
	 * Removing nothing records nothing.
	 */
	public function test_removing_nothing_records_nothing() {
		$this->assertFalse( Documentate_App_Adjuntos::quitar( $this->doc_id ) );
		$this->assertSame( array(), $this->eventos() );
	}

	/**
	 * Whoever cannot edit the document cannot detach its file either.
	 */
	public function test_a_stranger_cannot_remove_the_file() {
		Documentate_App_Adjuntos::guardar( $this->doc_id, $this->fichero( 'resolucion.pdf' ) );
		wp_set_current_user( 0 );

		$this->assertFalse( Documentate_App_Adjuntos::quitar( $this->doc_id ) );
		$this->assertNotNull( Documentate_Documento::adjunto( $this->doc_id ) );
	}

	/**
	 * The size of a file that is gone is not printed.
	 */
	public function test_the_size_of_a_missing_file_is_empty() {
		$this->assertSame( '', Documentate_App_Adjuntos::tamano_legible( 0 ) );
	}
}
