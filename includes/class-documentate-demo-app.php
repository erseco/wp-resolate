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
	const META_MARK = '_documentate_demo_app';

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
	public static function ensure_environment() {
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

		self::ensure_environment();

		$previous_user = get_current_user_id();
		add_filter( 'documentate_suspend_notifications', '__return_true' );
		try {
			$ids = array();
			foreach ( self::documents() as $definition ) {
				$ids[] = self::create_if_missing( $definition );
			}
			Documentate_Demo_Names::apply_to_older_demo_documents();
		} finally {
			remove_filter( 'documentate_suspend_notifications', '__return_true' );
			wp_set_current_user( $previous_user );
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

		$previous_user = get_current_user_id();
		try {
			foreach ( self::demo_document_ids() as $post_id ) {
				self::delete_attachments( $post_id );
				wp_delete_post( $post_id, true );
			}
		} finally {
			wp_set_current_user( $previous_user );
		}

		return self::seed();
	}

	/**
	 * Delete the attachment children of a document.
	 *
	 * @param int $post_id Document ID.
	 * @return void
	 */
	private static function delete_attachments( $post_id ) {
		$children = get_children(
			array(
				'post_parent' => $post_id,
				'post_type' => 'attachment',
				'fields' => 'ids',
			)
		);

		foreach ( $children as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Move a document straight to a status, writing the "devuelto" mark and
	 * relying on nothing about the current user.
	 *
	 * Seeding must be able to put a document in any state regardless of who
	 * runs it (WP-CLI with no logged-in user, a test, an anonymous first
	 * request), so this bypasses Documentate_Transitions::allowed() the same
	 * way Documentate_Transitions::apply() does: by flagging the change as
	 * already in progress before calling wp_update_post().
	 *
	 * @param int        $post_id  Document ID.
	 * @param string     $status   Destination status.
	 * @param array|null $returned Devuelto payload (motivo, desde, a), or null to clear the mark.
	 * @return void
	 */
	public static function set_status( $post_id, $status, $returned = null ) {
		if ( ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return;
		}

		if ( null === $returned ) {
			Documentate_Document_Data::clear_returned( $post_id );
		} else {
			Documentate_Document_Data::mark_returned( $post_id, $returned['motivo'], $returned['desde'], $returned['a'] );
		}

		self::force_status( $post_id, $status );
	}

	/**
	 * Change a post's status while flagging it as an in-progress transition.
	 *
	 * Two independent gates would otherwise stand in the way of a status
	 * change nobody asked for through the real workflow: Rule 0 of
	 * Documentate_Workflow, which Documentate_Transitions::$in_progress (private;
	 * reflection sets it the same way apply() does) tells to let the change
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
	private static function force_status( $post_id, $status ) {
		$in_progress = new ReflectionProperty( Documentate_Transitions::class, 'in_progress' );
		$in_progress->setAccessible( true );
		$previous_in_progress = $in_progress->getValue();
		$in_progress->setValue( null, array( (int) $post_id, (string) $status, '' ) );

		$previous_user = get_current_user_id();
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
			wp_set_current_user( $previous_user );
			$in_progress->setValue( null, $previous_in_progress );
		}
	}

	/**
	 * Run one transition of Documentate_Transitions' rule table on a demo
	 * document, impersonating the actor and recording the same event text and
	 * "devuelto" mark apply() would have written.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $actor   Demo login of whoever performs the move ("admin", "editor1", "author1").
	 * @param string $key     Rule key from Documentate_Transitions::rules().
	 * @param string $from    Stored status the rule starts from (disambiguates "devolver_area").
	 * @param string $reason  Reason, required by return rules.
	 * @return void
	 */
	private static function move( $post_id, $actor, $key, $from, $reason = '' ) {
		$rule = Documentate_Transitions::rule( $key, $from );
		if ( null === $rule ) {
			return;
		}

		wp_set_current_user( self::user_id_for_login( $actor ) );

		$returned = null;
		$event_text = (string) $rule['event'];
		if ( $rule['reason'] ) {
			$returned = array(
				'motivo' => $reason,
				'desde' => 'pending' === $from ? 'administracion' : 'gestion',
				'a' => 'en_gestion' === $rule['target'] ? 'gestion' : 'area',
			);
			$event_text .= ': «' . $reason . '»';
		}

		Documentate_Demo_App_Clock::record_event( $post_id, $event_text, $reason );
		self::set_status( $post_id, (string) $rule['target'], $returned );
	}

	/**
	 * Create one demo document (with its fields, attachment, steps and
	 * comment) unless a marked document of that title already exists.
	 *
	 * @param array $doc Document definition (see documents()).
	 * @return int Document ID, or 0 when it could not be created.
	 */
	private static function create_if_missing( array $doc ) {
		$existing = self::find_by_title( $doc['title'] );
		if ( $existing > 0 ) {
			return $existing;
		}

		Documentate_Demo_App_Clock::start( $doc );

		$post_id = self::create_document( $doc );
		if ( $post_id <= 0 ) {
			return 0;
		}

		if ( isset( $doc['attachment'] ) ) {
			self::attach( $post_id, $doc['attachment'], $doc['author'] );
		}

		foreach ( $doc['steps'] as $step ) {
			self::move( $post_id, $step['actor'], $step['key'], $step['from'], isset( $step['reason'] ) ? $step['reason'] : '' );
		}

		if ( isset( $doc['returned_directly'] ) ) {
			self::return_without_transition( $post_id, $doc['returned_directly'] );
		}

		if ( isset( $doc['comment'] ) ) {
			wp_set_current_user( self::user_id_for_login( $doc['comment']['actor'] ) );
			$comment_id = Documentate_Activity::add_comment( $post_id, $doc['comment']['text'] );
			if ( ! is_wp_error( $comment_id ) ) {
				Documentate_Demo_App_Clock::mark( (int) $comment_id );
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
	 * @param array $data    actor, motivo.
	 * @return void
	 */
	private static function return_without_transition( $post_id, array $data ) {
		wp_set_current_user( self::user_id_for_login( $data['actor'] ) );

		Documentate_Demo_App_Clock::record_event(
			$post_id,
			'devolvió el documento al área: «' . $data['reason'] . '»',
			$data['reason']
		);

		self::set_status(
			$post_id,
			'draft',
			array(
				'motivo' => $data['reason'],
				'desde' => 'gestion',
				'a' => 'area',
			)
		);
	}

	/**
	 * Create the draft post of a demo document: type, internal name, área,
	 * fields and post_content.
	 *
	 * @param array $doc Document definition (see documents()).
	 * @return int Document ID, or 0 when the type is missing or creation failed.
	 */
	private static function create_document( array $doc ) {
		$term = get_term_by( 'slug', $doc['type'], 'documentate_doc_type' );
		if ( ! $term instanceof WP_Term ) {
			return 0;
		}

		$actor_id = self::user_id_for_login( $doc['author'] );
		wp_set_current_user( $actor_id );

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_status' => 'draft',
				'post_title' => $doc['title'],
				'post_author' => $actor_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) || 0 === $post_id ) {
			return 0;
		}

		wp_set_post_terms( $post_id, array( $term->term_id ), 'documentate_doc_type', false );
		Documentate_Document_Data::save_internal_name( $post_id, $doc['name'] );
		update_post_meta( $post_id, self::META_MARK, '1' );
		self::assign_area( $post_id, isset( $doc['area'] ) ? $doc['area'] : '' );

		$fields = self::fill_fields(
			$post_id,
			$term->term_id,
			$doc['title'],
			! empty( $doc['management'] ),
			isset( $doc['skip'] ) ? $doc['skip'] : array(),
			isset( $doc['force'] ) ? $doc['force'] : array()
		);

		$content = Documentate_Demo_Data::build_structured_demo_content( $fields );
		if ( '' !== $content ) {
			wp_update_post(
				array(
					'ID' => $post_id,
					'post_content' => $content,
				)
			);
		}

		Documentate_Demo_App_Clock::record_event( $post_id, 'creó el borrador' );

		return $post_id;
	}

	/**
	 * Assign a document to its área (category), by name.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $name    Category name; empty leaves the document uncategorised.
	 * @return void
	 */
	private static function assign_area( $post_id, $name ) {
		if ( '' === $name ) {
			return;
		}

		$term = get_term_by( 'name', $name, 'category' );
		if ( $term instanceof WP_Term ) {
			wp_set_post_terms( $post_id, array( $term->term_id ), 'category', false );
		}
	}

	/**
	 * Fill the área rows (and, when asked, the gestión rows) of a document's
	 * schema with plausible content, storing meta the same way the sections
	 * metabox would.
	 *
	 * @param int    $post_id            Document ID.
	 * @param int    $term_id            Document type term ID.
	 * @param string $title              Official title (generator context).
	 * @param bool   $include_management Whether to also fill the gestión rows.
	 * @param array  $skip               Slugs to skip entirely (left unset).
	 * @param array  $force              Slug => structured entry overrides/additions, applied last.
	 * @return array<string,array{type:string,value:string}> Structured fields, for post_content.
	 */
	private static function fill_fields( $post_id, $term_id, $title, $include_management, array $skip, array $force ) {
		$groups = Documentate_Field_Roles::group_by_role( Documentate_Documents::get_term_schema( $term_id ) );
		$context = array( 'document_title' => $title );

		$fields = self::fill_rows( $post_id, $groups[ Documentate_Field_Roles::ROLE_AREA ], $context, $skip );
		if ( $include_management ) {
			$fields += self::fill_rows( $post_id, $groups[ Documentate_Field_Roles::ROLE_MANAGEMENT ], $context, $skip );
		}

		foreach ( $force as $slug => $entry ) {
			update_post_meta( $post_id, 'documentate_field_' . $slug, $entry['value'] );
			$fields[ $slug ] = $entry;
		}

		return $fields;
	}

	/**
	 * Generate and store demo values for a group of schema rows.
	 *
	 * Reuses Documentate_Demo_Data's public generators, the same ones the
	 * plugin's other demo documents are built from.
	 *
	 * @param int   $post_id Document ID.
	 * @param array $rows    Schema rows (legacy shape, one rol group).
	 * @param array $context Generator context (document_title).
	 * @param array $skip    Slugs to skip entirely.
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function fill_rows( $post_id, array $rows, array $context, array $skip ) {
		$fields = array();

		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug || in_array( $slug, $skip, true ) ) {
				continue;
			}

			$entry = self::row_value( $slug, $row, $context );
			if ( null === $entry ) {
				continue;
			}

			update_post_meta( $post_id, 'documentate_field_' . $slug, $entry['value'] );
			$fields[ $slug ] = $entry;
		}

		return $fields;
	}

	/**
	 * Build the structured entry (type + sanitised value) of one schema row.
	 *
	 * @param string $slug    Field slug.
	 * @param array  $row     Schema row (legacy shape).
	 * @param array  $context Generator context.
	 * @return array{type:string,value:string}|null Null for an empty repeater.
	 */
	private static function row_value( $slug, array $row, array $context ) {
		$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';

		if ( 'array' === $type ) {
			$item_schema = isset( $row['item_schema'] ) && is_array( $row['item_schema'] ) ? $row['item_schema'] : array();
			$items = Documentate_Demo_Data::generate_demo_array_items( $slug, $item_schema, $context );
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

		$data_type = isset( $row['data_type'] ) ? sanitize_key( $row['data_type'] ) : 'text';
		$value = Documentate_Demo_Data::generate_demo_scalar_value( $slug, $type, $data_type, 1, $context );

		if ( 'rich' === $type ) {
			$value = wp_kses_post( $value );
		} elseif ( 'single' === $type ) {
			$value = sanitize_text_field( $value );
		} else {
			$value = sanitize_textarea_field( $value );
		}

		return array(
			'type' => $type,
			'value' => $value,
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
	private static function attach( $post_id, array $fixture, $actor ) {
		wp_set_current_user( self::user_id_for_login( $actor ) );

		$file = self::temp_file( $fixture );
		if ( null === $file ) {
			return;
		}

		$result = Documentate_App_Attachments::store( $post_id, $file );
		if ( ! is_wp_error( $result ) ) {
			// store() records its own "adjuntó el fichero" event internally
			// and does not hand back its comment ID, so the freshest event of
			// this document (by ID, not by date — its date is what we are
			// about to change) is backdated onto the demo clock.
			Documentate_Demo_App_Clock::mark( Documentate_Demo_App_Clock::last_event_id( $post_id ) );
		}
	}

	/**
	 * Build a $_FILES-shaped array backed by a real temporary file.
	 *
	 * @param array $fixture tipo ("pdf" for the embedded sample, or the
	 *                       fixtures/ filename to copy for an ODT/DOCX), nombre.
	 * @return array<string,mixed>|null Null when the source file is unreadable.
	 */
	private static function temp_file( array $fixture ) {
		if ( 'pdf' === $fixture['type'] ) {
			$content = self::PDF_DEMO;
			$mime = 'application/pdf';
		} else {
			$source = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture['type'];
			if ( ! file_exists( $source ) ) {
				return null;
			}
			$content = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- Reading a bundled fixture, not user input.
			$mime = 'application/vnd.oasis.opendocument.text';
		}

		if ( false === $content ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( $fixture['name'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a temp copy of a bundled fixture, not user input.
		if ( ! $tmp || false === file_put_contents( $tmp, $content ) ) {
			return null;
		}

		return array(
			'name' => $fixture['name'],
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
	private static function user_id_for_login( $login ) {
		if ( 'admin' === $login ) {
			return self::admin_id();
		}

		$user = get_user_by( 'login', $login );

		return $user instanceof WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Resolve the "admin" demo actor: the literal account, else any administrator.
	 *
	 * @return int User ID, or the current user as a last resort.
	 */
	private static function admin_id() {
		$user = get_user_by( 'login', 'admin' );
		if ( $user instanceof WP_User ) {
			return (int) $user->ID;
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
				self::META_MARK
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
	 * @param string $title Post title.
	 * @return int Document ID, or 0.
	 */
	private static function find_by_title( $title ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Idempotency check during demo seeding; mirrors demo_document_ids().
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_type = %s AND p.post_title = %s AND pm.meta_key = %s AND p.post_status <> 'trash' LIMIT 1",
				'documentate_document',
				$title,
				self::META_MARK
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
	private static function pg_demo_providers() {
		$services = array(
			array(
				'proveedor' => 'Talleres Digitales Canarias S.L.',
				'cif' => 'B38112233',
				'email' => 'administracion@talleresdigitales.es',
				'telefono' => '922334455',
				'bruto' => '1800',
				// documentate-calculations.js reads IGIC and IRPF as euro
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

		$supplies = array(
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

		$experts = array(
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
				'value' => wp_json_encode( $services, JSON_UNESCAPED_UNICODE ),
			),
			'suministros' => array(
				'type' => 'array',
				'value' => wp_json_encode( $supplies, JSON_UNESCAPED_UNICODE ),
			),
			'expertos' => array(
				'type' => 'array',
				'value' => wp_json_encode( $experts, JSON_UNESCAPED_UNICODE ),
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
	 * Schema-row slugs pg_demo_providers() supplies by hand, so the generic
	 * generator does not overwrite them with a malformed nested value.
	 *
	 * @return string[]
	 */
	private static function pg_provider_slugs() {
		return array( 'servicios', 'suministros', 'expertos', 'gasto_letra', 'gasto_numero', 'partida' );
	}

	/**
	 * The twelve demo documents of the application walkthrough.
	 *
	 * Each entry: tipo (doc type slug), nombre (internal name), titulo
	 * (official title / post_title), autor (demo login), area (category
	 * name, optional), gestion (whether to fill gestión rows), omitir /
	 * forzar (field overrides), adjunto (fixture to attach, optional),
	 * pasos (ordered Documentate_Transitions moves), devuelto_directo
	 * (a devuelto mark with no matching rule, optional), comentario
	 * (one activity comment, optional).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function documents() {
		return array(
			self::doc_classroom_materials(),
			self::doc_digital_competence_days(),
			self::doc_panel_certificate(),
			self::doc_final_list(),
			self::doc_library_funding(),
			self::doc_teacher_training(),
			self::doc_pilot_programme_rules(),
			self::doc_admission_calendar(),
			self::doc_training_committee(),
			self::doc_training_plan_rules(),
			self::doc_licence_renewal(),
			self::doc_term_start_instructions(),
		);
	}

	/**
	 * PG "Material aulas digitales" — draft, with a PDF attachment.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_classroom_materials() {
		return array(
			'type' => 'propuesta-gasto',
			'name' => 'Material aulas digitales',
			'title' => 'Propuesta de gasto para material didáctico de las aulas digitales del Departamento de Proyectos',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => false,
			'attachment' => array(
				'type' => 'pdf',
				'name' => 'presupuesto-material-aulas.pdf',
			),
			'steps' => array(),
		);
	}

	/**
	 * CONV "Jornadas competencia digital" — draft.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_digital_competence_days() {
		return array(
			'type' => 'convocatoria-reunion',
			'name' => 'Jornadas competencia digital',
			'title' => 'Convocatoria de las Jornadas de Competencia Digital Docente',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => false,
			'steps' => array(),
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
	private static function doc_panel_certificate() {
		return array(
			'type' => 'hace-constar',
			'name' => 'Certificación tribunal materiales',
			'title' => 'Hace constar la participación en el tribunal de selección de materiales didácticos',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => false,
			'steps' => array(),
			'returned_directly' => array(
				'actor' => 'editor1',
				'reason' => 'Falta el anexo firmado por la dirección',
			),
		);
	}

	/**
	 * RES "Listado definitivo piloto innovación" — en_gestion, ODT attachment,
	 * área fields filled and gestión fields still empty, with one comment.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_final_list() {
		return array(
			'type' => 'resolucion-administrativa',
			'name' => 'Listado definitivo piloto innovación',
			'title' => 'Resolución por la que se aprueba el listado definitivo de centros admitidos en el programa piloto de innovación educativa',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => false,
			'attachment' => array(
				'type' => 'demo-wp-documentate.odt',
				'name' => 'listado-definitivo-piloto.odt',
			),
			'steps' => array(
				array(
					'actor' => 'author1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
			),
			'comment' => array(
				'actor' => 'author1',
				'text' => 'El anexo con el listado va en la última página del ODT.',
			),
		);
	}

	/**
	 * PG "Dotación biblioteca escolar" — en_gestion, área fields filled,
	 * gestión fields EMPTY (gestión has not touched it yet).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_library_funding() {
		return array(
			'type' => 'propuesta-gasto',
			'name' => 'Dotación biblioteca escolar',
			'title' => 'Propuesta de gasto para la dotación de fondos bibliográficos de los centros del área',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => false,
			'steps' => array(
				array(
					'actor' => 'author1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
			),
		);
	}

	/**
	 * PG "Formación profesorado metodologías" — pending, gestión fields filled.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_teacher_training() {
		return array(
			'type' => 'propuesta-gasto',
			'name' => 'Formación profesorado metodologías',
			'title' => 'Propuesta de gasto para la formación del profesorado en metodologías activas',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => true,
			'skip' => self::pg_provider_slugs(),
			'force' => self::pg_demo_providers(),
			'steps' => array(
				array(
					'actor' => 'author1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
			),
		);
	}

	/**
	 * RES "Bases programa piloto innovación" — publish.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_pilot_programme_rules() {
		return array(
			'type' => 'resolucion-administrativa',
			'name' => 'Bases programa piloto innovación',
			'title' => 'Resolución por la que se aprueban las bases del programa piloto de innovación educativa',
			'author' => 'author1',
			'area' => 'Departamento de Proyectos',
			'management' => true,
			'steps' => array(
				array(
					'actor' => 'author1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'key' => 'aprobar',
					'from' => 'pending',
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
	private static function doc_admission_calendar() {
		return array(
			'type' => 'resolucion-administrativa',
			'name' => 'Calendario de admisión 2027',
			'title' => 'Resolución por la que se aprueba el calendario del proceso de admisión para el curso 2026-2027',
			'author' => 'editor1',
			'area' => 'Subdirección de Administración',
			'management' => true,
			'skip' => array( 'expediente' ),
			'steps' => array(
				array(
					'actor' => 'editor1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'key' => 'devolver_gestion',
					'from' => 'pending',
					'reason' => 'Falta el número de expediente',
				),
			),
		);
	}

	/**
	 * CONV "Comisión formación septiembre" — pending (directo).
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_training_committee() {
		return array(
			'type' => 'convocatoria-reunion',
			'name' => 'Comisión formación septiembre',
			'title' => 'Convocatoria de la Comisión de Formación del mes de septiembre',
			'author' => 'editor1',
			'area' => 'Subdirección de Administración',
			'management' => false,
			'steps' => array(
				array(
					'actor' => 'editor1',
					'key' => 'enviar_revision',
					'from' => 'draft',
				),
			),
		);
	}

	/**
	 * RES "Bases plan de formación 2026-27" — publish.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_training_plan_rules() {
		return array(
			'type' => 'resolucion-administrativa',
			'name' => 'Bases plan de formación 2026-27',
			'title' => 'Resolución por la que se aprueban las bases del plan de formación del profesorado 2026-2027',
			'author' => 'editor1',
			'area' => 'Subdirección de Administración',
			'management' => true,
			'steps' => array(
				array(
					'actor' => 'editor1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'key' => 'aprobar',
					'from' => 'pending',
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
	private static function doc_licence_renewal() {
		return array(
			'type' => 'propuesta-gasto',
			'name' => 'Renovación licencias aulas virtuales',
			'title' => 'Propuesta de gasto para la renovación de licencias de las aulas virtuales',
			'author' => 'editor1',
			'area' => 'Subdirección de Administración',
			'management' => true,
			'skip' => self::pg_provider_slugs(),
			'force' => self::pg_demo_providers(),
			'steps' => array(
				array(
					'actor' => 'editor1',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'editor1',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'key' => 'devolver_area',
					'from' => 'pending',
					'reason' => 'Revisar la partida presupuestaria',
				),
			),
		);
	}

	/**
	 * RES "Instrucciones inicio de curso 2025-26" — archived.
	 *
	 * @return array<string,mixed>
	 */
	private static function doc_term_start_instructions() {
		return array(
			'type' => 'resolucion-administrativa',
			'name' => 'Instrucciones inicio de curso 2025-26',
			'title' => 'Resolución por la que se dictan instrucciones para el inicio del curso 2025-2026',
			'author' => 'admin',
			'management' => true,
			'steps' => array(
				array(
					'actor' => 'admin',
					'key' => 'enviar_gestion',
					'from' => 'draft',
				),
				array(
					'actor' => 'admin',
					'key' => 'pasar_admin',
					'from' => 'en_gestion',
				),
				array(
					'actor' => 'admin',
					'key' => 'aprobar',
					'from' => 'pending',
				),
				array(
					'actor' => 'admin',
					'key' => 'archivar',
					'from' => 'publish',
				),
			),
		);
	}
}
