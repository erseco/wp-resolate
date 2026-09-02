<?php
/**
 * Workflow transitions of a document, driven by one rule table.
 *
 * Every move between statuses (send to gestión, pass to administración,
 * return with a reason, approve, archive) is a row of the table: where it
 * starts, where it lands, who may do it, whether the type must go through
 * gestión and whether a reason is required. The application asks
 * disponibles() to draw its buttons and aplicar() to run one; wp-admin saves
 * are validated by permitida() from the workflow and recorded afterwards by
 * registrar_desde_guardado().
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Transiciones
 *
 * Static, data-driven transition engine.
 */
class Documentate_Transiciones {

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
	const MOTIVO_MIN = 3;

	/**
	 * Transition being applied by aplicar(): array( post_id, destino, motivo ).
	 *
	 * While set, the workflow lets that status change through and the
	 * notifier can read the reason before it is stored anywhere else.
	 *
	 * @var array{0:int,1:string,2:string}|null
	 */
	private static $en_curso = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'registrar_desde_guardado' ), 5, 3 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'bloquear_papelera' ), 10, 2 );
	}

	/**
	 * The rule table.
	 *
	 * The "quien" column: "area" = anyone who may edit the document;
	 * "gestion" = gestión documental or administración; "admin" =
	 * administración only. The "con_gestion" column: true/false = only for
	 * types that do / do not go through gestión; null = any type.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function reglas() {
		return array(
			array(
				'clave' => 'enviar_gestion',
				'desde' => 'draft',
				'destino' => 'en_gestion',
				'quien' => 'area',
				'con_gestion' => true,
				'motivo' => false,
				'etiqueta' => 'Enviar a gestión',
				'confirmar' => '¿Enviar el documento a gestión documental? Ya no podrás modificarlo hasta que te lo devuelvan.',
				'evento' => 'envió el documento a gestión',
				'redireccion' => 'detalle',
				'bandera' => 'enviado',
			),
			array(
				'clave' => 'enviar_revision',
				'desde' => 'draft',
				'destino' => 'pending',
				'quien' => 'area',
				'con_gestion' => false,
				'motivo' => false,
				'etiqueta' => 'Enviar a revisión',
				'confirmar' => '¿Enviar el documento a revisión de administración? Ya no podrás modificarlo hasta que te lo devuelvan.',
				'evento' => 'envió el documento a revisión',
				'redireccion' => 'detalle',
				'bandera' => 'enviado',
			),
			array(
				'clave' => 'pasar_admin',
				'desde' => 'en_gestion',
				'destino' => 'pending',
				'quien' => 'gestion',
				'con_gestion' => null,
				'motivo' => false,
				'etiqueta' => 'Pasar a administración',
				'confirmar' => '¿Pasar el documento a administración? Gestión ya no podrá modificarlo hasta que lo devuelvan.',
				'evento' => 'pasó el documento a administración',
				'redireccion' => 'detalle',
				'bandera' => 'enviado',
			),
			array(
				'clave' => 'devolver_area',
				'desde' => 'en_gestion',
				'destino' => 'draft',
				'quien' => 'gestion',
				'con_gestion' => null,
				'motivo' => true,
				'etiqueta' => 'Devolver al área',
				'confirmar' => '',
				'evento' => 'devolvió el documento al área',
				'redireccion' => 'bandeja',
				'bandera' => 'devuelto',
			),
			array(
				'clave' => 'aprobar',
				'desde' => 'pending',
				'destino' => 'publish',
				'quien' => 'admin',
				'con_gestion' => null,
				'motivo' => false,
				'etiqueta' => 'Aprobar y publicar',
				'confirmar' => '¿Aprobar y publicar el documento? Quedará bloqueado; solo se podrá consultar y descargar.',
				'evento' => 'aprobó y publicó el documento',
				'redireccion' => 'detalle',
				'bandera' => 'aprobado',
			),
			array(
				'clave' => 'devolver_gestion',
				'desde' => 'pending',
				'destino' => 'en_gestion',
				'quien' => 'admin',
				'con_gestion' => true,
				'motivo' => true,
				'etiqueta' => 'Devolver a gestión',
				'confirmar' => '',
				'evento' => 'devolvió el documento a gestión',
				'redireccion' => 'bandeja',
				'bandera' => 'devuelto',
			),
			array(
				'clave' => 'devolver_area',
				'desde' => 'pending',
				'destino' => 'draft',
				'quien' => 'admin',
				'con_gestion' => null,
				'motivo' => true,
				'etiqueta' => 'Devolver al área',
				'confirmar' => '',
				'evento' => 'devolvió el documento al área',
				'redireccion' => 'bandeja',
				'bandera' => 'devuelto',
			),
			array(
				'clave' => 'archivar',
				'desde' => 'publish',
				'destino' => 'archived',
				'quien' => 'admin',
				'con_gestion' => null,
				'motivo' => false,
				'etiqueta' => 'Archivar',
				'confirmar' => '',
				'evento' => 'archivó el documento',
				'redireccion' => 'detalle',
				'bandera' => '',
			),
			array(
				'clave' => 'desarchivar',
				'desde' => 'archived',
				'destino' => 'publish',
				'quien' => 'admin',
				'con_gestion' => null,
				'motivo' => false,
				'etiqueta' => 'Desarchivar',
				'confirmar' => '',
				'evento' => 'desarchivó el documento',
				'redireccion' => 'detalle',
				'bandera' => '',
			),
		);
	}

	/**
	 * Rules that move a document from one status to another.
	 *
	 * @param string $desde   Stored status.
	 * @param string $destino Requested status.
	 * @return array<int,array<string,mixed>>
	 */
	private static function reglas_entre( $desde, $destino ) {
		return array_values(
			array_filter(
				self::reglas(),
				static function ( $regla ) use ( $desde, $destino ) {
					return $regla['desde'] === $desde && $regla['destino'] === $destino;
				}
			)
		);
	}

	/**
	 * Whether a rule fits the type and the user.
	 *
	 * @param array $regla        Rule row.
	 * @param int   $post_id      Document ID.
	 * @param int   $user_id      User ID.
	 * @param bool  $con_gestion  Whether the document type goes through gestión.
	 * @param bool  $exigir_edit  Also require edit_post on the document (UI checks);
	 *                            saves coming through wp-admin already passed it.
	 * @return bool
	 */
	private static function regla_aplicable( array $regla, $post_id, $user_id, $con_gestion, $exigir_edit ) {
		if ( null !== $regla['con_gestion'] && $regla['con_gestion'] !== $con_gestion ) {
			return false;
		}

		if ( $exigir_edit && ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return false;
		}

		if ( 'admin' === $regla['quien'] ) {
			return Documentate_Roles::es_administracion( $user_id );
		}

		if ( 'gestion' === $regla['quien'] ) {
			return Documentate_Roles::es_gestion( $user_id );
		}

		return true;
	}

	/**
	 * Whether a return reason is long enough.
	 *
	 * @param string $motivo Reason.
	 * @return bool
	 */
	private static function motivo_valido( $motivo ) {
		return mb_strlen( trim( (string) $motivo ) ) >= self::MOTIVO_MIN;
	}

	/**
	 * Whether aplicar() is currently moving this document to this status.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $destino Requested status.
	 * @return bool
	 */
	private static function es_en_curso( $post_id, $destino ) {
		return null !== self::$en_curso
			&& (int) self::$en_curso[0] === (int) $post_id
			&& self::$en_curso[1] === $destino;
	}

	/**
	 * Transitions the user may run on a document right now, keyed by clave.
	 *
	 * @param WP_Post  $post    Document.
	 * @param int|null $user_id User ID, or null for the current user.
	 * @return array<string,array<string,mixed>>
	 */
	public static function disponibles( WP_Post $post, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$con_gestion = Documentate_Documento::con_gestion( $post );

		$disponibles = array();
		foreach ( self::reglas() as $regla ) {
			if ( $regla['desde'] !== $post->post_status ) {
				continue;
			}
			if ( ! self::regla_aplicable( $regla, $post->ID, $user_id, $con_gestion, true ) ) {
				continue;
			}
			$disponibles[ $regla['clave'] ] = $regla;
		}

		return $disponibles;
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
	 * @param int       $post_id     Document ID (0 when it does not exist yet).
	 * @param string    $desde       Stored status.
	 * @param string    $destino     Requested status.
	 * @param int       $user_id     User ID.
	 * @param string    $motivo      Reason posted with the change, when any.
	 * @param bool|null $con_gestion Whether the type goes through gestión, when
	 *                               the caller knows better than the stored
	 *                               document (type posted with the save).
	 * @return bool
	 */
	public static function permitida( $post_id, $desde, $destino, $user_id, $motivo = '', $con_gestion = null ) {
		$creacion = in_array( $desde, array( '', 'new', 'auto-draft' ), true );
		if ( $creacion ) {
			$desde = 'draft';
		}

		if ( self::siempre_permitida( $post_id, $desde, $destino ) ) {
			return true;
		}

		$es_admin = Documentate_Roles::es_administracion( $user_id );
		if ( $es_admin && self::libre_para_administracion( $creacion, $desde, $destino ) ) {
			return true;
		}

		$reglas = self::reglas_entre( $desde, $destino );
		if ( ! empty( $reglas ) ) {
			return self::alguna_regla_permite( $reglas, $post_id, $user_id, $motivo, $con_gestion );
		}

		if ( 'draft' === $desde && in_array( $destino, array( 'publish', 'private', 'future' ), true ) ) {
			return true;
		}

		return $es_admin && 'en_gestion' !== $desde;
	}

	/**
	 * Changes nobody is refused: same status, trash and untrash, and the one aplicar() is running.
	 *
	 * @param int    $post_id Document ID.
	 * @param string $desde   Stored status (creation already mapped to draft).
	 * @param string $destino Requested status.
	 * @return bool
	 */
	private static function siempre_permitida( $post_id, $desde, $destino ) {
		return $desde === $destino
			|| 'trash' === $desde
			|| 'trash' === $destino
			|| self::es_en_curso( $post_id, $destino );
	}

	/**
	 * Changes administración may always make: creating in any status, publish ↔ archived.
	 *
	 * @param bool   $creacion Whether the document is being created.
	 * @param string $desde    Stored status.
	 * @param string $destino  Requested status.
	 * @return bool
	 */
	private static function libre_para_administracion( $creacion, $desde, $destino ) {
		$publicacion = array( 'publish', 'archived' );

		return $creacion
			|| ( in_array( $desde, $publicacion, true ) && in_array( $destino, $publicacion, true ) );
	}

	/**
	 * Whether any of the rules fits the user, the type and the reason.
	 *
	 * @param array     $reglas      Candidate rules.
	 * @param int       $post_id     Document ID.
	 * @param int       $user_id     User ID.
	 * @param string    $motivo      Reason posted with the change.
	 * @param bool|null $con_gestion Whether the type goes through gestión; null reads the document.
	 * @return bool
	 */
	private static function alguna_regla_permite( array $reglas, $post_id, $user_id, $motivo, $con_gestion = null ) {
		if ( null === $con_gestion ) {
			$con_gestion = Documentate_Documento::con_gestion( $post_id );
		}

		foreach ( $reglas as $regla ) {
			if ( $regla['motivo'] && ! self::motivo_valido( $motivo ) ) {
				continue;
			}
			if ( self::regla_aplicable( $regla, $post_id, $user_id, $con_gestion, false ) ) {
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
	 * @param string $clave   Rule key (enviar_gestion, devolver_area, ...).
	 * @param string $motivo  Reason, required by return rules.
	 * @return true|WP_Error
	 */
	public static function aplicar( $post_id, $clave, $motivo = '' ) {
		$post = Documentate_Documento::post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'documento_invalido', 'El documento no existe.' );
		}

		$disponibles = self::disponibles( $post );
		if ( ! isset( $disponibles[ $clave ] ) ) {
			return new WP_Error( 'transicion_no_disponible', 'Esa acción no está disponible en el estado actual del documento.' );
		}

		$regla = $disponibles[ $clave ];
		$motivo = trim( sanitize_textarea_field( (string) $motivo ) );
		if ( $regla['motivo'] && ! self::motivo_valido( $motivo ) ) {
			return new WP_Error( 'motivo_requerido', 'Para devolver un documento hay que decir por qué.' );
		}

		$devuelto_previo = get_post_meta( $post->ID, Documentate_Documento::META_DEVUELTO, true );
		$evento_id = self::registrar( $post->ID, $regla, $motivo );

		self::$en_curso = array( $post->ID, $regla['destino'], $motivo );
		try {
			$resultado = wp_update_post(
				array(
					'ID' => $post->ID,
					'post_status' => $regla['destino'],
				),
				true
			);
		} finally {
			self::$en_curso = null;
		}

		if ( is_wp_error( $resultado ) || get_post_status( $post->ID ) !== $regla['destino'] ) {
			self::deshacer_registro( $post->ID, $evento_id, $devuelto_previo );

			return is_wp_error( $resultado )
				? $resultado
				: new WP_Error( 'transicion_no_aplicada', 'No se pudo cambiar el estado del documento.' );
		}

		return true;
	}

	/**
	 * Write the "devuelto" mark (or clear it) and record the event of a rule.
	 *
	 * @param int    $post_id Document ID.
	 * @param array  $regla   Rule row.
	 * @param string $motivo  Reason (only meaningful for return rules).
	 * @return int Event comment ID.
	 */
	private static function registrar( $post_id, array $regla, $motivo ) {
		if ( ! $regla['motivo'] ) {
			Documentate_Documento::limpiar_devuelto( $post_id );

			return Documentate_Actividad::registrar_evento( $post_id, $regla['evento'] );
		}

		Documentate_Documento::marcar_devuelto(
			$post_id,
			$motivo,
			'pending' === $regla['desde'] ? 'administracion' : 'gestion',
			'en_gestion' === $regla['destino'] ? 'gestion' : 'area'
		);

		return Documentate_Actividad::registrar_evento( $post_id, $regla['evento'] . ': «' . $motivo . '»', $motivo );
	}

	/**
	 * Remove what registrar() wrote when the status change did not land.
	 *
	 * @param int    $post_id         Document ID.
	 * @param int    $evento_id       Event comment ID.
	 * @param string $devuelto_previo Previous raw "devuelto" meta.
	 * @return void
	 */
	private static function deshacer_registro( $post_id, $evento_id, $devuelto_previo ) {
		if ( $evento_id > 0 ) {
			wp_delete_comment( $evento_id, true );
		}

		if ( '' === (string) $devuelto_previo ) {
			Documentate_Documento::limpiar_devuelto( $post_id );
		} else {
			update_post_meta( $post_id, Documentate_Documento::META_DEVUELTO, wp_slash( $devuelto_previo ) );
		}
	}

	/**
	 * Reason of the transition aplicar() is running on a document, for the notifier.
	 *
	 * @param int $post_id Document ID.
	 * @return string Empty when no transition is in progress for it.
	 */
	public static function motivo_en_curso( $post_id ) {
		if ( null === self::$en_curso || (int) self::$en_curso[0] !== (int) $post_id ) {
			return '';
		}

		return (string) self::$en_curso[2];
	}

	/**
	 * Rule row for a key, from a given status when several share the key.
	 *
	 * @param string $clave Rule key.
	 * @param string $desde Optional stored status to disambiguate.
	 * @return array<string,mixed>|null
	 */
	public static function regla( $clave, $desde = '' ) {
		foreach ( self::reglas() as $regla ) {
			if ( $regla['clave'] === $clave && ( '' === $desde || $regla['desde'] === $desde ) ) {
				return $regla;
			}
		}

		return null;
	}

	/**
	 * Button label of a rule, so the UIs never repeat the table.
	 *
	 * @param string $clave Rule key.
	 * @param string $desde Optional stored status to disambiguate.
	 * @return string Empty for an unknown rule.
	 */
	public static function etiqueta( $clave, $desde = '' ) {
		$regla = self::regla( $clave, $desde );

		return $regla ? (string) $regla['etiqueta'] : '';
	}

	/**
	 * Confirmation text of a rule, so the UIs never repeat the table.
	 *
	 * @param string $clave Rule key.
	 * @return string Empty for an unknown rule or one without confirmation.
	 */
	public static function confirmacion( $clave ) {
		$regla = self::regla( $clave );

		return $regla ? (string) $regla['confirmar'] : '';
	}

	/**
	 * View the application lands on after an action.
	 *
	 * @param string $clave Rule key, or "guardar" for a plain save.
	 * @return string "editar", "detalle" or "bandeja".
	 */
	public static function redireccion( $clave ) {
		$regla = self::regla( $clave );

		return $regla ? (string) $regla['redireccion'] : 'editar';
	}

	/**
	 * Feedback flag the application shows after an action.
	 *
	 * @param string $clave Rule key, or "guardar" for a plain save.
	 * @return string "guardado", "enviado", "devuelto", "aprobado" or empty.
	 */
	public static function bandera( $clave ) {
		$regla = self::regla( $clave );

		return $regla ? (string) $regla['bandera'] : 'guardado';
	}

	/**
	 * Reason posted by the wp-admin management metabox, nonce-verified.
	 *
	 * @return string Empty when absent or when the nonce does not verify.
	 */
	public static function motivo_publicado() {
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
	 * The transition run by aplicar() already wrote everything; any other
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
	public static function registrar_desde_guardado( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( self::es_en_curso( $post->ID, $new_status ) ) {
			return;
		}

		if ( 'auto-draft' === $old_status ) {
			if ( 'draft' === $new_status ) {
				Documentate_Actividad::registrar_evento( $post->ID, 'creó el borrador' );
				return;
			}
			$old_status = 'draft';
		}

		$reglas = self::reglas_entre( $old_status, $new_status );
		if ( empty( $reglas ) ) {
			return;
		}

		self::registrar( $post->ID, $reglas[0], self::motivo_publicado() );
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
	public static function bloquear_papelera( $trash, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $trash;
		}

		if ( Documentate_Workflow::user_can_modify_status( (string) $post->post_status, get_current_user_id() ) ) {
			return $trash;
		}

		return false;
	}
}
