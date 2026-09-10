<?php
/**
 * Tests for default document type seeding.
 */

use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

class DocumentateDocumentTypeSeedingTest extends WP_UnitTestCase {

    public function set_up() : void {
        parent::set_up();
        register_post_type( 'documentate_document', array( 'public' => false ) );
        register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
    }

    /**
     * Ensure default document types are created with templates.
     */
    public function test_default_document_types_seeded() {
        $this->delete_term_if_exists( 'resolucion-administrativa' );
        $this->delete_term_if_exists( 'documentate-demo-wp-documentate-odt' );
        $this->delete_term_if_exists( 'documentate-demo-wp-documentate-docx' );

        Documentate_Demo_Data::ensure_default_media();
        update_option('documentate_seed_demo_documents', true);
        Documentate_Demo_Data::maybe_seed_default_doc_types();

        $storage = new SchemaStorage();

        // Test the main resolution template (resolucion.odt).
        $resolution = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $resolution );
        $this->assertSame( 'resolucion-administrativa', get_term_meta( $resolution->term_id, '_documentate_fixture', true ) );
        $resolution_schema = $storage->get_schema( $resolution->term_id );
        $this->assertIsArray( $resolution_schema );
        $this->assertSame( 2, $resolution_schema['version'], 'Resolution schema must be version 2.' );
        $this->assertSchemaHasFields(
            $resolution_schema,
            array( 'antecedentes', 'resuelvo', 'fundamentos', 'objeto', 'post_title', 'numero_resolucion', 'fecha_resolucion', 'expediente', 'organo_firmante', 'pie_recursos', 'fondos_europeos' )
        );
        // A resolución is the área's from end to end, official data included.
        $this->assertSchemaFieldMatches( $resolution_schema, 'numero_resolucion', array( 'type' => 'text', 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'expediente', array( 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'pie_recursos', array( 'type' => 'select', 'rol' => '' ) );
        // Declared on the visibility block it switches on, so the checkbox
        // itself prints nothing into the document.
        $this->assertSchemaFieldMatches( $resolution_schema, 'fondos_europeos', array( 'type' => 'boolean', 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'antecedentes', array( 'type' => 'html', 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'fundamentos', array( 'type' => 'html', 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'resuelvo', array( 'type' => 'html', 'rol' => '' ) );
        $this->assertSchemaFieldMatches( $resolution_schema, 'objeto', array( 'rol' => '' ) );

        // Prefixes and the gestión flag of the seeded types.
        $prefixes = array(
            'resolucion-administrativa' => 'RES',
            'propuesta-gasto' => 'PG',
            'convocatoria-reunion' => 'CONV',
            'hace-constar' => 'HC',
            'solicitud-desplazamiento-dg' => 'SD',
            'autorizacion-viaje' => 'AV',
            'gastos-suplidos' => 'GS',
            'memoria-pago' => 'MP',
            'respuesta-escrito' => 'RE',
            'modelo-informe' => 'INF',
            'documentate-demo-wp-documentate-odt' => '',
        );
        foreach ( $prefixes as $slug => $prefix ) {
            $term = get_term_by( 'slug', $slug, 'documentate_doc_type' );
            $this->assertInstanceOf( WP_Term::class, $term, $slug );
            $this->assertSame( $prefix, get_term_meta( $term->term_id, 'documentate_type_prefijo', true ), $slug );
            // Only the documento 0 goes through revisión: every field of a
            // resolución belongs to the área that drafts it.
            $has_management = 'propuesta-gasto' === $slug;
            $this->assertSame( $has_management ? '1' : '', get_term_meta( $term->term_id, 'documentate_type_con_gestion', true ), $slug );
            $this->assertSame( $has_management, Documentate_Document_Data::type_has_management( $term->term_id ), $slug );
        }

        $advanced_odt = get_term_by( 'slug', 'documentate-demo-wp-documentate-odt', 'documentate_doc_type' );
        $this->assertInstanceOf( WP_Term::class, $advanced_odt );
        $advanced_odt_schema = $storage->get_schema( $advanced_odt->term_id );
        $this->assertIsArray( $advanced_odt_schema );
        $this->assertSame( 2, $advanced_odt_schema['version'], 'Advanced ODT schema must be version 2.' );

        $fixture_extractor = new SchemaExtractor();
        $fixture_schema    = $fixture_extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-documentate.odt' );
        $this->assertNotWPError( $fixture_schema, 'ODT fixture template must be parsed without errors.' );
        $fixture_fields    = $this->index_fields_from_schema( $fixture_schema );
        $fixture_repeaters = $this->index_repeaters_from_schema( $fixture_schema );
        $this->assertArrayHasKey( 'items', $fixture_repeaters, 'Fixture must contain the items repeater block.' );

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

        $this->assertFalse(
            get_term_by( 'slug', 'documentate-demo-wp-documentate-docx', 'documentate_doc_type' ),
            'The DOCX twin of the advanced example is no longer one of the examples.'
        );

        $converted_schema = Documentate_Documents::get_term_schema( $advanced_odt->term_id );
        $this->assertIsArray( $converted_schema, 'CPT must be able to read the stored schema.' );
        $this->assertNotEmpty( $converted_schema );

        Documentate_Demo_Data::maybe_seed_default_doc_types();
        $resolution_after = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
        $this->assertSame( $resolution->term_id, $resolution_after->term_id );
        $advanced_odt_after = get_term_by( 'slug', 'documentate-demo-wp-documentate-odt', 'documentate_doc_type' );
        $this->assertSame( $advanced_odt->term_id, $advanced_odt_after->term_id );
    }

    /**
     * A site seeded by an earlier version loses the DOCX example, unless a
     * document of that type would go with it.
     */
    public function test_the_docx_example_is_retired_from_a_site_that_has_it() {
        update_option( 'documentate_seed_demo_documents', true );
        $legacy = static function () {
            $term = wp_insert_term( 'Tipo de documento de prueba avanzado (DOCX)', 'documentate_doc_type', array( 'slug' => 'documentate-demo-wp-documentate-docx' ) );
            update_term_meta( (int) $term['term_id'], '_documentate_fixture', 'documentate-demo-wp-documentate-docx' );

            return (int) $term['term_id'];
        };

        $term_id = $legacy();
        Documentate_Demo_Data::maybe_seed_default_doc_types();
        $this->assertFalse( get_term_by( 'slug', 'documentate-demo-wp-documentate-docx', 'documentate_doc_type' ) );

        // With a document of that type it stays: the document would be left
        // pointing at a type that is not there any more.
        $term_id = $legacy();
        $doc = self::factory()->post->create(
            array(
                'post_type' => 'documentate_document',
                'post_status' => 'draft',
                'tax_input' => array( 'documentate_doc_type' => array( $term_id ) ),
            )
        );
        wp_set_object_terms( $doc, array( $term_id ), 'documentate_doc_type' );

        Documentate_Demo_Data::maybe_seed_default_doc_types();

        $this->assertInstanceOf( WP_Term::class, get_term_by( 'slug', 'documentate-demo-wp-documentate-docx', 'documentate_doc_type' ) );
    }

    /**
     * Seeding again brings the name of an example up to date, and leaves a
     * name somebody edited alone.
     */
    public function test_seeding_again_renames_its_own_examples_only() {
        update_option( 'documentate_seed_demo_documents', true );
        Documentate_Demo_Data::ensure_default_media();
        Documentate_Demo_Data::maybe_seed_default_doc_types();

        $expense = get_term_by( 'slug', 'propuesta-gasto', 'documentate_doc_type' );
        $this->assertSame( 'Propuesta de gasto (Documento 0)', $expense->name );

        wp_update_term( $expense->term_id, 'documentate_doc_type', array( 'name' => 'Propuesta de gasto del servicio' ) );
        Documentate_Demo_Data::maybe_seed_default_doc_types();

        $this->assertSame(
            'Propuesta de gasto del servicio',
            get_term( $expense->term_id, 'documentate_doc_type' )->name,
            'A name of their own is not overwritten by the seeder.'
        );
    }

    /**
     * Remove a term by slug if present.
     *
     * @param string $slug Term slug.
     * @return void
     */
    private function delete_term_if_exists( $slug ) {
        $term = get_term_by( 'slug', $slug, 'documentate_doc_type' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_delete_term( $term->term_id, 'documentate_doc_type' );
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

        $this->assertArrayHasKey( $slug, $indexed, sprintf( 'Field %s must exist.', $slug ) );
        foreach ( $expected as $key => $value ) {
            $this->assertArrayHasKey( $key, $indexed[ $slug ], sprintf( 'Field %s must include key %s.', $slug, $key ) );
            $actual_value = $indexed[ $slug ][ $key ];
            if ( 'pattern' === $key ) {
                $value        = $this->normalize_pattern( $value );
                $actual_value = $this->normalize_pattern( $actual_value );
            }
            $this->assertSame( $value, $actual_value, sprintf( 'Field %s does not match on key %s.', $slug, $key ) );
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

        $this->assertArrayHasKey( $slug, $indexed, sprintf( 'Block %s must exist.', $slug ) );

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

        $this->assertSame( $expected, $slugs, sprintf( 'Block %s does not contain the expected fields.', $slug ) );
    }

    /**
     * Normalize patterns to compare equivalent strings.
     *
     * @param string $pattern Original pattern.
     * @return string
     */
    private function normalize_pattern( $pattern ) {
        $pattern = (string) $pattern;
        // Unify redundant escapes in dots and hyphens.
        $pattern = str_replace( array( '\.', '\-' ), array( '.', '-' ), $pattern );
        // Normalize duplicate brace sequences.
        $pattern = str_replace( array( '{2,}', '{{2,}}' ), '{2,}', $pattern );
        return $pattern;
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
