<?php
/**
 * Tests for Resolate_Document_Generator template resolution.
 *
 * @package Resolate
 */

/**
 * @covers Resolate_Document_Generator
 */
class ResolateDocumentGeneratorTemplateTest extends Resolate_Test_Base {

        /**
         * Track attachments created during the test run.
         *
         * @var int[]
         */
        private $attachment_ids = array();

        public function set_up() {
                parent::set_up();
                require_once plugin_dir_path( RESOLATE_PLUGIN_FILE ) . 'includes/class-resolate-document-generator.php';
        }

        public function tear_down() {
                foreach ( $this->attachment_ids as $attachment_id ) {
                        wp_delete_attachment( $attachment_id, true );
                }
                $this->attachment_ids = array();
                delete_option( 'resolate_settings' );
                parent::tear_down();
        }

        /**
         * Ensure global template IDs are used when the document type lacks templates.
         */
        public function test_get_template_path_uses_global_odt_when_type_missing() {
                $post_id = self::factory()->post->create(
                        array(
                                'post_type' => 'resolate_document',
                        )
                );

                $attachment_id = $this->create_dummy_template( 'global-template.odt' );

                update_option(
                        'resolate_settings',
                        array(
                                'odt_template_id' => $attachment_id,
                        )
                );

                $path = Resolate_Document_Generator::get_template_path( $post_id, 'odt' );

                $this->assertSame( get_attached_file( $attachment_id ), $path );
        }

        /**
         * Create a dummy template attachment for testing purposes.
         *
         * @param string $filename Desired filename for the attachment.
         * @return int Attachment ID.
         */
        private function create_dummy_template( $filename ) {
                $upload = wp_upload_bits( $filename, null, 'contenido de prueba' );
                $this->assertEmpty( $upload['error'] );

                $attachment_id        = $this->factory->attachment->create_upload_object( $upload['file'] );
                $this->attachment_ids[] = $attachment_id;

                return $attachment_id;
        }
}
