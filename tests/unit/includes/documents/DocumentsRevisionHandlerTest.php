<?php
/**
 * Tests for Documents_Revision_Handler class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Revision_Handler;

/**
 * Test class for Documents_Revision_Handler.
 */
class DocumentsRevisionHandlerTest extends WP_UnitTestCase {

	/**
	 * Handler instance.
	 *
	 * @var Documents_Revision_Handler
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new Documents_Revision_Handler();

		// Ensure CPT is registered.
		if ( ! post_type_exists( 'documentate_document' ) ) {
			register_post_type(
				'documentate_document',
				array(
					'public'   => false,
					'supports' => array( 'title', 'revisions' ),
				)
			);
		}
	}

	/**
	 * Test register_hooks adds expected actions.
	 */
	public function test_register_hooks_adds_revision_actions() {
		$this->handler->register_hooks();

		$this->assertIsInt( has_action( 'wp_save_post_revision', array( $this->handler, 'copy_meta_to_revision' ) ) );
		$this->assertIsInt( has_action( 'wp_restore_post_revision', array( $this->handler, 'restore_meta_from_revision' ) ) );
	}

	/**
	 * Test register_hooks adds expected filters.
	 */
	public function test_register_hooks_adds_revision_filters() {
		$this->handler->register_hooks();

		$this->assertIsInt( has_filter( 'wp_revisions_to_keep', array( $this->handler, 'limit_revisions_for_cpt' ) ) );
		$this->assertIsInt( has_filter( 'wp_save_post_revision_post_has_changed', array( $this->handler, 'force_revision_on_meta' ) ) );
		$this->assertIsInt( has_filter( '_wp_post_revision_fields', array( $this->handler, 'add_revision_fields' ) ) );
	}

	/**
	 * Test limit_revisions_for_cpt returns 15 for documentate_document.
	 */
	public function test_limit_revisions_for_cpt_returns_15() {
		$post = (object) array(
			'post_type' => 'documentate_document',
		);

		$result = $this->handler->limit_revisions_for_cpt( 10, $post );
		$this->assertSame( 15, $result );
	}

	/**
	 * Test limit_revisions_for_cpt passes through for other types.
	 */
	public function test_limit_revisions_passes_through_for_others() {
		$post = (object) array(
			'post_type' => 'post',
		);

		$result = $this->handler->limit_revisions_for_cpt( 10, $post );
		$this->assertSame( 10, $result );

		$result = $this->handler->limit_revisions_for_cpt( 5, $post );
		$this->assertSame( 5, $result );
	}

	/**
	 * Test limit_revisions_for_cpt handles null post.
	 */
	public function test_limit_revisions_handles_null_post() {
		$result = $this->handler->limit_revisions_for_cpt( 10, null );
		$this->assertSame( 10, $result );
	}

	/**
	 * Test force_revision_on_meta returns true for documentate_document.
	 */
	public function test_force_revision_on_meta_returns_true() {
		$post = (object) array(
			'post_type' => 'documentate_document',
		);
		$revision = (object) array();

		$result = $this->handler->force_revision_on_meta( false, $revision, $post );
		$this->assertTrue( $result );
	}

	/**
	 * Test force_revision_on_meta passes through for other types.
	 */
	public function test_force_revision_on_meta_passes_through() {
		$post = (object) array(
			'post_type' => 'post',
		);
		$revision = (object) array();

		$result = $this->handler->force_revision_on_meta( false, $revision, $post );
		$this->assertFalse( $result );

		$result = $this->handler->force_revision_on_meta( true, $revision, $post );
		$this->assertTrue( $result );
	}

	/**
	 * Test force_revision_on_meta handles null post.
	 */
	public function test_force_revision_on_meta_handles_null() {
		$result = $this->handler->force_revision_on_meta( true, null, null );
		$this->assertTrue( $result );

		$result = $this->handler->force_revision_on_meta( false, null, null );
		$this->assertFalse( $result );
	}

	/**
	 * Test add_revision_fields returns fields unchanged.
	 */
	public function test_add_revision_fields_returns_fields() {
		$fields = array(
			'post_title'   => 'Title',
			'post_content' => 'Content',
		);
		$post = (object) array( 'ID' => 1 );

		$result = $this->handler->add_revision_fields( $fields, $post );
		$this->assertSame( $fields, $result );
	}

	/**
	 * Test copy_meta_to_revision skips non-document posts.
	 */
	public function test_copy_meta_to_revision_skips_non_documents() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, 'documentate_field_test', 'value' );

		// Create a fake revision.
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		$this->handler->copy_meta_to_revision( $post_id, $revision_id );

		// Meta should NOT be copied since it's not a documentate_document.
		$this->assertEmpty( get_post_meta( $revision_id, 'documentate_field_test', true ) );
	}

	/**
	 * Test copy_meta_to_revision copies meta for documents.
	 */
	public function test_copy_meta_to_revision_copies_meta() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post_id, 'documentate_field_title', 'Test Title' );
		update_post_meta( $post_id, 'documentate_field_description', 'Test Description' );

		// Create a revision.
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		$this->handler->copy_meta_to_revision( $post_id, $revision_id );

		$this->assertSame( 'Test Title', get_post_meta( $revision_id, 'documentate_field_title', true ) );
		$this->assertSame( 'Test Description', get_post_meta( $revision_id, 'documentate_field_description', true ) );
	}

	/**
	 * Test copy_meta_to_revision skips empty values.
	 */
	public function test_copy_meta_to_revision_skips_empty() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post_id, 'documentate_field_empty', '' );
		update_post_meta( $post_id, 'documentate_field_whitespace', '   ' );

		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		$this->handler->copy_meta_to_revision( $post_id, $revision_id );

		// Empty values should not be copied.
		$this->assertEmpty( get_post_meta( $revision_id, 'documentate_field_empty', true ) );
	}

	/**
	 * Test copy_meta_to_revision handles arrays.
	 */
	public function test_copy_meta_to_revision_handles_arrays() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$array_value = array( 'item1', 'item2', 'item3' );
		update_post_meta( $post_id, 'documentate_field_items', $array_value );

		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		$this->handler->copy_meta_to_revision( $post_id, $revision_id );

		$this->assertSame( $array_value, get_post_meta( $revision_id, 'documentate_field_items', true ) );
	}

	/**
	 * Test copy_meta_to_revision skips empty arrays.
	 */
	public function test_copy_meta_to_revision_skips_empty_arrays() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post_id, 'documentate_field_empty_array', array() );

		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		$this->handler->copy_meta_to_revision( $post_id, $revision_id );

		$this->assertEmpty( get_post_meta( $revision_id, 'documentate_field_empty_array', true ) );
	}

	/**
	 * Test restore_meta_from_revision skips non-documents.
	 */
	public function test_restore_meta_from_revision_skips_non_documents() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		// Add meta to revision.
		add_metadata( 'post', $revision_id, 'documentate_field_test', 'revision_value', true );

		$this->handler->restore_meta_from_revision( $post_id, $revision_id );

		// Meta should NOT be restored since it's not a documentate_document.
		$this->assertEmpty( get_post_meta( $post_id, 'documentate_field_test', true ) );
	}

	/**
	 * readable_text() keeps the words and drops the markup.
	 */
	public function test_readable_text_drops_the_markup() {
		$this->assertSame( '', Documents_Revision_Handler::readable_text( '' ) );
		$this->assertSame( 'Hello World', Documents_Revision_Handler::readable_text( '<p>Hello <b>World</b></p>' ) );
	}

	/**
	 * Block elements become line breaks, never more than one blank line.
	 */
	public function test_readable_text_breaks_lines_at_block_elements() {
		$this->assertSame( "Line 1\nLine 2", Documents_Revision_Handler::readable_text( '<p>Line 1</p><p>Line 2</p>' ) );
		$this->assertSame( "Uno\nDos", Documents_Revision_Handler::readable_text( '<ul><li>Uno</li><li>Dos</li></ul>' ) );
		$this->assertSame( "a · b\nc · d", Documents_Revision_Handler::readable_text( '<table><tr><td>a · b</td></tr><tr><td>c · d</td></tr></table>' ) );
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', Documents_Revision_Handler::readable_text( "<p>Line 1</p>\n\n\n\n<p>Line 2</p>" ) );
	}

	/**
	 * Entities read as the characters they stand for; a non-breaking space is a space.
	 */
	public function test_readable_text_decodes_entities() {
		$this->assertSame( '& < > "a"', Documents_Revision_Handler::readable_text( '<p>&amp; &lt; &gt; &quot;a&quot;</p>' ) );
		$this->assertSame( 'Uno Dos', Documents_Revision_Handler::readable_text( 'Uno&nbsp;Dos' ) );
	}

	/**
	 * Test revision_field_value returns empty for invalid revision.
	 */
	public function test_revision_field_value_invalid_revision() {
		$result = $this->handler->revision_field_value( '', null );
		$this->assertSame( '', $result );
	}

	/**
	 * Test revision_field_value with object containing ID.
	 */
	public function test_revision_field_value_with_object_id() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		add_metadata( 'post', $revision_id, 'documentate_field_test', '<p>Test Value</p>', true );

		// Create revision object.
		$revision = (object) array( 'ID' => $revision_id );

		// Set filter name to match field.
		add_filter( '_wp_post_revision_field_documentate_field_test', array( $this->handler, 'revision_field_value' ), 10, 2 );

		$result = apply_filters( '_wp_post_revision_field_documentate_field_test', '', $revision );

		$this->assertStringContainsString( 'Test Value', $result );

		remove_filter( '_wp_post_revision_field_documentate_field_test', array( $this->handler, 'revision_field_value' ), 10 );
	}

	/**
	 * Test revision_field_value with array containing ID.
	 */
	public function test_revision_field_value_with_array_id() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		add_metadata( 'post', $revision_id, 'documentate_field_array', 'Array Value', true );

		add_filter( '_wp_post_revision_field_documentate_field_array', array( $this->handler, 'revision_field_value' ), 10, 2 );

		$result = apply_filters( '_wp_post_revision_field_documentate_field_array', '', array( 'ID' => $revision_id ) );

		$this->assertStringContainsString( 'Array Value', $result );

		remove_filter( '_wp_post_revision_field_documentate_field_array', array( $this->handler, 'revision_field_value' ), 10 );
	}

	/**
	 * Test revision_field_value with numeric ID.
	 */
	public function test_revision_field_value_with_numeric_id() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);

		add_metadata( 'post', $revision_id, 'documentate_field_numeric', 'Numeric Value', true );

		add_filter( '_wp_post_revision_field_documentate_field_numeric', array( $this->handler, 'revision_field_value' ), 10, 2 );

		$result = apply_filters( '_wp_post_revision_field_documentate_field_numeric', '', $revision_id );

		$this->assertStringContainsString( 'Numeric Value', $result );

		remove_filter( '_wp_post_revision_field_documentate_field_numeric', array( $this->handler, 'revision_field_value' ), 10 );
	}

	/**
	 * Test revision_field_value with non-revision post ID returns empty.
	 */
	public function test_revision_field_value_non_revision_returns_empty() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );

		add_filter( '_wp_post_revision_field_documentate_field_post', array( $this->handler, 'revision_field_value' ), 10, 2 );

		// Pass a regular post ID, not a revision.
		$result = apply_filters( '_wp_post_revision_field_documentate_field_post', '', $post_id );

		$this->assertSame( '', $result );

		remove_filter( '_wp_post_revision_field_documentate_field_post', array( $this->handler, 'revision_field_value' ), 10 );
	}

	/**
	 * Test restore_meta_from_revision deletes meta when revision has no value.
	 */
	public function test_restore_meta_from_revision_deletes_empty_meta() {
		// Register taxonomy if not already.
		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post_id, 'documentate_field_to_delete', 'Original Value' );

		$revision_id = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post_id,
			)
		);
		// Don't add meta to revision.

		$this->handler->restore_meta_from_revision( $post_id, $revision_id );

		// Original meta should be deleted since revision has no value.
		$this->assertEmpty( get_post_meta( $post_id, 'documentate_field_to_delete', true ) );
	}

	/**
	 * Test various post type scenarios for limit_revisions.
	 *
	 * @dataProvider revision_limit_provider
	 *
	 * @param string   $post_type Post type.
	 * @param int      $input     Input limit.
	 * @param int      $expected  Expected result.
	 */
	public function test_limit_revisions_various_types( $post_type, $input, $expected ) {
		$post = null;
		if ( $post_type ) {
			$post = (object) array( 'post_type' => $post_type );
		}

		$result = $this->handler->limit_revisions_for_cpt( $input, $post );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for revision limit tests.
	 *
	 * @return array Test cases.
	 */
	public function revision_limit_provider() {
		return array(
			'document_10'    => array( 'documentate_document', 10, 15 ),
			'document_5'     => array( 'documentate_document', 5, 15 ),
			'document_20'    => array( 'documentate_document', 20, 15 ),
			'post_10'        => array( 'post', 10, 10 ),
			'page_10'        => array( 'page', 10, 10 ),
			'null_post'      => array( null, 10, 10 ),
		);
	}
}
