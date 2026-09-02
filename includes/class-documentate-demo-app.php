<?php
/**
 * Demo data for the front-end application: a realistic set of documents.
 *
 * Documentate_Demo_Data seeds document TYPES and a handful of standalone
 * example documents; this class seeds the story the application demo walks
 * through — twelve documents spread across every status, role and área, with
 * the events, "devuelto" marks, an attachment and a comment a real site would
 * have. Every document it creates is marked with _documentate_demo_app only
 * (never _documentate_demo_type_id, which DocumentateDemoDocumentsTest counts).
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Demo_App
 *
 * Static seeder for the application's demo document set.
 */
class Documentate_Demo_App {

	/**
	 * Post meta that marks a document created by this seeder.
	 *
	 * @var string
	 */
	const META_MARCA = '_documentate_demo_app';

	/**
	 * The smallest valid PDF the mime check accepts, for the one PDF attachment.
	 *
	 * @var string
	 */
	const PDF_DEMO = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

	/**
	 * Ensure demo types, categories and users exist, regardless of whether the
	 * "seed on activation" option was already consumed.
	 *
	 * Documentate_Demo_Data's own seeders are gated by the
	 * documentate_seed_demo_documents option so they run once, right after
	 * activation. reseed() is called later, by hand or from a script, when
	 * that option is long gone: this flips it on for the duration of the call
	 * so the same tested seeders run again, then restores whatever value the
	 * option had. Never touches anything on a production environment.
	 *
	 * @return void
	 */
	public static function asegurar_entorno() {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return;
		}

		$original = get_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		try {
			Documentate_Demo_Data::ensure_default_media();
			Documentate_Demo_Data::maybe_seed_default_doc_types();
			Documentate_Demo_Data::maybe_seed_demo_categories();
			Documentate_Demo_Data::maybe_seed_demo_users();
		} finally {
			if ( false === $original ) {
				delete_option( 'documentate_seed_demo_documents' );
			} else {
				update_option( 'documentate_seed_demo_documents', $original );
			}
		}
	}

	/**
	 * Create the demo document set, skipping documents that already exist.
	 *
	 * Idempotent by _documentate_demo_app marker + post title: a document is
	 * only (re)created when no marked document of that exact title exists yet,
	 * so running this twice never duplicates anything. Notification mails are
	 * suspended for the duration of the call.
	 *
	 * seed() is reached from an ordinary request (Documentate_Demo_Data hooks
	 * it on init priority 60, so the first hit after activation — anonymous
	 * front-end traffic, a cron ping, WP-CLI — can trigger it), and every step
	 * below impersonates a different demo actor via wp_set_current_user()
	 * without restoring it. Whoever was logged in when seed() was called must
	 * still be logged in (or logged out) once it returns, so the caller's
	 * identity is captured up front and restored in the finally block below,
	 * even when a step throws.
	 *
	 * @return int[] IDs of the twelve demo documents (created just now or already there).
	 */
	public static function seed() {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return array();
		}

		self::asegurar_entorno();

		$usuario_anterior = get_current_user_id();
		add_filter( 'documentate_suspend_notifications', '__return_true' );
		try {
			$ids = array();
			foreach ( self::documentos() as $definicion ) {
				$ids[] = self::crear_si_falta( $definicion );
			}
		} finally {
			remove_filter( 'documentate_suspend_notifications', '__return_true' );
			wp_set_current_user( $usuario_anterior );
		}

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Delete every document this seeder created, then seed again.
	 *
	 * Force-deleting a post deletes its comments (events included) but only
	 * reparents its attachment children rather than deleting them, so those
	 * are removed explicitly first.
	 *
	 * @return int[] IDs of the twelve demo documents, freshly created.
	 */
	public static function reseed() {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return array();
		}

		$usuario_anterior = get_current_user_id();
		try {
			foreach ( self::demo_document_ids() as $post_id ) {
				self::borrar_adjuntos( $post_id );
				wp_delete_post( $post_id, true );
			}
		} finally {
			wp_set_current_user( $usuario_anterior );
		}

		return self::seed();
	}

	/**
	 * Delete the attachment children of a document.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	private static function borrar_adjuntos( $post_id ) {
		$hijos = get_children(
			array(
				'post_parent' => $post_id,
				'post_type' => 'attachment',
				'fields' => 'ids',
			)
		);

		foreach ( $hijos as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Move a document straight to a status, writing the "devuelto" mark and
	 * relying on nothing about the current user.
	 *
	 * Seeding must be able to put a document in any state regardless of who
	 * runs it (WP-CLI with no logged-in user, a test, an anonymous first
	 * request), so this bypasses Documentate_Transiciones::permitida() the same
	 * way Documentate_Transiciones::aplicar() does: by flagging the change as
	 * already in progress before calling wp_update_post().
	 *
	 * @param int        $post_id  Document ID.
	 * @param string     $status   Destination status.
	 * @param array|null $devuelto Devuelto payload (motivo, desde, a), or null to clear the mark.
	 * @return void
	 */
	public static function poner_estado( $post_id, $status, $devuelto = null ) {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return;
		}

		if ( null === $devuelto ) {
			Documentate_Documento::limpiar_devuelto( $post_id );
		} else {
			Documentate_Documento::marcar_devuelto( $post_id, $devuelto['motivo'], $devuelto['desde'], $devuelto['a'] );
		}

		self::forzar_estado( $post_id, $status );
	}

	/**
	 * Change a post's status while flagging it as an in-progress transition.
	 *
	 * Two independent gates would otherwise stand in the way of a status
	 * change nobody asked for through the real workflow: Rule 0 of
	 * Documentate_Workflow, which Documentate_Transiciones::$en_curso (private;
	 * reflection sets it the same way aplicar() does) tells to let the change
	 * through, and freeze_locked_document_data(), which reverts the whole save
	 * when the CURRENT user may not modify the document's CURRENT status.
	 * Seeding must not depend on who that happens to be (WP-CLI with nobody
	 * logged in, a test, the real actor of the step), so an administrator
	 * momentarily stands in for the duration of the update.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $status  Destination status.
	 * @return void
	 */
	private static function forzar_estado( $post_id, $status ) {
		$en_curso = new ReflectionProperty( Documentate_Transiciones::class, 'en_curso' );
		$en_curso->setAccessible( true );
		$en_curso_anterior = $en_curso->getValue();
		$en_curso->setValue( null, array( (int) $post_id, (string) $status, '' ) );

		$usuario_anterior = get_current_user_id();
		wp_set_current_user( self::admin_id() );

		try {
			wp_update_post(
				array(
					'ID' => $post_id,
					'post_status' => $status,
				),
				true
			);
		} finally {
			wp_set_current_user( $usuario_anterior );
			$en_curso->setValue( null, $en_curso_anterior );
		}
	}

	/**
	 * Run one transition of Documentate_Transiciones' rule table on a demo
	 * document, impersonating the actor and recording the same event text and
	 * "devuelto" mark aplicar() would have written.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $actor   Demo login of whoever performs the move ("admin", "editor1", "author1").
	 * @param string $clave   Rule key from Documentate_Transiciones::reglas().
	 * @param string $desde   Stored status the rule starts from (disambiguates "devolver_area").
	 * @param string $motivo  Reason, required by return rules.
	 * @return void
	 */
	private static function mover( $post_id, $actor, $clave, $desde, $motivo = '' ) {
		$regla = Documentate_Transiciones::regla( $clave, $desde );
		if ( null === $regla ) {
			return;
		}

		wp_set_current_user( self::usuario_id( $actor ) );

		$devuelto = null;
		$texto_evento = (string) $regla['evento'];
		if ( $regla['motivo'] ) {
			$devuelto = array(
				'motivo' => $motivo,
				'desde' => 'pending' === $desde ? 'administracion' : 'gestion',
				'a' => 'en_gestion' === $regla['destino'] ? 'gestion' : 'area',
			);
			$texto_evento .= ': «' . $motivo . '»';
		}

		Documentate_Demo_App_Reloj::registrar_evento( $post_id, $texto_evento, $motivo );
		self::poner_estado( $post_id, (string) $regla['destino'], $devuelto );
	}

	/**
	 * Create one demo document (with its fields, attachment, steps and
	 * comment) unless a marked document of that title already exists.
	 *
	 * @param array $doc Document definition (see documentos()).
	 * @return int Document ID, or 0 when it could not be created.
	 */
	private static function crear_si_falta( array $doc ) {
		$existente = self::buscar_por_titulo( $doc['titulo'] );
		if ( $existente > 0 ) {
			return $existente;
		}

		Documentate_Demo_App_Reloj::iniciar( $doc );

		$post_id = self::crear_documento( $doc );
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( isset( $doc['adjunto'] ) ) {
			self::adjuntar( $post_id, $doc['adjunto'], $doc['autor'] );
		}

		foreach ( $doc['pasos'] as $paso ) {
			self::mover( $post_id, $paso['actor'], $paso['clave'], $paso['desde'], isset( $paso['motivo'] ) ? $paso['motivo'] : '' );
		}

		if ( isset( $doc['devuelto_directo'] ) ) {
			self::devolver_sin_transicion( $post_id, $doc['devuelto_directo'] );
		}

		if ( isset( $doc['comentario'] ) ) {
			wp_set_current_user( self::usuario_id( $doc['comentario']['actor'] ) );
			$comentario_id = Documentate_Actividad::comentar( $post_id, $doc['comentario']['texto'] );
			if ( ! is_wp_error( $comentario_id ) ) {
				Documentate_Demo_App_Reloj::marcar( (int) $comentario_id );
			}
		}

		return $post_id;
	}

	/**
	 * Write a "devuelto" mark and event with no status change of its own.
	 *
	 * One demo document (a directo type returned as if by gestión) needs the
	 * devuelto mark and the matching event while staying in draft, which is
	 * not a move the rule table has a row for.
	 *
	 * @param int   $post_id Document ID.
	 * @param array $datos   actor, motivo.
	 * @return void
	 */
	private static function devolver_sin_transicion( $post_id, array $datos ) {
		wp_set_current_user( self::usuario_id( $datos['actor'] ) );

		Documentate_Demo_App_Reloj::registrar_evento(
			$post_id,
			'devolvió el documento al área: «' . $datos['motivo'] . '»',
			$datos['motivo']
		);

		self::poner_estado(
			$post_id,
			'draft',
			array(
				'motivo' => $datos['motivo'],
				'desde' => 'gestion',
				'a' => 'area',
			)
		);
	}

	/**
	 * Create the draft post of a demo document: type, internal name, área,
	 * fields and post_content.
	 *
	 * @param array $doc Document definition (see documentos()).
	 * @return int Document ID, or 0 when the type is missing or creation failed.
	 */
	private static function crear_documento( array $doc ) {
		$termino = get_term_by( 'slug', $doc['tipo'], 'documentate_doc_type' );
		if ( ! $termino instanceof WP_Term ) {
			return 0;
		}

		$actor_id = self::usuario_id( $doc['autor'] );
		wp_set_current_user( $actor_id );

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'post_title' => $doc['titulo'],
				'post_author' => $actor_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		wp_set_post_terms( $post_id, array( $termino->term_id ), 'documentate_doc_type', false );
		Documentate_Documento::guardar_nombre_interno( $post_id, $doc['nombre'] );
		update_post_meta( $post_id, self::META_MARCA, '1' );
		self::asignar_area( $post_id, isset( $doc['area'] ) ? $doc['area'] : '' );

		$campos = self::rellenar_campos(
			$post_id,
			$termino->term_id,
			$doc['titulo'],
			! empty( $doc['gestion'] ),
			isset( $doc['omitir'] ) ? $doc['omitir'] : array(),
			isset( $doc['forzar'] ) ? $doc['forzar'] : array()
		);

		$contenido = Documentate_Demo_Data::build_structured_demo_content( $campos );
		if ( '' !== $contenido ) {
			wp_update_post(
				array(
					'ID' => $post_id,
					'post_content' => $contenido,
				)
			);
		}

		Documentate_Demo_App_Reloj::registrar_evento( $post_id, 'creó el borrador' );

		return $post_id;
	}

	/**
	 * Assign a document to its área (category), by name.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $nombre  Category name; empty leaves the document uncategorised.
	 * @return void
	 */
	private static function asignar_area( $post_id, $nombre ) {
		if ( '' === $nombre ) {
			return;
		}

		$termino = get_term_by( 'name', $nombre, 'category' );
		if ( $termino instanceof WP_Term ) {
			wp_set_post_terms( $post_id, array( $termino->term_id ), 'category', false );
		}
	}

	/**
	 * Fill the área rows (and, when asked, the gestión rows) of a document's
	 * schema with plausible content, storing meta the same way the sections
	 * metabox would.
	 *
	 * @param int    $post_id         Document ID.
	 * @param int    $term_id         Document type term ID.
	 * @param string $titulo          Official title (generator context).
	 * @param bool   $incluir_gestion Whether to also fill the gestión rows.
	 * @param array  $omitir          Slugs to skip entirely (left unset).
	 * @param array  $forzar          Slug => structured entry overrides/additions, applied last.
	 * @return array<string,array{type:string,value:string}> Structured fields, for post_content.
	 */
	private static function rellenar_campos( $post_id, $term_id, $titulo, $incluir_gestion, array $omitir, array $forzar ) {
		$grupos = Documentate_Campos_Rol::agrupar( Documentate_Documents::get_term_schema( $term_id ) );
		$contexto = array( 'document_title' => $titulo );

		$campos = self::rellenar_filas( $post_id, $grupos[ Documentate_Campos_Rol::ROL_AREA ], $contexto, $omitir );
		if ( $incluir_gestion ) {
			$campos += self::rellenar_filas( $post_id, $grupos[ Documentate_Campos_Rol::ROL_GESTION ], $contexto, $omitir );
		}

		foreach ( $forzar as $slug => $entrada ) {
			update_post_meta( $post_id, 'documentate_field_' . $slug, $entrada['value'] );
			$campos[ $slug ] = $entrada;
		}

		return $campos;
	}

	/**
	 * Generate and store demo values for a group of schema rows.
	 *
	 * Reuses Documentate_Demo_Data's public generators, the same ones the
	 * plugin's other demo documents are built from.
	 *
	 * @param int   $post_id  Document ID.
	 * @param array $filas    Schema rows (legacy shape, one rol group).
	 * @param array $contexto Generator context (document_title).
	 * @param array $omitir   Slugs to skip entirely.
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function rellenar_filas( $post_id, array $filas, array $contexto, array $omitir ) {
		$campos = array();

		foreach ( $filas as $fila ) {
			$slug = isset( $fila['slug'] ) ? sanitize_key( $fila['slug'] ) : '';
			if ( '' === $slug || in_array( $slug, $omitir, true ) ) {
				continue;
			}

			$entrada = self::valor_de_fila( $slug, $fila, $contexto );
			if ( null === $entrada ) {
				continue;
			}

			update_post_meta( $post_id, 'documentate_field_' . $slug, $entrada['value'] );
			$campos[ $slug ] = $entrada;
		}

		return $campos;
	}

	/**
	 * Build the structured entry (type + sanitised value) of one schema row.
	 *
	 * @param string $slug     Field slug.
	 * @param array  $fila     Schema row (legacy shape).
	 * @param array  $contexto Generator context.
	 * @return array{type:string,value:string}|null Null for an empty repeater.
	 */
	private static function valor_de_fila( $slug, array $fila, array $contexto ) {
		$type = isset( $fila['type'] ) ? sanitize_key( $fila['type'] ) : 'textarea';

		if ( 'array' === $type ) {
			$item_schema = isset( $fila['item_schema'] ) && is_array( $fila['item_schema'] ) ? $fila['item_schema'] : array();
			$items = Documentate_Demo_Data::generate_demo_array_items( $slug, $item_schema, $contexto );
			if ( empty( $items ) ) {
				return null;
			}

			return array(
				'type' => 'array',
				'value' => wp_json_encode( $items, JSON_UNESCAPED_UNICODE ),
			);
		}

		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		$data_type = isset( $fila['data_type'] ) ? sanitize_key( $fila['data_type'] ) : 'text';
		$valor = Documentate_Demo_Data::generate_demo_scalar_value( $slug, $type, $data_type, 1, $contexto );

		if ( 'rich' === $type ) {
			$valor = wp_kses_post( $valor );
		} elseif ( 'single' === $type ) {
			$valor = sanitize_text_field( $valor );
		} else {
			$valor = sanitize_textarea_field( $valor );
		}

		return array(
			'type' => $type,
			'value' => $valor,
		);
	}

	/**
	 * Attach a fixture file to a document, impersonating the actor.
	 *
	 * @param int    $post_id Document ID.
	 * @param array  $fixture tipo ("pdf" or the fixtures/ filename to copy), nombre.
	 * @param string $actor   Demo login of the uploader.
	 * @return void
	 */
	private static function adjuntar( $post_id, array $fixture, $actor ) {
		wp_set_current_user( self::usuario_id( $actor ) );

		$archivo = self::archivo_temporal( $fixture );
		if ( null === $archivo ) {
			return;
		}

		$resultado = Documentate_App_Adjuntos::guardar( $post_id, $archivo );
		if ( ! is_wp_error( $resultado ) ) {
			// guardar() records its own "adjuntó el fichero" event internally
			// and does not hand back its comment ID, so the freshest event of
			// this document (by ID, not by date — its date is what we are
			// about to change) is backdated onto the demo clock.
			Documentate_Demo_App_Reloj::marcar( Documentate_Demo_App_Reloj::ultimo_evento_id( $post_id ) );
		}
	}

	/**
	 * Build a $_FILES-shaped array backed by a real temporary file.
	 *
	 * @param array $fixture tipo ("pdf" for the embedded sample, or the
	 *                       fixtures/ filename to copy for an ODT/DOCX), nombre.
	 * @return array<string,mixed>|null Null when the source file is unreadable.
	 */
	private static function archivo_temporal( array $fixture ) {
		if ( 'pdf' === $fixture['tipo'] ) {
			$contenido = self::PDF_DEMO;
			$mime = 'application/pdf';
		} else {
			$origen = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture['tipo'];
			if ( ! file_exists( $origen ) ) {
				return null;
			}
			$contenido = file_get_contents( $origen ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- Reading a bundled fixture, not user input.
			$mime = 'application/vnd.oasis.opendocument.text';
		}

		if ( false === $contenido ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $fixture['nombre'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a temp copy of a bundled fixture, not user input.
		if ( ! $tmp || false === file_put_contents( $tmp, $contenido ) ) {
			return null;
		}

		return array(
			'name' => $fixture['nombre'],
			'type' => $mime,
			'tmp_name' => $tmp,
			'error' => 0,
			'size' => filesize( $tmp ),
		);
	}

	/**
	 * Resolve a demo login ("admin", "editor1", "author1") to a user ID.
	 *
	 * "admin" falls back to any administrator when no user is literally
	 * logged in as "admin" (a PHPUnit test creates its own admin account).
	 *
	 * @param string $login Demo login.
	 * @return int User ID, or 0 when it cannot be resolved.
	 */
	private static function usuario_id( $login ) {
		if ( 'admin' === $login ) {
			return self::admin_id();
		}

		$usuario = get_user_by( 'login', $login );

		return $usuario instanceof WP_User ? (int) $usuario->ID : 0;
	}

	/**
	 * Resolve the "admin" demo actor: the literal account, else any administrator.
	 *
	 * @return int User ID, or the current user as a last resort.
	 */
	private static function admin_id() {
		$usuario = get_user_by( 'login', 'admin' );
		if ( $usuario instanceof WP_User ) {
			return (int) $usuario->ID;
		}

		$admins = get_users(
			array(
				'role' => 'administrator',
				'number' => 1,
				'orderby' => 'ID',
				'fields' => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : get_current_user_id();
	}

	/**
	 * IDs of every document this seeder created, oldest first.
	 *
	 * Goes straight to the database, like Documentate_Demo_Data's own lookup:
	 * seeding runs in contexts with no user (WP-CLI, an anonymous first
	 * request), where the access protection hides every document from
	 * WP_Query.
	 *
	 * @return int[]
	 */
	private static function demo_document_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off lookup during demo seeding; WP_Query is filtered by the access protection when no user is logged in.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = %s AND pm.meta_key = %s ORDER BY p.ID ASC",
				'documentate_document',
				self::META_MARCA
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * ID of the marked demo document with an exact title, if any.
	 *
	 * Trashed documents are excluded: administración can send one to the
	 * trash, and a trashed row must not count toward idempotency (it would
	 * make seed() skip creating a replacement while the demo shows fewer than
	 * twelve documents).
	 *
	 * @param string $titulo Post title.
	 * @return int Document ID, or 0.
	 */
	private static function buscar_por_titulo( $titulo ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency check during demo seeding; mirrors demo_document_ids().
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = %s AND p.post_title = %s AND pm.meta_key = %s AND p.post_status <> 'trash' LIMIT 1",
				'documentate_document',
				$titulo,
				self::META_MARCA
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Plausible nested provider content for the two PG demo documents whose
	 * gestión fields are filled: one service provider, one supplier, one
	 * external expert.
	 *
	 * The generic generators do not model nested repeaters (a provider's own
	 * "conceptos" rows), so these seven gestión fields are supplied directly.
	 * All three provider blocks (servicios/suministros/expertos) share the
	 * item_schema of the propuesta-gasto template, so every row here carries
	 * the same shape, "conceptos" included.
	 *
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function proveedores_pg_demo() {
		$servicios = array(
			array(
				'proveedor' => 'Talleres Digitales Canarias S.L.',
				'cif' => 'B38112233',
				'email' => 'administracion@talleresdigitales.es',
				'telefono' => '922334455',
				'bruto' => '1800',
				// documentate-calculos.js reads IGIC and IRPF as euro
				// amounts (total = bruto + IGIC - IRPF), not as rates.
				'igic' => '126',
				'irpf' => '0',
				'total' => '1926',
				'conceptos' => array(
					array(
						'concepto' => 'Taller de robótica educativa (12 h)',
						'cantidad' => '1',
						'unitario' => '1800',
						'total' => '1800',
					),
				),
			),
		);

		$suministros = array(
			array(
				'proveedor' => 'Papelería Insular S.A.',
				'cif' => 'A38223344',
				'email' => 'pedidos@papeleriainsular.es',
				'telefono' => '922445566',
				'bruto' => '650',
				'igic' => '45.5',
				'irpf' => '0',
				'total' => '695.5',
				'conceptos' => array(
					array(
						'concepto' => 'Material fungible de aula',
						'cantidad' => '1',
						'unitario' => '650',
						'total' => '650',
					),
				),
			),
		);

		$expertos = array(
			array(
				'proveedor' => 'Dra. Marta Sánchez Delgado',
				'cif' => '43987654C',
				'email' => 'marta.sanchez@universidad.es',
				'telefono' => '650998877',
				'bruto' => '400',
				'igic' => '0',
				'irpf' => '60',
				'total' => '340',
				'conceptos' => array(
					array(
						'concepto' => 'Asesoría metodológica del proyecto',
						'cantidad' => '1',
						'unitario' => '400',
						'total' => '400',
					),
				),
			),
		);

		return array(
			'servicios' => array(
				'type' => 'array',
				'value' => wp_json_encode( $servicios, JSON_UNESCAPED_UNICODE ),
			),
			'suministros' => array(
				'type' => 'array',
				'value' => wp_json_encode( $suministros, JSON_UNESCAPED_UNICODE ),
			),
			'expertos' => array(
				'type' => 'array',
				'value' => wp_json_encode( $expertos, JSON_UNESCAPED_UNICODE ),
			),
			// 1926 + 695.50 + 340: the same total the calculator writes when
			// the editor opens, so figure and letter agree on screen.
			'gasto_letra' => array(
				'type' => 'single',
				'value' => 'dos mil novecientos sesenta y un euros con cincuenta céntimos',
			),
			'gasto_numero' => array(
				'type' => 'single',
				'value' => '2961.5',
			),
			'partida' => array(
				'type' => 'single',
				'value' => '18.03.322B.229.0100',
			),
		);
	}

	/**
	 * Schema-row slugs proveedores_pg_demo() supplies by hand, so the generic
	 * generator does not overwrite them with a malformed nested value.
	 *
	 * @return string[]
	 */
	private static function proveedores_pg_slugs() {
		return array( 'servicios', 'suministros', 'expertos', 'gasto_letra', 'gasto_numero', 'partida' );
	}

	/**
	 * The twelve demo documents of the application walkthrough.
	 *
	 * Each entry: tipo (doc type slug), nombre (internal name), titulo
	 * (official title / post_title), autor (demo login), area (category
	 * name, optional), gestion (whether to fill gestión rows), omitir /
	 * forzar (field overrides), adjunto (fixture to attach, optional),
	 * pasos (ordered Documentate_Transiciones moves), devuelto_directo
	 * (a devuelto mark with no matching rule, optional), comentario
	 * (one activity comment, optional).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function documentos() {
		return array(
			self::doc_material_aulas(),
			self::doc_jornadas_competencia(),
			self::doc_certificacion_tribunal(),
			self::doc_listado_definitivo(),
			self::doc_dotacion_biblioteca(),
			self::doc_formacion_profesorado(),
			self::doc_bases_programa_piloto(),
			self::doc_calendario_admision(),
			self::doc_comision_formacion(),
			self::doc_bases_plan_formacion(),
			self::doc_renovacion_licencias(),
			self::doc_instrucciones_inicio_curso(),
		);
	}

	/**
	 * PG "Material aulas digitales" — draft, with a PDF attachment.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_material_aulas() {
		return array(
			'tipo' => 'propuesta-gasto',
			'nombre' => 'Material aulas digitales',
			'titulo' => 'Propuesta de gasto para material didáctico de las aulas digitales del Departamento de Proyectos',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => false,
			'adjunto' => array(
				'tipo' => 'pdf',
				'nombre' => 'presupuesto-material-aulas.pdf',
			),
			'pasos' => array(),
		);
	}

	/**
	 * CONV "Jornadas competencia digital" — draft.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_jornadas_competencia() {
		return array(
			'tipo' => 'convocatoria-reunion',
			'nombre' => 'Jornadas competencia digital',
			'titulo' => 'Convocatoria de las Jornadas de Competencia Digital Docente',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => false,
			'pasos' => array(),
		);
	}

	/**
	 * HC "Certificación tribunal materiales" — draft, devuelto by editor1
	 * desde gestión (a mark with no matching table row: HC goes direct to
	 * administración, so it never really visits en_gestion; this document
	 * simply illustrates the devuelto notice on a draft in wp-admin/the app).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_certificacion_tribunal() {
		return array(
			'tipo' => 'hace-constar',
			'nombre' => 'Certificación tribunal materiales',
			'titulo' => 'Hace constar la participación en el tribunal de selección de materiales didácticos',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => false,
			'pasos' => array(),
			'devuelto_directo' => array(
				'actor' => 'editor1',
				'motivo' => 'Falta el anexo firmado por la dirección',
			),
		);
	}

	/**
	 * RES "Listado definitivo piloto innovación" — en_gestion, ODT attachment,
	 * área fields filled and gestión fields still empty, with one comment.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_listado_definitivo() {
		return array(
			'tipo' => 'resolucion-administrativa',
			'nombre' => 'Listado definitivo piloto innovación',
			'titulo' => 'Resolución por la que se aprueba el listado definitivo de centros admitidos en el programa piloto de innovación educativa',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => false,
			'adjunto' => array(
				'tipo' => 'demo-wp-documentate.odt',
				'nombre' => 'listado-definitivo-piloto.odt',
			),
			'pasos' => array(
				array(
					'actor' => 'author1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
			),
			'comentario' => array(
				'actor' => 'author1',
				'texto' => 'El anexo con el listado va en la última página del ODT.',
			),
		);
	}

	/**
	 * PG "Dotación biblioteca escolar" — en_gestion, área fields filled,
	 * gestión fields EMPTY (gestión has not touched it yet).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_dotacion_biblioteca() {
		return array(
			'tipo' => 'propuesta-gasto',
			'nombre' => 'Dotación biblioteca escolar',
			'titulo' => 'Propuesta de gasto para la dotación de fondos bibliográficos de los centros del área',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => false,
			'pasos' => array(
				array(
					'actor' => 'author1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
			),
		);
	}

	/**
	 * PG "Formación profesorado metodologías" — pending, gestión fields filled.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_formacion_profesorado() {
		return array(
			'tipo' => 'propuesta-gasto',
			'nombre' => 'Formación profesorado metodologías',
			'titulo' => 'Propuesta de gasto para la formación del profesorado en metodologías activas',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => true,
			'omitir' => self::proveedores_pg_slugs(),
			'forzar' => self::proveedores_pg_demo(),
			'pasos' => array(
				array(
					'actor' => 'author1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
			),
		);
	}

	/**
	 * RES "Bases programa piloto innovación" — publish.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_bases_programa_piloto() {
		return array(
			'tipo' => 'resolucion-administrativa',
			'nombre' => 'Bases programa piloto innovación',
			'titulo' => 'Resolución por la que se aprueban las bases del programa piloto de innovación educativa',
			'autor' => 'author1',
			'area' => 'Departamento de Proyectos',
			'gestion' => true,
			'pasos' => array(
				array(
					'actor' => 'author1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'clave' => 'aprobar',
					'desde' => 'pending',
				),
			),
		);
	}

	/**
	 * RES "Calendario de admisión 2027" — en_gestion, devuelto by admin,
	 * missing the "expediente" gestión field the motivo names.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_calendario_admision() {
		return array(
			'tipo' => 'resolucion-administrativa',
			'nombre' => 'Calendario de admisión 2027',
			'titulo' => 'Resolución por la que se aprueba el calendario del proceso de admisión para el curso 2026-2027',
			'autor' => 'editor1',
			'area' => 'Subdirección de Administración',
			'gestion' => true,
			'omitir' => array( 'expediente' ),
			'pasos' => array(
				array(
					'actor' => 'editor1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'clave' => 'devolver_gestion',
					'desde' => 'pending',
					'motivo' => 'Falta el número de expediente',
				),
			),
		);
	}

	/**
	 * CONV "Comisión formación septiembre" — pending (directo).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_comision_formacion() {
		return array(
			'tipo' => 'convocatoria-reunion',
			'nombre' => 'Comisión formación septiembre',
			'titulo' => 'Convocatoria de la Comisión de Formación del mes de septiembre',
			'autor' => 'editor1',
			'area' => 'Subdirección de Administración',
			'gestion' => false,
			'pasos' => array(
				array(
					'actor' => 'editor1',
					'clave' => 'enviar_revision',
					'desde' => 'draft',
				),
			),
		);
	}

	/**
	 * RES "Bases plan de formación 2026-27" — publish.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_bases_plan_formacion() {
		return array(
			'tipo' => 'resolucion-administrativa',
			'nombre' => 'Bases plan de formación 2026-27',
			'titulo' => 'Resolución por la que se aprueban las bases del plan de formación del profesorado 2026-2027',
			'autor' => 'editor1',
			'area' => 'Subdirección de Administración',
			'gestion' => true,
			'pasos' => array(
				array(
					'actor' => 'editor1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'clave' => 'aprobar',
					'desde' => 'pending',
				),
			),
		);
	}

	/**
	 * PG "Renovación licencias aulas virtuales" — draft, devuelto by admin
	 * straight from pending, gestión fields filled (it had reached
	 * administración before being sent back).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_renovacion_licencias() {
		return array(
			'tipo' => 'propuesta-gasto',
			'nombre' => 'Renovación licencias aulas virtuales',
			'titulo' => 'Propuesta de gasto para la renovación de licencias de las aulas virtuales',
			'autor' => 'editor1',
			'area' => 'Subdirección de Administración',
			'gestion' => true,
			'omitir' => self::proveedores_pg_slugs(),
			'forzar' => self::proveedores_pg_demo(),
			'pasos' => array(
				array(
					'actor' => 'editor1',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'clave' => 'devolver_area',
					'desde' => 'pending',
					'motivo' => 'Revisar la partida presupuestaria',
				),
			),
		);
	}

	/**
	 * RES "Instrucciones inicio de curso 2025-26" — archived.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_instrucciones_inicio_curso() {
		return array(
			'tipo' => 'resolucion-administrativa',
			'nombre' => 'Instrucciones inicio de curso 2025-26',
			'titulo' => 'Resolución por la que se dictan instrucciones para el inicio del curso 2025-2026',
			'autor' => 'admin',
			'gestion' => true,
			'pasos' => array(
				array(
					'actor' => 'admin',
					'clave' => 'enviar_gestion',
					'desde' => 'draft',
				),
				array(
					'actor' => 'admin',
					'clave' => 'pasar_admin',
					'desde' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'clave' => 'aprobar',
					'desde' => 'pending',
				),
				array(
					'actor' => 'admin',
					'clave' => 'archivar',
					'desde' => 'publish',
				),
			),
		);
	}
}
