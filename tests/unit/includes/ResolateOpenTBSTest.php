<?php
/**
 * Tests for the Resolate_OpenTBS rich text conversion helpers.
 */

class ResolateOpenTBSTest extends PHPUnit\Framework\TestCase {

	/**
	 * It should convert HTML strong tags into bold WordprocessingML runs.
	 */
	public function test_convert_docx_part_rich_text_converts_strong_tags() {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>Un &lt;strong&gt;text&lt;/strong&gt;</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( 'Un <strong>text</strong>' ) );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringNotContainsString( '<strong>', $result );
	}

	/**
	 * It should convert HTML italic and underline tags into WordprocessingML runs.
	 */
	public function test_convert_docx_part_rich_text_converts_italic_and_underline() {
		$html = 'Texto <em>cursiva</em> y <u>subrayado</u>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
	}

	/**
	 * It should convert HTML paragraphs into runs with Word line breaks.
	 */
	public function test_convert_docx_part_rich_text_converts_paragraphs() {
		$html    = '<p>Primero</p><p>Segundo</p>';
		$xml     = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';
		$result  = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertMatchesRegularExpression( '/Primero<\/w:t>.*<w:br\/>.*Segundo<\/w:t>/s', $result );
	}

	/**
	 * It should convert HTML lists into runs with simple bullet prefixes.
	 */
	public function test_convert_docx_part_rich_text_converts_lists() {
		$html = '<ul><li>Uno</li><li>Dos</li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '• ', $result );
		$this->assertMatchesRegularExpression( '/Uno<\/w:t>.*<w:br\/>.*Dos<\/w:t>/', $result );
	}

	/**
	 * It should convert headings into bold runs with double line breaks.
	 */
	public function test_convert_docx_part_rich_text_converts_headings() {
		$html = '<h2>Título</h2><p>Contenido</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertMatchesRegularExpression( '/<w:br\/><w:br\/>/', $result );
	}

	/**
	 * It should convert links into hyperlink containers with external relationships.
	 */
	public function test_convert_docx_part_rich_text_converts_links() {
		$html = '<a href="https://example.com">Ejemplo</a>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$relationships = $this->create_relationship_context();
		$result        = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ), $relationships );

		$this->assertStringContainsString( '<w:hyperlink', $result );
		$this->assertStringContainsString( 'r:id="rId1"', $result );
		$rels_xml = $relationships['doc']->saveXML();
		$this->assertStringContainsString( 'Target="https://example.com"', $rels_xml );
	}

	/**
	 * Create an empty relationship context for tests.
	 *
	 * @return array<string,mixed>
	 */
	private function create_relationship_context() {
		$doc = new DOMDocument();
		$doc->loadXML( '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships" />' );

		return array(
			'path'       => 'word/_rels/document.xml.rels',
			'doc'        => $doc,
			'next_index' => 0,
			'map'        => array(),
			'modified'   => false,
		);
	}
}
