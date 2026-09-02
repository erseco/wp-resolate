<?php
/**
 * Tests for Documentate_Transiciones.
 *
 * Walks the rule table with every role and both kinds of document type,
 * checking what is offered, what is allowed and what aplicar() writes.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Transiciones
 */
class DocumentateTransicionesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Gestión documental user ID (editor).
	 *
	 * @var int
	 */
	private $gestion_id;

	/**
	 * Área user ID (author).
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
	 * Document type that goes through gestión.
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
	 * Set up users, scope, types and the custom statuses.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Estados::registrar();
		Documentate_Roles::ensure_caps( true );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$cat = wp_insert_term( 'Ámbito Transiciones', 'category' );
		$this->cat_id = (int) $cat['term_id'];
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $this->cat_id );

		$gestion = wp_insert_term( 'Resolución T', 'documentate_doc_type' );
		$this->tipo_gestion = (int) $gestion['term_id'];
		update_term_meta( $this->tipo_gestion, 'documentate_type_con_gestion', '1' );

		$directo = wp_insert_term( 'Convocatoria T', 'documentate_doc_type' );
		$this->tipo_directo = (int) $directo['term_id'];
	}

	/**
	 * Reset request and user state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Create a document of the área user in a status.
	 *
	 * @param string $status  Post status.
	 * @param int    $tipo_id Document type term ID.
	 * @return int
	 */
	private function crear_documento( $status, $tipo_id ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento de prueba',
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( $tipo_id ) ),
			)
		);
		update_post_meta( $post_id, 'documentate_locked_doc_type', $tipo_id );
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		wp_set_current_user( 0 );

		return $post_id;
	}

	/**
	 * User ID for a role name.
	 *
	 * @param string $rol area, gestion or admin.
	 * @return int
	 */
	private function usuario( $rol ) {
		$ids = array(
			'area' => $this->area_id,
			'gestion' => $this->gestion_id,
			'admin' => $this->admin_id,
		);

		return $ids[ $rol ];
	}

	/**
	 * Every rule × role × con_gestion, with whether it must be allowed.
	 *
	 * @return array<string,array>
	 */
	public function provider_reglas() {
		$rangos = array(
			'area' => 0,
			'gestion' => 1,
			'admin' => 2,
		);
		$casos = array();

		foreach ( Documentate_Transiciones::reglas() as $regla ) {
			foreach ( array( 'area', 'gestion', 'admin' ) as $rol ) {
				foreach ( array( true, false ) as $con_gestion ) {
					$rol_ok = $rangos[ $rol ] >= $rangos[ $regla['quien'] ];
					$tipo_ok = null === $regla['con_gestion'] || $regla['con_gestion'] === $con_gestion;
					$nombre = sprintf( '%s %s→%s · %s · %s', $regla['clave'], $regla['desde'], $regla['destino'], $rol, $con_gestion ? 'con gestión' : 'directo' );
					$casos[ $nombre ] = array( $regla, $rol, $con_gestion, $rol_ok && $tipo_ok );
				}
			}
		}

		return $casos;
	}

	/**
	 * disponibles(), permitida() and aplicar() agree on every rule, role and type.
	 *
	 * @dataProvider provider_reglas
	 *
	 * @param array  $regla       Rule row.
	 * @param string $rol         Role name.
	 * @param bool   $con_gestion Whether the type goes through gestión.
	 * @param bool   $esperado    Whether the transition must be allowed.
	 */
	public function test_tabla_de_reglas( array $regla, $rol, $con_gestion, $esperado ) {
		$doc = $this->crear_documento( $regla['desde'], $con_gestion ? $this->tipo_gestion : $this->tipo_directo );
		$user_id = $this->usuario( $rol );
		$motivo = 'Falta el anexo firmado';

		// A previous return mark must be cleared by forward transitions.
		Documentate_Documento::marcar_devuelto( $doc, 'antes', 'gestion', 'area', $this->gestion_id );

		wp_set_current_user( $user_id );
		$disponibles = Documentate_Transiciones::disponibles( get_post( $doc ) );
		$ofrecida = isset( $disponibles[ $regla['clave'] ] ) && $disponibles[ $regla['clave'] ]['destino'] === $regla['destino'];
		$this->assertSame( $esperado, $ofrecida, 'disponibles()' );
		$this->assertSame( $esperado, Documentate_Transiciones::permitida( $doc, $regla['desde'], $regla['destino'], $user_id, $motivo ), 'permitida()' );

		$resultado = Documentate_Transiciones::aplicar( $doc, $regla['clave'], $motivo );

		if ( ! $esperado ) {
			$this->assertWPError( $resultado );
			$this->assertSame( 'transicion_no_disponible', $resultado->get_error_code() );
			$this->assertSame( $regla['desde'], get_post_status( $doc ) );
			$this->assertSame( array(), Documentate_Actividad::listar( $doc ), 'No event on a refused transition.' );
			return;
		}

		$this->assertTrue( $resultado );
		$this->assertSame( $regla['destino'], get_post_status( $doc ) );

		$eventos = Documentate_Actividad::listar( $doc );
		$this->assertCount( 1, $eventos );
		$this->assertSame( 'evento', $eventos[0]['tipo'] );

		$devuelto = Documentate_Documento::devuelto( $doc );
		if ( $regla['motivo'] ) {
			$this->assertSame( $regla['evento'] . ': «' . $motivo . '»', $eventos[0]['texto'] );
			$this->assertSame( $motivo, $eventos[0]['motivo'] );
			$this->assertNotNull( $devuelto );
			$this->assertSame( $motivo, $devuelto['motivo'] );
			$this->assertSame( $user_id, $devuelto['por'] );
			$this->assertSame( 'pending' === $regla['desde'] ? 'administracion' : 'gestion', $devuelto['desde'] );
			$this->assertSame( 'en_gestion' === $regla['destino'] ? 'gestion' : 'area', $devuelto['a'] );
		} else {
			$this->assertSame( $regla['evento'], $eventos[0]['texto'] );
			$this->assertSame( '', $eventos[0]['motivo'] );
			$this->assertNull( $devuelto, 'Forward transitions clear the return mark.' );
		}
	}

	/**
	 * A return without a reason (or a too short one) is refused everywhere.
	 */
	public function test_returns_require_a_reason() {
		$doc = $this->crear_documento( 'pending', $this->tipo_gestion );
		wp_set_current_user( $this->admin_id );

		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'pending', 'draft', $this->admin_id ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'pending', 'draft', $this->admin_id, ' ab ' ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'pending', 'draft', $this->admin_id, 'abc' ) );

		$sin = Documentate_Transiciones::aplicar( $doc, 'devolver_area' );
		$this->assertWPError( $sin );
		$this->assertSame( 'motivo_requerido', $sin->get_error_code() );

		$corto = Documentate_Transiciones::aplicar( $doc, 'devolver_gestion', 'no' );
		$this->assertWPError( $corto );
		$this->assertSame( 'motivo_requerido', $corto->get_error_code() );

		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertNull( Documentate_Documento::devuelto( $doc ) );
		$this->assertSame( array(), Documentate_Actividad::listar( $doc ) );
	}

	/**
	 * The edge cases permitida() always lets through or always blocks.
	 */
	public function test_permitida_edge_cases() {
		$doc = $this->crear_documento( 'en_gestion', $this->tipo_gestion );

		// Same status, creation as a draft and trash are always allowed.
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'en_gestion', 'en_gestion', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'auto-draft', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'new', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, '', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'draft', 'trash', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'trash', 'draft', $this->area_id ) );

		// A document is born as a draft: creation straight into the pipeline follows the table.
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, '', 'pending', $this->area_id ), 'Con gestión: pending skips gestión.' );
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'auto-draft', 'pending', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'auto-draft', 'en_gestion', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'new', 'publish', $this->area_id ), 'Left to the role rule.' );
		$directo = $this->crear_documento( 'draft', $this->tipo_directo );
		$this->assertTrue( Documentate_Transiciones::permitida( $directo, 'auto-draft', 'pending', $this->area_id ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $directo, '', 'en_gestion', $this->area_id ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $directo, 'new', 'en_gestion', $this->gestion_id ) );

		// Administración creates documents in any status (seeders, imports, fixes).
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, '', 'pending', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $directo, 'new', 'en_gestion', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( 0, 'auto-draft', 'archived', $this->admin_id, '', false ) );

		// The caller may say whether the type goes through gestión (type posted with a first save).
		$this->assertFalse( Documentate_Transiciones::permitida( 0, '', 'pending', $this->area_id, '', true ) );
		$this->assertTrue( Documentate_Transiciones::permitida( 0, '', 'en_gestion', $this->area_id, '', true ) );
		$this->assertTrue( Documentate_Transiciones::permitida( 0, '', 'pending', $this->area_id, '', false ) );
		$this->assertFalse( Documentate_Transiciones::permitida( 0, '', 'en_gestion', $this->area_id, '', false ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'draft', 'pending', $this->area_id, '', false ), 'The hint wins over the stored type.' );

		// Administrators move between publish and archived and keep their legacy freedom.
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'publish', 'archived', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'archived', 'publish', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'archived', 'draft', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'publish', 'draft', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'publish', 'pending', $this->admin_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'draft', 'publish', $this->admin_id ) );

		// ...except when leaving en_gestion outside the table.
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'en_gestion', 'publish', $this->admin_id ) );

		// Publish-like requests from draft are left to the workflow role rule.
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'draft', 'publish', $this->area_id ) );
		$this->assertTrue( Documentate_Transiciones::permitida( $doc, 'draft', 'private', $this->gestion_id ) );

		// Non-administrators cannot leave locked statuses outside the table.
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'publish', 'draft', $this->area_id ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'archived', 'publish', $this->gestion_id ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'en_gestion', 'publish', $this->gestion_id ) );
	}

	/**
	 * While aplicar() runs, the workflow lets its own status change through.
	 */
	public function test_en_curso_bypass_and_motivo_en_curso() {
		$doc = $this->crear_documento( 'pending', $this->tipo_gestion );
		$visto = array();

		$espia = function ( $data ) use ( $doc, &$visto ) {
			$visto['permitida'] = Documentate_Transiciones::permitida( $doc, 'pending', 'draft', $this->area_id );
			$visto['motivo'] = Documentate_Transiciones::motivo_en_curso( $doc );
			$visto['otro'] = Documentate_Transiciones::motivo_en_curso( $doc + 1 );
			return $data;
		};
		add_filter( 'wp_insert_post_data', $espia, 9 );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Transiciones::aplicar( $doc, 'devolver_area', 'Falta el expediente' ) );
		remove_filter( 'wp_insert_post_data', $espia, 9 );

		$this->assertTrue( $visto['permitida'], 'The área user could not do it, but the transition in progress passes.' );
		$this->assertSame( 'Falta el expediente', $visto['motivo'] );
		$this->assertSame( '', $visto['otro'] );

		// Cleared afterwards.
		$this->assertSame( '', Documentate_Transiciones::motivo_en_curso( $doc ) );
		$this->assertFalse( Documentate_Transiciones::permitida( $doc, 'pending', 'draft', $this->area_id ) );
	}

	/**
	 * aplicar() refuses unknown documents and transitions the workflow does not land.
	 */
	public function test_aplicar_failures_leave_no_trace() {
		wp_set_current_user( $this->admin_id );

		$invalido = Documentate_Transiciones::aplicar( 999999, 'aprobar' );
		$this->assertWPError( $invalido );
		$this->assertSame( 'documento_invalido', $invalido->get_error_code() );

		// A draft without a type: the workflow keeps it in draft (Rule 1).
		$sin_tipo = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Sin tipo',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_object_terms( $sin_tipo, array( $this->cat_id ), 'category' );
		Documentate_Documento::marcar_devuelto( $sin_tipo, 'antes', 'gestion', 'area', $this->gestion_id );

		$resultado = Documentate_Transiciones::aplicar( $sin_tipo, 'enviar_revision' );

		$this->assertWPError( $resultado );
		$this->assertSame( 'transicion_no_aplicada', $resultado->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $sin_tipo ) );
		$this->assertSame( array(), Documentate_Actividad::listar( $sin_tipo ), 'The event is rolled back.' );
		$this->assertSame( 'antes', Documentate_Documento::devuelto( $sin_tipo )['motivo'], 'The mark is restored.' );
	}

	/**
	 * Where the application lands after each action, and the flag it shows.
	 */
	public function test_redireccion_y_bandera() {
		$this->assertSame( 'detalle', Documentate_Transiciones::redireccion( 'enviar_gestion' ) );
		$this->assertSame( 'enviado', Documentate_Transiciones::bandera( 'enviar_gestion' ) );
		$this->assertSame( 'detalle', Documentate_Transiciones::redireccion( 'pasar_admin' ) );
		$this->assertSame( 'enviado', Documentate_Transiciones::bandera( 'pasar_admin' ) );
		$this->assertSame( 'bandeja', Documentate_Transiciones::redireccion( 'devolver_area' ) );
		$this->assertSame( 'devuelto', Documentate_Transiciones::bandera( 'devolver_gestion' ) );
		$this->assertSame( 'detalle', Documentate_Transiciones::redireccion( 'aprobar' ) );
		$this->assertSame( 'aprobado', Documentate_Transiciones::bandera( 'aprobar' ) );
		$this->assertSame( '', Documentate_Transiciones::bandera( 'archivar' ) );
		$this->assertSame( 'editar', Documentate_Transiciones::redireccion( 'guardar' ) );
		$this->assertSame( 'guardado', Documentate_Transiciones::bandera( 'guardar' ) );
	}

	/**
	 * regla() finds a row by key, disambiguated by the starting status.
	 */
	public function test_regla_lookup() {
		$this->assertSame( 'admin', Documentate_Transiciones::regla( 'devolver_area', 'pending' )['quien'] );
		$this->assertSame( 'gestion', Documentate_Transiciones::regla( 'devolver_area', 'en_gestion' )['quien'] );
		$this->assertSame( 'en_gestion', Documentate_Transiciones::regla( 'devolver_area' )['desde'] );
		$this->assertSame( 'Aprobar y publicar', Documentate_Transiciones::regla( 'aprobar' )['etiqueta'] );
		$this->assertNull( Documentate_Transiciones::regla( 'inventada' ) );
		$this->assertNull( Documentate_Transiciones::regla( 'aprobar', 'draft' ) );
	}

	/**
	 * A return posted through wp-admin (status + reason) is recorded by the hook.
	 */
	public function test_registrar_desde_guardado_records_wp_admin_returns() {
		$doc = $this->crear_documento( 'pending', $this->tipo_gestion );
		wp_set_current_user( $this->admin_id );

		$_POST['documentate_motivo'] = ' Falta el número de expediente ';
		$_POST[ Documentate_Transiciones::NONCE ] = wp_create_nonce( Documentate_Transiciones::NONCE );

		$this->assertSame( 'Falta el número de expediente', Documentate_Transiciones::motivo_publicado() );

		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'en_gestion',
			)
		);

		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$devuelto = Documentate_Documento::devuelto( $doc );
		$this->assertSame( 'Falta el número de expediente', $devuelto['motivo'] );
		$this->assertSame( 'administracion', $devuelto['desde'] );
		$this->assertSame( 'gestion', $devuelto['a'] );

		$eventos = Documentate_Actividad::listar( $doc );
		$this->assertCount( 1, $eventos );
		$this->assertSame( 'devolvió el documento a gestión: «Falta el número de expediente»', $eventos[0]['texto'] );
	}

	/**
	 * The first save of a document created in wp-admin records its creation.
	 *
	 * Saved as a draft it records "creó el borrador"; saved straight into
	 * the pipeline it records the transition from draft it amounts to.
	 */
	public function test_registrar_desde_guardado_records_wp_admin_creation() {
		$doc = $this->crear_documento( 'auto-draft', $this->tipo_gestion );
		wp_set_current_user( $this->area_id );

		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'draft', get_post_status( $doc ) );
		$eventos = Documentate_Actividad::listar( $doc );
		$this->assertCount( 1, $eventos );
		$this->assertSame( 'creó el borrador', $eventos[0]['texto'] );
		$this->assertNotSame( '', Documentate_Actividad::fecha_evento( $doc, 'creó' ) );

		// Only once: later draft saves are silent, and sending records the send.
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Otro título',
			)
		);
		$this->assertCount( 1, Documentate_Actividad::listar( $doc ) );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'en_gestion',
			)
		);
		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$this->assertSame( 'envió el documento a gestión', Documentate_Actividad::listar( $doc )[0]['texto'] );
		$this->assertCount( 2, Documentate_Actividad::listar( $doc ) );

		// Straight from auto-draft into gestión: the send is recorded, not a creation.
		$directo = $this->crear_documento( 'auto-draft', $this->tipo_gestion );
		wp_set_current_user( $this->area_id );
		wp_update_post(
			array(
				'ID' => $directo,
				'post_status' => 'en_gestion',
			)
		);
		$this->assertSame( 'en_gestion', get_post_status( $directo ) );
		$eventos = Documentate_Actividad::listar( $directo );
		$this->assertCount( 1, $eventos );
		$this->assertSame( 'envió el documento a gestión', $eventos[0]['texto'] );
		$this->assertSame( '', Documentate_Actividad::fecha_evento( $directo, 'creó' ) );

		// Programmatic creation (new → draft) stays silent: seeders write their own history.
		$this->assertSame( array(), Documentate_Actividad::listar( $this->crear_documento( 'draft', $this->tipo_directo ) ) );
	}

	/**
	 * A forward save through wp-admin records its event and clears the mark.
	 */
	public function test_registrar_desde_guardado_records_forward_saves() {
		$doc = $this->crear_documento( 'draft', $this->tipo_directo );
		Documentate_Documento::marcar_devuelto( $doc, 'antes', 'administracion', 'area', $this->admin_id );

		wp_set_current_user( $this->area_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'pending',
			)
		);

		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertNull( Documentate_Documento::devuelto( $doc ) );
		$eventos = Documentate_Actividad::listar( $doc );
		$this->assertCount( 1, $eventos );
		$this->assertSame( 'envió el documento a revisión', $eventos[0]['texto'] );

		// Saving again without changing the status records nothing else.
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Otro título',
			)
		);
		$this->assertCount( 1, Documentate_Actividad::listar( $doc ) );
	}

	/**
	 * The hook ignores other post types, transitions outside the table and its own.
	 */
	public function test_registrar_desde_guardado_ignores_what_is_not_a_rule() {
		$entrada = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $this->admin_id );
		wp_update_post(
			array(
				'ID' => $entrada,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array(), Documentate_Actividad::listar( $entrada ) );

		// Admin legacy publish from draft: outside the table, no event.
		$doc = $this->crear_documento( 'draft', $this->tipo_directo );
		wp_set_current_user( $this->admin_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'publish', get_post_status( $doc ) );
		$this->assertSame( array(), Documentate_Actividad::listar( $doc ) );

		// aplicar() writes once, not twice.
		$otro = $this->crear_documento( 'pending', $this->tipo_directo );
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Transiciones::aplicar( $otro, 'aprobar' ) );
		$this->assertCount( 1, Documentate_Actividad::listar( $otro ) );
	}

	/**
	 * A posted reason is ignored without a valid nonce.
	 */
	public function test_motivo_publicado_requires_nonce() {
		$this->assertSame( '', Documentate_Transiciones::motivo_publicado() );

		$_POST['documentate_motivo'] = 'Falta algo';
		$this->assertSame( '', Documentate_Transiciones::motivo_publicado() );

		$_POST[ Documentate_Transiciones::NONCE ] = 'nope';
		$this->assertSame( '', Documentate_Transiciones::motivo_publicado() );

		$_POST[ Documentate_Transiciones::NONCE ] = wp_create_nonce( Documentate_Transiciones::NONCE );
		$this->assertSame( 'Falta algo', Documentate_Transiciones::motivo_publicado() );
	}

	/**
	 * A document locked for the user cannot be trashed by them.
	 *
	 * The freeze would keep the status while WordPress hides the activity.
	 */
	public function test_bloquear_papelera_follows_the_lock() {
		$doc = $this->crear_documento( 'en_gestion', $this->tipo_gestion );
		wp_set_current_user( $this->area_id );
		$evento = Documentate_Actividad::registrar_evento( $doc, 'envió el documento a gestión' );

		$this->assertFalse( wp_trash_post( $doc ) );
		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$this->assertSame( '1', get_comment( $evento )->comment_approved, 'The activity is untouched.' );
		$this->assertCount( 1, Documentate_Actividad::listar( $doc ) );
		$this->assertSame( '', get_post_meta( $doc, '_wp_trash_meta_status', true ) );
		$this->assertFalse( Documentate_Transiciones::bloquear_papelera( null, get_post( $doc ) ) );

		// Gestión on pending is locked too; other post types are ignored.
		$pending = $this->crear_documento( 'pending', $this->tipo_gestion );
		wp_set_current_user( $this->gestion_id );
		$this->assertFalse( wp_trash_post( $pending ) );
		$this->assertSame( 'pending', get_post_status( $pending ) );
		$this->assertNull( Documentate_Transiciones::bloquear_papelera( null, get_post( self::factory()->post->create() ) ) );
		$this->assertTrue( Documentate_Transiciones::bloquear_papelera( true, get_post( $doc ) ), 'Gestión may trash en_gestion.' );

		// Whoever may modify the document may trash it: gestión on en_gestion, área on a draft, administración anywhere.
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $doc ) );
		$this->assertSame( 'trash', get_post_status( $doc ) );
		$borrador = $this->crear_documento( 'draft', $this->tipo_gestion );
		wp_set_current_user( $this->area_id );
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $borrador ) );
		wp_set_current_user( $this->admin_id );
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $pending ) );
	}

	/**
	 * init() hooks the recorder early on transition_post_status and the trash guard.
	 */
	public function test_init_hooks_recorder() {
		Documentate_Transiciones::init();

		$this->assertSame( 5, has_action( 'transition_post_status', array( 'Documentate_Transiciones', 'registrar_desde_guardado' ) ) );
		$this->assertSame( 10, has_filter( 'pre_trash_post', array( 'Documentate_Transiciones', 'bloquear_papelera' ) ) );
	}
}
