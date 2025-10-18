<?php
/**
 * Tests for default document type seeding.
 */

use Resolate\DocType\SchemaExtractor;
use Resolate\DocType\SchemaStorage;

class ResolateDocumentTypeSeedingTest extends WP_UnitTestCase {

    public function set_up() : void {
        parent::set_up();
        register_post_type( 'resolate_document', array( 'public' => false ) );
        register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
    }

    /**
     * Ensure default document types are created with templates.
     */
    public function test_default_document_types_seeded() {
        $this->delete_term_if_exists( 'resolate-demo-odt' );
        $this->delete_term_if_exists( 'resolate-demo-docx' );
        $this->delete_term_if_exists( 'resolate-demo-wp-resolate-odt' );
        $this->delete_term_if_exists( 'resolate-demo-wp-resolate-docx' );

        resolate_ensure_default_media();
        resolate_maybe_seed_default_doc_types();

        $storage = new SchemaStorage();

        $odt = get_term_by( 'slug', 'resolate-demo-odt', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $odt );
        $this->assertSame( 'resolate-demo-odt', get_term_meta( $odt->term_id, '_resolate_fixture', true ) );
        $odt_schema = $storage->get_schema( $odt->term_id );
        $this->assertIsArray( $odt_schema );
        $this->assertSame( 2, $odt_schema['version'], 'El esquema básico ODT debe ser de la versión 2.' );
        $this->assertSchemaHasFields( $odt_schema, array( 'antecedentes', 'dispositivo', 'fundamentos', 'objeto', 'post_title' ) );

        $docx = get_term_by( 'slug', 'resolate-demo-docx', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $docx );
        $this->assertSame( 'resolate-demo-docx', get_term_meta( $docx->term_id, '_resolate_fixture', true ) );
        $docx_schema = $storage->get_schema( $docx->term_id );
        $this->assertIsArray( $docx_schema );
        $this->assertSame( 2, $docx_schema['version'], 'El esquema básico DOCX debe ser de la versión 2.' );
        $this->assertSchemaHasFields( $docx_schema, array( 'antecedentes', 'dispositivo', 'fundamentos', 'objeto', 'post_title' ) );

        $advanced_odt = get_term_by( 'slug', 'resolate-demo-wp-resolate-odt', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $advanced_odt );
        $advanced_odt_schema = $storage->get_schema( $advanced_odt->term_id );
        $this->assertIsArray( $advanced_odt_schema );
        $this->assertSame( 2, $advanced_odt_schema['version'], 'El esquema avanzado ODT debe ser de la versión 2.' );

        $fixture_extractor = new SchemaExtractor();
        $fixture_schema    = $fixture_extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-resolate.odt' );
        $this->assertNotWPError( $fixture_schema, 'La plantilla de fixtures ODT debe analizarse sin errores.' );
        $fixture_fields    = $this->index_fields_from_schema( $fixture_schema );
        $fixture_repeaters = $this->index_repeaters_from_schema( $fixture_schema );
        $this->assertArrayHasKey( 'items', $fixture_repeaters, 'El fixture debe contener el bloque repetible items.' );

        $this->assertSchemaFieldMatches(
            $advanced_odt_schema,
            'nombrecompleto',
            array(
                'type'        => 'text',
                'placeholder' => $fixture_fields['nombrecompleto']['placeholder'],
                'length'      => $fixture_fields['nombrecompleto']['length'],
            )
        );
        $this->assertSchemaFieldMatches(
            $advanced_odt_schema,
            'email',
            array(
                'type'       => 'email',
                'pattern'    => $fixture_fields['email']['pattern'],
                'patternmsg' => $fixture_fields['email']['patternmsg'],
            )
        );
        $this->assertSchemaFieldMatches(
            $advanced_odt_schema,
            'telfono',
            array(
                'type'       => 'text',
                'pattern'    => $fixture_fields['telfono']['pattern'],
                'patternmsg' => $fixture_fields['telfono']['patternmsg'],
            )
        );
        $this->assertSchemaFieldMatches(
            $advanced_odt_schema,
            'unidades',
            array(
                'type'     => 'number',
                'minvalue' => $fixture_fields['unidades']['minvalue'],
                'maxvalue' => $fixture_fields['unidades']['maxvalue'],
            )
        );
        $this->assertRepeaterHasFields( $advanced_odt_schema, 'items', array_keys( $fixture_repeaters['items'] ) );

        $advanced_docx = get_term_by( 'slug', 'resolate-demo-wp-resolate-docx', 'resolate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $advanced_docx );
        $advanced_docx_schema = $storage->get_schema( $advanced_docx->term_id );
        $this->assertIsArray( $advanced_docx_schema );
        $this->assertSame( 2, $advanced_docx_schema['version'], 'El esquema avanzado DOCX debe ser de la versión 2.' );
        $this->assertRepeaterHasFields( $advanced_docx_schema, 'items', array_keys( $fixture_repeaters['items'] ) );

        $converted_schema = Resolate_Documents::get_term_schema( $advanced_odt->term_id );
        $this->assertIsArray( $converted_schema, 'El CPT debe poder leer el esquema almacenado.' );
        $this->assertNotEmpty( $converted_schema );

        resolate_maybe_seed_default_doc_types();
        $odt_after = get_term_by( 'slug', 'resolate-demo-odt', 'resolate_doc_type' );
        $this->assertSame( $odt->term_id, $odt_after->term_id );
        $advanced_odt_after = get_term_by( 'slug', 'resolate-demo-wp-resolate-odt', 'resolate_doc_type' );
        $this->assertSame( $advanced_odt->term_id, $advanced_odt_after->term_id );
        $advanced_docx_after = get_term_by( 'slug', 'resolate-demo-wp-resolate-docx', 'resolate_doc_type' );
        $this->assertSame( $advanced_docx->term_id, $advanced_docx_after->term_id );
    }

    /**
     * Remove a term by slug if present.
     *
     * @param string $slug Term slug.
     * @return void
     */
    private function delete_term_if_exists( $slug ) {
        $term = get_term_by( 'slug', $slug, 'resolate_doc_type' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_delete_term( $term->term_id, 'resolate_doc_type' );
        }
    }

    /**
     * Assert that schema contains expected placeholders.
     *
     * @param array $schema Schema array.
     * @return void
     */
    /**
     * Assert schema has expected field slugs.
     *
     * @param array $schema  Schema array.
     * @param array $expected Expected slugs.
     * @return void
     */
    private function assertSchemaHasFields( $schema, $expected ) {
        $slugs = array();
        $fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
        foreach ( $fields as $field ) {
            if ( is_array( $field ) && isset( $field['slug'] ) ) {
                $slugs[] = (string) $field['slug'];
            }
        }
        sort( $slugs );
        $expected = array_map( 'strval', $expected );
        sort( $expected );
        $this->assertSame( $expected, $slugs );
    }

    /**
     * Assert schema contains at least one field definition.
     *
     * @param array $schema Schema array.
     * @return void
     */
    private function assertSchemaNotEmpty( $schema ) {
        $fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
        $repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
        $count = count( $fields );
        foreach ( $repeaters as $repeater ) {
            if ( is_array( $repeater ) && isset( $repeater['fields'] ) && is_array( $repeater['fields'] ) ) {
                $count += count( $repeater['fields'] );
            }
        }
        $this->assertGreaterThan( 0, $count );
    }

    /**
     * Assert that a schema field matches specific attributes.
     *
     * @param array  $schema   Schema array.
     * @param string $slug     Field slug to inspect.
     * @param array  $expected Expected key/value pairs.
     * @return void
     */
    private function assertSchemaFieldMatches( $schema, $slug, $expected ) {
        $fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
        $indexed = array();
        foreach ( $fields as $field ) {
            if ( isset( $field['slug'] ) ) {
                $indexed[ $field['slug'] ] = $field;
            }
        }

        $this->assertArrayHasKey( $slug, $indexed, sprintf( 'El campo %s debe existir.', $slug ) );
        foreach ( $expected as $key => $value ) {
            $this->assertArrayHasKey( $key, $indexed[ $slug ], sprintf( 'El campo %s debe incluir la clave %s.', $slug, $key ) );
            $this->assertSame( $value, $indexed[ $slug ][ $key ], sprintf( 'El campo %s no coincide en la clave %s.', $slug, $key ) );
        }
    }

    /**
     * Assert that a repeater contains the expected field slugs.
     *
     * @param array  $schema Schema array.
     * @param string $slug   Repeater slug.
     * @param array  $expected Expected field slugs.
     * @return void
     */
    private function assertRepeaterHasFields( $schema, $slug, $expected ) {
        $repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
        $indexed   = array();
        foreach ( $repeaters as $repeater ) {
            if ( isset( $repeater['slug'] ) ) {
                $indexed[ $repeater['slug'] ] = $repeater;
            }
        }

        $this->assertArrayHasKey( $slug, $indexed, sprintf( 'El bloque %s debe existir.', $slug ) );

        $fields = isset( $indexed[ $slug ]['fields'] ) && is_array( $indexed[ $slug ]['fields'] ) ? $indexed[ $slug ]['fields'] : array();
        $slugs  = array();
        foreach ( $fields as $field ) {
            if ( isset( $field['slug'] ) ) {
                $slugs[] = $field['slug'];
            }
        }
        sort( $slugs );
        $expected = array_values( $expected );
        sort( $expected );

        $this->assertSame( $expected, $slugs, sprintf( 'El bloque %s no contiene los campos esperados.', $slug ) );
    }

    /**
     * Build an index of fields by slug from a schema array.
     *
     * @param array $schema Schema array.
     * @return array<string,array>
     */
    private function index_fields_from_schema( $schema ) {
        $fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
        $indexed = array();
        foreach ( $fields as $field ) {
            if ( isset( $field['slug'] ) ) {
                $indexed[ $field['slug'] ] = $field;
            }
        }
        return $indexed;
    }

    /**
     * Build an index of repeater fields by slug from a schema array.
     *
     * @param array $schema Schema array.
     * @return array<string,array<string,array>>
     */
    private function index_repeaters_from_schema( $schema ) {
        $repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
        $indexed   = array();
        foreach ( $repeaters as $repeater ) {
            if ( ! isset( $repeater['slug'] ) ) {
                continue;
            }
            $items = array();
            if ( isset( $repeater['fields'] ) && is_array( $repeater['fields'] ) ) {
                foreach ( $repeater['fields'] as $field ) {
                    if ( isset( $field['slug'] ) ) {
                        $items[ $field['slug'] ] = $field;
                    }
                }
            }
            $indexed[ $repeater['slug'] ] = $items;
        }
        return $indexed;
    }
}
