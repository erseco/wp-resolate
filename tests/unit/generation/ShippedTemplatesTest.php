<?php
/**
 * Every shipped template generates a document, in both formats.
 *
 * A smoke test over the whole set: the seeder gives each document type its
 * template and one demo document with values for every field it declares, and
 * this walks all of them. It is what catches a placeholder that a template
 * writes wrong — a missing separator, a name that no field answers to — in the
 * one place where every template is exercised at once.
 *
 * @package Documentate
 */

/**
 * Generation smoke test for the shipped templates.
 */
class ShippedTemplatesTest extends Documentate_Generation_Test_Base {

	/**
	 * Seed the document types and their demo documents.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'documentate_seed_demo_documents', true );
		Documentate_Demo_Data::ensure_default_media();
		Documentate_Demo_Data::maybe_seed_default_doc_types();
		Documentate_Demo_Data::maybe_seed_demo_documents();
	}

	/**
	 * Every seeded type renders its demo document as an ODT and as a PDF, with
	 * no placeholder left behind in either.
	 */
	public function test_every_shipped_template_generates_both_formats() {
		$types = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);

		$this->assertNotWPError( $types );
		$this->assertGreaterThan( 10, count( $types ), 'The seeder should have created the shipped types.' );

		foreach ( $types as $type ) {
			$documents = get_posts(
				array(
					'post_type' => 'documentate_document',
					'post_status' => 'any',
					'posts_per_page' => 1,
					'fields' => 'ids',
					'meta_key' => '_documentate_demo_type_id',
					'meta_value' => (string) $type->term_id,
				)
			);

			$this->assertNotEmpty( $documents, 'Type ' . $type->slug . ' should carry a demo document.' );
			$post_id = (int) $documents[0];

			$odt = $this->generate_document( $post_id, 'odt' );
			$this->assertNotWPError( $odt, 'The ODT of ' . $type->slug . ' should be generated.' );
			$this->assertNoPlaceholderArtifacts( $odt );

			$pdf = $this->generate_document( $post_id, 'pdf' );
			$this->assertIsString( $pdf, 'The PDF of ' . $type->slug . ' should be generated.' );
			$this->assertFileExists( $pdf );
			$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $pdf ) );
		}
	}
}
