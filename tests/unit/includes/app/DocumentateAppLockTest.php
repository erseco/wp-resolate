<?php
/**
 * Native edit lock interoperability and write protection.
 *
 * @package Documentate
 */

/**
 * Exercise locks through the app and the core Heartbeat callback.
 *
 * @covers Documentate_App_Lock
 * @covers Documentate_App_Edit
 * @covers Documentate_App_Actions
 */
class DocumentateAppLockTest extends WP_UnitTestCase {

	/** @var int First editor. */
	private $first;

	/** @var int Second editor. */
	private $second;

	/** @var int Document ID. */
	private $doc;

	/** Set up two editors and a draft. */
	public function set_up(): void {
		parent::set_up();
		Documentate_Roles::ensure_caps( true );
		$this->first = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Primera persona' ) );
		$this->second = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Segunda persona' ) );
		wp_set_current_user( $this->first );
		$this->doc = self::factory()->post->create( array( 'post_type' => 'documentate_document', 'post_status' => 'draft', 'post_title' => 'Original' ) );
		( new Documentate_App() )->ensure_page();
	}

	/** Clear request state. */
	public function tear_down(): void {
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** A first visit acquires the same lock that wp-admin checks. */
	public function test_first_editor_blocks_the_next_visitor() {
		$html = Documentate_App_Edit::render( $this->doc );
		$this->assertStringContainsString( 'class="dcta-editor"', $html );
		wp_set_current_user( $this->second );
		$this->assertSame( $this->first, wp_check_post_lock( $this->doc ) );
		$html = Documentate_App_Edit::render( $this->doc );
		$this->assertStringNotContainsString( 'class="dcta-editor"', $html );
		$this->assertStringContainsString( 'Primera persona', $html );
		$this->assertStringContainsString( 'Tomar posesión', $html );
		$this->assertSame( $this->first, Documentate_App_Lock::owner( $this->doc ) );
	}

	/** Core Heartbeat refreshes an owned lock and reports a wp-admin takeover. */
	public function test_core_heartbeat_detects_a_takeover() {
		Documentate_App_Edit::render( $this->doc );
		$data = array( 'wp-refresh-post-lock' => array( 'post_id' => $this->doc ) );
		$response = wp_refresh_post_lock( array(), $data, 'front' );
		$this->assertStringEndsWith( ':' . $this->first, $response['wp-refresh-post-lock']['new_lock'] );
		wp_set_current_user( $this->second );
		wp_set_post_lock( $this->doc );
		wp_set_current_user( $this->first );
		$response = wp_refresh_post_lock( array(), $data, 'front' );
		$this->assertSame( 'Segunda persona', $response['wp-refresh-post-lock']['lock_error']['name'] );
	}

	/** Expired locks do not prevent editing when a browser disappeared. */
	public function test_expired_lock_can_be_acquired() {
		update_post_meta( $this->doc, '_edit_lock', ( time() - 200 ) . ':' . $this->second );
		$this->assertStringContainsString( 'class="dcta-editor"', Documentate_App_Edit::render( $this->doc ) );
		$this->assertStringEndsWith( ':' . $this->first, get_post_meta( $this->doc, '_edit_lock', true ) );
	}

	/** Old collaborative settings cannot disable native locking anymore. */
	public function test_legacy_settings_do_not_disable_locks() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		Documentate_App_Edit::render( $this->doc );
		wp_set_current_user( $this->second );
		$this->assertSame( $this->first, Documentate_App_Lock::owner( $this->doc ) );
	}

	/** A release must never delete another editor's lock. */
	public function test_release_only_removes_owned_locks() {
		Documentate_App_Edit::render( $this->doc );
		wp_set_current_user( $this->second );
		Documentate_App_Lock::release( $this->doc );
		$this->assertSame( $this->first, Documentate_App_Lock::owner( $this->doc ) );
		wp_set_current_user( $this->first );
		Documentate_App_Lock::release( $this->doc );
		$this->assertSame( '', get_post_meta( $this->doc, '_edit_lock', true ) );
		Documentate_App_Lock::release( $this->doc );
		Documentate_App_Lock::require_available( $this->doc );
	}

	/**
	 * The losing editor cannot modify data or status through a stale POST.
	 *
	 * @dataProvider blocked_actions
	 * @param string $action POST action.
	 * @param string $nonce  Nonce prefix.
	 * @param string $method Handler.
	 */
	public function test_stale_writes_are_rejected( $action, $nonce, $method ) {
		Documentate_App_Edit::render( $this->doc );
		wp_set_current_user( $this->second );
		$_POST = array(
			'documentate_app_accion' => $action,
			'documentate_app_doc' => $this->doc,
			'documentate_app_nonce' => wp_create_nonce( $nonce . $this->doc ),
			'documentate_app_nombre' => 'Sobrescrito',
			'documentate_app_titulo' => 'Sobrescrito',
			'documentate_app_transicion' => 'enviar',
		);
		try {
			Documentate_App_Actions::$method();
			$this->fail( 'The stale write must be rejected.' );
		} catch ( WPDieException $error ) {
			$this->assertStringContainsString( 'Otra persona', $error->getMessage() );
		}
		$this->assertSame( 'Original', get_the_title( $this->doc ) );
		$this->assertSame( 'draft', get_post_status( $this->doc ) );
		$this->assertNotSame( 'Sobrescrito', Documentate_Document_Data::internal_name( get_post( $this->doc ) ) );
	}

	/**
	 * App write paths protected by the lock.
	 *
	 * @return array
	 */
	public static function blocked_actions() {
		return array(
			'save' => array( 'guardar_documento', 'documentate_app_guardar_', 'handle_save_document' ),
			'transition' => array( 'transicion', 'documentate_app_transicion_', 'handle_transition' ),
		);
	}

	/** A nonce-protected explicit takeover changes the owner and redirects. */
	public function test_takeover_changes_owner_without_saving_fields() {
		Documentate_App_Edit::render( $this->doc );
		wp_set_current_user( $this->second );
		$_POST = array(
			'documentate_app_accion' => 'tomar_control',
			'documentate_app_doc' => $this->doc,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_tomar_control_' . $this->doc ),
		);
		$interceptor = static function ( $url ) {
			throw new Documentate_Exit_Exception( $url );
		};
		add_filter( 'wp_redirect', $interceptor );
		try {
			Documentate_App_Actions::handle_takeover();
			$this->fail( 'Expected redirect.' );
		} catch ( Documentate_Exit_Exception $error ) {
			$this->assertSame( Documentate_App_Edit::url( $this->doc ), $error->get_location() );
		} finally {
			remove_filter( 'wp_redirect', $interceptor );
		}
		wp_set_current_user( $this->first );
		$this->assertSame( $this->second, Documentate_App_Lock::owner( $this->doc ) );
		$this->assertSame( 'Original', get_the_title( $this->doc ) );
	}

	/** A takeover cannot be forged without a nonce. */
	public function test_takeover_rejects_bad_nonce() {
		$_POST = array( 'documentate_app_accion' => 'tomar_control', 'documentate_app_doc' => $this->doc );
		$this->expectException( WPDieException::class );
		Documentate_App_Actions::handle_takeover();
	}

	/** A nonce alone does not allow a visitor without editing capability. */
	public function test_takeover_rejects_unauthorized_users() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array(
			'documentate_app_accion' => 'tomar_control',
			'documentate_app_doc' => $this->doc,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_tomar_control_' . $this->doc ),
		);
		$this->expectException( WPDieException::class );
		Documentate_App_Actions::handle_takeover();
	}

	/**
	 * Taking over is exactly "may this person edit the document right now".
	 *
	 * Whoever holds a document in its status can take it from whoever has it
	 * open — an área over a draft of its own ámbito, revisión over one in
	 * revisión, any of the heads of service over one in aprobación — and
	 * whoever the status has already left behind cannot.
	 *
	 * @dataProvider takeover_matrix
	 *
	 * @param string $status  Document status.
	 * @param string $role    area, gestion, jefatura or admin.
	 * @param bool   $allowed Whether the takeover must go through.
	 */
	public function test_takeover_follows_the_edit_rule_of_the_status( $status, $role, $allowed ) {
		$scope = wp_insert_term( 'Servicio ' . uniqid(), 'category' );
		$service = (int) $scope['term_id'];
		$area_term = wp_insert_term( 'Área ' . uniqid(), 'category', array( 'parent' => $service ) );
		$area_id = (int) $area_term['term_id'];

		$users = array(
			'area' => self::factory()->user->create( array( 'role' => 'author' ) ),
			'gestion' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			'jefatura' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			'admin' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
		);
		Documentate_Roles::grant_management( $users['gestion'] );
		Documentate_Roles::grant_head( $users['jefatura'] );
		update_user_meta( $users['area'], 'documentate_scope_term_id', $area_id );
		update_user_meta( $users['gestion'], 'documentate_scope_term_id', $service );
		update_user_meta( $users['jefatura'], 'documentate_scope_term_id', $service );

		// A document with no type never leaves draft, so the type comes first;
		// the área account authors it, which is what its role lets it edit.
		$type = wp_insert_term( 'Tipo bloqueo ' . uniqid(), 'documentate_doc_type' );
		$type_id = (int) $type['term_id'];

		wp_set_current_user( $this->first );
		$doc = self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_status' => $status,
				'post_title' => 'Original',
				'post_author' => $users['area'],
				'tax_input' => array( 'documentate_doc_type' => array( $type_id ) ),
			)
		);
		update_post_meta( $doc, 'documentate_locked_doc_type', $type_id );
		wp_set_object_terms( $doc, array( $area_id ), 'category' );
		$this->assertSame( $status, get_post_status( $doc ), 'The fixture really stands in that status.' );
		wp_set_post_lock( $doc );

		wp_set_current_user( $users[ $role ] );
		$_POST = array(
			'documentate_app_accion' => 'tomar_control',
			'documentate_app_doc' => $doc,
			'documentate_app_nonce' => wp_create_nonce( 'documentate_app_tomar_control_' . $doc ),
		);

		$interceptor = static function ( $url ) {
			throw new Documentate_Exit_Exception( $url );
		};
		add_filter( 'wp_redirect', $interceptor );
		try {
			Documentate_App_Actions::handle_takeover();
			$this->fail( 'The handler always ends in a redirect or a wp_die().' );
		} catch ( Documentate_Exit_Exception $error ) {
			$this->assertTrue( $allowed, 'The takeover went through.' );
			$this->assertSame( $users[ $role ], $this->lock_owner( $doc ) );
		} catch ( WPDieException $error ) {
			$this->assertFalse( $allowed, 'The takeover was refused.' );
			$this->assertSame( $this->first, $this->lock_owner( $doc ) );
		} finally {
			remove_filter( 'wp_redirect', $interceptor );
		}
	}

	/**
	 * Who holds the lock, read from the meta.
	 *
	 * wp_check_post_lock() answers "somebody else", so it says false to
	 * whoever just took the document over, which is exactly the case to
	 * assert here.
	 *
	 * @param int $post_id Document ID.
	 * @return int User ID, 0 when the document carries no lock.
	 */
	private function lock_owner( $post_id ) {
		$parts = explode( ':', (string) get_post_meta( $post_id, '_edit_lock', true ) );

		return isset( $parts[1] ) ? (int) $parts[1] : 0;
	}

	/**
	 * Who may take a document over in each status.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function takeover_matrix() {
		$rules = array(
			'draft' => array( 'area' => true, 'gestion' => true, 'jefatura' => true, 'admin' => true ),
			'en_gestion' => array( 'area' => false, 'gestion' => true, 'jefatura' => true, 'admin' => true ),
			'pending' => array( 'area' => false, 'gestion' => false, 'jefatura' => true, 'admin' => true ),
			'publish' => array( 'area' => false, 'gestion' => false, 'jefatura' => false, 'admin' => true ),
		);

		$cases = array();
		foreach ( $rules as $status => $roles ) {
			foreach ( $roles as $role => $allowed ) {
				$cases[ $status . ' · ' . $role ] = array( $status, $role, $allowed );
			}
		}

		return $cases;
	}
}
