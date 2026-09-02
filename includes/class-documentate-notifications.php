<?php
/**
 * Email notifications for Documentate document state changes.
 *
 * Sends concise emails to authors when their documents change state, and to
 * administrators when other users' documents move to the pending review state.
 * Per-user opt-out preferences are stored in user meta and exposed in the
 * standard WordPress profile screen.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Notifications
 *
 * Hooks into transition_post_status for the documentate_document CPT and
 * dispatches the appropriate notifications based on user preferences.
 */
class Documentate_Notifications {
	/**
	 * The post type this notifier applies to.
	 *
	 * @var string
	 */
	const POST_TYPE = 'documentate_document';

	/**
	 * User meta key holding the array of disabled notification keys.
	 *
	 * @var string
	 */
	const META_KEY = 'documentate_notifications_disabled';

	/**
	 * Notification key: own document moved to pending review.
	 *
	 * @var string
	 */
	const KEY_AUTHOR_REVIEW = 'author_review';

	/**
	 * Notification key: own document was published.
	 *
	 * @var string
	 */
	const KEY_AUTHOR_PUBLISH = 'author_publish';

	/**
	 * Notification key: own document changed to any other status.
	 *
	 * @var string
	 */
	const KEY_AUTHOR_OTHER = 'author_other';

	/**
	 * Notification key: someone else's document moved to pending review (admins only).
	 *
	 * @var string
	 */
	const KEY_ADMIN_REVIEW = 'admin_review';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'transition_post_status', array( $this, 'maybe_notify' ), 20, 3 );

		add_action( 'show_user_profile', array( $this, 'render_preferences_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_preferences_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_preferences' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_preferences' ) );
	}

	/**
	 * Dispatch notifications when a document changes status.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function maybe_notify( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		// Demo seeding replays a document's whole history through real status
		// changes; this filter lets it suspend the notifier entirely rather than
		// relying on wp_mail() being short-circuited after the fact.
		if ( apply_filters( 'documentate_suspend_notifications', false ) ) {
			return;
		}

		$author_id = (int) $post->post_author;
		$actor_id = get_current_user_id();

		// Notify the author about their own document's state change.
		$author_key = $this->get_author_notification_key( $old_status, $new_status, $author_id === $actor_id );
		if ( $author_key && $author_id > 0 && ! $this->user_disabled( $author_id, $author_key ) ) {
			$this->send_state_change_email( $post, $old_status, $new_status, $author_id, $actor_id );
		}

		// Notify administrators when someone else's document moves to pending review.
		if ( 'pending' === $new_status ) {
			$this->notify_admins_pending_review( $post, $author_id, $actor_id );
		}

		// Notify gestión documental about the documents that reach or leave them.
		$this->notify_gestion( $post, $old_status, $new_status, $actor_id );
	}

	/**
	 * Decide which author-side notification key applies to a status transition.
	 *
	 * @param string $old_status     Old post status.
	 * @param string $new_status     New post status.
	 * @param bool   $actor_is_author Whether the author triggered the change.
	 * @return string|null Notification key or null when no author email applies.
	 */
	private function get_author_notification_key( $old_status, $new_status, $actor_is_author = false ) {
		if ( in_array( $new_status, array( 'auto-draft', 'inherit', 'new' ), true ) ) {
			return null;
		}

		if ( 'pending' === $new_status ) {
			return self::KEY_AUTHOR_REVIEW;
		}

		if ( 'publish' === $new_status ) {
			return self::KEY_AUTHOR_PUBLISH;
		}

		// Sending to gestión is the author's own act; a return from
		// administración to gestión is gestión's business, not the author's.
		if ( 'en_gestion' === $new_status && ( $actor_is_author || 'pending' === $old_status ) ) {
			return null;
		}

		// Skip the initial transition into draft (document creation).
		if ( 'draft' === $new_status && in_array( $old_status, array( 'auto-draft', 'new', '' ), true ) ) {
			return null;
		}

		return self::KEY_AUTHOR_OTHER;
	}

	/**
	 * Send a state-change email to the document author.
	 *
	 * @param WP_Post $post       Post object.
	 * @param string  $old_status Old post status.
	 * @param string  $new_status New post status.
	 * @param int     $author_id  Author user ID.
	 * @param int     $actor_id   ID of the user who triggered the change.
	 * @return void
	 */
	private function send_state_change_email( $post, $old_status, $new_status, $author_id, $actor_id ) {
		$author = get_userdata( $author_id );
		if ( ! $author || empty( $author->user_email ) ) {
			return;
		}

		$reason = $this->get_state_change_reason( $post, $new_status );
		$subject = $this->build_subject( $reason, $post );
		$body = $this->build_body( $post, $old_status, $new_status, $actor_id );

		wp_mail( $author->user_email, $subject, $body );
	}

	/**
	 * Notify all administrators (except the author) that a document is pending approval.
	 *
	 * @param WP_Post $post      Post object.
	 * @param int     $author_id Author user ID.
	 * @param int     $actor_id  ID of the user who triggered the change.
	 * @return void
	 */
	private function notify_admins_pending_review( $post, $author_id, $actor_id ) {
		$admins = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'fields' => array( 'ID', 'user_email', 'display_name' ),
			)
		);

		foreach ( $admins as $admin ) {
			$admin_id = (int) $admin->ID;
			if ( $admin_id === $author_id ) {
				continue;
			}
			if ( $this->user_disabled( $admin_id, self::KEY_ADMIN_REVIEW ) ) {
				continue;
			}
			if ( empty( $admin->user_email ) ) {
				continue;
			}

			$subject = $this->build_subject( 'Pendiente de aprobar', $post );
			$body = $this->build_body( $post, '', 'pending', $actor_id );
			wp_mail( $admin->user_email, $subject, $body );
		}
	}

	/**
	 * Mail gestión documental about the transitions that concern them.
	 *
	 * Draft to en_gestion: a new document waits for them. Pending to
	 * en_gestion: administración returned one with a reason. Pending to
	 * publish of a type that went through gestión: it was approved.
	 *
	 * @param WP_Post $post       Post object.
	 * @param string  $old_status Old post status.
	 * @param string  $new_status New post status.
	 * @param int     $actor_id   ID of the user who triggered the change.
	 * @return void
	 */
	private function notify_gestion( $post, $old_status, $new_status, $actor_id ) {
		$transicion = $old_status . '>' . $new_status;
		$asuntos = array(
			'draft>en_gestion' => 'Nuevo documento en gestión',
			'pending>en_gestion' => 'Devuelto por administración',
			'pending>publish' => 'Documento aprobado',
		);

		if ( ! isset( $asuntos[ $transicion ] ) ) {
			return;
		}

		if ( 'pending>publish' === $transicion && ! Documentate_Documento::con_gestion( $post ) ) {
			return;
		}

		$subject = $this->build_subject( $asuntos[ $transicion ], $post );
		$body = $this->build_body( $post, $old_status, $new_status, $actor_id );

		foreach ( $this->gestion_recipients( $actor_id ) as $email ) {
			wp_mail( $email, $subject, $body );
		}
	}

	/**
	 * Email addresses of every gestión documental user except the actor.
	 *
	 * Selected by capability, not by role: the site owner may grant
	 * CAP_GESTION to any role or user, and only those who also hold
	 * edit_others_posts can actually open the documents.
	 *
	 * @param int $actor_id ID of the user who triggered the change.
	 * @return string[]
	 */
	private function gestion_recipients( $actor_id ) {
		$users = get_users(
			array(
				'capability' => Documentate_Roles::CAP_GESTION,
				'fields' => array( 'ID', 'user_email' ),
			)
		);

		$emails = array();
		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			if ( $user_id === $actor_id || empty( $user->user_email ) ) {
				continue;
			}
			if ( ! user_can( $user_id, Documentate_Roles::CAP_GESTION ) || ! user_can( $user_id, 'edit_others_posts' ) ) {
				continue;
			}
			$emails[] = (string) $user->user_email;
		}

		return $emails;
	}

	/**
	 * Build the email subject: "Documentate · <reason>: <nombre corto>".
	 *
	 * @param string  $reason Short reason describing the change.
	 * @param WP_Post $post   Document.
	 * @return string Final subject line.
	 */
	private function build_subject( $reason, $post ) {
		return sprintf( 'Documentate · %1$s: %2$s', $reason, Documentate_Documento::nombre_corto( $post ) );
	}

	/**
	 * Map a target status to a short, human-readable subject reason.
	 *
	 * @param WP_Post $post       Document.
	 * @param string  $new_status New post status.
	 * @return string
	 */
	private function get_state_change_reason( $post, $new_status ) {
		if ( 'draft' === $new_status && Documentate_Documento::devuelto( $post ) ) {
			return 'Documento devuelto';
		}

		$reasons = array(
			'pending' => 'Documento enviado a revisión',
			'publish' => 'Documento aprobado',
			'en_gestion' => 'Documento enviado a gestión',
			'draft' => 'Documento devuelto a borrador',
			'archived' => 'Documento archivado',
			'trash' => 'Documento enviado a la papelera',
		);

		return $reasons[ $new_status ] ?? 'Cambio de estado del documento';
	}

	/**
	 * Translate an internal post status into a human-readable label.
	 *
	 * @param string $status Post status.
	 * @return string Label, or the raw status if unknown.
	 */
	private function status_label( $status ) {
		$labels = Documentate_Estados::etiquetas() + array(
			'trash' => 'Papelera',
			'auto-draft' => 'Borrador inicial',
			'new' => 'Nuevo',
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Reason of a return, from the transition in progress or the stored mark.
	 *
	 * @param WP_Post $post Document.
	 * @return string Empty when the change is not a return.
	 */
	private function motivo( $post ) {
		$motivo = Documentate_Transiciones::motivo_en_curso( $post->ID );
		if ( '' !== $motivo ) {
			return $motivo;
		}

		$devuelto = Documentate_Documento::devuelto( $post );

		return $devuelto ? $devuelto['motivo'] : '';
	}

	/**
	 * Link to the document: the application when it exists, wp-admin otherwise.
	 *
	 * @param WP_Post $post   Document.
	 * @param bool    $editar Whether to point at the edit view.
	 * @return string
	 */
	private function enlace( $post, $editar ) {
		if ( class_exists( 'Documentate_App_Shell' ) ) {
			$args = array( 'doc' => $post->ID );
			if ( $editar ) {
				$args['vista'] = 'editar';
			}
			$url = Documentate_App_Shell::page_url( $args );
			if ( '' !== $url ) {
				return $url;
			}
		}

		$edit_link = get_edit_post_link( $post->ID, '' );

		return $edit_link ? $edit_link : admin_url( 'post.php?action=edit&post=' . $post->ID );
	}

	/**
	 * Build the brief plain-text email body.
	 *
	 * @param WP_Post $post       Post object.
	 * @param string  $old_status Old post status (may be empty for admin-side mails).
	 * @param string  $new_status New post status.
	 * @param int     $actor_id   ID of the user who triggered the change.
	 * @return string Email body.
	 */
	private function build_body( $post, $old_status, $new_status, $actor_id ) {
		$actor = $actor_id > 0 ? get_userdata( $actor_id ) : null;
		$actor_name = $actor && ! empty( $actor->display_name ) ? $actor->display_name : 'Sistema';
		$es_devolucion = in_array( $new_status, array( 'draft', 'en_gestion' ), true ) && '' !== $old_status;
		$motivo = $es_devolucion ? $this->motivo( $post ) : '';

		$lines = array();
		$lines[] = sprintf( 'Documento: %s', wp_strip_all_tags( (string) $post->post_title ) );

		if ( $old_status ) {
			$lines[] = sprintf( 'Cambio de estado: %1$s → %2$s', $this->status_label( $old_status ), $this->status_label( $new_status ) );
		} else {
			$lines[] = sprintf( 'Estado: %s', $this->status_label( $new_status ) );
		}

		$lines[] = sprintf( 'Realizado por: %s', $actor_name );
		if ( '' !== $motivo ) {
			$lines[] = sprintf( 'Motivo: «%s»', $motivo );
		}
		$lines[] = '';
		$lines[] = sprintf( 'Enlace al documento: %s', $this->enlace( $post, '' !== $motivo ) );

		return implode( "\n", $lines );
	}

	/**
	 * Check whether a user has opted out of a specific notification.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Notification key.
	 * @return bool True if the user disabled this notification.
	 */
	public function user_disabled( $user_id, $key ) {
		$disabled = get_user_meta( (int) $user_id, self::META_KEY, true );
		if ( ! is_array( $disabled ) ) {
			return false;
		}

		return in_array( $key, $disabled, true );
	}

	/**
	 * List of all notification keys exposed to the user.
	 *
	 * @return array<string, string> Map of key => translated label.
	 */
	private function get_notification_options() {
		return array(
			self::KEY_AUTHOR_REVIEW => __( 'Cuando uno de mis documentos se envía a revisión.', 'documentate' ),
			self::KEY_AUTHOR_PUBLISH => __( 'Cuando uno de mis documentos se publica.', 'documentate' ),
			self::KEY_AUTHOR_OTHER => __(
				'Otros cambios de estado de mis documentos (devueltos a borrador, archivados, etc.).',
				'documentate',
			),
			self::KEY_ADMIN_REVIEW => __(
				'Cuando un documento de otra persona pasa a revisión (solo administradores).',
				'documentate',
			),
		);
	}

	/**
	 * Render the notification preferences section on the user profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 * @return void
	 */
	public function render_preferences_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$disabled = get_user_meta( $user->ID, self::META_KEY, true );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		$is_admin_user = user_can( $user->ID, 'manage_options' );
		$options = $this->get_notification_options();

		wp_nonce_field( 'documentate_save_notifications_' . $user->ID, 'documentate_notifications_nonce' );
		?>
		<h2><?php esc_html_e( 'Notificaciones de Documentate', 'documentate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Notificaciones por correo', 'documentate' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">
							<span><?php esc_html_e( 'Notificaciones por correo', 'documentate' ); ?></span>
						</legend>
						<p class="description">
							<?php esc_html_e( 'Selecciona los avisos que quieres recibir por correo electrónico.', 'documentate' ); ?>
						</p>
						<?php foreach ( $options as $key => $label ) : ?>
							<?php
							if ( self::KEY_ADMIN_REVIEW === $key && ! $is_admin_user ) {
								continue;
							}
							?>
							<p>
								<label for="documentate_notify_<?php echo esc_attr( $key ); ?>">
									<input
										type="checkbox"
										id="documentate_notify_<?php echo esc_attr( $key ); ?>"
										name="documentate_notify[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( ! in_array( $key, $disabled, true ) ); ?>
									/>
									<?php echo esc_html( $label ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist notification preferences from the profile form.
	 *
	 * @param int $user_id ID of the user being saved.
	 * @return void
	 */
	public function save_preferences( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if (
			! isset( $_POST['documentate_notifications_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_key( wp_unslash( $_POST['documentate_notifications_nonce'] ) ),
				'documentate_save_notifications_' . $user_id,
			)
		) {
			return;
		}

		$enabled_raw = array();
		if ( isset( $_POST['documentate_notify'] ) && is_array( $_POST['documentate_notify'] ) ) {
			$enabled_raw = map_deep( wp_unslash( $_POST['documentate_notify'] ), 'sanitize_text_field' );
		}

		$enabled = array();
		foreach ( $enabled_raw as $key => $value ) {
			$enabled[ sanitize_key( (string) $key ) ] = ! empty( $value );
		}

		$disabled = array();
		foreach ( array_keys( $this->get_notification_options() ) as $key ) {
			if ( ! empty( $enabled[ $key ] ) ) {
				continue;
			}
			$disabled[] = $key;
		}

		update_user_meta( $user_id, self::META_KEY, $disabled );
	}
}

new Documentate_Notifications();
