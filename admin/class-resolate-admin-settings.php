<?php
/**
 * Admin_Settings Class
 *
 * This class handles the settings page for the Resolate plugin.
 *
 * @package Resolate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Resolate_Admin_Settings
 *
 * Handles the settings page for the Resolate plugin.
 */
class Resolate_Admin_Settings {

	/**
	 * Constructor
	 *
	 * Initializes the class by defining hooks.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Render Shared Key Field
	 *
	 * Outputs the HTML for the shared_key field, generating it only if it does not exist or does not meet the criteria.
	 */
	public function shared_key_render() {
		$options = get_option( 'resolate_settings', array() );

		// Generate a new shared key (UUID) only if it does not exist or does not meet criteria.
		if ( empty( $options['shared_key'] ) ) {
			$options['shared_key'] = wp_generate_uuid4();
			// Save the newly generated UUID back to the options.
			update_option( 'resolate_settings', $options );
		}

		$value = sanitize_text_field( $options['shared_key'] );
		echo '<input type="text" name="resolate_settings[shared_key]" pattern=".{8,}" value="' . esc_attr( $value ) . '" class="regular-text" pattern="" title="The key must be at least 8 characters long and include letters, numbers, and symbols." required>';
		echo '<p class="description">' . esc_html__( 'Provide the Bearer token in the Authorization header for the email-to-post endpoint. Example request:', 'resolate' ) . '</p>';
		echo '<pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">';
		echo 'POST ' . esc_url( get_site_url() ) . '/wp-json/resolate/v1/email-to-post';
		echo "\nHeader: Authorization: Bearer YOUR_SHARED_KEY";
		echo '</pre>';
	}

	/**
	 * Render Allow Email Notifications Field.
	 *
	 * Outputs the HTML for the allow_email_notifications field.
	 */
	public function allow_email_notifications_render() {
		$options = get_option( 'resolate_settings', array() );
		$checked = isset( $options['allow_email_notifications'] ) && '1' === $options['allow_email_notifications'];

		echo '<label>';
		echo '<input type="checkbox" name="resolate_settings[allow_email_notifications]" value="1" ' . checked( $checked, true, false ) . '>';
		echo esc_html__( 'Enable email notifications for all plugin events.', 'resolate' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'This setting allows users to manage email notifications in their profile. By default, all notifications are enabled.', 'resolate' ) . '</p>';
	}

	/**
	 * Render User Profile Field.
	 *
	 * Outputs the HTML for the minimum_user_profile field, displaying only roles with edit permissions.
	 */
	public function minimum_user_profile_render() {
		// Get saved plugin options.
		$options       = get_option( 'resolate_settings', array() );

		// Default to 'editor' if no user profile is selected.
		$selected_role = isset( $options['minimum_user_profile'] ) && ! empty( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// Retrieve all registered roles in WordPress.
		$roles = wp_roles()->roles;

		// Filter roles to include only those with 'edit_posts' capability.
		$editable_roles = array_filter(
			$roles,
			function ( $role ) {
				return isset( $role['capabilities']['edit_posts'] ) && $role['capabilities']['edit_posts'];
			}
		);

		// Render the select dropdown for user profiles.
		echo '<select name="resolate_settings[minimum_user_profile]" id="minimum_user_profile">';
		foreach ( $editable_roles as $role_value => $role_data ) {
			echo '<option value="' . esc_attr( $role_value ) . '" ' . selected( $selected_role, $role_value, false ) . '>' . esc_html( $role_data['name'] ) . '</option>';
		}
		echo '</select>';

		// Add a description below the dropdown.
		echo '<p class="description">' . esc_html__( 'Select the minimum user profile that can use Resolate.', 'resolate' ) . '</p>';
	}


	/**
	 * Render Alert Message Field.
	 *
	 * Outputs the HTML for the alert_message field.
	 */
	public function alert_message_render() {
		$options = get_option( 'resolate_settings', array() );
		$value   = isset( $options['alert_message'] ) ? wp_kses_post( $options['alert_message'] ) : '';
		echo '<textarea name="resolate_settings[alert_message]" class="large-text" rows="5">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Enter the alert message to display as a banner. Supports HTML. Leave empty to hide.', 'resolate' ) . '</p>';
	}

	/**
	 * Render Alert Color Field.
	 *
	 * Outputs the HTML for the alert_color field.
	 */
	public function alert_color_render() {
		$options = get_option( 'resolate_settings', array() );
		$color   = isset( $options['alert_color'] ) ? $options['alert_color'] : 'info';

		$colors = array(
			'success' => 'Success',
			'danger'  => 'Danger',
			'warning' => 'Warning',
			'info'    => 'Info',
		);

		foreach ( $colors as $value => $label ) {
			echo '<label style="margin-right: 15px;">';
			echo '<input type="radio" name="resolate_settings[alert_color]" value="' . esc_attr( $value ) . '" ' . checked( $color, $value, false ) . '>';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Select the color for the alert message.', 'resolate' ) . '</p>';
	}

	/**
	 * Handle Clear All Data.
	 *
	 * Handles the clearing of all Resolate data.
	 */
	public function handle_clear_all_data() {
		if ( isset( $_POST['resolate_clear_all_data'] ) && check_admin_referer( 'resolate_clear_all_data_action', 'resolate_clear_all_data_nonce' ) ) {

			// Delete all Resolate custom post types and taxonomies.
			$custom_post_types = array( 'resolate_task' );
			foreach ( $custom_post_types as $post_type ) {
				$posts = get_posts(
					array(
						'post_type'   => $post_type,
						'numberposts' => -1,
						'post_status' => array( 'publish', 'archived' ),
					)
				);
				foreach ( $posts as $post ) {
					wp_delete_post( $post->ID, true );
				}
			}

			// Delete all Resolate taxonomies.
			$taxonomies = array( 'resolate_board', 'resolate_label' );
			foreach ( $taxonomies as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
					)
				);
				foreach ( $terms as $term ) {
					wp_delete_term( $term->term_id, $taxonomy );
				}
			}

			// Redirect and terminate execution.
			$redirect_url = add_query_arg(
				array(
					'page'                => 'resolate_settings',
					'resolate_data_cleared' => 'true',
				),
				admin_url( 'options-general.php' )
			);

			$this->redirect_and_exit( $redirect_url );

		}
	}

	/**
	 * Redirect and Exit.
	 *
	 * Handles the redirection and termination of execution.
	 *
	 * @param string $url URL to redirect to.
	 */
	protected function redirect_and_exit( $url ) {
		wp_redirect( $url );
		exit;
	}

	/**
	 * Define Hooks.
	 *
	 * Registers all the hooks related to the settings page.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'handle_clear_all_data' ) );
	}

	/**
	 * Create Menu.
	 *
	 * Adds the settings page to the admin menu.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Resolate Settings', 'resolate' ),
			__( 'Resolate', 'resolate' ),
			'manage_options',
			'resolate_settings',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Settings Initialization.
	 *
	 * Registers settings and adds settings sections and fields.
	 */
    public function settings_init() {
        register_setting( 'resolate', 'resolate_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'resolate_main_section',
			__( 'Resolate Configuration', 'resolate' ),
			array( $this, 'settings_section_callback' ),
			'resolate'
		);

        $fields = array(
            'alert_color'           => __( 'Alert Color', 'resolate' ),
            'alert_message'         => __( 'Alert Message', 'resolate' ),
            'minimum_user_profile'  => __( 'Minimum User Profile', 'resolate' ),
            'conversion_engine'     => __( 'Motor de conversión', 'resolate' ),
            'collabora_base_url'    => __( 'URL de Collabora Online', 'resolate' ),
            'collabora_lang'        => __( 'Idioma para Collabora', 'resolate' ),
            'collabora_disable_ssl' => __( 'Omitir verificación SSL (Collabora)', 'resolate' ),

            // Document appearance settings.
            'doc_font_family'       => __( 'Fuente del documento', 'resolate' ),
            'doc_font_size'         => __( 'Tamaño de fuente (pt)', 'resolate' ),
            'doc_logo'              => __( 'Logo del documento', 'resolate' ),
            'doc_logo_right'        => __( 'Logo derecho (todas las páginas)', 'resolate' ),
            'doc_logo_left_width'   => __( 'Ancho logo izquierdo (px)', 'resolate' ),
            'doc_logo_right_width'  => __( 'Ancho logo derecho (px)', 'resolate' ),
            'doc_margin_text'       => __( 'Texto en margen izquierdo (1ª página)', 'resolate' ),
            'doc_margins'           => __( 'Márgenes de página (cm)', 'resolate' ),
            'odt_template'          => __( 'Plantilla ODT (OpenTBS)', 'resolate' ),
            'docx_template'         => __( 'Plantilla DOCX (OpenTBS)', 'resolate' ),
        );

		foreach ( $fields as $field_id => $field_title ) {
			add_settings_field(
				$field_id,
				$field_title,
				array( $this, $field_id . '_render' ),
				'resolate',
				'resolate_main_section'
			);
		}
	}

	/**
	 * Settings Section Callback.
	 *
	 * Outputs a description for the settings section.
	 */
    public function settings_section_callback() {
        echo '<p>' . esc_html__( 'Configura las opciones del plugin Resolate.', 'resolate' ) . '</p>';
    }




	/**
	 * Render Clear All Data Button.
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
	/**
	 * Render Ignored Users Field.
	 *
	 * Outputs the HTML for the ignored_users field.
	 */
	public function ignored_users_render() {
		$options = get_option( 'resolate_settings', array() );
		$value = isset( $options['ignored_users'] ) ? sanitize_text_field( $options['ignored_users'] ) : '';
		echo '<input type="text" name="resolate_settings[ignored_users]" class="regular-text" value="' . esc_attr( $value ) . '" pattern="^[0-9]+(,[0-9]+)*$" title="' . esc_attr__( 'Please enter comma-separated user IDs (numbers only)', 'resolate' ) . '">';
		echo '<p class="description">' . esc_html__( 'Enter comma-separated user IDs to ignore from Resolate functionality.', 'resolate' ) . '</p>';
	}

	/**
	 * Render Clear All Data Button.
	 *
	 * Outputs the HTML for the clear_all_data_button field.
	 */
    public function clear_all_data_button_render() {
        wp_nonce_field( 'resolate_clear_all_data_action', 'resolate_clear_all_data_nonce', true, true );
        echo '<input type="submit" name="resolate_clear_all_data" class="button button-secondary" style="background-color: red; color: white;" value="' . esc_attr__( 'Clear All Data', 'resolate' ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all Resolate records? This action cannot be undone.', 'resolate' ) ) . '\');">';
        echo '<p class="description">' . esc_html__( 'Click the button to delete all Resolate labels, tasks, and boards.', 'resolate' ) . '</p>';
    }

    /**
     * Render Conversion Engine selector.
     */
    public function conversion_engine_render() {
        $options = get_option( 'resolate_settings', array() );
        $current = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : 'collabora';

        $engines = array(
            'collabora' => __( 'Servicio web Collabora Online', 'resolate' ),
            'wasm'      => __( 'LibreOffice WASM en el navegador (experimental)', 'resolate' ),
        );

        echo '<fieldset>';
        foreach ( $engines as $value => $label ) {
            echo '<label style="display:block;margin-bottom:6px;">';
            echo '<input type="radio" name="resolate_settings[conversion_engine]" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . '> ';
            echo esc_html( $label );
            echo '</label>';
        }
        echo '<p class="description">' . esc_html__( 'Define si las conversiones se realizan a través de Collabora Online (predeterminado) o con LibreOffice WASM en el navegador (experimental).', 'resolate' ) . '</p>';
        echo '</fieldset>';
    }

    /**
     * Render Collabora base URL field.
     */
    public function collabora_base_url_render() {
        $options = get_option( 'resolate_settings', array() );
        $value   = isset( $options['collabora_base_url'] ) ? esc_url( $options['collabora_base_url'] ) : '';
        if ( '' === $value && defined( 'RESOLATE_COLLABORA_DEFAULT_URL' ) ) {
            $value = esc_url( RESOLATE_COLLABORA_DEFAULT_URL );
        }

        echo '<input type="url" class="regular-text" name="resolate_settings[collabora_base_url]" value="' . esc_attr( $value ) . '" placeholder="https://example.com">';
        echo '<p class="description">' . esc_html__( 'Ejemplo: https://demo.collaboraonline.com', 'resolate' ) . '</p>';
    }

    /**
     * Render Collabora language field.
     */
    public function collabora_lang_render() {
        $options = get_option( 'resolate_settings', array() );
        $value   = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'es-ES';

        echo '<input type="text" class="regular-text" name="resolate_settings[collabora_lang]" value="' . esc_attr( $value ) . '" placeholder="es-ES">';
        echo '<p class="description">' . esc_html__( 'Código de idioma que se enviará a Collabora Online (por defecto es-ES).', 'resolate' ) . '</p>';
    }

    /**
     * Render Collabora SSL verification toggle.
     */
    public function collabora_disable_ssl_render() {
        $options = get_option( 'resolate_settings', array() );
        $checked = isset( $options['collabora_disable_ssl'] ) && '1' === $options['collabora_disable_ssl'];

        echo '<label>';
        echo '<input type="checkbox" name="resolate_settings[collabora_disable_ssl]" value="1" ' . checked( $checked, true, false ) . '> ';
        echo esc_html__( 'Desactivar la comprobación de certificados SSL (usar solo en entornos de pruebas).', 'resolate' );
        echo '</label>';
    }

    /**
     * Render Document Font Family field.
     */
    public function doc_font_family_render() {
        $options = get_option( 'resolate_settings', array() );
        $current = isset( $options['doc_font_family'] ) ? sanitize_text_field( $options['doc_font_family'] ) : 'Times New Roman';

        $fonts = array(
            'Times New Roman' => 'Times New Roman',
            'Arial'           => 'Arial',
            'Calibri'         => 'Calibri',
            'Georgia'         => 'Georgia',
            'Garamond'        => 'Garamond',
            'Cambria'         => 'Cambria',
            'Helvetica'       => 'Helvetica',
            'Verdana'         => 'Verdana',
        );

        echo '<select name="resolate_settings[doc_font_family]" class="regular-text">';
        foreach ( $fonts as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Predeterminado: Times New Roman.', 'resolate' ) . '</p>';
    }

    /**
     * Render Document Font Size field.
     */
    public function doc_font_size_render() {
        $options = get_option( 'resolate_settings', array() );
        $current = isset( $options['doc_font_size'] ) ? intval( $options['doc_font_size'] ) : 12;
        echo '<input type="number" min="8" max="48" step="1" name="resolate_settings[doc_font_size]" value="' . esc_attr( $current ) . '" class="small-text">';
        echo '<span style="margin-left:8px;">pt</span>';
        echo '<p class="description">' . esc_html__( 'Predeterminado: 12 pt.', 'resolate' ) . '</p>';
    }

    /**
     * Render Document Logo field with media selector.
     */
    public function doc_logo_render() {
        $options = get_option( 'resolate_settings', array() );
        $attachment_id = isset( $options['doc_logo_id'] ) ? intval( $options['doc_logo_id'] ) : 0;
        $image_url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

        echo '<div id="resolate-doc-logo-field">';
        echo '<input type="hidden" id="resolate_doc_logo_id" name="resolate_settings[doc_logo_id]" value="' . esc_attr( $attachment_id ) . '">';
        echo '<div id="resolate_doc_logo_preview" style="margin:8px 0;">';
        if ( $image_url ) {
            echo '<img src="' . esc_url( $image_url ) . '" style="max-width:220px;height:auto;display:block;"/>';
        }
        echo '</div>';
        echo '<button type="button" class="button" id="resolate_doc_logo_select">' . esc_html__( 'Seleccionar logo', 'resolate' ) . '</button> ';
        echo '<button type="button" class="button" id="resolate_doc_logo_remove">' . esc_html__( 'Quitar', 'resolate' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Selecciona la imagen del logo que se incluirá en la parte superior de los documentos generados.', 'resolate' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render Right Logo field with media selector.
     */
    public function doc_logo_right_render() {
        $options = get_option( 'resolate_settings', array() );
        $attachment_id = isset( $options['doc_logo_right_id'] ) ? intval( $options['doc_logo_right_id'] ) : 0;
        $image_url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';

        echo '<div id="resolate-doc-logo-right-field">';
        echo '<input type="hidden" id="resolate_doc_logo_right_id" name="resolate_settings[doc_logo_right_id]" value="' . esc_attr( $attachment_id ) . '">';
        echo '<div id="resolate_doc_logo_right_preview" style="margin:8px 0;">';
        if ( $image_url ) {
            echo '<img src="' . esc_url( $image_url ) . '" style="max-width:220px;height:auto;display:block;"/>';
        }
        echo '</div>';
        echo '<button type="button" class="button" id="resolate_doc_logo_right_select">' . esc_html__( 'Seleccionar logo derecho', 'resolate' ) . '</button> ';
        echo '<button type="button" class="button" id="resolate_doc_logo_right_remove">' . esc_html__( 'Quitar', 'resolate' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Logo que aparecerá alineado a la derecha en todas las páginas.', 'resolate' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render Left Margin Text field.
     */
    public function doc_margin_text_render() {
        $options = get_option( 'resolate_settings', array() );
        $value   = isset( $options['doc_margin_text'] ) ? wp_kses_post( $options['doc_margin_text'] ) : '';
        echo '<textarea name="resolate_settings[doc_margin_text]" class="large-text" rows="3">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">' . esc_html__( 'Texto vertical que se mostrará en el margen izquierdo de la primera página.', 'resolate' ) . '</p>';
    }

    /**
     * Render Page Margins (cm).
     */
    public function doc_margins_render() {
        $options = get_option( 'resolate_settings', array() );
        $top    = isset( $options['doc_margin_top'] ) ? (float) $options['doc_margin_top'] : 2.0;
        $right  = isset( $options['doc_margin_right'] ) ? (float) $options['doc_margin_right'] : 2.0;
        $bottom = isset( $options['doc_margin_bottom'] ) ? (float) $options['doc_margin_bottom'] : 4.1;
        $left   = isset( $options['doc_margin_left'] ) ? (float) $options['doc_margin_left'] : 2.0;
        echo '<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">';
        echo '<label> ' . esc_html__( 'Arriba', 'resolate' ) . ' <input type="number" step="0.1" min="0" name="resolate_settings[doc_margin_top]" value="' . esc_attr( $top ) . '" class="small-text"></label>';
        echo '<label> ' . esc_html__( 'Derecha', 'resolate' ) . ' <input type="number" step="0.1" min="0" name="resolate_settings[doc_margin_right]" value="' . esc_attr( $right ) . '" class="small-text"></label>';
        echo '<label> ' . esc_html__( 'Abajo', 'resolate' ) . ' <input type="number" step="0.1" min="0" name="resolate_settings[doc_margin_bottom]" value="' . esc_attr( $bottom ) . '" class="small-text"></label>';
        echo '<label> ' . esc_html__( 'Izquierda', 'resolate' ) . ' <input type="number" step="0.1" min="0" name="resolate_settings[doc_margin_left]" value="' . esc_attr( $left ) . '" class="small-text"></label>';
        echo '<span>cm</span>';
        echo '</div>';
        echo '<p class="description">' . esc_html__( 'Predeterminado: 2, 2, 4.1, 2 (Arriba, Derecha, Abajo, Izquierda) cm.', 'resolate' ) . '</p>';
    }

    /**
     * Render ODT Template field (media selector restricted to .odt).
     */
    public function odt_template_render() {
        $options = get_option( 'resolate_settings', array() );
        $attachment_id = isset( $options['odt_template_id'] ) ? intval( $options['odt_template_id'] ) : 0;
        $file_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

        echo '<div id="resolate-odt-template-field">';
        echo '<input type="hidden" id="resolate_odt_template_id" name="resolate_settings[odt_template_id]" value="' . esc_attr( $attachment_id ) . '">';
        echo '<div id="resolate_odt_template_preview" style="margin:8px 0;">';
        if ( $file_url ) {
            echo '<a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">' . esc_html( basename( $file_url ) ) . '</a>';
        }
        echo '</div>';
        echo '<button type="button" class="button" id="resolate_odt_template_select">' . esc_html__( 'Seleccionar plantilla ODT', 'resolate' ) . '</button> ';
        echo '<button type="button" class="button" id="resolate_odt_template_remove">' . esc_html__( 'Quitar', 'resolate' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Sube una plantilla .odt con marcadores OpenTBS. Campos: [title], [objeto], [antecedentes], [fundamentos], [dispositivo], [firma], [margen].', 'resolate' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render DOCX Template field (media selector restricted to .docx).
     */
    public function docx_template_render() {
        $options = get_option( 'resolate_settings', array() );
        $attachment_id = isset( $options['docx_template_id'] ) ? intval( $options['docx_template_id'] ) : 0;
        $file_url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';

        echo '<div id="resolate-docx-template-field">';
        echo '<input type="hidden" id="resolate_docx_template_id" name="resolate_settings[docx_template_id]" value="' . esc_attr( $attachment_id ) . '">';
        echo '<div id="resolate_docx_template_preview" style="margin:8px 0;">';
        if ( $file_url ) {
            echo '<a href="' . esc_url( $file_url ) . '" target="_blank" rel="noopener">' . esc_html( basename( $file_url ) ) . '</a>';
        }
        echo '</div>';
        echo '<button type="button" class="button" id="resolate_docx_template_select">' . esc_html__( 'Seleccionar plantilla DOCX', 'resolate' ) . '</button> ';
        echo '<button type="button" class="button" id="resolate_docx_template_remove">' . esc_html__( 'Quitar', 'resolate' ) . '</button>';
        echo '<p class="description">' . esc_html__( 'Sube una plantilla .docx con marcadores OpenTBS. Campos: [title], [objeto], [antecedentes], [fundamentos], [dispositivo], [firma], [margen].', 'resolate' ) . '</p>';
        echo '</div>';
    }

    /**
     * Render Left Logo width (px).
     */
    public function doc_logo_left_width_render() {
        $options = get_option( 'resolate_settings', array() );
        $val = isset( $options['doc_logo_left_width'] ) ? intval( $options['doc_logo_left_width'] ) : 220;
        echo '<input type="number" min="40" max="600" step="10" name="resolate_settings[doc_logo_left_width]" value="' . esc_attr( $val ) . '" class="small-text">';
        echo '<span style="margin-left:6px;">px</span>';
        echo '<p class="description">' . esc_html__( 'Ancho del logo que aparece en la primera página (izquierda).', 'resolate' ) . '</p>';
    }

    /**
     * Render Right Logo width (px).
     */
    public function doc_logo_right_width_render() {
        $options = get_option( 'resolate_settings', array() );
        $val = isset( $options['doc_logo_right_width'] ) ? intval( $options['doc_logo_right_width'] ) : 160;
        echo '<input type="number" min="40" max="600" step="10" name="resolate_settings[doc_logo_right_width]" value="' . esc_attr( $val ) . '" class="small-text">';
        echo '<span style="margin-left:6px;">px</span>';
        echo '<p class="description">' . esc_html__( 'Ancho del logo que aparece en todas las páginas (derecha).', 'resolate' ) . '</p>';
    }

	/**
	 * Options Page.
	 *
	 * Renders the settings page.
	 */
	public function options_page() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'resolate' );
			do_settings_sections( 'resolate' );
			submit_button();
			?>
		</form>
		<?php
	}

	/**
	 * Admin Notices.
	 *
	 * Displays admin notices.
	 */
	public function admin_notices() {
		if ( isset( $_GET['resolate_data_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All Resolate records have been deleted.', 'resolate' ) . '</p></div>';
		}

		$invalid_user_ids = get_transient( 'resolate_invalid_user_ids' );
		if ( false !== $invalid_user_ids ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				sprintf(
					// Translators: %s is a list of invalid user IDs that have been removed.
					esc_html__( 'The following user IDs were invalid and have been removed: %s', 'resolate' ),
					esc_html( implode( ', ', $invalid_user_ids ) )
				) .
				'</p></div>';
			delete_transient( 'resolate_invalid_user_ids' );
		}
	}

	/**
	 * Settings Validation.
	 *
	 * Validates the settings fields.
	 *
	 * @param array $input The input fields to validate.
	 * @return array The validated fields.
	 */
    public function settings_validate( $input ) {

		// Validate shared key.
		$input['shared_key'] = isset( $input['shared_key'] ) ? sanitize_text_field( $input['shared_key'] ) : '';

		// Validate allow email notifications.
		$input['allow_email_notifications'] = isset( $input['allow_email_notifications'] ) && '1' === $input['allow_email_notifications'] ? '1' : '0';

                // Validate alert color.
                $valid_colors = array( 'success', 'danger', 'warning', 'info' );
                if ( isset( $input['alert_color'] ) && ! in_array( $input['alert_color'], $valid_colors ) ) {
                        $input['alert_color'] = 'info'; // Default to info if invalid.
                } else {
                        $input['alert_color'] = isset( $input['alert_color'] ) ? $input['alert_color'] : 'info';
                }

        // Validate conversion engine.
        $valid_engines = array( 'wasm', 'collabora' );
        $engine        = isset( $input['conversion_engine'] ) ? sanitize_key( $input['conversion_engine'] ) : 'collabora';
        if ( ! in_array( $engine, $valid_engines, true ) ) {
            $engine = 'collabora';
        }
        $input['conversion_engine'] = $engine;

        // Validate Collabora settings.
        $base_url = isset( $input['collabora_base_url'] ) ? trim( (string) $input['collabora_base_url'] ) : '';
        $input['collabora_base_url'] = '' === $base_url ? '' : untrailingslashit( esc_url_raw( $base_url ) );

        $lang = isset( $input['collabora_lang'] ) ? sanitize_text_field( $input['collabora_lang'] ) : 'es-ES';
        if ( '' === $lang ) {
            $lang = 'es-ES';
        }
        $input['collabora_lang'] = $lang;

        $input['collabora_disable_ssl'] = isset( $input['collabora_disable_ssl'] ) && '1' === $input['collabora_disable_ssl'] ? '1' : '0';

                // Validate user profile.
                $roles = wp_roles()->get_names();
                if ( isset( $input['minimum_user_profile'] ) && ! array_key_exists( $input['minimum_user_profile'], $roles ) ) {
			$input['minimum_user_profile'] = 'editor'; // Default to editor if invalid.
		} else {
			$input['minimum_user_profile'] = isset( $input['minimum_user_profile'] ) ? $input['minimum_user_profile'] : 'editor';
		}

        // Validate alert message.
        $input['alert_message'] = isset( $input['alert_message'] ) ? wp_kses_post( $input['alert_message'] ) : '';

        // Validate document font settings.
        $allowed_fonts = array( 'Times New Roman', 'Arial', 'Calibri', 'Georgia', 'Garamond', 'Cambria', 'Helvetica', 'Verdana' );
        $input['doc_font_family'] = ( isset( $input['doc_font_family'] ) && in_array( $input['doc_font_family'], $allowed_fonts, true ) ) ? $input['doc_font_family'] : 'Times New Roman';
        $size = isset( $input['doc_font_size'] ) ? intval( $input['doc_font_size'] ) : 12;
        if ( $size < 8 ) { $size = 8; }
        if ( $size > 48 ) { $size = 48; }
        $input['doc_font_size'] = $size;

        // Validate logo attachment IDs.
        $logo_id = isset( $input['doc_logo_id'] ) ? intval( $input['doc_logo_id'] ) : 0;
        $input['doc_logo_id'] = ( $logo_id > 0 && get_post( $logo_id ) ) ? $logo_id : 0;
        $logo_r_id = isset( $input['doc_logo_right_id'] ) ? intval( $input['doc_logo_right_id'] ) : 0;
        $input['doc_logo_right_id'] = ( $logo_r_id > 0 && get_post( $logo_r_id ) ) ? $logo_r_id : 0;

        // Validate margins.
        $input['doc_margin_top']    = isset( $input['doc_margin_top'] ) ? max( 0, (float) $input['doc_margin_top'] ) : 2.0;
        $input['doc_margin_right']  = isset( $input['doc_margin_right'] ) ? max( 0, (float) $input['doc_margin_right'] ) : 2.0;
        $input['doc_margin_bottom'] = isset( $input['doc_margin_bottom'] ) ? max( 0, (float) $input['doc_margin_bottom'] ) : 4.1;
        $input['doc_margin_left']   = isset( $input['doc_margin_left'] ) ? max( 0, (float) $input['doc_margin_left'] ) : 2.0;

        // Margin text safe HTML to plain text for Word output.
        $input['doc_margin_text'] = isset( $input['doc_margin_text'] ) ? wp_kses_post( $input['doc_margin_text'] ) : '';

        // Validate logo widths.
        $lw = isset( $input['doc_logo_left_width'] ) ? intval( $input['doc_logo_left_width'] ) : 220;
        $rw = isset( $input['doc_logo_right_width'] ) ? intval( $input['doc_logo_right_width'] ) : 160;
        $input['doc_logo_left_width']  = max( 40, min( 600, $lw ) );
        $input['doc_logo_right_width'] = max( 40, min( 600, $rw ) );

        // Validate ODT template attachment ID (.odt files).
        $tpl_id = isset( $input['odt_template_id'] ) ? intval( $input['odt_template_id'] ) : 0;
        if ( $tpl_id > 0 ) {
            $mime = get_post_mime_type( $tpl_id );
            if ( 'application/vnd.oasis.opendocument.text' !== $mime ) {
                $tpl_id = 0;
            }
        }
        $input['odt_template_id'] = $tpl_id;

        // Validate DOCX template attachment ID (.docx files).
        $tplx_id = isset( $input['docx_template_id'] ) ? intval( $input['docx_template_id'] ) : 0;
        if ( $tplx_id > 0 ) {
            $mime = get_post_mime_type( $tplx_id );
            if ( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' !== $mime ) {
                $tplx_id = 0;
            }
        }
        $input['docx_template_id'] = $tplx_id;

        return $input;
    }
}
