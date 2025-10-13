<?php
/**
 * Integration tests for Resolate Resolutions CPT and taxonomies.
 */

class ResolateResolutionsRegistrationTest extends WP_UnitTestCase {

    /**
     * Ensure the post type is registered with expected properties.
     */
    public function test_post_type_registered() {
        $this->assertTrue( post_type_exists( 'resolate_resolution' ), 'El CPT "resolate_resolution" no está registrado.' );

        $pt = get_post_type_object( 'resolate_resolution' );
        $this->assertNotNull( $pt, 'No se pudo obtener el objeto del CPT.' );

        // Should not support the block editor (we use meta boxes) and only title support.
        $this->assertFalse( post_type_supports( 'resolate_resolution', 'editor' ), 'No debe soportar el editor de bloques.' );
        $this->assertTrue( post_type_supports( 'resolate_resolution', 'title' ), 'Debe soportar el título.' );

        // Not public and not in REST for now.
        $this->assertFalse( $pt->public, 'No debe ser público.' );
        $this->assertFalse( $pt->show_in_rest, 'No debe estar en REST por ahora.' );
    }

    /**
     * Ensure taxonomies are registered and attached to the CPT.
     */
    public function test_taxonomies_registered() {
        $this->assertTrue( taxonomy_exists( 'resolate_ambito' ), 'La taxonomía "resolate_ambito" no está registrada.' );
        $this->assertTrue( taxonomy_exists( 'resolate_ley' ), 'La taxonomía "resolate_ley" no está registrada.' );

        $tax1 = get_taxonomy( 'resolate_ambito' );
        $tax2 = get_taxonomy( 'resolate_ley' );

        $this->assertContains( 'resolate_resolution', $tax1->object_type, 'La taxonomía "resolate_ambito" no está asociada al CPT.' );
        $this->assertContains( 'resolate_resolution', $tax2->object_type, 'La taxonomía "resolate_ley" no está asociada al CPT.' );

        $this->assertTrue( $tax1->hierarchical, 'Ámbitos debe ser jerárquica.' );
        $this->assertFalse( $tax2->hierarchical, 'Leyes no debe ser jerárquica.' );
    }
}

