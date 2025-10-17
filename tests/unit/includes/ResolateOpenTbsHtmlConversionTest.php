<?php
/**
 * Tests for HTML → DOCX conversion helpers (lists, hr, underline/italic).
 */

class ResolateOpenTbsHtmlConversionTest extends WP_UnitTestCase {

	/**
	 * It should convert split HTML across multiple runs within a paragraph,
	 * handling <ul>/<li>, <hr />, and underline/italic formatting.
	 */
	public function test_docx_paragraph_level_html_conversion_handles_lists_and_hr() {
		$ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

		// Simulate a document part where TBS split the HTML across several w:t nodes.
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<w:document xmlns:w="' . $ns . '"><w:body>'
				. '<w:p><w:r><w:t>Intro</w:t></w:r></w:p>'
				. '<w:p>'
					. '<w:r><w:t>Con </w:t></w:r>'
					. '<w:r><w:t>&lt;u&gt;&lt;em&gt;ssaltos&lt;/em&gt;&lt;/u&gt; de linea</w:t></w:r>'
				. '</w:p>'
				. '<w:p><w:r><w:t>&lt;hr /&gt;</w:t></w:r></w:p>'
				. '<w:p>'
					. '<w:r><w:t>Y </w:t></w:r>'
					. '<w:r><w:t>&lt;ul&gt;</w:t></w:r>'
					. '<w:r><w:t>&lt;li&gt;Una&lt;/li&gt;</w:t></w:r>'
					. '<w:r><w:t>&lt;li&gt;Dos&lt;/li&gt;&lt;li&gt;Tres&lt;/li&gt;</w:t></w:r>'
					. '<w:r><w:t>&lt;/ul&gt;</w:t></w:r>'
				. '</w:p>'
			. '</w:body></w:document>';

		// Non-empty lookup to bypass early return inside converter.
		$lookup = array( '<b>x</b>' );

		$updated = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, $lookup );

		// Viñetas: the literal UL/LI tags should be gone and bullet prefix present.
		$this->assertStringNotContainsString( '<ul', $updated, 'No debe quedar <ul> literal.' );
		$this->assertStringNotContainsString( '<li', $updated, 'No debe quedar <li> literal.' );
		$this->assertStringContainsString( '• Una', $updated, 'Debe anteponer viñeta (•) al primer elemento.' );
		$this->assertStringContainsString( '• Dos', $updated, 'Debe anteponer viñeta (•) al segundo elemento.' );
		$this->assertStringContainsString( 'Tres', $updated, 'Debe incluir el tercer elemento.' );

		// Subrayado + cursiva: ensure formatting tags exist alongside the text.
		$this->assertStringContainsString( 'ssaltos', $updated, 'Texto con formato debe estar presente.' );
		$this->assertStringContainsString( '<w:i', $updated, 'Debe aplicar cursiva (w:i).' );
		$this->assertStringContainsString( '<w:u', $updated, 'Debe aplicar subrayado (w:u).' );

		// Regla horizontal: a nuevo párrafo con borde inferior.
		$this->assertStringContainsString( '<w:pBdr>', $updated, 'Debe insertarse párrafo con borde inferior para <hr />.' );
	}
}

