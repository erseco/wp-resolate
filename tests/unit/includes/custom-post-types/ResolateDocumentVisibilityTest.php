<?php
/**
 * Tests for enforcing private visibility and publish date restrictions on documents.
 */

class ResolateDocumentVisibilityTest extends WP_UnitTestCase {

	/**
	 * Document handler instance.
	 *
	 * @var Resolate_Documents
	 */
	protected $documents;

	public function set_up() : void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		$this->documents = new Resolate_Documents();
	}

	public function test_new_documents_saved_as_private_without_password() {
		$post_id = wp_insert_post(
			array(
				'post_type'      => 'resolate_document',
				'post_title'     => 'Documento privado',
				'post_status'    => 'publish',
				'post_date'      => '2030-01-01 10:00:00',
				'post_date_gmt'  => '2030-01-01 08:00:00',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'private', $stored->post_status, 'El documento debe guardarse como privado.' );
		$this->assertSame( '', $stored->post_password, 'El documento no debe tener contraseña.' );
		$this->assertNotEquals( '2030-01-01 10:00:00', $stored->post_date, 'La fecha personalizada no debe aplicarse.' );
		$this->assertNotEquals( '2030-01-01 08:00:00', $stored->post_date_gmt, 'La fecha GMT personalizada no debe aplicarse.' );
	}

	public function test_existing_document_ignores_manual_date_changes() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento inicial',
				'post_status' => 'private',
			)
		);

		$this->assertNotWPError( $post_id );

		$original = get_post( $post_id );

		$updated_id = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_type'     => 'resolate_document',
				'post_status'   => 'publish',
				'post_date'     => '2040-02-02 12:00:00',
				'post_date_gmt' => '2040-02-02 10:00:00',
			)
		);

		$this->assertSame( $post_id, $updated_id, 'El ID actualizado debe coincidir.' );

		$reloaded = get_post( $post_id );
		$this->assertEquals( 'private', $reloaded->post_status, 'El documento debe mantenerse privado.' );
		$this->assertSame( '', $reloaded->post_password, 'El documento no debe tener contraseña.' );
		$this->assertEquals( $original->post_date, $reloaded->post_date, 'La fecha original debe mantenerse.' );
		$this->assertEquals( $original->post_date_gmt, $reloaded->post_date_gmt, 'La fecha GMT original debe mantenerse.' );
	}
}
