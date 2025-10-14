<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * @package    resolate
 * @subpackage Resolate/admin
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    resolate
 * @subpackage Resolate/admin
 * @author     Área de Tecnología Educativa <ate.educacion@gobiernodecanarias.org>
 */
class Resolate_Admin {

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
		$this->version     = $version;

		$this->load_dependencies();
		add_filter( 'plugin_action_links_' . plugin_basename( RESOLATE_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Add settings link to the plugins page.
	 *
	 * @param array $links The existing links.
	 * @return array The modified links.
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . admin_url( 'options-general.php?page=resolate_settings' ) . '">' . __( 'Settings', 'resolate' ) . '</a>';
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
		require_once plugin_dir_path( __DIR__ ) . 'admin/class-resolate-admin-settings.php';

		if ( ! has_action( 'admin_menu', array( 'Resolate_Admin_Settings', 'create_menu' ) ) ) {
			new Resolate_Admin_Settings();
		}
	}


	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'settings_page_resolate_settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/resolate-admin.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( 'settings_page_resolate_settings' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/resolate-admin.js', array( 'jquery' ), $this->version, true );
	}
}
