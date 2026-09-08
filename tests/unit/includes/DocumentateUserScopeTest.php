<?php
/**
 * Tests for Documentate_User_Scope.
 *
 * Verifies that the scope category profile field is rendered, saved, and
 * validated correctly.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_User_Scope
 */
class DocumentateUserScopeTest extends WP_UnitTestCase {

	/**
	 * User scope instance.
	 *
	 * @var Documentate_User_Scope
	 */
	protected $user_scope;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_user_id;

	/**
	 * Valid category term ID.
	 *
	 * @var int
	 */
	protected $cat_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$cat          = wp_insert_term( 'Test Scope', 'category' );
		$this->cat_id = $cat['term_id'];

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-user-scope.php';
		$this->user_scope = new Documentate_User_Scope();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY );
		parent::tear_down();
	}

	/**
	 * Test constructor registers show_user_profile hook.
	 */
	public function test_registers_show_user_profile_hook() {
		$this->assertNotFalse( has_action( 'show_user_profile', array( $this->user_scope, 'render_scope_field' ) ) );
	}

	/**
	 * Test constructor registers edit_user_profile hook.
	 */
	public function test_registers_edit_user_profile_hook() {
		$this->assertNotFalse( has_action( 'edit_user_profile', array( $this->user_scope, 'render_scope_field' ) ) );
	}

	/**
	 * Test constructor registers personal_options_update hook.
	 */
	public function test_registers_personal_options_update_hook() {
		$this->assertNotFalse( has_action( 'personal_options_update', array( $this->user_scope, 'save_scope_field' ) ) );
	}

	/**
	 * Test constructor registers edit_user_profile_update hook.
	 */
	public function test_registers_edit_user_profile_update_hook() {
		$this->assertNotFalse( has_action( 'edit_user_profile_update', array( $this->user_scope, 'save_scope_field' ) ) );
	}

	/**
	 * Test render_scope_field outputs the dropdown and nonce.
	 */
	public function test_render_scope_field_outputs_dropdown() {
		wp_set_current_user( $this->admin_user_id );
		$user = get_userdata( $this->editor_user_id );

		ob_start();
		$this->user_scope->render_scope_field( $user );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_scope_term_id', $output );
		$this->assertStringContainsString( 'documentate_scope_nonce', $output );
		$this->assertStringContainsString( 'Categoría de ámbito', $output );
	}

	/**
	 * Test render_scope_field does nothing if user cannot edit the profile.
	 */
	public function test_render_scope_field_skipped_without_permission() {
		wp_set_current_user( $this->editor_user_id );
		$admin_user = get_userdata( $this->admin_user_id );

		ob_start();
		$this->user_scope->render_scope_field( $admin_user );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Editor should not be able to render scope field for admin.' );
	}

	/**
	 * Test save_scope_field saves valid term.
	 */
	public function test_save_scope_field_saves_valid_term() {
		wp_set_current_user( $this->admin_user_id );

		$nonce = wp_create_nonce( 'documentate_save_scope_' . $this->editor_user_id );
		$_POST['documentate_scope_nonce']   = $nonce;
		$_POST[ Documentate_User_Scope::META_KEY ] = $this->cat_id;

		$this->user_scope->save_scope_field( $this->editor_user_id );

		$saved = absint( get_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, true ) );
		$this->assertSame( $this->cat_id, $saved, 'Valid term should be saved.' );

		unset( $_POST['documentate_scope_nonce'], $_POST[ Documentate_User_Scope::META_KEY ] );
	}

	/**
	 * Test save_scope_field rejects invalid term ID.
	 */
	public function test_save_scope_field_rejects_invalid_term() {
		wp_set_current_user( $this->admin_user_id );

		$nonce = wp_create_nonce( 'documentate_save_scope_' . $this->editor_user_id );
		$_POST['documentate_scope_nonce']   = $nonce;
		$_POST[ Documentate_User_Scope::META_KEY ] = 999999; // Non-existent term.

		$this->user_scope->save_scope_field( $this->editor_user_id );

		$saved = absint( get_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, true ) );
		$this->assertSame( 0, $saved, 'Invalid term should be stored as 0.' );

		unset( $_POST['documentate_scope_nonce'], $_POST[ Documentate_User_Scope::META_KEY ] );
	}

	/**
	 * Test save_scope_field stores 0 when no scope selected.
	 */
	public function test_save_scope_field_stores_zero_for_no_scope() {
		wp_set_current_user( $this->admin_user_id );

		$nonce = wp_create_nonce( 'documentate_save_scope_' . $this->editor_user_id );
		$_POST['documentate_scope_nonce']   = $nonce;
		$_POST[ Documentate_User_Scope::META_KEY ] = 0;

		$this->user_scope->save_scope_field( $this->editor_user_id );

		$saved = absint( get_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, true ) );
		$this->assertSame( 0, $saved, 'Zero scope should be stored as 0.' );

		unset( $_POST['documentate_scope_nonce'], $_POST[ Documentate_User_Scope::META_KEY ] );
	}

	/**
	 * Test save_scope_field requires valid nonce.
	 */
	public function test_save_scope_field_requires_valid_nonce() {
		wp_set_current_user( $this->admin_user_id );
		update_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, $this->cat_id );

		$_POST['documentate_scope_nonce']   = 'invalid-nonce';
		$_POST[ Documentate_User_Scope::META_KEY ] = 0;

		$this->user_scope->save_scope_field( $this->editor_user_id );

		// Value should remain unchanged.
		$saved = absint( get_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, true ) );
		$this->assertSame( $this->cat_id, $saved, 'Invalid nonce should prevent saving.' );

		unset( $_POST['documentate_scope_nonce'], $_POST[ Documentate_User_Scope::META_KEY ] );
	}

	/**
	 * Editors must not be able to change their own scope (authorization boundary).
	 */
	public function test_editor_cannot_self_assign_scope() {
		wp_set_current_user( $this->editor_user_id );
		update_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, 0 );

		$nonce = wp_create_nonce( 'documentate_save_scope_' . $this->editor_user_id );
		$_POST['documentate_scope_nonce']          = $nonce;
		$_POST[ Documentate_User_Scope::META_KEY ] = $this->cat_id;

		$this->user_scope->save_scope_field( $this->editor_user_id );

		$saved = absint( get_user_meta( $this->editor_user_id, Documentate_User_Scope::META_KEY, true ) );
		$this->assertSame( 0, $saved, 'Editor must not self-assign a broader scope.' );

		unset( $_POST['documentate_scope_nonce'], $_POST[ Documentate_User_Scope::META_KEY ] );
	}

	/**
	 * Scope field is hidden on the profile for non-administrators.
	 */
	public function test_render_scope_field_hidden_for_editor_self() {
		wp_set_current_user( $this->editor_user_id );
		$user = get_userdata( $this->editor_user_id );

		ob_start();
		$this->user_scope->render_scope_field( $user );
		$output = ob_get_clean();

		$this->assertSame( '', $output, 'Non-admins must not see the scope assignment field.' );
	}
}
