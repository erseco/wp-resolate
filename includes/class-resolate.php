<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    resolate
 * @subpackage Resolate/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package    resolate
 * @subpackage Resolate/includes
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Resolate {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @access   protected
	 * @var      Resolate_Loader    $loader    Maintains and registers all hooks for the plugin.
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
		$this->version = RESOLATE_VERSION;
		$this->plugin_name = 'resolate';

		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();

		// Hook demo data creation to init.
		add_action( 'init', array( $this, 'maybe_create_demo_data' ) );
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Resolate_Loader. Orchestrates the hooks of the plugin.
	 * - Resolate_Admin. Defines all hooks for the admin area.
	 * - Resolate_Public. Defines all hooks for the public side of the site.
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
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-loader.php';

		// Removed legacy AJAX handlers for Kanban/Tasks.

		/**
		 * The classes responsible for defining the custom-post-types.
		 */
		// Keep boards/labels for KB; remove tasks/events/actions (Kanban removal).
		// require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-resolate-boards.php';
		// require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-resolate-labels.php';

		// require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-resolate-kb.php';

		// Resolate: Documents CPT and taxonomies (non-breaking addition).
		require_once plugin_dir_path( __DIR__ ) . 'includes/custom-post-types/class-resolate-documents.php';

		// Removed email-to-post, mailer/notification, and calendar modules.

                require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-disable-comment-notifications.php';

		/**
		 * The class responsible for protecting comments on custom post types via the REST API.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-rest-comment-protection.php';

		/**
		 * The class responsible for defining the MVC.
		 */
		// Remove Task MVC models/managers; not needed for Resolate/KB.

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-resolate-admin.php';

		// Resolate admin helpers (row actions, exports for resolutions).
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-resolate-admin.php';

		// Admin UI for document types (taxonomy meta for templates, fields, etc.).
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-resolate-doc-types-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		// require_once plugin_dir_path( __DIR__ ) . 'public/class-resolate-public.php';

		$this->loader = new Resolate_Loader();
	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Resolate_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 10, 1 );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @access   private
	 */
	private function define_public_hooks() {

		// $plugin_public = new Resolate_Public( $this->get_plugin_name(), $this->get_version() );
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


	/**
	 * Check if the current user has at least the required role.
	 *
	 * @return bool True if the user has the required role or higher, false otherwise.
	 */
	public static function current_user_has_at_least_minimum_role() {
		// Get the saved user profile role from plugin options, default to 'editor'.
		$options = get_option( 'resolate_settings', array() );
		$required_role = isset( $options['minimum_user_profile'] ) ? $options['minimum_user_profile'] : 'editor';

		// WordPress role hierarchy, ordered from lowest to highest.
		$role_hierarchy = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

		// Determine the index of the required role.
		$required_index = array_search( $required_role, $role_hierarchy );

		if ( false === $required_index ) {
			// Invalid role in settings, fallback to the default.
			return false;
		}

		// Check each role of the current user.
		foreach ( wp_get_current_user()->roles as $user_role ) {
			$user_index = array_search( $user_role, $role_hierarchy );

			if ( false !== $user_index && $user_index >= $required_index ) {
				return true; // User has the required role or higher.
			}
		}

		return false; // User does not meet the minimum role requirement.
	}

	/**
	 * Create demo data if the version is 0.0.0
	 */
	public function maybe_create_demo_data() {

		// Demo task data disabled in Resolate.
		return;
	}
}
