<?php
/**
 * Front-end application of Documentate, served under /documentate/.
 *
 * One WordPress page carries the [documentate_app] shortcode; the views are
 * resolved from query arguments (vista, doc, bandeja, estado, area) so the
 * whole application lives under a single URL. Access is capability-gated: the
 * app is for logged-in users who can edit documents.
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
		add_action( 'template_redirect', array( 'Documentate_App_Actions', 'handle_create_document' ) );
		add_action( 'template_redirect', array( 'Documentate_App_Actions', 'handle_save_document' ) );
		add_action( 'template_redirect', array( 'Documentate_App_Actions', 'handle_transition' ) );
		add_action( 'template_redirect', array( 'Documentate_App_Actions', 'handle_comment' ) );
		Documentate_App_Attachments::init();
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
				'meta' => array( 'title' => 'Abrir la aplicación Documentate' ),
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
	 * Every view gets the stylesheet, the dashicons the chips and cards use
	 * and the small progressive-enhancement script. A document view also gets
	 * the export controls of wp-admin, and the edit view the classic editor
	 * (rich fields), the repeater script and the automatic totals.
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
			array( 'dashicons' ),
			DOCUMENTATE_VERSION,
		);

		wp_enqueue_script(
			'documentate-app',
			plugins_url( 'public/js/documentate-app.js', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION,
			true,
		);

		$this->enqueue_document_assets();
	}

	/**
	 * Enqueue what the document views need on top of the shell assets.
	 *
	 * @return void
	 */
	private function enqueue_document_assets() {
		$doc = self::requested_document();
		if ( $doc <= 0 || ! current_user_can( 'edit_post', $doc ) ) {
			return;
		}

		$helper = Documentate_Admin_Helper::instance();
		if ( $helper instanceof Documentate_Admin_Helper ) {
			$helper->enqueue_actions_assets_for_post( $doc, 'form.dcta-editor' );
		}

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
			'documentate-calculations',
			plugins_url( 'admin/js/documentate-calculations.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'documentate-annexes' ),
			DOCUMENTATE_VERSION,
			true,
		);
	}

	/**
	 * Document ID the request asks for, 0 when the view carries none.
	 *
	 * @return int
	 */
	private static function requested_document() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		return isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0;
	}

	/**
	 * Whether the request asks for the edit view of a document.
	 *
	 * @return bool
	 */
	private static function is_edit_view_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$view = isset( $_GET['vista'] ) ? sanitize_key( wp_unslash( $_GET['vista'] ) ) : '';

		return 'editar' === $view && self::requested_document() > 0;
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
	 * Thin delegate: the handlers live in Documentate_App_Actions.
	 *
	 * @return void
	 */
	public function handle_create_document() {
		Documentate_App_Actions::handle_create_document();
	}

	/**
	 * Persist the edit form and redirect.
	 *
	 * Thin delegate: the handlers live in Documentate_App_Actions.
	 *
	 * @return void
	 */
	public function handle_save_document() {
		Documentate_App_Actions::handle_save_document();
	}

	/**
	 * Render the application: gate, then the view the query asks for.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! is_user_logged_in() ) {
			return Documentate_App_Shell::open( '', 'Documentate', '' )
				. '<div class="dcta-aviso">Inicia sesión para trabajar con tus documentos.'
				. ' <a href="' . esc_url( wp_login_url( Documentate_App_Shell::page_url() ) ) . '">Iniciar sesión</a>'
				. '</div>'
				. Documentate_App_Shell::close();
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return Documentate_App_Shell::open( '', 'Documentate', '' )
				. '<div class="dcta-aviso">Tu usuario no puede editar documentos. Contacta con administración.</div>'
				. Documentate_App_Shell::close();
		}

		$doc = self::requested_document();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view routing.
		$view = isset( $_GET['vista'] ) ? sanitize_key( wp_unslash( $_GET['vista'] ) ) : '';

		if ( $doc > 0 ) {
			return 'editar' === $view
				? Documentate_App_Edit::render( $doc )
				: Documentate_App_Detail::render( $doc );
		}

		if ( 'nuevo' === $view ) {
			return $this->render_new();
		}

		return Documentate_App_List::render();
	}

	/**
	 * Render the "new document" form.
	 *
	 * @return string
	 */
	private function render_new() {
		$types = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);
		$types = is_wp_error( $types ) ? array() : $types;

		$error = Documentate_App_Detail::flag( 'error' );

		$html = Documentate_App_Shell::open(
			'nuevo',
			'Nuevo documento',
			'Elige el tipo y ponle nombre; el tipo no se puede cambiar después.'
		);

		if ( '' !== $error ) {
			$html .= '<div class="dcta-aviso dcta-aviso-mal">No se pudo crear el documento. Revisa el tipo, el nombre y el título.</div>';
		}

		if ( empty( $types ) ) {
			return $html
				. '<div class="dcta-aviso">No hay tipos de documento definidos. Los crea administración en el escritorio de WordPress, en Documentos → Tipos de documento.</div>'
				. Documentate_App_Shell::close();
		}

		return $html . $this->new_form( $types ) . Documentate_App_Shell::close();
	}

	/**
	 * The "new document" form itself.
	 *
	 * @param WP_Term[] $types Document types.
	 * @return string
	 */
	private function new_form( array $types ) {
		ob_start();
		?>
		<form class="dcta-form" method="post" action="">
			<?php wp_nonce_field( 'documentate_app_crear', 'documentate_app_nonce' ); ?>
			<input type="hidden" name="documentate_app_accion" value="crear_documento" />

			<div class="dcta-campo">
				<label for="documentate-app-tipo">Tipo de documento</label>
				<select id="documentate-app-tipo" name="documentate_app_tipo" required>
					<option value="">Elige un tipo…</option>
					<?php foreach ( $types as $type ) : ?>
						<option value="<?php echo esc_attr( (string) $type->term_id ); ?>"
							data-prefijo="<?php echo esc_attr( Documentate_Document_Data::prefix_for_type( $type->term_id ) ); ?>"
							data-gestion="<?php echo esc_attr( Documentate_Document_Data::type_has_management( $type->term_id ) ? '1' : '' ); ?>"><?php echo esc_html( $type->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="dcta-ayuda" id="documentate-app-tipo-nota"></p>
			</div>

			<div class="dcta-campo">
				<label for="documentate-app-nombre">Nombre interno</label>
				<span class="dcta-prefijo-grupo">
					<span class="dcta-prefijo" id="documentate-app-prefijo" hidden></span>
					<input type="text" id="documentate-app-nombre" name="documentate_app_nombre" required maxlength="80" />
				</span>
				<p class="dcta-ayuda">Corto: es el que verás en las listas. El prefijo lo pone el tipo; no aparece en el documento.</p>
			</div>

			<div class="dcta-campo">
				<label for="documentate-app-titulo">Título oficial</label>
				<textarea id="documentate-app-titulo" name="documentate_app_titulo" rows="2" required maxlength="500"></textarea>
				<p class="dcta-ayuda">El título completo tal y como saldrá en el documento.</p>
			</div>

			<button type="submit" class="dcta-btn dcta-btn-pri">Crear borrador</button>
			<a class="dcta-btn dcta-btn-ton" href="<?php echo esc_url( Documentate_App_Shell::page_url() ); ?>">Cancelar</a>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
