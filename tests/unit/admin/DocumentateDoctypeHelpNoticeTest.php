<?php
/**
 * Tests for Documentate_Doctype_Help_Notice class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Doctype_Help_Notice
 */
class DocumentateDoctypeHelpNoticeTest extends WP_UnitTestCase {

	/**
	 * Notice instance.
	 *
	 * @var Documentate_Doctype_Help_Notice
	 */
	private $notice;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/class-documentate-doctype-help-notice.php';
		$this->notice = new Documentate_Doctype_Help_Notice();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertNotFalse(
			has_action( 'admin_notices', array( $this->notice, 'maybe_print_notice' ) )
		);
	}

	/**
	 * Test maybe_print_notice returns early when not admin.
	 */
	public function test_maybe_print_notice_not_admin() {
		set_current_screen( 'front' );

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_print_notice returns early when wrong screen base.
	 */
	public function test_maybe_print_notice_wrong_screen_base() {
		set_current_screen( 'edit-post' );

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_print_notice returns early when wrong taxonomy.
	 */
	public function test_maybe_print_notice_wrong_taxonomy() {
		// Create a mock screen with wrong taxonomy.
		$screen           = WP_Screen::get( 'edit-tags' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'category';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_print_notice outputs content on correct screen.
	 */
	public function test_maybe_print_notice_correct_screen() {
		// Create a screen for the doctype taxonomy.
		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'documentate-doctype-help', $output );
		$this->assertStringContainsString( 'Plantillas', $output );
	}

	/**
	 * Test maybe_print_notice includes field type information.
	 */
	public function test_maybe_print_notice_includes_field_info() {
		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'text', $output );
		$this->assertStringContainsString( 'textarea', $output );
		$this->assertStringContainsString( 'html', $output );
		$this->assertStringContainsString( 'number', $output );
		$this->assertStringContainsString( 'date', $output );
		$this->assertStringContainsString( 'email', $output );
	}

	/**
	 * Test maybe_print_notice includes validation info.
	 */
	public function test_maybe_print_notice_includes_validation_info() {
		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'pattern', $output );
		$this->assertStringContainsString( 'minvalue', $output );
		$this->assertStringContainsString( 'maxvalue', $output );
	}

	/**
	 * Test maybe_print_notice includes repeater info.
	 */
	public function test_maybe_print_notice_includes_repeater_info() {
		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'block=begin', $output );
		$this->assertStringContainsString( 'block=end', $output );
		$this->assertStringContainsString( 'block=tbs:row', $output );
	}

	/**
	 * Test filter can change target taxonomy.
	 */
	public function test_filter_can_change_taxonomy() {
		add_filter(
			'documentate_doctype_help_notice_taxonomy',
			function () {
				return 'custom_taxonomy';
			}
		);

		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		// Should be empty because taxonomy doesn't match filter.
		$this->assertEmpty( $output );

		remove_all_filters( 'documentate_doctype_help_notice_taxonomy' );
	}

	/**
	 * Test filter can customize notice content.
	 */
	public function test_filter_can_customize_content() {
		add_filter(
			'documentate_doctype_help_notice_html',
			function () {
				return '<p>Custom notice content</p>';
			}
		);

		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom notice content', $output );

		remove_all_filters( 'documentate_doctype_help_notice_html' );
	}

	/**
	 * Test filter can disable notice by returning empty.
	 */
	public function test_filter_can_disable_notice() {
		add_filter(
			'documentate_doctype_help_notice_html',
			function () {
				return '';
			}
		);

		$screen           = WP_Screen::get( 'edit-documentate_doc_type' );
		$screen->base     = 'edit-tags';
		$screen->taxonomy = 'documentate_doc_type';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->notice->maybe_print_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );

		remove_all_filters( 'documentate_doctype_help_notice_html' );
	}
}
