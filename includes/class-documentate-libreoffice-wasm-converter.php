<?php
/**
 * LibreOffice WASM (browser) converter helper for Documentate.
 *
 * Provides availability checks, plugin-local asset URLs and user-facing status
 * messages for the in-browser LibreOffice WASM conversion engine, backed by the
 * `@matbee/libreoffice-converter` package.
 *
 * The actual conversion runs client-side, inside a cross-origin isolated popup
 * window that loads `WorkerBrowserConverter` from
 * `@matbee/libreoffice-converter/browser`. This class never executes the WASM
 * converter from PHP: there is no Node/server adapter, so server-side
 * availability is always reported as false and callers must fall back to
 * Collabora Online for server-side/background PDF generation.
 *
 * The engine keeps the existing `wasm` value of the `conversion_engine` plugin
 * setting for backwards compatibility, so existing installations keep working
 * without any settings migration.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit();

/**
 * Helper for the browser-based LibreOffice WASM conversion engine.
 */
class Documentate_Libreoffice_Wasm_Converter {
	/**
	 * Relative path (from the plugin root) to the copied browser runtime assets.
	 *
	 * @var string
	 */
	const VENDOR_PATH = 'admin/vendor/libreoffice-converter';

	/**
	 * Whether the plugin is configured to use in-browser LibreOffice WASM conversion.
	 *
	 * Conversion is performed client-side; this reflects the `wasm` engine option.
	 *
	 * @return bool
	 */
	public static function is_browser_mode() {
		$options = get_option( 'documentate_settings', array() );
		$engine = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : 'collabora';
		return 'wasm' === $engine;
	}

	/**
	 * Backwards-compatible alias for is_browser_mode().
	 *
	 * @deprecated Use is_browser_mode(). Kept so existing integrations keep working.
	 * @return bool
	 */
	public static function is_cdn_mode() {
		return self::is_browser_mode();
	}

	/**
	 * Determine if the engine can run server-side conversions.
	 *
	 * The LibreOffice WASM engine is browser-only, so this always returns false.
	 * Server-side PDF generation requires Collabora Online or another server-side
	 * service.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return false;
	}

	/**
	 * Attempt a server-side conversion.
	 *
	 * Always returns a WP_Error because the LibreOffice WASM engine only runs in
	 * the browser. The error data describes how conversion is performed instead.
	 *
	 * @param string $input_path    Absolute path to the source file.
	 * @param string $output_path   Absolute path to the desired output file.
	 * @param string $output_format Optional target format (unused).
	 * @param string $input_format  Optional input format (unused).
	 * @return WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format = '', $input_format = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return new WP_Error(
			'documentate_libreoffice_wasm_browser_only',
			self::get_browser_conversion_message(),
			array(
				'mode' => 'browser',
				'engine' => 'wasm',
			)
		);
	}

	/**
	 * Absolute filesystem path to the copied browser runtime assets.
	 *
	 * @return string
	 */
	public static function get_vendor_dir() {
		return plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . self::VENDOR_PATH;
	}

	/**
	 * Base URL of the copied browser runtime assets.
	 *
	 * @return string
	 */
	public static function get_vendor_base_url() {
		$url = plugins_url( self::VENDOR_PATH, DOCUMENTATE_PLUGIN_FILE );

		/**
		 * Filter the base URL used to load LibreOffice WASM browser assets.
		 *
		 * @param string $url Current base URL (no trailing slash).
		 */
		return (string) apply_filters( 'documentate_libreoffice_wasm_base_url', $url );
	}

	/**
	 * URL of the browser entrypoint module (dist/browser.js).
	 *
	 * @return string
	 */
	public static function get_module_url() {
		return self::get_vendor_base_url() . '/dist/browser.js';
	}

	/**
	 * URL of the browser worker script (dist/browser.worker.global.js).
	 *
	 * @return string
	 */
	public static function get_worker_url() {
		return self::get_vendor_base_url() . '/dist/browser.worker.global.js';
	}

	/**
	 * Base URL (with trailing slash) of the local WASM glue directory.
	 *
	 * Hosts the small, same-origin scripts (soffice.js, soffice.worker.js). The
	 * large binaries (soffice.wasm/soffice.data) are loaded from the CDN instead;
	 * see get_binary_base_url().
	 *
	 * @return string
	 */
	public static function get_wasm_base_url() {
		return trailingslashit( self::get_vendor_base_url() . '/wasm' );
	}

	/**
	 * Base URL (with trailing slash) of the large LibreOffice WASM binaries.
	 *
	 * Defaults to a CORS-enabled CDN so the ~235 MB of binaries do not need to be
	 * committed or shipped in the plugin. Configure with the
	 * DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL constant or the filter below.
	 *
	 * @return string
	 */
	public static function get_binary_base_url() {
		$url = defined( 'DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL' )
			? (string) DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL
			: self::get_wasm_base_url();

		/**
		 * Filter the base URL for the large LibreOffice WASM binaries.
		 *
		 * @param string $url Current base URL (with trailing slash).
		 */
		$url = (string) apply_filters( 'documentate_libreoffice_wasm_binary_base_url', $url );

		return trailingslashit( $url );
	}

	/**
	 * URL of the soffice.wasm binary (loaded from the CDN by default).
	 *
	 * @return string
	 */
	public static function get_soffice_wasm_url() {
		return self::get_binary_base_url() . 'soffice.wasm';
	}

	/**
	 * URL of the soffice.data binary (loaded from the CDN by default).
	 *
	 * @return string
	 */
	public static function get_soffice_data_url() {
		return self::get_binary_base_url() . 'soffice.data';
	}

	/**
	 * Whether the local WASM glue scripts are present in the plugin.
	 *
	 * Only the small, same-origin glue (browser.js, browser worker, soffice.js,
	 * soffice.worker.js) needs to ship with the plugin; the large binaries are
	 * fetched from the CDN. Used to show a diagnostic when the glue is missing
	 * (for example, a build that skipped `npm run copy:libreoffice-converter`).
	 *
	 * @return bool
	 */
	public static function assets_available() {
		$dir = self::get_vendor_dir();
		return file_exists( $dir . '/dist/browser.js' )
			&& file_exists( $dir . '/dist/browser.worker.global.js' )
			&& file_exists( $dir . '/wasm/soffice.js' );
	}

	/**
	 * Input formats the browser converter can read for this plugin.
	 *
	 * @return array<int, string>
	 */
	public static function get_supported_input_formats() {
		/**
		 * Filter the input formats offered for browser LibreOffice WASM conversion.
		 *
		 * @param array<int, string> $formats Supported input extensions.
		 */
		return (array) apply_filters( 'documentate_libreoffice_wasm_input_formats', array( 'odt', 'docx' ) );
	}

	/**
	 * Target format produced by the browser converter.
	 *
	 * @return string
	 */
	public static function get_target_format() {
		return 'pdf';
	}

	/**
	 * Default user-facing message describing the browser conversion flow.
	 *
	 * @return string
	 */
	public static function get_browser_conversion_message() {
		return 'La conversión a PDF se realiza en el navegador mediante LibreOffice WASM. La generación de PDF en el servidor o en segundo plano requiere Collabora Online u otro servicio del lado del servidor.';
	}

	/**
	 * Message shown when the WASM runtime assets are missing.
	 *
	 * @return string
	 */
	public static function get_missing_assets_message() {
		return 'Los recursos de LibreOffice WASM no están instalados. Ejecuta «npm install» (o «npm run copy:libreoffice-converter») para habilitar la conversión en el navegador, o usa Collabora Online.';
	}

	/**
	 * Message describing the cross-origin isolation requirements.
	 *
	 * @return string
	 */
	public static function get_headers_help_message() {
		return 'La conversión en el navegador requiere un contexto con aislamiento de origen cruzado (Cross-Origin-Opener-Policy: same-origin y Cross-Origin-Embedder-Policy: require-corp) y compatibilidad con SharedArrayBuffer. Si tu navegador no puede proporcionarlos, usa Collabora Online en su lugar.';
	}

	/**
	 * Translatable strings passed to the browser converter script.
	 *
	 * @return array<string, string>
	 */
	public static function get_browser_strings() {
		return array(
			'loading' => 'Cargando LibreOffice...',
			'loadingDetail' => 'Descargando componentes de LibreOffice WASM. Esto puede tardar la primera vez.',
			'generating' => 'Generando documento...',
			'generatingDetail' => 'Procesando plantilla en el servidor.',
			'downloading' => 'Descargando documento...',
			'downloadingDetail' => 'Obteniendo documento fuente.',
			'converting' => 'Convirtiendo a PDF...',
			'convertingDetail' => 'Procesando con LibreOffice WASM.',
			'completed' => '¡Completado!',
			'completedDetail' => 'Documento convertido.',
			'error' => 'Error',
			'errorGeneric' => 'Error en la conversión.',
			'sharedArrayBufferError' => self::get_headers_help_message(),
			'missingAssets' => self::get_missing_assets_message(),
		);
	}

	/**
	 * Full configuration array for the browser converter script.
	 *
	 * Includes the plugin-local asset URLs, the supported input formats, the
	 * target format and the translated help/error strings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_browser_config() {
		return array(
			'moduleUrl' => self::get_module_url(),
			'workerUrl' => self::get_worker_url(),
			'wasmBaseUrl' => self::get_wasm_base_url(),
			'sofficeWasmUrl' => self::get_soffice_wasm_url(),
			'sofficeDataUrl' => self::get_soffice_data_url(),
			'supportedInputFormats' => array_values( self::get_supported_input_formats() ),
			'targetFormat' => self::get_target_format(),
			'assetsAvailable' => self::assets_available(),
			'strings' => self::get_browser_strings(),
		);
	}
}
