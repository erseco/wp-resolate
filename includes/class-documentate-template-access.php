<?php
/**
 * Template Access Restriction for Documentate.
 *
 * Restricts management of documentate_doc_type taxonomy terms (templates)
 * to administrators only. Non-admin users are blocked from accessing the
 * taxonomy edit screens and the admin menu item is hidden for them.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Template_Access
 *
 * Enforces server-side access control for the documentate_doc_type taxonomy:
 * - Blocks non-admins from accessing add/edit/list screens.
 * - Removes the taxonomy submenu for non-admins.
 */
class Documentate_Template_Access {
	/**
	 * The taxonomy slug for document types (templates).
	 *
	 * @var string
	 */
	const TAXONOMY = 'documentate_doc_type';

	/**
	 * The capability required to manage templates.
	 *
	 * @var string
	 */
	const REQUIRED_CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'block_non_admin_access' ) );
		add_action( 'admin_menu', array( $this, 'remove_menu_for_non_admins' ), 999 );
	}

	/**
	 * Block non-admin users from accessing template taxonomy admin screens.
	 *
	 * Fires on admin_init. `get_current_screen()` is often null at that hook,
	 * so the check uses the `taxonomy` request parameter as the primary signal
	 * and falls back to the screen object when it is available (e.g. later in
	 * the request or in tests). Taxonomy capabilities already require
	 * manage_options; this is an explicit early deny for direct URL access.
	 *
	 * @return void
	 */
	public function block_non_admin_access() {
		if ( current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only gate on admin screen load.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';

		$screen          = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_taxonomy = ( $screen && ! empty( $screen->taxonomy ) ) ? (string) $screen->taxonomy : '';
		$screen_id       = $screen ? (string) $screen->id : '';

		$is_doc_type_surface = (
			self::TAXONOMY === $taxonomy
			|| self::TAXONOMY === $screen_taxonomy
			|| ( 'edit-' . self::TAXONOMY ) === $screen_id
		);

		if ( ! $is_doc_type_surface ) {
			return;
		}

		wp_die(
			esc_html( 'No tienes permiso para gestionar plantillas.' ),
			esc_html( 'Acceso denegado' ),
			array( 'response' => 403 ),
		);
	}

	/**
	 * Remove the documentate_doc_type submenu for non-admin users.
	 *
	 * @return void
	 */
	public function remove_menu_for_non_admins() {
		if ( current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		remove_submenu_page(
			'edit.php?post_type=documentate_document',
			'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=documentate_document',
		);
	}
}

new Documentate_Template_Access();
