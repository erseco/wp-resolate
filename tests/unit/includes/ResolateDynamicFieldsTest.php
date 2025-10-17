<?php
/**
 * Tests for the dynamic fields manager.
 *
 * @package Resolate\Tests
 */

/**
 * @group dynamic-fields
 */
class ResolateDynamicFieldsTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );

		require_once dirname( __DIR__, 3 ) . '/includes/class-resolate-dynamic-fields.php';
	}

	/**
	 * It should sanitize and validate dynamic field submissions.
	 */
	public function test_save_dynamic_fields_validates_and_sanitizes() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$manager = new Resolate_Dynamic_Fields();

		$term    = wp_insert_term( 'Tipo Resolución', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$schema  = array(
			array(
				'name'              => 'nombre completo',
				'merge_key'         => 'nombre completo',
				'title'             => 'Nombre completo',
				'type'              => 'text',
				'placeholder'       => 'nombre completo',
				'input_placeholder' => 'Escribe tu nombre',
				'description'       => 'Nombre según documento oficial',
				'pattern'           => '^[A-ZÁÉÍÓÚÑ ]+$',
				'patternmsg'        => 'Solo letras mayúsculas.',
				'length'            => 80,
			),
			array(
				'name'       => 'importe total',
				'merge_key'  => 'importe total',
				'title'      => 'Importe',
				'type'       => 'number',
				'placeholder' => 'importe total',
				'minvalue'   => '0',
				'maxvalue'   => '1000',
			),
			array(
				'name'      => 'cuerpo html',
				'merge_key' => 'cuerpo html',
				'title'     => 'Contenido',
				'type'      => 'html',
				'placeholder' => 'cuerpo html',
			),
		);
		update_term_meta( $term_id, 'schema', $schema );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		$_POST = array(
			Resolate_Dynamic_Fields::NONCE_NAME  => wp_create_nonce( Resolate_Dynamic_Fields::NONCE_ACTION ),
			Resolate_Dynamic_Fields::REQUEST_KEY => wp_slash(
				array(
					'nombre completo' => ' JUAN PÉREZ ',
					'importe total'   => '10.5',
					'cuerpo html'     => '<p>Hola<script>alert(1)</script></p>',
				)
			),
		);

		$manager->save_dynamic_fields( $post_id );

		$this->assertSame( 'JUAN PÉREZ', get_post_meta( $post_id, 'nombre completo', true ) );
		$this->assertSame( '10.5', get_post_meta( $post_id, 'importe total', true ) );
		$this->assertSame( '<p>Hola</p>', get_post_meta( $post_id, 'cuerpo html', true ) );
		$this->assertFalse( get_transient( Resolate_Dynamic_Fields::TRANSIENT_PREFIX . $user_id ), 'No debe haber errores tras guardar valores válidos.' );

		$_POST = array(
			Resolate_Dynamic_Fields::NONCE_NAME  => wp_create_nonce( Resolate_Dynamic_Fields::NONCE_ACTION ),
			Resolate_Dynamic_Fields::REQUEST_KEY => wp_slash(
				array(
					'nombre completo' => '1234',
				)
			),
		);

		$manager->save_dynamic_fields( $post_id );

		$this->assertSame( 'JUAN PÉREZ', get_post_meta( $post_id, 'nombre completo', true ), 'El valor anterior debe mantenerse tras un error.' );
		$errors = get_transient( Resolate_Dynamic_Fields::TRANSIENT_PREFIX . $user_id );
		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );

		$_POST = array();
	}
}
