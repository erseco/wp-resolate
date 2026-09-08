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
	private $management_id;

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
	private $management_type;

	/**
	 * Document type that goes straight to administración.
	 *
	 * @var int
	 */
	private $direct_type;

	/**
	 * Set up users, scope, types and the custom statuses.
	 */
	public function set_up(): void {
		parent::set_up();

		Documentate_Statuses::register();
		Documentate_Roles::ensure_caps( true );
		$this->workflow = new Documentate_Workflow();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$cat = wp_insert_term( 'Ámbito Workflow', 'category' );
		$this->cat_id = (int) $cat['term_id'];
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_id );

		$management = wp_insert_term( 'Resolución W', 'documentate_doc_type' );
		$this->management_type_id = (int) $management['term_id'];
		update_term_meta( $this->management_type_id, 'documentate_type_con_gestion', '1' );

		$direct = wp_insert_term( 'Convocatoria W', 'documentate_doc_type' );
		$this->direct_type_id = (int) $direct['term_id'];
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
	 * @param int    $type_id Document type term ID (defaults to the gestión type).
	 * @return int
	 */
	private function create_document( $status, $type_id = 0 ) {
		$type_id = $type_id > 0 ? $type_id : $this->management_type_id;

		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Título original',
				'post_content' => 'Contenido original',
				'post_status' => $status,
				'post_author' => $this->area_id,
				'tax_input' => array( 'documentate_doc_type' => array( $type_id ) ),
			)
		);
		update_post_meta( $post_id, 'documentate_locked_doc_type', $type_id );
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
	 * @param string $reason  Optional reason (posted with the metabox nonce).
	 * @return string Resulting status.
	 */
	private function save_as( $doc_id, $user_id, $status, $reason = '' ) {
		wp_set_current_user( $user_id );
		if ( '' !== $reason ) {
			$_POST['documentate_motivo'] = $reason;
			$_POST[ Documentate_Transitions::NONCE ] = wp_create_nonce( Documentate_Transitions::NONCE );
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
	public function test_area_cannot_post_pending_on_a_management_draft() {
		$doc = $this->create_document( 'draft' );

		$this->assertSame( 'draft', $this->save_as( $doc, $this->area_id, 'pending' ) );

		$notice = get_transient( 'documentate_workflow_notice_' . $this->area_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );

		// The same request on a direct type goes through.
		$direct = $this->create_document( 'draft', $this->direct_type_id );
		$this->assertSame( 'pending', $this->save_as( $direct, $this->area_id, 'pending' ) );
	}

	/**
	 * Rule 0: a document is born as a draft, so creating one straight into the
	 * pipeline follows the table with the type posted along the save.
	 */
	public function test_creation_straight_into_the_pipeline_follows_the_table() {
		wp_set_current_user( $this->area_id );
		$create = function ( $status, $type_id ) {
			return wp_insert_post(
				array(
					'post_type' => 'documentate_document',
					'post_title' => 'Creado directamente',
					'post_status' => $status,
					'tax_input' => array( 'documentate_doc_type' => array( $type_id ) ),
				)
			);
		};

		// Con gestión: pending would skip gestión; en_gestion is the legitimate first step.
		$skipped = $create( 'pending', $this->management_type_id );
		$this->assertSame( 'draft', get_post_status( $skipped ) );
		$notice = get_transient( 'documentate_workflow_notice_' . $this->area_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );
		$this->assertSame( 'en_gestion', get_post_status( $create( 'en_gestion', $this->management_type_id ) ) );

		// Directo: pending is its first step; en_gestion is not.
		$this->assertSame( 'pending', get_post_status( $create( 'pending', $this->direct_type_id ) ) );
		$this->assertSame( 'draft', get_post_status( $create( 'en_gestion', $this->direct_type_id ) ) );

		// A publish request on creation lands on the next step of the posted type (Rule 2).
		$this->assertSame( 'en_gestion', get_post_status( $create( 'publish', $this->management_type_id ) ) );
		$this->assertSame( 'pending', get_post_status( $create( 'publish', $this->direct_type_id ) ) );

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
				'tax_input' => array( 'documentate_doc_type' => array( $this->management_type_id ) ),
			)
		);
		$this->assertSame( 'draft', get_post_status( $stub ), 'Refused: a refused first save lands in draft, never auto-draft.' );
		$this->assertTrue( Documentate_Document_Data::has_management( $stub ), 'The posted type was assigned anyway.' );
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
		$doc = $this->create_document( 'draft' );
		wp_set_current_user( $this->area_id );

		foreach ( array( 'pending', 'publish', 'en_gestion' ) as $status ) {
			$result = wp_autosave(
				array(
					'post_id' => $doc,
					'_wpnonce' => wp_create_nonce( 'update-post_' . $doc ),
					'post_type' => 'documentate_document',
					'post_status' => $status,
					'post_title' => 'Autoguardado ' . $status,
					'content' => '',
				)
			);

			$this->assertSame( $doc, $result, $status );
			$this->assertSame( 'draft', get_post_status( $doc ), $status );
			$this->assertSame( 'Autoguardado ' . $status, get_post_field( 'post_title', $doc ), 'The autosave itself went through.' );
		}

		$this->assertTrue( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE );
		$this->assertSame( array(), Documentate_Activity::entries( $doc ), 'No transition, no event.' );
	}

	/**
	 * Rule 2: a non-admin asking to publish a draft lands on the next step.
	 */
	public function test_non_admin_publish_request_lands_on_next_step() {
		$has_management = $this->create_document( 'draft' );
		$this->assertSame( 'en_gestion', $this->save_as( $has_management, $this->area_id, 'publish' ) );

		$direct = $this->create_document( 'draft', $this->direct_type_id );
		$this->assertSame( 'pending', $this->save_as( $direct, $this->area_id, 'publish' ) );

		// From en_gestion or pending the stored status is kept.
		$en_gestion = $this->create_document( 'en_gestion' );
		$this->assertSame( 'en_gestion', $this->save_as( $en_gestion, $this->management_id, 'publish' ) );

		$pending = $this->create_document( 'pending' );
		$this->assertSame( 'pending', $this->save_as( $pending, $this->management_id, 'private' ) );
	}

	/**
	 * Rule 0: gestión cannot return a document to draft without a reason.
	 */
	public function test_management_return_requires_a_reason() {
		$doc = $this->create_document( 'en_gestion' );

		$this->assertSame( 'en_gestion', $this->save_as( $doc, $this->management_id, 'draft' ) );
		$this->assertNull( Documentate_Document_Data::returned( $doc ) );

		$this->assertSame( 'draft', $this->save_as( $doc, $this->management_id, 'draft', 'Falta el anexo firmado' ) );
		$returned = Documentate_Document_Data::returned( $doc );
		$this->assertSame( 'Falta el anexo firmado', $returned['motivo'] );
		$this->assertSame( 'gestion', $returned['desde'] );
		$this->assertSame( $this->management_id, $returned['por'] );
	}

	/**
	 * Rule 0: gestión passes a document to administración, but cannot publish it.
	 */
	public function test_management_moves_to_pending_but_never_publishes() {
		$doc = $this->create_document( 'en_gestion' );
		$this->assertSame( 'pending', $this->save_as( $doc, $this->management_id, 'pending' ) );

		// Once in pending, gestión cannot approve nor return it.
		$this->assertSame( 'pending', $this->save_as( $doc, $this->management_id, 'publish' ) );
		$this->assertSame( 'pending', $this->save_as( $doc, $this->management_id, 'draft', 'Motivo válido' ) );
	}

	/**
	 * Rule 0: an administrator publishing straight from en_gestion is reverted.
	 */
	public function test_admin_cannot_publish_from_en_gestion() {
		$doc = $this->create_document( 'en_gestion' );

		$this->assertSame( 'en_gestion', $this->save_as( $doc, $this->admin_id, 'publish' ) );
		$notice = get_transient( 'documentate_workflow_notice_' . $this->admin_id );
		$this->assertSame( 'transicion_no_permitida', $notice['reason'] );

		// The table path works: pass to administración, then approve.
		$this->assertSame( 'pending', $this->save_as( $doc, $this->admin_id, 'pending' ) );
		$this->assertSame( 'publish', $this->save_as( $doc, $this->admin_id, 'publish' ) );
	}

	/**
	 * The role-aware lock per status and role.
	 */
	public function test_user_can_modify_status_matrix() {
		foreach ( array( 'draft', 'auto-draft', 'en_gestion', 'pending', 'publish', 'archived' ) as $status ) {
			$this->assertTrue( Documentate_Workflow::user_can_modify_status( $status, $this->admin_id ), $status );
		}

		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'draft', $this->management_id ) );
		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'auto-draft', $this->management_id ) );
		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'en_gestion', $this->management_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'pending', $this->management_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'publish', $this->management_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'archived', $this->management_id ) );

		$this->assertTrue( Documentate_Workflow::user_can_modify_status( 'draft', $this->area_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'en_gestion', $this->area_id ) );
		$this->assertFalse( Documentate_Workflow::user_can_modify_status( 'pending', $this->area_id ) );

		// current_user_can_modify_document() routes through it.
		$doc = $this->create_document( 'en_gestion' );
		wp_set_current_user( $this->area_id );
		$this->assertFalse( Documentate_Workflow::current_user_can_modify_document( $doc ) );
		wp_set_current_user( $this->management_id );
		$this->assertTrue( Documentate_Workflow::current_user_can_modify_document( $doc ) );
	}

	/**
	 * Content is frozen for the área on en_gestion, editable for gestión, frozen for gestión on pending.
	 */
	public function test_freeze_follows_the_role_aware_lock() {
		$doc = $this->create_document( 'en_gestion' );

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

		wp_set_current_user( $this->management_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Cambiado por gestión',
			)
		);
		$this->assertSame( 'Cambiado por gestión', get_post_field( 'post_title', $doc ) );

		$pending = $this->create_document( 'pending' );
		wp_set_current_user( $this->management_id );
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
		$doc = $this->create_document( 'en_gestion' );

		$management = $this->render_metabox( $doc, $this->management_id );
		$this->assertStringContainsString( 'id="documentate-save-gestion"', $management );
		$this->assertStringContainsString( 'id="documentate-pass-admin"', $management );
		$this->assertStringContainsString( 'Pasar a administración', $management );
		$this->assertStringContainsString( 'id="documentate-return-draft"', $management );
		$this->assertStringContainsString( 'Devolver al área', $management );
		$this->assertStringContainsString( 'id="documentate-return-draft-motivo"', $management );
		$this->assertStringContainsString( 'Completa los datos oficiales', $management );
		$this->assertStringContainsString( 'is-current is-status-en_gestion', $management );
		$this->assertStringContainsString( 'Mover a la papelera', $management );

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
		$has_management = $this->create_document( 'draft' );
		$html = $this->render_metabox( $has_management, $this->area_id );
		$this->assertStringContainsString( 'En gestión', $html );
		$this->assertStringContainsString( 'Enviar a gestión', $html );
		$this->assertStringContainsString( 'data-estado="en_gestion"', $html );
		$this->assertStringContainsString( 'Envía a gestión documental', $html );

		$direct = $this->create_document( 'draft', $this->direct_type_id );
		$html = $this->render_metabox( $direct, $this->area_id );
		$this->assertStringNotContainsString( 'En gestión', $html );
		$this->assertStringContainsString( 'Enviar a revisión', $html );
		$this->assertStringContainsString( 'data-estado="pending"', $html );
	}

	/**
	 * Administrators reviewing a management document can return it to gestión.
	 */
	public function test_metabox_pending_admin_offers_return_to_management() {
		$doc = $this->create_document( 'pending' );

		$html = $this->render_metabox( $doc, $this->admin_id );
		$this->assertStringContainsString( 'id="documentate-return-gestion"', $html );
		$this->assertStringContainsString( 'Devolver a gestión', $html );
		$this->assertStringContainsString( 'id="documentate-return-draft"', $html );
		$this->assertStringContainsString( 'id="documentate-approve-publish"', $html );
		$this->assertStringContainsString( 'Apruébalo o devuélvelo', $html );

		// Gestión is locked on pending.
		$html = $this->render_metabox( $doc, $this->management_id );
		$this->assertStringContainsString( 'documentate-mgmt-locked-notice', $html );
		$this->assertStringNotContainsString( 'documentate-return-gestion', $html );
	}

	/**
	 * Revision restores follow the same lock.
	 */
	public function test_restrict_revision_restore_follows_the_lock() {
		$doc = $this->create_document( 'en_gestion' );

		wp_set_current_user( $this->management_id );
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
		$en_gestion = $this->create_document( 'en_gestion' );
		$pending = $this->create_document( 'pending' );
		$draft = $this->create_document( 'draft' );

		wp_set_current_user( $this->area_id );
		$this->assertFalse( current_user_can( 'delete_post', $en_gestion ) );
		$this->assertEmpty( get_delete_post_link( $en_gestion ), 'No row action, no trash URL.' );
		$this->assertFalse( current_user_can( 'delete_post', $pending ) );
		$this->assertTrue( current_user_can( 'delete_post', $draft ) );

		wp_set_current_user( $this->management_id );
		$this->assertTrue( current_user_can( 'delete_post', $en_gestion ) );
		$this->assertFalse( current_user_can( 'delete_post', $pending ) );
		$this->assertTrue( current_user_can( 'delete_post', $draft ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'delete_post', $pending ) );
		$this->assertNotSame( '', get_delete_post_link( $pending ) );
	}

	/**
	 * Gestión cannot restore revisions once the document is in pending.
	 */
	public function test_management_cannot_restore_revisions_on_pending() {
		$pending = $this->create_document( 'pending' );
		wp_set_current_user( $this->management_id );

		$this->expectException( 'WPDieException' );
		$this->workflow->restrict_revision_restore( $pending, 0 );
	}

	/**
	 * The script config carries the gestión flags.
	 */
	public function test_enqueue_localizes_management_flags() {
		$doc = $this->create_document( 'en_gestion' );
		wp_set_current_user( $this->area_id );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;
		$GLOBALS['post'] = get_post( $doc );

		$this->workflow->enqueue_workflow_assets( 'post.php' );
		$data = wp_scripts()->get_data( 'documentate-workflow', 'data' );

		// wp_localize_script() casts scalars to strings: "1" / "".
		$this->assertStringContainsString( '"isEnGestion":"1"', $data );
		$this->assertStringContainsString( '"hasManagement":"1"', $data );
		$this->assertStringContainsString( '"isManagement":""', $data );
		$this->assertStringContainsString( '"isLocked":"1"', $data );
		$this->assertStringContainsString( 'managementMessage', $data );
		$this->assertStringContainsString( 'reasonRequired', $data );

		unset( $GLOBALS['post'] );
	}

	/**
	 * The new notice reasons render.
	 */
	public function test_new_notice_reasons_render() {
		wp_set_current_user( $this->admin_id );
		set_current_screen( 'documentate_document' );
		get_current_screen()->post_type = 'documentate_document';

		foreach ( array( 'gestion_locked' => 'gestión', 'transicion_no_permitida' => 'transición' ) as $reason => $text ) {
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
			$this->assertStringContainsString( $text, $output, $reason );
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
