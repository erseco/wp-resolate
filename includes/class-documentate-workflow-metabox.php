<?php
/**
 * The "Gestión del documento" metabox of the wp-admin editor.
 *
 * Replaces the core submitdiv for documents: type selector, stepper, status
 * messages, the action buttons of the current status and role, revision and
 * trash links, plus the workflow script and its texts. Status decisions stay
 * in Documentate_Workflow; this class only draws them.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Workflow_Metabox
 *
 * Renders the unified document management meta box and its assets.
 */
class Documentate_Workflow_Metabox {
	/**
	 * The post type this metabox applies to.
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
	 * Register hooks.
	 */
	public function __construct() {
		// Enqueue scripts and styles for workflow UI.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_workflow_assets' ) );

		// Add unified document management meta box (replaces submitdiv).
		add_action( 'add_meta_boxes', array( $this, 'add_workflow_metabox' ) );

		// Enforce sidebar metabox order: management first, then actions.
		add_filter( 'get_user_option_meta-box-order_' . $this->post_type, array( $this, 'enforce_sidebar_metabox_order' ) );
	}

	/**
	 * Add unified document management meta box, replacing submitdiv.
	 */
	public function add_workflow_metabox() {
		remove_meta_box( 'submitdiv', $this->post_type, 'side' );
		remove_meta_box( 'documentate_doc_type', $this->post_type, 'side' );

		add_meta_box(
			'documentate_document_management',
			'Gestión del documento',
			array( $this, 'render_document_management_metabox' ),
			$this->post_type,
			'side',
			'high',
		);
	}

	/**
	 * Enforce sidebar metabox order so Document Actions always follows Document Management.
	 *
	 * @param array|false $order Saved metabox order or false.
	 * @return array Metabox order with side column enforced.
	 */
	public function enforce_sidebar_metabox_order( $order ) {
		if ( ! is_array( $order ) ) {
			$order = array();
		}
		// Place management and actions first; everything else follows.
		$order['side'] = 'documentate_document_management,documentate_actions';
		return $order;
	}

	/**
	 * Enqueue workflow-related scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_workflow_assets( $hook_suffix ) {
		// Only on post edit screens for our CPT.
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		// Enqueue workflow JavaScript.
		wp_enqueue_script(
			'documentate-workflow',
			plugins_url( 'admin/js/documentate-workflow.js', __DIR__ ),
			array( 'jquery' ),
			DOCUMENTATE_VERSION,
			true,
		);

		// Enqueue workflow CSS.
		wp_enqueue_style(
			'documentate-workflow',
			plugins_url( 'admin/css/documentate-workflow.css', __DIR__ ),
			array(),
			DOCUMENTATE_VERSION,
		);

		// Get post data for JavaScript.
		global $post;
		$post_id = $post ? $post->ID : 0;
		$post_status = $post ? $post->post_status : 'auto-draft';
		$is_admin = current_user_can( 'manage_options' );
		$has_doc_type = $this->post_has_doc_type( $post_id );

		wp_localize_script(
			'documentate-workflow',
			'documentateWorkflow',
			array(
				'postId' => $post_id,
				'postStatus' => $post_status,
				'isAdmin' => $is_admin,
				'isManagement' => Documentate_Roles::is_management(),
				'hasDocType' => $has_doc_type,
				'hasManagement' => $post_id > 0 && Documentate_Document_Data::has_management( $post_id ),
				'isPublished' => 'publish' === $post_status,
				'isArchived' => 'archived' === $post_status,
				'isPending' => 'pending' === $post_status,
				'isEnGestion' => 'en_gestion' === $post_status,
				'isLocked' => $this->is_status_locked( $post_status, $is_admin ),
				'strings' => self::get_js_strings(),
			)
		);
	}

	/**
	 * Texts the workflow script shows (confirmations come from the rule table).
	 *
	 * @return array<string,string>
	 */
	private static function get_js_strings() {
		return array(
			'lockedTitle' => 'Documento bloqueado',
			'lockedMessage' => 'Este documento está aprobado y es de solo lectura. Solo administración puede desbloquearlo devolviéndolo a revisión.',
			'archivedMessage' => 'Este documento está archivado y es de solo lectura. Solo administración puede desarchivarlo.',
			'pendingMessage' => 'Este documento está en revisión y es de solo lectura. Administración lo revisará.',
			'managementMessage' => 'Este documento está en gestión documental y es de solo lectura. Si falta algo, gestión te lo devolverá.',
			'adminUnlock' => 'Devuélvelo a revisión o al área para habilitar la edición.',
			'adminUnarchive' => 'Desarchívalo para habilitar la edición.',
			'needsDocType' => 'Selecciona un tipo de documento antes de enviarlo.',
			'editorRestriction' => 'Solo administración puede aprobar y publicar.',
			'confirmSendReview' => Documentate_Transitions::confirmation( 'enviar_revision' ),
			'confirmSendManagement' => Documentate_Transitions::confirmation( 'enviar_gestion' ),
			'confirmPassAdmin' => Documentate_Transitions::confirmation( 'pasar_admin' ),
			'reasonRequired' => 'Escribe el motivo de la devolución antes de devolver el documento.',
		);
	}

	/**
	 * Check if post has a document type assigned.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if has doc type.
	 */
	private function post_has_doc_type( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}

		// Check locked doc type.
		$locked_term = get_post_meta( $post_id, 'documentate_locked_doc_type', true );
		if ( ! empty( $locked_term ) ) {
			return true;
		}

		// Check taxonomy terms.
		$terms = wp_get_object_terms( $post_id, $this->taxonomy, array( 'fields' => 'ids' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/**
	 * Check if the given status should lock the document for this user.
	 *
	 * @param string $status   Post status.
	 * @param bool   $is_admin Whether current user is admin.
	 * @return bool True if document should be locked.
	 */
	private function is_status_locked( $status, $is_admin ) {
		if ( $is_admin ) {
			return false;
		}

		return ! Documentate_Workflow::user_can_modify_status( (string) $status, get_current_user_id() );
	}

	/**
	 * Render unified document management meta box.
	 *
	 * Combines visual stepper, status messages, context-sensitive action buttons,
	 * and all hidden inputs that WordPress needs (lost when submitdiv is removed).
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_document_management_metabox( $post ) {
		$status = $post->post_status;
		$is_admin = current_user_can( 'manage_options' );
		$has_doc_type = $this->post_has_doc_type( $post->ID );
		$has_management = Documentate_Document_Data::has_management( $post );
		$can_modify = Documentate_Workflow::user_can_modify_status( (string) $status, get_current_user_id() );

		// Hidden inputs WordPress needs (lost when submitdiv is removed).
		$this->render_hidden_inputs( $post );

		// Document type selector (merged from separate metabox).
		$this->render_doc_type_section( $post );

		// Visual stepper.
		$this->render_stepper( $status, $has_management );

		// Status messages.
		$this->render_status_messages( $status, $is_admin, $has_doc_type, $has_management, $can_modify );

		// Action buttons.
		$this->render_action_buttons( $post, $status, $is_admin, $has_management, $can_modify );

		// Revision link.
		$this->render_revision_link( $post );

		// Trash link.
		$this->render_trash_link( $post, $status );

		// Spinner.
		echo '<span class="spinner"></span>';
	}

	/**
	 * Render hidden inputs that WordPress core needs.
	 *
	 * When submitdiv is removed, these hidden fields must be provided
	 * so that wp-admin/js/post.js and _wp_translate_postdata() work correctly.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_hidden_inputs( $post ) {
		$status = $post->post_status;
		wp_nonce_field( Documentate_Transitions::NONCE, Documentate_Transitions::NONCE );
		?>
		<div style="display:none;">
			<?php submit_button( 'Guardar', '', 'save', false ); ?>
			<input type="hidden" id="publish" name="publish" value="" />
		</div>
		<input type="hidden" name="post_status" id="post_status" value="<?php echo esc_attr( $status ); ?>" />
		<input type="hidden" name="hidden_post_status" id="hidden_post_status" value="<?php echo esc_attr( $status ); ?>" />
		<input type="hidden" name="visibility" value="public" />
		<input type="hidden" name="hidden_post_visibility" value="public" />
		<?php
	}

	/**
	 * Render the document type selector section inside the management metabox.
	 *
	 * Replicates the logic from Documentate_Documents::render_type_metabox()
	 * so the doc type selection lives inside the unified management box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_doc_type_section( $post ) {
		wp_nonce_field( 'documentate_type_nonce', 'documentate_type_nonce' );

		$assigned = wp_get_post_terms( $post->ID, $this->taxonomy, array( 'fields' => 'ids' ) );
		$current = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy' => $this->taxonomy,
				'hide_empty' => false,
			)
		);

		echo '<div class="documentate-doc-type-section">';

		if ( ! $terms || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html( 'No hay tipos de documento definidos. Crea uno en Tipos de documento.' ) . '</p>';
			echo '</div>';
			return;
		}

		$locked = $current > 0 && 'auto-draft' !== $post->post_status;
		echo '<p class="description">'
				. esc_html( 'Elige el tipo al crear el documento. No se puede cambiar después.' )
				. '</p>';
		if ( $locked ) {
			$term = get_term( $current, $this->taxonomy );
			echo '<p><strong>'
					. esc_html( 'Tipo seleccionado:' )
					. '</strong> '
					. esc_html( $term ? $term->name : '' )
					. '</p>';
			echo '<input type="hidden" name="documentate_doc_type" value="' . esc_attr( (string) $current ) . '" />';
		} else {
			echo '<select name="documentate_doc_type" class="widefat">';
			echo '<option value="">' . esc_html( 'Selecciona un tipo…' ) . '</option>';
			foreach ( $terms as $t ) {
				echo '<option value="'
						. esc_attr( (string) $t->term_id )
						. '" '
						. selected( $current, $t->term_id, false )
						. '>'
						. esc_html( $t->name )
						. '</option>';
			}
			echo '</select>';
		}

		echo '</div>';
	}

	/**
	 * Render the visual stepper showing workflow progress.
	 *
	 * Steps: Borrador -> [En gestión] -> En revisión -> Aprobado
	 *
	 * @param string $status         Current post status.
	 * @param bool   $has_management Whether the type goes through gestión documental.
	 */
	private function render_stepper( $status, $has_management ) {
		$steps = Documentate_Statuses::labels();
		unset( $steps['archived'] );
		// The step the document is standing on always stays: a type can stop
		// going through gestión documental while a document of that type is
		// already in en_gestion, and the stepper must not answer "Borrador".
		if ( ! $has_management && 'en_gestion' !== $status ) {
			unset( $steps['en_gestion'] );
		}

		$step_order = array_keys( $steps );

		// Map auto-draft to draft, archived to publish for stepper purposes.
		$effective_status = $status;
		if ( 'auto-draft' === $status ) {
			$effective_status = 'draft';
		} elseif ( 'archived' === $status ) {
			$effective_status = 'publish';
		}

		$current_index = array_search( $effective_status, $step_order, true );
		if ( false === $current_index ) {
			$current_index = 0;
		}

		echo '<div class="documentate-stepper">';
		foreach ( $step_order as $index => $step_key ) {
			$css_class = 'documentate-stepper__step';
			if ( $index === $current_index ) {
				$css_class .= ' is-current is-status-' . $step_key;
			}
			echo '<div class="' . esc_attr( $css_class ) . '">';
			echo '<span class="documentate-stepper__dot"></span>';
			echo '<span class="documentate-stepper__label">' . esc_html( $steps[ $step_key ] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render status messages based on current state.
	 *
	 * @param string $status         Current post status.
	 * @param bool   $is_admin       Whether current user is admin.
	 * @param bool   $has_doc_type   Whether post has a document type.
	 * @param bool   $has_management Whether the type goes through gestión documental.
	 * @param bool   $can_modify     Whether the current user may modify the document.
	 */
	private function render_status_messages( $status, $is_admin, $has_doc_type, $has_management, $can_modify ) {
		if ( ! $has_doc_type && 'auto-draft' !== $status ) {
			$this->render_message( 'warning', 'warning', 'No hay tipo de documento seleccionado. Debes asignar un tipo antes de enviarlo.' );
		}

		$key = 'auto-draft' === $status ? 'draft' : $status;
		if ( 'draft' === $key && $is_admin ) {
			return;
		}

		$message = Documentate_Statuses::metabox_message( $key, $is_admin, $has_management, $can_modify );
		if ( $message ) {
			$this->render_message( $message[0], $message[1], $message[2] );
		}
	}

	/**
	 * Print one status message paragraph.
	 *
	 * @param string $type Message modifier (warning, success, pending, draft).
	 * @param string $icon Dashicon name without prefix.
	 * @param string $text Message text.
	 */
	private function render_message( $type, $icon, $text ) {
		echo '<p class="documentate-mgmt-message documentate-mgmt-message--' . esc_attr( $type ) . '">';
		echo '<span class="dashicons dashicons-' . esc_attr( $icon ) . '"></span> ';
		echo esc_html( $text );
		echo '</p>';
	}

	/**
	 * Render context-sensitive action buttons.
	 *
	 * @param WP_Post $post           Current post object.
	 * @param string  $status         Current post status.
	 * @param bool    $is_admin       Whether current user is admin.
	 * @param bool    $has_management Whether the type goes through gestión documental.
	 * @param bool    $can_modify     Whether the current user may modify the document.
	 */
	private function render_action_buttons( $post, $status, $is_admin, $has_management, $can_modify ) {
		echo '<div class="documentate-mgmt-actions">';

		if ( in_array( $status, array( 'auto-draft', 'draft' ), true ) ) {
			$this->render_draft_buttons( $status, $has_management );
		} elseif ( 'en_gestion' === $status ) {
			$this->render_management_buttons( $can_modify );
		} elseif ( 'pending' === $status ) {
			$this->render_pending_buttons( $is_admin, $has_management );
		} elseif ( 'publish' === $status ) {
			$this->render_published_buttons( $post, $is_admin );
		} elseif ( 'archived' === $status ) {
			$this->render_archived_buttons( $post, $is_admin );
		}

		echo '</div>';
	}

	/**
	 * Print one action button.
	 *
	 * @param string $id       Element ID.
	 * @param string $modifier Button modifier (danger, warning, success, primary).
	 * @param string $icon     Dashicon name without prefix.
	 * @param string $label    Button text.
	 * @param string $status   Optional status the button posts (data-estado).
	 */
	private function render_button( $id, $modifier, $icon, $label, $status = '' ) {
		printf(
			'<button type="button" id="%1$s" class="button documentate-mgmt-btn documentate-mgmt-btn--%2$s"%5$s><span class="dashicons dashicons-%3$s"></span> %4$s</button>',
			esc_attr( $id ),
			esc_attr( $modifier ),
			esc_attr( $icon ),
			esc_html( $label ),
			'' !== $status ? ' data-estado="' . esc_attr( $status ) . '"' : ''
		);
	}

	/**
	 * Print a locked notice instead of buttons.
	 *
	 * @param string $text Notice text.
	 */
	private function render_locked_notice( $text ) {
		echo '<p class="documentate-mgmt-locked-notice">';
		echo '<span class="dashicons dashicons-lock"></span> ';
		echo esc_html( $text );
		echo '</p>';
	}

	/**
	 * Print the reason textarea shown before a "Devolver" submit.
	 *
	 * Hidden until the workflow script reveals it; the no-JS form still posts it.
	 */
	private function render_reason_field() {
		?>
		<div class="documentate-mgmt-motivo" style="display:none;">
			<label for="documentate-return-draft-motivo"><?php echo esc_html( 'Motivo de la devolución' ); ?></label>
			<textarea id="documentate-return-draft-motivo" name="documentate_motivo" class="widefat" rows="3" placeholder="<?php echo esc_attr( 'Qué falta o qué hay que corregir' ); ?>"></textarea>
			<p class="description"><?php echo esc_html( 'El motivo se envía por correo y queda en la actividad.' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render buttons for draft/auto-draft status.
	 *
	 * A brand-new document (auto-draft) only offers "Guardar borrador": it
	 * cannot be sent until it has been saved at least once.
	 *
	 * @param string $status         Current post status (auto-draft or draft).
	 * @param bool   $has_management Whether the type goes through gestión documental.
	 */
	private function render_draft_buttons( $status, $has_management ) {
		$this->render_button( 'documentate-save-draft', 'danger', 'cloud-saved', 'Guardar borrador' );

		if ( 'draft' === $status ) {
			$this->render_button(
				'documentate-send-review',
				'warning',
				'share-alt2',
				Documentate_Transitions::label( $has_management ? 'enviar_gestion' : 'enviar_revision' ),
				$has_management ? 'en_gestion' : 'pending'
			);
		}
	}

	/**
	 * Render buttons for the en_gestion status.
	 *
	 * @param bool $can_modify Whether the current user (gestión/admin) may act.
	 */
	private function render_management_buttons( $can_modify ) {
		if ( ! $can_modify ) {
			$this->render_locked_notice( 'El documento está en gestión documental. No hay acciones disponibles.' );
			return;
		}

		$this->render_button( 'documentate-save-gestion', 'warning', 'cloud-saved', 'Guardar' );
		$this->render_button( 'documentate-pass-admin', 'success', 'share-alt2', Documentate_Transitions::label( 'pasar_admin' ) );
		$this->render_button( 'documentate-return-draft', 'danger', 'undo', Documentate_Transitions::label( 'devolver_area', 'en_gestion' ) );
		$this->render_reason_field();
	}

	/**
	 * Render buttons for pending status.
	 *
	 * @param bool $is_admin       Whether current user is admin.
	 * @param bool $has_management Whether the type goes through gestión documental.
	 */
	private function render_pending_buttons( $is_admin, $has_management ) {
		if ( ! $is_admin ) {
			$this->render_locked_notice( 'El documento está en revisión. No hay acciones disponibles.' );
			return;
		}

		$this->render_button( 'documentate-return-draft', 'danger', 'undo', Documentate_Transitions::label( 'devolver_area', 'pending' ) );
		if ( $has_management ) {
			$this->render_button( 'documentate-return-gestion', 'danger', 'undo', Documentate_Transitions::label( 'devolver_gestion' ) );
		}
		$this->render_button( 'documentate-save-pending', 'warning', 'cloud-saved', 'Guardar' );
		$this->render_button( 'documentate-approve-publish', 'success', 'saved', Documentate_Transitions::label( 'aprobar' ) );
		$this->render_reason_field();
	}

	/**
	 * Render buttons for published status.
	 *
	 * @param WP_Post $post     Current post object.
	 * @param bool    $is_admin Whether current user is admin.
	 */
	private function render_published_buttons( $post, $is_admin ) {
		if ( ! $is_admin ) {
			$this->render_locked_notice( 'El documento está aprobado y bloqueado.' );
			return;
		}

		$this->render_button( 'documentate-return-review', 'warning', 'undo', Documentate_Transitions::label( 'devolver_revision' ) );
		?>
		<a href="<?php echo esc_url( $this->get_archive_action_url( $post->ID, 'archive' ) ); ?>" class="documentate-mgmt-link">
			<?php echo esc_html( Documentate_Transitions::label( 'archivar' ) ); ?>
		</a>
		<?php
	}

	/**
	 * Render buttons for archived status.
	 *
	 * @param WP_Post $post     Current post object.
	 * @param bool    $is_admin Whether current user is admin.
	 */
	private function render_archived_buttons( $post, $is_admin ) {
		if ( ! $is_admin ) {
			$this->render_locked_notice( 'El documento está archivado y bloqueado.' );
			return;
		}
		?>
		<a href="<?php echo esc_url( $this->get_archive_action_url( $post->ID, 'unarchive' ) ); ?>" class="documentate-mgmt-link">
			<?php echo esc_html( Documentate_Transitions::label( 'desarchivar' ) ); ?>
		</a>
		<?php
	}

	/**
	 * Render trash link.
	 *
	 * Only users who may modify the document in its status can trash it.
	 *
	 * @param WP_Post $post   Current post object.
	 * @param string  $status Current post status.
	 */
	private function render_trash_link( $post, $status ) {
		if ( ! Documentate_Workflow::user_can_modify_status( (string) $status, get_current_user_id() ) ) {
			return;
		}

		if ( current_user_can( 'delete_post', $post->ID ) ) {
			$delete_url = get_delete_post_link( $post->ID );
			if ( $delete_url ) {
				echo '<div class="documentate-mgmt-delete">';
				printf(
					'<a class="submitdelete deletion" href="%s">%s</a>',
					esc_url( $delete_url ),
					esc_html( 'Mover a la papelera' ),
				);
				echo '</div>';
			}
		}
	}

	/**
	 * Get the URL for archive/unarchive actions.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action type: 'archive' or 'unarchive'.
	 * @return string URL with nonce.
	 */
	private function get_archive_action_url( $post_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'documentate_' . $action,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'documentate_' . $action . '_' . $post_id,
		);
	}

	/**
	 * Render the revision count link in the meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_revision_link( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$revisions = wp_get_post_revisions( $post->ID );
		$count = count( $revisions );

		if ( $count < 1 ) {
			return;
		}

		$revisions_url = admin_url( 'revision.php?revision=' . $post->ID );
		// Get the latest revision to link to the comparison view.
		$latest_revision = reset( $revisions );
		if ( $latest_revision ) {
			$revisions_url = admin_url( 'revision.php?revision=' . $latest_revision->ID );
		}

		echo '<div class="documentate-mgmt-revisions">';
		printf(
			'<a href="%s">%s</a>',
			esc_url( $revisions_url ),
			esc_html( 1 === $count ? '1 revisión' : $count . ' revisiones' ),
		);
		echo '</div>';
	}
}
