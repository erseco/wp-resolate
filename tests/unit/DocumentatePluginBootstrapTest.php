<?php
/**
 * Tests for the lifecycle functions defined in documentate.php.
 *
 * @package Documentate
 */

/**
 * @covers ::documentate_activate_plugin
 * @covers ::documentate_deactivate_plugin
 * @covers ::documentate_update_handler
 * @covers ::documentate_maybe_flush_rewrite_rules
 * @covers ::documentate_run_plugin
 */
class DocumentatePluginBootstrapTest extends WP_UnitTestCase {

	/**
	 * The real rewrite object, restored on tear down.
	 *
	 * @var WP_Rewrite
	 */
	private $original_rewrite;

	/**
	 * Rewrite stand-in that counts flushes instead of rebuilding the rules.
	 *
	 * @var WP_Rewrite
	 */
	private $rewrite_spy;

	/**
	 * Swap the global rewrite object for a counting stand-in.
	 *
	 * flush_rewrite_rules() is the only observable effect of several of these
	 * functions, and the real implementation regenerates nothing while the test
	 * site runs on plain permalinks.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_rewrite = $GLOBALS['wp_rewrite'];
		$this->rewrite_spy = new class() extends WP_Rewrite {
			/**
			 * Number of flushes requested.
			 *
			 * @var int
			 */
			public $documentate_flushes = 0;

			/**
			 * Count the flush instead of rebuilding the rewrite rules.
			 *
			 * @param bool $hard Whether to update .htaccess too.
			 * @return void
			 */
			public function flush_rules( $hard = true ) {
				unset( $hard );
				++$this->documentate_flushes;
			}
		};
		$GLOBALS['wp_rewrite'] = $this->rewrite_spy;
	}

	/**
	 * Restore the rewrite object and the lifecycle options.
	 */
	public function tear_down() {
		$GLOBALS['wp_rewrite'] = $this->original_rewrite;
		delete_option( 'documentate_flush_rewrites' );
		delete_option( 'documentate_seed_demo_documents' );
		delete_option( 'documentate_version' );
		parent::tear_down();
	}

	/**
	 * Number of rewrite flushes requested so far.
	 *
	 * @return int
	 */
	private function flush_count() {
		return $this->rewrite_spy->documentate_flushes;
	}

	/**
	 * Activation forces the pretty permalink structure the CPT needs.
	 */
	public function test_activation_sets_postname_permalink_structure() {
		update_option( 'permalink_structure', '' );

		documentate_activate_plugin();

		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
	}

	/**
	 * Activation leaves an already correct permalink structure untouched.
	 */
	public function test_activation_keeps_existing_postname_permalink_structure() {
		update_option( 'permalink_structure', '/%postname%/' );
		$before = get_num_queries();

		documentate_activate_plugin();

		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
		$this->assertGreaterThan( $before, get_num_queries() );
	}

	/**
	 * Activation records the version and asks init to flush the rewrite rules.
	 */
	public function test_activation_records_version_and_flush_flag() {
		delete_option( 'documentate_version' );
		delete_option( 'documentate_flush_rewrites' );

		documentate_activate_plugin();

		$this->assertTrue( (bool) get_option( 'documentate_flush_rewrites' ) );
		$this->assertSame( DOCUMENTATE_VERSION, get_option( 'documentate_version' ) );
		$this->assertSame( 1, $this->flush_count() );
	}

	/**
	 * Outside production, activation requests the demo document seeding.
	 */
	public function test_activation_requests_demo_seeding_outside_production() {
		delete_option( 'documentate_seed_demo_documents' );

		$this->assertTrue(
			Documentate_Demo_Data::should_allow_demo_seeding(),
			'The test environment must allow demo seeding for this assertion to mean anything.'
		);

		documentate_activate_plugin();

		$this->assertTrue( (bool) get_option( 'documentate_seed_demo_documents' ) );
	}

	/**
	 * Activation imports the bundled ODT templates into the Media Library.
	 */
	public function test_activation_imports_default_templates() {
		documentate_activate_plugin();

		$attachments = get_posts(
			array(
				'post_type' => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => -1,
				'fields' => 'ids',
				'meta_key' => '_documentate_fixture_hash',
				'meta_compare' => 'EXISTS',
			)
		);

		$this->assertNotEmpty( $attachments, 'Activation must import the bundled fixture templates.' );
	}

	/**
	 * Deactivation flushes the rewrite rules so the CPT routes disappear.
	 */
	public function test_deactivation_flushes_rewrite_rules() {
		documentate_deactivate_plugin();

		$this->assertSame( 1, $this->flush_count() );
	}

	/**
	 * A plugin update that includes this plugin triggers a rewrite flush.
	 */
	public function test_update_handler_flushes_for_this_plugin() {
		documentate_update_handler(
			null,
			array(
				'action' => 'update',
				'type' => 'plugin',
				'plugins' => array( 'hello-dolly/hello.php', plugin_basename( DOCUMENTATE_PLUGIN_FILE ) ),
			)
		);

		$this->assertSame( 1, $this->flush_count() );
	}

	/**
	 * Updating an unrelated plugin must not flush the rewrite rules.
	 */
	public function test_update_handler_ignores_other_plugins() {
		documentate_update_handler(
			null,
			array(
				'action' => 'update',
				'type' => 'plugin',
				'plugins' => array( 'hello-dolly/hello.php' ),
			)
		);

		$this->assertSame( 0, $this->flush_count() );
	}

	/**
	 * Theme updates and plugin installs are ignored by the update handler.
	 *
	 * @dataProvider provide_non_plugin_update_options
	 *
	 * @param array $options Upgrader options.
	 */
	public function test_update_handler_ignores_non_plugin_updates( array $options ) {
		documentate_update_handler( null, $options );

		$this->assertSame( 0, $this->flush_count() );
	}

	/**
	 * Upgrader payloads that must not trigger a flush.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_non_plugin_update_options() {
		return array(
			'theme update' => array(
				array(
					'action' => 'update',
					'type' => 'theme',
				),
			),
			'plugin install' => array(
				array(
					'action' => 'install',
					'type' => 'plugin',
				),
			),
		);
	}

	/**
	 * A version mismatch flushes the rules and stores the new version.
	 */
	public function test_maybe_flush_rewrite_rules_on_version_change() {
		update_option( 'documentate_version', '0.0.0-old' );
		delete_option( 'documentate_flush_rewrites' );

		documentate_maybe_flush_rewrite_rules();

		$this->assertSame( DOCUMENTATE_VERSION, get_option( 'documentate_version' ) );
		$this->assertSame( 1, $this->flush_count() );
	}

	/**
	 * The activation flag flushes once and is then cleared.
	 */
	public function test_maybe_flush_rewrite_rules_consumes_the_flush_flag() {
		update_option( 'documentate_version', DOCUMENTATE_VERSION );
		update_option( 'documentate_flush_rewrites', true );

		documentate_maybe_flush_rewrite_rules();

		$this->assertFalse( get_option( 'documentate_flush_rewrites' ) );
		$this->assertSame( 1, $this->flush_count() );
	}

	/**
	 * With the version current and no flag set, nothing is flushed.
	 */
	public function test_maybe_flush_rewrite_rules_is_a_no_op_when_up_to_date() {
		update_option( 'documentate_version', DOCUMENTATE_VERSION );
		delete_option( 'documentate_flush_rewrites' );

		documentate_maybe_flush_rewrite_rules();

		$this->assertSame( 0, $this->flush_count() );
	}

	/**
	 * The bootstrap wires the core plugin class into WordPress.
	 */
	public function test_run_plugin_registers_the_core_hooks() {
		$links_filter = 'plugin_action_links_' . plugin_basename( DOCUMENTATE_PLUGIN_FILE );
		remove_all_filters( $links_filter );
		remove_all_actions( 'wp_ajax_documentate_get_collab_avatars' );

		$this->assertFalse( has_filter( $links_filter ) );

		documentate_run_plugin();

		$this->assertNotFalse( has_filter( $links_filter ) );
		$this->assertFalse( has_action( 'wp_ajax_documentate_get_collab_avatars' ) );
	}
}
