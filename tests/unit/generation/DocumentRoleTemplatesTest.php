<?php
/**
 * Generation tests for the bundled templates that declare fields by rol.
 *
 * The rol attribute is meaningful to the plugin only; OpenTBS must keep
 * merging the placeholders that carry it, and the new gestión fields of the
 * resolución must land in the document.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

/**
 * Class DocumentRoleTemplatesTest
 */
class DocumentRoleTemplatesTest extends Documentate_Generation_Test_Base {

	/**
	 * Create a document type from a template of the plugin's fixtures/ directory.
	 *
	 * @param string $fixture_name Template filename.
	 * @return int Term ID.
	 */
	private function create_type_from_plugin_fixture( $fixture_name ) {
		$source = dirname( __DIR__, 3 ) . '/fixtures/' . $fixture_name;
		$this->assertFileExists( $source );

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$template_path = trailingslashit( $upload_dir['basedir'] ) . 'rol-' . $fixture_name;
		copy( $source, $template_path );
		$this->temp_files[] = $template_path;

		$template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.oasis.opendocument.text',
				'post_title' => $fixture_name,
				'post_status' => 'inherit',
			),
			$template_path
		);
		$term = wp_insert_term( 'Rol ' . $fixture_name . ' ' . uniqid(), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		$schema = ( new SchemaExtractor() )->extract( $template_path );
		$this->assertNotWPError( $schema );
		( new SchemaStorage() )->save_schema( $term_id, $schema );

		return $term_id;
	}

	/**
	 * The resolución merges its four new gestión fields and the rol'd bodies.
	 */
	public function test_resolucion_merges_the_management_fields() {
		$term_id = $this->create_type_from_plugin_fixture( 'resolucion.odt' );
		$post_id = $this->create_document_with_data(
			$term_id,
			array(
				'objeto' => 'Objeto de prueba',
				'numero_resolucion' => '118/2026',
				'fecha_resolucion' => '2026-09-01',
				'expediente' => 'EXP-2026-0042',
				'organo_firmante' => 'Viceconsejería de Educación',
				'antecedentes' => '<p>Primer antecedente.</p>',
				'fundamentos' => '<p>Primer fundamento.</p>',
				'resuelvo' => '<p>Resuelvo aprobar.</p>',
			),
			array(
				'anexos' => array(
					array(
						'code' => 'Anexo I',
						'title' => 'FINALIDAD',
						'summary' => '<p>Resumen del anexo.</p>',
					),
				),
			)
		);

		$doc_path = $this->generate_document( $post_id, 'odt' );
		$this->assertNotWPError( $doc_path );
		$this->assertFileExists( $doc_path );

		$this->assertDocumentContains( $doc_path, 'Resolución n.º 118/2026' );
		$this->assertDocumentContains( $doc_path, 'EXP-2026-0042' );
		$this->assertDocumentContains( $doc_path, 'Viceconsejería de Educación' );
		$this->assertDocumentContains( $doc_path, 'Primer antecedente.' );
		$this->assertDocumentContains( $doc_path, 'Resuelvo aprobar.' );
		$this->assertDocumentNotContains( $doc_path, '[numero_resolucion' );
		$this->assertDocumentNotContains( $doc_path, '[organo_firmante' );
		$this->assertDocumentNotContains( $doc_path, "rol='gestion'" );
		$this->assertNoPlaceholderArtifacts( $doc_path );
	}

	/**
	 * The propuesta de gasto merges the gestión blocks and scalars.
	 */
	public function test_propuesta_gasto_merges_the_management_blocks() {
		$term_id = $this->create_type_from_plugin_fixture( 'propuestagasto.odt' );
		$post_id = $this->create_document_with_data(
			$term_id,
			array(
				'curso' => '2026/2027',
				'letra_decreto' => 'a',
				'para' => 'formar al profesorado',
				'objeto' => 'Objeto del proyecto',
				'lineadeactuacion' => 'Línea 1',
				'destinatarios' => 'Profesorado',
				'alcance_centros' => '12',
				'gasto_letra' => 'Mil veintiocho euros',
				'gasto_numero' => '1028',
				'partida' => '18.03.321B.229.0100',
			),
			array(
				'servicios' => array(
					array(
						'proveedor' => 'Formación Docente Canarias S.L.',
						'cif' => 'B76543210',
						'email' => 'contacto@formaciondocente.es',
						'telefono' => '922123456',
						'bruto' => '1021',
						'igic' => '7',
						'irpf' => '0',
						'total' => '1028',
						'conceptos' => array(
							array(
								'concepto' => 'Curso presencial',
								'cantidad' => '2',
								'unitario' => '10.5',
								'total' => '21',
							),
							array(
								'concepto' => 'Material',
								'cantidad' => '1',
								'unitario' => '1000',
								'total' => '1000',
							),
						),
					),
				),
			)
		);

		$doc_path = $this->generate_document( $post_id, 'odt' );
		$this->assertNotWPError( $doc_path );
		$this->assertFileExists( $doc_path );

		$this->assertDocumentContains( $doc_path, 'Mil veintiocho euros' );
		$this->assertDocumentContains( $doc_path, '18.03.321B.229.0100' );
		$this->assertDocumentContains( $doc_path, 'Formación Docente Canarias S.L.' );
		$this->assertDocumentContains( $doc_path, 'Curso presencial' );
		$this->assertDocumentNotContains( $doc_path, '[servicios' );
		$this->assertDocumentNotContains( $doc_path, '[gasto_numero' );
		$this->assertDocumentNotContains( $doc_path, "rol='gestion'" );
		$this->assertNoPlaceholderArtifacts( $doc_path );
	}
}
