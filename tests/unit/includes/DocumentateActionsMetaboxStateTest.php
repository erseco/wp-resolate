<?php
/**
 * Output-level tests for the document actions metabox availability matrix.
 *
 * Each case drives the two inputs that decide what the metabox offers - which
 * templates the document type has, and which conversion route is configured -
 * and asserts the rendered buttons rather than the internal state.
 *
 * @covers Documentate_Admin_Helper
 */

class DocumentateActionsMetaboxStateTest extends WP_UnitTestCase {

	/**
	 * Helper under test.
	 *
	 * @var Documentate_Admin_Helper
	 */
	private $helper;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->helper = new Documentate_Admin_Helper();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Restore the settings option between cases.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Skip when the browser converter assets are not on disk.
	 *
	 * They are generated from node_modules by the postinstall hook rather than
	 * committed, so a checkout that has not run `npm install` cannot exercise
	 * the popup path: assets_available() is false and the metabox renders the
	 * disabled buttons instead. CI installs the npm dependencies before
	 * PHPUnit, so this case is still covered there.
	 *
	 * @return void
	 */
	private function requireWasmAssets() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE )
			. 'includes/class-documentate-libreoffice-wasm-converter.php';

		if ( ! Documentate_Libreoffice_Wasm_Converter::assets_available() ) {
			$this->markTestSkipped(
				'LibreOffice WASM glue not generated. Run "npm install" to exercise the popup path.'
			);
		}
	}

	/**
	 * Configure the conversion engine used by the metabox.
	 *
	 * @param string $engine   Either collabora or wasm.
	 * @param string $base_url Collabora base URL, empty to make it unavailable.
	 * @return void
	 */
	private function set_conversion( $engine, $base_url = '' ) {
		update_option(
			'documentate_settings',
			array(
				'conversion_engine' => $engine,
				'collabora_base_url' => $base_url,
			)
		);
	}

	/**
	 * Build a document whose type carries the given template, and render it.
	 *
	 * @param string $template Either odt, docx or an empty string for none.
	 * @return string Rendered metabox markup.
	 */
	private function render_for_template( $template ) {
		$term = wp_insert_term( 'Tipo ' . $template . wp_rand(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );

		if ( '' !== $template ) {
			$fixture = 'odt' === $template ? 'resolucion.odt' : 'demo-wp-documentate.docx';
			$path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture;
			$this->assertFileExists( $path );

			$attachment_id = self::factory()->attachment->create_upload_object( $path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', $template );
		}

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );

		return ob_get_clean();
	}

	/**
	 * Build a document whose type carries the given template, and resolve the
	 * action state the metabox is rendered from.
	 *
	 * @param string $template Either odt, docx or an empty string for none.
	 * @return array<string,mixed> Resolved action state.
	 */
	private function state_for_template( $template ) {
		$term = wp_insert_term( 'Tipo estado ' . $template . wp_rand(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );

		if ( '' !== $template ) {
			$fixture = 'odt' === $template ? 'resolucion.odt' : 'demo-wp-documentate.docx';
			$path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture;
			$this->assertFileExists( $path );

			$attachment_id = self::factory()->attachment->create_upload_object( $path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', $template );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		return $this->invoke_private( 'build_actions_state', array( $post_id ) );
	}

	/**
	 * Build a document whose type carries both office templates, and render it.
	 *
	 * The type editor configures a single template, but the per-format term
	 * metas the generator still reads let a legacy type carry one of each.
	 *
	 * @return string Rendered metabox markup.
	 */
	private function render_for_both_templates() {
		$term = wp_insert_term( 'Tipo ambos ' . wp_rand(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );

		foreach ( array( 'odt' => 'resolucion.odt', 'docx' => 'demo-wp-documentate.docx' ) as $format => $fixture ) {
			$path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture;
			$this->assertFileExists( $path );

			update_term_meta(
				$term_id,
				'documentate_type_' . $format . '_template',
				self::factory()->attachment->create_upload_object( $path )
			);
		}

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );

		return ob_get_clean();
	}

	/**
	 * Assert that an action is offered as an enabled link.
	 *
	 * @param string $markup Rendered metabox markup.
	 * @param string $action Value of data-documentate-action.
	 * @param string $format Value of data-documentate-format.
	 * @return void
	 */
	private function assertActionEnabled( $markup, $action, $format ) {
		$this->assertMatchesRegularExpression(
			'/<a [^>]*data-documentate-action="' . $action . '"[^>]*data-documentate-format="' . $format . '"/',
			$markup,
			sprintf( 'Expected an enabled %s link for %s.', $action, $format )
		);
	}

	/**
	 * Assert that an action is not offered as an enabled link.
	 *
	 * @param string $markup Rendered metabox markup.
	 * @param string $action Value of data-documentate-action.
	 * @param string $format Value of data-documentate-format.
	 * @return void
	 */
	private function assertActionDisabled( $markup, $action, $format ) {
		$this->assertDoesNotMatchRegularExpression(
			'/<a [^>]*data-documentate-action="' . $action . '"[^>]*data-documentate-format="' . $format . '"/',
			$markup,
			sprintf( 'Expected no enabled %s link for %s.', $action, $format )
		);
	}

	/**
	 * Without a template nothing can be produced, and the disabled buttons
	 * explain that a template must be configured first.
	 */
	public function test_no_template_disables_everything() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( '' );

		$this->assertActionDisabled( $markup, 'preview', 'pdf' );
		$this->assertActionDisabled( $markup, 'download', 'pdf' );
		$this->assertActionDisabled( $markup, 'download', 'odt' );
		$this->assertActionDisabled( $markup, 'download', 'docx' );

		$this->assertStringContainsString( 'disabled', $markup );
		$this->assertStringContainsString( 'Configure a DOCX or ODT template', $markup );
	}

	/**
	 * An ODT template offers PDF and the ODT download. DOCX is not offered:
	 * the editable download is the format the type has a template for.
	 */
	public function test_odt_template_offers_pdf_and_only_the_odt_download() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'odt' );
		$this->assertActionDisabled( $markup, 'download', 'docx' );
		$this->assertStringNotContainsString( 'DOCX', $markup );

		// Server-side conversion needs no browser popup.
		$this->assertStringNotContainsString( 'data-documentate-cdn-mode', $markup );
	}

	/**
	 * A DOCX template mirrors the ODT case: PDF and DOCX, never ODT.
	 */
	public function test_docx_template_offers_pdf_and_only_the_docx_download() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( 'docx' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'docx' );
		$this->assertActionDisabled( $markup, 'download', 'odt' );
		$this->assertStringNotContainsString( 'ODT', $markup );
	}

	/**
	 * The secondary block names itself as the editable download, and lists the
	 * one format the type can really hand over.
	 */
	public function test_editable_download_block_lists_the_template_format() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertStringContainsString( 'Editable download:', $markup );
		$this->assertStringNotContainsString( 'Other download formats:', $markup );
	}

	/**
	 * Without a template there is no editable download at all, so the block is
	 * left out rather than printed empty.
	 */
	public function test_editable_download_block_is_omitted_without_a_template() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( '' );

		$this->assertStringNotContainsString( 'Editable download:', $markup );
		$this->assertStringNotContainsString( 'documentate-actions-secondary', $markup );
	}

	/**
	 * The native engine produces the PDF on its own, so the actions are offered
	 * whether or not a conversion service could be reached.
	 */
	public function test_pdf_is_available_with_the_native_engine_and_no_converter() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );

		$state = $this->state_for_template( 'odt' );

		$this->assertTrue( $state['pdf_available'] );
		$this->assertTrue( $state['preview_available'] );
		$this->assertSame( '', $state['pdf_message'] );
		$this->assertSame( array( 'odt' ), array_keys( $state['formats'] ) );
	}

	/**
	 * The native engine draws the PDF in PHP, so nothing may be routed through
	 * the browser converter popup - not even where Collabora would take it.
	 */
	public function test_native_engine_never_flags_the_browser_popup() {
		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';

		try {
			$this->set_conversion( 'collabora', 'https://collabora.example.org' );
			$this->assertStringContainsString(
				'data-documentate-cdn-mode',
				$this->render_for_template( 'odt' ),
				'A converter engine in Playground converts through the popup.'
			);

			update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );
			$markup = $this->render_for_template( 'odt' );
		} finally {
			unset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
		}

		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertStringNotContainsString( 'data-documentate-cdn-mode', $markup );
	}

	/**
	 * Invoke a private helper of the admin helper.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_private( $name, array $args ) {
		$method = ( new ReflectionClass( $this->helper ) )->getMethod( $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->helper, $args );
	}

	/**
	 * Only a format the document type has a template for is offered, whichever
	 * engine is configured: the editable download is never converted.
	 *
	 * @dataProvider provide_own_formats
	 *
	 * @param string   $docx_template DOCX template path, or an empty string.
	 * @param string   $odt_template  ODT template path, or an empty string.
	 * @param string[] $expected      Format keys the metabox must offer.
	 */
	public function test_only_the_template_format_is_offered( $docx_template, $odt_template, array $expected ) {
		$formats = $this->invoke_private( 'own_format_state', array( $docx_template, $odt_template ) );

		$this->assertSame( $expected, array_keys( $formats ) );
		foreach ( $formats as $format => $data ) {
			$this->assertTrue( $data['available'], $format );
			$this->assertSame( strtoupper( $format ), $data['label'] );
			$this->assertSame( '', $data['message'], 'An offered format has nothing to explain.' );
		}
	}

	/**
	 * Template combinations and the formats each one may be downloaded in.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_own_formats() {
		return array(
			'only an ODT template' => array( '', '/tmp/t.odt', array( 'odt' ) ),
			'only a DOCX template' => array( '/tmp/t.docx', '', array( 'docx' ) ),
			'both templates' => array( '/tmp/t.docx', '/tmp/t.odt', array( 'odt', 'docx' ) ),
			'no template at all' => array( '', '', array() ),
		);
	}

	/**
	 * The PDF tooltip is empty when PDF can be produced, and otherwise names
	 * the reason it cannot.
	 */
	public function test_pdf_message_covers_each_cause() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$this->assertStringContainsString(
			'Configure a DOCX or ODT template',
			$this->invoke_private( 'build_pdf_message', array( '', '', true ) )
		);

		$this->assertSame(
			'',
			$this->invoke_private( 'build_pdf_message', array( '', '/tmp/t.odt', true ) ),
			'No tooltip is needed while PDF generation is available.'
		);

		$this->assertNotSame(
			'',
			$this->invoke_private( 'build_pdf_message', array( '', '/tmp/t.odt', false ) ),
			'A template without a conversion route must explain itself.'
		);
	}

	/**
	 * Under the native engine a template is all it takes, so the tooltip stays
	 * empty even where a converter would have reported itself unreachable.
	 */
	public function test_pdf_message_is_empty_under_the_native_engine() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'fpdf' ) );

		$this->assertSame(
			'',
			$this->invoke_private( 'build_pdf_message', array( '', '/tmp/t.odt', false ) )
		);

		$this->assertStringContainsString(
			'Configure a DOCX or ODT template',
			$this->invoke_private( 'build_pdf_message', array( '', '', false ) ),
			'The native engine still needs a template to know what to draw.'
		);
	}

	/**
	 * The source format is the ODT when both exist, and falls back to whatever
	 * template is present.
	 */
	public function test_source_format_resolution() {
		$this->assertSame( 'odt', $this->invoke_private( 'resolve_source_format', array( '/tmp/t.docx', '/tmp/t.odt' ) ) );
		$this->assertSame( 'odt', $this->invoke_private( 'resolve_source_format', array( '', '/tmp/t.odt' ) ) );
		$this->assertSame( 'docx', $this->invoke_private( 'resolve_source_format', array( '/tmp/t.docx', '' ) ) );
		$this->assertSame( '', $this->invoke_private( 'resolve_source_format', array( '', '' ) ) );
	}

	/**
	 * The browser WASM engine still produces the PDF, and marks it so the
	 * front-end opens the isolated converter popup to do the conversion.
	 */
	public function test_browser_wasm_engine_enables_popup_conversion() {
		$this->requireWasmAssets();
		$this->set_conversion( 'wasm' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'odt' );
		$this->assertActionDisabled( $markup, 'download', 'docx' );

		// The PDF must be flagged for the popup, sourced from the ODT.
		$this->assertStringContainsString( 'data-documentate-cdn-mode="1"', $markup );
		$this->assertStringContainsString( 'data-documentate-source-format="odt"', $markup );
	}

	/**
	 * The source format itself is served directly even in popup mode, so it is
	 * not flagged for conversion.
	 */
	public function test_source_format_is_not_flagged_for_popup_conversion() {
		$this->set_conversion( 'wasm' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertMatchesRegularExpression(
			'/<a (?![^>]*data-documentate-cdn-mode)[^>]*data-documentate-format="odt"/',
			$markup,
			'The ODT download is the source format and must not be flagged for conversion.'
		);
	}

	/**
	 * A type carrying both templates offers both editable downloads, and hands
	 * each one over directly: neither is ever routed through the converter
	 * popup, because neither needs converting.
	 */
	public function test_both_templates_are_offered_and_never_converted() {
		$this->requireWasmAssets();
		$this->set_conversion( 'wasm' );

		$markup = $this->render_for_both_templates();

		$this->assertActionEnabled( $markup, 'download', 'odt' );
		$this->assertActionEnabled( $markup, 'download', 'docx' );

		// The PDF still goes through the popup under this engine.
		$this->assertStringContainsString( 'data-documentate-cdn-mode="1"', $markup );

		$secondary = substr( $markup, strpos( $markup, 'documentate-actions-secondary' ) );
		$this->assertStringNotContainsString(
			'data-documentate-cdn-mode',
			$secondary,
			'An editable download is the rendered template itself and is never converted.'
		);
	}

	/**
	 * Users without edit rights see nothing but a notice.
	 */
	public function test_insufficient_permissions_renders_notice_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$markup = ob_get_clean();

		$this->assertStringNotContainsString( '<a ', $markup );
		$this->assertStringNotContainsString( 'documentate-actions-primary', $markup );
		$this->assertStringContainsString( 'Insufficient permissions.', $markup );
	}
}
