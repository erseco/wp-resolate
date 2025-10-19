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
	 * It should convert HTML paragraphs into individual Word paragraphs.
	 */
	public function test_convert_docx_part_rich_text_converts_paragraphs() {
		$html = '<p>Primero</p><p>Segundo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';
		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );
		$nodes = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $nodes->length );
		$this->assertSame( 'Primero', trim( $nodes->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo', trim( $nodes->item( 1 )->textContent ) );
	}

	/**
	 * It should convert HTML lists into individual Word paragraphs with bullet prefixes.
	 */
	public function test_convert_docx_part_rich_text_converts_lists() {
		$html = '<ul><li>Uno</li><li>Dos</li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc        = $this->load_docx_dom( $result );
		$xpath      = $this->create_word_xpath( $doc );
		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( '• Uno', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( '• Dos', trim( $paragraphs->item( 1 )->textContent ) );
	}

	/**
	 * It should convert headings into paragraphs with surrounding blank spacing.
	 */
	public function test_convert_docx_part_rich_text_converts_headings() {
		$html = '<h2>Título</h2><p>Contenido</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );
		$this->assertGreaterThanOrEqual( 4, $paragraphs->length );
		$this->assertSame( '', trim( $paragraphs->item( 0 )->textContent ) );

		$heading = $paragraphs->item( 1 );
		$this->assertStringContainsString( 'Título', $heading->textContent );
		$this->assertGreaterThan( 0, $xpath->query( './/w:b', $heading )->length );

		$this->assertSame( '', trim( $paragraphs->item( 2 )->textContent ) );
		$this->assertStringContainsString( 'Contenido', $paragraphs->item( 3 )->textContent );
	}

	/**
	 * It should convert HTML tables into WordprocessingML table structures.
	 */
	public function test_convert_docx_part_rich_text_converts_tables() {
		$html = '<table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>A1</td><td>A2</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length );

		$rows = $xpath->query( './/w:tr', $tables->item( 0 ) );
		$this->assertSame( 2, $rows->length );

		$header_cells = $xpath->query( './/w:tr[1]/w:tc', $tables->item( 0 ) );
		$this->assertSame( 2, $header_cells->length );
		$this->assertStringContainsString( 'Col 1', $header_cells->item( 0 )->textContent );
		$this->assertStringContainsString( 'Col 2', $header_cells->item( 1 )->textContent );
		$this->assertGreaterThan( 0, $xpath->query( './/w:b', $header_cells->item( 0 ) )->length );

		$data_cells = $xpath->query( './/w:tr[2]/w:tc', $tables->item( 0 ) );
		$this->assertSame( 2, $data_cells->length );
		$this->assertStringContainsString( 'A1', $data_cells->item( 0 )->textContent );
		$this->assertStringContainsString( 'A2', $data_cells->item( 1 )->textContent );
	}

	/**
	 * Load a DOCX XML string into a DOMDocument for assertions.
	 *
	 * @param string $xml XML string.
	 * @return DOMDocument
	 */
	private function load_docx_dom( $xml ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Create a WordprocessingML XPath helper.
	 *
	 * @param DOMDocument $dom DOMDocument instance.
	 * @return DOMXPath
	 */
	private function create_word_xpath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		return $xpath;
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
