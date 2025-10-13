<?php
/**
 * Admin UI for "Tipos de documento" taxonomy term meta.
 *
 * Allows defining per-type templates (ODT/DOCX), logos, font family/size,
 * and a dynamic fields schema that is later rendered in document edit screens.
 */

defined( 'ABSPATH' ) || exit;

class Resolate_Doc_Types_Admin {

    /**
     * Boot hooks.
     */
    public function __construct() {
        add_action( 'resolate_doc_type_add_form_fields', array( $this, 'add_fields' ) );
        add_action( 'resolate_doc_type_edit_form_fields', array( $this, 'edit_fields' ), 10, 2 );
        add_action( 'created_resolate_doc_type', array( $this, 'save_term' ) );
        add_action( 'edited_resolate_doc_type', array( $this, 'save_term' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Enqueue media and small JS for the taxonomy screens.
     */
    public function enqueue_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-resolate_doc_type' !== $screen->id ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'resolate-doc-types', plugins_url( 'admin/js/resolate-doc-types.js', RESOLATE_PLUGIN_FILE ), array( 'jquery', 'underscore' ), RESOLATE_VERSION, true );
        wp_localize_script( 'resolate-doc-types', 'resolateDocTypes', array(
            'i18n' => array(
                'single'  => __( 'Línea', 'resolate' ),
                'textarea'=> __( 'Párrafo', 'resolate' ),
                'rich'    => __( 'Enriquecido', 'resolate' ),
                'remove'  => __( 'Eliminar', 'resolate' ),
                'select'  => __( 'Seleccionar archivo', 'resolate' ),
                'logo'    => __( 'Seleccionar logo', 'resolate' ),
            ),
        ) );
        wp_enqueue_style( 'resolate-doc-types', plugins_url( 'admin/css/resolate-doc-types.css', RESOLATE_PLUGIN_FILE ), array(), RESOLATE_VERSION );
    }

    /**
     * Render extra fields on the Add term screen.
     */
    public function add_fields() {
        ?>
        <div class="form-field">
            <label for="resolate_type_docx_template"><?php esc_html_e( 'Plantilla DOCX', 'resolate' ); ?></label>
            <input type="hidden" id="resolate_type_docx_template" name="resolate_type_docx_template" value="" />
            <div id="resolate_type_docx_template_preview"></div>
            <button type="button" class="button resolate-media-select" data-target="resolate_type_docx_template" data-type="application/vnd.openxmlformats-officedocument.wordprocessingml.document"><?php esc_html_e( 'Seleccionar plantilla DOCX', 'resolate' ); ?></button>
        </div>
        <div class="form-field">
            <label for="resolate_type_odt_template"><?php esc_html_e( 'Plantilla ODT', 'resolate' ); ?></label>
            <input type="hidden" id="resolate_type_odt_template" name="resolate_type_odt_template" value="" />
            <div id="resolate_type_odt_template_preview"></div>
            <button type="button" class="button resolate-media-select" data-target="resolate_type_odt_template" data-type="application/vnd.oasis.opendocument.text"><?php esc_html_e( 'Seleccionar plantilla ODT', 'resolate' ); ?></button>
        </div>
        <div class="form-field">
            <label><?php esc_html_e( 'Logos', 'resolate' ); ?></label>
            <div id="resolate_type_logos_list"></div>
            <input type="hidden" id="resolate_type_logos" name="resolate_type_logos" value="" />
            <button type="button" class="button resolate-add-logo"><?php esc_html_e( 'Añadir logo', 'resolate' ); ?></button>
        </div>
        <div class="form-field">
            <label for="resolate_type_font_name"><?php esc_html_e( 'Fuente', 'resolate' ); ?></label>
            <select id="resolate_type_font_name" name="resolate_type_font_name">
                <?php
                $fonts = array( 'Times New Roman', 'Arial', 'Calibri', 'Georgia', 'Garamond' );
                foreach ( $fonts as $f ) {
                    echo '<option value="' . esc_attr( $f ) . '">' . esc_html( $f ) . '</option>';
                }
                ?>
            </select>
        </div>
        <div class="form-field">
            <label for="resolate_type_font_size"><?php esc_html_e( 'Tamaño de fuente (pt)', 'resolate' ); ?></label>
            <input type="number" id="resolate_type_font_size" name="resolate_type_font_size" min="8" max="48" step="1" value="12" />
        </div>
        <div class="form-field">
            <label><?php esc_html_e( 'Campos del documento', 'resolate' ); ?></label>
            <p class="description"><?php esc_html_e( 'Define campos de texto que se mostrarán al editar el documento. Tipos: línea, párrafo, enriquecido.', 'resolate' ); ?></p>
            <div id="resolate_type_fields"></div>
            <button type="button" class="button resolate-add-field"><?php esc_html_e( 'Añadir campo', 'resolate' ); ?></button>
            <input type="hidden" id="resolate_type_fields_json" name="resolate_type_fields_json" value="[]" />
        </div>
        <?php
    }

    /**
     * Render extra fields on the Edit term screen.
     *
     * @param WP_Term $term Term.
     */
    public function edit_fields( $term, $taxonomy ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $docx = intval( get_term_meta( $term->term_id, 'resolate_type_docx_template', true ) );
        $odt  = intval( get_term_meta( $term->term_id, 'resolate_type_odt_template', true ) );
        $logos = get_term_meta( $term->term_id, 'resolate_type_logos', true );
        $logos = is_array( $logos ) ? array_map( 'intval', $logos ) : array();
        $font  = sanitize_text_field( (string) get_term_meta( $term->term_id, 'resolate_type_font_name', true ) );
        $size  = intval( get_term_meta( $term->term_id, 'resolate_type_font_size', true ) );
        $schema = get_term_meta( $term->term_id, 'resolate_type_fields', true );
        if ( ! is_array( $schema ) ) { $schema = array(); }
        ?>
        <tr class="form-field">
            <th scope="row"><label for="resolate_type_docx_template"><?php esc_html_e( 'Plantilla DOCX', 'resolate' ); ?></label></th>
            <td>
                <input type="hidden" id="resolate_type_docx_template" name="resolate_type_docx_template" value="<?php echo esc_attr( (string) $docx ); ?>" />
                <div id="resolate_type_docx_template_preview"><?php echo $docx ? esc_html( basename( get_attached_file( $docx ) ) ) : ''; ?></div>
                <button type="button" class="button resolate-media-select" data-target="resolate_type_docx_template" data-type="application/vnd.openxmlformats-officedocument.wordprocessingml.document"><?php esc_html_e( 'Seleccionar plantilla DOCX', 'resolate' ); ?></button>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="resolate_type_odt_template"><?php esc_html_e( 'Plantilla ODT', 'resolate' ); ?></label></th>
            <td>
                <input type="hidden" id="resolate_type_odt_template" name="resolate_type_odt_template" value="<?php echo esc_attr( (string) $odt ); ?>" />
                <div id="resolate_type_odt_template_preview"><?php echo $odt ? esc_html( basename( get_attached_file( $odt ) ) ) : ''; ?></div>
                <button type="button" class="button resolate-media-select" data-target="resolate_type_odt_template" data-type="application/vnd.oasis.opendocument.text"><?php esc_html_e( 'Seleccionar plantilla ODT', 'resolate' ); ?></button>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e( 'Logos', 'resolate' ); ?></label></th>
            <td>
                <div id="resolate_type_logos_list" data-initial="<?php echo esc_attr( wp_json_encode( $logos ) ); ?>"></div>
                <input type="hidden" id="resolate_type_logos" name="resolate_type_logos" value="<?php echo esc_attr( implode( ',', $logos ) ); ?>" />
                <button type="button" class="button resolate-add-logo"><?php esc_html_e( 'Añadir logo', 'resolate' ); ?></button>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="resolate_type_font_name"><?php esc_html_e( 'Fuente', 'resolate' ); ?></label></th>
            <td>
                <select id="resolate_type_font_name" name="resolate_type_font_name">
                    <?php
                    $fonts = array( 'Times New Roman', 'Arial', 'Calibri', 'Georgia', 'Garamond' );
                    foreach ( $fonts as $f ) {
                        echo '<option value="' . esc_attr( $f ) . '" ' . selected( $font, $f, false ) . '>' . esc_html( $f ) . '</option>';
                    }
                    ?>
                </select>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="resolate_type_font_size"><?php esc_html_e( 'Tamaño de fuente (pt)', 'resolate' ); ?></label></th>
            <td><input type="number" id="resolate_type_font_size" name="resolate_type_font_size" min="8" max="48" step="1" value="<?php echo esc_attr( (string) max( 8, $size ) ); ?>" /></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label><?php esc_html_e( 'Campos del documento', 'resolate' ); ?></label></th>
            <td>
                <p class="description"><?php esc_html_e( 'Define campos de texto que se mostrarán al editar. Tipos: línea (single), párrafo (textarea), enriquecido (rich).', 'resolate' ); ?></p>
                <div id="resolate_type_fields" data-initial="<?php echo esc_attr( wp_json_encode( $schema ) ); ?>"></div>
                <button type="button" class="button resolate-add-field"><?php esc_html_e( 'Añadir campo', 'resolate' ); ?></button>
                <input type="hidden" id="resolate_type_fields_json" name="resolate_type_fields_json" value="<?php echo esc_attr( wp_json_encode( $schema ) ); ?>" />
            </td>
        </tr>
        <?php
    }

    /**
     * Save term meta for document type.
     *
     * @param int $term_id Term ID.
     */
    public function save_term( $term_id ) {
        // DOCX / ODT templates.
        $docx = isset( $_POST['resolate_type_docx_template'] ) ? intval( $_POST['resolate_type_docx_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $odt  = isset( $_POST['resolate_type_odt_template'] ) ? intval( $_POST['resolate_type_odt_template'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        update_term_meta( $term_id, 'resolate_type_docx_template', $docx > 0 ? $docx : '' );
        update_term_meta( $term_id, 'resolate_type_odt_template', $odt > 0 ? $odt : '' );

        // Logos: CSV of IDs.
        $logos_csv = isset( $_POST['resolate_type_logos'] ) ? sanitize_text_field( wp_unslash( $_POST['resolate_type_logos'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $logos = array_filter( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $logos_csv ) ) ) ) );
        update_term_meta( $term_id, 'resolate_type_logos', $logos );

        // Font name/size.
        $font = isset( $_POST['resolate_type_font_name'] ) ? sanitize_text_field( wp_unslash( $_POST['resolate_type_font_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $size = isset( $_POST['resolate_type_font_size'] ) ? intval( $_POST['resolate_type_font_size'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( '' !== $font ) { update_term_meta( $term_id, 'resolate_type_font_name', $font ); }
        if ( $size > 0 ) { update_term_meta( $term_id, 'resolate_type_font_size', $size ); }

        // Fields schema JSON.
        $json = isset( $_POST['resolate_type_fields_json'] ) ? wp_unslash( $_POST['resolate_type_fields_json'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $data = json_decode( $json, true );
        $schema = array();
        if ( is_array( $data ) ) {
            foreach ( $data as $row ) {
                if ( ! is_array( $row ) ) { continue; }
                $slug  = isset( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
                $label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
                $type  = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
                if ( '' === $slug || '' === $label ) { continue; }
                if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) { $type = 'textarea'; }
                $schema[] = array(
                    'slug'  => $slug,
                    'label' => $label,
                    'type'  => $type,
                );
            }
        }
        update_term_meta( $term_id, 'resolate_type_fields', $schema );
    }
}

new Resolate_Doc_Types_Admin();
