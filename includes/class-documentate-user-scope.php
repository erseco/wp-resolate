<?php
/**
 * User Scope Profile Field for Documentate.
 *
 * Adds a "Scope category" dropdown to WordPress user profiles so administrators
 * can assign a scope (category term) to each user. Non-admin users will only
 * see documents belonging to their assigned scope category (and descendants).
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_User_Scope
 *
 * Manages the user-level scope meta field (documentate_scope_term_id).
 */
class Documentate_User_Scope {
	/**
	 * The user meta key for storing the scope term ID.
	 *
	 * @var string
	 */
	const META_KEY = 'documentate_scope_term_id';

	/**
	 * The taxonomy used for scope terms.
	 *
	 * @var string
	 */
	const TAXONOMY = 'category';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_scope_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_scope_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_scope_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_scope_field' ) );
	}

	/**
	 * Whether the current user may assign document scopes.
	 *
	 * Scope assignment is an authorization control: only administrators may
	 * set or change a user's scope category. Being able to edit a profile
	 * (including one's own) is not sufficient.
	 *
	 * @param int $user_id User ID being edited.
	 * @return bool
	 */
	private function current_user_can_assign_scope( $user_id ) {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $user_id );
	}

	/**
	 * Render the scope category field on the user profile page.
	 *
	 * @param WP_User $user The user object being edited.
	 * @return void
	 */
	public function render_scope_field( $user ) {
		if ( ! $this->current_user_can_assign_scope( $user->ID ) ) {
			return;
		}

		$current_term_id = absint( get_user_meta( $user->ID, self::META_KEY, true ) );

		wp_nonce_field( 'documentate_save_scope_' . $user->ID, 'documentate_scope_nonce' );
		?>
		<h2><?php echo esc_html( 'Documentate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="documentate_scope_term_id">
						<?php echo esc_html( 'Categoría de ámbito' ); ?>
					</label>
				</th>
				<td>
					<?php

					wp_dropdown_categories(
						array(
							'show_option_none' => '— Sin ámbito —',
							'option_none_value' => '0',
							'orderby' => 'name',
							'selected' => $current_term_id,
							'hierarchical' => true,
							'name' => 'documentate_scope_term_id',
							'id' => 'documentate_scope_term_id',
							'hide_empty' => false,
						)
					);
					?>
					<p class="description">
						<?php echo esc_html( 'Los documentos en esta categoría (y subcategorías) serán visibles para este usuario.' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the scope category field from the user profile form.
	 *
	 * @param int $user_id The ID of the user being saved.
	 * @return void
	 */
	public function save_scope_field( $user_id ) {
		if ( ! $this->current_user_can_assign_scope( $user_id ) ) {
			return;
		}

		if (
			! isset( $_POST['documentate_scope_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_key( wp_unslash( $_POST['documentate_scope_nonce'] ) ),
				'documentate_save_scope_' . $user_id,
			)
		) {
			return;
		}

		$term_id = isset( $_POST[ self::META_KEY ] ) ? absint( wp_unslash( $_POST[ self::META_KEY ] ) ) : 0;

		// Validate term exists in the correct taxonomy (or allow 0 to clear).
		if ( $term_id > 0 ) {
			$term = get_term( $term_id, self::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				$term_id = 0;
			}
		}

		update_user_meta( $user_id, self::META_KEY, $term_id );
	}
}

new Documentate_User_Scope();
