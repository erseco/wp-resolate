<?php
/**
 * Native WordPress edit locks shared by the app and wp-admin.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Adapt the core post lock to the front-end editor.
 */
class Documentate_App_Lock {

	/**
	 * Return the other editor holding an unexpired WordPress lock.
	 *
	 * @param int $post_id Document ID.
	 * @return int|false
	 */
	public static function owner( $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		return wp_check_post_lock( $post_id );
	}

	/**
	 * Reject writes before any fields, files or status can be changed.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	public static function require_available( $post_id ) {
		if ( self::owner( $post_id ) ) {
			wp_die(
				'Otra persona está editando este documento. Vuelve al editor y toma posesión antes de guardar.',
				'Edición bloqueada',
				array(
					'response' => 409,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Release only the current user's unchanged lock after a transition.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	public static function release( $post_id ) {
		$lock = (string) get_post_meta( $post_id, '_edit_lock', true );
		$parts = explode( ':', $lock );
		if ( isset( $parts[1] ) && get_current_user_id() === (int) $parts[1] ) {
			delete_post_meta( $post_id, '_edit_lock', $lock );
		}
	}

	/**
	 * Draw the takeover notice, also used when Heartbeat reports a lost lock.
	 *
	 * Same look as the lock notice of a document in another role's hands: a
	 * lock, who has it and the two things this person can do about it.
	 *
	 * @param WP_Post $post  Document.
	 * @param int     $owner Other editor ID, or zero when the editor is available.
	 * @return string
	 */
	public static function render( $post, $owner = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$lock = $owner ? array() : wp_set_post_lock( $post->ID );
		$user = $owner ? get_userdata( $owner ) : false;
		ob_start();
		?>
		<div id="dcta-edit-lock" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>" data-lock="<?php echo esc_attr( $lock ? implode( ':', $lock ) : '' ); ?>" data-release-nonce="<?php echo esc_attr( wp_create_nonce( 'update-post_' . $post->ID ) ); ?>">
			<dialog id="dcta-lock-dialog" class="dcta-dialogo dcta-dialogo-bloqueo" aria-labelledby="dcta-lock-title" <?php echo $owner ? 'open' : ''; ?>>
				<h2 id="dcta-lock-title" class="dcta-dialogo-titulo"><?php echo Documentate_App_Shell::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG literal. ?> Lo está editando otra persona</h2>
				<p class="dcta-dialogo-texto"><strong id="dcta-lock-owner"><?php echo esc_html( $user ? $user->display_name : 'Otra persona' ); ?></strong> tiene abierto este documento ahora mismo.</p>
				<p class="dcta-dialogo-texto">Puedes consultarlo mientras termina, o tomar posesión para editarlo tú: la otra persona dejará de poder guardar y el editor se recargará.</p>
				<form method="post" action="<?php echo esc_url( Documentate_App_Edit::url( $post->ID ) ); ?>">
					<?php wp_nonce_field( 'documentate_app_tomar_control_' . $post->ID, 'documentate_app_nonce' ); ?>
					<input type="hidden" name="documentate_app_accion" value="tomar_control" />
					<input type="hidden" name="documentate_app_doc" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
					<a class="dcta-btn" href="<?php echo esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ); ?>">Ver el documento</a>
					<button class="dcta-btn dcta-btn-pri" type="submit">Tomar posesión</button>
				</form>
			</dialog>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
