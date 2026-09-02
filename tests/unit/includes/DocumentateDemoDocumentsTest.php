<?php
/**
 * Tests for demo document seeding.
 */

class DocumentateDemoDocumentsTest extends WP_UnitTestCase {

	/**
	 * Admin user ID for testing.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );

		// Create and set admin user (required for document access).
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * It should create demo documents per document type with structured content.
	 * The "resolucion-administrativa" type creates 3 specific documents, others create 1.
	 */
	public function test_demo_documents_seeded_per_type() {
		delete_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_Demo_Data::ensure_default_media();
		Documentate_Demo_Data::maybe_seed_default_doc_types();

		$terms = get_terms(
			array(
				'taxonomy'   => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);

		$this->assertNotWPError( $terms );
		$this->assertNotEmpty( $terms );

		Documentate_Demo_Data::maybe_seed_demo_documents();

		foreach ( $terms as $term ) {
			$posts = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_type_id',
					'meta_value'     => (string) $term->term_id,
				)
			);

			// "resolucion-administrativa" type creates 3 specific demo documents.
			$expected_count = ( 'resolucion-administrativa' === $term->slug ) ? 3 : 1;
			$this->assertCount( $expected_count, $posts, "Must create {$expected_count} test document(s) for type {$term->slug}." );

			foreach ( $posts as $post_id ) {
				$post_id = intval( $post_id );
				$this->assertGreaterThan( 0, $post_id );

				$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
				$this->assertNotWPError( $assigned );
				$this->assertContains( $term->term_id, $assigned, 'Test document must be assigned to the corresponding type.' );

				$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
				$this->assertNotEmpty( $structured, 'Test document must include structured content.' );
			}
		}

		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ), 'Seeding option must be removed after creating documents.' );
	}

	/**
	 * Test that the 3 specific resolution demo documents are created correctly.
	 */
	public function test_resolucion_demo_documents_created() {
		delete_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_Demo_Data::ensure_default_media();
		Documentate_Demo_Data::maybe_seed_default_doc_types();
		Documentate_Demo_Data::maybe_seed_demo_documents();

		$expected_keys = array( 'resolucion-prueba', 'listado-provisional-prueba', 'listado-definitivo-prueba' );

		foreach ( $expected_keys as $demo_key ) {
			$posts = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_key',
					'meta_value'     => $demo_key,
				)
			);

			$this->assertCount( 1, $posts, "Demo document with key '{$demo_key}' must exist." );

			// The template prints the official data of the resolución between
			// the objeto and the antecedentes, so the demo must fill it in.
			$this->assertNotEmpty(
				get_post_meta( $posts[0], 'documentate_field_numero_resolucion', true ),
				"Demo document '{$demo_key}' must carry a resolution number."
			);
			$this->assertNotEmpty( get_post_meta( $posts[0], 'documentate_field_fecha_resolucion', true ) );
			$this->assertNotEmpty( get_post_meta( $posts[0], 'documentate_field_expediente', true ) );
			$this->assertNotEmpty( get_post_meta( $posts[0], 'documentate_field_organo_firmante', true ) );
		}
	}

	/**
	 * The generated demo documents fill the official data in too.
	 *
	 * A document built from the schema (instead of from the fixed demo data)
	 * would otherwise print "Resolución n.º , de ." in the ODT.
	 *
	 * @dataProvider gestion_slug_provider
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Control type.
	 * @param string $datat Data type.
	 */
	public function test_generated_demo_values_cover_the_official_resolucion_fields( $slug, $type, $datat ) {
		$value = Documentate_Demo_Data::generate_demo_scalar_value( $slug, $type, $datat );

		$this->assertNotSame( '', trim( $value ) );
		$this->assertStringNotContainsString( 'Lorem ipsum', $value );
	}

	/**
	 * Official fields of the resolución template.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function gestion_slug_provider() {
		return array(
			'número' => array( 'numero_resolucion', 'single', 'text' ),
			'fecha' => array( 'fecha_resolucion', 'single', 'date' ),
			'expediente' => array( 'expediente', 'single', 'text' ),
			'órgano' => array( 'organo_firmante', 'single', 'text' ),
		);
	}

	/**
	 * Seeding with no logged-in user (WP-CLI, Playground blueprint steps) must
	 * still give every demo document a scope category and an author, and must
	 * not seed twice.
	 */
	public function test_demo_documents_get_scope_and_author_without_a_logged_in_user() {
		wp_set_current_user( 0 );
		delete_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_Demo_Data::ensure_default_media();
		Documentate_Demo_Data::maybe_seed_default_doc_types();
		Documentate_Demo_Data::maybe_seed_demo_categories();
		Documentate_Demo_Data::maybe_seed_demo_users();
		Documentate_Demo_Data::maybe_seed_demo_documents();

		wp_set_current_user( $this->admin_user_id );

		$ids = get_posts(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_documentate_demo_type_id',
			)
		);
		$this->assertNotEmpty( $ids );

		$root = get_term_by( 'name', 'Organización', 'category' );
		$this->assertInstanceOf( WP_Term::class, $root );
		$scope_ids = get_term_children( $root->term_id, 'category' );

		$authors = array();
		foreach ( $ids as $post_id ) {
			$categories = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
			$this->assertNotEmpty( array_intersect( $categories, $scope_ids ), "Demo document {$post_id} must belong to a scope category." );
			$authors[] = (int) get_post_field( 'post_author', $post_id );
		}
		$this->assertNotContains( 0, $authors, 'Every demo document must have an author.' );
		$this->assertContains( get_user_by( 'login', 'editor1' )->ID, $authors );
		$this->assertContains( get_user_by( 'login', 'author1' )->ID, $authors );

		// A second seeding pass in the same anonymous context must detect the
		// existing demo documents instead of duplicating them.
		wp_set_current_user( 0 );
		update_option( 'documentate_seed_demo_documents', true );
		Documentate_Demo_Data::maybe_seed_demo_documents();
		wp_set_current_user( $this->admin_user_id );

		$again = get_posts(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_documentate_demo_type_id',
			)
		);
		$this->assertCount( count( $ids ), $again );
		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ) );
	}
}

