<?php
/**
 * Tests for CPT public visibility flags to prevent data leaks.
 *
 * @package Resolate
 */

class CustomPostTypesVisibilityTest extends WP_UnitTestCase {

    // Removed Task and Event CPTs in Resolate.

    public function test_resolate_kb_not_publicly_queryable() {
        do_action( 'init' );
        $pto = get_post_type_object( 'resolate_document' );
        $this->assertNotNull( $pto );
        $this->assertFalse( $pto->public );
        $this->assertFalse( $pto->publicly_queryable );
    }
}
