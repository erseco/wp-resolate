<?php
/**
 * Documentate admin helper bootstrap.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

use Documentate\Export\Export_DOCX_Handler;
use Documentate\Export\Export_ODT_Handler;
use Documentate\Export\Export_PDF_Handler;

/**
 * Admin helpers for Documentate (export actions, UI additions).
 *
 * Uses specialized Export handlers for document export functionality.
 */
class Documentate_Admin_Helper {
	/**
	 * DOCX export handler.
	 *
	 * @var Export_DOCX_Handler|null
	 */
	private $docx_handler;

	/**
	 * ODT export handler.
	 *
	 * @var Export_ODT_Handler|null
	 */
	private $odt_handler;

	/**
	 * PDF export handler.
	 *
	 * @var Export_PDF_Handler|null
	 */
	private $pdf_handler;

	/**
	 * Track whether the document generator class has been loaded.
	 *
	 * @var bool
	 */
	private $document_generator_loaded = false;

	/**
	 * Format to generator method mapping.
	 *
	 * @var array<string, string>
	 */
	private static $format_generator_map = array(
		'docx' => 'generate_docx',
		'odt' => 'generate_odt',
		'pdf' => 'generate_pdf',
	);

	/**
	 * Get an initialized WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|WP_Error Filesystem handler or error on failure.
	 */
	private function get_wp_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		// Ensure the Filesystem API is available and attempt to initialize it.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'documentate_fs_unavailable',
				__(
					'Could not initialize the WordPress filesystem.',
					'documentate',
				)
			);
		}

		return $wp_filesystem;
	}

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		// Initialize export handlers.
		$this->docx_handler = new Export_DOCX_Handler();
		$this->odt_handler = new Export_ODT_Handler();
		$this->pdf_handler = new Export_PDF_Handler();

		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_archive_row_actions' ), 15, 2 );
		add_action( 'admin_post_documentate_export_docx', array( $this, 'handle_export_docx' ) );
		add_action( 'admin_post_documentate_export_odt', array( $this, 'handle_export_odt' ) );
		add_action( 'admin_post_documentate_export_pdf', array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_documentate_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_documentate_archive', array( $this, 'handle_archive_action' ) );
		add_action( 'admin_post_documentate_unarchive', array( $this, 'handle_unarchive_action' ) );
		add_action( 'admin_post_documentate_preview_stream', array( $this, 'handle_preview_stream' ) );

		// Handler for the converter page with COOP/COEP headers (LibreOffice WASM mode).
		add_action( 'admin_post_documentate_converter', array( $this, 'render_converter_page' ) );

		// AJAX handler for document generation with progress modal.
		add_action( 'wp_ajax_documentate_generate_document', array( $this, 'ajax_generate_document' ) );

		// Metabox with action buttons in the edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_actions_metabox' ) );

		// Surface error notices after redirects.
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		// Enhance title field UX for documents CPT.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_title_textarea_assets' ) );

		// Enqueue scripts for the actions metabox.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_actions_metabox_assets' ) );
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

		if ( ! class_exists( 'Documentate_Document_Generator' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-generator.php';
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
		if ( 'documentate_document' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_editor();
		wp_enqueue_style(
			'documentate-title-textarea',
			plugins_url( 'admin/css/documentate-title.css', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION,
		);
		wp_enqueue_script(
			'documentate-title-textarea',
			plugins_url( 'admin/js/documentate-title.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery' ),
			DOCUMENTATE_VERSION,
			true,
		);

		wp_localize_script(
			'documentate-title-textarea',
			'documentateTitleConfig',
			array(
				'requiredMessage' => __( 'Title is required.', 'documentate' ),
				'placeholder' => __( 'Enter document title', 'documentate' ),
			)
		);

		// Annexes repeater UI.
		wp_enqueue_script(
			'documentate-annexes',
			plugins_url( 'admin/js/documentate-annexes.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery', 'wp-editor' ),
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
	}

	/**
	 * Add "Exportar DOCX" link to row actions for the Documentate CPT.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function add_row_actions( $actions, $post ) {
		if ( 'documentate_document' !== $post->post_type ) {
			return $actions;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			// Only show DOCX export if a template is configured (global or type-specific).
			$opts = get_option( 'documentate_settings', array() );
			$has_docx_tpl = ! empty( $opts['docx_template_id'] );
			$types = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
				$tid = intval( $types[0] );
				if ( intval( get_term_meta( $tid, 'documentate_type_docx_template', true ) ) > 0 ) {
					$has_docx_tpl = true;
				}
			}
			if ( $has_docx_tpl ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'documentate_export_docx',
							'post_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'documentate_export_' . $post->ID
				);
				$actions['documentate_export_docx'] =
					'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export DOCX', 'documentate' ) . '</a>';
			}
		}

		return $actions;
	}

	/**
	 * Add archive/unarchive row actions for administrators.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array Modified row actions.
	 */
	public function add_archive_row_actions( $actions, $post ) {
		if ( 'documentate_document' !== $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}

		if ( 'publish' === $post->post_status ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'documentate_archive',
						'post_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'documentate_archive_' . $post->ID
			);
			$actions['documentate_archive'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Archive', 'documentate' ) . '</a>';
		}

		if ( 'archived' === $post->post_status ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'documentate_unarchive',
						'post_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'documentate_unarchive_' . $post->ID
			);
			$actions['documentate_unarchive'] =
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Unarchive', 'documentate' ) . '</a>';
		}

		return $actions;
	}

	/**
	 * Handle archive action.
	 *
	 * @return void
	 */
	public function handle_archive_action() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_archive_' . $post_id )
		) {
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'documentate_document' !== $post->post_type || 'publish' !== $post->post_status ) {
			wp_die( esc_html__( 'Invalid document or status.', 'documentate' ) );
		}

		wp_update_post(
			array(
				'ID' => $post_id,
				'post_status' => 'archived',
			)
		);

		wp_safe_redirect( add_query_arg( array( 'post_type' => 'documentate_document' ), admin_url( 'edit.php' ) ) );
		exit();
	}

	/**
	 * Handle unarchive action.
	 *
	 * @return void
	 */
	public function handle_unarchive_action() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_unarchive_' . $post_id )
		) {
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'documentate_document' !== $post->post_type || 'archived' !== $post->post_status ) {
			wp_die( esc_html__( 'Invalid document or status.', 'documentate' ) );
		}

		wp_update_post(
			array(
				'ID' => $post_id,
				'post_status' => 'publish',
			)
		);

		wp_safe_redirect( add_query_arg( array( 'post_type' => 'documentate_document' ), admin_url( 'edit.php' ) ) );
		exit();
	}

	/**
	 * Handle DOCX export action.
	 *
	 * Delegates to Export_DOCX_Handler.
	 */
	public function handle_export_docx() {
		$this->docx_handler->handle();
	}

	/**
	 * Handle ODT export action.
	 *
	 * Delegates to Export_ODT_Handler.
	 */
	public function handle_export_odt() {
		$this->odt_handler->handle();
	}

	/**
	 * Handle PDF export action.
	 *
	 * Delegates to Export_PDF_Handler.
	 */
	public function handle_export_pdf() {
		$this->pdf_handler->handle();
	}

	/**
	 * Render-only preview of the document in a new tab.
	 */
	public function handle_preview() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_' . $post_id )
		) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Preview error', 'documentate' ),
				array(
					'back_link' => true,
				)
			);
		}

		$this->stream_pdf_inline( $result, get_the_title( $post_id ) );
	}

	/**
	 * Stream the generated PDF inline so browsers can render it inside an iframe.
	 *
	 * @return void
	 */
	public function handle_preview_stream() {
		$post_id = $this->authorize_preview_stream();
		$filename = $this->resolve_preview_filename( $post_id, get_current_user_id() );

		$upload_dir = wp_upload_dir();
		$path = trailingslashit( $upload_dir['basedir'] ) . 'documentate/' . $filename;

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			wp_die( esc_html( $fs->get_error_message() ) );
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			wp_die( esc_html__( 'Could not access the generated PDF file.', 'documentate' ) );
		}

		$this->send_preview_headers( $filename, (int) $fs->size( $path ) );

		if ( ! self::stream_file( $path ) ) {
			wp_die( esc_html__( 'Could not read the PDF file.', 'documentate' ) );
		}

		exit();
	}

	/**
	 * Authorise a preview stream request, terminating when it is not allowed.
	 *
	 * @return int Document post ID.
	 */
	private function authorize_preview_stream() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_stream_' . $post_id )
		) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		if ( get_current_user_id() <= 0 ) {
			wp_die( esc_html__( 'User not authenticated.', 'documentate' ) );
		}

		return $post_id;
	}

	/**
	 * Reuse the cached preview file, generating it when missing.
	 *
	 * @param int $post_id Document post ID.
	 * @param int $user_id Requesting user ID.
	 * @return string Sanitized file name.
	 */
	private function resolve_preview_filename( $post_id, $user_id ) {
		$filename = get_transient( $this->get_preview_stream_transient_key( $post_id, $user_id ) );

		if ( false === $filename || '' === $filename ) {
			$this->ensure_document_generator();
			$result = Documentate_Document_Generator::generate_pdf( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html__( 'Could not generate the PDF for preview.', 'documentate' ) );
			}

			$filename = basename( $result );
			$this->remember_preview_stream_file( $post_id, $filename );
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			wp_die( esc_html__( 'Preview file not available.', 'documentate' ) );
		}

		return $filename;
	}

	/**
	 * Send the headers that make browsers render the PDF inline.
	 *
	 * @param string $filename Sanitized file name.
	 * @param int    $filesize File size in bytes.
	 * @return void
	 */
	private function send_preview_headers( $filename, $filesize ) {
		$download_name = wp_basename( $filename );
		$encoded_name = rawurlencode( $download_name );
		$disposition = 'inline; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name;

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}
	}

	/**
	 * Render the converter page for LibreOffice WASM (browser) mode.
	 *
	 * This page runs in an iframe with COOP/COEP headers required for SharedArrayBuffer.
	 * Uses admin-post.php as the entry point to ensure PHP executes properly.
	 *
	 * @return void
	 */
	public function render_converter_page() {
		// Debug: Check if headers were already sent.
		if ( headers_sent( $file, $line ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Documentate: Headers already sent in $file on line $line" );
		}

		// Clear ALL output buffering levels from WordPress.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Start fresh buffer.
		ob_start();

		// Remove WordPress headers that may interfere with cross-origin isolation.
		header_remove( 'X-Frame-Options' );
		header_remove( 'Expires' );
		header_remove( 'Cache-Control' );
		header_remove( 'Pragma' );
		header_remove( 'Referrer-Policy' );

		// Send COOP/COEP headers required for SharedArrayBuffer (used by WASM).
		// Using 'credentialless' instead of 'require-corp' - less restrictive, better iframe support.
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Cross-Origin-Embedder-Policy: credentialless' );
		header( 'Content-Type: text/html; charset=utf-8' );

		// Discard any buffered output.
		ob_end_clean();

		// Verify user has permission.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'documentate' ) );
		}

		// Determine which template to use based on conversion engine and environment.
		$options = get_option( 'documentate_settings', array() );
		$engine = isset( $options['conversion_engine'] ) ? $options['conversion_engine'] : 'collabora';

		// Use Collabora Playground template when:
		// - Engine is 'collabora' AND we're in Playground environment
		// - This bypasses PHP's wp_remote_post which doesn't handle multipart well in Playground.
		if (
			'collabora' === $engine
			&& class_exists( 'Documentate_Collabora_Converter' )
			&& Documentate_Collabora_Converter::is_playground()
		) {
			include plugin_dir_path( __FILE__ ) . '../admin/documentate-collabora-playground-template.php';
		} else {
			// Use the LibreOffice WASM template for 'wasm' engine or non-Playground environments.
			include plugin_dir_path( __FILE__ ) . '../admin/documentate-converter-template.php';
		}
		exit();
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
	 * Generate the transient key used to remember the preview filename.
	 *
	 * @param int $post_id Document post ID.
	 * @param int $user_id Current user ID.
	 * @return string
	 */
	private function get_preview_stream_transient_key( $post_id, $user_id ) {
		return 'documentate_preview_stream_' . absint( $user_id ) . '_' . absint( $post_id );
	}

	/**
	 * Stream a PDF file inline to the browser.
	 *
	 * @param string $pdf_path Absolute path to the PDF file.
	 * @param string $title    Optional document title for the filename.
	 * @return void
	 */
	private function stream_pdf_inline( $pdf_path, $title = '' ) {
		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			wp_die( esc_html( $fs->get_error_message() ), '', array( 'back_link' => true ) );
		}

		if ( ! $fs->exists( $pdf_path ) || ! $fs->is_readable( $pdf_path ) ) {
			wp_die( esc_html__( 'Could not access the generated PDF file.', 'documentate' ), '', array( 'back_link' => true ) );
		}

		$filename = wp_basename( $pdf_path );
		$encoded_name = rawurlencode( $filename );
		$filesize = (int) $fs->size( $pdf_path );
		$disposition = 'inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encoded_name;

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		if ( ! self::stream_file( $pdf_path ) ) {
			wp_die( esc_html__( 'Could not read the PDF file.', 'documentate' ), '', array( 'back_link' => true ) );
		}

		exit();
	}

	/**
	 * Add actions metabox to the edit screen.
	 */
	public function add_actions_metabox() {
		add_meta_box(
			'documentate_actions',
			__( 'Document Actions', 'documentate' ),
			array( $this, 'render_actions_metabox' ),
			'documentate_document',
			'side',
			'high',
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
			echo '<p>' . esc_html__( 'Insufficient permissions.', 'documentate' ) . '</p>';
			return;
		}

		$state = $this->build_actions_state( $post->ID );

		$this->render_unsaved_indicator();
		$this->render_primary_actions( $state );
		$this->render_secondary_actions( $state );
	}

	/**
	 * Render the passive "unsaved changes" indicator.
	 *
	 * Always present but hidden; documentate-unsaved-changes.js toggles it as the
	 * form becomes dirty. The status role makes assistive technology announce the
	 * transition without stealing focus.
	 *
	 * @return void
	 */
	private function render_unsaved_indicator() {
		echo '<p class="documentate-unsaved-indicator" role="status" hidden>'
				. '<span class="documentate-unsaved-indicator__dot" aria-hidden="true"></span>'
				. esc_html__( 'Unsaved changes', 'documentate' )
				. '</p>';
	}

	/**
	 * Resolve which export actions are available, and why when they are not.
	 *
	 * @param int $post_id Document post ID.
	 * @return array<string,mixed>
	 */
	private function build_actions_state( $post_id ) {
		$this->ensure_document_generator();

		$docx_template = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );
		$odt_template = Documentate_Document_Generator::get_template_path( $post_id, 'odt' );

		$conversion = $this->resolve_conversion_capabilities();

		// In CDN mode or Playground with Collabora, browser can do conversions too.
		$can_convert = $conversion['ready'] || $conversion['use_popup'];
		$has_template = '' !== $docx_template || '' !== $odt_template;
		$pdf_available = $can_convert && $has_template;
		$pdf_message = $this->build_pdf_message( $docx_template, $odt_template, $can_convert );

		return array(
			'has_sign_placeholder' => $this->detect_sign_placeholder( $docx_template, $odt_template ),
			// Determine source format for CDN conversions.
			'source_format' => $this->resolve_source_format( $docx_template, $odt_template ),
			'needs_popup_base' => $conversion['needs_popup_base'],
			'pdf_available' => $pdf_available,
			'pdf_message' => $pdf_message,
			// Preview is available if server conversion is ready OR if popup conversion is available.
			'preview_available' => $pdf_available || ( $conversion['use_popup'] && $has_template ),
			'preview_message' => $pdf_message,
			'formats' => $this->own_format_state( $docx_template, $odt_template ),
		);
	}

	/**
	 * Determine whether PDFs are drawn here rather than converted elsewhere.
	 *
	 * @return bool
	 */
	private function uses_native_pdf_engine() {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';

		return Documentate_Conversion_Manager::ENGINE_FPDF === Documentate_Conversion_Manager::get_engine();
	}

	/**
	 * Determine how, if at all, this site can convert between formats.
	 *
	 * @return array{ready:bool,use_popup:bool,needs_popup_base:bool}
	 */
	private function resolve_conversion_capabilities() {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';

		// The native renderer draws the PDF in this process. Nothing may be
		// routed through the browser converter, which would convert the office
		// template instead of drawing the layout.
		if ( $this->uses_native_pdf_engine() ) {
			return array(
				'ready' => true,
				'use_popup' => false,
				'needs_popup_base' => false,
			);
		}

		$ready = Documentate_Conversion_Manager::is_available();
		$in_playground = Documentate_Collabora_Converter::is_playground();

		// In-browser LibreOffice WASM conversion is not available in WordPress
		// Playground: the site runs in a sandboxed, non-cross-origin-isolated iframe,
		// so SharedArrayBuffer is unavailable and the isolated converter page is blocked.
		$wasm_browser = false;
		if ( ! $ready && ! $in_playground ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
			$wasm_browser = Documentate_Libreoffice_Wasm_Converter::is_browser_mode()
				&& Documentate_Libreoffice_Wasm_Converter::assets_available();
		}

		// Collabora in Playground converts via a JavaScript fetch (bypassing PHP
		// wp_remote_post multipart issues).
		$collabora_in_playground = $in_playground && Documentate_Collabora_Converter::is_available();

		return array(
			'ready' => $ready,
			'use_popup' => $wasm_browser || $collabora_in_playground,
			'needs_popup_base' => ( $wasm_browser && ! $ready ) || $collabora_in_playground,
		);
	}

	/**
	 * Detect the [sign] placeholder in the active template.
	 *
	 * @param string $docx_template DOCX template path, or an empty string.
	 * @param string $odt_template  ODT template path, or an empty string.
	 * @return bool
	 */
	private function detect_sign_placeholder( $docx_template, $odt_template ) {
		$template = '' !== $docx_template ? $docx_template : $odt_template;
		if ( '' === $template ) {
			return false;
		}

		return Documentate_Template_Parser::template_has_sign_placeholder( $template );
	}

	/**
	 * Resolve the format conversions should start from.
	 *
	 * @param string $docx_template DOCX template path, or an empty string.
	 * @param string $odt_template  ODT template path, or an empty string.
	 * @return string
	 */
	private function resolve_source_format( $docx_template, $odt_template ) {
		if ( '' !== $odt_template ) {
			return 'odt';
		}

		return '' !== $docx_template ? 'docx' : '';
	}

	/**
	 * Build the tooltip explaining why PDF generation is unavailable.
	 *
	 * @param string $docx_template DOCX template path, or an empty string.
	 * @param string $odt_template  ODT template path, or an empty string.
	 * @param bool   $can_convert   Whether any conversion route is available.
	 * @return string Empty string when PDF generation is available.
	 */
	private function build_pdf_message( $docx_template, $odt_template, $can_convert ) {
		if ( '' === $docx_template && '' === $odt_template ) {
			return __( 'Configure a DOCX or ODT template in the document type before generating PDF.', 'documentate' );
		}

		// The native renderer needs nothing beyond the document type, so there
		// is never anything to explain once a template is there.
		if ( $this->uses_native_pdf_engine() || $can_convert ) {
			return '';
		}

		return Documentate_Conversion_Manager::get_unavailable_message(
			'' !== $docx_template ? 'docx' : 'odt',
			'pdf'
		);
	}

	/**
	 * Build the editable download entry for each format the type has a
	 * template for.
	 *
	 * The editable download hands over the rendered template itself, so a
	 * format is offered when, and only when, the document type carries a
	 * template in it. A type with one template therefore yields one entry, and
	 * a type with none yields no entry at all.
	 *
	 * @param string $docx_template DOCX template path, or an empty string.
	 * @param string $odt_template  ODT template path, or an empty string.
	 * @return array<string,array{available:bool,message:string,label:string}>
	 */
	private function own_format_state( $docx_template, $odt_template ) {
		$templates = array(
			'odt' => $odt_template,
			'docx' => $docx_template,
		);

		$formats = array();
		foreach ( $templates as $format => $template ) {
			if ( '' === $template ) {
				continue;
			}

			$formats[ $format ] = array(
				'available' => true,
				'message' => '',
				'label' => strtoupper( $format ),
			);
		}

		return $formats;
	}

	/**
	 * Render the primary row: Preview, Download PDF and Sign.
	 *
	 * @param array<string,mixed> $state Resolved action state.
	 * @return void
	 */
	private function render_primary_actions( array $state ) {
		echo '<div class="documentate-actions-primary">';

		$this->render_preview_button( $state );
		$this->render_pdf_button( $state );
		$this->render_sign_button( $state );

		echo '</div>';
	}

	/**
	 * Render the Preview button.
	 *
	 * @param array<string,mixed> $state Resolved action state.
	 * @return void
	 */
	private function render_preview_button( array $state ) {
		if ( ! $state['preview_available'] ) {
			echo '<button type="button" class="button documentate-action-btn--preview" disabled title="'
					. esc_attr( $state['preview_message'] )
					. '"><span class="dashicons dashicons-visibility"></span> '
					. esc_html__( 'Preview', 'documentate' )
					. '</button>';
			return;
		}

		$attrs = array(
			'class' => 'button documentate-action-btn documentate-action-btn--preview',
			'href' => '#',
			'data-documentate-action' => 'preview',
			'data-documentate-format' => 'pdf',
		);
		if ( $state['needs_popup_base'] ) {
			$attrs['data-documentate-cdn-mode'] = '1';
			$attrs['data-documentate-source-format'] = $state['source_format'];
		}

		echo '<a '
				. $this->build_action_attributes( $attrs ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '><span class="dashicons dashicons-visibility"></span> '
				. esc_html__( 'Preview', 'documentate' )
				. '</a>';
	}

	/**
	 * Render the Download PDF button, which is always shown.
	 *
	 * @param array<string,mixed> $state Resolved action state.
	 * @return void
	 */
	private function render_pdf_button( array $state ) {
		if ( ! $state['pdf_available'] ) {
			echo '<button type="button" class="button button-primary documentate-action-btn--pdf" disabled title="'
					. esc_attr( $state['pdf_message'] )
					. '"><span class="dashicons dashicons-pdf"></span> '
					. esc_html__( 'Download PDF', 'documentate' )
					. '</button>';
			return;
		}

		$attrs = array(
			'class' => 'button button-primary documentate-action-btn documentate-action-btn--pdf',
			'href' => '#',
			'data-documentate-action' => 'download',
			'data-documentate-format' => 'pdf',
		);
		if ( $state['needs_popup_base'] && 'pdf' !== $state['source_format'] ) {
			$attrs['data-documentate-cdn-mode'] = '1';
			$attrs['data-documentate-source-format'] = $state['source_format'];
		}

		echo '<a '
				. $this->build_action_attributes( $attrs ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '><span class="dashicons dashicons-pdf"></span> '
				. esc_html__( 'Download PDF', 'documentate' )
				. '</a>';
	}

	/**
	 * Render the Sign and Download button, when the template supports it.
	 *
	 * @param array<string,mixed> $state Resolved action state.
	 * @return void
	 */
	private function render_sign_button( array $state ) {
		if ( ! $state['has_sign_placeholder'] || ! $state['pdf_available'] ) {
			return;
		}

		$attrs = array(
			'class' => 'button button-primary documentate-action-btn documentate-action-btn--sign',
			'href' => '#',
			'data-documentate-action' => 'sign',
			'data-documentate-format' => 'pdf',
		);

		echo '<a '
				. $this->build_action_attributes( $attrs ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '><span class="dashicons dashicons-lock"></span> '
				. esc_html__( 'Sign and Download', 'documentate' )
				. '</a>';
	}

	/**
	 * Render the secondary row: the editable document behind the PDF.
	 *
	 * @param array<string,mixed> $state Resolved action state.
	 * @return void
	 */
	private function render_secondary_actions( array $state ) {
		if ( empty( $state['formats'] ) ) {
			return;
		}

		echo '<div class="documentate-actions-secondary">';
		echo '<span class="documentate-actions-secondary__label">'
				. esc_html__( 'Editable download:', 'documentate' )
				. '</span>';
		echo '<span class="documentate-actions-secondary__buttons">';

		foreach ( $state['formats'] as $format => $data ) {
			$this->render_secondary_button( $format, $data );
		}

		echo '</span>';
		echo '</div>';
	}

	/**
	 * Render one secondary download button.
	 *
	 * Every entry names a format the document type has a template for, so the
	 * file is rendered and handed over as it is. Nothing here is ever routed
	 * through the browser converter, whichever engine draws the PDF.
	 *
	 * @param string              $format Format key.
	 * @param array<string,mixed> $data   Availability, tooltip and label.
	 * @return void
	 */
	private function render_secondary_button( $format, array $data ) {
		$attrs = array(
			'class' => 'button button-small documentate-action-btn',
			'href' => '#',
			'data-documentate-action' => 'download',
			'data-documentate-format' => $format,
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
		echo '<a ' . $this->build_action_attributes( $attrs ) . '>' . esc_html( $data['label'] ) . '</a> ';
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
	 * Stream a generated document to the browser as an attachment download.
	 *
	 * @param string $path Absolute path to the generated file.
	 * @param string $mime Mime type to send in the response headers.
	 * @return true|WP_Error
	 */
	private function stream_file_download( $path, $mime ) {
		$path = (string) $path;
		$mime = (string) $mime;
		if ( '' === $path ) {
			return new WP_Error( 'documentate_download_missing', __( 'Could not determine the generated file.', 'documentate' ) );
		}

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			return new WP_Error( 'documentate_download_unreadable', __( 'Could not access the generated file.', 'documentate' ) );
		}

		$download_name = wp_basename( $path );
		$encoded_name = rawurlencode( $download_name );
		$filesize = (int) $fs->size( $path );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		if ( ! self::stream_file( $path ) ) {
			return new WP_Error( 'documentate_download_unreadable', __( 'Could not read the generated file.', 'documentate' ) );
		}

		return true;
	}

	/**
	 * Stream a local file to the browser without buffering it in memory.
	 *
	 * WP_Filesystem exposes no chunked-read API, so readfile() is used to send
	 * the file straight to the output stream. This keeps memory usage flat
	 * regardless of document size, whereas get_contents() loaded the whole file
	 * into a PHP string first. Callers must send the HTTP headers and validate
	 * that the path exists and is readable before calling this method.
	 *
	 * @param string $path Absolute path to the file to stream.
	 * @return bool True on success, false if the file could not be read.
	 */
	public static function stream_file( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a validated local binary file directly to output; WP_Filesystem has no chunked-read API and get_contents() would buffer the whole file in memory.
		return false !== readfile( $path );
	}

	/**
	 * Show admin notice if redirected with an error.
	 */
	public function maybe_notice() {
		if ( empty( $_GET['documentate_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'documentate_document' !== $screen->id && 'post' !== $screen->base ) {
			// Only show in edit screens.
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['documentate_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Enqueue scripts and styles for the actions metabox.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_actions_metabox_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_style(
			'documentate-actions',
			plugins_url( 'admin/css/documentate-actions.css', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION,
		);

		// Loaded before the actions script so its capture-phase click handler is
		// registered first and can gate every action, including the signing one
		// handled by documentate-autofirma.
		wp_enqueue_script(
			'documentate-unsaved-changes',
			plugins_url( 'admin/js/documentate-unsaved-changes.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery', 'wp-a11y' ),
			DOCUMENTATE_VERSION,
			true,
		);

		wp_localize_script(
			'documentate-unsaved-changes',
			'documentateUnsavedChangesConfig',
			array(
				'postId' => $post_id,
				'strings' => $this->get_unsaved_changes_script_strings(),
			)
		);

		wp_enqueue_script(
			'documentate-actions',
			plugins_url( 'admin/js/documentate-actions.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery', 'documentate-unsaved-changes' ),
			DOCUMENTATE_VERSION,
			true,
		);

		$config = $this->build_actions_script_config( $post_id );

		// Enqueue AutoScript.js when the template has a [sign] placeholder.
		if ( ! empty( $config['hasSignPlaceholder'] ) ) {
			wp_enqueue_script(
				'autoscript',
				plugins_url( 'admin/js/vendor/autoscript.js', DOCUMENTATE_PLUGIN_FILE ),
				array(),
				DOCUMENTATE_VERSION,
				true,
			);
		}

		wp_localize_script( 'documentate-actions', 'documentateActionsConfig', $config );
	}

	/**
	 * Get the current post ID from request or global.
	 *
	 * @return int Post ID or 0 if not found.
	 */
	private function get_current_post_id() {
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id && isset( $GLOBALS['post'] ) ) {
			$post_id = $GLOBALS['post']->ID;
		}
		return $post_id;
	}

	/**
	 * Build the configuration array for the actions script.
	 *
	 * @param int $post_id The post ID.
	 * @return array Configuration array for JavaScript.
	 */
	private function build_actions_script_config( $post_id ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-libreoffice-wasm-converter.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'postId' => $post_id,
			'postSlug' => sanitize_title( get_the_title( $post_id ) ),
			'nonce' => wp_create_nonce( 'documentate_generate_' . $post_id ),
			'strings' => $this->get_actions_script_strings(),
		);

		// Detect [sign] placeholder for AutoFirma integration.
		$this->ensure_document_generator();
		$docx_tpl = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );
		$odt_tpl = Documentate_Document_Generator::get_template_path( $post_id, 'odt' );
		$sign_check_tpl = '' !== $docx_tpl ? $docx_tpl : $odt_tpl;
		$sign_info = '' !== $sign_check_tpl ? Documentate_Template_Parser::get_sign_placeholder_info( $sign_check_tpl ) : false;
		if ( false !== $sign_info ) {
			$config['hasSignPlaceholder'] = true;
			$config['signPosition'] = $sign_info;
		}

		return $this->add_conversion_mode_config( $config );
	}

	/**
	 * Get translatable strings for the actions script.
	 *
	 * @return array Translatable strings.
	 */
	private function get_actions_script_strings() {
		return array(
			'generating' => __( 'Generating document...', 'documentate' ),
			'generatingPreview' => __( 'Generating preview...', 'documentate' ),
			/* translators: %s: document format (DOCX, ODT, PDF). */
			'generatingFormat' => __( 'Generating %s...', 'documentate' ),
			'wait' => __( 'Please wait while the document is being generated.', 'documentate' ),
			'close' => __( 'Close', 'documentate' ),
			'errorGeneric' => __( 'Error generating the document.', 'documentate' ),
			'errorNetwork' => __( 'Connection error. Please try again.', 'documentate' ),
			'loadingWasm' => __( 'Loading LibreOffice...', 'documentate' ),
			'convertingBrowser' => __( 'Converting in browser...', 'documentate' ),
			'wasmError' => __( 'Error loading LibreOffice.', 'documentate' ),
			'previewReady' => __( 'Preview ready', 'documentate' ),
			'previewBlocked' => __( 'Your browser blocked the pop-up window.', 'documentate' ),
			'openPreview' => __( 'Open preview', 'documentate' ),
			'signingInProgress' => __( 'Please select your certificate in AutoFirma...', 'documentate' ),
			'signErrorNoAutofirma' => __( 'AutoFirma is not installed or could not be started.', 'documentate' ),
			'downloadUnsigned' => __( 'Download unsigned PDF', 'documentate' ),
		);
	}

	/**
	 * Get translatable strings for the unsaved-changes guard.
	 *
	 * @return array Translatable strings.
	 */
	private function get_unsaved_changes_script_strings() {
		return array(
			'title' => __( 'You have unsaved changes', 'documentate' ),
			'message' => __(
				'The document is generated from the last saved version. If you continue without saving, your changes will not appear in it.',
				'documentate',
			),
			'saveAndPreview' => __( 'Save and preview', 'documentate' ),
			'saveAndDownload' => __( 'Save and download', 'documentate' ),
			'saveAndSign' => __( 'Save and sign', 'documentate' ),
			'useSaved' => __( 'Use saved version', 'documentate' ),
			'cancel' => __( 'Cancel', 'documentate' ),
			'saving' => __( 'Saving changes...', 'documentate' ),
			'savingWait' => __( 'The action will continue automatically.', 'documentate' ),
			'savedTitle' => __( 'Changes saved', 'documentate' ),
			'resumeMessage' => __( 'Your changes were saved. Continue to generate the document.', 'documentate' ),
			// Same label as the button itself, so the dialog reads as a continuation.
			'signNow' => __( 'Sign and Download', 'documentate' ),
		);
	}

	/**
	 * Add conversion mode configuration based on available converters.
	 *
	 * @param array $config Base configuration array.
	 * @return array Configuration with conversion mode settings.
	 */
	private function add_conversion_mode_config( $config ) {
		// The native renderer produces the PDF here, so the script must not be
		// told to convert anything in the browser. It does need to know it is in
		// Playground: opening a preview in a new tab does not work inside that
		// sandboxed iframe, so the script shows the PDF in its embedded viewer
		// instead, exactly as the Collabora path already does there.
		if ( $this->uses_native_pdf_engine() ) {
			if ( Documentate_Collabora_Converter::is_playground() ) {
				$config['inPlayground'] = true;
			}

			return $config;
		}

		$conversion_ready = Documentate_Conversion_Manager::is_available();
		$in_playground = Documentate_Collabora_Converter::is_playground();
		$collabora_in_playground = $in_playground && Documentate_Collabora_Converter::is_available();

		if ( $collabora_in_playground ) {
			$options = get_option( 'documentate_settings', array() );
			$config['collaboraPlayground'] = true;
			$config['collaboraUrl'] = isset( $options['collabora_base_url'] ) ? esc_url( $options['collabora_base_url'] ) : '';
			return $config;
		}

		// The `cdnMode`/`converterUrl` keys are kept for backwards compatibility with
		// the actions script and E2E tests; they drive the self-hosted browser
		// LibreOffice WASM popup. This popup cannot run in WordPress Playground (the
		// site runs in a sandboxed iframe that blocks cross-origin isolated pages), so
		// the WASM browser path is not offered there.
		$wasm_browser_available = ! $conversion_ready
			&& ! $in_playground
			&& Documentate_Libreoffice_Wasm_Converter::is_browser_mode()
			&& Documentate_Libreoffice_Wasm_Converter::assets_available();
		if ( $wasm_browser_available ) {
			$config['cdnMode'] = true;
			$config['converterUrl'] = admin_url( 'admin-post.php?action=documentate_converter' );
		}

		return $config;
	}

	/**
	 * AJAX handler for document generation.
	 *
	 * Generates the document and returns a URL for download/preview.
	 *
	 * @return void
	 */
	public function ajax_generate_document() {
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$format = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'pdf';
		$output = isset( $_POST['output'] ) ? sanitize_key( $_POST['output'] ) : 'download';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'documentate' ) ) );
		}

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'documentate_generate_' . $post_id )
		) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'documentate' ) ) );
		}

		$this->ensure_document_generator();

		$method = isset( self::$format_generator_map[ $format ] ) ? self::$format_generator_map[ $format ] : 'generate_pdf';
		$result = call_user_func( array( 'Documentate_Document_Generator', $method ), $post_id );

		if ( is_wp_error( $result ) ) {
			$this->send_generation_error( $result );
		}

		wp_send_json_success(
			array( 'url' => $this->build_generated_document_url( $post_id, $format, $output, $result ) )
		);
	}

	/**
	 * Report a generation failure without leaking conversion details.
	 *
	 * @param WP_Error $result Generation error.
	 * @return void
	 */
	private function send_generation_error( WP_Error $result ) {
		// Never return Collabora endpoint/body details to the browser — they
		// can disclose internal conversion URLs. Log only when debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional when WP_DEBUG is enabled.
			error_log(
				sprintf(
					'[Documentate] generate_document failed: %s | code=%s | data=%s',
					$result->get_error_message(),
					$result->get_error_code(),
					wp_json_encode( $result->get_error_data() )
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => $result->get_error_message(),
			)
		);
	}

	/**
	 * Build the admin-post URL that serves the generated document.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $format  Requested format.
	 * @param string $output  Either preview or download.
	 * @param string $result  Path to the generated file.
	 * @return string
	 */
	private function build_generated_document_url( $post_id, $format, $output, $result ) {
		if ( 'preview' === $output ) {
			// For preview, use the preview stream URL.
			$this->remember_preview_stream_file( $post_id, basename( $result ) );

			return add_query_arg(
				array(
					'action' => 'documentate_preview_stream',
					'post_id' => $post_id,
					'_wpnonce' => wp_create_nonce( 'documentate_preview_stream_' . $post_id ),
				),
				admin_url( 'admin-post.php' )
			);
		}

		// For download, use the export URL.
		return add_query_arg(
			array(
				'action' => 'documentate_export_' . $format,
				'post_id' => $post_id,
				'_wpnonce' => wp_create_nonce( 'documentate_export_' . $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}
}

new Documentate_Admin_Helper();
