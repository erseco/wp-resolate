<?php
/**
 * Tests for the document metadata meta box handling.
 *
 * @package Documentate
 */

use Documentate\Document\Meta\Document_Meta;
use Documentate\Document\Meta\Document_Meta_Box;

/**
 * @group documentate
 */
class DocumentateDocumentMetaBoxTest extends Documentate_Test_Base {

	/**
	 * Meta box handler instance.
	 *
	 * @var Document_Meta_Box
	 */
	protected $meta_box;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->meta_box = new Document_Meta_Box();

		do_action( 'init' );
	}

	/**
	 * Clean up global state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Ensure the meta box is registered when the add_meta_boxes hook fires.
	 */
	public function test_metabox_registers_on_hook() {
		global $wp_meta_boxes;

		$wp_meta_boxes = array();

		$post_id = self::factory()->document->create( array() );
		$post    = get_post( $post_id );

		do_action( 'add_meta_boxes_documentate_document', $post );

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes, 'Meta boxes array must contain the CPT key.' );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'], 'Side context must exist.' );
		$this->assertArrayHasKey( 'default', $wp_meta_boxes['documentate_document']['side'], 'Default priority must exist.' );
		$this->assertArrayHasKey(
			'documentate_document_meta',
			$wp_meta_boxes['documentate_document']['side']['default'],
			'Metadata metabox must be registered.'
		);
	}

	/**
	 * Ensure the render method outputs the expected fields and values.
	 */
	public function test_render_outputs_expected_fields() {
		$post_id = self::factory()->document->create(
			array(
				'post_title' => 'Documento Demo',
			)
		);
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Documento Demo',
			)
		);
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, 'Asunto previo' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Autor previo' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, 'uno, dos' );

		$post = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Documento Demo', $html, 'Title must be displayed as text.' );
		$this->assertStringContainsString( 'El asunto se obtiene del título del artículo.', $html, 'Subject help text must be displayed.' );
		$this->assertStringNotContainsString( 'name="documentate_document_meta_subject"', $html, 'Subject field must not be rendered as input.' );
		$this->assertStringContainsString( 'id="documentate_document_meta_author" name="documentate_document_meta_author" class="widefat"', $html, 'Author field must span full width.' );
		$this->assertStringContainsString( 'id="documentate_document_meta_keywords" name="documentate_document_meta_keywords" class="widefat"', $html, 'Keywords field must span full width.' );
		$this->assertStringContainsString( 'value="Autor previo"', $html, 'Stored author must appear.' );
		$this->assertStringContainsString( 'value="uno, dos"', $html, 'Stored keywords must appear.' );
	}

	/**
	 * Verify that saving persists metadata with sanitization applied.
	 */
	public function test_save_updates_metadata_with_sanitization() {
		$long_title = str_repeat( 'S', 260 ) . "\x07";
		$post_id    = self::factory()->document->create(
			array(
				'post_title' => $long_title,
			)
		);
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $long_title,
			)
		);

		$_POST = array(
			Document_Meta_Box::NONCE_NAME     => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
			'documentate_document_meta_author'   => " Autor con tab\t",
			'documentate_document_meta_keywords' => "  uno ,  dos, , tres  \n",
		);

		$this->meta_box->save( $post_id );

		$subject  = get_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, true );
		$author   = get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true );
		$keywords = get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true );

		$this->assertSame( 255, strlen( $subject ), 'Subject must be truncated to 255 characters.' );
		$this->assertStringNotContainsString( "\x07", $subject, 'Subject must not contain control characters.' );
		$this->assertSame( 'Autor con tab', $author, 'Author must be cleaned of spaces and control chars.' );
		$this->assertSame( 'uno, dos, tres', $keywords, 'Keywords must be normalized.' );

		$_POST['documentate_document_meta_keywords'] = str_repeat( 'palabra,', 200 );
		$this->meta_box->save( $post_id );

		$keywords = get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true );
		$this->assertLessThanOrEqual( 512, strlen( $keywords ), 'Keywords must not exceed 512 characters.' );

		$meta = Document_Meta::get( $post_id );
		$this->assertSame( get_the_title( $post_id ), $meta['title'], 'Title must come from the post.' );
		$this->assertSame( $subject, $meta['subject'], 'Subject must be derived from post title.' );
		$this->assertSame( 'Autor con tab', $meta['author'], 'Author must be retrieved from meta.' );
		$this->assertSame( $keywords, $meta['keywords'], 'Keywords must be retrieved from meta.' );
	}

	/**
	 * Verify that Document_Meta::get returns empty values for invalid post_id.
	 */
	public function test_document_meta_get_returns_empty_for_invalid_id() {
		$meta = Document_Meta::get( 0 );

		$this->assertSame( '', $meta['title'], 'Title must be empty for invalid post_id.' );
		$this->assertSame( '', $meta['subject'], 'Subject must be empty for invalid post_id.' );
		$this->assertSame( '', $meta['author'], 'Author must be empty for invalid post_id.' );
		$this->assertSame( '', $meta['keywords'], 'Keywords must be empty for invalid post_id.' );
	}

	/**
	 * Verify that Document_Meta::get returns empty values for negative post_id.
	 */
	public function test_document_meta_get_handles_negative_id() {
		$meta = Document_Meta::get( -1 );

		$this->assertSame( '', $meta['title'], 'Title must be empty for negative post_id.' );
		$this->assertSame( '', $meta['subject'], 'Subject must be empty for negative post_id.' );
		$this->assertSame( '', $meta['author'], 'Author must be empty for negative post_id.' );
		$this->assertSame( '', $meta['keywords'], 'Keywords must be empty for negative post_id.' );
	}

	/**
	 * Verify that invalid requests do not change stored metadata.
	 */
	public function test_save_bails_on_invalid_nonce() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, 'Original' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Autor original' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, 'uno' );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME           => 'invalid',
			'documentate_document_meta_subject'        => 'Nuevo',
			'documentate_document_meta_author'         => 'Nuevo autor',
			'documentate_document_meta_keywords'       => 'dos',
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( 'Original', get_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, true ), 'Subject must remain the same.' );
		$this->assertSame( 'Autor original', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Author must remain the same.' );
		$this->assertSame( 'uno', get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true ), 'Keywords must remain the same.' );
	}

	/**
	 * Verify that save bails on post revision.
	 */
	public function test_save_bails_on_revision() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Original Author' );

		// Create a revision.
		$revision_id = wp_save_post_revision( $post_id );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME        => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
			'documentate_document_meta_author'   => 'New Author',
		);

		$this->meta_box->save( $revision_id );

		// The revision shouldn't have the new author meta set.
		$this->assertSame( '', get_post_meta( $revision_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Revision should not have author meta updated.' );
	}

	/**
	 * Verify that save bails when user lacks permission.
	 */
	public function test_save_bails_without_permission() {
		// Create the post as admin first.
		$post_id = self::factory()->document->create( array( 'post_author' => $this->admin_id ) );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Original Author' );

		// Now switch to subscriber who shouldn't be able to edit.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME        => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
			'documentate_document_meta_author'   => 'New Author',
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( 'Original Author', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Author must remain unchanged when user lacks permission.' );
	}

	/**
	 * Verify that save handles missing nonce.
	 */
	public function test_save_bails_on_missing_nonce() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Original Author' );

		$_POST = array(
			'documentate_document_meta_author' => 'New Author',
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( 'Original Author', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Author must remain unchanged without nonce.' );
	}

	/**
	 * Verify that save deletes empty meta values.
	 */
	public function test_save_deletes_empty_meta_values() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Author Value' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, 'keyword1, keyword2' );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME          => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
			'documentate_document_meta_author'     => '',
			'documentate_document_meta_keywords'   => '',
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Author meta must be deleted when empty.' );
		$this->assertSame( '', get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true ), 'Keywords meta must be deleted when empty.' );
	}

	/**
	 * Verify that save handles missing author and keywords fields.
	 */
	public function test_save_handles_missing_fields() {
		$post_id = self::factory()->document->create( array() );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'Author must be empty when field is missing.' );
		$this->assertSame( '', get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true ), 'Keywords must be empty when field is missing.' );
	}

	/**
	 * Verify that render outputs title when post_title is empty.
	 */
	public function test_render_handles_empty_title() {
		$post_id = self::factory()->document->create( array() );

		// Use reflection to set post_title to empty.
		$post = get_post( $post_id );
		$post->post_title = '';

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Título', $html, 'Title label must be displayed.' );
	}

	/**
	 * Verify the register method adds the correct hooks.
	 */
	public function test_register_adds_hooks() {
		$meta_box = new Document_Meta_Box();
		$meta_box->register();

		$this->assertNotFalse(
			has_action( 'add_meta_boxes_documentate_document', array( $meta_box, 'register_meta_box' ) ),
			'Meta box registration hook must be added.'
		);
		$this->assertNotFalse(
			has_action( 'save_post_documentate_document', array( $meta_box, 'save' ) ),
			'Save hook must be added.'
		);
	}

}
