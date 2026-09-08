<?php
/**
 * Leave a development site with just its demo content.
 *
 * Run with WP-CLI, which loads WordPress for us:
 *
 *   npx @wordpress/env run cli --config=.wp-env.docker.json \
 *     wp eval-file wp-content/plugins/documentate/scripts/reset-demo.php
 *
 * `make reset-demo` is the short way. It deletes every document the demo does
 * not claim — everything the end-to-end suite left behind — and re-seeds, so
 * the site ends in the state a fresh install would have.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'Documentate_Demo_Reset' ) ) {
	WP_CLI::error( 'El plugin Documentate no está activo en este sitio.' );
}

$resultado = Documentate_Demo_Reset::run();

if ( is_wp_error( $resultado ) ) {
	WP_CLI::error( $resultado->get_error_message() );
}

WP_CLI::success(
	sprintf(
		'Borrados %d documentos de pruebas, %d usuarios, %d categorías y %d tipos. Quedan %d documentos del circuito.',
		$resultado['documentos'],
		$resultado['usuarios'],
		$resultado['categorias'],
		$resultado['tipos'],
		$resultado['sembrados']
	)
);
