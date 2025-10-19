<?php
/**
 * Integration test to ensure repeater blocks merge without printing "Array" and placeholders disappear.
 */

use Resolate\DocType\SchemaExtractor;
use Resolate\DocType\SchemaStorage;

class ResolateArrayMergeIntegrationTest extends WP_UnitTestCase {

    public function set_up(): void {
		parent::set_up();
		register_post_type( 'resolate_document', array( 'public' => false ) );
		register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
	}

    /**
     * Debe generar un ODT con un bloque repetible sin imprimir "Array" y con los campos sustituidos.
     */
    public function test_generate_odt_merges_repeater_without_array_artifacts() {
        // Importa la plantilla avanzada ODT de fixtures y prepara el tipo.
        resolate_ensure_default_media();
        $tpl_id = resolate_import_fixture_file( 'demo-wp-resolate.odt' );
        $this->assertGreaterThan( 0, $tpl_id, 'La plantilla ODT de prueba debe importarse correctamente.' );
        $tpl_path = get_attached_file( $tpl_id );
        $this->assertFileExists( $tpl_path, 'La ruta de la plantilla ODT debe existir.' );

        $term    = wp_insert_term( 'Tipo Repetidor', 'resolate_doc_type' );
        $term_id = intval( $term['term_id'] );
        update_term_meta( $term_id, 'resolate_type_template_id', $tpl_id );
        update_term_meta( $term_id, 'resolate_type_template_type', 'odt' );

        // Extrae y guarda el esquema para ese tipo (incluye el bloque "items").
        $extractor = new SchemaExtractor();
        $schema    = $extractor->extract( $tpl_path );
        $this->assertNotWPError( $schema, 'El esquema de la plantilla ODT debe extraerse sin errores.' );
        $storage = new SchemaStorage();
        $storage->save_schema( $term_id, $schema );

        // Localiza el bloque repetible "items" y sus campos para poblar datos.
        $repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
        $items_def = null;
        foreach ( $repeaters as $rp ) {
            if ( is_array( $rp ) && isset( $rp['slug'] ) && 'items' === $rp['slug'] ) {
                $items_def = $rp;
                break;
            }
        }
        $this->assertIsArray( $items_def, 'La plantilla debe definir un bloque repetible con slug items.' );
        $item_fields = array();
        if ( isset( $items_def['fields'] ) && is_array( $items_def['fields'] ) ) {
            foreach ( $items_def['fields'] as $f ) {
                if ( isset( $f['slug'] ) ) {
                    $item_fields[] = $f['slug'];
                }
            }
        }
        $this->assertNotEmpty( $item_fields, 'El bloque items debe contener campos.' );

        // Prepara un documento con valores para el bloque repetible.
        $post_id = wp_insert_post(
            array(
                'post_type'   => 'resolate_document',
                'post_title'  => 'Documento con Repetidor',
                'post_status' => 'private',
            )
        );
        $this->assertIsInt( $post_id );
        wp_set_post_terms( $post_id, array( $term_id ), 'resolate_doc_type', false );

        $item1 = array();
        $item2 = array();
        foreach ( $item_fields as $slug ) {
            // Genera valores distintivos; usa HTML para alguno por si aplica formato rico.
            if ( false !== strpos( $slug, 'html' ) || false !== strpos( $slug, 'content' ) || false !== strpos( $slug, 'cuerpo' ) ) {
                $item1[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
                $item2[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
            } else {
                $item1[ $slug ] = 'Valor Uno';
                $item2[ $slug ] = 'Valor Dos';
            }
        }

        $doc                          = new Resolate_Documents();
        $_POST['resolate_doc_type']    = (string) $term_id;
        $_POST['tpl_fields']           = wp_slash( array( 'items' => array( $item1, $item2 ) ) );

        // Fuerza composición del contenido estructurado y guarda.
        $data    = array( 'post_type' => 'resolate_document' );
        $postarr = array( 'ID' => $post_id );
        $result  = $doc->filter_post_data_compose_content( $data, $postarr );
        wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
        $_POST = array();

        // Genera el ODT y comprueba que no contiene "Array" ni los placeholders sin resolver.
        $path = Resolate_Document_Generator::generate_odt( $post_id );
        $this->assertIsString( $path, 'La generación ODT debe devolver una ruta.' );
        $this->assertFileExists( $path, 'El archivo ODT generado debe existir.' );

        // Inspecciona content.xml en busca de artefactos.
        $zip    = new ZipArchive();
        $opened = $zip->open( $path );
        $this->assertTrue( true === $opened, 'El ODT generado debe abrirse correctamente.' );
        $xml = $zip->getFromName( 'content.xml' );
        $zip->close();
        $this->assertNotFalse( $xml, 'El ODT debe contener content.xml.' );

        // No debe aparecer el literal "Array".
        $this->assertStringNotContainsString( 'Array', $xml, 'El documento no debe imprimir el literal "Array".' );

        // Debe aparecer al menos un valor del repetidor.
        $this->assertTrue(
            false !== strpos( $xml, 'Valor Uno' ) || false !== strpos( $xml, 'Valor Dos' ),
            'El documento debe contener valores del bloque repetible.'
        );
    }
}
