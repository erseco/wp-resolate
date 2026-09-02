<?php
/**
 * Re-seed the application's demo document set.
 *
 * Run with WP-CLI's eval-file, which already loads WordPress (and therefore
 * the plugin) before this file executes:
 *
 *   wp eval-file wp-content/plugins/documentate/scripts/seed-demo-app.php
 *
 * Used by capturas.mjs before every screenshot pass so the demo always starts
 * from the same twelve documents. Outside phpunit.xml.dist's coverage: it is
 * a thin, one-shot entry point, not behaviour of its own to test.
 *
 * @package Documentate
 */

if ( ! defined( 'WPINC' ) ) {
	echo "This script must be run with wp eval-file.\n";
	exit( 1 );
}

if ( ! class_exists( 'Documentate_Demo_App' ) ) {
	echo "Documentate_Demo_App is not available — is the plugin active?\n";
	exit( 1 );
}

$ids = Documentate_Demo_App::reseed();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain CLI text (WP-CLI eval-file), not HTML; the values are internal integer IDs.
printf( "Documentate demo app: seeded %d documents (IDs: %s).\n", count( $ids ), implode( ', ', $ids ) );
