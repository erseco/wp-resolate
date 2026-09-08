<?php
/**
 * Tests for Documentate_Transitions.
 *
 * Walks the rule table with every role and both kinds of document type,
 * checking what is offered, what is allowed and what apply() writes.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Transitions
 */
class DocumentateTransitionsTest extends WP_UnitTestCase {

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

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->management_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $this->management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$this->area_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$cat = wp_insert_term( 'Ámbito Transiciones', 'category' );
		$this->cat_id = (int) $cat['term_id'];
		update_user_meta( $this->area_id, 'documentate_scope_term_id', $this->cat_id );
		update_user_meta( $this->management_id, 'documentate_scope_term_id', $this->cat_id );

		$management = wp_insert_term( 'Resolución T', 'documentate_doc_type' );
		$this->management_type_id = (int) $management['term_id'];
		update_term_meta( $this->management_type_id, 'documentate_type_con_gestion', '1' );

		$direct = wp_insert_term( 'Convocatoria T', 'documentate_doc_type' );
		$this->direct_type_id = (int) $direct['term_id'];
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
	 * @param int    $type_id Document type term ID.
	 * @return int
	 */
	private function create_document( $status, $type_id ) {
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Documento de prueba',
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
	 * User ID for a role name.
	 *
	 * @param string $role area, gestion or admin.
	 * @return int
	 */
	private function user_for( $role ) {
		$ids = array(
			'area' => $this->area_id,
			'gestion' => $this->management_id,
			'admin' => $this->admin_id,
		);

		return $ids[ $role ];
	}

	/**
	 * Every rule × role × has_management, with whether it must be allowed.
	 *
	 * @return array<string,array>
	 */
	public function provider_rules() {
		$ranges = array(
			'area' => 0,
			'gestion' => 1,
			'admin' => 2,
		);
		$cases = array();

		foreach ( Documentate_Transitions::rules() as $rule ) {
			foreach ( array( 'area', 'gestion', 'admin' ) as $role ) {
				foreach ( array( true, false ) as $has_management ) {
					$role_ok = $ranges[ $role ] >= $ranges[ $rule['who'] ];
					$type_ok = null === $rule['has_management'] || $rule['has_management'] === $has_management;
					$name = sprintf( '%s %s→%s · %s · %s', $rule['key'], $rule['from'], $rule['target'], $role, $has_management ? 'via management' : 'direct' );
					$cases[ $name ] = array( $rule, $role, $has_management, $role_ok && $type_ok );
				}
			}
		}

		return $cases;
	}

	/**
	 * available(), allowed() and apply() agree on every rule, role and type.
	 *
	 * @dataProvider provider_rules
	 *
	 * @param array  $rule       Rule row.
	 * @param string $role         Role name.
	 * @param bool   $has_management Whether the type goes through gestión.
	 * @param bool   $expected    Whether the transition must be allowed.
	 */
	public function test_rule_table( array $rule, $role, $has_management, $expected ) {
		$doc = $this->create_document( $rule['from'], $has_management ? $this->management_type_id : $this->direct_type_id );
		$user_id = $this->user_for( $role );
		$reason = 'Falta el anexo firmado';

		// A previous return mark must be cleared by forward transitions.
		Documentate_Document_Data::mark_returned( $doc, 'antes', 'gestion', 'area', $this->management_id );

		wp_set_current_user( $user_id );
		$available = Documentate_Transitions::available( get_post( $doc ) );
		$offered = isset( $available[ $rule['key'] ] ) && $available[ $rule['key'] ]['target'] === $rule['target'];
		$this->assertSame( $expected, $offered, 'available()' );
		$this->assertSame( $expected, Documentate_Transitions::allowed( $doc, $rule['from'], $rule['target'], $user_id, $reason ), 'allowed()' );

		$result = Documentate_Transitions::apply( $doc, $rule['key'], $reason );

		if ( ! $expected ) {
			$this->assertWPError( $result );
			$this->assertSame( 'transicion_no_disponible', $result->get_error_code() );
			$this->assertSame( $rule['from'], get_post_status( $doc ) );
			$this->assertSame( array(), Documentate_Activity::entries( $doc ), 'No event on a refused transition.' );
			return;
		}

		$this->assertTrue( $result );
		$this->assertSame( $rule['target'], get_post_status( $doc ) );

		$events = Documentate_Activity::entries( $doc );
		$this->assertCount( 1, $events );
		$this->assertSame( 'evento', $events[0]['type'] );

		$returned = Documentate_Document_Data::returned( $doc );
		if ( $rule['reason'] ) {
			$this->assertSame( $rule['event'] . ': «' . $reason . '»', $events[0]['text'] );
			$this->assertSame( $reason, $events[0]['reason'] );
			$this->assertNotNull( $returned );
			$this->assertSame( $reason, $returned['motivo'] );
			$this->assertSame( $user_id, $returned['por'] );
			$this->assertSame( 'pending' === $rule['from'] ? 'administracion' : 'gestion', $returned['desde'] );
			$this->assertSame( 'en_gestion' === $rule['target'] ? 'gestion' : 'area', $returned['a'] );
		} else {
			$this->assertSame( $rule['event'], $events[0]['text'] );
			$this->assertSame( '', $events[0]['reason'] );
			$this->assertNull( $returned, 'Forward transitions clear the return mark.' );
		}
	}

	/**
	 * A document with no document type is offered nothing that would bounce back.
	 *
	 * Documentate_Workflow forces such a document back to draft, so a button
	 * for it could only ever fail with "esa acción no está disponible", which
	 * is not the reason and leaves the person nothing to act on.
	 */
	public function test_a_document_without_a_type_is_offered_no_way_forward() {
		wp_set_current_user( $this->admin_id );
		$doc = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Guardado en wp-admin sin tipo',
				'post_status' => 'draft',
				'post_author' => $this->area_id,
			)
		);
		wp_set_object_terms( $doc, array( $this->cat_id ), 'category' );

		wp_set_current_user( $this->area_id );
		$this->assertSame( array(), Documentate_Transitions::available( get_post( $doc ) ) );

		$result = Documentate_Transitions::apply( $doc, 'enviar_revision' );
		$this->assertWPError( $result );
		$this->assertSame( 'transicion_no_disponible', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $doc ) );
		$this->assertSame( array(), Documentate_Activity::entries( $doc ) );

		// With a type it is offered again.
		wp_set_object_terms( $doc, array( $this->direct_type_id ), 'documentate_doc_type' );
		$this->assertArrayHasKey( 'enviar_revision', Documentate_Transitions::available( get_post( $doc ) ) );
	}

	/**
	 * Un-approving a published document is a rule like any other: audited.
	 */
	public function test_un_approving_a_published_document_is_recorded() {
		$doc = $this->create_document( 'publish', $this->management_type_id );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Transitions::apply( $doc, 'devolver_revision' ) );

		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertSame(
			array( 'devolvió el documento a revisión' ),
			wp_list_pluck( Documentate_Activity::entries( $doc ), 'text' )
		);
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'publish', 'pending', $this->management_id ) );
	}

	/**
	 * A return without a reason (or a too short one) is refused everywhere.
	 */
	public function test_returns_require_a_reason() {
		$doc = $this->create_document( 'pending', $this->management_type_id );
		wp_set_current_user( $this->admin_id );

		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'pending', 'draft', $this->admin_id ) );
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'pending', 'draft', $this->admin_id, ' ab ' ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'pending', 'draft', $this->admin_id, 'abc' ) );

		$without = Documentate_Transitions::apply( $doc, 'devolver_area' );
		$this->assertWPError( $without );
		$this->assertSame( 'motivo_requerido', $without->get_error_code() );

		$short = Documentate_Transitions::apply( $doc, 'devolver_gestion', 'no' );
		$this->assertWPError( $short );
		$this->assertSame( 'motivo_requerido', $short->get_error_code() );

		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertNull( Documentate_Document_Data::returned( $doc ) );
		$this->assertSame( array(), Documentate_Activity::entries( $doc ) );
	}

	/**
	 * The edge cases allowed() always lets through or always blocks.
	 */
	public function test_allowed_edge_cases() {
		$doc = $this->create_document( 'en_gestion', $this->management_type_id );

		// Same status, creation as a draft and trash are always allowed.
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'en_gestion', 'en_gestion', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'auto-draft', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'new', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, '', 'draft', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'draft', 'trash', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'trash', 'draft', $this->area_id ) );

		// A document is born as a draft: creation straight into the pipeline follows the table.
		$this->assertFalse( Documentate_Transitions::allowed( $doc, '', 'pending', $this->area_id ), 'Con gestión: pending skips gestión.' );
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'auto-draft', 'pending', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'auto-draft', 'en_gestion', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'new', 'publish', $this->area_id ), 'Left to the role rule.' );
		$direct = $this->create_document( 'draft', $this->direct_type_id );
		$this->assertTrue( Documentate_Transitions::allowed( $direct, 'auto-draft', 'pending', $this->area_id ) );
		$this->assertFalse( Documentate_Transitions::allowed( $direct, '', 'en_gestion', $this->area_id ) );
		$this->assertFalse( Documentate_Transitions::allowed( $direct, 'new', 'en_gestion', $this->management_id ) );

		// Administración creates documents in any status (seeders, imports, fixes).
		$this->assertTrue( Documentate_Transitions::allowed( $doc, '', 'pending', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $direct, 'new', 'en_gestion', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( 0, 'auto-draft', 'archived', $this->admin_id, '', false ) );

		// The caller may say whether the type goes through gestión (type posted with a first save).
		$this->assertFalse( Documentate_Transitions::allowed( 0, '', 'pending', $this->area_id, '', true ) );
		$this->assertTrue( Documentate_Transitions::allowed( 0, '', 'en_gestion', $this->area_id, '', true ) );
		$this->assertTrue( Documentate_Transitions::allowed( 0, '', 'pending', $this->area_id, '', false ) );
		$this->assertFalse( Documentate_Transitions::allowed( 0, '', 'en_gestion', $this->area_id, '', false ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'draft', 'pending', $this->area_id, '', false ), 'The hint wins over the stored type.' );

		// Administrators move between publish and archived and keep their legacy freedom.
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'publish', 'archived', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'archived', 'publish', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'archived', 'draft', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'publish', 'draft', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'publish', 'pending', $this->admin_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'draft', 'publish', $this->admin_id ) );

		// ...except when leaving en_gestion outside the table.
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'en_gestion', 'publish', $this->admin_id ) );

		// Publish-like requests from draft are left to the workflow role rule.
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'draft', 'publish', $this->area_id ) );
		$this->assertTrue( Documentate_Transitions::allowed( $doc, 'draft', 'private', $this->management_id ) );

		// Non-administrators cannot leave locked statuses outside the table.
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'publish', 'draft', $this->area_id ) );
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'archived', 'publish', $this->management_id ) );
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'en_gestion', 'publish', $this->management_id ) );
	}

	/**
	 * While apply() runs, the workflow lets its own status change through.
	 */
	public function test_in_progress_bypass_and_reason_in_progress() {
		$doc = $this->create_document( 'pending', $this->management_type_id );
		$seen = array();

		$spy = function ( $data ) use ( $doc, &$seen ) {
			$seen['allowed'] = Documentate_Transitions::allowed( $doc, 'pending', 'draft', $this->area_id );
			$seen['reason'] = Documentate_Transitions::reason_in_progress( $doc );
			$seen['other'] = Documentate_Transitions::reason_in_progress( $doc + 1 );
			return $data;
		};
		add_filter( 'wp_insert_post_data', $spy, 9 );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Transitions::apply( $doc, 'devolver_area', 'Falta el expediente' ) );
		remove_filter( 'wp_insert_post_data', $spy, 9 );

		$this->assertTrue( $seen['allowed'], 'The área user could not do it, but the transition in progress passes.' );
		$this->assertSame( 'Falta el expediente', $seen['reason'] );
		$this->assertSame( '', $seen['other'] );

		// Cleared afterwards.
		$this->assertSame( '', Documentate_Transitions::reason_in_progress( $doc ) );
		$this->assertFalse( Documentate_Transitions::allowed( $doc, 'pending', 'draft', $this->area_id ) );
	}

	/**
	 * apply() refuses unknown documents and transitions the workflow does not land.
	 */
	public function test_apply_failures_leave_no_trace() {
		wp_set_current_user( $this->admin_id );

		$invalid = Documentate_Transitions::apply( 999999, 'aprobar' );
		$this->assertWPError( $invalid );
		$this->assertSame( 'documento_invalido', $invalid->get_error_code() );

		// A transition the save does not land (the workflow, or anything else
		// hooked on the save, keeps the document where it was).
		$doc = $this->create_document( 'draft', $this->direct_type_id );
		Documentate_Document_Data::mark_returned( $doc, 'antes', 'gestion', 'area', $this->management_id );
		wp_set_current_user( $this->admin_id );

		$freeze = static function ( $data ) {
			$data['post_status'] = 'draft';

			return $data;
		};
		add_filter( 'wp_insert_post_data', $freeze, 999 );
		$result = Documentate_Transitions::apply( $doc, 'enviar_revision' );
		remove_filter( 'wp_insert_post_data', $freeze, 999 );

		$this->assertWPError( $result );
		$this->assertSame( 'transicion_no_aplicada', $result->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $doc ) );
		$this->assertSame( array(), Documentate_Activity::entries( $doc ), 'The event is rolled back.' );
		$this->assertSame( 'antes', Documentate_Document_Data::returned( $doc )['motivo'], 'The mark is restored.' );
	}

	/**
	 * Where the application lands after each action, and the flag it shows.
	 */
	public function test_redirect_and_flag() {
		$this->assertSame( 'detalle', Documentate_Transitions::redirect( 'enviar_gestion' ) );
		$this->assertSame( 'enviado', Documentate_Transitions::flag( 'enviar_gestion' ) );
		$this->assertSame( 'detalle', Documentate_Transitions::redirect( 'pasar_admin' ) );
		$this->assertSame( 'enviado', Documentate_Transitions::flag( 'pasar_admin' ) );
		$this->assertSame( 'bandeja', Documentate_Transitions::redirect( 'devolver_area' ) );
		$this->assertSame( 'devuelto', Documentate_Transitions::flag( 'devolver_gestion' ) );
		$this->assertSame( 'detalle', Documentate_Transitions::redirect( 'aprobar' ) );
		$this->assertSame( 'aprobado', Documentate_Transitions::flag( 'aprobar' ) );
		$this->assertSame( '', Documentate_Transitions::flag( 'archivar' ) );
		$this->assertSame( 'editar', Documentate_Transitions::redirect( 'guardar' ) );
		$this->assertSame( 'guardado', Documentate_Transitions::flag( 'guardar' ) );
	}

	/**
	 * rule() finds a row by key, disambiguated by the starting status.
	 */
	public function test_rule_lookup() {
		$this->assertSame( 'admin', Documentate_Transitions::rule( 'devolver_area', 'pending' )['who'] );
		$this->assertSame( 'gestion', Documentate_Transitions::rule( 'devolver_area', 'en_gestion' )['who'] );
		$this->assertSame( 'en_gestion', Documentate_Transitions::rule( 'devolver_area' )['from'] );
		$this->assertSame( 'Aprobar y publicar', Documentate_Transitions::rule( 'aprobar' )['label'] );
		$this->assertNull( Documentate_Transitions::rule( 'inventada' ) );
		$this->assertNull( Documentate_Transitions::rule( 'aprobar', 'draft' ) );
	}

	/**
	 * A return posted through wp-admin (status + reason) is recorded by the hook.
	 */
	public function test_record_from_save_records_wp_admin_returns() {
		$doc = $this->create_document( 'pending', $this->management_type_id );
		wp_set_current_user( $this->admin_id );

		$_POST['documentate_motivo'] = ' Falta el número de expediente ';
		$_POST[ Documentate_Transitions::NONCE ] = wp_create_nonce( Documentate_Transitions::NONCE );

		$this->assertSame( 'Falta el número de expediente', Documentate_Transitions::posted_reason() );

		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'en_gestion',
			)
		);

		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$returned = Documentate_Document_Data::returned( $doc );
		$this->assertSame( 'Falta el número de expediente', $returned['motivo'] );
		$this->assertSame( 'administracion', $returned['desde'] );
		$this->assertSame( 'gestion', $returned['a'] );

		$events = Documentate_Activity::entries( $doc );
		$this->assertCount( 1, $events );
		$this->assertSame( 'devolvió el documento a gestión: «Falta el número de expediente»', $events[0]['text'] );
	}

	/**
	 * The first save of a document created in wp-admin records its creation.
	 *
	 * Saved as a draft it records "creó el borrador"; saved straight into
	 * the pipeline it records the transition from draft it amounts to.
	 */
	public function test_record_from_save_records_wp_admin_creation() {
		$doc = $this->create_document( 'auto-draft', $this->management_type_id );
		wp_set_current_user( $this->area_id );

		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'draft', get_post_status( $doc ) );
		$events = Documentate_Activity::entries( $doc );
		$this->assertCount( 1, $events );
		$this->assertSame( 'creó el borrador', $events[0]['text'] );
		$this->assertNotSame( '', Documentate_Activity::event_date( $doc, 'creó' ) );

		// Only once: later draft saves are silent, and sending records the send.
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Otro título',
			)
		);
		$this->assertCount( 1, Documentate_Activity::entries( $doc ) );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'en_gestion',
			)
		);
		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$this->assertSame( 'envió el documento a gestión', Documentate_Activity::entries( $doc )[0]['text'] );
		$this->assertCount( 2, Documentate_Activity::entries( $doc ) );

		// Straight from auto-draft into gestión: the send is recorded, not a creation.
		$direct = $this->create_document( 'auto-draft', $this->management_type_id );
		wp_set_current_user( $this->area_id );
		wp_update_post(
			array(
				'ID' => $direct,
				'post_status' => 'en_gestion',
			)
		);
		$this->assertSame( 'en_gestion', get_post_status( $direct ) );
		$events = Documentate_Activity::entries( $direct );
		$this->assertCount( 1, $events );
		$this->assertSame( 'envió el documento a gestión', $events[0]['text'] );
		$this->assertSame( '', Documentate_Activity::event_date( $direct, 'creó' ) );

		// Programmatic creation (new → draft) stays silent: seeders write their own history.
		$this->assertSame( array(), Documentate_Activity::entries( $this->create_document( 'draft', $this->direct_type_id ) ) );
	}

	/**
	 * A forward save through wp-admin records its event and clears the mark.
	 */
	public function test_record_from_save_records_forward_saves() {
		$doc = $this->create_document( 'draft', $this->direct_type_id );
		Documentate_Document_Data::mark_returned( $doc, 'antes', 'administracion', 'area', $this->admin_id );

		wp_set_current_user( $this->area_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'pending',
			)
		);

		$this->assertSame( 'pending', get_post_status( $doc ) );
		$this->assertNull( Documentate_Document_Data::returned( $doc ) );
		$events = Documentate_Activity::entries( $doc );
		$this->assertCount( 1, $events );
		$this->assertSame( 'envió el documento a revisión', $events[0]['text'] );

		// Saving again without changing the status records nothing else.
		wp_update_post(
			array(
				'ID' => $doc,
				'post_title' => 'Otro título',
			)
		);
		$this->assertCount( 1, Documentate_Activity::entries( $doc ) );
	}

	/**
	 * The hook ignores other post types, transitions outside the table and its own.
	 */
	public function test_record_from_save_ignores_what_is_not_a_rule() {
		$entry = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		wp_set_current_user( $this->admin_id );
		wp_update_post(
			array(
				'ID' => $entry,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array(), Documentate_Activity::entries( $entry ) );

		// Admin legacy publish from draft: outside the table, no event.
		$doc = $this->create_document( 'draft', $this->direct_type_id );
		wp_set_current_user( $this->admin_id );
		wp_update_post(
			array(
				'ID' => $doc,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'publish', get_post_status( $doc ) );
		$this->assertSame( array(), Documentate_Activity::entries( $doc ) );

		// apply() writes once, not twice.
		$other = $this->create_document( 'pending', $this->direct_type_id );
		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Documentate_Transitions::apply( $other, 'aprobar' ) );
		$this->assertCount( 1, Documentate_Activity::entries( $other ) );
	}

	/**
	 * A posted reason is ignored without a valid nonce.
	 */
	public function test_posted_reason_requires_nonce() {
		$this->assertSame( '', Documentate_Transitions::posted_reason() );

		$_POST['documentate_motivo'] = 'Falta algo';
		$this->assertSame( '', Documentate_Transitions::posted_reason() );

		$_POST[ Documentate_Transitions::NONCE ] = 'nope';
		$this->assertSame( '', Documentate_Transitions::posted_reason() );

		$_POST[ Documentate_Transitions::NONCE ] = wp_create_nonce( Documentate_Transitions::NONCE );
		$this->assertSame( 'Falta algo', Documentate_Transitions::posted_reason() );
	}

	/**
	 * A document locked for the user cannot be trashed by them.
	 *
	 * The freeze would keep the status while WordPress hides the activity.
	 */
	public function test_block_trash_follows_the_lock() {
		$doc = $this->create_document( 'en_gestion', $this->management_type_id );
		wp_set_current_user( $this->area_id );
		$event = Documentate_Activity::record_event( $doc, 'envió el documento a gestión' );

		$this->assertFalse( wp_trash_post( $doc ) );
		$this->assertSame( 'en_gestion', get_post_status( $doc ) );
		$this->assertSame( '1', get_comment( $event )->comment_approved, 'The activity is untouched.' );
		$this->assertCount( 1, Documentate_Activity::entries( $doc ) );
		$this->assertSame( '', get_post_meta( $doc, '_wp_trash_meta_status', true ) );
		$this->assertFalse( Documentate_Transitions::block_trash( null, get_post( $doc ) ) );

		// Gestión on pending is locked too; other post types are ignored.
		$pending = $this->create_document( 'pending', $this->management_type_id );
		wp_set_current_user( $this->management_id );
		$this->assertFalse( wp_trash_post( $pending ) );
		$this->assertSame( 'pending', get_post_status( $pending ) );
		$this->assertNull( Documentate_Transitions::block_trash( null, get_post( self::factory()->post->create() ) ) );
		$this->assertTrue( Documentate_Transitions::block_trash( true, get_post( $doc ) ), 'Gestión may trash en_gestion.' );

		// Whoever may modify the document may trash it: gestión on en_gestion, área on a draft, administración anywhere.
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $doc ) );
		$this->assertSame( 'trash', get_post_status( $doc ) );
		$draft = $this->create_document( 'draft', $this->management_type_id );
		wp_set_current_user( $this->area_id );
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $draft ) );
		wp_set_current_user( $this->admin_id );
		$this->assertInstanceOf( WP_Post::class, wp_trash_post( $pending ) );
	}

	/**
	 * init() hooks the recorder early on transition_post_status and the trash guard.
	 */
	public function test_init_hooks_recorder() {
		Documentate_Transitions::init();

		$this->assertSame( 5, has_action( 'transition_post_status', array( 'Documentate_Transitions', 'record_from_save' ) ) );
		$this->assertSame( 10, has_filter( 'pre_trash_post', array( 'Documentate_Transitions', 'block_trash' ) ) );
	}
}
