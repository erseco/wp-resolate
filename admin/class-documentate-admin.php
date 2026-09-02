<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * @package    documentate
 * @subpackage Documentate/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    documentate
 * @subpackage Documentate/admin
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Documentate_Admin {
	/**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version     The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version = $version;

		$this->load_dependencies();
		add_filter( 'plugin_action_links_' . plugin_basename( DOCUMENTATE_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add settings link to the plugins page.
	 *
	 * @param array $links The existing links.
	 * @return array The modified links.
	 */
	public function add_settings_link( $links ) {
		$settings_link =
			'<a href="'
			. admin_url( 'options-general.php?page=documentate_settings' )
			. '">'
			. 'Ajustes'
			. '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Load the required dependencies for this class.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-documentate-admin-settings.php';

		if ( ! has_action( 'admin_menu', array( 'Documentate_Admin_Settings', 'create_menu' ) ) ) {
			new Documentate_Admin_Settings();
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( ! $this->is_documentate_screen( $hook_suffix ) ) {
			return;
		}
		wp_enqueue_style(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . 'css/documentate-admin.css',
			array(),
			$this->version,
			'all',
		);
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( ! $this->is_documentate_screen( $hook_suffix ) ) {
			return;
		}
		if ( 'settings_page_documentate_settings' === $hook_suffix ) {
			wp_enqueue_media();
		}
		wp_enqueue_script(
			$this->plugin_name,
			plugin_dir_url( __FILE__ ) . 'js/documentate-admin.js',
			array( 'jquery' ),
			$this->version,
			true,
		);
	}

	/**
	 * Check if the current screen is a documentate admin page.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 * @return bool
	 */
	private function is_documentate_screen( $hook_suffix ) {
		if ( 'settings_page_documentate_settings' === $hook_suffix ) {
			return true;
		}

		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen = get_current_screen();
			if ( $screen && 'documentate_document' === $screen->post_type ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if collaborative editing is enabled.
	 *
	 * @return bool
	 */
	public static function is_collaborative_enabled() {
		$options = get_option( 'documentate_settings', array() );
		return isset( $options['collaborative_enabled'] ) && '1' === $options['collaborative_enabled'];
	}

	/**
	 * Get collaborative editor settings.
	 *
	 * @return array
	 */
	public static function get_collaborative_settings() {
		$options = get_option( 'documentate_settings', array() );
		return array(
			'enabled' => self::is_collaborative_enabled(),
			'signalingServer' => isset( $options['collaborative_signaling'] ) && '' !== $options['collaborative_signaling']
				? $options['collaborative_signaling']
				: 'wss://signaling.yjs.dev',
		);
	}

	/**
	 * Enqueue collaborative editor assets for document edit screens.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_collaborative_editor( $hook_suffix ) {
		// Only on post edit screens.
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Only for documentate_document post type.
		$screen = get_current_screen();
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		// Only if collaborative editing is enabled.
		if ( ! self::is_collaborative_enabled() ) {
			return;
		}

		$settings = self::get_collaborative_settings();
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;

		// Get current user info.
		$current_user = wp_get_current_user();
		$user_name = $current_user->display_name ? $current_user->display_name : $current_user->user_login;

		// Enqueue styles.
		wp_enqueue_style(
			'documentate-collaborative-editor',
			plugin_dir_url( __FILE__ ) . 'css/documentate-collaborative-editor.css',
			array(),
			$this->version,
		);

		// Enqueue script as module.
		wp_enqueue_script(
			'documentate-collaborative-editor',
			plugin_dir_url( __FILE__ ) . 'js/documentate-collaborative-editor.js',
			array(),
			$this->version,
			array(
				'in_footer' => true,
				'strategy' => 'defer',
			),
		);

		// Add module type to script.
		add_filter( 'script_loader_tag', array( $this, 'add_module_type_to_collaborative_script' ), 10, 3 );

		// Pass settings to JavaScript.
		wp_localize_script(
			'documentate-collaborative-editor',
			'documentateCollaborative',
			array(
				'postId' => $post_id,
				'signalingServer' => $settings['signalingServer'],
				'userName' => $user_name,
				'userId' => $current_user->ID,
				'siteUrl' => get_site_url(),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'documentate_collab_avatars' ),
				'userAvatar' => get_avatar_url( $current_user->ID, array( 'size' => 32 ) ),
			)
		);
	}

	/**
	 * Add type="module" to collaborative editor script tag.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string Modified script tag.
	 */
	public function add_module_type_to_collaborative_script( $tag, $handle, $src ) {
		if ( 'documentate-collaborative-editor' === $handle ) {
			$tag = str_replace( '<script ', '<script type="module" ', $tag );
		}
		return $tag;
	}

	/**
	 * Register the collaborative status meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function register_collaborative_status_metabox( $post ) {
		// Only if collaborative editing is enabled.
		if ( ! self::is_collaborative_enabled() ) {
			return;
		}

		// Only for saved documents (post_id > 0).
		if ( ! $post || $post->ID <= 0 ) {
			return;
		}

		add_meta_box(
			'documentate_collaborative_status',
			'Modo colaborativo',
			array( $this, 'render_collaborative_status_metabox' ),
			'documentate_document',
			'side',
			'high',
		);
	}

	/**
	 * Render the collaborative status meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_collaborative_status_metabox( $post ) {
		?>
		<div id="documentate-collab-status-metabox" class="documentate-collab-metabox">
			<div class="documentate-collab-metabox__status" data-status="connecting">
				<span class="documentate-collab-metabox__indicator"></span>
				<span class="documentate-collab-metabox__label"><?php echo esc_html( 'Conectando...' ); ?></span>
				<div class="documentate-collab-metabox__avatars"></div>
			</div>
			<div class="documentate-collab-metabox__retries" style="display: none;">
				<span class="documentate-collab-metabox__retry-count">0</span>/5 <?php echo esc_html( 'reintentos' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to get user avatars by IDs.
	 */
	public function ajax_get_user_avatars() {
		check_ajax_referer( 'documentate_collab_avatars', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error(
				array( 'message' => 'No estás autorizado para acceder a este recurso.' ),
				403,
			);
		}

		$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'intval', (array) $_POST['user_ids'] ) : array();
		$avatars = array();

		foreach ( $user_ids as $user_id ) {
			if ( $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user ) {
					$avatars[ $user_id ] = array(
						'name' => $user->display_name,
						'avatar' => get_avatar_url( $user_id, array( 'size' => 32 ) ),
					);
				}
			}
		}

		wp_send_json_success( $avatars );
	}

	/**
	 * Disable post locking dialog for collaborative documents.
	 *
	 * @param bool    $show Whether to show the dialog.
	 * @param WP_Post $post The post object.
	 * @param WP_User $user The user who has the lock.
	 * @return bool
	 */
	public function disable_post_lock_dialog( $show, $post, $user ) {
		if ( 'documentate_document' === $post->post_type && self::is_collaborative_enabled() ) {
			return false;
		}
		return $show;
	}

	/**
	 * Disable post lock window check for collaborative documents.
	 *
	 * @param int $window Lock window in seconds.
	 * @return int|false
	 */
	public function disable_post_lock_window( $window ) {
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && 'documentate_document' === $post->post_type && self::is_collaborative_enabled() ) {
				return false;
			}
		}
		return $window;
	}

	/**
	 * Prevent post lock from being set for collaborative documents.
	 *
	 * @param array|bool $lock The lock data or false.
	 * @param int        $post_id The post ID.
	 * @return array|bool
	 */
	public function disable_post_lock( $lock, $post_id ) {
		$post = get_post( $post_id );
		if ( $post && 'documentate_document' === $post->post_type && self::is_collaborative_enabled() ) {
			return false;
		}
		return $lock;
	}

	/**
	 * Remove post lock when editing collaborative documents.
	 * Runs on admin_init to delete locks BEFORE the dialog is rendered.
	 */
	public function remove_post_lock_for_collaborative() {
		global $pagenow;

		// Only on post edit screens.
		if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Check post type via query param (get_current_screen() not available yet).
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || 'documentate_document' !== $post->post_type ) {
				return;
			}
		} else {
			// post-new.php - check post_type param.
			$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post';
			if ( 'documentate_document' !== $post_type ) {
				return;
			}
		}

		if ( ! self::is_collaborative_enabled() ) {
			return;
		}

		// Delete the lock BEFORE the dialog is rendered.
		if ( $post_id > 0 ) {
			delete_post_meta( $post_id, '_edit_lock' );
		}
	}

	/**
	 * Deregister heartbeat script for collaborative documents.
	 * This prevents WordPress from setting post locks via wp_refresh_post_lock().
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function deregister_heartbeat_for_collaborative( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		if ( ! self::is_collaborative_enabled() ) {
			return;
		}

		// Deregister heartbeat completely - our Yjs handles collaboration.
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Enqueue assets for revision diff view enhancement.
	 *
	 * Replaces raw HTML comment markers with styled field badges
	 * in the WordPress revisions comparison screen.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_revisions_assets( $hook_suffix ) {
		// Check if we're on the revision screen or document edit screen.
		if ( 'revision.php' !== $hook_suffix && 'post.php' !== $hook_suffix ) {
			return;
		}

		// On post.php, only load for our post type.
		if ( 'post.php' === $hook_suffix ) {
			$screen = get_current_screen();
			if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
				return;
			}
		}

		// For revision.php, check if it's a revision of our post type.
		if ( 'revision.php' === $hook_suffix ) {
			$revision_id = isset( $_GET['revision'] ) ? intval( $_GET['revision'] ) : 0;
			if ( $revision_id > 0 ) {
				$revision = wp_get_post_revision( $revision_id );
				if ( $revision ) {
					$parent = get_post( $revision->post_parent );
					if ( ! $parent || 'documentate_document' !== $parent->post_type ) {
						return;
					}
				}
			}
		}

		// Enqueue CSS (dashicons dependency for icons).
		wp_enqueue_style(
			'documentate-revisions',
			plugin_dir_url( __FILE__ ) . 'css/documentate-revisions.css',
			array( 'dashicons' ),
			$this->version,
		);

		// Enqueue JavaScript.
		wp_enqueue_script(
			'documentate-revisions',
			plugin_dir_url( __FILE__ ) . 'js/documentate-revisions.js',
			array(),
			$this->version,
			true,
		);

		// Get field labels for the current document type.
		$field_labels = $this->get_revision_field_labels();

		// Pass data to JavaScript.
		wp_localize_script(
			'documentate-revisions',
			'documentateRevisions',
			array(
				'fieldLabels' => $field_labels,
				'strings' => array(
					'fieldContent' => 'Contenido del campo ↓',
				),
			)
		);
	}

	/**
	 * Get field labels for revision display.
	 *
	 * Builds a map of field slugs to human-readable labels
	 * from the document type schema.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	private function get_revision_field_labels() {
		$labels = array(
			// Default labels for common fields.
			'post_title' => 'Título del documento',
			'post_content' => 'Contenido',
			'resolution_number' => 'Número de resolución',
			'date' => 'Fecha',
			'antecedentes' => 'Antecedentes',
			'fundamentos' => 'Fundamentos de derecho',
			'resuelve' => 'Resolución',
			'anexos' => 'Anexos',
			'firma' => 'Firma',
			'cargo' => 'Cargo',
			'lugar' => 'Lugar',
			'destinatario' => 'Destinatario',
			'asunto' => 'Asunto',
			'cuerpo' => 'Cuerpo',
			'saludo' => 'Saludo',
			'despedida' => 'Despedida',
		);

		// Try to get labels from the current revision's parent document type.
		$revision_id = isset( $_GET['revision'] ) ? intval( $_GET['revision'] ) : 0;
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;

		// Determine the parent post ID.
		$parent_id = 0;
		if ( $revision_id > 0 ) {
			$revision = wp_get_post_revision( $revision_id );
			if ( $revision ) {
				$parent_id = $revision->post_parent;
			}
		} elseif ( $post_id > 0 ) {
			$parent_id = $post_id;
		}

		if ( $parent_id > 0 ) {
			$schema_labels = $this->get_schema_labels_for_post( $parent_id );
			if ( ! empty( $schema_labels ) ) {
				$labels = array_merge( $labels, $schema_labels );
			}
		}

		/**
		 * Filter the field labels used in revision diff display.
		 *
		 * @param array<string,string> $labels    Map of slug => label.
		 * @param int                  $parent_id Parent document post ID.
		 */
		return apply_filters( 'documentate_revision_field_labels', $labels, $parent_id );
	}

	/**
	 * Get schema field labels for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string> Map of slug => label.
	 */
	private function get_schema_labels_for_post( $post_id ) {
		$labels = array();

		// Get the document type term.
		$terms = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $labels;
		}

		$term_id = intval( $terms[0] );

		// Get schema from the term.
		if ( ! class_exists( 'Documentate\\DocType\\SchemaStorage' ) ) {
			return $labels;
		}

		$storage = new \Documentate\DocType\SchemaStorage();
		$schema = $storage->get_schema( $term_id );

		// The schema groups its entries under `fields` and `repeaters`; both
		// carry the human readable name in `title`.
		foreach ( array( 'fields', 'repeaters' ) as $group ) {
			if ( empty( $schema[ $group ] ) || ! is_array( $schema[ $group ] ) ) {
				continue;
			}
			$labels = array_merge( $labels, $this->collect_schema_entry_labels( $schema[ $group ] ) );
		}

		return $labels;
	}

	/**
	 * Map the slug of every schema entry to its declared label.
	 *
	 * @param array<int,array<string,mixed>> $entries Schema fields or repeaters.
	 * @return array<string,string> Map of slug => label.
	 */
	private function collect_schema_entry_labels( array $entries ) {
		$labels = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['slug'] ) ) {
				continue;
			}

			$label = '';
			if ( ! empty( $entry['title'] ) ) {
				$label = (string) $entry['title'];
			} elseif ( ! empty( $entry['label'] ) ) {
				$label = (string) $entry['label'];
			}

			if ( '' !== $label ) {
				$labels[ sanitize_key( $entry['slug'] ) ] = $label;
			}
		}

		return $labels;
	}

	/**
	 * Whether the request renders document editors: the wp-admin document
	 * screen or the front-end application page. `get_current_screen()` does
	 * not exist on the front end, so it must not be assumed.
	 *
	 * @return bool
	 */
	private function is_document_editor_context() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && 'documentate_document' === $screen->post_type ) {
				return true;
			}
		}

		return class_exists( 'Documentate_App_Shell' ) && Documentate_App_Shell::is_app_page();
	}

	/**
	 * Register TinyMCE table and searchreplace plugins for document editors.
	 *
	 * @param array<string,string> $plugins Array of external TinyMCE plugins.
	 * @return array<string,string> Modified array of plugins.
	 */
	public function add_tinymce_table_plugin( $plugins ) {
		if ( $this->is_document_editor_context() ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			$plugins['table'] = plugin_dir_url( __FILE__ ) . 'mce/table/plugin' . $suffix . '.js';
			$plugins['searchreplace'] = plugin_dir_url( __FILE__ ) . 'mce/searchreplace/plugin' . $suffix . '.js';
		}
		return $plugins;
	}

	/**
	 * Configure TinyMCE table plugin options.
	 *
	 * @param array<string,mixed> $init TinyMCE init settings.
	 * @return array<string,mixed> Modified init settings.
	 */
	public function configure_tinymce_table_options( $init ) {
		if ( $this->is_document_editor_context() ) {
			$init['table_toolbar'] = false;
			$init['table_responsive_width'] = true;
			$init['table_resize_bars'] = true;
			$init['table_grid'] = true;
			$init['table_tab_navigation'] = true;
			$init['table_advtab'] = true;
			$init['table_cell_advtab'] = true;
			$init['table_row_advtab'] = true;

			// Paste filtering.
			$init['paste_remove_styles'] = true;
			$init['paste_remove_styles_if_webkit'] = true;
			$init['paste_strip_class_attributes'] = 'all';
			// Allow desired tags; preserve style/align on p, td, th for user-set formatting (e.g. text-align: justify).
			$init['valid_elements'] = 'a[href|title|target],strong/b,em/i,u,p[style|class|align],br,ul,ol,li,h1,h2,h3,h4,h5,h6,blockquote,code,pre,table[border|cellpadding|cellspacing|style|class|align],tr,td[colspan|rowspan|style|class|align],th[colspan|rowspan|style|class|align]';
			$init['invalid_elements'] = 'span,button,form,select,input,textarea,div,iframe,embed,object,label,font,img,video,audio,canvas,svg,script,style,noscript,map,area,applet';
		}
		return $init;
	}

	/**
	 * Enqueue assets for the document attachments meta box.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_attachments_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'documentate-attachments',
			plugin_dir_url( __FILE__ ) . 'css/documentate-attachments.css',
			array( 'dashicons' ),
			$this->version,
		);

		wp_enqueue_script(
			'documentate-attachments',
			plugin_dir_url( __FILE__ ) . 'js/documentate-attachments-metabox.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			$this->version,
			true,
		);

		wp_localize_script(
			'documentate-attachments',
			'documentateAttachments',
			array(
				'i18n' => array(
					'title' => 'Seleccionar archivos',
					'button' => 'Añadir al documento',
					'remove' => 'Eliminar',
				),
			)
		);
	}
}
