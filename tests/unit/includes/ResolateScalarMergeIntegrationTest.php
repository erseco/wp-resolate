<?php
/**
 * Integration test to ensure scalar placeholders map to template names (name, phone, Observaciones).
 */

use Resolate\DocType\SchemaExtractor;
use Resolate\DocType\SchemaStorage;

class ResolateScalarMergeIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

	/**
	 * Debe sustituir correctamente placeholders simples como [name], [phone] y [Observaciones].
	 */
	public function test_generate_odt_merges_scalar_placeholders_correctly() {
		// Importa la plantilla avanzada ODT de fixtures y prepara el tipo.
		resolate_ensure_default_media();
		$tpl_id = resolate_import_fixture_file( 'demo-wp-resolate.odt' );
		$this->assertGreaterThan( 0, $tpl_id, 'La plantilla ODT de prueba debe importarse correctamente.' );
		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path, 'La ruta de la plantilla ODT debe existir.' );

		$term    = wp_insert_term( 'Tipo Escalares', 'resolate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'resolate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'resolate_type_template_type', 'odt' );

		// Extrae y guarda el esquema para ese tipo.
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $tpl_path );
		$this->assertNotWPError( $schema, 'El esquema de la plantilla ODT debe extraerse sin errores.' );
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Prepara un documento con valores para campos simples.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => 'Documento de prueba',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

		// Slugs esperados según SchemaExtractorTest.
		$_POST['resolate_field_nombrecompleto'] = 'Pepe Pérez';
		$_POST['resolate_field_email']          = 'demo1@ejemplo.es';
		$_POST['resolate_field_telfono']        = '+34611112222';
		$_POST['resolate_field_dni']            = '12345671A';
		$_POST['resolate_field_body']           = '<p>Cuerpo simple</p>';
		$_POST['resolate_field_unidades']       = '7';
		$_POST['resolate_field_observaciones']  = 'Texto de observación';

		// Fuerza composición del contenido estructurado y guarda.
		$doc     = new Resolate_Documents();
		$_POST['resolate_doc_type'] = (string) $term_id;
		$data    = array( 'post_type' => 'resolate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		$path = Resolate_Document_Generator::generate_odt( $post_id );
		$this->assertIsString( $path, 'La generación ODT debe devolver una ruta.' );
		$this->assertFileExists( $path, 'El archivo ODT generado debe existir.' );

		$zip = new ZipArchive();
		$opened = $zip->open( $path );
		$this->assertTrue( true === $opened, 'El ODT generado debe abrirse correctamente.' );
		$xml = $zip->getFromName( 'content.xml' );
		$zip->close();
		$this->assertNotFalse( $xml, 'El ODT debe contener content.xml.' );

		// No debe aparecer el literal "Array" ni restos de placeholders sin resolver.
		$this->assertStringNotContainsString( 'Array', $xml, 'El documento no debe imprimir el literal "Array".' );
		$this->assertStringNotContainsString( '[name', $xml, 'No deben quedar placeholders [name...] sin resolver.' );
		$this->assertStringNotContainsString( '[phone', $xml, 'No deben quedar placeholders [phone...] sin resolver.' );
		$this->assertStringNotContainsString( '[Observaciones', $xml, 'No deben quedar placeholders [Observaciones...] sin resolver.' );

		// Deben aparecer los valores aportados.
		$this->assertStringContainsString( 'Pepe Pérez', $xml, 'El nombre debe aparecer en el documento.' );
		$this->assertStringContainsString( 'demo1@ejemplo.es', $xml, 'El email debe aparecer en el documento.' );
		$this->assertTrue(
			false !== strpos( $xml, '+34611112222' ) || false !== strpos( $xml, '+34 611112222' ),
			'El teléfono debe aparecer en el documento.'
		);
		$this->assertStringContainsString( '12345671A', $xml, 'El DNI debe aparecer en el documento.' );
		$this->assertStringContainsString( 'Texto de observación', $xml, 'Las observaciones deben aparecer en el documento.' );
	}
}

