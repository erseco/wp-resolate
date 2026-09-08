<?php
/**
 * Form handlers of the front-end application.
 *
 * Every form of the application posts to the application page itself and is
 * handled here on template_redirect, before any output: nonce, capability,
 * sanitised input, the write, and a redirect carrying a feedback flag. The
 * views never write anything.
 *
 * @package Documentate
 * @subpackage App
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Create, save, move on and comment on documents from the application.
 */
class Documentate_App_Actions {

	/**
	 * Post type of the documents.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Name of the file input of the edit form.
	 *
	 * @var string
	 */
	const ATTACHMENT_FIELD = 'documentate_app_adjunto';

	/**
	 * Whether the request posts the given application action.
	 *
	 * @param string $action Action name carried by the form.
	 * @return bool
	 */
	private static function is_action( $action ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the nonce next.
		return isset( $_POST['documentate_app_accion'] ) && $action === $_POST['documentate_app_accion'];
	}

	/**
	 * Stop the request unless the form carries a valid nonce for the action.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function require_nonce( $action ) {
		$nonce = isset( $_POST['documentate_app_nonce'] ) ? sanitize_key( wp_unslash( $_POST['documentate_app_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html( 'La comprobación de seguridad ha fallado.' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stop the request with a 403.
	 *
	 * @return void
	 */
	private static function deny() {
		wp_die( esc_html( 'No tienes permisos suficientes.' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Redirect inside the application and stop.
	 *
	 * @param string               $url  Destination.
	 * @param array<string,string> $args Query arguments to add (feedback flags).
	 * @return void
	 */
	private static function redirect_to( $url, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Non-negative integer posted by the form (an ID), 0 when absent.
	 *
	 * @param string $field Field name.
	 * @return int
	 */
	private static function posted_int( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce around this read.
		return isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0;
	}

	/**
	 * Text posted by the form, sanitised.
	 *
	 * @param string $field    Field name.
	 * @param bool   $textarea Whether the field is a textarea (keeps line breaks).
	 * @return string Empty when absent or when an array was posted.
	 */
	private static function posted_text( $field, $textarea = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce around this read.
		if ( ! isset( $_POST[ $field ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised right below, once it is known to be a scalar.
		$value = wp_unslash( $_POST[ $field ] );
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return $textarea ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
	}

	/**
	 * The return reason posted by the dialog or by its no-JavaScript fallback.
	 *
	 * @return string
	 */
	private static function posted_reason() {
		return trim( self::posted_text( 'documentate_app_motivo', true ) );
	}

	/**
	 * The tray the form came from, when this person can open it.
	 *
	 * The lists, the document view and the editor all carry it so the back
	 * link and the highlighted tab survive a save, a comment or a transition.
	 *
	 * @return string Tray key, empty when none was posted or it is not theirs.
	 */
	private static function posted_tray() {
		$tray = sanitize_key( self::posted_text( 'documentate_app_bandeja' ) );

		return in_array( $tray, Documentate_App_List::trays(), true ) ? $tray : '';
	}

	/**
	 * View arguments of a document, remembering the tray it was opened from.
	 *
	 * @param int    $doc_id Document ID.
	 * @param string $tray   Tray key.
	 * @return array<string,string|int>
	 */
	private static function detail_args( $doc_id, $tray ) {
		$args = array( 'doc' => $doc_id );
		if ( '' !== $tray && 'mis' !== $tray ) {
			$args['bandeja'] = $tray;
		}

		return $args;
	}

	/**
	 * What is missing in the basic data of the edit form.
	 *
	 * The "Guardar" button is formnovalidate (a draft must always be
	 * saveable), so the required attributes of the browser are not enough:
	 * an empty internal name would silently erase the one every list, every
	 * heading and every notification is named after. Both values arrive here
	 * after falling back to the stored ones, so this only fires for a
	 * document that never had them.
	 *
	 * @param string $title Official title, or the stored one.
	 * @param string $name  Internal name, or the stored one.
	 * @return string Error flag, empty when both are there.
	 */
	private static function data_error( $title, $name ) {
		if ( '' === $title ) {
			return 'titulo';
		}

		return '' === $name ? 'nombre' : '';
	}

	/**
	 * The basic data of the edit form, falling back to what is stored.
	 *
	 * What the document already has is what an empty box falls back to, and
	 * nothing is written before the fields of the form are persisted: a blank
	 * internal name can never cost somebody the whole Bloque I they just
	 * filled in. Only a document that never had one is reported.
	 *
	 * @param WP_Post $post Document.
	 * @return array{title:string,name:string,error:string}
	 */
	private static function basic_data( $post ) {
		$title = self::posted_text( 'documentate_app_titulo' );
		$name = self::posted_text( 'documentate_app_nombre' );

		$title = '' !== $title ? $title : (string) $post->post_title;
		$name = '' !== $name ? $name : Documentate_Document_Data::internal_name( $post );

		return array(
			'title' => $title,
			'name' => $name,
			'error' => self::data_error( $title, $name ),
		);
	}

	/**
	 * The document the current user may work on through the application.
	 *
	 * @param int $doc_id Document ID from the form.
	 * @return WP_Post|null Null when it is not a document the user can edit.
	 */
	private static function editable_document_for_user( $doc_id ) {
		if ( ! Documentate_App::current_user_can_use_app() ) {
			return null;
		}

		$post = get_post( $doc_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	/**
	 * Create a draft document from the "new document" form and redirect.
	 *
	 * Runs on template_redirect so the redirect happens before any output.
	 *
	 * @return void
	 */
	public static function handle_create_document() {
		if ( ! self::is_action( 'crear_documento' ) ) {
			return;
		}

		self::require_nonce( 'documentate_app_crear' );

		if ( ! Documentate_App::current_user_can_use_app() ) {
			self::deny();
		}

		$new_url = Documentate_App_Shell::page_url( array( 'vista' => 'nuevo' ) );
		$title = self::posted_text( 'documentate_app_titulo' );
		$name = self::posted_text( 'documentate_app_nombre' );
		$type = self::posted_int( 'documentate_app_tipo' );

		if ( '' === $title || '' === $name || 0 === $type || ! term_exists( $type, 'documentate_doc_type' ) ) {
			self::redirect_to( $new_url, array( 'error' => 'datos' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type' => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title' => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			self::redirect_to( $new_url, array( 'error' => 'crear' ) );
		}

		wp_set_post_terms( $post_id, array( $type ), 'documentate_doc_type', false );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $type );
		Documentate_Document_Data::save_internal_name( $post_id, $name );
		Documentate_Activity::record_event( $post_id, 'creó el borrador' );

		self::redirect_to( Documentate_App_Edit::url( $post_id ) );
	}

	/**
	 * Persist the edit form, run the transition it asks for and redirect.
	 *
	 * The form carries the sections-metabox nonce and field names, so
	 * wp_update_post() drives the same filters and save_post handlers as the
	 * wp-admin editor: the content writer composes post_content and the meta
	 * saver stores the fields. The status is never posted: whether the
	 * document moves on is decided afterwards by the transition engine.
	 *
	 * @return void
	 */
	public static function handle_save_document() {
		if ( ! self::is_action( 'guardar_documento' ) ) {
			return;
		}

		$doc_id = self::posted_int( 'documentate_app_doc' );
		self::require_nonce( 'documentate_app_guardar_' . $doc_id );

		$post = self::editable_document_for_user( $doc_id );
		if ( ! $post ) {
			self::deny();
		}

		$tray = self::posted_tray();
		$edit_url = Documentate_App_Edit::url( $post->ID, $tray );

		if ( ! Documentate_App_Edit::can_edit( $post ) ) {
			self::redirect_to( $edit_url, array( 'error' => 'bloqueado' ) );
		}

		$basics = self::basic_data( $post );
		$title = $basics['title'];
		$data_error = $basics['error'];

		Documentate_Document_Data::save_internal_name( $post->ID, $basics['name'] );
		if ( Documentate_Roles::is_management() ) {
			Documentate_Document_Data::save_notes( $post->ID, self::posted_text( 'documentate_app_anotaciones', true ) );
		}

		$attachment_error = self::process_attachment( $post );

		$result = wp_update_post(
			array(
				'ID' => $post->ID,
				'post_title' => $title,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			self::redirect_to( $edit_url, array( 'error' => 'guardar' ) );
		}

		// Everything the request carried is stored by now; what is missing is
		// reported without moving the document on.
		if ( '' !== $data_error ) {
			self::redirect_to( $edit_url, array( 'error' => $data_error ) );
		}

		if ( '' !== $attachment_error ) {
			self::redirect_to( $edit_url, array( 'error' => $attachment_error ) );
		}

		self::apply_transition( $post, $edit_url, $tray );

		self::redirect_to( $edit_url, array( 'guardado' => '1' ) );
	}

	/**
	 * Move a document on from the document view.
	 *
	 * @return void
	 */
	public static function handle_transition() {
		if ( ! self::is_action( 'transicion' ) ) {
			return;
		}

		$doc_id = self::posted_int( 'documentate_app_doc' );
		self::require_nonce( 'documentate_app_transicion_' . $doc_id );

		$post = self::editable_document_for_user( $doc_id );
		if ( ! $post ) {
			self::deny();
		}

		$tray = self::posted_tray();
		$detail_url = Documentate_App_Shell::page_url( self::detail_args( $post->ID, $tray ) );

		self::apply_transition( $post, $detail_url, $tray );

		self::redirect_to( $detail_url, array( 'error' => 'transicion' ) );
	}

	/**
	 * Store a comment written from the activity card.
	 *
	 * @return void
	 */
	public static function handle_comment() {
		if ( ! self::is_action( 'comentar' ) ) {
			return;
		}

		$doc_id = self::posted_int( 'documentate_app_doc' );
		self::require_nonce( 'documentate_app_comentar_' . $doc_id );

		$post = self::editable_document_for_user( $doc_id );
		if ( ! $post ) {
			self::deny();
		}

		$tray = self::posted_tray();
		$target = 'editar' === self::posted_text( 'documentate_app_redirect_to' )
			? Documentate_App_Edit::url( $post->ID, $tray )
			: Documentate_App_Shell::page_url( self::detail_args( $post->ID, $tray ) );

		$result = Documentate_Activity::add_comment( $post->ID, self::posted_text( 'documentate_app_comentario', true ) );

		self::redirect_to(
			$target,
			is_wp_error( $result ) ? array( 'error' => 'comentario' ) : array( 'comentado' => '1' )
		);
	}

	/**
	 * Run the transition the form asks for, if any, and redirect on the result.
	 *
	 * Returns without doing anything when no transition was posted, so the
	 * caller can carry on with its own redirect.
	 *
	 * @param WP_Post $post      Document.
	 * @param string  $error_url Where to come back to when the transition fails.
	 * @param string  $tray      Tray the form came from, so the document view keeps it.
	 * @return void
	 */
	private static function apply_transition( $post, $error_url, $tray = '' ) {
		$key = sanitize_key( self::posted_text( 'documentate_app_transicion' ) );
		if ( '' === $key ) {
			return;
		}

		// Only what the application draws as a button: archiving is a wp-admin
		// decision and must not be reachable by posting its key here.
		$available = Documentate_App_Shell::app_transitions( $post );
		if ( ! isset( $available[ $key ] ) ) {
			self::redirect_to( $error_url, array( 'error' => 'transicion' ) );
		}

		$result = Documentate_Transitions::apply( $post->ID, $key, self::posted_reason() );
		if ( is_wp_error( $result ) ) {
			$error = 'motivo_requerido' === $result->get_error_code() ? 'motivo' : 'transicion';
			self::redirect_to( $error_url, array( 'error' => $error ) );
		}

		$target = self::transition_target( $post->ID, $key, $tray );
		self::redirect_to( $target[0], $target[1] );
	}

	/**
	 * Where the application lands after a transition, and with which flag.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $key     Transition key.
	 * @param string $tray    Tray the form came from.
	 * @return array{0:string,1:array<string,string>}
	 */
	private static function transition_target( $post_id, $key, $tray = '' ) {
		$flag = Documentate_Transitions::flag( $key );
		$args = '' !== $flag ? array( $flag => '1' ) : array();

		// Three rules raise the "enviado" flag and each one has its own
		// sentence, which the status alone cannot tell apart (enviar_revision
		// and pasar_admin both land in "pending").
		if ( 'enviado' === $flag ) {
			$args['transicion'] = $key;
		}

		if ( 'bandeja' === Documentate_Transitions::redirect( $key ) ) {
			$tray = Documentate_Roles::is_administration() ? 'revision' : 'revisar';

			return array( Documentate_App_Shell::page_url( array( 'bandeja' => $tray ) ), $args );
		}

		return array( Documentate_App_Shell::page_url( self::detail_args( $post_id, $tray ) ), $args );
	}

	/**
	 * Remove the current file when asked to, and store the uploaded one.
	 *
	 * The replacement is validated before anything is detached: a file that
	 * cannot be stored must never cost the user the one they already had.
	 *
	 * @param WP_Post $post Document.
	 * @return string Error flag, or an empty string when everything went well.
	 */
	private static function process_attachment( $post ) {
		$file = self::posted_file();
		$remove_requested = '' !== self::posted_text( 'documentate_app_quitar_adjunto' );

		if ( empty( $file ) ) {
			if ( $remove_requested ) {
				Documentate_App_Attachments::remove( $post->ID );
			}

			return '';
		}

		if ( ! self::attachment_acceptable( $file ) ) {
			return 'adjunto';
		}

		if ( $remove_requested ) {
			Documentate_App_Attachments::remove( $post->ID );
		}

		$saved = Documentate_App_Attachments::store( $post->ID, $file );

		return is_wp_error( $saved ) ? self::attachment_flag( $saved ) : '';
	}

	/**
	 * The upload posted by the file input of the edit form.
	 *
	 * @return array<string,mixed> Empty when the form carried no file.
	 */
	private static function posted_file() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified by the handler; the upload is validated by Documentate_App_Attachments::validate().
		$file = isset( $_FILES[ self::ATTACHMENT_FIELD ] ) ? (array) $_FILES[ self::ATTACHMENT_FIELD ] : array();
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		return UPLOAD_ERR_NO_FILE === $error ? array() : $file;
	}

	/**
	 * Whether the posted file may become the file of the document.
	 *
	 * Documentate_App_Attachments::store() goes through
	 * wp_handle_sideload(), which skips the is_uploaded_file() test a normal
	 * upload gets, so the request boundary is where that test belongs.
	 *
	 * @param array<string,mixed> $file Upload posted by the form.
	 * @return bool
	 */
	private static function attachment_acceptable( array $file ) {
		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$is_upload = '' !== $path && is_uploaded_file( $path );

		// The filter exists so the test suite can hand a fixture written to a
		// temporary file to the handler. It is fenced to the PHPUnit bootstrap
		// (WP_TESTS_DOMAIN is defined by wp-phpunit and by nothing else): a
		// check that says "this path really is an upload of this request" must
		// not be something a plugin or a theme of the site can switch off.
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			/**
			 * Whether a posted path really is an upload of the current request.
			 *
			 * @param bool   $is_upload Result of is_uploaded_file().
			 * @param string $path      Temporary path posted by the browser.
			 */
			$is_upload = (bool) apply_filters( 'documentate_app_adjunto_es_subida', $is_upload, $path );
		}

		return $is_upload && ! is_wp_error( Documentate_App_Attachments::validate( $file ) );
	}

	/**
	 * Which error the user is shown when the file could not be stored.
	 *
	 * @param WP_Error $error Error from Documentate_App_Attachments::store().
	 * @return string Error flag.
	 */
	private static function attachment_flag( WP_Error $error ) {
		return 'sin_permiso' === $error->get_error_code() ? 'adjunto_permiso' : 'adjunto';
	}
}
