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
 * @covers Documentate_App_Attachments
 */
class DocumentateAppAttachmentsTest extends WP_UnitTestCase {

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
	private $author_id;

	/**
	 * A document owned by an author who may upload files.
	 */
	public function set_up(): void {
		parent::set_up();

		$area = wp_insert_term( 'Área adjuntos ' . uniqid(), 'category' );
		$cat_id = (int) $area['term_id'];

		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $this->author_id, 'documentate_scope_term_id', $cat_id );

		$this->doc_id = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento con fichero',
				'post_author' => $this->author_id,
			)
		);
		wp_set_object_terms( $this->doc_id, array( $cat_id ), 'category' );

		wp_set_current_user( $this->author_id );
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
	 * @param string $name    File name.
	 * @param string $content File contents.
	 * @return array<string,mixed>
	 */
	private function file_fixture( $name, $content = self::PDF ) {
		$path = wp_tempnam( $name );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name' => $name,
			'type' => 'application/pdf',
			'tmp_name' => $path,
			'error' => UPLOAD_ERR_OK,
			'size' => filesize( $path ),
		);
	}

	/**
	 * The activity texts of the document.
	 *
	 * @return string[]
	 */
	private function events() {
		return wp_list_pluck( Documentate_Activity::entries( $this->doc_id ), 'text' );
	}

	/**
	 * The limit never goes over the 20 MB of the application.
	 */
	public function test_the_limit_is_capped_at_twenty_megabytes() {
		$this->assertLessThanOrEqual( Documentate_App_Attachments::MAX_BYTES, Documentate_App_Attachments::max_size() );
		$this->assertGreaterThan( 0, Documentate_App_Attachments::max_size() );
	}

	/**
	 * An empty file input is not an error the user has to see.
	 */
	public function test_no_file_is_reported_as_such() {
		$error = Documentate_App_Attachments::validate( array( 'error' => UPLOAD_ERR_NO_FILE ) );

		$this->assertWPError( $error );
		$this->assertSame( 'sin_fichero', $error->get_error_code() );
	}

	/**
	 * A failed upload is refused.
	 */
	public function test_a_failed_upload_is_refused() {
		$error = Documentate_App_Attachments::validate( array( 'error' => UPLOAD_ERR_PARTIAL ) );

		$this->assertWPError( $error );
		$this->assertSame( 'subida', $error->get_error_code() );
	}

	/**
	 * A file over the limit is refused.
	 */
	public function test_a_file_over_the_limit_is_refused() {
		$file = $this->file_fixture( 'grande.pdf' );
		$file['size'] = Documentate_App_Attachments::MAX_BYTES + 1;

		$error = Documentate_App_Attachments::validate( $file );

		$this->assertWPError( $error );
		$this->assertSame( 'tamano', $error->get_error_code() );
	}

	/**
	 * Anything that is not PDF, ODT or DOCX is refused.
	 */
	public function test_another_format_is_refused() {
		$error = Documentate_App_Attachments::validate( $this->file_fixture( 'hoja.xlsx', 'no importa' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'tipo', $error->get_error_code() );
	}

	/**
	 * A real PDF passes.
	 */
	public function test_a_pdf_is_accepted() {
		$this->assertTrue( Documentate_App_Attachments::validate( $this->file_fixture( 'resolucion.pdf' ) ) );
	}

	/**
	 * Storing a file attaches it to the document and records the event.
	 */
	public function test_storing_a_file_attaches_it_and_records_the_event() {
		$attachment_id = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );

		$this->assertIsInt( $attachment_id );
		$this->assertGreaterThan( 0, $attachment_id );
		$this->assertSame( $this->doc_id, (int) get_post_field( 'post_parent', $attachment_id ) );
		$this->assertSame(
			array( $attachment_id ),
			array_map( 'intval', (array) get_post_meta( $this->doc_id, Documentate_Document_Data::META_ATTACHMENTS, true ) )
		);

		$attachment = Documentate_Document_Data::attachment( $this->doc_id );
		$this->assertInstanceOf( WP_Post::class, $attachment );
		$this->assertSame( $attachment_id, $attachment->ID );
		$name = Documentate_App_Attachments::name( $attachment_id );
		$this->assertStringStartsWith( 'resolucion', $name );
		$this->assertContains( 'adjuntó el fichero «' . $name . '»', $this->events() );
		$this->assertNotSame( '', Documentate_App_Attachments::readable_size( $attachment_id ) );
	}

	/**
	 * The name on disk is unguessable; the name on screen is the one chosen.
	 *
	 * The uploads folder is served by the web server with no capability check
	 * of any kind, so a document whose file kept its own name would be one
	 * guess away from anybody on the internet.
	 */
	public function test_the_file_is_stored_under_an_unguessable_name() {
		$attachment_id = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );

		$on_disk = basename( (string) get_attached_file( $attachment_id ) );

		$this->assertNotSame( 'resolucion.pdf', $on_disk );
		$this->assertMatchesRegularExpression( '/^resolucion-[0-9a-f]{16}\.pdf$/', $on_disk );
		$this->assertSame( 'resolucion.pdf', Documentate_App_Attachments::name( $attachment_id ), 'The readable name is what the lists and the activity show.' );
	}

	/**
	 * The file is served by a handler that checks the document permission.
	 */
	public function test_the_file_is_served_only_to_whoever_may_open_the_document() {
		$attachment_id = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );

		$url = Documentate_App_Attachments::url( $this->doc_id );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Documentate_App_Attachments::SERVE_ACTION, $url );
		$this->assertStringContainsString( 'doc=' . $this->doc_id, $url );
		$this->assertStringContainsString( 'adjunto=' . $attachment_id, $url );

		$_GET = array(
			'doc' => (string) $this->doc_id,
			'adjunto' => (string) $attachment_id,
		);

		// Somebody from another área cannot read the file of this document.
		$intruder = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $intruder, 'documentate_scope_term_id', (int) wp_insert_term( 'Otra área ' . uniqid(), 'category' )['term_id'] );
		wp_set_current_user( $intruder );

		try {
			Documentate_App_Attachments::serve();
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
		$this->assertSame( '', Documentate_App_Attachments::url( $this->doc_id ) );
		$this->assertSame( '', Documentate_App_Attachments::url( 0 ) );
	}

	/**
	 * A path that is neither an upload nor a file the plugin wrote is refused.
	 *
	 * media_handle_sideload() — unlike wp_handle_upload() — skips the
	 * is_uploaded_file() test, so a posted tmp_name pointing anywhere the
	 * process can read would otherwise copy that file to a public URL.
	 */
	public function test_a_path_outside_the_temporary_folder_is_refused() {
		$outside = trailingslashit( wp_upload_dir()['basedir'] ) . 'documentate-origen-' . uniqid() . '.pdf';
		file_put_contents( $outside, self::PDF ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$error = Documentate_App_Attachments::store(
			$this->doc_id,
			array(
				'name' => 'resolucion.pdf',
				'type' => 'application/pdf',
				'tmp_name' => $outside,
				'error' => 0,
				'size' => (int) filesize( $outside ),
			)
		);

		$this->assertWPError( $error );
		$this->assertSame( 'sin_subida', $error->get_error_code() );
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );
		$this->assertFileExists( $outside, 'The file it pointed at is left alone.' );

		wp_delete_file( $outside );
	}

	/**
	 * A second file replaces the first one.
	 */
	public function test_a_second_file_replaces_the_first_one() {
		$first = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'primera.pdf' ) );
		$second = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'segunda.pdf' ) );

		$this->assertNotSame( $first, $second );
		$this->assertSame( $second, Documentate_Document_Data::attachment( $this->doc_id )->ID );
	}

	/**
	 * An invalid file never reaches the media library.
	 */
	public function test_an_invalid_file_is_not_stored() {
		$error = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'hoja.xlsx', 'no importa' ) );

		$this->assertWPError( $error );
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );
		$this->assertSame( array(), $this->events() );
	}

	/**
	 * Whoever cannot edit the document cannot attach anything to it.
	 */
	public function test_a_stranger_cannot_attach_anything() {
		wp_set_current_user( 0 );

		$error = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'sin_permiso', $error->get_error_code() );
	}

	/**
	 * Removing the file detaches it and records the event.
	 */
	public function test_removing_the_file_records_the_event() {
		$attachment_id = Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );
		$name = Documentate_App_Attachments::name( $attachment_id );

		$this->assertTrue( Documentate_App_Attachments::remove( $this->doc_id ) );
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );
		$this->assertContains( 'quitó el fichero «' . $name . '»', $this->events() );
	}

	/**
	 * Removing nothing records nothing.
	 */
	public function test_removing_nothing_records_nothing() {
		$this->assertFalse( Documentate_App_Attachments::remove( $this->doc_id ) );
		$this->assertSame( array(), $this->events() );
	}

	/**
	 * Whoever cannot edit the document cannot detach its file either.
	 */
	public function test_a_stranger_cannot_remove_the_file() {
		Documentate_App_Attachments::store( $this->doc_id, $this->file_fixture( 'resolucion.pdf' ) );
		wp_set_current_user( 0 );

		$this->assertFalse( Documentate_App_Attachments::remove( $this->doc_id ) );
		$this->assertNotNull( Documentate_Document_Data::attachment( $this->doc_id ) );
	}

	/**
	 * The size of a file that is gone is not printed.
	 */
	public function test_the_size_of_a_missing_file_is_empty() {
		$this->assertSame( '', Documentate_App_Attachments::readable_size( 0 ) );
	}
}
