<?php
/**
 * Tests for Documentate_Demo_Data class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Demo_Data
 */
class DocumentateDemoDataTest extends WP_UnitTestCase {

	/**
	 * Preserve JSON escapes when saving multiline annex demo content.
	 */
	public function test_demo_annex_preserves_json_escapes() {
		$post_id = self::factory()->post->create();
		$items   = array( array( 'summary' => "<p>First.</p>\n<p>Second: \"quoted\".</p>" ) );
		$method  = new ReflectionMethod( Documentate_Demo_Data::class, 'save_demo_fields' );
		$method->setAccessible( true );
		$method->invoke( null, $post_id, array( 'anexos' => array( 'type' => 'array', 'value' => $items ) ) );

		$this->assertSame( $items, json_decode( get_post_meta( $post_id, 'documentate_field_anexos', true ), true ) );
	}

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

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-demo-data.php';
		delete_option( 'documentate_settings' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test create_sample_data sets alert settings.
	 */
	public function test_create_sample_data_sets_alert() {
		wp_set_current_user( $this->admin_user_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		$this->assertSame( 'danger', $options['alert_color'] );
		$this->assertStringContainsString( 'Warning', $options['alert_message'] );
		$this->assertStringContainsString( 'demo data', $options['alert_message'] );
	}

	/**
	 * Test create_sample_data preserves existing settings.
	 */
	public function test_create_sample_data_preserves_existing() {
		wp_set_current_user( $this->admin_user_id );

		// Set existing option.
		update_option(
			'documentate_settings',
			array(
				'conversion_engine' => 'wasm',
				'existing_key'      => 'existing_value',
			)
		);

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Alert should be set.
		$this->assertSame( 'danger', $options['alert_color'] );

		// Existing settings should be preserved.
		$this->assertSame( 'wasm', $options['conversion_engine'] );
		$this->assertSame( 'existing_value', $options['existing_key'] );
	}

	/**
	 * Test create_sample_data can be called as non-admin.
	 */
	public function test_create_sample_data_as_non_admin() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Should still set options (temporarily elevates permissions).
		$this->assertSame( 'danger', $options['alert_color'] );
	}

	/**
	 * Test create_sample_data restores user after execution.
	 */
	public function test_create_sample_data_restores_user() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		// User should be restored.
		$this->assertSame( $subscriber_id, get_current_user_id() );
	}

	/**
	 * Test create_sample_data alert message is translatable.
	 */
	public function test_create_sample_data_alert_is_translatable() {
		wp_set_current_user( $this->admin_user_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Message should contain HTML for emphasis.
		$this->assertStringContainsString( '<strong>', $options['alert_message'] );
		$this->assertStringContainsString( '</strong>', $options['alert_message'] );
	}

	/**
	 * Demo seeding is permitted in the test (non-production) environment.
	 */
	public function test_should_allow_demo_seeding_in_non_production() {
		$this->assertTrue( Documentate_Demo_Data::should_allow_demo_seeding() );
	}

	/**
	 * Demo seeding is permitted inside WordPress Playground.
	 */
	public function test_should_allow_demo_seeding_in_playground() {
		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		$this->assertTrue( Documentate_Demo_Data::should_allow_demo_seeding() );

		unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
	}

	/**
	 * Demo login accounts are created when the seed flag is set and seeding is allowed.
	 */
	public function test_maybe_seed_demo_users_creates_accounts_when_allowed() {
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_Demo_Data::maybe_seed_demo_users();

		$this->assertNotEmpty( username_exists( 'editor1' ) );
		$this->assertNotEmpty( username_exists( 'author1' ) );
		$this->assertNotEmpty( username_exists( 'subscriber1' ) );

		delete_option( 'documentate_seed_demo_documents' );
	}

	/**
	 * No demo accounts are created when the seed flag is absent.
	 */
	public function test_maybe_seed_demo_users_skips_without_seed_flag() {
		delete_option( 'documentate_seed_demo_documents' );

		Documentate_Demo_Data::maybe_seed_demo_users();

		$this->assertEmpty( username_exists( 'editor1' ) );
	}

	/**
	 * Every seeded document type names the PDF layout it is drawn from.
	 */
	public function test_seeded_doc_types_carry_their_pdf_layout() {
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_Demo_Data::ensure_default_media();
		Documentate_Demo_Data::maybe_seed_default_doc_types();

		$expected = array(
			'resolucion-administrativa'            => 'resolucion',
			'propuesta-gasto'                      => 'propuestagasto',
			'hace-constar'                         => 'haceconstar',
			'convocatoria-reunion'                 => 'convocatoriareunion',
			'autorizacion-viaje'                   => 'autorizacionviaje',
			'gastos-suplidos'                      => 'gastossuplidos',
			'memoria-pago'                         => 'memoria_pago_cep',
			'modelo-informe'                       => 'modelo_informe',
			'respuesta-escrito'                    => 'respuesta_escrito',
			'documentate-demo-wp-documentate-odt'  => 'generic',
			'documentate-demo-wp-documentate-docx' => 'generic',
		);

		foreach ( $expected as $slug => $layout ) {
			$term = get_term_by( 'slug', $slug, 'documentate_doc_type' );
			$this->assertInstanceOf( WP_Term::class, $term, $slug . ' must be seeded.' );
			$this->assertSame(
				$layout,
				get_term_meta( $term->term_id, Documentate_Pdf_Layout::META_KEY, true ),
				$slug . ' must name its PDF layout.'
			);
		}

		delete_option( 'documentate_seed_demo_documents' );
	}
}
