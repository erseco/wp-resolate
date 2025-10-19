<?php
/**
 * Tests for the document metadata meta box handling.
 *
 * @package Resolate
 */

use Resolate\Document\Meta\Document_Meta;
use Resolate\Document\Meta\Document_Meta_Box;

/**
 * @group resolate
 */
class ResolateDocumentMetaBoxTest extends Resolate_Test_Base {

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

		do_action( 'add_meta_boxes_resolate_document', $post );

		$this->assertArrayHasKey( 'resolate_document', $wp_meta_boxes, 'El array de meta boxes debe contener la clave del CPT.' );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['resolate_document'], 'El contexto lateral debe existir.' );
		$this->assertArrayHasKey( 'default', $wp_meta_boxes['resolate_document']['side'], 'La prioridad por defecto debe existir.' );
		$this->assertArrayHasKey(
			'resolate_document_meta',
			$wp_meta_boxes['resolate_document']['side']['default'],
			'El metabox de metadatos debe registrarse.'
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
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, 'Asunto previo' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, 'Autor previo' );
		update_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, 'uno, dos' );

		$post = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'value="Documento Demo"', $html, 'El titulo debe mostrarse.' );
		$this->assertStringContainsString( 'name="resolate_document_meta_subject"', $html, 'Debe mostrarse el campo Asunto.' );
		$this->assertStringContainsString( 'value="Asunto previo"', $html, 'El asunto almacenado debe aparecer.' );
		$this->assertStringContainsString( 'value="Autor previo"', $html, 'El autor almacenado debe aparecer.' );
		$this->assertStringContainsString( 'value="uno, dos"', $html, 'Las palabras clave almacenadas deben aparecer.' );
	}

	/**
	 * Verify that saving persists metadata with sanitization applied.
	 */
	public function test_save_updates_metadata_with_sanitization() {
		$post_id = self::factory()->document->create( array() );

		$_POST = array(
			Document_Meta_Box::NONCE_NAME           => wp_create_nonce( Document_Meta_Box::NONCE_ACTION ),
			'resolate_document_meta_subject'        => str_repeat( 'S', 260 ) . "\x07",
			'resolate_document_meta_author'         => " Autor con tab\t",
			'resolate_document_meta_keywords'       => "  uno ,  dos, , tres  \n",
		);

		$this->meta_box->save( $post_id );

		$subject  = get_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, true );
		$author   = get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true );
		$keywords = get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true );

		$this->assertSame( 255, strlen( $subject ), 'El asunto debe truncarse a 255 caracteres.' );
		$this->assertStringNotContainsString( "\x07", $subject, 'El asunto no debe contener caracteres de control.' );
		$this->assertSame( 'Autor con tab', $author, 'El autor debe limpiarse de espacios y controles.' );
		$this->assertSame( 'uno, dos, tres', $keywords, 'Las palabras clave deben normalizarse.' );

		$_POST['resolate_document_meta_keywords'] = str_repeat( 'palabra,', 200 );
		$this->meta_box->save( $post_id );

		$keywords = get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true );
		$this->assertLessThanOrEqual( 512, strlen( $keywords ), 'Las palabras clave no deben superar los 512 caracteres.' );

		$meta = Document_Meta::get( $post_id );
		$this->assertSame( get_the_title( $post_id ), $meta['title'], 'El titulo debe provenir del post.' );
		$this->assertSame( $subject, $meta['subject'], 'El asunto debe recuperarse del meta.' );
		$this->assertSame( 'Autor con tab', $meta['author'], 'El autor debe recuperarse del meta.' );
		$this->assertSame( $keywords, $meta['keywords'], 'Las palabras clave deben recuperarse del meta.' );
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
			'resolate_document_meta_subject'        => 'Nuevo',
			'resolate_document_meta_author'         => 'Nuevo autor',
			'resolate_document_meta_keywords'       => 'dos',
		);

		$this->meta_box->save( $post_id );

		$this->assertSame( 'Original', get_post_meta( $post_id, Document_Meta_Box::META_KEY_SUBJECT, true ), 'El asunto debe permanecer igual.' );
		$this->assertSame( 'Autor original', get_post_meta( $post_id, Document_Meta_Box::META_KEY_AUTHOR, true ), 'El autor debe permanecer igual.' );
		$this->assertSame( 'uno', get_post_meta( $post_id, Document_Meta_Box::META_KEY_KEYWORDS, true ), 'Las palabras clave deben permanecer igual.' );
	}
}
