<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    documentate
 * @subpackage Documentate/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    documentate
 * @subpackage Documentate/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Documentate {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @access   protected
	 * @var      Documentate_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct() {
		$this->version = DOCUMENTATE_VERSION;
		$this->plugin_name = 'documentate';

		$this->load_dependencies();
		require_once __DIR__ . '/class-documentate-private-output.php';
		// The one-time hardening pass belongs to maintenance, not to page views;
		// every generation re-checks the guards through ensure_output_dir().
		add_action( 'admin_init', array( 'Documentate_Private_Output', 'upgrade' ) );
		// A type created before the native engine existed carries no layout.
		add_action( 'admin_init', array( 'Documentate_Pdf_Layout', 'assign_missing' ) );
		$this->define_admin_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Documentate_Loader. Orchestrates the hooks of the plugin.
	 * - Documentate_Admin. Defines all hooks for the admin area.
	 * - Documentate_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @access   private
	 */
	private function load_dependencies() {
		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-loader.php';

		/**
		 * Refactored document classes following Single Responsibility Principle.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-meta-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-cpt-registration.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-comments-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-revision-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-input-attributes.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-field-validator.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/documents/class-documents-field-renderer.php';

		/**
		 * Refactored OpenTBS classes.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/opentbs/class-opentbs-html-parser.php';

		/**
		 * Refactored export handlers.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/export/class-export-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/export/class-export-docx-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/export/class-export-odt-handler.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/export/class-export-pdf-handler.php';

		/**
		 * The classes responsible for defining the custom-post-types.
		 */
		// Documentate: Documents CPT and taxonomies.
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-field-help.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-scalar-field.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-repeater-field.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-content-writer.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-meta-boxes.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-meta-saver.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-document-admin-list.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-documentate-documents.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/document/meta/class-document-meta-box.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/document/meta/class-document-meta.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/document/meta/class-document-attachments-meta-box.php';

		// Schema extraction/storage services.
		require_once plugin_dir_path( __DIR__ ) . 'includes/doc-type/class-schemaextractor.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/doc-type/class-schemastorage.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/doc-type/class-schemaconverter.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-template-parser.php';

		// Document generator and templating helpers.
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-generator.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-opentbs.php';

		// The native PDF renderer loads on demand: it pulls in the vendored
		// FPDF, and most requests — the whole front end, and every request on
		// a site converting through Collabora — never draw a document.
		self::register_pdf_autoloader();

		if ( class_exists( '\Documentate\Document\Meta\Document_Meta_Box' ) ) {
			$document_meta_box = new \Documentate\Document\Meta\Document_Meta_Box();
			$document_meta_box->register();
		}

		if ( class_exists( '\Documentate\Document\Meta\Document_Attachments_Meta_Box' ) ) {
			$attachments_meta_box = new \Documentate\Document\Meta\Document_Attachments_Meta_Box();
			$attachments_meta_box->register();
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-disable-comment-notifications.php';

		/**
		 * The class responsible for sending email notifications on document state changes.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-notifications.php';

		/**
		 * The class responsible for protecting comments on custom post types via the REST API.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-rest-comment-protection.php';

		/**
		 * The class responsible for protecting document access from unauthorized users.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-access-protection.php';

		/**
		 * The class responsible for restricting template (doc_type) management to admins.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-template-access.php';

		/**
		 * The class responsible for filtering documents by user scope category.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-scope-filter.php';

		/**
		 * The class responsible for the user scope profile field.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-user-scope.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-documentate-admin.php';

		// Documentate admin helpers (row actions, exports for resolutions).
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-admin-helper.php';

		// Admin UI for document types (taxonomy meta for templates, fields, etc.).
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-documentate-doc-type-pdf-layout-field.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-documentate-doc-types-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-documentate-doctype-help-notice.php';

		// Workflow management (role-based restrictions, read-only published state).
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-workflow.php';
		new Documentate_Workflow();

		// Front-end application under /documentate/ (shell, list, detail, edit, new).
		require_once plugin_dir_path( __DIR__ ) . 'includes/app/class-documentate-app-shell.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/app/class-documentate-app-lista.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/app/class-documentate-app-detalle.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/app/class-documentate-app-editar.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/app/class-documentate-app.php';
		( new Documentate_App() )->register();

		$this->loader = new Documentate_Loader();
	}

	/**
	 * Load a `Documentate_Pdf_*` class from `includes/pdf/` when first named.
	 *
	 * The renderer is nine classes plus roughly two thousand lines of FPDF.
	 * Parsing them on every page view buys nothing, and the class names map
	 * to file names one to one.
	 */
	private static function register_pdf_autoloader() {
		spl_autoload_register(
			static function ( $class ) {
				if ( 0 !== strpos( $class, 'Documentate_Pdf_' ) ) {
					return;
				}

				$file = plugin_dir_path( __DIR__ ) . 'includes/pdf/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @access   private
	 */
	private function define_admin_hooks() {
		$plugin_admin = new Documentate_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_collaborative_editor', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_revisions_assets', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_attachments_assets', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'deregister_heartbeat_for_collaborative', 1, 1 );
		$this->loader->add_action( 'admin_init', $plugin_admin, 'remove_post_lock_for_collaborative', 1 );
		$this->loader->add_action(
			'add_meta_boxes_documentate_document',
			$plugin_admin,
			'register_collaborative_status_metabox',
			10,
			1,
		);
		$this->loader->add_action( 'wp_ajax_documentate_get_collab_avatars', $plugin_admin, 'ajax_get_user_avatars' );
		$this->loader->add_filter( 'show_post_locked_dialog', $plugin_admin, 'disable_post_lock_dialog', 10, 3 );
		$this->loader->add_filter( 'wp_check_post_lock', $plugin_admin, 'disable_post_lock', 10, 2 );
		$this->loader->add_filter( 'wp_check_post_lock_window', $plugin_admin, 'disable_post_lock_window', 10, 1 );

		// TinyMCE table plugin for document editors.
		$this->loader->add_filter( 'mce_external_plugins', $plugin_admin, 'add_tinymce_table_plugin', 10, 1 );
		$this->loader->add_filter( 'tiny_mce_before_init', $plugin_admin, 'configure_tinymce_table_options', 10, 1 );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
