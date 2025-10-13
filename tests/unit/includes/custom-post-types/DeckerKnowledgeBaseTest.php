<?php
/**
 * Class Test_Resolate_Knowledge_Base
 *
 * @package Resolate
 */

class ResolateKnowledgeBaseTest extends WP_Test_REST_TestCase {

	private $administrator;
	private $editor;

	public function set_up() {
		parent::set_up();

		// Initialize REST server
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		// Create test users
		$this->administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor        = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	public function test_post_type_registration() {
		$post_type = get_post_type_object( 'resolate_kb' );
		$this->assertNotNull( $post_type );
		$this->assertEquals( 'resolate_kb', $post_type->name );
		$this->assertTrue( $post_type->hierarchical );
	}

	public function test_rest_api_integration() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wp/v2/resolate_kb', $routes );
	}

	public function test_hierarchical_structure() {
		wp_set_current_user( $this->administrator );

		// Create parent article
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'resolate_kb',
				'post_title'  => 'Parent Article',
				'post_status' => 'publish',
			)
		);

		// Create child article
		$child_id = self::factory()->post->create(
			array(
				'post_type'   => 'resolate_kb',
				'post_title'  => 'Child Article',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$child_post = get_post( $child_id );
		$this->assertEquals( $parent_id, $child_post->post_parent );
	}

	public function test_board_taxonomy_connection() {
		$taxonomy = get_taxonomy( 'resolate_board' );
		$this->assertContains( 'resolate_kb', $taxonomy->object_type );
	}

	public function test_board_required_for_kb_article() {
		wp_set_current_user( $this->administrator );

		// Create a board
		$board_id = wp_insert_term(
			'Required Board',
			'resolate_board',
			array(
				'slug' => 'required-board',
			)
		);

		// Test the REST API endpoint with missing board
		$request = new WP_REST_Request( 'POST', '/resolate/v1/kb' );
		$request->set_param( 'title', 'Test Article' );
		$request->set_param( 'content', 'Test content' );

		$response = rest_do_request( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertStringContainsString( 'Board is required', $data['message'] );

		// Test with valid board
		$request = new WP_REST_Request( 'POST', '/resolate/v1/kb' );
		$request->set_param( 'title', 'Test Article' );
		$request->set_param( 'content', 'Test content' );
		$request->set_param( 'board', $board_id['term_id'] );

		$response = rest_do_request( $request );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		// Verify the article was created with the board
		$article_id = $data['id'];
		$terms      = wp_get_object_terms( $article_id, 'resolate_board' );
		$this->assertCount( 1, $terms );
		$this->assertEquals( $board_id['term_id'], $terms[0]->term_id );
	}

	public function test_get_articles_with_board_filter() {
		wp_set_current_user( $this->administrator );

		// Create a board
		$board_id = wp_insert_term(
			'Test Board',
			'resolate_board',
			array(
				'slug' => 'test-board',
			)
		);

		// Create another board for testing
		$board2_id = wp_insert_term(
			'Test Board 2',
			'resolate_board',
			array(
				'slug' => 'test-board-2',
			)
		);

		// Create an article with the first board
		$article_id = self::factory()->post->create(
			array(
				'post_type'   => 'resolate_kb',
				'post_title'  => 'Test Article with Board',
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $article_id, array( $board_id['term_id'] ), 'resolate_board' );

		// Create another article with the second board
		$article2_id = self::factory()->post->create(
			array(
				'post_type'   => 'resolate_kb',
				'post_title'  => 'Test Article with Board 2',
				'post_status' => 'publish',
			)
		);

		wp_set_object_terms( $article2_id, array( $board2_id['term_id'] ), 'resolate_board' );

		// Test filtering by board
		$args = array(
			'tax_query' => array(
				array(
					'taxonomy' => 'resolate_board',
					'field'    => 'slug',
					'terms'    => 'test-board',
				),
			),
		);

		$filtered_articles = Resolate_Kb::get_articles( $args );

		// Should only return the article with the board
		$this->assertEquals( 1, count( $filtered_articles ) );
		$this->assertEquals( $article_id, $filtered_articles[0]->ID );
	}
}
