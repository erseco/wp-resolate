<?php
/**
 * Workflow transitions of a document, driven by one rule table.
 *
 * Every move between statuses (send to revisión, pass to jefatura de
 * servicio, return with a reason, approve, archive) is a row of the table:
 * where it starts, where it lands, who may do it, whether the type must go
 * through revisión and whether a reason is required. The application asks
 * available() to draw its buttons and apply() to run one; wp-admin saves
 * are validated by allowed() from the workflow and recorded afterwards by
 * record_from_save().
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Transitions
 *
 * Static, data-driven transition engine.
 */
class Documentate_Transitions {

	/**
	 * Post type of the documents.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * Nonce action/name that authorises a posted reason in wp-admin.
	 *
	 * @var string
	 */
	const NONCE = 'documentate_workflow_nonce';

	/**
	 * Minimum length of a return reason after trimming.
	 *
	 * @var int
	 */
	const REASON_MIN = 3;

	/**
	 * Statuses a document can only reach once it has a document type.
	 *
	 * Documentate_Workflow forces a document with no type back to draft
	 * (apply_classification_rule), so offering these moves on a document
	 * without one would draw a button that can only ever fail.
	 *
	 * @var string[]
	 */
	const TARGETS_REQUIRING_TYPE = array( 'en_gestion', 'pending', 'publish' );

	/**
	 * Transition being applied by apply(): array( post_id, target, reason ).
	 *
	 * While set, the workflow lets that status change through and the
	 * notifier can read the reason before it is stored anywhere else.
	 *
	 * @var array{0:int,1:string,2:string}|null
	 */
	private static $in_progress = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'record_from_save' ), 5, 3 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'block_trash' ), 10, 2 );
	}

	/**
	 * The rule table.
	 *
	 * The "who" column: "area" = anyone who may edit the document; "gestion" =
	 * revisión, jefatura de servicio or administración; "jefatura" = jefatura
	 * de servicio or administración; "admin" = site administrators only. The
	 * "has_management" column: true/false = only for types that do / do not
	 * go through revisión; null = any type.
	 * - help: one line under the button, for the moves that hand the
	 *   document to somebody else; empty when there is nothing to explain.
	 * - icon: the icon of the button: "forward" (drawn after the label,
	 *   the document moves on), "return" or "check" (drawn before it), or
	 *   empty.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules() {
		return array_merge( self::rules_of_area_and_management(), self::rules_of_jefatura_and_admin() );
	}

	/**
	 * The moves of the área and of revisión: sending, passing on, returning.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rules_of_area_and_management() {
		return array(
			array(
				'key' => 'enviar_gestion',
				'from' => 'draft',
				'target' => 'en_gestion',
				'who' => 'area',
				'has_management' => true,
				'reason' => false,
				'label' => 'Enviar a revisión',
				'confirm' => '¿Enviar el documento a revisión? Ya no podrás modificarlo hasta que te lo devuelvan.',
				'help' => 'Revisión completará los datos oficiales. No podrás editarlo hasta que te lo devuelvan.',
				'icon' => 'forward',
				'event' => 'envió el documento a revisión',
				'redirect' => 'detalle',
				'flag' => 'enviado',
			),
			array(
				'key' => 'enviar_revision',
				'from' => 'draft',
				'target' => 'pending',
				'who' => 'area',
				'has_management' => false,
				'reason' => false,
				'label' => 'Enviar a aprobación',
				'confirm' => '¿Enviar el documento a la jefatura de servicio para su aprobación? Ya no podrás modificarlo hasta que te lo devuelvan.',
				'help' => 'La jefatura de servicio lo aprobará. No podrás editarlo hasta que te lo devuelvan.',
				'icon' => 'forward',
				'event' => 'envió el documento a aprobación',
				'redirect' => 'detalle',
				'flag' => 'enviado',
			),
			array(
				'key' => 'pasar_admin',
				'from' => 'en_gestion',
				'target' => 'pending',
				'who' => 'gestion',
				'has_management' => null,
				'reason' => false,
				'label' => 'Pasar a aprobación',
				'confirm' => '¿Pasar el documento a la jefatura de servicio para su aprobación? Revisión ya no podrá modificarlo hasta que lo devuelvan.',
				'help' => 'La jefatura de servicio lo aprobará. Revisión no podrá modificarlo hasta que lo devuelvan.',
				'icon' => 'forward',
				'event' => 'pasó el documento a aprobación',
				'redirect' => 'detalle',
				'flag' => 'enviado',
			),
			array(
				'key' => 'devolver_area',
				'from' => 'en_gestion',
				'target' => 'draft',
				'who' => 'gestion',
				'has_management' => null,
				'reason' => true,
				'label' => 'Devolver al área',
				'confirm' => '',
				'help' => '',
				'icon' => 'return',
				'event' => 'devolvió el documento al área',
				'redirect' => 'lista',
				'flag' => 'devuelto',
			),
		);
	}

	/**
	 * The moves of the jefatura de servicio and of the administrators:
	 * approving, returning, archiving.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function rules_of_jefatura_and_admin() {
		return array(
			array(
				'key' => 'aprobar',
				'from' => 'pending',
				'target' => 'publish',
				'who' => 'jefatura',
				'has_management' => null,
				'reason' => false,
				'label' => 'Aprobar',
				'confirm' => '¿Aprobar el documento? Quedará bloqueado; solo se podrá consultar y descargar.',
				'help' => 'Quedará bloqueado: solo se podrá consultar y descargar.',
				'icon' => 'check',
				'event' => 'aprobó el documento',
				'redirect' => 'detalle',
				'flag' => 'aprobado',
			),
			array(
				'key' => 'devolver_gestion',
				'from' => 'pending',
				'target' => 'en_gestion',
				'who' => 'jefatura',
				'has_management' => true,
				'reason' => true,
				'label' => 'Devolver a revisión',
				'confirm' => '',
				'help' => '',
				'icon' => 'return',
				'event' => 'devolvió el documento a revisión',
				'redirect' => 'lista',
				'flag' => 'devuelto',
			),
			array(
				'key' => 'devolver_area',
				'from' => 'pending',
				'target' => 'draft',
				'who' => 'jefatura',
				'has_management' => null,
				'reason' => true,
				'label' => 'Devolver al área',
				'confirm' => '',
				'help' => '',
				'icon' => 'return',
				'event' => 'devolvió el documento al área',
				'redirect' => 'lista',
				'flag' => 'devuelto',
			),
			array(
				'key' => 'devolver_revision',
				'from' => 'publish',
				'target' => 'pending',
				'who' => 'admin',
				'has_management' => null,
				'reason' => false,
				'label' => 'Devolver a aprobación',
				'confirm' => '¿Devolver el documento a aprobación? Dejará de estar aprobado y volverá a la lista de la jefatura de servicio.',
				'help' => '',
				'icon' => 'return',
				'event' => 'devolvió el documento a aprobación',
				'redirect' => 'detalle',
				'flag' => '',
			),
			array(
				'key' => 'archivar',
				'from' => 'publish',
				'target' => 'archived',
				'who' => 'admin',
				'has_management' => null,
				'reason' => false,
				'label' => 'Archivar',
				'confirm' => '',
				'help' => '',
				'icon' => '',
				'event' => 'archivó el documento',
				'redirect' => 'detalle',
				'flag' => '',
			),
			array(
				'key' => 'desarchivar',
				'from' => 'archived',
				'target' => 'publish',
				'who' => 'admin',
				'has_management' => null,
				'reason' => false,
				'label' => 'Desarchivar',
				'confirm' => '',
				'help' => '',
				'icon' => '',
				'event' => 'desarchivó el documento',
				'redirect' => 'detalle',
				'flag' => '',
			),
		);
	}

	/**
	 * Rules that move a document from one status to another.
	 *
	 * @param string $from   Stored status.
	 * @param string $target Requested status.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rules_between( $from, $target ) {
		return array_values(
			array_filter(
				self::rules(),
				static function ( $rule ) use ( $from, $target ) {
					return $rule['from'] === $from && $rule['target'] === $target;
				}
			)
		);
	}

	/**
	 * Whether a rule fits the type and the user.
	 *
	 * @param array $rule           Rule row.
	 * @param int   $post_id        Document ID.
	 * @param int   $user_id        User ID.
	 * @param bool  $has_management Whether the document type goes through revisión.
	 * @param bool  $require_edit   Also require edit_post on the document (UI checks);
	 *                            saves coming through wp-admin already passed it.
	 * @return bool
	 */
	private static function rule_applies( array $rule, $post_id, $user_id, $has_management, $require_edit ) {
		if ( null !== $rule['has_management'] && $rule['has_management'] !== $has_management ) {
			return false;
		}

		if ( $require_edit && ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return false;
		}

		return self::who_applies( (string) $rule['who'], $user_id );
	}

	/**
	 * Whether the user plays the role a rule names in its "who" column.
	 *
	 * @param string $who     Rule role: area, gestion, jefatura or admin.
	 * @param int    $user_id User ID.
	 * @return bool
	 */
	private static function who_applies( $who, $user_id ) {
		if ( 'admin' === $who ) {
			return Documentate_Roles::is_administration( $user_id );
		}

		if ( 'jefatura' === $who ) {
			return Documentate_Roles::is_head( $user_id );
		}

		if ( 'gestion' === $who ) {
			return Documentate_Roles::is_management( $user_id );
		}

		return true;
	}

	/**
	 * Whether a return reason is long enough.
	 *
	 * @param string $reason Reason.
	 * @return bool
	 */
	private static function reason_valid( $reason ) {
		return mb_strlen( trim( (string) $reason ) ) >= self::REASON_MIN;
	}

	/**
	 * Whether apply() is currently moving this document to this status.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $target  Requested status.
	 * @return bool
	 */
	private static function is_in_progress( $post_id, $target ) {
		return null !== self::$in_progress
			&& (int) self::$in_progress[0] === (int) $post_id
			&& self::$in_progress[1] === $target;
	}

	/**
	 * Transitions the user may run on a document right now, keyed by clave.
	 *
	 * @param WP_Post  $post    Document.
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return array<string,array<string,mixed>>
	 */
	public static function available( WP_Post $post, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$has_management = Documentate_Document_Data::has_management( $post );
		$without_type = null === Documentate_Document_Data::type( $post );

		$available = array();
		foreach ( self::rules() as $rule ) {
			if ( $rule['from'] !== $post->post_status ) {
				continue;
			}
			if ( $without_type && in_array( $rule['target'], self::TARGETS_REQUIRING_TYPE, true ) ) {
				continue;
			}
			if ( ! self::rule_applies( $rule, $post->ID, $user_id, $has_management, true ) ) {
				continue;
			}
			$available[ $rule['key'] ] = $rule;
		}

		return $available;
	}

	/**
	 * Whether a status change posted for a document is allowed for the user.
	 *
	 * A document is born as a draft: creation (auto-draft / new / none) is
	 * evaluated as a change from draft, so auto-draft→draft and new→draft
	 * are always allowed and anything further must be a transition of the
	 * table — except for administración, who may create a document in any
	 * status (seeders, imports, fixes). Same-status saves, trash and untrash
	 * are always allowed; administrators may move between publish and
	 * archived. A change the table knows is allowed when one of its rules
	 * fits the role, the type and (for returns) the reason. Outside the
	 * table, publish-like requests from draft are left to the role rule of
	 * the workflow, and administrators keep their freedom except when
	 * leaving en_gestion, which must go through the table.
	 *
	 * @param int       $post_id        Document ID (0 when it does not exist yet).
	 * @param string    $from           Stored status.
	 * @param string    $target         Requested status.
	 * @param int       $user_id        User ID.
	 * @param string    $reason         Reason posted with the change, when any.
	 * @param bool|null $has_management Whether the type goes through revisión, when
	 *                               the caller knows better than the stored
	 *                               document (type posted with the save).
	 * @return bool
	 */
	public static function allowed( $post_id, $from, $target, $user_id, $reason = '', $has_management = null ) {
		$creation = in_array( $from, array( '', 'new', 'auto-draft' ), true );
		if ( $creation ) {
			$from = 'draft';
		}

		if ( self::always_allowed( $post_id, $from, $target ) ) {
			return true;
		}

		$is_admin = Documentate_Roles::is_administration( $user_id );
		if ( $is_admin && self::free_for_administration( $creation, $from, $target ) ) {
			return true;
		}

		$rules = self::rules_between( $from, $target );
		if ( ! empty( $rules ) ) {
			return self::any_rule_allows( $rules, $post_id, $user_id, $reason, $has_management );
		}

		if ( 'draft' === $from && in_array( $target, array( 'publish', 'private', 'future' ), true ) ) {
			return true;
		}

		return $is_admin && 'en_gestion' !== $from;
	}

	/**
	 * Changes nobody is refused: same status, trash and untrash, and the one apply() is running.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $from    Stored status (creation already mapped to draft).
	 * @param string $target  Requested status.
	 * @return bool
	 */
	private static function always_allowed( $post_id, $from, $target ) {
		return $from === $target
			|| 'trash' === $from
			|| 'trash' === $target
			|| self::is_in_progress( $post_id, $target );
	}

	/**
	 * Changes administración may always make: creating in any status, publish ↔ archived.
	 *
	 * @param bool   $creation Whether the document is being created.
	 * @param string $from     Stored status.
	 * @param string $target   Requested status.
	 * @return bool
	 */
	private static function free_for_administration( $creation, $from, $target ) {
		$published_statuses = array( 'publish', 'archived' );

		return $creation
			|| ( in_array( $from, $published_statuses, true ) && in_array( $target, $published_statuses, true ) );
	}

	/**
	 * Whether any of the rules fits the user, the type and the reason.
	 *
	 * @param array     $rules          Candidate rules.
	 * @param int       $post_id        Document ID.
	 * @param int       $user_id        User ID.
	 * @param string    $reason         Reason posted with the change.
	 * @param bool|null $has_management Whether the type goes through revisión; null reads the document.
	 * @return bool
	 */
	private static function any_rule_allows( array $rules, $post_id, $user_id, $reason, $has_management = null ) {
		if ( null === $has_management ) {
			$has_management = Documentate_Document_Data::has_management( $post_id );
		}

		foreach ( $rules as $rule ) {
			if ( $rule['reason'] && ! self::reason_valid( $reason ) ) {
				continue;
			}
			if ( self::rule_applies( $rule, $post_id, $user_id, $has_management, false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run a transition on a document as the current user.
	 *
	 * Order matters: the "devuelto" mark and the event are written first so
	 * the notifier (hooked on transition_post_status) can read them, then
	 * the status changes with the transition flagged as in progress so the
	 * workflow lets it through.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $key     Rule key (enviar_gestion, devolver_area, ...).
	 * @param string $reason  Reason, required by return rules.
	 * @return true|WP_Error
	 */
	public static function apply( $post_id, $key, $reason = '' ) {
		$post = Documentate_Document_Data::post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'documento_invalido', 'El documento no existe.' );
		}

		$available = self::available( $post );
		if ( ! isset( $available[ $key ] ) ) {
			return new WP_Error( 'transicion_no_disponible', 'Esa acción no está disponible en el estado actual del documento.' );
		}

		$rule = $available[ $key ];
		$reason = trim( sanitize_textarea_field( (string) $reason ) );
		if ( $rule['reason'] && ! self::reason_valid( $reason ) ) {
			return new WP_Error( 'motivo_requerido', 'Para devolver un documento hay que decir por qué.' );
		}

		$previous_returned = get_post_meta( $post->ID, Documentate_Document_Data::META_RETURNED, true );
		$event_id = self::record( $post->ID, $rule, $reason );

		self::$in_progress = array( $post->ID, $rule['target'], $reason );
		try {
			$result = wp_update_post(
				array(
					'ID' => $post->ID,
					'post_status' => $rule['target'],
				),
				true
			);
		} finally {
			self::$in_progress = null;
		}

		if ( is_wp_error( $result ) || get_post_status( $post->ID ) !== $rule['target'] ) {
			self::undo_record( $post->ID, $event_id, $previous_returned );

			return is_wp_error( $result )
				? $result
				: new WP_Error( 'transicion_no_aplicada', 'No se pudo cambiar el estado del documento.' );
		}

		return true;
	}

	/**
	 * Write the "devuelto" mark (or clear it) and record the event of a rule.
	 *
	 * @param int    $post_id Document ID.
	 * @param array  $rule    Rule row.
	 * @param string $reason  Reason (only meaningful for return rules).
	 * @return int Event comment ID.
	 */
	private static function record( $post_id, array $rule, $reason ) {
		if ( ! $rule['reason'] ) {
			Documentate_Document_Data::clear_returned( $post_id );

			return Documentate_Activity::record_event( $post_id, $rule['event'] );
		}

		Documentate_Document_Data::mark_returned(
			$post_id,
			$reason,
			'pending' === $rule['from'] ? 'administracion' : 'gestion',
			'en_gestion' === $rule['target'] ? 'gestion' : 'area'
		);

		return Documentate_Activity::record_event( $post_id, $rule['event'] . ': «' . $reason . '»', $reason );
	}

	/**
	 * Remove what record() wrote when the status change did not land.
	 *
	 * @param int    $post_id         Document ID.
	 * @param int    $event_id       Event comment ID.
	 * @param string $previous_returned Previous raw "devuelto" meta.
	 * @return void
	 */
	private static function undo_record( $post_id, $event_id, $previous_returned ) {
		if ( $event_id > 0 ) {
			wp_delete_comment( $event_id, true );
		}

		if ( '' === (string) $previous_returned ) {
			Documentate_Document_Data::clear_returned( $post_id );
		} else {
			update_post_meta( $post_id, Documentate_Document_Data::META_RETURNED, wp_slash( $previous_returned ) );
		}
	}

	/**
	 * Reason of the transition apply() is running on a document, for the notifier.
	 *
	 * @param int $post_id Document ID.
	 * @return string Empty when no transition is in progress for it.
	 */
	public static function reason_in_progress( $post_id ) {
		if ( null === self::$in_progress || (int) self::$in_progress[0] !== (int) $post_id ) {
			return '';
		}

		return (string) self::$in_progress[2];
	}

	/**
	 * Rule row for a key, from a given status when several share the key.
	 *
	 * @param string $key  Rule key.
	 * @param string $from Optional stored status to disambiguate.
	 * @return array<string,mixed>|null
	 */
	public static function rule( $key, $from = '' ) {
		foreach ( self::rules() as $rule ) {
			if ( $rule['key'] === $key && ( '' === $from || $rule['from'] === $from ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Button label of a rule, so the UIs never repeat the table.
	 *
	 * @param string $key  Rule key.
	 * @param string $from Optional stored status to disambiguate.
	 * @return string Empty for an unknown rule.
	 */
	public static function label( $key, $from = '' ) {
		$rule = self::rule( $key, $from );

		return $rule ? (string) $rule['label'] : '';
	}

	/**
	 * Confirmation text of a rule, so the UIs never repeat the table.
	 *
	 * @param string $key  Rule key.
	 * @param string $from Optional stored status, to pick between two rules
	 *                     that share a key (`devolver_area` exists both from
	 *                     `en_gestion` and from `pending`).
	 * @return string Empty for an unknown rule or one without confirmation.
	 */
	public static function confirmation( $key, $from = '' ) {
		$rule = self::rule( $key, $from );

		return $rule ? (string) $rule['confirm'] : '';
	}

	/**
	 * View the application lands on after an action.
	 *
	 * @param string $key  Rule key, or "guardar" for a plain save.
	 * @param string $from Optional stored status, to pick between two rules
	 *                     that share a key.
	 * @return string "editar", "detalle" or "lista".
	 */
	public static function redirect( $key, $from = '' ) {
		$rule = self::rule( $key, $from );

		return $rule ? (string) $rule['redirect'] : 'editar';
	}

	/**
	 * Feedback flag the application shows after an action.
	 *
	 * @param string $key  Rule key, or "guardar" for a plain save.
	 * @param string $from Optional stored status, to pick between two rules
	 *                     that share a key.
	 * @return string "guardado", "enviado", "devuelto", "aprobado" or empty.
	 */
	public static function flag( $key, $from = '' ) {
		$rule = self::rule( $key, $from );

		return $rule ? (string) $rule['flag'] : 'guardado';
	}

	/**
	 * Reason posted by the wp-admin management metabox, nonce-verified.
	 *
	 * @return string Empty when absent or when the nonce does not verify.
	 */
	public static function posted_reason() {
		if ( ! isset( $_POST['documentate_motivo'], $_POST[ self::NONCE ] ) ) {
			return '';
		}

		$nonce = sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return '';
		}

		return trim( sanitize_textarea_field( wp_unslash( $_POST['documentate_motivo'] ) ) );
	}

	/**
	 * Record a transition that arrived through a plain save (wp-admin).
	 *
	 * The transition run by apply() already wrote everything; any other
	 * status change the table recognises gets its mark and event here. The
	 * first save of a document created in wp-admin (auto-draft → draft)
	 * records "creó el borrador", as the application does for its own; a
	 * first save straight into the pipeline is recorded as the transition
	 * from draft it amounts to. Documents inserted programmatically (new →
	 * anything) record nothing: seeders and imports write their own history.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function record_from_save( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( self::is_in_progress( $post->ID, $new_status ) ) {
			return;
		}

		if ( 'auto-draft' === $old_status ) {
			if ( 'draft' === $new_status ) {
				Documentate_Activity::record_event( $post->ID, 'creó el borrador' );
				return;
			}
			$old_status = 'draft';
		}

		$rules = self::rules_between( $old_status, $new_status );
		if ( empty( $rules ) ) {
			return;
		}

		self::record( $post->ID, $rules[0], self::posted_reason() );
	}

	/**
	 * Refuse to trash a document locked for the current user.
	 *
	 * The workflow freezes the status of a locked document, so a trash
	 * request would leave it in place while WordPress hides its activity
	 * (events and comments get "post-trashed"). Whoever cannot modify the
	 * document in its status cannot trash it either.
	 *
	 * @param bool|null $trash Whether to go forward with trashing (null = default).
	 * @param WP_Post   $post  Post being trashed.
	 * @return bool|null False to stop the trash, otherwise the incoming value.
	 */
	public static function block_trash( $trash, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $trash;
		}

		if ( Documentate_Workflow::user_can_modify_status( (string) $post->post_status, get_current_user_id() ) ) {
			return $trash;
		}

		return false;
	}
}
