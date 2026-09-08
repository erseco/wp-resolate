<?php
/**
 * Tests for Documentate_Demo_Reset: what a development site keeps.
 *
 * @package Documentate
 */

/**
 * Class DocumentateDemoResetTest
 *
 * @covers Documentate_Demo_Reset
 */
class DocumentateDemoResetTest extends WP_UnitTestCase {

	/**
	 * Register the custom statuses the demo content uses.
	 */
	public function set_up(): void {
		parent::set_up();
		( new Documentate_Workflow() )->register_custom_statuses();
	}

	/**
	 * A document with no demo mark, as a spec leaves it behind.
	 *
	 * @param string $title Post title.
	 * @return int Post ID.
	 */
	private function leftover_document( $title ) {
		return self::factory()->post->create(
			array(
				'post_type' => 'documentate_document',
				'post_title' => $title,
			)
		);
	}

	/**
	 * Documents still in the database.
	 *
	 * @return int[]
	 */
	private function remaining_documents() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'documentate_document' )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The leftovers go and the demo content stays.
	 */
	public function test_it_removes_the_leftovers_and_keeps_the_demo() {
		$app_ids = Documentate_Demo_App::seed();
		$this->assertCount( 12, $app_ids );

		$legacy = $this->leftover_document( 'Demo de la versión anterior' );
		update_post_meta( $legacy, '_documentate_demo_type_id', '7' );

		$leftovers = array(
			$this->leftover_document( 'Return to Review Test 1788339265704' ),
			$this->leftover_document( 'Board Task 1788339265999' ),
		);

		$result = Documentate_Demo_Reset::run();

		$this->assertSame( 2, $result['documentos'], 'Only the documents nothing claims are deleted.' );
		$this->assertSame( 12, $result['sembrados'], 'The workflow documents are back (they never left).' );

		$remaining = $this->remaining_documents();
		foreach ( $leftovers as $id ) {
			$this->assertNotContains( $id, $remaining );
		}
		$this->assertContains( $legacy, $remaining, 'The one-per-type demo document is demo content too.' );
		foreach ( $app_ids as $id ) {
			$this->assertContains( (int) $id, $remaining );
		}
	}

	/**
	 * What hangs off a deleted document goes with it.
	 */
	public function test_the_attachments_and_the_activity_of_a_leftover_go_too() {
		$post_id = $this->leftover_document( 'Preview Download Test 1788339265704' );

		$attachment = self::factory()->attachment->create_object(
			array(
				'file' => 'anexo.pdf',
				'post_parent' => $post_id,
				'post_mime_type' => 'application/pdf',
			)
		);
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		Documentate_Demo_Reset::run();

		$this->assertNull( get_post( $post_id ) );
		$this->assertNull( get_post( $attachment ), 'Core reparents attachments instead of deleting them.' );
		$this->assertNull( get_comment( $comment ) );
	}

	/**
	 * Accounts and terms with a timestamp in their name are the specs'.
	 */
	public function test_it_removes_the_accounts_and_terms_the_specs_create() {
		$test_user = self::factory()->user->create( array( 'user_login' => 'app1788339265704editor' ) );
		$real_user = self::factory()->user->create( array( 'user_login' => 'maria.perez' ) );

		$test_category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name' => 'App Scope app1788339265704',
			)
		);
		$real_category = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name' => 'Innovación Educativa',
			)
		);
		$test_type = self::factory()->term->create(
			array(
				'taxonomy' => 'documentate_doc_type',
				'name' => 'Scope Doc Type e2e1788339266575',
			)
		);

		$result = Documentate_Demo_Reset::run();

		$this->assertSame( 1, $result['usuarios'] );
		$this->assertSame( 1, $result['categorias'] );
		$this->assertSame( 1, $result['tipos'] );

		$this->assertFalse( get_user_by( 'id', $test_user ) );
		$this->assertInstanceOf( WP_User::class, get_user_by( 'id', $real_user ), 'An account a person made is not a leftover.' );
		$this->assertNull( get_term( $test_category, 'category' ) );
		$this->assertInstanceOf( WP_Term::class, get_term( $real_category, 'category' ) );
		$this->assertNull( get_term( $test_type, 'documentate_doc_type' ) );
	}

	/**
	 * The demo accounts survive: their logins carry no timestamp.
	 */
	public function test_the_demo_accounts_survive() {
		Documentate_Demo_App::ensure_environment();

		Documentate_Demo_Reset::run();

		foreach ( array( 'editor1', 'author1', 'subscriber1' ) as $login ) {
			$this->assertInstanceOf( WP_User::class, get_user_by( 'login', $login ), $login . ' is demo content.' );
		}
	}

	/**
	 * On a site where demo content is not allowed, nothing is deleted.
	 */
	public function test_it_refuses_where_demo_content_is_not_allowed() {
		$post_id = $this->leftover_document( 'Algo que no es de ejemplo' );

		$result = Documentate_Demo_Reset::run( 'production' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'entorno_no_permitido', $result->get_error_code() );
		$this->assertInstanceOf( WP_Post::class, get_post( $post_id ), 'A refusal deletes nothing.' );
	}
}
