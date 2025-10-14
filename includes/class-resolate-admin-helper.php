<?php
/**
 * Resolate admin helper bootstrap.
 *
 * @package Resolate
 */

/**
 * Admin helpers for Resolate (export actions, UI additions).
 */
class Resolate_Admin_Helper {

	/**
	 * Track whether the document generator class has been loaded.
	 *
	 * @var bool
	 */
	private $document_generator_loaded = false;

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_post_resolate_export_docx', array( $this, 'handle_export_docx' ) );
		add_action( 'admin_post_resolate_export_odt', array( $this, 'handle_export_odt' ) );
		add_action( 'admin_post_resolate_export_pdf', array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_resolate_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_resolate_preview_stream', array( $this, 'handle_preview_stream' ) );

		// Metabox with action buttons in the edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_actions_metabox' ) );

		// Surface error notices after redirects.
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		// Enhance title field UX for documents CPT.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_title_textarea_assets' ) );
	}

	/**
	 * Ensure the document generator class is available before use.
	 *
	 * @return void
	 */
	private function ensure_document_generator() {
		if ( $this->document_generator_loaded ) {
			return;
		}

		if ( ! class_exists( 'Resolate_Document_Generator' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-document-generator.php';
		}

		$this->document_generator_loaded = true;
	}

	/**
	 * Enqueue JS/CSS to replace title input with a textarea for this CPT only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_title_textarea_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}
		if ( 'resolate_document' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'resolate-title-textarea', plugins_url( 'admin/css/resolate-title.css', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION );
		wp_enqueue_script( 'resolate-title-textarea', plugins_url( 'admin/js/resolate-title.js', RESOLATE_PLUGIN_FILE ), array( 'jquery' ), RESOLATE_VERSION, true );

		// Annexes repeater UI.
		wp_enqueue_script( 'resolate-annexes', plugins_url( 'admin/js/resolate-annexes.js', RESOLATE_PLUGIN_FILE ), array( 'jquery' ), RESOLATE_VERSION, true );
	}

	/**
	 * Add "Exportar DOCX" link to row actions for the Resolate CPT.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function add_row_actions( $actions, $post ) {
		if ( 'resolate_document' !== $post->post_type ) {
			return $actions;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			// Only show DOCX export if a template is configured (global or type-specific).
			$opts = get_option( 'resolate_settings', array() );
			$has_docx_tpl = ! empty( $opts['docx_template_id'] );
			$types = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
				$tid = intval( $types[0] );
				if ( intval( get_term_meta( $tid, 'resolate_type_docx_template', true ) ) > 0 ) {
					$has_docx_tpl = true; }
			}
			if ( $has_docx_tpl ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'resolate_export_docx',
							'post_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'resolate_export_' . $post->ID
				);
				$actions['resolate_export_docx'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Exportar DOCX', 'resolate' ) . '</a>';
			}
		}

		return $actions;
	}

	/**
	 * Handle DOCX export action.
	 */
	public function handle_export_docx() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
		}

		$this->ensure_document_generator();

		$result = Resolate_Document_Generator::generate_docx( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
		$filename   = basename( $result );
		$url        = $baseurl . $filename;

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle ODT export action.
	 */
	public function handle_export_odt() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
		}

		$this->ensure_document_generator();

		$result = Resolate_Document_Generator::generate_odt( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
		$filename   = basename( $result );
		$url        = $baseurl . $filename;

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle PDF export action.
	 */
	public function handle_export_pdf() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
		}

		$this->ensure_document_generator();

		$result = Resolate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
		$filename   = basename( $result );
		$url        = $baseurl . $filename;

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render-only preview of the document in a new tab.
	 */
	public function handle_preview() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_preview_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
		}

		$this->ensure_document_generator();

		$result = Resolate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			if ( 'resolate_conversion_not_available' === $result->get_error_code() ) {
				require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-conversion-manager.php';

				$engine = Resolate_Conversion_Manager::get_engine();
				if ( Resolate_Conversion_Manager::ENGINE_WASM === $engine ) {
					if ( ! class_exists( 'Resolate_Zetajs_Converter' ) ) {
						require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs-converter.php';
					}

					if ( class_exists( 'Resolate_Zetajs_Converter' ) && Resolate_Zetajs_Converter::is_cdn_mode() ) {
						$this->render_browser_workspace( $post_id );
						return;
					}
				}
			}

			$this->render_legacy_preview( $post_id, $result );
			return;
		}

		$title      = get_the_title( $post_id );
		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
		$pdf_url    = $baseurl . basename( $result );
		$print      = isset( $_GET['print'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['print'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stream_url = $this->get_preview_stream_url( $post_id, basename( $result ) );
		$iframe_src = $stream_url ? $stream_url : $pdf_url;

		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		/* translators: %s: document title shown in the PDF preview window. */
		echo '<title>' . esc_html( sprintf( __( 'Vista previa PDF · %s', 'resolate' ), $title ) ) . '</title>';
		echo '<style>
            body{margin:0;font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f4f4f4;color:#111;min-height:100vh;display:flex;flex-direction:column}
            header{background:#1d2327;color:#fff;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px}
            header h1{margin:0;font-size:18px;font-weight:600}
            header a{color:#fff;text-decoration:none;font-weight:500;border:1px solid rgba(255,255,255,.4);padding:6px 12px;border-radius:4px}
            header a:hover{background:rgba(255,255,255,.1)}
            main{flex:1;display:flex;padding:12px}
            .viewer{flex:1;box-shadow:0 0 0 1px #d0d0d0,0 16px 32px rgba(0,0,0,.12);background:#000;border-radius:6px;overflow:hidden}
            iframe{border:0;width:100%;height:100%}
            body.loading .viewer::after{content:"' . esc_js( __( 'Cargando PDF…', 'resolate' ) ) . '";color:#fff;font-size:16px;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6)}
        </style>';
		echo '</head><body class="loading">';
		echo '<header><h1>' . esc_html( $title ) . '</h1><div class="actions">';
		echo '<a href="' . esc_url( $pdf_url ) . '" download>' . esc_html__( 'Descargar PDF', 'resolate' ) . '</a>';
		echo '</div></header>';
		echo '<main><div class="viewer"><iframe id="resolate-pdf-frame" src="' . esc_url( $iframe_src ) . '" title="' . esc_attr__( 'Documento en PDF', 'resolate' ) . '"></iframe></div></main>';
		echo '<script>document.getElementById("resolate-pdf-frame").addEventListener("load",function(){document.body.classList.remove("loading");});</script>';
		if ( $print ) {
			echo '<script>(function(){const frame=document.getElementById("resolate-pdf-frame");frame.addEventListener("load",function(){try{frame.contentWindow.focus();frame.contentWindow.print();}catch(e){console.error(e);}});})();</script>';
		}
		echo '</body></html>';
		exit;
	}

	/**
	 * Stream the generated PDF inline so browsers can render it inside an iframe.
	 *
	 * @return void
	 */
	public function handle_preview_stream() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_preview_stream_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'Usuario no autenticado.', 'resolate' ) );
		}

		$key      = $this->get_preview_stream_transient_key( $post_id, $user_id );
		$filename = get_transient( $key );

		if ( false === $filename || '' === $filename ) {
			$this->ensure_document_generator();
			$result = Resolate_Document_Generator::generate_pdf( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html__( 'No se pudo generar el PDF para la vista previa.', 'resolate' ) );
			}

			$filename = basename( $result );
			$this->remember_preview_stream_file( $post_id, $filename );
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			wp_die( esc_html__( 'Archivo de vista previa no disponible.', 'resolate' ) );
		}

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'resolate/' . $filename;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'No se pudo acceder al archivo PDF generado.', 'resolate' ) );
		}

		$filesize       = filesize( $path );
		$download_name  = wp_basename( $filename );
		$encoded_name   = rawurlencode( $download_name );
		$disposition    = 'inline; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name;

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'No se pudo leer el archivo PDF.', 'resolate' ) );
		}

		while ( ! feof( $handle ) ) {
			echo fread( $handle, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming PDF binary data.
		}
		fclose( $handle );
		exit;
	}

	/**
	 * Store the generated filename so the streaming endpoint can serve it inline.
	 *
	 * @param int    $post_id  Document post ID.
	 * @param string $filename Generated filename.
	 * @return bool
	 */
	private function remember_preview_stream_file( $post_id, $filename ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			return false;
		}

		$ttl = defined( 'MINUTE_IN_SECONDS' ) ? 10 * MINUTE_IN_SECONDS : 600;
		set_transient( $this->get_preview_stream_transient_key( $post_id, $user_id ), $filename, $ttl );

		return true;
	}

	/**
	 * Build the streaming URL for the preview iframe.
	 *
	 * @param int    $post_id  Document post ID.
	 * @param string $filename Generated filename.
	 * @return string
	 */
	private function get_preview_stream_url( $post_id, $filename ) {
		if ( ! $this->remember_preview_stream_file( $post_id, $filename ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'   => 'resolate_preview_stream',
				'post_id'  => $post_id,
				'_wpnonce' => wp_create_nonce( 'resolate_preview_stream_' . $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Generate the transient key used to remember the preview filename.
	 *
	 * @param int $post_id Document post ID.
	 * @param int $user_id Current user ID.
	 * @return string
	 */
	private function get_preview_stream_transient_key( $post_id, $user_id ) {
		return 'resolate_preview_stream_' . absint( $user_id ) . '_' . absint( $post_id );
	}

	/**
	 * Render the browser-based workspace when using ZetaJS CDN mode.
	 *
	 * @param int $post_id Document post ID.
	 * @return void
	 */
	private function render_browser_workspace( $post_id ) {
		if ( ! class_exists( 'Resolate_Zetajs_Converter' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs-converter.php';
		}

		if ( ! class_exists( 'Resolate_Zetajs_Converter' ) || ! Resolate_Zetajs_Converter::is_cdn_mode() ) {
			$this->render_legacy_preview( $post_id );
			return;
		}

		$title          = get_the_title( $post_id );
		$base           = admin_url( 'admin-post.php' );
		$export_nonce   = wp_create_nonce( 'resolate_export_' . $post_id );
		$preview_nonce  = wp_create_nonce( 'resolate_preview_' . $post_id );
		$edit_link      = get_edit_post_link( $post_id, 'url' );
		$preview_url    = add_query_arg(
			array(
				'action'   => 'resolate_preview',
				'post_id'  => $post_id,
				'_wpnonce' => $preview_nonce,
			),
			$base
		);
		$docx_url       = add_query_arg(
			array(
				'action'   => 'resolate_export_docx',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);
		$odt_url        = add_query_arg(
			array(
				'action'   => 'resolate_export_odt',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);
		$pdf_url        = add_query_arg(
			array(
				'action'   => 'resolate_export_pdf',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);

		$this->ensure_document_generator();

		$docx_template = Resolate_Document_Generator::get_template_path( $post_id, 'docx' );
		$odt_template  = Resolate_Document_Generator::get_template_path( $post_id, 'odt' );

		$zetajs_ready = class_exists( 'Resolate_Zetajs_Converter' ) && Resolate_Zetajs_Converter::is_available();

		$docx_available = ( '' !== $docx_template ) || ( '' !== $odt_template && $zetajs_ready );
		$odt_available  = ( '' !== $odt_template ) || ( '' !== $docx_template && $zetajs_ready );
		$pdf_available  = $zetajs_ready && ( '' !== $docx_template || '' !== $odt_template );

		$docx_message = '' === $docx_template && '' !== $odt_template
			? __( 'Configura ZetaJS para convertir tu plantilla ODT a DOCX.', 'resolate' )
			: __( 'Configura una plantilla DOCX en el tipo de documento.', 'resolate' );
		$odt_message = '' === $odt_template && '' !== $docx_template
			? __( 'Configura ZetaJS para convertir tu plantilla DOCX a ODT.', 'resolate' )
			: __( 'Configura una plantilla ODT en el tipo de documento.', 'resolate' );
		$pdf_message = __( 'Instala ZetaJS y configura RESOLATE_ZETAJS_BIN para habilitar la conversión a PDF.', 'resolate' );
		if ( '' === $docx_template && '' === $odt_template ) {
			$pdf_message = __( 'Configura una plantilla DOCX u ODT en el tipo de documento antes de generar el PDF.', 'resolate' );
		}

		$steps = array(
			'docx' => array(
				'label'     => __( 'Generar DOCX', 'resolate' ),
				'available' => $docx_available,
				'href'      => $docx_url,
				'type'      => 'docx',
				'message'   => $docx_message,
			),
			'odt'  => array(
				'label'     => __( 'Generar ODT', 'resolate' ),
				'available' => $odt_available,
				'href'      => $odt_url,
				'type'      => 'odt',
				'message'   => $odt_message,
			),
			'pdf'  => array(
				'label'     => __( 'Generar PDF', 'resolate' ),
				'available' => $pdf_available,
				'href'      => $pdf_url,
				'type'      => 'pdf',
				'message'   => $pdf_message,
			),
		);

		$cdn_base = Resolate_Zetajs_Converter::get_cdn_base_url();

		$loader_config = array(
			'baseUrl'         => $cdn_base,
			'loadingText'     => __( 'Cargando LibreOffice…', 'resolate' ),
			'errorText'       => __( 'No se pudo cargar LibreOffice.', 'resolate' ),
			'pendingSelector' => '[data-zetajs-disabled]',
			'readyEvent'      => 'resolateZeta:ready',
			'errorEvent'      => 'resolateZeta:error',
			'assets'          => array(
				array(
					'href' => 'soffice.wasm',
					'as'   => 'fetch',
				),
				array(
					'href' => 'soffice.data',
					'as'   => 'fetch',
				),
			),
		);

		$workspace_config = array(
			'events'      => array(
				'ready' => 'resolateZeta:ready',
				'error' => 'resolateZeta:error',
			),
			'frameTarget' => 'resolateExportFrame',
			'strings'     => array(
				'loaderLoading' => __( 'Cargando LibreOffice…', 'resolate' ),
				'loaderReady'   => __( 'LibreOffice cargado.', 'resolate' ),
				'loaderError'   => __( 'No se pudo cargar LibreOffice.', 'resolate' ),
				'stepPending'   => __( 'En espera…', 'resolate' ),
				'stepReady'     => __( 'Listo para generar.', 'resolate' ),
				'stepWorking'   => __( 'Generando…', 'resolate' ),
				'stepDone'      => __( 'Descarga preparada.', 'resolate' ),
			),
		);

		$workspace_class = 'resolate-export-workspace';

		$style_handle  = 'resolate-export-workspace';
		$loader_handle = 'resolate-zetajs-loader';
		$app_handle    = 'resolate-export-workspace-app';

		wp_enqueue_style( $style_handle, plugins_url( 'admin/css/resolate-export-workspace.css', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION );
		wp_enqueue_script( $loader_handle, plugins_url( 'admin/js/resolate-zetajs-loader.js', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( $loader_handle, 'type', 'module' );
		}
		wp_enqueue_script( $app_handle, plugins_url( 'admin/js/resolate-export-workspace.js', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( $app_handle, 'script_execution', 'defer' );
		}

		wp_add_inline_script( $loader_handle, 'window.resolateZetaLoaderConfig = ' . wp_json_encode( $loader_config ) . ';', 'before' );
		wp_add_inline_script( $app_handle, 'window.resolateExportWorkspaceConfig = ' . wp_json_encode( $workspace_config ) . ';', 'before' );

		$styles_html  = '';
		$scripts_html = '';
		if ( function_exists( 'wp_print_styles' ) ) {
			ob_start();
			wp_print_styles( array( $style_handle ) );
			$styles_html = ob_get_clean();
		}
		if ( function_exists( 'wp_print_scripts' ) ) {
			ob_start();
			wp_print_scripts( array( $loader_handle, $app_handle ) );
			$scripts_html = ob_get_clean();
		}

		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		/* translators: %s: document title shown in the export workspace window. */
		echo '<title>' . esc_html( sprintf( __( 'Previsualizar y exportar · %s', 'resolate' ), $title ) ) . '</title>';
		if ( '' !== $styles_html ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core prints sanitized style tags.
			echo $styles_html;
		}
		echo '</head><body class="' . esc_attr( $workspace_class ) . '">';

		echo '<div class="resolate-export-workspace__layout">';
		echo '<header class="resolate-export-workspace__header">';
		echo '<div class="resolate-export-workspace__headline">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html__( 'Convierte el documento con LibreOffice cargado desde la CDN oficial.', 'resolate' ) . '</p>';
		echo '</div>';
		if ( $edit_link ) {
			echo '<div class="resolate-export-workspace__header-actions">';
			echo '<a class="button" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Volver al editor', 'resolate' ) . '</a>';
			echo '</div>';
		}
		echo '</header>';

		echo '<main class="resolate-export-workspace__content">';
		echo '<section class="resolate-export-workspace__preview">';
		echo '<iframe src="' . esc_url( $preview_url ) . '" title="' . esc_attr__( 'Vista previa del documento', 'resolate' ) . '" loading="lazy"></iframe>';
		echo '</section>';

		echo '<aside class="resolate-export-workspace__panel">';
		echo '<div class="resolate-export-workspace__status" data-resolate-workspace-status>' . esc_html__( 'Cargando LibreOffice…', 'resolate' ) . '</div>';
		echo '<p class="resolate-export-workspace__intro">' . esc_html__( 'Cuando LibreOffice esté listo podrás descargar el formato que necesites.', 'resolate' ) . '</p>';

		echo '<ul class="resolate-export-workspace__steps">';
		echo '<li class="resolate-export-workspace__step is-active" data-resolate-step="loader" data-resolate-step-available="1">';
		echo '<span class="resolate-export-workspace__step-title">' . esc_html__( 'Cargando LibreOffice', 'resolate' ) . '</span>';
		echo '<span class="resolate-export-workspace__step-status" data-resolate-step-status>' . esc_html__( 'En espera…', 'resolate' ) . '</span>';
		echo '</li>';
		foreach ( $steps as $key => $data ) {
			$available_attr = $data['available'] ? '1' : '0';
			$classes        = 'resolate-export-workspace__step';
			$classes       .= $data['available'] ? ' is-pending' : ' is-disabled';
			echo '<li class="' . esc_attr( $classes ) . '" data-resolate-step="' . esc_attr( $key ) . '" data-resolate-step-available="' . esc_attr( $available_attr ) . '">';
			echo '<span class="resolate-export-workspace__step-title">' . esc_html( $data['label'] ) . '</span>';
			if ( $data['available'] ) {
				echo '<span class="resolate-export-workspace__step-status" data-resolate-step-status>' . esc_html__( 'En espera…', 'resolate' ) . '</span>';
			} else {
				echo '<span class="resolate-export-workspace__step-status">' . esc_html( $data['message'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="resolate-export-workspace__buttons">';
		foreach ( $steps as $key => $data ) {
			if ( $data['available'] ) {
				$attrs = array(
					'class'                => 'button button-secondary disabled',
					'href'                 => $data['href'],
					'aria-disabled'        => 'true',
					'data-zetajs-disabled' => '1',
					'data-zetajs-type'     => $data['type'],
					'data-resolate-step-target' => $key,
					'target'               => 'resolateExportFrame',
				);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<a ' . $this->build_action_attributes( $attrs ) . '>' . esc_html( $data['label'] ) . '</a>';
			} else {
				echo '<button type="button" class="button" disabled>' . esc_html( $data['label'] ) . '</button>';
			}
		}
		echo '</div>';

		echo '<p class="resolate-export-workspace__note">' . esc_html__( 'Las descargas se abrirán en segundo plano o se guardarán según la configuración de tu navegador.', 'resolate' ) . '</p>';
		echo '<iframe class="resolate-export-workspace__frame" name="resolateExportFrame" title="' . esc_attr__( 'Descargas de exportación', 'resolate' ) . '" hidden></iframe>';
		echo '</aside>';
		echo '</main>';
		echo '</div>';

		if ( '' !== $scripts_html ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core prints sanitized script tags.
			echo $scripts_html;
		}
		echo '</body></html>';
		exit;
	}

	/**
	 * Legacy HTML preview used when PDF generation is not available.
	 *
	 * @param int           $post_id Post ID.
	 * @param WP_Error|null $error   Optional error to show to the user.
	 * @return void
	 */
	private function render_legacy_preview( $post_id, $error = null ) {
		$title = get_the_title( $post_id );
		$opts  = get_option( 'resolate_settings', array() );
		$font  = isset( $opts['doc_font_family'] ) ? sanitize_text_field( $opts['doc_font_family'] ) : 'Times New Roman';
		$size  = isset( $opts['doc_font_size'] ) ? intval( $opts['doc_font_size'] ) : 12;
		$logo       = isset( $opts['doc_logo_id'] ) ? intval( $opts['doc_logo_id'] ) : 0;
		$logo_r     = isset( $opts['doc_logo_right_id'] ) ? intval( $opts['doc_logo_right_id'] ) : 0;
		$types = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$tid = intval( $types[0] );
			$t_font = sanitize_text_field( (string) get_term_meta( $tid, 'resolate_type_font_name', true ) );
			$t_size = intval( get_term_meta( $tid, 'resolate_type_font_size', true ) );
			if ( '' !== $t_font ) {
				$font = $t_font; }
			if ( $t_size > 0 ) {
				$size = $t_size; }
			$t_logos = get_term_meta( $tid, 'resolate_type_logos', true );
			if ( is_array( $t_logos ) && ! empty( $t_logos ) ) {
				$logo   = intval( $t_logos[0] );
				$logo_r = isset( $t_logos[1] ) ? intval( $t_logos[1] ) : $logo_r;
			}
		}
		$logo_url   = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
		$logo_r_url = $logo_r ? wp_get_attachment_image_url( $logo_r, 'full' ) : '';
		$lw = isset( $opts['doc_logo_left_width'] ) ? intval( $opts['doc_logo_left_width'] ) : 220;
		$rw = isset( $opts['doc_logo_right_width'] ) ? intval( $opts['doc_logo_right_width'] ) : 160;
		$margin_txt = isset( $opts['doc_margin_text'] ) ? wp_kses_post( $opts['doc_margin_text'] ) : '';
		$structured_fields = array();
		$schema_fields     = array();
		if ( class_exists( 'Resolate_Documents' ) ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$structured_fields = Resolate_Documents::parse_structured_content( $post->post_content );
			}
		}

		if ( ! is_wp_error( $types ) && ! empty( $types ) && class_exists( 'Resolate_Documents' ) ) {
			$schema_fields = Resolate_Documents::get_term_schema( intval( $types[0] ) );
		}

		$humanize = static function ( $slug ) {
			$label = str_replace( array( '-', '_' ), ' ', (string) $slug );
			$label = preg_replace( '/\s+/', ' ', $label );
			$label = trim( $label );
			if ( '' === $label ) {
				return '';
			}
			return ucwords( $label );
		};

		$fields_to_render = array();
		if ( ! empty( $schema_fields ) ) {
			foreach ( $schema_fields as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$slug  = sanitize_key( $def['slug'] );
				$label = isset( $def['label'] ) ? sanitize_text_field( $def['label'] ) : '';
				if ( '' === $slug ) {
					continue;
				}
				if ( '' === $label ) {
					$label = $humanize( $slug );
				}
				if ( '' === $label ) {
					continue;
				}
				$fields_to_render[] = array(
					'slug'  => $slug,
					'label' => $label,
				);
			}
		} elseif ( ! empty( $structured_fields ) ) {
			foreach ( $structured_fields as $slug => $info ) {
				$slug  = sanitize_key( $slug );
				if ( '' === $slug ) {
					continue;
				}
				$label = $humanize( $slug );
				if ( '' === $label ) {
					continue;
				}
				$fields_to_render[] = array(
					'slug'  => $slug,
					'label' => $label,
				);
			}
		}

		foreach ( $fields_to_render as $field ) {
			$slug  = $field['slug'];
			$label = $field['label'];
			$value = '';
			if ( isset( $structured_fields[ $slug ]['value'] ) ) {
				$value = (string) $structured_fields[ $slug ]['value'];
			} else {
				$meta_key = 'resolate_field_' . $slug;
				$value    = (string) get_post_meta( $post_id, $meta_key, true );
			}
			$value = preg_replace( '/<\/?font[^>]*>/i', '', $value );
			$value = preg_replace( '/font-family\s*:\s*[^;\"\']+;?/i', '', $value );
			$value = preg_replace( '/font-size\s*:\s*[^;\"\']+;?/i', '', $value );
			$value = str_replace( '&nbsp;', ' ', $value );
			$value = trim( $value );
			if ( '' !== $value ) {
				echo '<section data-avoid-break="1"><h2>' . esc_html( $label ) . '</h2>';
				echo '<div class="section-content">' . wp_kses_post( $value ) . '</div></section>';
			}
		}

		$annexes = get_post_meta( $post_id, 'resolate_annexes', true );
		if ( is_array( $annexes ) && ! empty( $annexes ) ) {
			$roman = function ( $num ) {
				$map = array(
					'M'  => 1000,
					'CM' => 900,
					'D'  => 500,
					'CD' => 400,
					'C'  => 100,
					'XC' => 90,
					'L'  => 50,
					'XL' => 40,
					'X'  => 10,
					'IX' => 9,
					'V'  => 5,
					'IV' => 4,
					'I'  => 1,
				);
				$res = '';
				foreach ( $map as $rom => $int ) {
					while ( $num >= $int ) {
						$res .= $rom;
						$num -= $int; }
				}
				return $res;
			};

			foreach ( $annexes as $i => $anx ) {
				$t = isset( $anx['title'] ) ? (string) $anx['title'] : '';
				$c = isset( $anx['text'] ) ? (string) $anx['text'] : '';
				$c = preg_replace( '/<\\/?font[^>]*>/i', '', (string) $c );
				$c = preg_replace( '/font-family\\s*:\\s*[^;"\']+;?/i', '', (string) $c );
				$c = preg_replace( '/font-size\\s*:\\s*[^;"\']+;?/i', '', (string) $c );
				if ( '' === trim( $t ) && '' === trim( wp_strip_all_tags( (string) $c ) ) ) {
					continue;
				}
				$label = sprintf( 'Anexo %s', $roman( $i + 1 ) );
				echo '<section data-new-page="1" data-avoid-break="1">';
				echo '<h1>' . esc_html( $label ) . '</h1>';
				if ( '' !== trim( $t ) ) {
					echo '<h1>' . esc_html( $t ) . '</h1>';
				}
				echo wp_kses_post( $c );
				echo '</section>';
			}
		}
		echo '</div>';
		echo '<div class="pages" id="pages"></div>';
		echo '<script>(function(){
          const pageW = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-w"));
          const pageH = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-h"));
          const padTop = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-pad-top"));
          const pad = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-pad"));
          const footerH = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--footer-h"));
          const flow = document.getElementById("content-flow");
          const pages = document.getElementById("pages");
          function newPage(){
            const p = document.createElement("div"); p.className="page";
            const inner = document.createElement("div"); inner.className="page-inner";
            const footer = document.createElement("div"); footer.className="page-footer";
            p.appendChild(inner); p.appendChild(footer); pages.appendChild(p);
            return {p, inner, footer};
          }
          let cur = newPage(); let num=1;
          function setFooter(){ cur.footer.textContent = "Página " + num; }
          setFooter();
          const blocks = Array.from(flow.children);
          for (const el of blocks){
            if (el.hasAttribute("data-new-page") && (cur.inner.childElementCount > 0)) {
              num++;
              cur = newPage();
              setFooter();
            }
            cur.inner.appendChild(el.cloneNode(true));
            const maxH = cur.inner.clientHeight;
            if (cur.inner.scrollHeight > maxH){
              cur.inner.removeChild(cur.inner.lastChild);
              num++;
              cur = newPage();
              setFooter();
              cur.inner.appendChild(el.cloneNode(true));
            }
          }
          flow.remove();
          const params = new URLSearchParams(window.location.search);
          if (params.get("print") === "1") {
            setTimeout(function(){ window.print(); }, 300);
          }
        })();</script>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Add actions metabox to the edit screen.
	 */
	public function add_actions_metabox() {
		add_meta_box(
			'resolate_actions',
			__( 'Acciones del documento', 'resolate' ),
			array( $this, 'render_actions_metabox' ),
			'resolate_document',
			'side',
			'high'
		);
	}

	/**
	 * Render action buttons: Preview, DOCX, ODT, PDF.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_actions_metabox( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'Permisos insuficientes.', 'resolate' ) . '</p>';
			return;
		}

		$nonce_export = wp_create_nonce( 'resolate_export_' . $post->ID );
		$nonce_prev   = wp_create_nonce( 'resolate_preview_' . $post->ID );

		$base = admin_url( 'admin-post.php' );

		$preview = add_query_arg(
			array(
				'action'  => 'resolate_preview',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_prev,
			),
			$base
		);
		$docx    = add_query_arg(
			array(
				'action' => 'resolate_export_docx',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);
		$pdf     = add_query_arg(
			array(
				'action' => 'resolate_export_pdf',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);
		$odt     = add_query_arg(
			array(
				'action' => 'resolate_export_odt',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);

		$this->ensure_document_generator();

		$docx_template = Resolate_Document_Generator::get_template_path( $post->ID, 'docx' );
		$odt_template  = Resolate_Document_Generator::get_template_path( $post->ID, 'odt' );

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-conversion-manager.php';

		$conversion_ready         = Resolate_Conversion_Manager::is_available();
		$engine_label             = Resolate_Conversion_Manager::get_engine_label();
		$docx_requires_conversion = ( '' === $docx_template && '' !== $odt_template );
		$odt_requires_conversion  = ( '' === $odt_template && '' !== $docx_template );

		$docx_available = ( '' !== $docx_template ) || ( $docx_requires_conversion && $conversion_ready );
		$odt_available  = ( '' !== $odt_template ) || ( $odt_requires_conversion && $conversion_ready );
		$pdf_available  = $conversion_ready && ( '' !== $docx_template || '' !== $odt_template );

		$docx_message = __( 'Configura una plantilla DOCX en el tipo de documento.', 'resolate' );
		if ( $docx_requires_conversion && ! $conversion_ready ) {
			$docx_message = Resolate_Conversion_Manager::get_unavailable_message( 'odt', 'docx' );
		}

		$odt_message = __( 'Configura una plantilla ODT en el tipo de documento.', 'resolate' );
		if ( $odt_requires_conversion && ! $conversion_ready ) {
			$odt_message = Resolate_Conversion_Manager::get_unavailable_message( 'docx', 'odt' );
		}

		if ( '' === $docx_template && '' === $odt_template ) {
			$pdf_message = __( 'Configura una plantilla DOCX u ODT en el tipo de documento antes de generar el PDF.', 'resolate' );
		} else {
			$source_for_pdf = '' !== $docx_template ? 'docx' : 'odt';
			$pdf_message    = Resolate_Conversion_Manager::get_unavailable_message( $source_for_pdf, 'pdf' );
		}

		$preview_available = $pdf_available;
		$preview_message   = $pdf_message;

		$preferred_format = '';
		$types            = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_id         = intval( $types[0] );
			$template_format = sanitize_key( (string) get_term_meta( $type_id, 'resolate_type_template_type', true ) );
			if ( in_array( $template_format, array( 'docx', 'odt' ), true ) ) {
				$preferred_format = $template_format;
			}
		}
		if ( '' === $preferred_format ) {
			if ( '' !== $docx_template ) {
				$preferred_format = 'docx';
			} elseif ( '' !== $odt_template ) {
				$preferred_format = 'odt';
			}
		}

		echo '<p>';
		if ( $preview_available ) {
			$preview_attrs = array(
				'class'  => 'button button-secondary',
				'href'   => $preview,
				'target' => '_blank',
				'rel'    => 'noopener',
			);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
			echo '<a ' . $this->build_action_attributes( $preview_attrs ) . '>' . esc_html__( 'Previsualizar', 'resolate' ) . '</a>';
		} else {
			echo '<button type="button" class="button button-secondary" disabled title="' . esc_attr( $preview_message ) . '">' . esc_html__( 'Previsualizar', 'resolate' ) . '</button>';
		}
		echo '</p>';

		$buttons = array(
			'docx' => array(
				'href'      => $docx,
				'available' => $docx_available,
				'message'   => $docx_message,
				'primary'   => ( 'docx' === $preferred_format ),
				'label'     => 'DOCX',
			),
			'odt'  => array(
				'href'      => $odt,
				'available' => $odt_available,
				'message'   => $odt_message,
				'primary'   => ( 'odt' === $preferred_format ),
				'label'     => 'ODT',
			),
			'pdf'  => array(
				'href'      => $pdf,
				'available' => $pdf_available,
				'message'   => $pdf_message,
				'primary'   => false,
				'label'     => 'PDF',
			),
		);

		echo '<p>';
		foreach ( array( 'docx', 'odt', 'pdf' ) as $format ) {
			$data  = $buttons[ $format ];
			$class = $data['primary'] ? 'button button-primary' : 'button';
			if ( $data['available'] ) {
				$attrs = array(
					'class' => $class,
					'href'  => $data['href'],
				);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<a ' . $this->build_action_attributes( $attrs ) . '>' . esc_html( $data['label'] ) . '</a> ';
			} else {
				$title_attr    = '';
				$title_message = isset( $data['message'] ) ? $data['message'] : '';
				if ( '' !== $title_message ) {
					$title_attr = sanitize_text_field( $title_message );
				}
				$button_attrs = array(
					'type'     => 'button',
					'class'    => $class,
					'disabled' => 'disabled',
				);
				if ( '' !== $title_attr ) {
					$button_attrs['title'] = $title_attr;
				}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<button ' . $this->build_action_attributes( $button_attrs ) . '>' . esc_html( $data['label'] ) . '</button> ';
			}
		}
		echo '</p>';

		/* translators: %s: converter engine label. */
		echo '<p class="description">' . sprintf( esc_html__( 'Las conversiones adicionales se realizan con %s.', 'resolate' ), esc_html( $engine_label ) ) . '</p>';
	}

	/**
	 * Build a HTML attribute string for action buttons.
	 *
	 * @param array $attributes Attributes to render.
	 * @return string
	 */
	private function build_action_attributes( array $attributes ) {
		$pairs = array();
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value && 'href' !== $name ) {
				continue;
			}
			$attr_name = esc_attr( $name );
			if ( 'href' === $name ) {
				$pairs[] = sprintf( '%s="%s"', $attr_name, esc_url( $value ) );
			} else {
				$pairs[] = sprintf( '%s="%s"', $attr_name, esc_attr( $value ) );
			}
		}
		return implode( ' ', $pairs );
	}

	/**
	 * Show admin notice if redirected with an error.
	 */
	public function maybe_notice() {
		if ( empty( $_GET['resolate_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'resolate_document' !== $screen->id && 'post' !== $screen->base ) {
			// Only show in edit screens.
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['resolate_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}

new Resolate_Admin_Helper();
