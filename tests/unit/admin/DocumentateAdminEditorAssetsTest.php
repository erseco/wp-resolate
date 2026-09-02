<?php
/**
 * Tests for the editor-screen integrations of Documentate_Admin.
 *
 * Covers the TinyMCE wiring, the attachments meta box assets, the revision
 * diff labels and the collaborative-mode post lock removal.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin
 * @covers Documentate\DocType\SchemaStorage
 */
class DocumentateAdminEditorAssetsTest extends Documentate_Test_Base {

	/**
	 * Admin instance under test.
	 *
	 * @var Documentate_Admin
	 */
	private $admin;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up an administrator on a document edit screen.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->admin = new Documentate_Admin( 'documentate', '1.0.0' );
	}

	/**
	 * Reset screen, request and option state.
	 */
	public function tear_down() {
		unset( $_GET['post'], $_GET['post_type'], $_GET['revision'] );
		delete_option( 'documentate_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Pretend we are on the edit screen of a given post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	private function set_edit_screen( $post_type ) {
		set_current_screen( 'post.php' );
		get_current_screen()->post_type = $post_type;
	}

	/**
	 * Resolve the revision diff labels through the private accessor.
	 *
	 * @return array<string, string> Map of field slug to label.
	 */
	private function revision_field_labels() {
		$method = new ReflectionMethod( Documentate_Admin::class, 'get_revision_field_labels' );
		$method->setAccessible( true );

		return $method->invoke( $this->admin );
	}

	/**
	 * Create a document assigned to a document type.
	 *
	 * @param int $term_id Document type term ID.
	 * @return int Post ID.
	 */
	private function create_document( $term_id = 0 ) {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Editor assets document',
				'post_status' => 'draft',
			)
		);

		if ( $term_id > 0 ) {
			wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );
		}

		return $post_id;
	}

	/**
	 * The table and search/replace TinyMCE plugins are added for documents only.
	 */
	public function test_tinymce_plugins_are_added_on_document_screens() {
		$this->set_edit_screen( 'documentate_document' );

		$plugins = $this->admin->add_tinymce_table_plugin( array( 'existing' => 'keep.js' ) );

		$this->assertArrayHasKey( 'table', $plugins );
		$this->assertArrayHasKey( 'searchreplace', $plugins );
		$this->assertSame( 'keep.js', $plugins['existing'] );
		$this->assertStringContainsString( 'mce/table/plugin', $plugins['table'] );
	}

	/**
	 * Other post types keep their TinyMCE plugin list untouched.
	 */
	public function test_tinymce_plugins_are_not_added_elsewhere() {
		$this->set_edit_screen( 'post' );

		$plugins = $this->admin->add_tinymce_table_plugin( array( 'existing' => 'keep.js' ) );

		$this->assertSame( array( 'existing' => 'keep.js' ), $plugins );
	}

	/**
	 * The front-end application renders the same rich text fields, so it gets
	 * the same TinyMCE plugins and restrictions; other front-end pages do not.
	 */
	public function test_tinymce_wiring_applies_to_the_app_page() {
		( new Documentate_App() )->ensure_page();
		set_current_screen( 'front' );

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( is_admin() );
		$this->assertSame( array( 'existing' => 'keep.js' ), $this->admin->add_tinymce_table_plugin( array( 'existing' => 'keep.js' ) ) );
		$this->assertSame( array( 'existing' => 'value' ), $this->admin->configure_tinymce_table_options( array( 'existing' => 'value' ) ) );

		$this->go_to( Documentate_App_Shell::page_url() );
		$plugins = $this->admin->add_tinymce_table_plugin( array( 'existing' => 'keep.js' ) );
		$init = $this->admin->configure_tinymce_table_options( array( 'existing' => 'value' ) );

		$this->assertArrayHasKey( 'table', $plugins );
		$this->assertArrayHasKey( 'searchreplace', $plugins );
		$this->assertTrue( $init['table_advtab'] );
		$this->assertStringContainsString( 'script', $init['invalid_elements'] );
	}

	/**
	 * The editor is locked down to the tags the ODT/DOCX renderer understands.
	 */
	public function test_tinymce_is_restricted_to_renderable_markup() {
		$this->set_edit_screen( 'documentate_document' );

		$init = $this->admin->configure_tinymce_table_options( array( 'existing' => 'value' ) );

		$this->assertSame( 'value', $init['existing'] );
		$this->assertTrue( $init['table_advtab'] );
		$this->assertTrue( $init['paste_remove_styles'] );
		$this->assertStringContainsString( 'strong/b', $init['valid_elements'] );
		// One list, not two: the filter runs after wp_editor() has passed the
		// editor its own settings, so a second copy here would quietly win and
		// TinyMCE would drop the table sections the editor had just accepted.
		$config = Documentate_Document_Scalar_Field::get_rich_editor_tinymce_config();
		$this->assertSame( $config['valid_elements'], $init['valid_elements'] );
		$this->assertSame( $config['invalid_elements'], $init['invalid_elements'] );
		foreach ( array( 'thead', 'tbody', 'tfoot' ) as $section ) {
			$this->assertStringContainsString( $section, $init['valid_elements'] );
		}
		$this->assertStringContainsString( 'script', $init['invalid_elements'] );
		$this->assertStringNotContainsString( 'iframe|', $init['valid_elements'] );
	}

	/**
	 * TinyMCE settings for other post types are left alone.
	 */
	public function test_tinymce_options_are_untouched_elsewhere() {
		$this->set_edit_screen( 'post' );

		$init = $this->admin->configure_tinymce_table_options( array( 'existing' => 'value' ) );

		$this->assertSame( array( 'existing' => 'value' ), $init );
	}

	/**
	 * The attachments meta box assets load on the document editor.
	 */
	public function test_attachment_assets_are_enqueued_on_document_screens() {
		$this->set_edit_screen( 'documentate_document' );

		$this->admin->enqueue_attachments_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-attachments', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-attachments', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'documentate-attachments', 'data' );
		$this->assertStringContainsString( 'documentateAttachments', (string) $data );
	}

	/**
	 * The attachments assets stay off other screens and post types.
	 *
	 * @dataProvider provide_non_document_editor_contexts
	 *
	 * @param string $hook      Admin page hook.
	 * @param string $post_type Screen post type.
	 */
	public function test_attachment_assets_are_not_enqueued_elsewhere( $hook, $post_type ) {
		wp_dequeue_script( 'documentate-attachments' );
		wp_dequeue_style( 'documentate-attachments' );
		$this->set_edit_screen( $post_type );

		$this->admin->enqueue_attachments_assets( $hook );

		$this->assertFalse( wp_script_is( 'documentate-attachments', 'enqueued' ) );
	}

	/**
	 * Screens that must not load the document editor assets.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_non_document_editor_contexts() {
		return array(
			'wrong hook' => array( 'edit.php', 'documentate_document' ),
			'wrong post type' => array( 'post.php', 'post' ),
		);
	}

	/**
	 * The heartbeat script is deregistered only when collaborative mode is on,
	 * because WordPress would otherwise keep taking post locks.
	 */
	public function test_heartbeat_is_deregistered_in_collaborative_mode() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$this->set_edit_screen( 'documentate_document' );
		wp_register_script( 'heartbeat', 'https://example.org/heartbeat.js', array(), '1.0', true );

		$this->admin->deregister_heartbeat_for_collaborative( 'post.php' );

		$this->assertFalse( wp_script_is( 'heartbeat', 'registered' ) );
	}

	/**
	 * With collaborative mode off the heartbeat keeps running.
	 */
	public function test_heartbeat_is_kept_without_collaborative_mode() {
		$this->set_edit_screen( 'documentate_document' );
		wp_register_script( 'heartbeat', 'https://example.org/heartbeat.js', array(), '1.0', true );

		$this->admin->deregister_heartbeat_for_collaborative( 'post.php' );

		$this->assertTrue( wp_script_is( 'heartbeat', 'registered' ) );

		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * The heartbeat is left alone outside the editor and for other post types.
	 *
	 * @dataProvider provide_non_document_editor_contexts
	 *
	 * @param string $hook      Admin page hook.
	 * @param string $post_type Screen post type.
	 */
	public function test_heartbeat_is_kept_outside_document_editor( $hook, $post_type ) {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$this->set_edit_screen( $post_type );
		wp_register_script( 'heartbeat', 'https://example.org/heartbeat.js', array(), '1.0', true );

		$this->admin->deregister_heartbeat_for_collaborative( $hook );

		$this->assertTrue( wp_script_is( 'heartbeat', 'registered' ) );

		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Editing an existing document in collaborative mode drops its edit lock.
	 */
	public function test_post_lock_is_removed_for_collaborative_documents() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post_id = $this->create_document();
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $this->admin_id );

		$GLOBALS['pagenow'] = 'post.php';
		$_GET['post'] = (string) $post_id;

		$this->admin->remove_post_lock_for_collaborative();

		$this->assertSame( '', get_post_meta( $post_id, '_edit_lock', true ) );
	}

	/**
	 * Documents of other post types keep their lock.
	 */
	public function test_post_lock_is_kept_for_other_post_types() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, '_edit_lock', '123:1' );

		$GLOBALS['pagenow'] = 'post.php';
		$_GET['post'] = (string) $post_id;

		$this->admin->remove_post_lock_for_collaborative();

		$this->assertSame( '123:1', get_post_meta( $post_id, '_edit_lock', true ) );
	}

	/**
	 * On post-new.php the post type comes from the query string.
	 */
	public function test_post_lock_handler_ignores_other_new_post_types() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$GLOBALS['pagenow'] = 'post-new.php';
		unset( $_GET['post'] );
		$_GET['post_type'] = 'page';

		$this->admin->remove_post_lock_for_collaborative();

		$this->assertSame( 'page', $_GET['post_type'], 'The handler must return without side effects.' );
	}

	/**
	 * Without collaborative mode the lock survives.
	 */
	public function test_post_lock_is_kept_without_collaborative_mode() {
		$post_id = $this->create_document();
		update_post_meta( $post_id, '_edit_lock', '123:1' );

		$GLOBALS['pagenow'] = 'post.php';
		$_GET['post'] = (string) $post_id;

		$this->admin->remove_post_lock_for_collaborative();

		$this->assertSame( '123:1', get_post_meta( $post_id, '_edit_lock', true ) );
	}

	/**
	 * Revision diff assets load on the revision screen for our documents.
	 */
	public function test_revision_assets_load_for_document_revisions() {
		$post_id = $this->create_document();
		wp_update_post(
			array(
				'ID' => $post_id,
				'post_content' => 'Second version',
			)
		);
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions );
		$revision = array_shift( $revisions );

		set_current_screen( 'revision.php' );
		$_GET['revision'] = (string) $revision->ID;

		$this->admin->enqueue_revisions_assets( 'revision.php' );

		$this->assertTrue( wp_style_is( 'documentate-revisions', 'enqueued' ) );
	}

	/**
	 * Revisions belonging to other post types are ignored.
	 */
	public function test_revision_assets_skip_other_post_types() {
		wp_dequeue_style( 'documentate-revisions' );
		wp_dequeue_script( 'documentate-revisions' );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		wp_update_post(
			array(
				'ID' => $post_id,
				'post_content' => 'Second version',
			)
		);
		$revisions = wp_get_post_revisions( $post_id );
		$revision = array_shift( $revisions );

		set_current_screen( 'revision.php' );
		$_GET['revision'] = (string) $revision->ID;

		$this->admin->enqueue_revisions_assets( 'revision.php' );

		$this->assertFalse( wp_script_is( 'documentate-revisions', 'enqueued' ) );
	}

	/**
	 * Revision diff labels fall back to the built-in field names.
	 */
	public function test_revision_field_labels_include_built_in_fields() {
		$labels = $this->revision_field_labels();

		$this->assertArrayHasKey( 'post_title', $labels );
		$this->assertArrayHasKey( 'anexos', $labels );
	}

	/**
	 * Schema labels of the edited document override the built-in defaults, for
	 * both scalar fields and repeaters.
	 */
	public function test_revision_field_labels_use_the_document_schema() {
		$post_id = $this->create_document( $this->create_labelled_doc_type() );
		$_GET['post'] = (string) $post_id;

		$labels = $this->revision_field_labels();

		$this->assertSame( 'Motivo de la solicitud', $labels['asunto'] );
		$this->assertSame( 'Documentos adjuntos', $labels['anexos'] );
	}

	/**
	 * The labels of the revision's parent document are used on revision.php,
	 * where there is no `post` query argument.
	 */
	public function test_revision_field_labels_follow_the_revision_parent() {
		$post_id = $this->create_document( $this->create_labelled_doc_type() );
		wp_update_post(
			array(
				'ID' => $post_id,
				'post_content' => 'Second version',
			)
		);
		$revisions = wp_get_post_revisions( $post_id );
		$this->assertNotEmpty( $revisions );
		$revision = array_shift( $revisions );

		unset( $_GET['post'] );
		$_GET['revision'] = (string) $revision->ID;

		$labels = $this->revision_field_labels();

		$this->assertSame( 'Motivo de la solicitud', $labels['asunto'] );
	}

	/**
	 * Schema entries without a declared title keep the built-in label.
	 */
	public function test_revision_field_labels_ignore_untitled_schema_entries() {
		$term = wp_insert_term( 'Untitled Fields Type', 'documentate_doc_type' );
		$storage = new \Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			(int) $term['term_id'],
			array(
				'version' => 2,
				'fields' => array(
					array(
						'slug' => 'asunto',
						'title' => '',
						'type' => 'text',
					),
				),
				'repeaters' => array(),
				'meta' => array(),
			)
		);

		$post_id = $this->create_document( (int) $term['term_id'] );
		$_GET['post'] = (string) $post_id;

		$labels = $this->revision_field_labels();

		$this->assertSame( 'Asunto', $labels['asunto'] );
	}

	/**
	 * Hand-written schemas may carry `label` instead of `title`, and entries
	 * without a slug cannot be mapped at all.
	 */
	public function test_revision_field_labels_accept_legacy_label_entries() {
		$term = wp_insert_term( 'Legacy Labels Type', 'documentate_doc_type' );
		$storage = new \Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			(int) $term['term_id'],
			array(
				'version' => 2,
				'fields' => array(
					array(
						'slug' => 'asunto',
						'label' => 'Asunto heredado',
						'type' => 'text',
					),
					array(
						'label' => 'Sin slug',
						'type' => 'text',
					),
					'not an entry',
				),
				'repeaters' => array(),
				'meta' => array(),
			)
		);

		$post_id = $this->create_document( (int) $term['term_id'] );
		$_GET['post'] = (string) $post_id;

		$labels = $this->revision_field_labels();

		$this->assertSame( 'Asunto heredado', $labels['asunto'] );
		$this->assertNotContains( 'Sin slug', $labels );
	}

	/**
	 * Create a document type whose schema declares its own field labels.
	 *
	 * @return int Term ID.
	 */
	private function create_labelled_doc_type() {
		$term = wp_insert_term( 'Labelled Type', 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];

		$storage = new \Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					array(
						'name' => 'asunto',
						'slug' => 'asunto',
						'title' => 'Motivo de la solicitud',
						'type' => 'text',
					),
				),
				'repeaters' => array(
					array(
						'name' => 'anexos',
						'slug' => 'anexos',
						'title' => 'Documentos adjuntos',
						'fields' => array(),
					),
				),
				'meta' => array(),
			)
		);

		return $term_id;
	}

	/**
	 * Documents without a type contribute no schema labels.
	 */
	public function test_revision_field_labels_without_a_document_type() {
		$post_id = $this->create_document();
		$_GET['post'] = (string) $post_id;

		$labels = $this->revision_field_labels();

		$this->assertSame( 'Asunto', $labels['asunto'] );
	}

	/**
	 * The collaborative status meta box only exists for saved documents with
	 * collaborative editing enabled.
	 */
	public function test_collaborative_status_metabox_requires_a_saved_document() {
		global $wp_meta_boxes;
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );
		$wp_meta_boxes = array();

		$this->admin->register_collaborative_status_metabox( null );

		$this->assertEmpty( $wp_meta_boxes );
	}
}
