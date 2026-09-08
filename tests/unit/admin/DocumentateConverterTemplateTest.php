<?php
/**
 * Tests for documentate-converter-template.php.
 *
 * @package Documentate
 */

/**
 * @coversDefaultClass documentate-converter-template
 */
class DocumentateConverterTemplateTest extends WP_UnitTestCase {

	/**
	 * Template file path.
	 *
	 * @var string
	 */
	private $template_path;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->template_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/documentate-converter-template.php';
	}

	/**
	 * Test template file exists.
	 */
	public function test_template_file_exists() {
		$this->assertFileExists( $this->template_path );
	}

	/**
	 * Test template contains required HTML structure.
	 */
	public function test_template_contains_html_structure() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '<!DOCTYPE html>', $content );
		$this->assertStringContainsString( '<html>', $content );
		$this->assertStringContainsString( '</html>', $content );
		$this->assertStringContainsString( '<head>', $content );
		$this->assertStringContainsString( '</head>', $content );
		$this->assertStringContainsString( '<body>', $content );
		$this->assertStringContainsString( '</body>', $content );
	}

	/**
	 * Test template contains required PHP variables.
	 */
	public function test_template_contains_required_variables() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_document_id', $content );
		$this->assertStringContainsString( '$documentate_target_format', $content );
		$this->assertStringContainsString( '$documentate_source_format', $content );
		$this->assertStringContainsString( '$documentate_output_action', $content );
		$this->assertStringContainsString( '$documentate_nonce', $content );
	}

	/**
	 * Test template sanitizes input.
	 */
	public function test_template_sanitizes_input() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'absint(', $content );
		$this->assertStringContainsString( 'sanitize_key(', $content );
		$this->assertStringContainsString( 'sanitize_text_field(', $content );
	}

	/**
	 * Test template escapes its output.
	 */
	public function test_template_escapes_output() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( "esc_html(", $content );
		$this->assertStringContainsString( "wp_json_encode(", $content );
	}

	/**
	 * Test template integrates the LibreOffice WASM browser converter.
	 */
	public function test_template_contains_libreoffice_wasm() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'WorkerBrowserConverter', $content );
		$this->assertStringContainsString( 'createWasmPaths', $content );
		$this->assertStringContainsString( 'createLibreOfficeWasmConverter', $content );
		$this->assertStringNotContainsStringIgnoringCase( 'ZetaJS', $content );
		$this->assertStringNotContainsString( 'zetaHelper.js', $content );
	}

	/**
	 * Test template guards on cross-origin isolation and installed assets.
	 */
	public function test_template_contains_environment_guards() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'crossOriginIsolated', $content );
		$this->assertStringContainsString( 'SharedArrayBuffer', $content );
		$this->assertStringContainsString( 'assetsAvailable', $content );
	}

	/**
	 * Test template contains status elements.
	 */
	public function test_template_contains_status_elements() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'id="status"', $content );
		$this->assertStringContainsString( 'id="spinner"', $content );
		$this->assertStringContainsString( 'id="status-title"', $content );
		$this->assertStringContainsString( 'id="status-message"', $content );
	}

	/**
	 * Test template contains conversion configuration.
	 */
	public function test_template_contains_conversion_config() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'conversionConfig', $content );
		$this->assertStringContainsString( 'postId', $content );
		$this->assertStringContainsString( 'targetFormat', $content );
		$this->assertStringContainsString( 'sourceFormat', $content );
		$this->assertStringContainsString( 'ajaxUrl', $content );
	}

	/**
	 * Test template contains AJAX handling.
	 */
	public function test_template_contains_ajax() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'admin-ajax.php', $content );
		$this->assertStringContainsString( 'documentate_generate_document', $content );
		$this->assertStringContainsString( 'FormData', $content );
	}

	/**
	 * Test template contains BroadcastChannel.
	 */
	public function test_template_contains_broadcast_channel() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'BroadcastChannel', $content );
		$this->assertStringContainsString( 'documentate_converter', $content );
	}

	/**
	 * Test template contains error handling.
	 */
	public function test_template_contains_error_handling() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'catch', $content );
		$this->assertStringContainsString( 'error', $content );
		$this->assertStringContainsString( "classList.add('error')", $content );
	}

	/**
	 * Test template contains MIME types.
	 */
	public function test_template_contains_mime_types() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'application/pdf', $content );
		$this->assertStringContainsString( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $content );
		$this->assertStringContainsString( 'application/vnd.oasis.opendocument.text', $content );
	}

	/**
	 * Test template localizes the browser converter configuration.
	 */
	public function test_template_contains_converter_localization() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'moduleUrl', $content );
		$this->assertStringContainsString( 'workerUrl', $content );
		$this->assertStringContainsString( 'wasmBaseUrl', $content );
		$this->assertStringContainsString( 'sofficeWasmUrl', $content );
		$this->assertStringContainsString( 'sofficeDataUrl', $content );
		$this->assertStringContainsString( 'wrapperUrl', $content );
		$this->assertStringContainsString( 'targetFormat', $content );
	}

	/**
	 * Test template contains download handling.
	 */
	public function test_template_contains_download() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'download', $content );
		$this->assertStringContainsString( 'Blob', $content );
		$this->assertStringContainsString( 'createObjectURL', $content );
	}

	/**
	 * Test template module script type.
	 */
	public function test_template_uses_module_script() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'type="module"', $content );
	}

	/**
	 * Test template contains CSS styles.
	 */
	public function test_template_contains_styles() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '<style>', $content );
		$this->assertStringContainsString( '</style>', $content );
		$this->assertStringContainsString( '.spinner', $content );
		$this->assertStringContainsString( '.status', $content );
	}

	/**
	 * Test template contains async function.
	 */
	public function test_template_contains_async() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'async function', $content );
		$this->assertStringContainsString( 'await', $content );
	}

	/**
	 * Test template contains use_channel variable.
	 */
	public function test_template_contains_use_channel() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_use_channel', $content );
		$this->assertStringContainsString( 'useChannel', $content );
	}

	/**
	 * Test template builds asset URLs from the converter class and plugins_url().
	 */
	public function test_template_builds_asset_urls() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_wrapper_url', $content );
		$this->assertStringContainsString( 'Documentate_Libreoffice_Wasm_Converter::get_browser_config()', $content );
		$this->assertStringContainsString( 'plugins_url(', $content );
	}
}
