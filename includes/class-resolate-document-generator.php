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
                $type_id       = intval( $types[0] );
                $type_template = intval( get_term_meta( $type_id, 'resolate_type_template_id', true ) );
                $template_kind = sanitize_key( (string) get_term_meta( $type_id, 'resolate_type_template_type', true ) );
                if ( $type_template > 0 ) {
                    if ( 'docx' === $template_kind ) {
                        $tpl_id = $type_template;
                    } elseif ( '' === $template_kind ) {
                        $path = get_attached_file( $type_template );
                        if ( $path && 'docx' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                            $tpl_id = $type_template;
                        }
                    }
                }
                if ( $tpl_id <= 0 ) {
                    $tpl_id = intval( get_term_meta( $type_id, 'resolate_type_docx_template', true ) );
                }
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
                $schema = self::get_type_schema( intval( $types[0] ) );
                foreach ( $schema as $def ) {
                    if ( empty( $def['slug'] ) ) { continue; }
                    $slug = sanitize_key( $def['slug'] );
                    $val  = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
                    $fields[ $slug ] = wp_strip_all_tags( (string) $val );
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
                $type_id       = intval( $types[0] );
                $type_template = intval( get_term_meta( $type_id, 'resolate_type_template_id', true ) );
                $template_kind = sanitize_key( (string) get_term_meta( $type_id, 'resolate_type_template_type', true ) );
                if ( $type_template > 0 ) {
                    if ( 'odt' === $template_kind ) {
                        $tpl_id = $type_template;
                    } elseif ( '' === $template_kind ) {
                        $path = get_attached_file( $type_template );
                        if ( $path && 'odt' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
                            $tpl_id = $type_template;
                        }
                    }
                }
                if ( $tpl_id <= 0 ) {
                    $tpl_id = intval( get_term_meta( $type_id, 'resolate_type_odt_template', true ) );
                }
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
                $schema = self::get_type_schema( intval( $types[0] ) );
                foreach ( $schema as $def ) {
                    if ( empty( $def['slug'] ) ) { continue; }
                    $slug = sanitize_key( $def['slug'] );
                    $val  = get_post_meta( $post_id, 'resolate_field_' . $slug, true );
                    $fields[ $slug ] = wp_strip_all_tags( (string) $val );
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
     * Retrieve sanitized schema definition for a document type.
     *
     * @param int $term_id Term ID.
     * @return array[]
     */
    private static function get_type_schema( $term_id ) {
        $raw = get_term_meta( $term_id, 'schema', true );
        if ( ! is_array( $raw ) ) {
            $raw = get_term_meta( $term_id, 'resolate_type_fields', true );
        }
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $out = array();
        foreach ( $raw as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $slug  = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
            $label = isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '';
            $type  = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
            if ( '' === $slug || '' === $label ) {
                continue;
            }
            if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
                $type = 'textarea';
            }
            $out[] = array(
                'slug'  => $slug,
                'label' => $label,
                'type'  => $type,
            );
        }
        return $out;
    }

    /**
     * PDF generation is not implemented (use print preview in admin UI).
     *
     * @param int $post_id Document post ID.
     * @return WP_Error
     */
    public static function generate_pdf( $post_id ) {
        try {
            $source_path   = '';
            $source_format = '';

            $odt_result = self::generate_odt( $post_id );
            if ( is_wp_error( $odt_result ) ) {
                $docx_result = self::generate_docx( $post_id );
                if ( is_wp_error( $docx_result ) ) {
                    return new WP_Error(
                        'resolate_pdf_source_missing',
                        __( 'No se pudo generar el documento base en ODT ni en DOCX antes de convertir a PDF.', 'resolate' ),
                        array(
                            'odt'  => $odt_result,
                            'docx' => $docx_result,
                        )
                    );
                }
                $source_path   = $docx_result;
                $source_format = 'docx';
            } else {
                $source_path   = $odt_result;
                $source_format = 'odt';
            }

            require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-zetajs.php';
            if ( ! class_exists( 'Resolate_Zetajs_Converter' ) ) {
                return new WP_Error( 'resolate_zetajs_not_available', __( 'No se pudo cargar el conversor de ZetaJS.', 'resolate' ) );
            }

            if ( Resolate_Zetajs_Converter::is_cdn_mode() ) {
                return new WP_Error(
                    'resolate_zetajs_browser_only',
                    Resolate_Zetajs_Converter::get_browser_conversion_message(),
                    array(
                        'mode'       => 'cdn',
                        'cdn_base'   => Resolate_Zetajs_Converter::get_cdn_base_url(),
                        'source'     => $source_path,
                        'source_ext' => $source_format,
                    )
                );
            }

            if ( ! Resolate_Zetajs_Converter::is_available() ) {
                return new WP_Error( 'resolate_zetajs_not_available', __( 'Configura el ejecutable de ZetaJS para generar PDF.', 'resolate' ) );
            }

            $upload_dir = wp_upload_dir();
            $dir        = trailingslashit( $upload_dir['basedir'] ) . 'resolate';
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $filename = sanitize_title( get_the_title( $post_id ) ) . '-' . $post_id . '.pdf';
            $target   = trailingslashit( $dir ) . $filename;

            $result = Resolate_Zetajs_Converter::convert( $source_path, $target, 'pdf', $source_format );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return $target;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'resolate_pdf_error', $e->getMessage() );
        }
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
