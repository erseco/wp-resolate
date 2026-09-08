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
}
