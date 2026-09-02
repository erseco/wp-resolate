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
class Documentate_App_Acciones {

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
	const CAMPO_ADJUNTO = 'documentate_app_adjunto';

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
			wp_die( esc_html( 'La comprobación de seguridad ha fallado.' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Stop the request with a 403.
	 *
	 * @return void
	 */
	private static function denegar() {
		wp_die( esc_html( 'No tienes permisos suficientes.' ), '', array( 'response' => 403 ) );
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
	 * Text posted by the form, sanitised.
	 *
	 * @param string $campo    Field name.
	 * @param bool   $textarea Whether the field is a textarea (keeps line breaks).
	 * @return string Empty when absent or when an array was posted.
	 */
	private static function texto_enviado( $campo, $textarea = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce around this read.
		if ( ! isset( $_POST[ $campo ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised right below, once it is known to be a scalar.
		$valor = wp_unslash( $_POST[ $campo ] );
		if ( ! is_scalar( $valor ) ) {
			return '';
		}

		return $textarea ? sanitize_textarea_field( (string) $valor ) : sanitize_text_field( (string) $valor );
	}

	/**
	 * The return reason posted by the dialog or by its no-JavaScript fallback.
	 *
	 * @return string
	 */
	private static function motivo_enviado() {
		return trim( self::texto_enviado( 'documentate_app_motivo', true ) );
	}

	/**
	 * The tray the form came from, when this person can open it.
	 *
	 * The lists, the document view and the editor all carry it so the back
	 * link and the highlighted tab survive a save, a comment or a transition.
	 *
	 * @return string Tray key, empty when none was posted or it is not theirs.
	 */
	private static function bandeja_enviada() {
		$bandeja = sanitize_key( self::texto_enviado( 'documentate_app_bandeja' ) );

		return in_array( $bandeja, Documentate_App_Lista::bandejas(), true ) ? $bandeja : '';
	}

	/**
	 * View arguments of a document, remembering the tray it was opened from.
	 *
	 * @param int    $doc_id  Document ID.
	 * @param string $bandeja Tray key.
	 * @return array<string,string|int>
	 */
	private static function args_detalle( $doc_id, $bandeja ) {
		$args = array( 'doc' => $doc_id );
		if ( '' !== $bandeja && 'mis' !== $bandeja ) {
			$args['bandeja'] = $bandeja;
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
	 * @param string $titulo Official title, or the stored one.
	 * @param string $nombre Internal name, or the stored one.
	 * @return string Error flag, empty when both are there.
	 */
	private static function error_de_datos( $titulo, $nombre ) {
		if ( '' === $titulo ) {
			return 'titulo';
		}

		return '' === $nombre ? 'nombre' : '';
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
	 * @return array{titulo:string,nombre:string,error:string}
	 */
	private static function datos_basicos( $post ) {
		$titulo = self::texto_enviado( 'documentate_app_titulo' );
		$nombre = self::texto_enviado( 'documentate_app_nombre' );

		$titulo = '' !== $titulo ? $titulo : (string) $post->post_title;
		$nombre = '' !== $nombre ? $nombre : Documentate_Documento::nombre_interno( $post );

		return array(
			'titulo' => $titulo,
			'nombre' => $nombre,
			'error' => self::error_de_datos( $titulo, $nombre ),
		);
	}

	/**
	 * The document the current user may work on through the application.
	 *
	 * @param int $doc_id Document ID from the form.
	 * @return WP_Post|null Null when it is not a document the user can edit.
	 */
	private static function documento_editable_por_el_usuario( $doc_id ) {
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
		if ( ! self::es_accion( 'crear_documento' ) ) {
			return;
		}

		self::exigir_nonce( 'documentate_app_crear' );

		if ( ! Documentate_App::current_user_can_use_app() ) {
			self::denegar();
		}

		$nuevo_url = Documentate_App_Shell::page_url( array( 'vista' => 'nuevo' ) );
		$titulo = self::texto_enviado( 'documentate_app_titulo' );
		$nombre = self::texto_enviado( 'documentate_app_nombre' );
		$tipo = self::entero_enviado( 'documentate_app_tipo' );

		if ( '' === $titulo || '' === $nombre || 0 === $tipo || ! term_exists( $tipo, 'documentate_doc_type' ) ) {
			self::redirigir( $nuevo_url, array( 'error' => 'datos' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type' => self::POST_TYPE,
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
		Documentate_Documento::guardar_nombre_interno( $post_id, $nombre );
		Documentate_Actividad::registrar_evento( $post_id, 'creó el borrador' );

		self::redirigir( Documentate_App_Editar::url( $post_id ) );
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
		if ( ! self::es_accion( 'guardar_documento' ) ) {
			return;
		}

		$doc_id = self::entero_enviado( 'documentate_app_doc' );
		self::exigir_nonce( 'documentate_app_guardar_' . $doc_id );

		$post = self::documento_editable_por_el_usuario( $doc_id );
		if ( ! $post ) {
			self::denegar();
		}

		$bandeja = self::bandeja_enviada();
		$editar_url = Documentate_App_Editar::url( $post->ID, $bandeja );

		if ( ! Documentate_App_Editar::puede_editar( $post ) ) {
			self::redirigir( $editar_url, array( 'error' => 'bloqueado' ) );
		}

		$basicos = self::datos_basicos( $post );
		$titulo = $basicos['titulo'];
		$error_datos = $basicos['error'];

		Documentate_Documento::guardar_nombre_interno( $post->ID, $basicos['nombre'] );
		if ( Documentate_Roles::es_gestion() ) {
			Documentate_Documento::guardar_anotaciones( $post->ID, self::texto_enviado( 'documentate_app_anotaciones', true ) );
		}

		$error_adjunto = self::procesar_adjunto( $post );

		$resultado = wp_update_post(
			array(
				'ID' => $post->ID,
				'post_title' => $titulo,
			),
			true
		);

		if ( is_wp_error( $resultado ) ) {
			self::redirigir( $editar_url, array( 'error' => 'guardar' ) );
		}

		// Everything the request carried is stored by now; what is missing is
		// reported without moving the document on.
		if ( '' !== $error_datos ) {
			self::redirigir( $editar_url, array( 'error' => $error_datos ) );
		}

		if ( '' !== $error_adjunto ) {
			self::redirigir( $editar_url, array( 'error' => $error_adjunto ) );
		}

		self::aplicar_transicion( $post, $editar_url, $bandeja );

		self::redirigir( $editar_url, array( 'guardado' => '1' ) );
	}

	/**
	 * Move a document on from the document view.
	 *
	 * @return void
	 */
	public static function handle_transition() {
		if ( ! self::es_accion( 'transicion' ) ) {
			return;
		}

		$doc_id = self::entero_enviado( 'documentate_app_doc' );
		self::exigir_nonce( 'documentate_app_transicion_' . $doc_id );

		$post = self::documento_editable_por_el_usuario( $doc_id );
		if ( ! $post ) {
			self::denegar();
		}

		$bandeja = self::bandeja_enviada();
		$detalle_url = Documentate_App_Shell::page_url( self::args_detalle( $post->ID, $bandeja ) );

		self::aplicar_transicion( $post, $detalle_url, $bandeja );

		self::redirigir( $detalle_url, array( 'error' => 'transicion' ) );
	}

	/**
	 * Store a comment written from the activity card.
	 *
	 * @return void
	 */
	public static function handle_comment() {
		if ( ! self::es_accion( 'comentar' ) ) {
			return;
		}

		$doc_id = self::entero_enviado( 'documentate_app_doc' );
		self::exigir_nonce( 'documentate_app_comentar_' . $doc_id );

		$post = self::documento_editable_por_el_usuario( $doc_id );
		if ( ! $post ) {
			self::denegar();
		}

		$bandeja = self::bandeja_enviada();
		$destino = 'editar' === self::texto_enviado( 'documentate_app_redirect_to' )
			? Documentate_App_Editar::url( $post->ID, $bandeja )
			: Documentate_App_Shell::page_url( self::args_detalle( $post->ID, $bandeja ) );

		$resultado = Documentate_Actividad::comentar( $post->ID, self::texto_enviado( 'documentate_app_comentario', true ) );

		self::redirigir(
			$destino,
			is_wp_error( $resultado ) ? array( 'error' => 'comentario' ) : array( 'comentado' => '1' )
		);
	}

	/**
	 * Run the transition the form asks for, if any, and redirect on the result.
	 *
	 * Returns without doing anything when no transition was posted, so the
	 * caller can carry on with its own redirect.
	 *
	 * @param WP_Post $post      Document.
	 * @param string  $url_error Where to come back to when the transition fails.
	 * @param string  $bandeja   Tray the form came from, so the document view keeps it.
	 * @return void
	 */
	private static function aplicar_transicion( $post, $url_error, $bandeja = '' ) {
		$clave = sanitize_key( self::texto_enviado( 'documentate_app_transicion' ) );
		if ( '' === $clave ) {
			return;
		}

		// Only what the application draws as a button: archiving is a wp-admin
		// decision and must not be reachable by posting its key here.
		$disponibles = Documentate_App_Shell::transiciones_app( $post );
		if ( ! isset( $disponibles[ $clave ] ) ) {
			self::redirigir( $url_error, array( 'error' => 'transicion' ) );
		}

		$resultado = Documentate_Transiciones::aplicar( $post->ID, $clave, self::motivo_enviado() );
		if ( is_wp_error( $resultado ) ) {
			$error = 'motivo_requerido' === $resultado->get_error_code() ? 'motivo' : 'transicion';
			self::redirigir( $url_error, array( 'error' => $error ) );
		}

		$destino = self::destino_transicion( $post->ID, $clave, $bandeja );
		self::redirigir( $destino[0], $destino[1] );
	}

	/**
	 * Where the application lands after a transition, and with which flag.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $clave   Transition key.
	 * @param string $bandeja Tray the form came from.
	 * @return array{0:string,1:array<string,string>}
	 */
	private static function destino_transicion( $post_id, $clave, $bandeja = '' ) {
		$bandera = Documentate_Transiciones::bandera( $clave );
		$args = '' !== $bandera ? array( $bandera => '1' ) : array();

		// Three rules raise the "enviado" flag and each one has its own
		// sentence, which the status alone cannot tell apart (enviar_revision
		// and pasar_admin both land in "pending").
		if ( 'enviado' === $bandera ) {
			$args['transicion'] = $clave;
		}

		if ( 'bandeja' === Documentate_Transiciones::redireccion( $clave ) ) {
			$bandeja = Documentate_Roles::es_administracion() ? 'revision' : 'revisar';

			return array( Documentate_App_Shell::page_url( array( 'bandeja' => $bandeja ) ), $args );
		}

		return array( Documentate_App_Shell::page_url( self::args_detalle( $post_id, $bandeja ) ), $args );
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
	private static function procesar_adjunto( $post ) {
		$archivo = self::archivo_enviado();
		$quitar = '' !== self::texto_enviado( 'documentate_app_quitar_adjunto' );

		if ( empty( $archivo ) ) {
			if ( $quitar ) {
				Documentate_App_Adjuntos::quitar( $post->ID );
			}

			return '';
		}

		if ( ! self::adjunto_aceptable( $archivo ) ) {
			return 'adjunto';
		}

		if ( $quitar ) {
			Documentate_App_Adjuntos::quitar( $post->ID );
		}

		$guardado = Documentate_App_Adjuntos::guardar( $post->ID, $archivo );

		return is_wp_error( $guardado ) ? self::bandera_de_adjunto( $guardado ) : '';
	}

	/**
	 * The upload posted by the file input of the edit form.
	 *
	 * @return array<string,mixed> Empty when the form carried no file.
	 */
	private static function archivo_enviado() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified by the handler; the upload is validated by Documentate_App_Adjuntos::validar().
		$archivo = isset( $_FILES[ self::CAMPO_ADJUNTO ] ) ? (array) $_FILES[ self::CAMPO_ADJUNTO ] : array();
		$error = isset( $archivo['error'] ) ? (int) $archivo['error'] : UPLOAD_ERR_NO_FILE;

		return UPLOAD_ERR_NO_FILE === $error ? array() : $archivo;
	}

	/**
	 * Whether the posted file may become the file of the document.
	 *
	 * Documentate_App_Adjuntos::guardar() goes through
	 * wp_handle_sideload(), which skips the is_uploaded_file() test a normal
	 * upload gets, so the request boundary is where that test belongs.
	 *
	 * @param array<string,mixed> $archivo Upload posted by the form.
	 * @return bool
	 */
	private static function adjunto_aceptable( array $archivo ) {
		$ruta = isset( $archivo['tmp_name'] ) ? (string) $archivo['tmp_name'] : '';
		$es_subida = '' !== $ruta && is_uploaded_file( $ruta );

		// The filter exists so the test suite can hand a fixture written to a
		// temporary file to the handler. It is fenced to the PHPUnit bootstrap
		// (WP_TESTS_DOMAIN is defined by wp-phpunit and by nothing else): a
		// check that says "this path really is an upload of this request" must
		// not be something a plugin or a theme of the site can switch off.
		if ( defined( 'WP_TESTS_DOMAIN' ) ) {
			/**
			 * Whether a posted path really is an upload of the current request.
			 *
			 * @param bool   $es_subida Result of is_uploaded_file().
			 * @param string $ruta      Temporary path posted by the browser.
			 */
			$es_subida = (bool) apply_filters( 'documentate_app_adjunto_es_subida', $es_subida, $ruta );
		}

		return $es_subida && ! is_wp_error( Documentate_App_Adjuntos::validar( $archivo ) );
	}

	/**
	 * Which error the user is shown when the file could not be stored.
	 *
	 * @param WP_Error $error Error from Documentate_App_Adjuntos::guardar().
	 * @return string Error flag.
	 */
	private static function bandera_de_adjunto( WP_Error $error ) {
		return 'sin_permiso' === $error->get_error_code() ? 'adjunto_permiso' : 'adjunto';
	}
}
