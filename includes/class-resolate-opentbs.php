<?php
/**
 * OpenTBS integration helpers for Resolate.
 *
 * @package Resolate
 */

/**
 * Lightweight OpenTBS wrapper for Resolate.
 */
class Resolate_OpenTBS {

	/**
	 * Ensure libraries are loaded.
	 *
	 * @return bool
	 */
	public static function load_libs() {
		$base = plugin_dir_path( __DIR__ ) . 'admin/vendor/tinybutstrong/';
		$tbs = $base . 'tinybutstrong/tbs_class.php';
		$otb = $base . 'opentbs/tbs_plugin_opentbs.php';
		if ( file_exists( $tbs ) && file_exists( $otb ) ) {
			require_once $tbs; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			require_once $otb; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			return class_exists( 'clsTinyButStrong' ) && defined( 'OPENTBS_PLUGIN' );
		}
		return false;
	}

	/**
	 * Render an ODT from template and data.
	 *
	 * @param string $template_path Absolute path to .odt template.
	 * @param array  $fields        Associative fields.
	 * @param string $dest_path     Output file path.
	 * @return bool|WP_Error
	 */
	public static function render_odt( $template_path, $fields, $dest_path ) {
		if ( ! self::load_libs() ) {
			return new WP_Error( 'resolate_opentbs_missing', __( 'OpenTBS no está disponible.', 'resolate' ) );
		}
		if ( ! file_exists( $template_path ) ) {
			return new WP_Error( 'resolate_template_missing', __( 'Plantilla ODT no encontrada.', 'resolate' ) );
		}
		try {
			$tbs_engine = new clsTinyButStrong();
			$tbs_engine->Plugin( TBS_INSTALL, OPENTBS_PLUGIN );
			$tbs_engine->LoadTemplate( $template_path, OPENTBS_ALREADY_UTF8 );

			if ( ! is_array( $fields ) ) {
				$fields = array();
			}

			$tbs_engine->ResetVarRef( false );

			foreach ( $fields as $k => $v ) {
				if ( ! is_string( $k ) || '' === $k ) {
					continue;
				}
				$tbs_engine->SetVarRefItem( $k, $v );
				$tbs_engine->MergeField( $k, $v );
			}

			$tbs_engine->Show( OPENTBS_FILE, $dest_path );
			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'resolate_opentbs_error', $e->getMessage() );
		}
	}

	/**
	 * Render a DOCX from template and data (same as ODT).
	 *
	 * @param string $template_path Template path.
	 * @param array  $fields        Fields map.
	 * @param string $dest_path     Output path.
	 * @return bool|WP_Error
	 */
	public static function render_docx( $template_path, $fields, $dest_path ) {
		return self::render_odt( $template_path, $fields, $dest_path );
	}
}
