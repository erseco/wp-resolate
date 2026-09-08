<?php
/**
 * Rendering tests for the two admin converter popup templates.
 *
 * DocumentateConverterTemplateTest only inspects the template source text. These
 * tests actually render the templates so the request parameters they turn into
 * the client-side `conversionConfig` object are exercised end to end.
 *
 * @package Documentate
 */

/**
 * Renders admin/documentate-converter-template.php and
 * admin/documentate-collabora-playground-template.php.
 */
class DocumentateConverterTemplateRenderTest extends WP_UnitTestCase {

	/**
	 * Query parameters that must be restored after every test.
	 *
	 * @var string[]
	 */
	private static $request_keys = array( 'post_id', 'format', 'source', 'output', '_wpnonce', 'use_channel' );

	/**
	 * Remove the request parameters the templates read.
	 */
	public function tear_down() {
		foreach ( self::$request_keys as $key ) {
			unset( $_GET[ $key ] );
		}
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Render a template file and return everything it echoed.
	 *
	 * @param string $relative_path Template path relative to the plugin root.
	 * @return string Rendered markup.
	 */
	private function render( $relative_path ) {
		ob_start();
		include plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . $relative_path;
		return (string) ob_get_clean();
	}

	/**
	 * Extract the JSON value assigned to a key of the `conversionConfig` object.
	 *
	 * The WASM template emits the whole object as one JSON blob, the Collabora
	 * one emits a key per line, so the two are decoded differently.
	 *
	 * @param string $markup Rendered markup.
	 * @return array<string,mixed> Decoded configuration.
	 */
	private function extract_wasm_config( $markup ) {
		$this->assertSame(
			1,
			preg_match( '/const conversionConfig = (\{.*?\});/s', $markup, $matches ),
			'The WASM template must emit a JSON conversionConfig object.'
		);

		$config = json_decode( $matches[1], true );
		$this->assertIsArray( $config, 'conversionConfig must be valid JSON.' );

		return $config;
	}

	/**
	 * Read one `key: <json>,` pair out of the Collabora playground config block.
	 *
	 * @param string $markup Rendered markup.
	 * @param string $key    Configuration key.
	 * @return mixed Decoded value.
	 */
	private function extract_playground_value( $markup, $key ) {
		$this->assertSame(
			1,
			preg_match( '/\b' . preg_quote( $key, '/' ) . ': (.+?),?\n/', $markup, $matches ),
			sprintf( 'The Collabora template must emit a "%s" configuration key.', $key )
		);

		return json_decode( trim( $matches[1] ), true );
	}

	/**
	 * The WASM template forwards the sanitized request parameters to the browser.
	 */
	public function test_wasm_template_forwards_request_parameters() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );

		$_GET['post_id'] = (string) $post_id;
		$_GET['format'] = 'pdf';
		$_GET['source'] = 'odt';
		$_GET['output'] = 'preview';
		$_GET['_wpnonce'] = 'abc123';
		$_GET['use_channel'] = '1';

		$config = $this->extract_wasm_config( $this->render( 'admin/documentate-converter-template.php' ) );

		$this->assertSame( $post_id, $config['postId'] );
		$this->assertSame( 'pdf', $config['targetFormat'] );
		$this->assertSame( 'odt', $config['sourceFormat'] );
		$this->assertSame( 'preview', $config['outputAction'] );
		$this->assertSame( 'abc123', $config['nonce'] );
		$this->assertTrue( $config['useChannel'] );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
	}

	/**
	 * Without request parameters the WASM template falls back to safe defaults.
	 */
	public function test_wasm_template_applies_defaults_without_request_parameters() {
		$config = $this->extract_wasm_config( $this->render( 'admin/documentate-converter-template.php' ) );

		$this->assertSame( 0, $config['postId'] );
		$this->assertSame( 'pdf', $config['targetFormat'] );
		$this->assertSame( 'odt', $config['sourceFormat'] );
		$this->assertSame( 'preview', $config['outputAction'] );
		$this->assertSame( '', $config['nonce'] );
		$this->assertFalse( $config['useChannel'] );
	}

	/**
	 * Hostile request parameters are sanitized before reaching the popup.
	 */
	public function test_wasm_template_sanitizes_hostile_request_parameters() {
		$_GET['post_id'] = '-42abc';
		$_GET['format'] = '<script>alert(1)</script>';
		$_GET['_wpnonce'] = '"><script>alert(1)</script>';
		$_GET['use_channel'] = 'yes';

		$markup = $this->render( 'admin/documentate-converter-template.php' );
		$config = $this->extract_wasm_config( $markup );

		$this->assertSame( 42, $config['postId'] );
		$this->assertSame( 'scriptalert1script', $config['targetFormat'] );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $markup );
		// Only the literal string "1" enables the BroadcastChannel handshake.
		$this->assertFalse( $config['useChannel'] );
	}

	/**
	 * The WASM template ships the browser runtime asset URLs the popup imports.
	 */
	public function test_wasm_template_includes_browser_runtime_configuration() {
		$config = $this->extract_wasm_config( $this->render( 'admin/documentate-converter-template.php' ) );
		$expected = Documentate_Libreoffice_Wasm_Converter::get_browser_config();

		$this->assertSame( $expected['moduleUrl'], $config['moduleUrl'] );
		$this->assertSame( $expected['workerUrl'], $config['workerUrl'] );
		$this->assertSame( $expected['wasmBaseUrl'], $config['wasmBaseUrl'] );
		$this->assertSame( $expected['assetsAvailable'], $config['assetsAvailable'] );
		$this->assertSame(
			plugins_url( 'admin/js/documentate-libreoffice-wasm.js', DOCUMENTATE_PLUGIN_FILE ),
			$config['wrapperUrl']
		);
	}

	/**
	 * The rendered popup is a self-contained HTML document with the status shell.
	 */
	public function test_wasm_template_renders_status_shell() {
		$markup = $this->render( 'admin/documentate-converter-template.php' );

		$this->assertStringStartsWith( '<!DOCTYPE html>', ltrim( $markup ) );
		$this->assertStringContainsString( '<div class="status" id="status">', $markup );
		$this->assertStringContainsString( 'id="status-title"', $markup );
		$this->assertStringContainsString( '<script type="module">', $markup );
		$this->assertStringEndsWith( '</html>', rtrim( $markup ) );
	}

	/**
	 * The Collabora playground template forwards the request parameters.
	 */
	public function test_playground_template_forwards_request_parameters() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );

		$_GET['post_id'] = (string) $post_id;
		$_GET['format'] = 'docx';
		$_GET['source'] = 'odt';
		$_GET['output'] = 'download';
		$_GET['_wpnonce'] = 'nonce-value';
		$_GET['use_channel'] = '1';

		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertStringContainsString( 'postId: ' . $post_id . ',', $markup );
		$this->assertSame( 'docx', $this->extract_playground_value( $markup, 'targetFormat' ) );
		$this->assertSame( 'odt', $this->extract_playground_value( $markup, 'sourceFormat' ) );
		$this->assertSame( 'download', $this->extract_playground_value( $markup, 'outputAction' ) );
		$this->assertSame( 'nonce-value', $this->extract_playground_value( $markup, 'nonce' ) );
		$this->assertStringContainsString( 'useChannel: true', $markup );
	}

	/**
	 * The playground template publishes the configured Collabora base URL.
	 */
	public function test_playground_template_publishes_configured_collabora_url() {
		update_option(
			'documentate_settings',
			array( 'collabora_base_url' => 'https://collabora.example.org/' )
		);

		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertSame(
			'https://collabora.example.org/',
			$this->extract_playground_value( $markup, 'collaboraUrl' )
		);
	}

	/**
	 * With no Collabora URL configured the popup receives an empty string, which
	 * is what makes its init() abort with "La URL de Collabora no está
	 * configurada.".
	 */
	public function test_playground_template_emits_empty_collabora_url_when_unset() {
		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertSame( '', $this->extract_playground_value( $markup, 'collaboraUrl' ) );
		// wp_json_encode() escapes non-ASCII characters, so the message is not
		// present in the markup as literal UTF-8; compare against the same
		// JSON-encoded form the template itself echoes.
		$this->assertStringContainsString(
			wp_json_encode( 'La URL de Collabora no está configurada.' ),
			$markup
		);
	}

	/**
	 * The playground template escapes a hostile Collabora URL from the settings.
	 */
	public function test_playground_template_escapes_collabora_url_from_settings() {
		update_option(
			'documentate_settings',
			array( 'collabora_base_url' => 'javascript:alert(1)//"><script>alert(2)</script>' )
		);

		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertStringNotContainsString( '<script>alert(2)</script>', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
	}

	/**
	 * Without use_channel the popup runs in legacy (non-BroadcastChannel) mode.
	 */
	public function test_playground_template_defaults_to_legacy_mode() {
		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertStringContainsString( 'useChannel: false', $markup );
		$this->assertStringContainsString( 'postId: 0,', $markup );
		$this->assertSame( 'pdf', $this->extract_playground_value( $markup, 'targetFormat' ) );
		$this->assertSame( 'preview', $this->extract_playground_value( $markup, 'outputAction' ) );
	}

	/**
	 * The playground popup targets the Collabora convert-to endpoint, not the
	 * PHP proxy, because Playground cannot post multipart bodies from PHP.
	 */
	public function test_playground_template_converts_through_the_browser() {
		$markup = $this->render( 'admin/documentate-collabora-playground-template.php' );

		$this->assertStringContainsString( '/cool/convert-to/', $markup );
		$this->assertStringContainsString( 'documentate_generate_document', $markup );
		$this->assertStringEndsWith( '</html>', rtrim( $markup ) );
	}
}
