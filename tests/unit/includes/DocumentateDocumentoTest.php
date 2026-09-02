<?php
/**
 * Tests for Documentate_Documento.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Documento
 */
class DocumentateDocumentoTest extends WP_UnitTestCase {

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
	private $tipo_id;

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

		$tipo = wp_insert_term( 'Resolución D', 'documentate_doc_type' );
		$this->tipo_id = (int) $tipo['term_id'];
		$cat = wp_insert_term( 'Departamento de Proyectos', 'category' );
		$this->cat_id = (int) $cat['term_id'];

		$this->doc_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución por la que se aprueban las bases del programa piloto de innovación educativa 2026',
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
				'tax_input' => array( 'documentate_doc_type' => array( $this->tipo_id ) ),
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
		$this->assertSame( $this->doc_id, Documentate_Documento::post( $this->doc_id )->ID );
		$this->assertSame( $this->doc_id, Documentate_Documento::post( get_post( $this->doc_id ) )->ID );
		$this->assertNull( Documentate_Documento::post( self::factory()->post->create() ) );
		$this->assertNull( Documentate_Documento::post( 999999 ) );

		// An empty ID never falls back to the global post (get_post() would).
		$GLOBALS['post'] = get_post( $this->doc_id );
		$this->assertNull( Documentate_Documento::post( 0 ) );
		$this->assertNull( Documentate_Documento::post( null ) );
		$this->assertFalse( Documentate_Documento::con_gestion( 0 ) );
		unset( $GLOBALS['post'] );
	}

	/**
	 * The type comes from the term, or from the locked meta as a fallback.
	 */
	public function test_tipo_and_prefijo() {
		$this->assertSame( $this->tipo_id, Documentate_Documento::tipo( $this->doc_id )->term_id );
		$this->assertSame( '', Documentate_Documento::prefijo_tipo( $this->doc_id ) );

		update_term_meta( $this->tipo_id, 'documentate_type_prefijo', ' res ' );
		$this->assertSame( 'RES', Documentate_Documento::prefijo_tipo( $this->doc_id ) );

		update_term_meta( $this->tipo_id, 'documentate_type_prefijo', 'demasiadolargo' );
		$this->assertSame( 'DEMASI', Documentate_Documento::prefijo_tipo( $this->doc_id ) );

		// Without the term, the locked meta still names the type. The CPT locks
		// the type on first assignment and re-applies it on every term change,
		// so the lock is dropped before clearing the terms.
		delete_post_meta( $this->doc_id, 'documentate_locked_doc_type' );
		wp_set_object_terms( $this->doc_id, array(), 'documentate_doc_type' );
		$this->assertNull( Documentate_Documento::tipo( $this->doc_id ) );
		update_post_meta( $this->doc_id, 'documentate_locked_doc_type', $this->tipo_id );
		$this->assertSame( $this->tipo_id, Documentate_Documento::tipo( $this->doc_id )->term_id );

		$this->assertNull( Documentate_Documento::tipo( 999999 ) );
		$this->assertSame( '', Documentate_Documento::prefijo_tipo( 999999 ) );
	}

	/**
	 * The internal name is stored trimmed to 80 characters and removed when empty.
	 */
	public function test_nombre_interno_round_trip() {
		$this->assertSame( '', Documentate_Documento::nombre_interno( $this->doc_id ) );

		$guardado = Documentate_Documento::guardar_nombre_interno( $this->doc_id, ' <b>Bases</b> programa piloto ' );
		$this->assertSame( 'Bases programa piloto', $guardado );
		$this->assertSame( 'Bases programa piloto', Documentate_Documento::nombre_interno( $this->doc_id ) );

		$largo = Documentate_Documento::guardar_nombre_interno( $this->doc_id, str_repeat( 'á', 100 ) );
		$this->assertSame( 80, mb_strlen( $largo ) );

		// Backslashes survive the meta round trip (update_post_meta() unslashes).
		Documentate_Documento::guardar_nombre_interno( $this->doc_id, 'Ruta \\\\srv\\docs' );
		$this->assertSame( 'Ruta \\\\srv\\docs', Documentate_Documento::nombre_interno( $this->doc_id ) );

		Documentate_Documento::guardar_nombre_interno( $this->doc_id, '   ' );
		$this->assertSame( '', Documentate_Documento::nombre_interno( $this->doc_id ) );
		$this->assertSame( '', get_post_meta( $this->doc_id, Documentate_Documento::META_NOMBRE, true ) );
		$this->assertSame( '', Documentate_Documento::nombre_interno( 999999 ) );
	}

	/**
	 * Internal notes round trip.
	 */
	public function test_anotaciones_round_trip() {
		$this->assertSame( '', Documentate_Documento::anotaciones( $this->doc_id ) );

		$texto = Documentate_Documento::guardar_anotaciones( $this->doc_id, "Revisar la partida\n<em>x</em><script>alert(1)</script>" );
		$this->assertSame( "Revisar la partida\nx", $texto );
		$this->assertSame( "Revisar la partida\nx", Documentate_Documento::anotaciones( $this->doc_id ) );

		// Backslashes survive the meta round trip (update_post_meta() unslashes).
		Documentate_Documento::guardar_anotaciones( $this->doc_id, "Copia en \\\\srv\\docs\nRevisar" );
		$this->assertSame( "Copia en \\\\srv\\docs\nRevisar", Documentate_Documento::anotaciones( $this->doc_id ) );

		Documentate_Documento::guardar_anotaciones( $this->doc_id, '' );
		$this->assertSame( '', Documentate_Documento::anotaciones( $this->doc_id ) );
		$this->assertSame( '', Documentate_Documento::anotaciones( 999999 ) );
	}

	/**
	 * The short name: prefix · internal name, with a truncated title as fallback.
	 */
	public function test_nombre_corto() {
		$this->assertSame(
			'Resolución por la que se aprueban las bases del programa pi…',
			Documentate_Documento::nombre_corto( $this->doc_id )
		);
		$this->assertSame( 60, mb_strlen( Documentate_Documento::nombre_corto( $this->doc_id ) ) );

		Documentate_Documento::guardar_nombre_interno( $this->doc_id, 'Bases programa piloto' );
		$this->assertSame( 'Bases programa piloto', Documentate_Documento::nombre_corto( $this->doc_id ) );

		update_term_meta( $this->tipo_id, 'documentate_type_prefijo', 'RES' );
		$this->assertSame( 'RES · Bases programa piloto', Documentate_Documento::nombre_corto( $this->doc_id ) );

		$corto = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Corto',
			)
		);
		$this->assertSame( 'Corto', Documentate_Documento::nombre_corto( $corto ) );
		$this->assertSame( '', Documentate_Documento::nombre_corto( 999999 ) );
	}

	/**
	 * The "devuelto" mark: written, read and cleared.
	 */
	public function test_devuelto_mark() {
		$this->assertNull( Documentate_Documento::devuelto( $this->doc_id ) );

		$datos = Documentate_Documento::marcar_devuelto( $this->doc_id, ' Falta el <b>anexo</b> ', 'administracion', 'gestion', 42 );
		$this->assertSame( 42, $datos['por'] );
		$this->assertSame( 'Falta el anexo', $datos['motivo'] );
		$this->assertSame( 'administracion', $datos['desde'] );
		$this->assertSame( 'gestion', $datos['a'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $datos['fecha'] );

		$leido = Documentate_Documento::devuelto( get_post( $this->doc_id ) );
		$this->assertSame( $datos, $leido );

		// Unknown origins/destinations fall back to gestión → área, and the actor to the current user.
		$defecto = Documentate_Documento::marcar_devuelto( $this->doc_id, 'x', 'nadie', 'nadie' );
		$this->assertSame( 'gestion', $defecto['desde'] );
		$this->assertSame( 'area', $defecto['a'] );
		$this->assertSame( $this->admin_id, $defecto['por'] );

		Documentate_Documento::limpiar_devuelto( $this->doc_id );
		$this->assertNull( Documentate_Documento::devuelto( $this->doc_id ) );

		update_post_meta( $this->doc_id, Documentate_Documento::META_DEVUELTO, 'no es json' );
		$this->assertNull( Documentate_Documento::devuelto( $this->doc_id ) );
		update_post_meta( $this->doc_id, Documentate_Documento::META_DEVUELTO, '{"motivo":"solo motivo"}' );
		$this->assertSame( 0, Documentate_Documento::devuelto( $this->doc_id )['por'] );
		$this->assertSame( 'solo motivo', Documentate_Documento::devuelto( $this->doc_id )['motivo'] );
		$this->assertNull( Documentate_Documento::devuelto( 999999 ) );
	}

	/**
	 * Whether the type goes through gestión, from the term meta.
	 */
	public function test_con_gestion() {
		$this->assertFalse( Documentate_Documento::con_gestion( $this->doc_id ) );
		$this->assertFalse( Documentate_Documento::tipo_con_gestion( $this->tipo_id ) );

		update_term_meta( $this->tipo_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Documento::con_gestion( $this->doc_id ) );
		$this->assertTrue( Documentate_Documento::con_gestion( get_post( $this->doc_id ) ) );
		$this->assertTrue( Documentate_Documento::tipo_con_gestion( $this->tipo_id ) );

		update_term_meta( $this->tipo_id, 'documentate_type_con_gestion', '' );
		$this->assertFalse( Documentate_Documento::con_gestion( $this->doc_id ) );

		$sin_tipo = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$this->assertFalse( Documentate_Documento::con_gestion( $sin_tipo ) );
		$this->assertFalse( Documentate_Documento::con_gestion( 999999 ) );
	}

	/**
	 * Whether a save goes through gestión: the document's type, else the type posted with it.
	 */
	public function test_con_gestion_al_guardar() {
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( $this->doc_id, array() ) );
		update_term_meta( $this->tipo_id, 'documentate_type_con_gestion', '1' );
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( $this->doc_id, array() ) );

		// A typed document ignores whatever tax_input says.
		$otro = wp_insert_term( 'Convocatoria D', 'documentate_doc_type' );
		$directo = (int) $otro['term_id'];
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( $this->doc_id, array( 'tax_input' => array( 'documentate_doc_type' => array( $directo ) ) ) ) );

		// Without a document (creation) the posted type decides: by ID, numeric string or slug.
		$con = array( 'tax_input' => array( 'documentate_doc_type' => array( $this->tipo_id ) ) );
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( 0, $con ) );
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( 0, array( 'tax_input' => array( 'documentate_doc_type' => (string) $this->tipo_id ) ) ) );
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( '', get_term( $this->tipo_id )->slug ) ) ) ) );
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( $directo ) ) ) ) );
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( 0, array( 'tax_input' => array( 'documentate_doc_type' => array( 999999, array( $this->tipo_id ) ) ) ) ) );
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( 0, array( 'tax_input' => array( 'category' => array( $this->tipo_id ) ) ) ) );
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( 0, array() ) );

		// An untyped document (auto-draft on its first save) reads the posted type too.
		$stub = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);
		$this->assertTrue( Documentate_Documento::con_gestion_al_guardar( $stub, $con ) );
		$this->assertFalse( Documentate_Documento::con_gestion_al_guardar( $stub, array() ) );
	}

	/**
	 * The attached file is the first ID of the attachments meta.
	 */
	public function test_adjunto() {
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );

		$attachment = self::factory()->attachment->create_object(
			'informe.pdf',
			$this->doc_id,
			array(
				'post_mime_type' => 'application/pdf',
				'post_type' => 'attachment',
			)
		);

		update_post_meta( $this->doc_id, Documentate_Documento::META_ADJUNTOS, array( $attachment, 999999 ) );
		$this->assertSame( $attachment, Documentate_Documento::adjunto( $this->doc_id )->ID );

		update_post_meta( $this->doc_id, Documentate_Documento::META_ADJUNTOS, array( 999999 ) );
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );

		update_post_meta( $this->doc_id, Documentate_Documento::META_ADJUNTOS, 'no es una lista' );
		$this->assertNull( Documentate_Documento::adjunto( $this->doc_id ) );
		$this->assertNull( Documentate_Documento::adjunto( 999999 ) );
	}

	/**
	 * Área and persona of the document.
	 */
	public function test_area_and_persona() {
		$this->assertSame( 'Departamento de Proyectos', Documentate_Documento::area( $this->doc_id ) );
		$this->assertSame( 'Ana Admin', Documentate_Documento::persona( $this->doc_id ) );

		wp_set_object_terms( $this->doc_id, array(), 'category' );
		$this->assertSame( '', Documentate_Documento::area( $this->doc_id ) );

		$huerfano = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_author' => 0,
			)
		);
		$this->assertSame( '', Documentate_Documento::persona( $huerfano ) );
		$this->assertSame( '', Documentate_Documento::area( 999999 ) );
		$this->assertSame( '', Documentate_Documento::persona( 999999 ) );
	}

	/**
	 * The curso value only exists when the schema defines the field.
	 */
	public function test_curso_depends_on_the_schema() {
		update_post_meta( $this->doc_id, 'documentate_field_curso', '2026-2027' );
		$this->assertSame( '', Documentate_Documento::curso( $this->doc_id ), 'No schema: no curso.' );

		( new Documentate\DocType\SchemaStorage() )->save_schema(
			$this->tipo_id,
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

		$this->assertSame( '2026-2027', Documentate_Documento::curso( $this->doc_id ) );
		$this->assertSame( '', Documentate_Documento::curso( 999999 ) );
	}
}
