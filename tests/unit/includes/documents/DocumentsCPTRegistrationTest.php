<?php
/**
 * Tests for Documents_CPT_Registration class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_CPT_Registration;

/**
 * Test class for Documents_CPT_Registration.
 */
class DocumentsCPTRegistrationTest extends WP_UnitTestCase {

	/**
	 * Registration instance.
	 *
	 * @var Documents_CPT_Registration
	 */
	private $registration;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->registration = new Documents_CPT_Registration();
	}

	/**
	 * Test register_hooks adds expected actions.
	 */
	public function test_register_hooks_adds_init_actions() {
		$this->registration->register_hooks();

		$this->assertIsInt( has_action( 'init', array( $this->registration, 'register_post_type' ) ) );
		$this->assertIsInt( has_action( 'init', array( $this->registration, 'register_taxonomies' ) ) );
	}

	/**
	 * Test register_hooks adds block editor filter.
	 */
	public function test_register_hooks_adds_gutenberg_filter() {
		$this->registration->register_hooks();

		$this->assertIsInt( has_filter( 'use_block_editor_for_post_type', array( $this->registration, 'disable_gutenberg' ) ) );
	}

	/**
	 * Test register_hooks adds default comment status filter.
	 */
	public function test_register_hooks_adds_default_comment_status_filter() {
		$this->registration->register_hooks();

		$this->assertIsInt( has_filter( 'get_default_comment_status', array( $this->registration, 'set_default_comment_status_open' ) ) );
	}

	/**
	 * Managing document types stays restricted to administrators.
	 *
	 * A document type defines the template and fields every document of that
	 * type renders from, so editing one rewrites existing documents. Without an
	 * explicit capability map WordPress would fall back to manage_categories,
	 * which editors hold.
	 */
	public function test_doc_type_terms_are_administrator_only() {
		$this->registration->register_taxonomies();

		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		$this->assertSame( 'manage_options', $taxonomy->cap->manage_terms );
		$this->assertSame( 'manage_options', $taxonomy->cap->edit_terms );
		$this->assertSame( 'manage_options', $taxonomy->cap->delete_terms );
	}

	/**
	 * Assigning an existing type is not restricted the same way, because that is
	 * what writing a document does.
	 */
	public function test_doc_type_can_be_assigned_by_authors() {
		$this->registration->register_taxonomies();

		$this->assertSame( 'edit_posts', get_taxonomy( 'documentate_doc_type' )->cap->assign_terms );
	}

	/**
	 * Test register_post_type registers CPT.
	 */
	public function test_register_post_type_creates_cpt() {
		$this->registration->register_post_type();

		$this->assertTrue( post_type_exists( 'documentate_document' ) );
	}

	/**
	 * Test CPT has correct labels.
	 */
	public function test_cpt_has_correct_labels() {
		$this->registration->register_post_type();
		$post_type = get_post_type_object( 'documentate_document' );

		$this->assertSame( 'Documentos', $post_type->labels->name );
		$this->assertSame( 'Documento', $post_type->labels->singular_name );
		$this->assertSame( 'Añadir nuevo documento', $post_type->labels->add_new_item );
	}

	/**
	 * Test CPT supports expected features.
	 */
	public function test_cpt_supports_features() {
		$this->registration->register_post_type();

		$this->assertTrue( post_type_supports( 'documentate_document', 'title' ) );
		$this->assertTrue( post_type_supports( 'documentate_document', 'revisions' ) );
		$this->assertTrue( post_type_supports( 'documentate_document', 'comments' ) );
		// Note: editor support check removed as CPT may be pre-registered by other tests.
		// The actual class does NOT add editor support (only title, revisions, comments).
	}

	/**
	 * Test CPT is not public but has UI.
	 */
	public function test_cpt_visibility_settings() {
		$this->registration->register_post_type();
		$post_type = get_post_type_object( 'documentate_document' );

		$this->assertFalse( $post_type->public );
		$this->assertTrue( $post_type->show_ui );
		$this->assertTrue( $post_type->show_in_menu );
	}

	/**
	 * Test CPT has correct menu icon.
	 */
	public function test_cpt_menu_icon() {
		$this->registration->register_post_type();
		$post_type = get_post_type_object( 'documentate_document' );

		$this->assertSame( 'dashicons-media-document', $post_type->menu_icon );
	}

	/**
	 * Test CPT is not in REST API.
	 */
	public function test_cpt_not_in_rest() {
		$this->registration->register_post_type();
		$post_type = get_post_type_object( 'documentate_document' );

		$this->assertFalse( $post_type->show_in_rest );
	}

	/**
	 * Test register_taxonomies creates taxonomy.
	 */
	public function test_register_taxonomies_creates_doc_type() {
		$this->registration->register_taxonomies();

		$this->assertTrue( taxonomy_exists( 'documentate_doc_type' ) );
	}

	/**
	 * Test taxonomy has correct labels.
	 */
	public function test_taxonomy_has_correct_labels() {
		$this->registration->register_taxonomies();
		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		$this->assertSame( 'Tipos de documento', $taxonomy->labels->name );
		$this->assertSame( 'Tipo de documento', $taxonomy->labels->singular_name );
		$this->assertSame( 'Añadir nuevo tipo', $taxonomy->labels->add_new_item );
	}

	/**
	 * Test taxonomy is not hierarchical.
	 */
	public function test_taxonomy_not_hierarchical() {
		$this->registration->register_taxonomies();
		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		$this->assertFalse( $taxonomy->hierarchical );
	}

	/**
	 * Test taxonomy has admin column.
	 */
	public function test_taxonomy_has_admin_column() {
		$this->registration->register_taxonomies();
		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		$this->assertTrue( $taxonomy->show_admin_column );
	}

	/**
	 * Test taxonomy is associated with CPT.
	 */
	public function test_taxonomy_associated_with_cpt() {
		$this->registration->register_post_type();
		$this->registration->register_taxonomies();
		$taxonomy = get_taxonomy( 'documentate_doc_type' );

		$this->assertContains( 'documentate_document', $taxonomy->object_type );
	}

	/**
	 * Test disable_gutenberg returns false for documentate_document.
	 */
	public function test_disable_gutenberg_for_cpt() {
		$result = $this->registration->disable_gutenberg( true, 'documentate_document' );
		$this->assertFalse( $result );
	}

	/**
	 * Test disable_gutenberg passes through for other post types.
	 */
	public function test_disable_gutenberg_passes_through_for_others() {
		$result = $this->registration->disable_gutenberg( true, 'post' );
		$this->assertTrue( $result );

		$result = $this->registration->disable_gutenberg( false, 'page' );
		$this->assertFalse( $result );
	}

	/**
	 * Test disable_gutenberg with various post types.
	 *
	 * @dataProvider post_type_provider
	 *
	 * @param string $post_type     Post type to test.
	 * @param bool   $input         Input value.
	 * @param bool   $expected      Expected result.
	 */
	public function test_disable_gutenberg_various_types( $post_type, $input, $expected ) {
		$result = $this->registration->disable_gutenberg( $input, $post_type );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for post type tests.
	 *
	 * @return array Test cases.
	 */
	public function post_type_provider() {
		return array(
			'cpt_with_true'    => array( 'documentate_document', true, false ),
			'cpt_with_false'   => array( 'documentate_document', false, false ),
			'post_with_true'   => array( 'post', true, true ),
			'post_with_false'  => array( 'post', false, false ),
			'page_with_true'   => array( 'page', true, true ),
			'custom_with_true' => array( 'my_custom_type', true, true ),
		);
	}

	/**
	 * Test CPT registers category taxonomy.
	 */
	public function test_cpt_registers_category_taxonomy() {
		$this->registration->register_post_type();

		$taxonomies = get_object_taxonomies( 'documentate_document' );
		$this->assertContains( 'category', $taxonomies );
	}

	/**
	 * Test set_default_comment_status_open returns open for documentate_document.
	 */
	public function test_default_comment_status_open_for_cpt() {
		$result = $this->registration->set_default_comment_status_open( 'closed', 'documentate_document', 'comment' );
		$this->assertSame( 'open', $result );
	}

	/**
	 * Test set_default_comment_status_open passes through for other post types.
	 */
	public function test_default_comment_status_unchanged_for_other_post_types() {
		$result = $this->registration->set_default_comment_status_open( 'closed', 'post', 'comment' );
		$this->assertSame( 'closed', $result );

		$result = $this->registration->set_default_comment_status_open( 'open', 'page', 'comment' );
		$this->assertSame( 'open', $result );
	}
}
