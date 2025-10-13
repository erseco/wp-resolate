<?php
/**
 * Admin helpers for Resolate (export actions, UI additions).
 */

class Resolate_Admin2 {

    /**
     * Boot hooks.
     */
    public function __construct() {
        add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
        add_action( 'admin_post_resolate_export_docx', array( $this, 'handle_export_docx' ) );
        add_action( 'admin_post_resolate_export_odt', array( $this, 'handle_export_odt' ) );
        add_action( 'admin_post_resolate_export_pdf', array( $this, 'handle_export_pdf' ) );
        add_action( 'admin_post_resolate_preview', array( $this, 'handle_preview' ) );

        // Metabox with action buttons in the edit screen.
        add_action( 'add_meta_boxes', array( $this, 'add_actions_metabox' ) );

        // Surface error notices after redirects.
        add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

        // Enhance title field UX for documents CPT.
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_title_textarea_assets' ) );
    }

    /**
     * Enqueue JS/CSS to replace title input with a textarea for this CPT only.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_title_textarea_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
            return;
        }
        if ( 'resolate_document' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style( 'resolate-title-textarea', plugins_url( 'admin/css/resolate-title.css', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION );
        wp_enqueue_script( 'resolate-title-textarea', plugins_url( 'admin/js/resolate-title.js', RESOLATE_PLUGIN_FILE ), array( 'jquery' ), RESOLATE_VERSION, true );

        // Annexes repeater UI.
        wp_enqueue_script( 'resolate-annexes', plugins_url( 'admin/js/resolate-annexes.js', RESOLATE_PLUGIN_FILE ), array( 'jquery' ), RESOLATE_VERSION, true );
    }

    /**
     * Add "Exportar DOCX" link to row actions for the Resolate CPT.
     *
     * @param array   $actions Row actions.
     * @param WP_Post $post    Post.
     * @return array
     */
    public function add_row_actions( $actions, $post ) {
        if ( 'resolate_document' !== $post->post_type ) {
            return $actions;
        }

        if ( current_user_can( 'edit_post', $post->ID ) ) {
            // Only show DOCX export if a template is configured (global or type-specific).
            $opts = get_option( 'resolate_settings', array() );
            $has_docx_tpl = ! empty( $opts['docx_template_id'] );
            $types = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
                $tid = intval( $types[0] );
                if ( intval( get_term_meta( $tid, 'resolate_type_docx_template', true ) ) > 0 ) { $has_docx_tpl = true; }
            }
            if ( $has_docx_tpl ) {
                $url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'  => 'resolate_export_docx',
                            'post_id' => $post->ID,
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'resolate_export_' . $post->ID
                );
                $actions['resolate_export_docx'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Exportar DOCX', 'resolate' ) . '</a>';
            }
        }

        return $actions;
    }

    /**
     * Handle DOCX export action.
     */
    public function handle_export_docx() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
        }

        $result = Resolate_Document_Generator::generate_docx( $post_id );
        if ( is_wp_error( $result ) ) {
            $msg = $result->get_error_message();
            wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
            exit;
        }

        $upload_dir = wp_upload_dir();
        $baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
        $filename   = basename( $result );
        $url        = $baseurl . $filename;

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle ODT export action.
     */
    public function handle_export_odt() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
        }

        $result = Resolate_Document_Generator::generate_odt( $post_id );
        if ( is_wp_error( $result ) ) {
            $msg = $result->get_error_message();
            wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
            exit;
        }

        $upload_dir = wp_upload_dir();
        $baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
        $filename   = basename( $result );
        $url        = $baseurl . $filename;

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Handle PDF export action.
     */
    public function handle_export_pdf() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
        }

        $result = Resolate_Document_Generator::generate_pdf( $post_id );
        if ( is_wp_error( $result ) ) {
            $msg = $result->get_error_message();
            wp_safe_redirect( add_query_arg( 'resolate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
            exit;
        }

        $upload_dir = wp_upload_dir();
        $baseurl    = trailingslashit( $upload_dir['baseurl'] ) . 'resolate/';
        $filename   = basename( $result );
        $url        = $baseurl . $filename;

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Render-only preview of the document in a new tab.
     */
    public function handle_preview() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'resolate' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'resolate_preview_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( esc_html__( 'Nonce no válido.', 'resolate' ) );
        }

        $title = get_the_title( $post_id );
        $opts  = get_option( 'resolate_settings', array() );
        $font  = isset( $opts['doc_font_family'] ) ? sanitize_text_field( $opts['doc_font_family'] ) : 'Times New Roman';
        $size  = isset( $opts['doc_font_size'] ) ? intval( $opts['doc_font_size'] ) : 12;
        $logo       = isset( $opts['doc_logo_id'] ) ? intval( $opts['doc_logo_id'] ) : 0;
        $logo_r     = isset( $opts['doc_logo_right_id'] ) ? intval( $opts['doc_logo_right_id'] ) : 0;
        // Override with type-specific appearance if present.
        $types = wp_get_post_terms( $post_id, 'resolate_doc_type', array( 'fields' => 'ids' ) );
        if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
            $tid = intval( $types[0] );
            $t_font = sanitize_text_field( (string) get_term_meta( $tid, 'resolate_type_font_name', true ) );
            $t_size = intval( get_term_meta( $tid, 'resolate_type_font_size', true ) );
            if ( '' !== $t_font ) { $font = $t_font; }
            if ( $t_size > 0 ) { $size = $t_size; }
            $t_logos = get_term_meta( $tid, 'resolate_type_logos', true );
            if ( is_array( $t_logos ) && ! empty( $t_logos ) ) {
                $logo   = intval( $t_logos[0] );
                $logo_r = isset( $t_logos[1] ) ? intval( $t_logos[1] ) : $logo_r;
            }
        }
        $logo_url   = $logo ? wp_get_attachment_image_url( $logo, 'full' ) : '';
        $logo_r_url = $logo_r ? wp_get_attachment_image_url( $logo_r, 'full' ) : '';
        $lw = isset( $opts['doc_logo_left_width'] ) ? intval( $opts['doc_logo_left_width'] ) : 220;
        $rw = isset( $opts['doc_logo_right_width'] ) ? intval( $opts['doc_logo_right_width'] ) : 160;
        $margin_txt = isset( $opts['doc_margin_text'] ) ? wp_kses_post( $opts['doc_margin_text'] ) : '';
        $fields = array(
            'resolate_objeto'       => __( 'Objeto', 'resolate' ),
            'resolate_antecedentes' => __( 'Antecedentes', 'resolate' ),
            'resolate_fundamentos'  => __( 'Fundamentos de derecho', 'resolate' ),
            'resolate_dispositivo'  => __( 'Parte dispositiva (Resuelvo)', 'resolate' ),
            'resolate_firma'        => __( 'Firma / Pie', 'resolate' ),
        );

        // Basic printable HTML preview.
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title>';
        echo '<style>
          :root{--page-w:794px;--page-h:1123px;--page-pad:56px;--page-pad-top:40px;--footer-h:32px}
          *{box-sizing:border-box}
          body{background:#f2f2f2;font-family:' . esc_attr( $font ) . ',serif;margin:0;padding:24px}
          .page{background:#fff;width:var(--page-w);height:var(--page-h);margin:0 auto 24px;box-shadow:0 0 0 1px #ddd,0 8px 24px rgba(0,0,0,.08);position:relative;}
          .page-inner{position:absolute;inset:0;padding:var(--page-pad-top) var(--page-pad) calc(var(--page-pad) + var(--footer-h)) var(--page-pad);overflow:hidden}
          .page-footer{position:absolute;right:var(--page-pad);bottom:12px;font-size:' . esc_attr( (string) max( 9, min( 14, $size - 1 ) ) ) . 'pt;color:#333}
          h1{font-size:' . esc_attr( (string) max( 12, min( 48, $size + 6 ) ) ) . 'pt;margin:0 0 16px}
          h2{font-size:' . esc_attr( (string) max( 10, min( 40, $size ) ) ) . 'pt;margin:24px 0 8px}
          section{margin-bottom:16px}
          hr{margin:24px 0;border:0;border-top:1px solid #ddd}
          img.logo{max-width:' . esc_attr( (string) $lw ) . 'px;height:auto;display:block;margin:0 0 16px}
          .logo-right{width:0.8cm;height:1.54cm;object-fit:contain;display:block;position:absolute;top:16px;right:24px}
          .margin-text{position:absolute;left:-60px;top:160px;transform:rotate(-90deg);transform-origin:left top;opacity:0.8;font-size:' . esc_attr( (string) max( 8, min( 36, $size - 2 ) ) ) . 'pt}
          .pages{display:block}
          #content-flow{width:var(--page-w);margin:0 auto;visibility:hidden;position:absolute;left:-9999px;top:-9999px}
          [data-avoid-break]{break-inside:avoid;page-break-inside:avoid}
          @media print{
            body{background:#fff;padding:0}
            .page{margin:0 auto;box-shadow:none;page-break-after:always}
            .page:last-child{page-break-after:auto}
            .logo-right{position:fixed}
          }
        </style>';
        echo '</head><body>';
        echo '<div id="content-flow">';
        if ( $logo_r_url ) {
            echo '<img class="logo-right" src="' . esc_url( $logo_r_url ) . '" alt="Logo derecho" />';
        }
        if ( '' !== trim( (string) $margin_txt ) ) {
            echo '<div class="margin-text">' . wp_kses_post( $margin_txt ) . '</div>';
        }
        if ( $logo_url ) {
            echo '<img class="logo" style="margin-left:0;float:left;" src="' . esc_url( $logo_url ) . '" alt="Logo" />';
            echo '<div style="clear:both"></div>';
        }
        echo '<h1 data-avoid-break="1">' . esc_html( $title ) . '</h1><hr />';
        foreach ( $fields as $meta_key => $label ) {
            $val = get_post_meta( $post_id, $meta_key, true );
            // Strip font family/size from inline styles and <font> tags to enforce configured font.
            $val = preg_replace( '/<\\/?font[^>]*>/i', '', (string) $val );
            $val = preg_replace( '/font-family\\s*:\\s*[^;"\']+;?/i', '', (string) $val );
            $val = preg_replace( '/font-size\\s*:\\s*[^;"\']+;?/i', '', (string) $val );
            if ( '' !== trim( (string) $val ) ) {
                echo '<section data-avoid-break="1"><h2>' . esc_html( $label ) . '</h2>';
                echo wp_kses_post( $val );
                echo '</section>';
            }
        }

        // Append annexes, each starting on a new page.
        $annexes = get_post_meta( $post_id, 'resolate_annexes', true );
        if ( is_array( $annexes ) && ! empty( $annexes ) ) {
            $roman = function( $num ) {
                $map = array(
                    'M'  => 1000,
                    'CM' => 900,
                    'D'  => 500,
                    'CD' => 400,
                    'C'  => 100,
                    'XC' => 90,
                    'L'  => 50,
                    'XL' => 40,
                    'X'  => 10,
                    'IX' => 9,
                    'V'  => 5,
                    'IV' => 4,
                    'I'  => 1,
                );
                $res = '';
                foreach ( $map as $rom => $int ) {
                    while ( $num >= $int ) { $res .= $rom; $num -= $int; }
                }
                return $res;
            };

            foreach ( $annexes as $i => $anx ) {
                $t = isset( $anx['title'] ) ? (string) $anx['title'] : '';
                $c = isset( $anx['text'] ) ? (string) $anx['text'] : '';
                // Clean text similar to sections.
                $c = preg_replace( '/<\\/?font[^>]*>/i', '', (string) $c );
                $c = preg_replace( '/font-family\\s*:\\s*[^;"\']+;?/i', '', (string) $c );
                $c = preg_replace( '/font-size\\s*:\\s*[^;"\']+;?/i', '', (string) $c );
                if ( '' === trim( $t ) && '' === trim( wp_strip_all_tags( (string) $c ) ) ) {
                    continue;
                }
                $label = sprintf( 'Anexo %s', $roman( $i + 1 ) );
                echo '<section data-new-page="1" data-avoid-break="1">';
                echo '<h1>' . esc_html( $label ) . '</h1>';
                if ( '' !== trim( $t ) ) {
                    echo '<h1>' . esc_html( $t ) . '</h1>';
                }
                echo wp_kses_post( $c );
                echo '</section>';
            }
        }
        echo '</div>'; // content-flow
        echo '<div class="pages" id="pages"></div>';
        echo '<script>(function(){
          const pageW = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-w"));
          const pageH = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-h"));
          const padTop = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-pad-top"));
          const pad = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--page-pad"));
          const footerH = parseInt(getComputedStyle(document.documentElement).getPropertyValue("--footer-h"));
          const flow = document.getElementById("content-flow");
          const pages = document.getElementById("pages");
          function newPage(){
            const p = document.createElement("div"); p.className="page";
            const inner = document.createElement("div"); inner.className="page-inner";
            const footer = document.createElement("div"); footer.className="page-footer";
            p.appendChild(inner); p.appendChild(footer); pages.appendChild(p);
            return {p, inner, footer};
          }
          let cur = newPage(); let num=1;
          function setFooter(){ cur.footer.textContent = "Página " + num; }
          setFooter();
          const blocks = Array.from(flow.children);
          for (const el of blocks){
            if (el.hasAttribute("data-new-page") && (cur.inner.childElementCount > 0)) {
              // Start each annex on a fresh page
              num++;
              cur = newPage();
              setFooter();
            }
            cur.inner.appendChild(el.cloneNode(true));
            const maxH = cur.inner.clientHeight; // inner height incl. padding
            if (cur.inner.scrollHeight > maxH){
              // move to next page
              cur.inner.removeChild(cur.inner.lastChild);
              num++;
              cur = newPage();
              setFooter();
              cur.inner.appendChild(el.cloneNode(true));
            }
          }
          // remove flow
          flow.remove();
          // Auto print if requested
          const params = new URLSearchParams(window.location.search);
          if (params.get("print") === "1") {
            setTimeout(function(){ window.print(); }, 300);
          }
        })();</script>';
        echo '</body></html>';
        exit;
    }

    /**
     * Add actions metabox to the edit screen.
     */
    public function add_actions_metabox() {
        add_meta_box(
            'resolate_actions',
            __( 'Acciones del documento', 'resolate' ),
            array( $this, 'render_actions_metabox' ),
            'resolate_document',
            'side',
            'high'
        );
    }

    /**
     * Render action buttons: Preview, DOCX, PDF, ODT.
     *
     * @param WP_Post $post Post object.
     * @return void
     */
    public function render_actions_metabox( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            echo '<p>' . esc_html__( 'Permisos insuficientes.', 'resolate' ) . '</p>';
            return;
        }

        $nonce_export = wp_create_nonce( 'resolate_export_' . $post->ID );
        $nonce_prev   = wp_create_nonce( 'resolate_preview_' . $post->ID );

        $base = admin_url( 'admin-post.php' );

        $preview = add_query_arg( array( 'action' => 'resolate_preview', 'post_id' => $post->ID, '_wpnonce' => $nonce_prev ), $base );
        $docx    = add_query_arg( array( 'action' => 'resolate_export_docx', 'post_id' => $post->ID, '_wpnonce' => $nonce_export ), $base );
        // PDF: usa la vista de previsualización y abre el diálogo de impresión.
        $pdf     = add_query_arg( array( 'action' => 'resolate_preview',  'post_id' => $post->ID, '_wpnonce' => $nonce_prev, 'print' => '1' ), $base );
        $odt     = add_query_arg( array( 'action' => 'resolate_export_odt',  'post_id' => $post->ID, '_wpnonce' => $nonce_export ), $base );

        $opts = get_option( 'resolate_settings', array() );
        $has_docx_tpl = ! empty( $opts['docx_template_id'] );
        $has_odt_tpl  = ! empty( $opts['odt_template_id'] );
        $types = wp_get_post_terms( $post->ID, 'resolate_doc_type', array( 'fields' => 'ids' ) );
        if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
            $tid = intval( $types[0] );
            if ( intval( get_term_meta( $tid, 'resolate_type_docx_template', true ) ) > 0 ) { $has_docx_tpl = true; }
            if ( intval( get_term_meta( $tid, 'resolate_type_odt_template', true ) ) > 0 ) { $has_odt_tpl = true; }
        }

        echo '<p><a class="button button-secondary" href="' . esc_url( $preview ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver documento', 'resolate' ) . '</a></p>';
        echo '<p>';
        if ( $has_docx_tpl ) {
            echo '<a class="button button-primary" href="' . esc_url( $docx ) . '">DOCX</a> ';
        } else {
            echo '<button type="button" class="button button-primary" disabled title="' . esc_attr__( 'Configura una plantilla DOCX en los ajustes.', 'resolate' ) . '">DOCX</button> ';
        }
        echo '<a class="button" href="' . esc_url( $pdf ) . '">PDF</a> ';
        if ( $has_odt_tpl ) {
            echo '<a class="button" href="' . esc_url( $odt ) . '">ODT</a>';
        } else {
            echo '<button type="button" class="button" disabled title="' . esc_attr__( 'Configura una plantilla ODT en los ajustes.', 'resolate' ) . '">ODT</button>';
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__( 'Descarga el documento generado.', 'resolate' ) . '</p>';
    }

    /**
     * Show admin notice if redirected with an error.
     */
    public function maybe_notice() {
        if ( empty( $_GET['resolate_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'resolate_document' !== $screen->id && 'post' !== $screen->base ) {
            // Only show in edit screens.
            return;
        }
        $msg = sanitize_text_field( wp_unslash( $_GET['resolate_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
    }
}

new Resolate_Admin2();
