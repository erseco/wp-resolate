<?php
/**
 * Tests for Documentate_Notifications.
 *
 * Verifies that state-change emails are dispatched to the correct recipients
 * and that per-user opt-out preferences are honored.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Notifications
 */
class DocumentateNotificationsTest extends WP_UnitTestCase {

	/**
	 * Notifications instance under test.
	 *
	 * @var Documentate_Notifications
	 */
	protected $notifications;

	/**
	 * Captured email arguments from the pre_wp_mail filter.
	 *
	 * @var array
	 */
	protected $sent_emails = array();

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	protected $author_id;

	/**
	 * Administrator user ID (different from the author).
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Scope category shared by the author, the gestión user and the typed documents.
	 *
	 * @var int
	 */
	protected $cat_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->sent_emails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_email' ), 10, 2 );

		$this->author_id = $this->factory->user->create(
			array(
				'role'         => 'author',
				'user_email'   => 'author@example.com',
				'display_name' => 'Author User',
			)
		);

		$this->admin_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'user_email'   => 'admin@example.com',
				'display_name' => 'Admin User',
			)
		);

		// The transition engine requires edit_post, which the scope filter gates by category.
		$cat = wp_insert_term( 'Área Notificaciones', 'category' );
		$this->cat_id = (int) $cat['term_id'];
		update_user_meta( $this->author_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_id );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-notifications.php';
		$this->notifications = new Documentate_Notifications();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_email' ), 10 );
		parent::tear_down();
	}

	/**
	 * Capture wp_mail() calls without dispatching them.
	 *
	 * @param mixed $return Filter short-circuit value.
	 * @param array $atts   Mail attributes.
	 * @return bool Always true to short-circuit wp_mail.
	 */
	public function capture_email( $return, $atts ) {
		$this->sent_emails[] = $atts;
		return true;
	}

	/**
	 * Create a documentate_document with a doc_type meta so the workflow filter
	 * does not force the post back to draft.
	 *
	 * @param array $args Overrides for wp_insert_post().
	 * @return int Post ID.
	 */
	private function create_document( $args = array() ) {
		$post_id = wp_insert_post(
			array_merge(
				array(
					'post_type'   => 'documentate_document',
					'post_status' => 'draft',
					'post_author' => $this->author_id,
					'post_title'  => 'Test Doc',
				),
				$args
			)
		);
		update_post_meta( $post_id, 'documentate_locked_doc_type', 1 );
		return $post_id;
	}

	/**
	 * Helper: extract every recipient seen across captured emails.
	 *
	 * @return string[] Email addresses.
	 */
	private function get_all_recipients() {
		$recipients = array();
		foreach ( $this->sent_emails as $mail ) {
			$to = is_array( $mail['to'] ) ? $mail['to'] : array( $mail['to'] );
			foreach ( $to as $addr ) {
				$recipients[] = $addr;
			}
		}
		return $recipients;
	}

	/**
	 * Helper: locate the captured email addressed to a specific recipient.
	 *
	 * @param string $email Recipient email.
	 * @return array|null Captured mail attributes or null when not found.
	 */
	private function find_email_to( $email ) {
		foreach ( $this->sent_emails as $mail ) {
			$to = is_array( $mail['to'] ) ? $mail['to'] : array( $mail['to'] );
			if ( in_array( $email, $to, true ) ) {
				return $mail;
			}
		}
		return null;
	}

	/**
	 * Test constructor registers transition_post_status hook.
	 */
	public function test_constructor_registers_transition_hook() {
		$this->assertNotFalse(
			has_action( 'transition_post_status', array( $this->notifications, 'maybe_notify' ) )
		);
	}

	/**
	 * Test constructor registers profile field hooks.
	 */
	public function test_constructor_registers_profile_hooks() {
		$this->assertNotFalse(
			has_action( 'show_user_profile', array( $this->notifications, 'render_preferences_field' ) )
		);
		$this->assertNotFalse(
			has_action( 'edit_user_profile', array( $this->notifications, 'render_preferences_field' ) )
		);
		$this->assertNotFalse(
			has_action( 'personal_options_update', array( $this->notifications, 'save_preferences' ) )
		);
		$this->assertNotFalse(
			has_action( 'edit_user_profile_update', array( $this->notifications, 'save_preferences' ) )
		);
	}

	/**
	 * Test ignores non-documentate post types.
	 */
	public function test_ignores_other_post_types() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_author' => $this->author_id,
				'post_status' => 'draft',
			)
		);
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		$this->assertEmpty( $this->sent_emails, 'Should not send email for non-documentate post types.' );
	}

	/**
	 * Test author receives an email when their document moves to pending review.
	 */
	public function test_author_notified_on_send_to_review() {
		wp_set_current_user( $this->author_id );

		$post_id = $this->create_document( array( 'post_title' => 'My Doc' ) );
		$this->sent_emails = array(); // Reset captures from initial creation.

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		$mail = $this->find_email_to( 'author@example.com' );
		$this->assertNotNull( $mail, 'Author should be notified.' );
		$this->assertStringStartsWith( 'Documentate · ', $mail['subject'] );
		$this->assertStringContainsString( 'revisión', $mail['subject'] );
		$this->assertStringContainsString( 'My Doc', $mail['subject'] );
	}

	/**
	 * Test admin (different from author) is notified when a document goes to pending.
	 */
	public function test_admin_notified_when_other_users_doc_pending() {
		wp_set_current_user( $this->author_id );

		$post_id = $this->create_document( array( 'post_title' => 'Doc for review' ) );
		$this->sent_emails = array();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		$this->assertContains( 'admin@example.com', $this->get_all_recipients() );
		$admin_mail = $this->find_email_to( 'admin@example.com' );
		$this->assertStringContainsString( 'Pendiente de aprobar', $admin_mail['subject'] );
	}

	/**
	 * Test admin is NOT notified about their own document going to pending.
	 *
	 * They still receive the author-side email but only once.
	 */
	public function test_admin_only_gets_author_email_for_own_document() {
		wp_set_current_user( $this->admin_id );

		$post_id = $this->create_document(
			array(
				'post_author' => $this->admin_id,
				'post_title'  => 'Admin Doc',
			)
		);
		$this->sent_emails = array();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		// Admin must not receive the admin-side "Pendiente de revisión" notification
		// for their own document — only the author-side state change email.
		foreach ( $this->sent_emails as $mail ) {
			$to = is_array( $mail['to'] ) ? $mail['to'] : array( $mail['to'] );
			if ( ! in_array( 'admin@example.com', $to, true ) ) {
				continue;
			}
			$this->assertStringNotContainsString(
				'Pendiente de aprobar',
				$mail['subject'],
				'Admin should not receive the admin-side pending review email for their own document.'
			);
		}
	}

	/**
	 * Test author opt-out prevents the author email.
	 */
	public function test_author_opt_out_prevents_email() {
		update_user_meta(
			$this->author_id,
			Documentate_Notifications::META_KEY,
			array( Documentate_Notifications::KEY_AUTHOR_REVIEW )
		);

		wp_set_current_user( $this->author_id );

		$post_id = $this->create_document();
		$this->sent_emails = array();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		$this->assertNull(
			$this->find_email_to( 'author@example.com' ),
			'Opt-out should prevent the author notification.'
		);
	}

	/**
	 * Test admin opt-out prevents the admin pending review email.
	 */
	public function test_admin_opt_out_prevents_admin_email() {
		update_user_meta(
			$this->admin_id,
			Documentate_Notifications::META_KEY,
			array( Documentate_Notifications::KEY_ADMIN_REVIEW )
		);

		wp_set_current_user( $this->author_id );

		$post_id = $this->create_document();
		$this->sent_emails = array();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		$this->assertNull(
			$this->find_email_to( 'admin@example.com' ),
			'Admin opt-out should prevent the pending review notification.'
		);
	}

	/**
	 * Test no email is sent on initial draft creation (auto-draft -> draft).
	 */
	public function test_no_email_on_initial_draft_creation() {
		wp_set_current_user( $this->author_id );

		wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
				'post_author' => $this->author_id,
			)
		);

		$this->assertEmpty(
			$this->sent_emails,
			'Creating a draft should not trigger any notifications.'
		);
	}

	/**
	 * Test author is notified when document moves to publish.
	 */
	public function test_author_notified_on_publish() {
		wp_set_current_user( $this->admin_id );

		$post_id = $this->create_document( array( 'post_title' => 'Pending Doc' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
		$this->sent_emails = array();

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

		$mail = $this->find_email_to( 'author@example.com' );
		$this->assertNotNull( $mail );
		$this->assertStringContainsString( 'aprobado', $mail['subject'] );
	}

	/**
	 * Create a gestión documental user (editor) with an email.
	 *
	 * @return int
	 */
	private function create_management_user() {
		Documentate_Roles::ensure_caps( true );

		$management_id = $this->factory->user->create(
			array(
				'role'         => 'editor',
				'user_email'   => 'gestion@example.com',
				'display_name' => 'Gestión User',
			)
		);
		// Gestión documental is appointed account by account: the plugin keeps
		// the capability in a role of its own and never grants it to the stock
		// editor role, so the account is given it here the way a site would.
		( new WP_User( $management_id ) )->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		update_user_meta( $management_id, Documentate_Scope_Filter::SCOPE_META_KEY, $this->cat_id );

		return $management_id;
	}

	/**
	 * Create a document of a type that goes through gestión.
	 *
	 * @param string $status Post status.
	 * @param string $title  Title.
	 * @return int
	 */
	private function create_document_with_management( $status, $title = 'Doc con gestión' ) {
		Documentate_Statuses::register();
		$type = wp_insert_term( 'Resolución N ' . wp_generate_password( 4, false ), 'documentate_doc_type' );
		update_term_meta( $type['term_id'], 'documentate_type_con_gestion', '1' );

		$current = get_current_user_id();
		wp_set_current_user( $this->admin_id );
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => $status,
				'post_author' => $this->author_id,
				'post_title'  => $title,
				'tax_input'   => array( 'documentate_doc_type' => array( (int) $type['term_id'] ) ),
			)
		);
		update_post_meta( $post_id, 'documentate_locked_doc_type', (int) $type['term_id'] );
		wp_set_object_terms( $post_id, array( $this->cat_id ), 'category' );
		wp_set_current_user( $current );
		$this->sent_emails = array();

		return $post_id;
	}

	/**
	 * Sending to gestión mails every gestión user except the actor; never the author.
	 */
	public function test_send_to_management_mails_management_users_not_the_author() {
		$management_id = $this->create_management_user();
		wp_set_current_user( $this->author_id );
		$post_id = $this->create_document_with_management( 'draft', 'Listado piloto' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'enviar_gestion' ) );

		$mail = $this->find_email_to( 'gestion@example.com' );
		$this->assertNotNull( $mail );
		$this->assertSame( 'Documentate · Nuevo documento en gestión: Listado piloto', $mail['subject'] );
		$this->assertStringContainsString( 'Borrador → En gestión', $mail['message'] );
		$this->assertStringContainsString( 'Realizado por: Author User', $mail['message'] );
		$this->assertNull( $this->find_email_to( 'author@example.com' ), 'The author did it: no mail.' );
		unset( $management_id );
	}

	/**
	 * Gestión recipients are selected by capability, whatever their role.
	 */
	public function test_send_to_management_mails_the_capability_on_any_role() {
		Documentate_Roles::ensure_caps( true );
		$capable = $this->factory->user->create(
			array(
				'role'       => 'author',
				'user_email' => 'capacitado@example.com',
			)
		);
		$user = get_user_by( 'id', $capable );
		$user->add_cap( Documentate_Roles::CAP_MANAGEMENT );
		$user->add_cap( 'edit_others_posts' );

		// The capability alone (without edit_others_posts) is not gestión.
		$half_filled = $this->factory->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'a-medias@example.com',
			)
		);
		get_user_by( 'id', $half_filled )->add_cap( Documentate_Roles::CAP_MANAGEMENT );

		wp_set_current_user( $this->author_id );
		$post_id = $this->create_document_with_management( 'draft', 'Por capacidad' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'enviar_gestion' ) );

		$mail = $this->find_email_to( 'capacitado@example.com' );
		$this->assertNotNull( $mail );
		$this->assertSame( 'Documentate · Nuevo documento en gestión: Por capacidad', $mail['subject'] );
		$this->assertNull( $this->find_email_to( 'a-medias@example.com' ) );
	}

	/**
	 * The actor is skipped, and an author who did not send it is told.
	 */
	public function test_send_to_management_by_someone_else_tells_the_author_and_skips_the_actor() {
		$management_id = $this->create_management_user();
		wp_set_current_user( $management_id );
		$post_id = $this->create_document_with_management( 'draft', 'Enviado por gestión' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'enviar_gestion' ) );

		$this->assertNull( $this->find_email_to( 'gestion@example.com' ), 'The actor is not mailed.' );
		$author = $this->find_email_to( 'author@example.com' );
		$this->assertNotNull( $author );
		$this->assertSame( 'Documentate · Documento enviado a gestión: Enviado por gestión', $author['subject'] );
	}

	/**
	 * A return to draft mails the author with the reason and the edit link.
	 */
	public function test_return_to_draft_mails_the_author_with_the_reason() {
		( new Documentate_App() )->ensure_page();
		wp_set_current_user( $this->admin_id );
		$post_id = $this->create_document_with_management( 'pending', 'Bases plan' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'devolver_area', 'Falta el anexo firmado' ) );

		$mail = $this->find_email_to( 'author@example.com' );
		$this->assertNotNull( $mail );
		$this->assertSame( 'Documentate · Documento devuelto: Bases plan', $mail['subject'] );
		$this->assertStringContainsString( 'Motivo: «Falta el anexo firmado»', $mail['message'] );
		$this->assertStringContainsString( 'vista=editar', $mail['message'] );
		$this->assertStringContainsString( 'En revisión → Borrador', $mail['message'] );
	}

	/**
	 * A return from administración to gestión mails gestión, not the author.
	 */
	public function test_return_to_management_mails_management_not_the_author() {
		$management_id = $this->create_management_user();
		wp_set_current_user( $this->admin_id );
		$post_id = $this->create_document_with_management( 'pending', 'Calendario admisión' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'devolver_gestion', 'Falta el número de expediente' ) );

		$mail = $this->find_email_to( 'gestion@example.com' );
		$this->assertNotNull( $mail );
		$this->assertSame( 'Documentate · Devuelto por administración: Calendario admisión', $mail['subject'] );
		$this->assertStringContainsString( 'Motivo: «Falta el número de expediente»', $mail['message'] );
		$this->assertNull( $this->find_email_to( 'author@example.com' ) );
		unset( $management_id );
	}

	/**
	 * Approval mails gestión only when the type went through gestión.
	 */
	public function test_approval_mails_management_for_management_types_only() {
		$management_id = $this->create_management_user();
		wp_set_current_user( $this->admin_id );

		$has_management = $this->create_document_with_management( 'pending', 'Con gestión' );
		$this->assertTrue( Documentate_Transitions::apply( $has_management, 'aprobar' ) );
		$mail = $this->find_email_to( 'gestion@example.com' );
		$this->assertNotNull( $mail );
		$this->assertSame( 'Documentate · Documento aprobado: Con gestión', $mail['subject'] );
		$this->assertSame( 'Documentate · Documento aprobado: Con gestión', $this->find_email_to( 'author@example.com' )['subject'] );

		$direct = $this->create_document( array( 'post_title' => 'Directo' ) );
		wp_update_post( array( 'ID' => $direct, 'post_status' => 'pending' ) );
		$this->sent_emails = array();
		wp_update_post( array( 'ID' => $direct, 'post_status' => 'publish' ) );
		$this->assertNull( $this->find_email_to( 'gestion@example.com' ) );
		$this->assertNotNull( $this->find_email_to( 'author@example.com' ) );
		unset( $management_id );
	}

	/**
	 * Passing from gestión to administración mails the admins and the author.
	 */
	public function test_pass_to_admin_mails_admins() {
		$management_id = $this->create_management_user();
		wp_set_current_user( $management_id );
		$post_id = $this->create_document_with_management( 'en_gestion', 'Pasado a admin' );

		$this->assertTrue( Documentate_Transitions::apply( $post_id, 'pasar_admin' ) );

		$this->assertSame( 'Documentate · Pendiente de aprobar: Pasado a admin', $this->find_email_to( 'admin@example.com' )['subject'] );
		$this->assertSame( 'Documentate · Documento enviado a revisión: Pasado a admin', $this->find_email_to( 'author@example.com' )['subject'] );
		$this->assertStringContainsString( 'En gestión → En revisión', $this->find_email_to( 'author@example.com' )['message'] );
	}

	/**
	 * Test user_disabled() helper returns false by default and true after opt-out.
	 */
	public function test_user_disabled_helper() {
		$this->assertFalse(
			$this->notifications->user_disabled( $this->author_id, Documentate_Notifications::KEY_AUTHOR_REVIEW )
		);

		update_user_meta(
			$this->author_id,
			Documentate_Notifications::META_KEY,
			array( Documentate_Notifications::KEY_AUTHOR_REVIEW )
		);

		$this->assertTrue(
			$this->notifications->user_disabled( $this->author_id, Documentate_Notifications::KEY_AUTHOR_REVIEW )
		);
	}

	/**
	 * Test save_preferences() stores disabled keys.
	 */
	public function test_save_preferences_stores_disabled_keys() {
		wp_set_current_user( $this->admin_id );

		$_POST['documentate_notifications_nonce'] = wp_create_nonce(
			'documentate_save_notifications_' . $this->author_id
		);
		// Only enable author_publish; the rest should be flagged as disabled.
		$_POST['documentate_notify'] = array( Documentate_Notifications::KEY_AUTHOR_PUBLISH => '1' );

		$this->notifications->save_preferences( $this->author_id );

		$disabled = get_user_meta( $this->author_id, Documentate_Notifications::META_KEY, true );
		$this->assertContains( Documentate_Notifications::KEY_AUTHOR_REVIEW, $disabled );
		$this->assertContains( Documentate_Notifications::KEY_AUTHOR_OTHER, $disabled );
		$this->assertContains( Documentate_Notifications::KEY_ADMIN_REVIEW, $disabled );
		$this->assertNotContains( Documentate_Notifications::KEY_AUTHOR_PUBLISH, $disabled );

		unset( $_POST['documentate_notifications_nonce'], $_POST['documentate_notify'] );
	}

	/**
	 * Test save_preferences() ignores invalid nonce.
	 */
	public function test_save_preferences_requires_valid_nonce() {
		wp_set_current_user( $this->admin_id );
		update_user_meta(
			$this->author_id,
			Documentate_Notifications::META_KEY,
			array( Documentate_Notifications::KEY_AUTHOR_REVIEW )
		);

		$_POST['documentate_notifications_nonce'] = 'invalid-nonce';
		$_POST['documentate_notify']              = array();

		$this->notifications->save_preferences( $this->author_id );

		$disabled = get_user_meta( $this->author_id, Documentate_Notifications::META_KEY, true );
		$this->assertSame(
			array( Documentate_Notifications::KEY_AUTHOR_REVIEW ),
			$disabled,
			'Invalid nonce should not modify stored preferences.'
		);

		unset( $_POST['documentate_notifications_nonce'], $_POST['documentate_notify'] );
	}

	/**
	 * Test render_preferences_field outputs the form.
	 */
	public function test_render_preferences_field_outputs_form() {
		wp_set_current_user( $this->admin_id );
		$user = get_userdata( $this->author_id );

		ob_start();
		$this->notifications->render_preferences_field( $user );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_notifications_nonce', $output );
		$this->assertStringContainsString( 'documentate_notify[' . Documentate_Notifications::KEY_AUTHOR_REVIEW . ']', $output );
		$this->assertStringContainsString( 'documentate_notify[' . Documentate_Notifications::KEY_AUTHOR_PUBLISH . ']', $output );
	}

	/**
	 * Test render_preferences_field hides admin-only option for non-admins.
	 */
	public function test_render_preferences_field_hides_admin_option_for_non_admin() {
		wp_set_current_user( $this->admin_id );
		$user = get_userdata( $this->author_id );

		ob_start();
		$this->notifications->render_preferences_field( $user );
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'documentate_notify[' . Documentate_Notifications::KEY_ADMIN_REVIEW . ']',
			$output
		);
	}
}
