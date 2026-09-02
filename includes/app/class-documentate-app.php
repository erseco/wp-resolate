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
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_node' ), 100 );
		add_action( 'template_redirect', array( $this, 'handle_create_document' ) );
		add_action( 'template_redirect', array( $this, 'handle_save_document' ) );
	}

	/**
	 * Add a shortcut to the application in the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function admin_bar_node( $wp_admin_bar ) {
		if ( ! self::current_user_can_use_app() ) {
			return;
		}

		$url = Documentate_App_Shell::page_url();
		if ( '' === $url ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id' => 'documentate-app',
				'title' => 'Documentate',
				'href' => $url,
				'meta' => array( 'title' => __( 'Open the Documentate application', 'documentate' ) ),
			)
		);
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
	 * Enqueue the application assets on the application page only.
	 *
	 * The edit view reuses the wp-admin field controls, so it also needs the
	 * classic editor (rich fields) and the repeater script.
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

		if ( ! self::is_edit_view_request() ) {
			return;
		}

		wp_enqueue_editor();
		wp_enqueue_script(
			'documentate-annexes',
			plugins_url( 'admin/js/documentate-annexes.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'editor' ),
			DOCUMENTATE_VERSION,
			true,
		);
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_localize_script(
			'documentate-annexes',
			'documentateTable',
			array(
				'pluginUrl' => plugins_url( 'admin/mce/table/plugin' . $suffix . '.js', DOCUMENTATE_PLUGIN_FILE ),
			)
		);
		// Automatic totals for the provider repeaters (propuesta de gasto).
		wp_enqueue_script(
			'documentate-calculos',
			plugins_url( 'admin/js/documentate-calculos.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'documentate-annexes' ),
			DOCUMENTATE_VERSION,
			true,
		);
	}

	/**
	 * Whether the request asks for the edit view of a document.
	 *
	 * @return bool
	 */
	private static function is_edit_view_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$vista = isset( $_GET['vista'] ) ? sanitize_key( wp_unslash( $_GET['vista'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$doc = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;

		return 'editar' === $vista && $doc > 0;
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
	 * Whether the request posts the given application action.
	 *
	 * @param string $accion Action name carried by the form.
	 * @return bool
	 */
	private static function es_accion( $accion ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce next.
		return isset( $_POST['documentate_app_accion'] ) && $accion === $_POST['documentate_app_accion'];
	}

	/**
	 * Stop the request unless the form carries a valid nonce for the action.
	 *
	 * @param string $accion Nonce action.
	 * @return void
	 */
	private static function exigir_nonce( $accion ) {
		$nonce = isset( $_POST['documentate_app_nonce'] ) ? sanitize_key( wp_unslash( $_POST['documentate_app_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $accion ) ) {
			wp_die( esc_html__( 'Security check failed.', 'documentate' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stop the request with a 403.
	 *
	 * @return void
	 */
	private static function denegar() {
		wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Title posted by the form, sanitised.
	 *
	 * @return string
	 */
	private static function titulo_enviado() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller has verified the nonce.
		return isset( $_POST['documentate_app_titulo'] ) ? sanitize_text_field( wp_unslash( $_POST['documentate_app_titulo'] ) ) : '';
	}

	/**
	 * Non-negative integer posted by the form (an ID), 0 when absent.
	 *
	 * @param string $campo Field name.
	 * @return int
	 */
	private static function entero_enviado( $campo ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce around this read.
		return isset( $_POST[ $campo ] ) ? absint( $_POST[ $campo ] ) : 0;
	}

	/**
	 * Redirect inside the application and stop.
	 *
	 * @param string               $url  Destination.
	 * @param array<string,string> $args Query arguments to add (feedback flags).
	 * @return void
	 */
	private static function redirigir( $url, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Create a draft document from the "new document" form and redirect.
	 *
	 * Runs on template_redirect so the redirect happens before any output.
	 *
	 * @return void
	 */
	public function handle_create_document() {
		if ( ! self::es_accion( 'crear_documento' ) ) {
			return;
		}

		self::exigir_nonce( 'documentate_app_crear' );

		if ( ! self::current_user_can_use_app() ) {
			self::denegar();
		}

		$nuevo_url = Documentate_App_Shell::page_url( array( 'vista' => 'nuevo' ) );
		$titulo = self::titulo_enviado();
		$tipo = self::entero_enviado( 'documentate_app_tipo' );

		if ( '' === $titulo || 0 === $tipo || ! term_exists( $tipo, 'documentate_doc_type' ) ) {
			self::redirigir( $nuevo_url, array( 'error' => 'datos' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'post_title' => $titulo,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			self::redirigir( $nuevo_url, array( 'error' => 'crear' ) );
		}

		wp_set_post_terms( $post_id, array( $tipo ), 'documentate_doc_type', false );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $tipo );
		Documentate_Actividad::registrar_evento( $post_id, 'creó el borrador' );

		self::redirigir( Documentate_App_Editar::url( $post_id ) );
	}

	/**
	 * Persist the edit form and redirect.
	 *
	 * The form carries the sections-metabox nonce and field names, so
	 * wp_update_post() drives the same filters and save_post handlers as the
	 * wp-admin editor: the workflow decides the final status, the content
	 * writer composes post_content and the meta saver stores the fields.
	 *
	 * @return void
	 */
	public function handle_save_document() {
		if ( ! self::es_accion( 'guardar_documento' ) ) {
			return;
		}

		$doc_id = self::entero_enviado( 'documentate_app_doc' );
		self::exigir_nonce( 'documentate_app_guardar_' . $doc_id );

		$post = self::documento_editable_por_el_usuario( $doc_id );
		if ( ! $post ) {
			self::denegar();
		}

		$editar_url = Documentate_App_Editar::url( $post->ID );

		if ( ! Documentate_App_Editar::puede_editar( $post ) ) {
			self::redirigir( $editar_url, array( 'error' => 'bloqueado' ) );
		}

		$titulo = self::titulo_enviado();
		if ( '' === $titulo ) {
			self::redirigir( $editar_url, array( 'error' => 'titulo' ) );
		}

		// Saving keeps the stored status: the fields are persisted first and
		// the transition, when asked for, runs afterwards through the engine.
		$enviar = self::se_envia_a_revision( $post );

		$result = wp_update_post(
			array(
				'ID' => $post->ID,
				'post_title' => $titulo,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			self::redirigir( $editar_url, array( 'error' => 'guardar' ) );
		}

		// The engine refuses the transition when the workflow keeps the
		// document in draft (for instance when it has no type); only leave
		// the editor when it really moved on.
		if ( $enviar ) {
			$clave = Documentate_Documento::con_gestion( $post ) ? 'enviar_gestion' : 'enviar_revision';
			if ( true === Documentate_Transiciones::aplicar( $post->ID, $clave ) ) {
				self::redirigir( Documentate_App_Shell::page_url( array( 'doc' => $post->ID ) ), array( 'enviado' => '1' ) );
			}
		}

		self::redirigir( $editar_url, array( 'guardado' => '1' ) );
	}

	/**
	 * The document the current user may save through the application.
	 *
	 * @param int $doc_id Document ID from the form.
	 * @return WP_Post|null Null when it is not a document the user can edit.
	 */
	private static function documento_editable_por_el_usuario( $doc_id ) {
		if ( ! self::current_user_can_use_app() ) {
			return null;
		}

		$post = get_post( $doc_id );
		if ( ! $post instanceof WP_Post || 'documentate_document' !== $post->post_type ) {
			return null;
		}

		return current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	/**
	 * Whether the form asks to send a draft for review.
	 *
	 * @param WP_Post $post Document being saved.
	 * @return bool
	 */
	private static function se_envia_a_revision( $post ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller has verified the nonce.
		$estado = isset( $_POST['documentate_app_estado'] ) ? sanitize_key( wp_unslash( $_POST['documentate_app_estado'] ) ) : '';

		return 'enviar' === $estado && 'draft' === $post->post_status;
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
			return 'editar' === $vista
				? Documentate_App_Editar::render( $doc )
				: Documentate_App_Detalle::render( $doc );
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
