<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @link       https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 *
 * @package    documentate
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

// Remove the revisión capability from the roles and forget its version.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-roles.php';
Documentate_Roles::remove_caps();

// The application page carries nothing but the shortcode, which would print
// itself as text to every visitor once the plugin is gone.
$documentate_page_id = (int) get_option( 'documentate_app_page_id' );
if ( $documentate_page_id > 0 && 'page' === get_post_type( $documentate_page_id ) ) {
	wp_delete_post( $documentate_page_id, true );
}

foreach (
	array(
		'documentate_app_page_id',
		'documentate_pdf_layout_assigned',
		'documentate_private_output_version',
		'documentate_seed_demo_documents',
	) as $documentate_option
) {
	delete_option( $documentate_option );
}

// Generated resolutions and propuestas carry personal and administrative data
// and live outside the media library, so nothing else would ever remove them.
// The directory itself is left in place: it is empty, and a host may well have
// its own rules file in there that is not ours to take away.
$documentate_uploads = wp_upload_dir();
if ( empty( $documentate_uploads['error'] ) ) {
	$documentate_output = trailingslashit( $documentate_uploads['basedir'] ) . 'documentate';

	if ( is_dir( $documentate_output ) && ! is_link( $documentate_output ) ) {
		foreach ( new DirectoryIterator( $documentate_output ) as $documentate_entry ) {
			if ( $documentate_entry->isDot() || $documentate_entry->isDir() ) {
				continue;
			}
			wp_delete_file( $documentate_entry->getPathname() );
		}
	}
}
