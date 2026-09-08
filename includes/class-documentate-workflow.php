<?php
/**
 * Workflow Restriction Handler for Documentate Documents.
 *
 * Manages save workflow, role-based restrictions, and UI states for the
 * documentate_document Custom Post Type. Provides a unified "Document Management"
 * meta box that replaces the default WordPress submitdiv.
 *
 * @package Documentate
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Class Documentate_Workflow
 *
 * Handles:
 * - Force draft status when no doc_type assigned
 * - Role-based publishing restrictions (Editors vs Admins)
 * - Read-only mode when post is published, archived, or pending (non-admin)
 * - Owns the unified Document Management meta box (Documentate_Workflow_Metabox)
 */
class Documentate_Workflow {
	/**
	 * The post type this workflow applies to.
	 *
	 * @var string
	 */
	private $post_type = 'documentate_document';

	/**
	 * The taxonomy for document classification.
	 *
	 * @var string
	 */
	private $taxonomy = 'documentate_doc_type';

	/**
	 * Store original status for admin notices.
	 *
	 * @var string|null
	 */
	private $original_status = null;

	/**
	 * Get workflow notice configuration.
	 *
	 * @return array<string, array{message: string, type: string}>
	 */
	private static function get_notice_config() {
		return array(
			'no_classification' => array(
				'message' => 'Documento guardado como borrador. Debes seleccionar un tipo de documento antes de enviarlo.',
				'type' => 'warning',
			),
			'editor_no_publish' => array(
				'message' => 'El documento pasa a revisión. Solo administración puede publicar documentos.',
				'type' => 'info',
			),
			'published_locked' => array(
				'message' => 'Los documentos aprobados solo los puede modificar administración.',
				'type' => 'error',
			),
			'pending_locked' => array(
				'message' => 'Los documentos en revisión solo los puede modificar administración.',
				'type' => 'error',
			),
			'gestion_locked' => array(
				'message' => 'Los documentos en gestión documental solo los pueden modificar gestión y administración.',
				'type' => 'error',
			),
			'transicion_no_permitida' => array(
				'message' => 'No se pudo cambiar el estado: la transición no está permitida para tu rol o falta el motivo de la devolución.',
				'type' => 'error',
			),
			'archive_requires_publish' => array(
				'message' => 'Solo se pueden archivar los documentos aprobados.',
				'type' => 'error',
			),
			'archive_admin_only' => array(
				'message' => 'Solo administración puede archivar documentos.',
				'type' => 'error',
			),
			'archived_locked' => array(
				'message' => 'Los documentos archivados solo los puede modificar administración.',
				'type' => 'error',
			),
		);
	}

	/**
	 * Store status change reason for admin notices.
	 *
	 * @var string|null
	 */
	private $status_change_reason = null;

	/**
	 * The management metabox and its assets.
	 *
	 * @var Documentate_Workflow_Metabox
	 */
	private $metabox;

	/**
	 * Initialize the workflow handler.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register all hooks for workflow management.
	 */
	private function init_hooks() {
		// Register custom post statuses.
		add_action( 'init', array( $this, 'register_custom_statuses' ), 5 );

		// Status control before saving.
		add_filter( 'wp_insert_post_data', array( $this, 'control_post_status' ), 10, 2 );
		// After content composition: freeze locked documents for non-admins.
		add_filter( 'wp_insert_post_data', array( $this, 'freeze_locked_document_data' ), 99, 2 );

		// Admin notices for status changes.
		add_action( 'admin_notices', array( $this, 'display_workflow_notices' ) );

		// Store status change info in transient for notices.
		add_action( 'save_post_' . $this->post_type, array( $this, 'store_status_change_notice' ), 99, 3 );

		// The management metabox (replaces submitdiv) hooks itself.
		$this->metabox = new Documentate_Workflow_Metabox();

		// Prevent editors from setting publish status via quick edit.
		add_filter( 'wp_insert_post_empty_content', array( $this, 'check_publish_capability' ), 10, 2 );

		// Prevent non-admins from restoring revisions on pending/published/archived documents.
		add_action( 'wp_restore_post_revision', array( $this, 'restrict_revision_restore' ), 1, 2 );
	}

	/**
	 * Register the custom post statuses (en_gestion, archived).
	 */
	public function register_custom_statuses() {
		Documentate_Statuses::register();
	}

	/**
	 * Register the 'archived' custom post status.
	 *
	 * Kept as an alias of register_custom_statuses() for callers that predate
	 * the en_gestion status.
	 */
	public function register_archived_status() {
		$this->register_custom_statuses();
	}

	/**
	 * Control post status based on business rules.
	 *
	 * @param array $data    An array of slashed, sanitized post data.
	 * @param array $postarr An array of sanitized post data.
	 * @return array Modified post data.
	 */
	public function control_post_status( $data, $postarr ) {
		if ( ! $this->should_control_status( $data ) ) {
			return $data;
		}

		$context = array(
			'post_id' => isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0,
			'postarr' => $postarr,
			'is_admin' => current_user_can( 'manage_options' ),
			'requested_status' => $data['post_status'],
		);

		// Store original status for notices.
		$this->original_status = $context['requested_status'];

		// Rule 0: only the transitions of the table are allowed for this role.
		$decided = $this->apply_transition_rule( $data, $context );
		if ( null !== $decided ) {
			return $decided;
		}

		// Rule 1: Force draft if no doc_type assigned (for any non-draft status).
		$decided = $this->apply_classification_rule( $data, $context );
		if ( null !== $decided ) {
			return $decided;
		}

		// Rule 2: Role-based restrictions for non-admins.
		$data = $this->apply_role_rule( $data, $context );

		// Rule 3: If post is currently published, only admin can change it.
		$data = $this->apply_published_lock_rule( $data, $context );

		// Rule 4: Archive transitions (admin only, from publish only).
		$decided = $this->apply_archive_transition_rule( $data, $context );
		if ( null !== $decided ) {
			return $decided;
		}

		// Rule 5: Archived documents are locked (similar to published).
		return $this->apply_archived_lock_rule( $data, $context );
	}

	/**
	 * Whether the workflow status rules apply to this save at all.
	 *
	 * @param array $data An array of slashed, sanitized post data.
	 * @return bool
	 */
	private function should_control_status( $data ) {
		// Only apply to our post type.
		if ( $data['post_type'] !== $this->post_type ) {
			return false;
		}

		// Skip auto-drafts and revisions. Autosaves are not skipped: a heartbeat
		// autosave carries a client-chosen post_status and must obey Rule 0.
		return 'auto-draft' !== $data['post_status'] && 'revision' !== $data['post_type'];
	}

	/**
	 * Whether the current save is an autosave (heartbeat or REST).
	 *
	 * @return bool
	 */
	private function doing_autosave() {
		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/**
	 * Rule 0: a status change must be a transition the user's role may run.
	 *
	 * The application applies its transitions through Documentate_Transitions,
	 * which flags them as in progress so they always pass; wp-admin saves post
	 * the target status (and the reason of a return) and are validated here.
	 * A document is born as a draft (no stored status, or auto-draft), so a
	 * creation straight into the pipeline is judged as a change from draft.
	 * An autosave never moves a document along.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array|null Final post data, or null to continue with the next rule.
	 */
	private function apply_transition_rule( $data, array $context ) {
		$stored = $this->get_stored_status( $context['post_id'] );
		// A document is born as a draft; allowed() still sees the raw status to tell a creation apart.
		$base = in_array( $stored, array( '', 'auto-draft' ), true ) ? 'draft' : $stored;
		if ( $base === $context['requested_status'] ) {
			return null;
		}

		$allowed = ! $this->doing_autosave() && Documentate_Transitions::allowed(
			$context['post_id'],
			$stored,
			$context['requested_status'],
			get_current_user_id(),
			Documentate_Transitions::posted_reason(),
			Documentate_Document_Data::has_management_on_save( $context['post_id'], $context['postarr'] )
		);
		if ( $allowed ) {
			return null;
		}

		$data['post_status'] = $base;
		$this->status_change_reason = 'transicion_no_permitida';

		return $data;
	}

	/**
	 * Rule 1: a document without a doc type cannot leave draft.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array|null Final post data, or null to continue with the next rule.
	 */
	private function apply_classification_rule( $data, array $context ) {
		if ( ! $this->should_force_draft_no_classification( $context['post_id'], $context['postarr'] ) ) {
			return null;
		}

		// Any attempt to publish/private/pending without doc_type should fail.
		if ( $this->is_publish_status( $context['requested_status'] ) || in_array( $context['requested_status'], array( 'pending', 'en_gestion' ), true ) ) {
			$data['post_status'] = 'draft';
			$this->status_change_reason = 'no_classification';
		}

		return $data;
	}

	/**
	 * Rule 2: only administrators may publish.
	 *
	 * A non-admin asking for publish/private/future from a draft lands on the
	 * next step of the workflow (en_gestion when the type goes through
	 * gestión, pending otherwise); from en_gestion or pending the document
	 * keeps its stored status.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array
	 */
	private function apply_role_rule( $data, array $context ) {
		if ( $context['is_admin'] || ! $this->is_publish_status( $context['requested_status'] ) ) {
			return $data;
		}

		$stored = $this->get_stored_status( $context['post_id'] );
		if ( in_array( $stored, array( 'en_gestion', 'pending' ), true ) ) {
			$data['post_status'] = $stored;
		} elseif ( Documentate_Document_Data::has_management_on_save( $context['post_id'], $context['postarr'] ) ) {
			$data['post_status'] = 'en_gestion';
		} else {
			$data['post_status'] = 'pending';
		}
		$this->status_change_reason = 'editor_no_publish';

		return $data;
	}

	/**
	 * Rule 3: published documents are locked for non-administrators.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array
	 */
	private function apply_published_lock_rule( $data, array $context ) {
		if ( $context['is_admin'] || 'publish' !== $this->get_stored_status( $context['post_id'] ) ) {
			return $data;
		}

		// Non-admins cannot modify published posts.
		$data['post_status'] = 'publish';
		$this->status_change_reason = 'published_locked';

		return $data;
	}

	/**
	 * Rule 4: archiving is admin-only and allowed from publish only.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array|null Final post data, or null to continue with the next rule.
	 */
	private function apply_archive_transition_rule( $data, array $context ) {
		if ( 'archived' !== $context['requested_status'] ) {
			return null;
		}

		if ( ! $context['is_admin'] ) {
			// Non-admins cannot archive.
			$data['post_status'] = $context['post_id'] > 0
				? get_post_field( 'post_status', $context['post_id'] )
				: 'draft';
			$this->status_change_reason = 'archive_admin_only';
			return $data;
		}

		$stored_status = $this->get_stored_status( $context['post_id'] );
		if ( '' !== $stored_status && 'publish' !== $stored_status ) {
			// Can only archive from publish.
			$data['post_status'] = $stored_status;
			$this->status_change_reason = 'archive_requires_publish';
			return $data;
		}

		return null;
	}

	/**
	 * Rule 5: archived documents are locked, and only unarchive to publish.
	 *
	 * @param array $data    Post data being saved.
	 * @param array $context Status evaluation context.
	 * @return array
	 */
	private function apply_archived_lock_rule( $data, array $context ) {
		if ( 'archived' !== $this->get_stored_status( $context['post_id'] ) ) {
			return $data;
		}

		if ( ! $context['is_admin'] ) {
			// Non-admins cannot modify archived posts.
			$data['post_status'] = 'archived';
			$this->status_change_reason = 'archived_locked';
			return $data;
		}

		// Admins can only unarchive to publish.
		if ( 'archived' !== $context['requested_status'] && 'publish' !== $context['requested_status'] ) {
			$data['post_status'] = 'publish';
		}

		return $data;
	}

	/**
	 * Read the status currently stored for a post.
	 *
	 * @param int $post_id Post ID, or 0 for a post that does not exist yet.
	 * @return string Stored status, or an empty string when unavailable.
	 */
	private function get_stored_status( $post_id ) {
		if ( $post_id <= 0 ) {
			return '';
		}

		$current_post = get_post( $post_id );

		return $current_post ? $current_post->post_status : '';
	}

	/**
	 * Whether a status is publish-like, and so requires a doc type or admin rights.
	 *
	 * @param string $status Requested post status.
	 * @return bool
	 */
	private function is_publish_status( $status ) {
		return in_array( $status, array( 'publish', 'private', 'future' ), true );
	}

	/**
	 * Check if post should be forced to draft due to missing classification.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $postarr Post data array.
	 * @return bool True if should force draft.
	 */
	private function should_force_draft_no_classification( $post_id, $postarr ) {
		// Check if taxonomy terms are being set in this save.
		if ( isset( $postarr['tax_input'][ $this->taxonomy ] ) ) {
			$terms = $postarr['tax_input'][ $this->taxonomy ];
			if ( ! empty( $terms ) && ! ( is_array( $terms ) && empty( array_filter( $terms ) ) ) ) {
				return false;
			}
		}

		// Check existing terms if not a new post.
		if ( $post_id > 0 ) {
			$existing_terms = wp_get_object_terms( $post_id, $this->taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
				return false;
			}
		}

		// Also check the locked doc type meta.
		if ( $post_id > 0 ) {
			$locked_term = get_post_meta( $post_id, 'documentate_locked_doc_type', true );
			if ( ! empty( $locked_term ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Store status change notice in transient.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function store_status_change_notice( $post_id, $post, $update ) {
		if ( $this->status_change_reason ) {
			set_transient(
				'documentate_workflow_notice_' . get_current_user_id(),
				array(
					'reason' => $this->status_change_reason,
					'original_status' => $this->original_status,
					'post_id' => $post_id,
				),
				30,
			);
		}
	}

	/**
	 * Display admin notices about workflow status changes.
	 */
	public function display_workflow_notices() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		$notice = get_transient( 'documentate_workflow_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}

		delete_transient( 'documentate_workflow_notice_' . get_current_user_id() );

		$config = self::get_notice_config();
		$reason = $notice['reason'];
		$message = '';
		$type = 'warning';

		if ( isset( $config[ $reason ] ) ) {
			$message = $config[ $reason ]['message'];
			$type = $config[ $reason ]['type'];
		}

		if ( $message ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	/**
	 * Enqueue the management metabox assets (delegated to the metabox class).
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_workflow_assets( $hook_suffix ) {
		$this->metabox->enqueue_workflow_assets( $hook_suffix );
	}

	/**
	 * Register the management metabox (delegated to the metabox class).
	 */
	public function add_workflow_metabox() {
		$this->metabox->add_workflow_metabox();
	}

	/**
	 * Enforce the sidebar metabox order (delegated to the metabox class).
	 *
	 * @param array|false $order Saved metabox order or false.
	 * @return array Metabox order with side column enforced.
	 */
	public function enforce_sidebar_metabox_order( $order ) {
		return $this->metabox->enforce_sidebar_metabox_order( $order );
	}

	/**
	 * Render the management metabox (delegated to the metabox class).
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_document_management_metabox( $post ) {
		$this->metabox->render_document_management_metabox( $post );
	}

	/**
	 * Statuses that lock content edits for non-administrators.
	 *
	 * Kept for back-compat; the role-aware check is user_can_modify_status().
	 *
	 * @param string $status Post status.
	 * @return bool
	 */
	public static function status_locks_content_for_non_admins( $status ) {
		return in_array( (string) $status, array( 'publish', 'archived', 'pending' ), true );
	}

	/**
	 * Whether a user may modify a document that sits in a status.
	 *
	 * Administración edits everything; gestión documental edits drafts and
	 * documents in gestión; everyone else edits drafts only.
	 *
	 * @param string $status  Post status.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	public static function user_can_modify_status( string $status, int $user_id ): bool {
		if ( Documentate_Roles::is_administration( $user_id ) ) {
			return true;
		}

		$editable = Documentate_Roles::is_management( $user_id )
			? array( 'draft', 'auto-draft', 'en_gestion' )
			: array( 'draft', 'auto-draft' );

		return in_array( $status, $editable, true );
	}

	/**
	 * Whether the current user may modify a document's content and meta.
	 *
	 * @param int $post_id Document post ID.
	 * @return bool
	 */
	public static function current_user_can_modify_document( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'documentate_document' !== $post->post_type ) {
			return true;
		}

		return self::user_can_modify_status( (string) $post->post_status, get_current_user_id() );
	}

	/**
	 * Restore core post fields from the database when a user hits a locked document.
	 *
	 * UI locks alone are not enough: a crafted save_post request can still change
	 * title/content while status is forced to stay locked.
	 *
	 * Runs at priority 99 so it wins over content composition filters.
	 *
	 * @param array $data    Sanitized post data to be inserted.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function freeze_locked_document_data( $data, $postarr ) {
		$current_post = $this->find_locked_source_post( $data, $postarr );
		if ( ! $current_post ) {
			return $data;
		}

		// wp_insert_post_data expects slashed field values.
		$data['post_content'] = wp_slash( $current_post->post_content );
		$data['post_title']   = wp_slash( $current_post->post_title );
		$data['post_excerpt'] = wp_slash( $current_post->post_excerpt );
		$data['post_status']  = $current_post->post_status;
		$data['post_name']    = wp_slash( $current_post->post_name );
		$data['post_author']  = (string) (int) $current_post->post_author;

		$this->status_change_reason = self::lock_reason_for_status( $current_post->post_status );

		return $data;
	}

	/**
	 * Locate the stored post whose fields must be preserved on this save.
	 *
	 * @param array $data    Sanitized post data to be inserted.
	 * @param array $postarr Raw post data.
	 * @return WP_Post|null The stored post, or null when nothing is locked.
	 */
	private function find_locked_source_post( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || $data['post_type'] !== $this->post_type ) {
			return null;
		}

		if ( $this->doing_autosave() ) {
			return null;
		}

		$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		$current_post = get_post( $post_id );
		if ( ! $current_post || self::user_can_modify_status( (string) $current_post->post_status, get_current_user_id() ) ) {
			return null;
		}

		return $current_post;
	}

	/**
	 * Map the locked status to the notice reason it raises.
	 *
	 * @param string $status Stored post status.
	 * @return string
	 */
	private static function lock_reason_for_status( $status ) {
		$reasons = array(
			'publish' => 'published_locked',
			'archived' => 'archived_locked',
			'en_gestion' => 'gestion_locked',
		);

		return isset( $reasons[ $status ] ) ? $reasons[ $status ] : 'pending_locked';
	}

	/**
	 * Prevent users from restoring revisions on documents locked for them.
	 *
	 * They can still view revision history.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 */
	public function restrict_revision_restore( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || $this->post_type !== $parent->post_type ) {
			return;
		}

		if ( self::user_can_modify_status( (string) $parent->post_status, get_current_user_id() ) ) {
			return;
		}

		wp_die(
			esc_html( 'No tienes permiso para restaurar revisiones de este documento.' ),
			esc_html( 'Restauración bloqueada' ),
			array( 'response' => 403 ),
		);
	}

	/**
	 * Additional check for publish capability.
	 *
	 * @param bool  $maybe_empty Whether the post should be considered empty.
	 * @param array $postarr     Array of post data.
	 * @return bool
	 */
	public function check_publish_capability( $maybe_empty, $postarr ) {
		// This hook runs early, we just pass through but log any issues.
		return $maybe_empty;
	}
}
