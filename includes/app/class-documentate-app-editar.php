<?php
/**
 * Document edit view of the front-end application.
 *
 * The form posts the same field names as the wp-admin sections metabox
 * (documentate_field_<slug>, tpl_fields[...]) together with its nonce, so a
 * plain wp_update_post() in Documentate_App::handle_save_document() runs the
 * whole existing pipeline: content composition, meta persistence and the
 * workflow status rules. Nothing about how fields are stored is duplicated
 * here; the view only draws the metabox markup inside the app sheet.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Renders the edit form of one document.
 */
class Documentate_App_Editar {

	/**
	 * Whether a document can be edited in the app by the current user.
	 *
	 * @param WP_Post $post Document.
	 * @return bool
	 */
	public static function puede_editar( $post ) {
		return current_user_can( 'edit_post', $post->ID )
			&& Documentate_Workflow::current_user_can_modify_document( $post->ID );
	}

	/**
	 * URL of the edit view of a document.
	 *
	 * @param int $doc_id Document post ID.
	 * @return string
	 */
	public static function url( $doc_id ) {
		return Documentate_App_Shell::page_url(
			array(
				'doc' => $doc_id,
				'vista' => 'editar',
			)
		);
	}

	/**
	 * Render the edit view.
	 *
	 * @param int $doc_id Document post ID.
	 * @return string
	 */
	public static function render( $doc_id ) {
		$post = get_post( $doc_id );

		if (
			! $post instanceof WP_Post
			|| 'documentate_document' !== $post->post_type
			|| ! current_user_can( 'edit_post', $post->ID )
		) {
			return Documentate_App_Shell::abrir( 'lista', __( 'Document', 'documentate' ), '' )
				. '<div class="dcta-aviso">'
				. esc_html__( 'This document does not exist or is outside your scope.', 'documentate' )
				. '</div>'
				. Documentate_App_Shell::cerrar();
		}

		if ( ! self::puede_editar( $post ) ) {
			return Documentate_App_Shell::abrir( 'lista', get_the_title( $post ), '' )
				. '<div class="dcta-aviso">'
				. esc_html__( 'This document is locked in its current status and cannot be edited.', 'documentate' )
				. ' <a href="' . esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ) . '">'
				. esc_html__( 'View the document', 'documentate' )
				. '</a></div>'
				. Documentate_App_Shell::cerrar();
		}

		$tipos = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'names' ) );
		$tipo = ! is_wp_error( $tipos ) && ! empty( $tipos ) ? $tipos[0] : '—';

		$html = Documentate_App_Shell::abrir(
			'lista',
			get_the_title( $post ),
			/* translators: %s: document type name */
			sprintf( __( '%s · fill in the fields and save, or send it for review when it is ready.', 'documentate' ), $tipo )
		);

		$html .= self::render_avisos();

		ob_start();
		?>
		<form class="dcta-editor" method="post" action="<?php echo esc_url( self::url( $post->ID ) ); ?>">
			<?php wp_nonce_field( 'documentate_app_guardar_' . $post->ID, 'documentate_app_nonce' ); ?>
			<input type="hidden" name="documentate_app_accion" value="guardar_documento" />
			<input type="hidden" name="documentate_app_doc" value="<?php echo esc_attr( (string) $post->ID ); ?>" />

			<div class="dcta-editor-cuerpo">
				<div class="dcta-card dcta-editor-card">
					<div class="dcta-campo">
						<label for="documentate-app-titulo"><?php esc_html_e( 'Document name', 'documentate' ); ?></label>
						<input type="text" id="documentate-app-titulo" name="documentate_app_titulo" value="<?php echo esc_attr( $post->post_title ); ?>" required maxlength="200" />
					</div>
					<?php ( new Documentate_Document_Meta_Boxes() )->render_sections_metabox( $post ); ?>
				</div>
			</div>

			<?php self::render_lado( $post, $tipo ); ?>
		</form>
		<?php
		$html .= (string) ob_get_clean();

		return $html . Documentate_App_Shell::cerrar();
	}

	/**
	 * Feedback after a redirect from the save handler.
	 *
	 * @return string
	 */
	private static function render_avisos() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flags on a redirect.
		$guardado = isset( $_GET['guardado'] ) ? sanitize_key( wp_unslash( $_GET['guardado'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flags on a redirect.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

		if ( '1' === $guardado ) {
			return '<div class="dcta-aviso dcta-aviso-ok">' . esc_html__( 'Changes saved.', 'documentate' ) . '</div>';
		}

		if ( 'titulo' === $error ) {
			return '<div class="dcta-aviso dcta-aviso-mal">' . esc_html__( 'The document needs a name.', 'documentate' ) . '</div>';
		}

		if ( '' !== $error ) {
			return '<div class="dcta-aviso dcta-aviso-mal">' . esc_html__( 'The document could not be saved. Try again.', 'documentate' ) . '</div>';
		}

		return '';
	}

	/**
	 * Print the side rail: status, type and the save buttons.
	 *
	 * @param WP_Post $post Document.
	 * @param string  $tipo Document type name.
	 * @return void
	 */
	private static function render_lado( $post, $tipo ) {
		$chip = Documentate_App_Shell::estado_chip( $post->post_status );
		?>
		<div class="dcta-lado dcta-editor-lado">
			<div class="dcta-card">
				<span class="<?php echo esc_attr( $chip['clase'] ); ?>"><?php echo esc_html( $chip['texto'] ); ?></span>
				<dl class="dcta-editor-meta">
					<dt><?php esc_html_e( 'Type', 'documentate' ); ?></dt>
					<dd><?php echo esc_html( $tipo ); ?></dd>
					<dt><?php esc_html_e( 'Updated', 'documentate' ); ?></dt>
					<dd><?php echo esc_html( get_the_modified_date( '', $post ) . ' · ' . get_the_modified_time( '', $post ) ); ?></dd>
				</dl>
			</div>
			<div class="dcta-card dcta-editor-acciones">
				<?php if ( 'draft' === $post->post_status ) : ?>
					<button type="submit" class="dcta-btn dcta-btn-ton" name="documentate_app_estado" value="guardar"><?php esc_html_e( 'Save draft', 'documentate' ); ?></button>
					<button type="submit" class="dcta-btn dcta-btn-pri" name="documentate_app_estado" value="enviar"><?php esc_html_e( 'Send for review', 'documentate' ); ?></button>
					<p class="dcta-ayuda"><?php esc_html_e( 'Once sent, only administrators can change it.', 'documentate' ); ?></p>
				<?php else : ?>
					<button type="submit" class="dcta-btn dcta-btn-pri" name="documentate_app_estado" value="guardar"><?php esc_html_e( 'Save changes', 'documentate' ); ?></button>
				<?php endif; ?>
				<a class="dcta-editor-volver" href="<?php echo esc_url( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ) ); ?>"><?php esc_html_e( 'View the document', 'documentate' ); ?></a>
			</div>
		</div>
		<?php
	}
}
