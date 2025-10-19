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
	 * It should add table borders to generated DOCX tables.
	 */
	public function test_convert_docx_part_rich_text_adds_table_borders() {
		$html = '<table><tr><td>A</td><td>B</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Resolate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:tblBorders', $result );
		$this->assertStringContainsString( '<w:top', $result );
		$this->assertStringContainsString( '<w:insideH', $result );
		$this->assertStringContainsString( 'w:color="000000"', $result );
	}

	/**
	 * It should convert HTML tables into ODF table markup when processing ODT fragments.
	 */
	public function test_convert_odt_part_rich_text_converts_tables() {
		$html = "<table>\r\n<thead><tr><th>Título</th><th>Descripción</th></tr></thead>\r\n<tbody><tr><td>Dato 1</td><td>Valor 1</td></tr></tbody></table>";
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Resolate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( '<table:table', $result );
		$this->assertStringContainsString( '<table:table-row', $result );
		$this->assertStringContainsString( '<table:table-cell', $result );
		$this->assertStringContainsString( '<text:p', $result );
	}

	/**
	 * It should apply ODF styles for table and table-cell with borders.
	 */
	public function test_convert_odt_part_rich_text_adds_table_border_styles() {
		$html = '<table><tr><td>X</td><td>Y</td></tr></table>';
			$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
				. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
				. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
				. ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
				. ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0">'
				. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
				. '</office:document-content>';

		$result = Resolate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( 'table:style-name="ResolateRichTable"', $result );
		$this->assertStringContainsString( 'style:name="ResolateRichTable"', $result );
		$this->assertStringContainsString( 'style:table-properties', $result );
		$this->assertStringContainsString( 'fo:border="0.5pt solid #000000"', $result );
	}

	/**
	 * It should keep complex table structures when other inline elements precede it.
	 */
	public function test_convert_odt_part_rich_text_handles_complex_fragments_with_table() {
		$html = '<h3>Encabezado de prueba</h3>'
			. 'Primer párrafo con texto de ejemplo.'
			. '<a href="http://lkjlñjlk">Segundo pá</a>rrafo con <strong>negritas</strong>, '
			. '<a href="https://www.gg.es"><em>cursivas</em></a> y <u>subrayado</u>.'
			. '<ul><li>Elemento uno</li><li>Elemento dos<ul><li>Subelemento</li><li>subelemento 2</li></ul></li><li>element</li></ul>'
			. '<table border="1"><tbody><tr><th>Col 1</th><th>Col 2</th></tr>'
			. '<tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></tbody></table>';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Resolate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( '<table:table', $result );
		$this->assertStringContainsString( '<table:table-row', $result );
		$this->assertStringContainsString( '<table:table-cell', $result );
		$this->assertStringContainsString( 'Encabezado de prueba', $result );
		$this->assertStringContainsString( 'Dato B2', $result );
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
