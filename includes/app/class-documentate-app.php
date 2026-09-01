<?php
/**
 * Front-end application of Documentate, served under /documentate/.
 *
 * One WordPress page carries the [documentate_app] shortcode; the views are
 * resolved from query arguments (vista, doc) so the whole application lives
 * under a single URL. Access is capability-gated: the app is for logged-in
 * users who can edit documents.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Shortcode, routing and plumbing of the front-end application.
 */
class Documentate_App {

	/**
	 * Shortcode that renders the application.
	 *
	 * @var string
	 */
	const SHORTCODE = 'documentate_app';

	/**
	 * Option holding the ID of the application page.
	 *
	 * @var string
	 */
	const OPTION_PAGE_ID = 'documentate_app_page_id';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_filter( 'body_class', array( 'Documentate_App_Shell', 'body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'ensure_page' ) );
		add_action( 'template_redirect', array( $this, 'handle_create_document' ) );
	}

	/**
	 * Create the /documentate/ page the first time, and adopt an existing one.
	 *
	 * Idempotent: once the option points at a published page nothing happens.
	 *
	 * @return void
	 */
	public function ensure_page() {
		$page_id = absint( get_option( self::OPTION_PAGE_ID ) );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( Documentate_App_Shell::PAGE_SLUG );
		if ( $existing instanceof WP_Post && 'publish' === $existing->post_status ) {
			update_option( self::OPTION_PAGE_ID, $existing->ID );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_type' => 'page',
				'post_status' => 'publish',
				'post_name' => Documentate_App_Shell::PAGE_SLUG,
				'post_title' => 'Documentate',
				'post_content' => '[' . self::SHORTCODE . ']',
				'comment_status' => 'closed',
				'ping_status' => 'closed',
			)
		);

		if ( $page_id > 0 && ! is_wp_error( $page_id ) ) {
			update_option( self::OPTION_PAGE_ID, $page_id );
		}
	}

	/**
	 * Enqueue the application stylesheet on the application page only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! Documentate_App_Shell::is_app_page() ) {
			return;
		}

		wp_enqueue_style(
			'documentate-app',
			plugins_url( 'public/css/documentate-app.css', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION,
		);
	}

	/**
	 * Whether the current user may use the application at all.
	 *
	 * @return bool
	 */
	public static function current_user_can_use_app() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Create a draft document from the "new document" form and redirect.
	 *
	 * Runs on template_redirect so the redirect happens before any output.
	 *
	 * @return void
	 */
	public function handle_create_document() {
		if ( ! isset( $_POST['documentate_app_accion'] ) || 'crear_documento' !== $_POST['documentate_app_accion'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below.
			return;
		}

		if (
			! isset( $_POST['documentate_app_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_key( wp_unslash( $_POST['documentate_app_nonce'] ) ),
				'documentate_app_crear'
			)
		) {
			wp_die( esc_html__( 'Security check failed.', 'documentate' ), '', array( 'response' => 403 ) );
		}

		if ( ! self::current_user_can_use_app() ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ), '', array( 'response' => 403 ) );
		}

		$titulo = isset( $_POST['documentate_app_titulo'] )
			? sanitize_text_field( wp_unslash( $_POST['documentate_app_titulo'] ) )
			: '';
		$tipo = isset( $_POST['documentate_app_tipo'] ) ? absint( $_POST['documentate_app_tipo'] ) : 0;

		if ( '' === $titulo || $tipo <= 0 || ! term_exists( $tipo, 'documentate_doc_type' ) ) {
			wp_safe_redirect( add_query_arg( 'error', 'datos', Documentate_App_Shell::page_url( array( 'vista' => 'nuevo' ) ) ) );
			exit;
		}

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'post_title' => $titulo,
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			wp_safe_redirect( add_query_arg( 'error', 'crear', Documentate_App_Shell::page_url( array( 'vista' => 'nuevo' ) ) ) );
			exit;
		}

		wp_set_post_terms( $post_id, array( $tipo ), 'documentate_doc_type', false );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $tipo );

		// The fields editor still lives in wp-admin; the app takes over as views land.
		wp_safe_redirect( admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Render the application: gate, then the view the query asks for.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return Documentate_App_Shell::abrir( '', 'Documentate', '' )
				. '<div class="dcta-aviso">'
				. esc_html__( 'Sign in to work with your documents.', 'documentate' )
				. ' <a href="' . esc_url( wp_login_url( Documentate_App_Shell::page_url() ) ) . '">'
				. esc_html__( 'Sign in', 'documentate' )
				. '</a></div>'
				. Documentate_App_Shell::cerrar();
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return Documentate_App_Shell::abrir( '', 'Documentate', '' )
				. '<div class="dcta-aviso">'
				. esc_html__( 'Your user cannot edit documents. Contact an administrator.', 'documentate' )
				. '</div>'
				. Documentate_App_Shell::cerrar();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$doc = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$vista = isset( $_GET['vista'] ) ? sanitize_key( wp_unslash( $_GET['vista'] ) ) : '';

		if ( $doc > 0 ) {
			return Documentate_App_Detalle::render( $doc );
		}

		if ( 'nuevo' === $vista ) {
			return $this->render_nuevo();
		}

		return Documentate_App_Lista::render();
	}

	/**
	 * Render the "new document" form.
	 *
	 * @return string
	 */
	private function render_nuevo() {
		$tipos = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);
		$tipos = is_wp_error( $tipos ) ? array() : $tipos;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feedback flag on a redirect.
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';

		$html = Documentate_App_Shell::abrir(
			'nuevo',
			__( 'New document', 'documentate' ),
			__( 'Choose the type and give it a name; the type cannot be changed later.', 'documentate' )
		);

		if ( '' !== $error ) {
			$html .= '<div class="dcta-aviso">'
				. esc_html__( 'The document could not be created. Check the name and the type.', 'documentate' )
				. '</div>';
		}

		if ( empty( $tipos ) ) {
			return $html
				. '<div class="dcta-aviso">'
				. esc_html__( 'No document types defined. Create one in Document Types.', 'documentate' )
				. '</div>'
				. Documentate_App_Shell::cerrar();
		}

		ob_start();
		?>
		<form class="dcta-form" method="post" action="">
			<?php wp_nonce_field( 'documentate_app_crear', 'documentate_app_nonce' ); ?>
			<input type="hidden" name="documentate_app_accion" value="crear_documento" />

			<div class="dcta-campo">
				<label for="documentate-app-tipo"><?php esc_html_e( 'Document type', 'documentate' ); ?></label>
				<select id="documentate-app-tipo" name="documentate_app_tipo" required>
					<option value=""><?php esc_html_e( 'Select a type…', 'documentate' ); ?></option>
					<?php foreach ( $tipos as $tipo ) : ?>
						<option value="<?php echo esc_attr( (string) $tipo->term_id ); ?>"><?php echo esc_html( $tipo->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="dcta-campo">
				<label for="documentate-app-titulo"><?php esc_html_e( 'Document name', 'documentate' ); ?></label>
				<input type="text" id="documentate-app-titulo" name="documentate_app_titulo" required maxlength="200" />
				<p class="dcta-ayuda"><?php esc_html_e( 'Short: it is what you will see in lists and searches.', 'documentate' ); ?></p>
			</div>

			<button type="submit" class="dcta-btn dcta-btn-pri"><?php esc_html_e( 'Create draft', 'documentate' ); ?></button>
			<a class="dcta-btn dcta-btn-ton" href="<?php echo esc_url( Documentate_App_Shell::page_url() ); ?>"><?php esc_html_e( 'Cancel', 'documentate' ); ?></a>
		</form>
		<?php
		$html .= (string) ob_get_clean();

		return $html . Documentate_App_Shell::cerrar();
	}
}
