<?php
/**
 * Document generator for Resolate based on OpenTBS templates.
 *
 * Generates DOCX/ODT using preconfigured templates via OpenTBS. PDF is
 * currently handled via print preview from the admin UI.
 *
 * @package Resolate
 */

/**
 * Resolate document generator service.
 */
class Resolate_Document_Generator {

    /**
     * Generate a DOCX file for a given Document post using a DOCX template.
     *
     * @param int $post_id Document post ID.
     * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
     */
    public static function generate_docx( $post_id ) {
        try {
            $opts   = get_option( 'resolate_settings', array() );
            // Prefer template from selected document type.
            $tpl_id = 0;
            $types = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
                $tpl_id = intval( get_term_meta( intval( $types[0] ), 'resolate_type_docx_template', true ) );
            }
            if ( $tpl_id <= 0 ) {
                $tpl_id = isset( $opts['docx_template_id'] ) ? intval( $opts['docx_template_id'] ) : 0;
            }
            if ( $tpl_id <= 0 ) {
                return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla DOCX configurada.', 'resolate' ) );
            }

            $template_path = get_attached_file( $tpl_id );
            if ( ! $template_path || ! file_exists( $template_path ) ) {
                return new WP_Error( 'resolate_template_missing', __( 'Plantilla DOCX no encontrada.', 'resolate' ) );
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

            $fields = array(
                'title'        => get_the_title( $post_id ),
                'objeto'       => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_objeto', true ) ),
                'antecedentes' => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_antecedentes', true ) ),
                'fundamentos'  => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_fundamentos', true ) ),
                'dispositivo'  => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_dispositivo', true ) ),
                'firma'        => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_firma', true ) ),
                'margen'       => wp_strip_all_tags( isset( $opts['doc_margin_text'] ) ? $opts['doc_margin_text'] : '' ),
            );
            // Merge dynamic fields as [slug] => value.
            if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
                $schema = get_term_meta( intval( $types[0] ), 'resolate_type_fields', true );
                if ( is_array( $schema ) ) {
                    foreach ( $schema as $def ) {
                        if ( empty( $def['slug'] ) ) { continue; }
                        $slug = sanitize_key( $def['slug'] );
                        $val  = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
                        $fields[ $slug ] = wp_strip_all_tags( (string) $val );
                    }
                }
                // Expose logos if defined at type level.
                $logos = get_term_meta( intval( $types[0] ), 'resolate_type_logos', true );
                if ( is_array( $logos ) && ! empty( $logos ) ) {
                    $i = 1;
                    foreach ( $logos as $att_id ) {
                        $att_id = intval( $att_id );
                        if ( $att_id > 0 ) {
                            $fields[ 'logo' . $i . '_path' ] = get_attached_file( $att_id );
                            $fields[ 'logo' . $i . '_url' ]  = wp_get_attachment_url( $att_id );
                            $i++;
                        }
                    }
                }
            }

            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            $filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.docx';
            $path     = trailingslashit( $dir ) . $filename;

            $res = Resolate_OpenTBS::render_docx( $template_path, $fields, $path );
            if ( is_wp_error( $res ) ) {
                return $res;
            }

            return $path;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'resolate_docx_error', $e->getMessage() );
        }
    }

    /**
     * Generate an ODT file for a given Document post using an ODT template.
     *
     * @param int $post_id Document post ID.
     * @return string|WP_Error Absolute path to generated file or WP_Error on failure.
     */
    public static function generate_odt( $post_id ) {
        try {
            $opts   = get_option( 'resolate_settings', array() );
            // Prefer template from selected document type.
            $tpl_id = 0;
            $types = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
                $tpl_id = intval( get_term_meta( intval( $types[0] ), 'resolate_type_odt_template', true ) );
            }
            if ( $tpl_id <= 0 ) {
                $tpl_id = isset( $opts['odt_template_id'] ) ? intval( $opts['odt_template_id'] ) : 0;
            }
            if ( $tpl_id <= 0 ) {
                return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla ODT configurada.', 'resolate' ) );
            }

            $template_path = get_attached_file( $tpl_id );
            if ( ! $template_path || ! file_exists( $template_path ) ) {
                return new WP_Error( 'resolate_template_missing', __( 'Plantilla ODT no encontrada.', 'resolate' ) );
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

            $fields = array(
                'title'        => get_the_title( $post_id ),
                'objeto'       => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_objeto', true ) ),
                'antecedentes' => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_antecedentes', true ) ),
                'fundamentos'  => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_fundamentos', true ) ),
                'dispositivo'  => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_dispositivo', true ) ),
                'firma'        => wp_strip_all_tags( (string) get_post_meta( $post_id, 'resolate_firma', true ) ),
                'margen'       => wp_strip_all_tags( isset( $opts['doc_margin_text'] ) ? $opts['doc_margin_text'] : '' ),
            );
            if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
                $schema = get_term_meta( intval( $types[0] ), 'resolate_type_fields', true );
                if ( is_array( $schema ) ) {
                    foreach ( $schema as $def ) {
                        if ( empty( $def['slug'] ) ) { continue; }
                        $slug = sanitize_key( $def['slug'] );
                        $val  = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
                        $fields[ $slug ] = wp_strip_all_tags( (string) $val );
                    }
                }
                $logos = get_term_meta( intval( $types[0] ), 'resolate_type_logos', true );
                if ( is_array( $logos ) && ! empty( $logos ) ) {
                    $i = 1;
                    foreach ( $logos as $att_id ) {
                        $att_id = intval( $att_id );
                        if ( $att_id > 0 ) {
                            $fields[ 'logo' . $i . '_path' ] = get_attached_file( $att_id );
                            $fields[ 'logo' . $i . '_url' ]  = wp_get_attachment_url( $att_id );
                            $i++;
                        }
                    }
                }
            }

            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.odt';
            $path     = trailingslashit( $dir ) . $filename;

            $res = Resolate_OpenTBS::render_odt( $template_path, $fields, $path );
            if ( is_wp_error( $res ) ) {
                return $res;
            }

            return $path;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'resolate_odt_error', $e->getMessage() );
        }
    }

    /**
     * PDF generation is not implemented (use print preview in admin UI).
     *
     * @param int $post_id Document post ID.
     * @return WP_Error
     */
    public static function generate_pdf( $post_id ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        return new WP_Error( 'resolate_pdf_pending', __( 'Generación de PDF pendiente de configuración.', 'resolate' ) );
    }

    /**
     * Generate DOCX for a Law post using the same configured DOCX template.
     * Expects placeholders like [title] and [contenido] in the template.
     *
     * @param int $post_id Post ID.
     * @return string|WP_Error
     */
    public static function generate_docx_law( $post_id ) {
        try {
            $opts   = get_option( 'resolate_settings', array() );
            $tpl_id = isset( $opts['docx_template_id'] ) ? intval( $opts['docx_template_id'] ) : 0;
            if ( $tpl_id <= 0 ) {
                return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla DOCX configurada.', 'resolate' ) );
            }
            $template_path = get_attached_file( $tpl_id );
            if ( ! $template_path || ! file_exists( $template_path ) ) {
                return new WP_Error( 'resolate_template_missing', __( 'Plantilla DOCX no encontrada.', 'resolate' ) );
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

            $content = get_post_field( 'post_content', $post_id );
            $content = apply_filters( 'the_content', $content );
            $fields = array(
                'title'     => get_the_title( $post_id ),
                'contenido' => wp_strip_all_tags( (string) $content ),
            );

            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            $filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.docx';
            $path     = trailingslashit( $dir ) . $filename;

            $res = Resolate_OpenTBS::render_docx( $template_path, $fields, $path );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            return $path;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'resolate_docx_error', $e->getMessage() );
        }
    }

    /**
     * Generate ODT for a Law post using the same configured ODT template.
     * Placeholders expected: [title], [contenido].
     *
     * @param int $post_id Post ID.
     * @return string|WP_Error
     */
    public static function generate_odt_law( $post_id ) {
        try {
            $opts   = get_option( 'resolate_settings', array() );
            $tpl_id = isset( $opts['odt_template_id'] ) ? intval( $opts['odt_template_id'] ) : 0;
            if ( $tpl_id <= 0 ) {
                return new WP_Error( 'resolate_template_missing', __( 'No hay plantilla ODT configurada.', 'resolate' ) );
            }
            $template_path = get_attached_file( $tpl_id );
            if ( ! $template_path || ! file_exists( $template_path ) ) {
                return new WP_Error( 'resolate_template_missing', __( 'Plantilla ODT no encontrada.', 'resolate' ) );
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-opentbs.php';

            $content = get_post_field( 'post_content', $post_id );
            $content = apply_filters( 'the_content', $content );
            $fields = array(
                'title'     => get_the_title( $post_id ),
                'contenido' => wp_strip_all_tags( (string) $content ),
            );

            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $filename = sanitize_title( $fields['title'] ) . '-' . $post_id . '.odt';
            $path     = trailingslashit( $dir ) . $filename;

            $res = Resolate_OpenTBS::render_odt( $template_path, $fields, $path );
            if ( is_wp_error( $res ) ) {
                return $res;
            }
            return $path;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'resolate_odt_error', $e->getMessage() );
        }
    }
}
