<?php
/**
 * Tests for Documentate_Document_Data.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Document_Data
 */
class DocumentateDocumentDataTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Document type term ID.
	 *
	 * @var int
	 */
	private $type_id;

	/**
	 * Category term ID.
	 *
	 * @var int
	 */
	private $cat_id;

	/**
	 * Document ID.
	 *
	 * @var int
	 */
	private $doc_id;

	/**
	 * Set up a typed, categorised document.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
				'display_name' => 'Ana Admin',
			)
		);
		wp_set_current_user( $this->admin_id );

		$type = wp_insert_term( 'Resolución D', 'documentate_doc_type' );
		$this->type_id = (int) $type['term_id'];
		$cat = wp_insert_term( 'Departamento de Proyectos', 'category' );
		$this->cat_id = (int) $cat['term_id'];

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución por la que se aprueban las bases del programa piloto de innovación educativa 2026',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
				'tax_input' => array( 'documentate_doc_type' => array( $this->type_id ) ),
			)
		);
		wp_set_object_terms( $this->doc_id, array( $this->cat_id ), 'category' );
	}

	/**
	 * Reset user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * post() resolves documents only.
	 */
	public function test_post_resolves_documents_only() {
		$this->assertSame( $this->doc_id, Documentate_Document_Data::post( $this->doc_id )->ID );
		$this->assertSame( $this->doc_id, Documentate_Document_Data::post( get_post( $this->doc_id ) )->ID );
		$this->assertNull( Documentate_Document_Data::post( self::factory()->post->create() ) );
		$this->assertNull( Documentate_Document_Data::post( 999999 ) );

		// An empty ID never falls back to the global post (get_post() would).
		$GLOBALS['post'] = get_post( $this->doc_id );
		$this->assertNull( Documentate_Document_Data::post( 0 ) );
		$this->assertNull( Documentate_Document_Data::post( null ) );
		$this->assertFalse( Documentate_Document_Data::has_management( 0 ) );
		unset( $GLOBALS['post'] );
	}

	/**
	 * The type comes from the term, or from the locked meta as a fallback.
	 */
	public function test_type_and_prefix() {
		$this->assertSame( $this->type_id, Documentate_Document_Data::type( $this->doc_id )->term_id );
		$this->assertSame( '', Documentate_Document_Data::type_prefix( $this->doc_id ) );

		update_term_meta( $this->type_id, 'documentate_type_prefijo', ' res ' );
		$this->assertSame( 'RES', Documentate_Document_Data::type_prefix( $this->doc_id ) );

		update_term_meta( $this->type_id, 'documentate_type_prefijo', 'demasiadolargo' );
		$this->assertSame( 'DEMASI', Documentate_Document_Data::type_prefix( $this->doc_id ) );

		// Without the term, the locked meta still names the type. The CPT locks
		// the type on first assignment and re-applies it on every term change,
		// so the lock is dropped before clearing the terms.
		delete_post_meta( $this->doc_id, 'documentate_locked_doc_type' );
		wp_set_object_terms( $this->doc_id, array(), 'documentate_doc_type' );
		$this->assertNull( Documentate_Document_Data::type( $this->doc_id ) );
		update_post_meta( $this->doc_id, 'documentate_locked_doc_type', $this->type_id );
		$this->assertSame( $this->type_id, Documentate_Document_Data::type( $this->doc_id )->term_id );

		$this->assertNull( Documentate_Document_Data::type( 999999 ) );
		$this->assertSame( '', Documentate_Document_Data::type_prefix( 999999 ) );
	}

	/**
	 * The internal name is stored trimmed to 80 characters and removed when empty.
	 */
	public function test_internal_name_round_trip() {
		$this->assertSame( '', Documentate_Document_Data::internal_name( $this->doc_id ) );

		$saved = Documentate_Document_Data::save_internal_name( $this->doc_id, ' <b>Bases</b> programa piloto ' );
		$this->assertSame( 'Bases programa piloto', $saved );
		$this->assertSame( 'Bases programa piloto', Documentate_Document_Data::internal_name( $this->doc_id ) );

		$long_text = Documentate_Document_Data::save_internal_name( $this->doc_id, str_repeat( 'á', 100 ) );
		$this->assertSame( 80, mb_strlen( $long_text ) );

		// Backslashes survive the meta round trip (update_post_meta() unslashes).
		Documentate_Document_Data::save_internal_name( $this->doc_id, 'Ruta \\\\srv\\docs' );
		$this->assertSame( 'Ruta \\\\srv\\docs', Documentate_Document_Data::internal_name( $this->doc_id ) );

		Documentate_Document_Data::save_internal_name( $this->doc_id, '   ' );
		$this->assertSame( '', Documentate_Document_Data::internal_name( $this->doc_id ) );
		$this->assertSame( '', get_post_meta( $this->doc_id, Documentate_Document_Data::META_NAME, true ) );
		$this->assertSame( '', Documentate_Document_Data::internal_name( 999999 ) );
	}

	/**
	 * Internal notes round trip.
	 */
	public function test_notes_round_trip() {
		$this->assertSame( '', Documentate_Document_Data::notes( $this->doc_id ) );

		$text = Documentate_Document_Data::save_notes( $this->doc_id, "Revisar la partida\n<em>x</em><script>alert(1)</script>" );
		$this->assertSame( "Revisar la partida\nx", $text );
		$this->assertSame( "Revisar la partida\nx", Documentate_Document_Data::notes( $this->doc_id ) );

		// Backslashes survive the meta round trip (update_post_meta() unslashes).
		Documentate_Document_Data::save_notes( $this->doc_id, "Copia en \\\\srv\\docs\nRevisar" );
		$this->assertSame( "Copia en \\\\srv\\docs\nRevisar", Documentate_Document_Data::notes( $this->doc_id ) );

		Documentate_Document_Data::save_notes( $this->doc_id, '' );
		$this->assertSame( '', Documentate_Document_Data::notes( $this->doc_id ) );
		$this->assertSame( '', Documentate_Document_Data::notes( 999999 ) );
	}

	/**
	 * The short name: prefix · internal name, with a truncated title as fallback.
	 */
	public function test_short_name() {
		$this->assertSame(
			'Resolución por la que se aprueban las bases del programa pi…',
			Documentate_Document_Data::short_name( $this->doc_id )
		);
		$this->assertSame( 60, mb_strlen( Documentate_Document_Data::short_name( $this->doc_id ) ) );

		Documentate_Document_Data::save_internal_name( $this->doc_id, 'Bases programa piloto' );
		$this->assertSame( 'Bases programa piloto', Documentate_Document_Data::short_name( $this->doc_id ) );

		update_term_meta( $this->type_id, 'documentate_type_prefijo', 'RES' );
		$this->assertSame( 'RES · Bases programa piloto', Documentate_Document_Data::short_name( $this->doc_id ) );

		$short = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Corto',
			)
		);
		$this->assertSame( 'Corto', Documentate_Document_Data::short_name( $short ) );
		$this->assertSame( '', Documentate_Document_Data::short_name( 999999 ) );
	}

	/**
	 * The "devuelto" mark: written, read and cleared.
	 */
	public function test_returned_mark() {
		$this->assertNull( Documentate_Document_Data::returned( $this->doc_id ) );

		$data = Documentate_Document_Data::mark_returned( $this->doc_id, ' Falta el <b>anexo</b> ', 'administracion', 'gestion', 42 );
		$this->assertSame( 42, $data['por'] );
		$this->assertSame( 'Falta el anexo', $data['motivo'] );
		$this->assertSame( 'administracion', $data['desde'] );
		$this->assertSame( 'gestion', $data['a'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['fecha'] );

		$read_back = Documentate_Document_Data::returned( get_post( $this->doc_id ) );
		$this->assertSame( $data, $read_back );

		// Unknown origins/destinations fall back to gestión → área, and the actor to the current user.
		$default_value = Documentate_Document_Data::mark_returned( $this->doc_id, 'x', 'nadie', 'nadie' );
		$this->assertSame( 'gestion', $default_value['desde'] );
		$this->assertSame( 'area', $default_value['a'] );
		$this->assertSame( $this->admin_id, $default_value['por'] );

		Documentate_Document_Data::clear_returned( $this->doc_id );
		$this->assertNull( Documentate_Document_Data::returned( $this->doc_id ) );

		update_post_meta( $this->doc_id, Documentate_Document_Data::META_RETURNED, 'no es json' );
		$this->assertNull( Documentate_Document_Data::returned( $this->doc_id ) );
		update_post_meta( $this->doc_id, Documentate_Document_Data::META_RETURNED, '{"motivo":"solo motivo"}' );
		$this->assertSame( 0, Documentate_Document_Data::returned( $this->doc_id )['por'] );
		$this->assertSame( 'solo motivo', Documentate_Document_Data::returned( $this->doc_id )['motivo'] );
		$this->assertNull( Documentate_Document_Data::returned( 999999 ) );
	}

	/**
	 * Whether the type goes through gestión, from the term meta.
	 */
	public function test_has_management() {
		$this->assertFalse( Documentate_Document_Data::has_management( $this->doc_id ) );
		$this->assertFalse( Documentate_Document_Data::type_has_management( $this->type_id ) );

		update_term_meta( $this->type_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Document_Data::has_management( $this->doc_id ) );
		$this->assertTrue( Documentate_Document_Data::has_management( get_post( $this->doc_id ) ) );
		$this->assertTrue( Documentate_Document_Data::type_has_management( $this->type_id ) );

		update_term_meta( $this->type_id, 'documentate_type_con_gestion', '' );
		$this->assertFalse( Documentate_Document_Data::has_management( $this->doc_id ) );

		$without_type = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$this->assertFalse( Documentate_Document_Data::has_management( $without_type ) );
		$this->assertFalse( Documentate_Document_Data::has_management( 999999 ) );
	}

	/**
	 * Whether a save goes through gestión: the document's type, else the type posted with it.
	 */
	public function test_has_management_on_save() {
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( $this->doc_id, array() ) );
		update_term_meta( $this->type_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( $this->doc_id, array() ) );

		// A typed document ignores whatever tax_input says.
		$other = wp_insert_term( 'Convocatoria D', 'documentate_doc_type' );
		$direct = (int) $other['term_id'];
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( $this->doc_id, array( 'tax_input' => array( 'documentate_doc_type' => array( $direct ) ) ) ) );

		// Without a document (creation) the posted type decides: by ID, numeric string or slug.
		$with = array( 'tax_input' => array( 'documentate_doc_type' => array( $this->type_id ) ) );
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( 0, $with ) );
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( 0, array( 'tax_input' => array( 'documentate_doc_type' => (string) $this->type_id ) ) ) );
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( '', get_term( $this->type_id )->slug ) ) ) ) );
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( $direct ) ) ) ) );
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( 999999, array( $this->type_id ) ) ) ) ) );
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( 0, array( 'tax_input' => array( 'category' => array( $this->type_id ) ) ) ) );
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( 0, array() ) );

		// An untyped document (auto-draft on its first save) reads the posted type too.
		$stub = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);
		$this->assertTrue( Documentate_Document_Data::has_management_on_save( $stub, $with ) );
		$this->assertFalse( Documentate_Document_Data::has_management_on_save( $stub, array() ) );
	}

	/**
	 * The attached file is the first ID of the attachments meta.
	 */
	public function test_attachment() {
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );

		$attachment = self::factory()->attachment->create_object(
			'informe.pdf',
			$this->doc_id,
			array(
				'post_mime_type' => 'application/pdf',
				'post_type' => 'attachment',
			)
		);

		update_post_meta( $this->doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( $attachment, 999999 ) );
		$this->assertSame( $attachment, Documentate_Document_Data::attachment( $this->doc_id )->ID );

		update_post_meta( $this->doc_id, Documentate_Document_Data::META_ATTACHMENTS, array( 999999 ) );
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );

		update_post_meta( $this->doc_id, Documentate_Document_Data::META_ATTACHMENTS, 'no es una lista' );
		$this->assertNull( Documentate_Document_Data::attachment( $this->doc_id ) );
		$this->assertNull( Documentate_Document_Data::attachment( 999999 ) );
	}

	/**
	 * Área and person of the document.
	 */
	public function test_area_and_person() {
		$this->assertSame( 'Departamento de Proyectos', Documentate_Document_Data::area( $this->doc_id ) );
		$this->assertSame( 'Ana Admin', Documentate_Document_Data::person( $this->doc_id ) );

		wp_set_object_terms( $this->doc_id, array(), 'category' );
		$this->assertSame( '', Documentate_Document_Data::area( $this->doc_id ) );

		$orphan = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_author' => 0,
			)
		);
		$this->assertSame( '', Documentate_Document_Data::person( $orphan ) );
		$this->assertSame( '', Documentate_Document_Data::area( 999999 ) );
		$this->assertSame( '', Documentate_Document_Data::person( 999999 ) );
	}

	/**
	 * The curso value only exists when the schema defines the field.
	 */
	public function test_course_depends_on_the_schema() {
		update_post_meta( $this->doc_id, 'documentate_field_curso', '2026-2027' );
		$this->assertSame( '', Documentate_Document_Data::course( $this->doc_id ), 'No schema: no curso.' );

		( new Documentate\DocType\SchemaStorage() )->save_schema(
			$this->type_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'Curso',
						'slug' => 'curso',
						'type' => 'text',
						'title' => 'Curso',
					),
				),
				'repeaters' => array(),
				'meta' => array(
					'template_type' => 'odt',
					'template_name' => 'curso.odt',
					'hash' => md5( 'curso' ),
					'parsed_at' => current_time( 'mysql' ),
				),
			)
		);

		$this->assertSame( '2026-2027', Documentate_Document_Data::course( $this->doc_id ) );
		$this->assertSame( '', Documentate_Document_Data::course( 999999 ) );
	}
}
