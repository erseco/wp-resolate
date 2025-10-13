<?php
/**
 * WP-CLI commands for the Resolate plugin.
 *
 * @package Resolate
 * @subpackage Resolate/includes
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Custom WP-CLI commands for Resolate Plugin.
	 */
	class Resolate_WPCLI extends WP_CLI_Command {

		/**
		 * Say hello.
		 *
		 * ## OPTIONS
		 *
		 * [--name=<name>]
		 * : The name to greet.
		 *
		 * ## EXAMPLES
		 *
		 *     wp resolate greet --name=Freddy
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function greet( $args, $assoc_args ) {
			$name = $assoc_args['name'] ?? 'World';
			WP_CLI::success( "Hello, $name!" );
		}

		/**
		 * Create sample data for Resolate Plugin.
		 *
		 * This command creates 10 labels, 5 boards and 10 tasks per board.
		 *
		 * ## EXAMPLES
		 *
		 *     wp resolate create_sample_data
		 */
		public function create_sample_data() {
			// Check if we're running on a development version.
			if ( defined( 'RESOLATE_VERSION' ) && RESOLATE_VERSION !== '0.0.0' ) {
				WP_CLI::warning( 'You are adding sample data to a non-development version of Resolate (v' . RESOLATE_VERSION . ')' );
				WP_CLI::confirm( 'Do you want to continue?' );
			}

			WP_CLI::log( 'Starting sample data creation...' );

			$demo_data = new Resolate_Demo_Data();
			$demo_data->create_sample_data();

			WP_CLI::success( 'Sample data created successfully!' );
		}
	}

	// Register the main command that groups the subcommands.
	WP_CLI::add_command( 'resolate', 'Resolate_WPCLI' );
}
