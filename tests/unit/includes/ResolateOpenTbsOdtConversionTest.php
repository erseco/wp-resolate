<?php
/**
 * Tests for ODT HTML conversion avoiding literal <ul>/<ol> remnants
 * when only part of the text node matches the rich lookup.
 */

class ResolateOpenTbsOdtConversionTest extends WP_UnitTestCase {

    public function test_odt_conversion_removes_literal_ul_when_mixed_with_lookup_matches() {
		$text_ns   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
		$office_ns = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="' . $office_ns . '" xmlns:text="' . $text_ns . '">'
			. '<office:body><text:p>'
			. 'Intro &lt;em&gt;X&lt;/em&gt; y lista &lt;ul&gt;&lt;li&gt;Uno&lt;/li&gt;&lt;li&gt;Dos&lt;/li&gt;&lt;li&gt;Tres&lt;/li&gt;&lt;/ul&gt;'
			. '</text:p></office:body></office:document-content>';

		// Force a partial match so the converter takes the exact-match path.
		$lookup = array( '<em>X</em>' );

		$ref = new ReflectionClass( 'Resolate_OpenTBS' );
		$method = $ref->getMethod( 'convert_odt_part_rich_text' );
		$method->setAccessible( true );

		$updated = $method->invoke( null, $xml, $lookup );

		$this->assertIsString( $updated );
		$this->assertStringNotContainsString( '<ul', $updated, 'No debe quedar <ul> literal.' );
		$this->assertStringNotContainsString( '</ul>', $updated, 'No debe quedar </ul> literal.' );
		$this->assertStringContainsString( '• Uno', $updated, 'Debe convertir a viñetas con •.' );
		$this->assertStringContainsString( '• Dos', $updated, 'Debe convertir el segundo elemento.' );
		$this->assertStringContainsString( '• Tres', $updated, 'Debe convertir el tercer elemento.' );
    }

	public function test_odt_conversion_cleans_html_even_if_lookup_is_empty() {
		$text_ns   = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
		$office_ns = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="' . $office_ns . '" xmlns:text="' . $text_ns . '">'
			. '<office:body><text:p>&lt;ul&gt;&lt;li&gt;Uno&lt;/li&gt;&lt;/ul&gt;</text:p></office:body></office:document-content>';

		$ref = new ReflectionClass( 'Resolate_OpenTBS' );
		$method = $ref->getMethod( 'convert_odt_part_rich_text' );
		$method->setAccessible( true );

		$updated = $method->invoke( null, $xml, array() );

		$this->assertIsString( $updated );
		$this->assertStringNotContainsString( '<ul', $updated, 'No debe quedar <ul> literal aun sin lookup.' );
		$this->assertStringContainsString( '• Uno', $updated, 'Debe convertir a viñeta incluso sin lookup.' );
	}
}
