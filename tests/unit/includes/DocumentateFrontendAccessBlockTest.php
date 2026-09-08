<?php
/**
 * Tests for Documentate_Document_Access_Protection::block_frontend_access().
 *
 * Documents are official records: the front end must never serve one to a
 * visitor who cannot edit posts.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Document_Access_Protection
 */
class DocumentateFrontendAccessBlockTest extends Documentate_Test_Base {

	/**
	 * Protection instance under test.
	 *
	 * @var Documentate_Document_Access_Protection
	 */
	private $protection;

	/**
	 * Document being requested.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Build the protection instance and a document to request.
	 */
	public function set_up() {
		parent::set_up();

		$this->protection = new Documentate_Document_Access_Protection();
		$this->post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Front end document',
				'post_status' => 'draft',
			)
		);

		// The default theme may resolve a 404 template, which the handler would
		// include and exit on. Force the wp_die() fallback instead.
		add_filter( '404_template', '__return_empty_string', PHP_INT_MAX );
	}

	/**
	 * Restore the query and the theme template resolution.
	 */
	public function tear_down() {
		remove_filter( '404_template', '__return_empty_string', PHP_INT_MAX );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Make the main query look like a single-document front end request.
	 *
	 * @param int $post_id Document post ID.
	 * @return void
	 */
	private function request_single_document( $post_id ) {
		global $wp_query;

		$wp_query->is_singular = true;
		$wp_query->is_single = true;
		$wp_query->queried_object = get_post( $post_id );
		$wp_query->queried_object_id = $post_id;
	}

	/**
	 * An anonymous visitor gets a 404, not the document.
	 */
	public function test_anonymous_visitors_get_a_404() {
		$this->request_single_document( $this->post_id );

		try {
			$this->protection->block_frontend_access();
			$this->fail( 'The request should have been blocked.' );
		} catch ( WPDieException $exception ) {
			$this->assertStringContainsString( 'No estás autorizado', $exception->getMessage() );
		}

		$this->assertTrue( is_404() );
	}

	/**
	 * Subscribers cannot read documents either: edit_posts is the bar.
	 */
	public function test_subscribers_get_a_404() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->request_single_document( $this->post_id );

		$this->expectException( WPDieException::class );

		$this->protection->block_frontend_access();
	}

	/**
	 * Editors may view the document; the query is left untouched.
	 */
	public function test_editors_are_allowed_through() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->request_single_document( $this->post_id );

		$this->protection->block_frontend_access();

		$this->assertFalse( is_404() );
		$this->assertTrue( $this->protection->user_can_access() );
	}

	/**
	 * Requests that are not for a single document are ignored entirely.
	 */
	public function test_other_requests_are_left_alone() {
		$this->request_single_document(
			self::factory()->post->create( array( 'post_type' => 'post' ) )
		);

		$this->protection->block_frontend_access();

		$this->assertFalse( is_404() );
	}

	/**
	 * Archive-style requests are not single documents and pass through.
	 */
	public function test_non_singular_requests_are_left_alone() {
		global $wp_query;
		$wp_query->is_singular = false;

		$this->protection->block_frontend_access();

		$this->assertFalse( is_404() );
	}
}
