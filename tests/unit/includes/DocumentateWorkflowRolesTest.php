<?php
/**
 * Tests for the role-aware parts of Documentate_Workflow.
 *
 * Rule 0 (transition table), Rule 2 with gestión, the role-aware content
 * lock and the management metabox for the en_gestion status.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Workflow
 * @covers Documentate_Workflow_Metabox
 */
class DocumentateWorkflowRolesTest extends WP_UnitTestCase {

	/**
	 * Workflow handler instance.
	 *
	 * @var Documentate_Workflow
	 */
	private $workflow;

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
		$this->workflow = new Documentate_Workflow();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->gestion_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->gestion_id ) )->add_cap( Documentate_Roles::CAP_GESTION );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$cat = wp_insert_term( 'Ámbito Workflow', 'category' );
		$this->cat_id = (int) $cat['term_id'];
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->gestion_id, 'documentate_scope_term_id', $this->cat_id );

		$gestion = wp_insert_term( 'Resolución W', 'documentate_doc_type' );
		$this->tipo_gestion = (int) $gestion['term_id'];
		update_term_meta( $this->tipo_gestion, 'documentate_type_con_gestion', '1' );

		$directo = wp_insert_term( 'Convocatoria W', 'documentate_doc_type' );
		$this->tipo_directo = (int) $directo['term_id'];
	}

	/**
	 * Reset request and user state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Create a document of the área user in a status.
	 *
	 * @param string $status  Post status.
	 * @param int    $tipo_id Document type term ID (defaults to the gestión type).
	 * @return int
	 */
	private function crear_documento( $status, $tipo_id = 0 ) {
		$tipo_id = $tipo_id > 0 ? $tipo_id : $this->tipo_gestion;

		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Título original',
				'post_content' => 'Contenido original',
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
	 * Post a status change as a user, the way post.php would.
	 *
	 * @param int    $doc_id  Document ID.
	 * @param int    $user_id Acting user.
	 * @param string $status  Requested status.
	 * @param string $motivo  Optional reason (posted with the metabox nonce).
	 * @return string Resulting status.
	 */
	private function guardar_como( $doc_id, $user_id, $status, $motivo = '' ) {
		wp_set_current_user( $user_id );
		if ( '' !== $motivo ) {
			$_POST['documentate_motivo'] = $motivo;
			$_POST[ Documentate_Transiciones::NONCE ] = wp_create_nonce( Documentate_Transiciones::NONCE );
		}

		wp_update_post(
			array(
				'ID' => $doc_id,
				'post_status' => $status,
			)
		);

		$_POST = array();

		return get_post_status( $doc_id );
	}

	/**
	 * Render the management metabox as a user.
	 *
	 * @param int $doc_id  Document ID.
	 * @param int $user_id Acting user.
	 * @return string
	 */
	private function render_metabox( $doc_id, $user_id ) {
		wp_set_current_user( $user_id );
		ob_start();
		$this->workflow->render_document_management_metabox( get_post( $doc_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Rule 0: área cannot post pending on a draft that goes through gestión.
	 */
	public function test_area_cannot_post_pending_on_con_gestion_draft() {
		$doc = $this->crear_documento( 'draft' );

		$this->assertSame( 'draft', $this->guardar_como( $doc, $this->area_id, 'pending' ) );

		$notice = get_transient( 'documentate_workflow_notice_' . $this->area_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );

		// The same request on a direct type goes through.
		$directo = $this->crear_documento( 'draft', $this->tipo_directo );
		$this->assertSame( 'pending', $this->guardar_como( $directo, $this->area_id, 'pending' ) );
	}

	/**
	 * Rule 0: a document is born as a draft, so creating one straight into the
	 * pipeline follows the table with the type posted along the save.
	 */
	public function test_creation_straight_into_the_pipeline_follows_the_table() {
		wp_set_current_user( $this->area_id );
		$nuevo = function ( $status, $tipo_id ) {
			return wp_insert_post(
				array(
					'post_type' => 'documentate_document',
					'post_title' => 'Creado directamente',
					'post_status' => $status,
					'tax_input' => array( 'documentate_doc_type' => array( $tipo_id ) ),
				)
			);
		};

		// Con gestión: pending would skip gestión; en_gestion is the legitimate first step.
		$saltado = $nuevo( 'pending', $this->tipo_gestion );
		$this->assertSame( 'draft', get_post_status( $saltado ) );
		$notice = get_transient( 'documentate_workflow_notice_' . $this->area_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );
		$this->assertSame( 'en_gestion', get_post_status( $nuevo( 'en_gestion', $this->tipo_gestion ) ) );

		// Directo: pending is its first step; en_gestion is not.
		$this->assertSame( 'pending', get_post_status( $nuevo( 'pending', $this->tipo_directo ) ) );
		$this->assertSame( 'draft', get_post_status( $nuevo( 'en_gestion', $this->tipo_directo ) ) );

		// A publish request on creation lands on the next step of the posted type (Rule 2).
		$this->assertSame( 'en_gestion', get_post_status( $nuevo( 'publish', $this->tipo_gestion ) ) );
		$this->assertSame( 'pending', get_post_status( $nuevo( 'publish', $this->tipo_directo ) ) );

		// The wp-admin path: the auto-draft has no type yet, the first save posts it.
		$stub = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Auto-draft',
				'post_status' => 'auto-draft',
			)
		);
		wp_update_post(
			array(
				'ID' => $stub,
				'post_status' => 'pending',
				'tax_input' => array( 'documentate_doc_type' => array( $this->tipo_gestion ) ),
			)
		);
		$this->assertSame( 'draft', get_post_status( $stub ), 'Refused: a refused first save lands in draft, never auto-draft.' );
		$this->assertTrue( Documentate_Documento::con_gestion( $stub ), 'The posted type was assigned anyway.' );
	}

	/**
	 * A heartbeat autosave cannot move a draft along: Rule 0 runs during autosaves.
	 *
	 * wp_autosave() defines DOING_AUTOSAVE and, for the author's own draft,
	 * calls edit_post() with the client-chosen post_status.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_autosave_cannot_change_the_status() {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$doc = $this->crear_documento( 'draft' );
		wp_set_current_user( $this->area_id );

		foreach ( array( 'pending', 'publish', 'en_gestion' ) as $status ) {
			$resultado = wp_autosave(
				array(
					'post_id' => $doc,
					'_wpnonce' => wp_create_nonce( 'update-post_' . $doc ),
					'post_type' => 'documentate_document',
					'post_status' => $status,
					'post_title' => 'Autoguardado ' . $status,
					'content' => '',
				)
			);

			$this->assertSame( $doc, $resultado, $status );
			$this->assertSame( 'draft', get_post_status( $doc ), $status );
			$this->assertSame( 'Autoguardado ' . $status, get_post_field( 'post_title', $doc ), 'The autosave itself went through.' );
		}

		$this->assertTrue( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
		$this->assertSame( array(), Documentate_Actividad::listar( $doc ), 'No transition, no event.' );
	}

	/**
	 * Rule 2: a non-admin asking to publish a draft lands on the next step.
	 */
	public function test_non_admin_publish_request_lands_on_next_step() {
		$con_gestion = $this->crear_documento( 'draft' );
		$this->assertSame( 'en_gestion', $this->guardar_como( $con_gestion, $this->area_id, 'publish' ) );

		$directo = $this->crear_documento( 'draft', $this->tipo_directo );
		$this->assertSame( 'pending', $this->guardar_como( $directo, $this->area_id, 'publish' ) );

		// From en_gestion or pending the stored status is kept.
		$en_gestion = $this->crear_documento( 'en_gestion' );
		$this->assertSame( 'en_gestion', $this->guardar_como( $en_gestion, $this->gestion_id, 'publish' ) );

		$pending = $this->crear_documento( 'pending' );
		$this->assertSame( 'pending', $this->guardar_como( $pending, $this->gestion_id, 'private' ) );
	}

	/**
	 * Rule 0: gestión cannot return a document to draft without a reason.
	 */
	public function test_gestion_return_requires_a_reason() {
		$doc = $this->crear_documento( 'en_gestion' );

		$this->assertSame( 'en_gestion', $this->guardar_como( $doc, $this->gestion_id, 'draft' ) );
		$this->assertNull( Documentate_Documento::devuelto( $doc ) );

		$this->assertSame( 'draft', $this->guardar_como( $doc, $this->gestion_id, 'draft', 'Falta el anexo firmado' ) );
		$devuelto = Documentate_Documento::devuelto( $doc );
		$this->assertSame( 'Falta el anexo firmado', $devuelto['motivo'] );
		$this->assertSame( 'gestion', $devuelto['desde'] );
		$this->assertSame( $this->gestion_id, $devuelto['por'] );
	}

	/**
	 * Rule 0: gestión passes a document to administración, but cannot publish it.
	 */
	public function test_gestion_moves_to_pending_but_never_publishes() {
		$doc = $this->crear_documento( 'en_gestion' );
		$this->assertSame( 'pending', $this->guardar_como( $doc, $this->gestion_id, 'pending' ) );

		// Once in pending, gestión cannot approve nor return it.
		$this->assertSame( 'pending', $this->guardar_como( $doc, $this->gestion_id, 'publish' ) );
		$this->assertSame( 'pending', $this->guardar_como( $doc, $this->gestion_id, 'draft', 'Motivo válido' ) );
	}

	/**
	 * Rule 0: an administrator publishing straight from en_gestion is reverted.
	 */
	public function test_admin_cannot_publish_from_en_gestion() {
		$doc = $this->crear_documento( 'en_gestion' );

		$this->assertSame( 'en_gestion', $this->guardar_como( $doc, $this->admin_id, 'publish' ) );
		$notice = get_transient( 'documentate_workflow_notice_' . $this->admin_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );

		// The table path works: pass to administración, then approve.
		$this->assertSame( 'pending', $this->guardar_como( $doc, $this->admin_id, 'pending' ) );
		$this->assertSame( 'publish', $this->guardar_como( $doc, $this->admin_id, 'publish' ) );
	}

	/**
	 * The role-aware lock per status and role.
	 */
	public function test_user_can_modify_status_matrix() {
		foreach ( array( 'draft', 'auto-draft', 'en_gestion', 'pending', 'publish', 'archived' ) as $status ) {
			$this->assertTrue( Documentate_Workflow::user_can_modify_status( $status, $this->admin_id ), $status );
		}

		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'draft', $this->gestion_id ) );
		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'auto-draft', $this->gestion_id ) );
		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'en_gestion', $this->gestion_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'pending', $this->gestion_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'publish', $this->gestion_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'archived', $this->gestion_id ) );

		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'draft', $this->area_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'en_gestion', $this->area_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'pending', $this->area_id ) );

		// current_user_can_modify_document() routes through it.
		$doc = $this->crear_documento( 'en_gestion' );
		wp_set_current_user( $this->area_id );
		$this->assertFalse( Documentate_Workflow::current_user_can_modify_document( $doc ) );
		wp_set_current_user( $this->gestion_id );
		$this->assertTrue( Documentate_Workflow::current_user_can_modify_document( $doc ) );
	}

	/**
	 * Content is frozen for the área on en_gestion, editable for gestión, frozen for gestión on pending.
	 */
	public function test_freeze_follows_the_role_aware_lock() {
		$doc = $this->crear_documento( 'en_gestion' );

		wp_set_current_user( $this->area_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Cambiado por el área',
			)
		);
		$this->assertSame( 'Título original', get_post_field( 'post_title', $doc ) );
		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$notice = get_transient( 'documentate_workflow_notice_' . $this->area_id );
		$this->assertSame( 'gestion_locked', $notice['reason'] );

		wp_set_current_user( $this->gestion_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Cambiado por gestión',
			)
		);
		$this->assertSame( 'Cambiado por gestión', get_post_field( 'post_title', $doc ) );

		$pending = $this->crear_documento( 'pending' );
		wp_set_current_user( $this->gestion_id );
		wp_update_post(
			array(
				'ID' => $pending,
				'post_title' => 'Cambiado en revisión',
			)
		);
		$this->assertSame( 'Título original', get_post_field( 'post_title', $pending ) );
	}

	/**
	 * The metabox in en_gestion offers gestión its buttons and locks the área.
	 */
	public function test_metabox_en_gestion_buttons_per_role() {
		$doc = $this->crear_documento( 'en_gestion' );

		$gestion = $this->render_metabox( $doc, $this->gestion_id );
		$this->assertStringContainsString( 'id="documentate-save-gestion"', $gestion );
		$this->assertStringContainsString( 'id="documentate-pass-admin"', $gestion );
		$this->assertStringContainsString( 'Pasar a administración', $gestion );
		$this->assertStringContainsString( 'id="documentate-return-draft"', $gestion );
		$this->assertStringContainsString( 'Devolver al área', $gestion );
		$this->assertStringContainsString( 'id="documentate-return-draft-motivo"', $gestion );
		$this->assertStringContainsString( 'Completa los datos oficiales', $gestion );
		$this->assertStringContainsString( 'is-current is-status-en_gestion', $gestion );
		$this->assertStringContainsString( 'Mover a la papelera', $gestion );

		$area = $this->render_metabox( $doc, $this->area_id );
		$this->assertStringContainsString( 'documentate-mgmt-locked-notice', $area );
		$this->assertStringContainsString( 'Ya no puedes modificarlo', $area );
		$this->assertStringNotContainsString( 'documentate-pass-admin', $area );
		$this->assertStringNotContainsString( 'documentate-return-draft', $area );
		$this->assertStringNotContainsString( 'Mover a la papelera', $area );
	}

	/**
	 * The stepper and the send button follow the document type.
	 */
	public function test_metabox_draft_follows_the_type() {
		$con_gestion = $this->crear_documento( 'draft' );
		$html = $this->render_metabox( $con_gestion, $this->area_id );
		$this->assertStringContainsString( 'En gestión', $html );
		$this->assertStringContainsString( 'Enviar a gestión', $html );
		$this->assertStringContainsString( 'data-estado="en_gestion"', $html );
		$this->assertStringContainsString( 'Envía a gestión documental', $html );

		$directo = $this->crear_documento( 'draft', $this->tipo_directo );
		$html = $this->render_metabox( $directo, $this->area_id );
		$this->assertStringNotContainsString( 'En gestión', $html );
		$this->assertStringContainsString( 'Enviar a revisión', $html );
		$this->assertStringContainsString( 'data-estado="pending"', $html );
	}

	/**
	 * Administrators reviewing a con_gestion document can return it to gestión.
	 */
	public function test_metabox_pending_admin_offers_return_to_gestion() {
		$doc = $this->crear_documento( 'pending' );

		$html = $this->render_metabox( $doc, $this->admin_id );
		$this->assertStringContainsString( 'id="documentate-return-gestion"', $html );
		$this->assertStringContainsString( 'Devolver a gestión', $html );
		$this->assertStringContainsString( 'id="documentate-return-draft"', $html );
		$this->assertStringContainsString( 'id="documentate-approve-publish"', $html );
		$this->assertStringContainsString( 'Apruébalo o devuélvelo', $html );

		// Gestión is locked on pending.
		$html = $this->render_metabox( $doc, $this->gestion_id );
		$this->assertStringContainsString( 'documentate-mgmt-locked-notice', $html );
		$this->assertStringNotContainsString( 'documentate-return-gestion', $html );
	}

	/**
	 * Revision restores follow the same lock.
	 */
	public function test_restrict_revision_restore_follows_the_lock() {
		$doc = $this->crear_documento( 'en_gestion' );

		wp_set_current_user( $this->gestion_id );
		$this->assertNull( $this->workflow->restrict_revision_restore( $doc, 0 ), 'Gestión may restore on en_gestion.' );
		$this->assertNull( $this->workflow->restrict_revision_restore( self::factory()->post->create(), 0 ), 'Other post types are ignored.' );

		wp_set_current_user( $this->area_id );
		$this->expectException( 'WPDieException' );
		$this->workflow->restrict_revision_restore( $doc, 0 );
	}

	/**
	 * Whoever is locked out of a document cannot trash it: delete_post is denied.
	 */
	public function test_locked_documents_cannot_be_trashed() {
		$en_gestion = $this->crear_documento( 'en_gestion' );
		$pending = $this->crear_documento( 'pending' );
		$borrador = $this->crear_documento( 'draft' );

		wp_set_current_user( $this->area_id );
		$this->assertFalse( current_user_can( 'delete_post', $en_gestion ) );
		$this->assertEmpty( get_delete_post_link( $en_gestion ), 'No row action, no trash URL.' );
		$this->assertFalse( current_user_can( 'delete_post', $pending ) );
		$this->assertTrue( current_user_can( 'delete_post', $borrador ) );

		wp_set_current_user( $this->gestion_id );
		$this->assertTrue( current_user_can( 'delete_post', $en_gestion ) );
		$this->assertFalse( current_user_can( 'delete_post', $pending ) );
		$this->assertTrue( current_user_can( 'delete_post', $borrador ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'delete_post', $pending ) );
		$this->assertNotSame( '', get_delete_post_link( $pending ) );
	}

	/**
	 * Gestión cannot restore revisions once the document is in pending.
	 */
	public function test_gestion_cannot_restore_revisions_on_pending() {
		$pending = $this->crear_documento( 'pending' );
		wp_set_current_user( $this->gestion_id );

		$this->expectException( 'WPDieException' );
		$this->workflow->restrict_revision_restore( $pending, 0 );
	}

	/**
	 * The script config carries the gestión flags.
	 */
	public function test_enqueue_localizes_gestion_flags() {
		$doc = $this->crear_documento( 'en_gestion' );
		wp_set_current_user( $this->area_id );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;
		$GLOBALS['post'] = get_post( $doc );

		$this->workflow->enqueue_workflow_assets( 'post.php' );
		$data = wp_scripts()->get_data( 'documentate-workflow', 'data' );

		// wp_localize_script() casts scalars to strings: "1" / "".
		$this->assertStringContainsString( '"isEnGestion":"1"', $data );
		$this->assertStringContainsString( '"conGestion":"1"', $data );
		$this->assertStringContainsString( '"isGestion":""', $data );
		$this->assertStringContainsString( '"isLocked":"1"', $data );
		$this->assertStringContainsString( 'gestionMessage', $data );
		$this->assertStringContainsString( 'motivoRequired', $data );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The new notice reasons render.
	 */
	public function test_new_notice_reasons_render() {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'documentate_document' );
		get_current_screen()->post_type = 'documentate_document';

		foreach ( array( 'gestion_locked' => 'gestión', 'transicion_no_permitida' => 'transición' ) as $reason => $texto ) {
			set_transient(
				'documentate_workflow_notice_' . $this->admin_id,
				array(
					'reason' => $reason,
					'original_status' => 'draft',
					'post_id' => 1,
				),
				30
			);

			ob_start();
			$this->workflow->display_workflow_notices();
			$output = ob_get_clean();

			$this->assertStringContainsString( 'notice-error', $output, $reason );
			$this->assertStringContainsString( $texto, $output, $reason );
		}

		set_current_screen( 'front' );
	}

	/**
	 * register_archived_status() keeps working as an alias.
	 */
	public function test_status_registration_alias() {
		$this->workflow->register_archived_status();

		$this->assertNotNull( get_post_status_object( 'en_gestion' ) );
		$this->assertNotNull( get_post_status_object( 'archived' ) );
		$this->assertSame( 5, has_action( 'init', array( $this->workflow, 'register_custom_statuses' ) ) );
	}
}
