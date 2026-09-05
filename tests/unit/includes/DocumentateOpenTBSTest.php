<?php
/**
 * Tests for the Documentate_OpenTBS rich text conversion helpers.
 */

class DocumentateOpenTBSTest extends PHPUnit\Framework\TestCase {

	/**
	 * It should convert HTML strong tags into bold WordprocessingML runs.
	 */
	public function test_convert_docx_part_rich_text_converts_strong_tags() {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>Un &lt;strong&gt;text&lt;/strong&gt;</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( 'Un <strong>text</strong>' ) );

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

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

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
		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );
		$nodes = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $nodes->length );
		$this->assertSame( 'Primero', trim( $nodes->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo', trim( $nodes->item( 1 )->textContent ) );
	}

	/**
	 * It should split inline DOCX placeholders with multiple paragraphs into real paragraphs.
	 */
	public function test_convert_docx_part_rich_text_splits_inline_multi_paragraph_placeholders() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:pPr><w:jc w:val="both"/></w:pPr><w:r><w:t>Antes '
			. htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 )
			. ' Después</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Antes Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo Después', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//w:body//w:br' )->length );
		$this->assertSame(
			'both',
			$xpath->query( './w:pPr/w:jc', $paragraphs->item( 0 ) )->item( 0 )->getAttributeNS(
				'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
				'val'
			)
		);
		$this->assertSame(
			'both',
			$xpath->query( './w:pPr/w:jc', $paragraphs->item( 1 ) )->item( 0 )->getAttributeNS(
				'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
				'val'
			)
		);
	}

	/**
	 * It should convert standalone DOCX multi-paragraph placeholders into separate paragraphs.
	 */
	public function test_convert_docx_part_rich_text_keeps_standalone_multi_paragraph_placeholders_as_paragraphs() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//w:body//w:br' )->length );
	}

	/**
	 * It should keep soft line breaks inside a single DOCX paragraph.
	 */
	public function test_convert_docx_part_rich_text_keeps_soft_breaks_within_single_paragraph() {
		$html = '<p>Primera línea<br>Segunda línea</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$this->assertSame( 1, $xpath->query( '//w:body/w:p' )->length );
		$this->assertSame( 1, $xpath->query( '//w:body//w:br' )->length );
	}

	/**
	 * It should convert HTML lists into individual Word paragraphs with bullet prefixes.
	 */
	public function test_convert_docx_part_rich_text_converts_lists() {
		$html = '<ul><li>Uno</li><li>Dos</li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc        = $this->load_docx_dom( $result );
		$xpath      = $this->create_word_xpath( $doc );
		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( '• Uno', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( '• Dos', trim( $paragraphs->item( 1 )->textContent ) );
	}

	/**
	 * It should convert nested HTML lists into paragraphs preserving bullets and nested content.
	 */
	public function test_convert_docx_part_rich_text_converts_nested_lists() {
		$html = '<ul><li>Uno</li><li>Dos<ol><li>2.1</li><li>2.2</li></ol></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc        = $this->load_docx_dom( $result );
		$xpath      = $this->create_word_xpath( $doc );
		$paragraphs = $xpath->query( '//w:body/w:p' );

		// Nested lists produce separate paragraphs: Uno, Dos, 2.1, 2.2.
		$this->assertGreaterThanOrEqual( 4, $paragraphs->length );
		$this->assertSame( '• Uno', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( '• Dos', trim( $paragraphs->item( 1 )->textContent ) );

		// Verify nested ordered list items appear with numbering.
		$this->assertStringContainsString( '2.1', $result );
		$this->assertStringContainsString( '2.2', $result );

		$this->assertStringContainsString( 'xml:space="preserve">• </w:t>', $result );
	}

	/**
	 * It should convert headings into paragraphs with surrounding blank spacing.
	 */
	public function test_convert_docx_part_rich_text_converts_headings() {
		$html = '<h2>Título</h2><p>Contenido</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
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

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

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

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:tblBorders', $result );
		$this->assertStringContainsString( '<w:top', $result );
		$this->assertStringContainsString( '<w:insideH', $result );
		$this->assertStringContainsString( 'w:color="000000"', $result );
	}

	/**
	 * It should convert nested HTML lists into ODT markup preserving bullet indentation.
	 */
	public function test_convert_odt_part_rich_text_converts_nested_lists() {
		$html = '<ul><li>Uno</li><li>Dos<ol><li>2.1</li><li>2.2</li></ol></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$result = (string) $result;

		$doc   = new DOMDocument();
		$doc->loadXML( $result );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );

		$breaks = $xpath->query( '//text:line-break' );
		$this->assertGreaterThanOrEqual( 1, $breaks->length );

		$this->assertStringContainsString( '• Uno', $result );
		$this->assertStringContainsString( '• Dos', $result );
		$this->assertStringContainsString( '2.1', $result );
		$this->assertStringContainsString( '2.2', $result );
	}

	/**
	 * It should split inline ODT placeholders with multiple paragraphs into real text:p nodes.
	 */
	public function test_convert_odt_part_rich_text_splits_inline_multi_paragraph_placeholders() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p text:style-name="BodyJustified">Antes '
			. htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 )
			. ' Después</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$paragraphs = $xpath->query( '//office:text/text:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Antes Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo Después', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//office:text//text:line-break' )->length );
		$this->assertSame(
			'BodyJustified',
			$paragraphs->item( 0 )->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'style-name' )
		);
		$this->assertSame(
			'BodyJustified',
			$paragraphs->item( 1 )->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'style-name' )
		);
	}

	/**
	 * It should convert standalone ODT multi-paragraph placeholders into separate paragraphs.
	 */
	public function test_convert_odt_part_rich_text_keeps_standalone_multi_paragraph_placeholders_as_paragraphs() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$paragraphs = $xpath->query( '//office:text/text:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//office:text//text:line-break' )->length );
	}

	/**
	 * It should keep soft line breaks inside a single ODT paragraph.
	 */
	public function test_convert_odt_part_rich_text_keeps_soft_breaks_within_single_paragraph() {
		$html = '<p>Primera línea<br>Segunda línea</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$this->assertSame( 1, $xpath->query( '//office:text/text:p' )->length );
		$this->assertSame( 1, $xpath->query( '//office:text//text:line-break' )->length );
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

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
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

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( 'table:style-name="DocumentateRichTable"', $result );
		$this->assertStringContainsString( 'style:name="DocumentateRichTable"', $result );
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

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
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
	 * Load an ODT XML string into a DOMDocument for assertions.
	 *
	 * @param string $xml XML string.
	 * @return DOMDocument
	 */
	private function load_odt_dom( $xml ) {
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
	 * Create an ODT XPath helper.
	 *
	 * @param DOMDocument $dom DOMDocument instance.
	 * @return DOMXPath
	 */
	private function create_odt_xpath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0' );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );
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
		$result        = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ), $relationships );

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

	/**
	 * It should handle combined formatting (strong + italic + underline).
	 */
	public function test_convert_docx_part_rich_text_handles_combined_formatting() {
		$html = 'Texto <strong><em><u>todo junto</u></em></strong> y normal';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		// Should have bold, italic, and underline in the same run properties
		$runs = $xpath->query( '//w:r[contains(., "todo junto")]' );
		$this->assertGreaterThan( 0, $runs->length, 'Should find run with combined text' );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
	}

	/**
	 * It should handle inline styles with font-weight, font-style, and text-decoration.
	 */
	public function test_convert_docx_part_rich_text_handles_inline_styles() {
		$html = '<span style="font-weight:bold; font-style:italic; text-decoration:underline">Estilizado</span>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
		$this->assertStringContainsString( 'Estilizado', $result );
	}

	/**
	 * It should handle empty formatting tags gracefully.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_tags() {
		$html = 'Antes <strong></strong><em></em><u></u> después';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Antes', $result );
		$this->assertStringContainsString( 'después', $result );
	}

	/**
	 * It should preserve whitespace and line breaks.
	 */
	public function test_convert_docx_part_rich_text_preserves_whitespace() {
		$html = "Texto con\nmúltiples    espacios y\nsaltos de línea";
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Texto', $result );
		$this->assertStringContainsString( 'múltiples', $result );
		$this->assertStringContainsString( 'saltos de línea', $result );
	}

	/**
	 * It should handle special HTML entities.
	 */
	public function test_convert_docx_part_rich_text_handles_entities() {
		$html = 'Símbolos: &lt; &gt; &amp; &quot; &nbsp; &apos;';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Símbolos', $result );
	}

	/**
	 * It should handle tables with colspan without crashing.
	 */
	public function test_convert_docx_part_rich_text_handles_table_colspan() {
		$html = '<table><tr><th colspan="2">Título</th></tr><tr><td>A1</td><td>A2</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length, 'Should create a table' );

		// Check content is present even if colspan is not fully supported
		$this->assertStringContainsString( 'Título', $result );
		$this->assertStringContainsString( 'A1', $result );
		$this->assertStringContainsString( 'A2', $result );
	}

	/**
	 * It should handle tables with rowspan without crashing.
	 */
	public function test_convert_docx_part_rich_text_handles_table_rowspan() {
		$html = '<table><tr><td rowspan="2">Span</td><td>A1</td></tr><tr><td>B1</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length, 'Should create a table' );

		// Check content is present even if rowspan is not fully supported
		$this->assertStringContainsString( 'Span', $result );
		$this->assertStringContainsString( 'A1', $result );
		$this->assertStringContainsString( 'B1', $result );
	}

	/**
	 * It should handle empty tables.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_table() {
		$html = '<table></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		// Should not crash, should handle gracefully
		$this->assertIsString( $result );
	}

	/**
	 * It should handle tables with empty cells.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_cells() {
		$html = '<table><tr><td></td><td>Lleno</td></tr><tr><td>Texto</td><td></td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length );

		$cells = $xpath->query( './/w:tc', $tables->item( 0 ) );
		$this->assertSame( 4, $cells->length );
		$this->assertStringContainsString( 'Lleno', $result );
		$this->assertStringContainsString( 'Texto', $result );
	}

	/**
	 * It should handle deeply nested lists (4+ levels).
	 */
	public function test_convert_docx_part_rich_text_handles_deep_nested_lists() {
		$html = '<ul><li>L1<ul><li>L2<ul><li>L3<ul><li>L4</li></ul></li></ul></li></ul></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'L1', $result );
		$this->assertStringContainsString( 'L2', $result );
		$this->assertStringContainsString( 'L3', $result );
		$this->assertStringContainsString( 'L4', $result );
	}

	/**
	 * It should handle mixed list types (ol inside ul and vice versa).
	 */
	public function test_convert_docx_part_rich_text_handles_mixed_lists() {
		$html = '<ol><li>Num 1<ul><li>Bullet A</li><li>Bullet B</li></ul></li><li>Num 2</li></ol>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Num 1', $result );
		$this->assertStringContainsString( 'Bullet A', $result );
		$this->assertStringContainsString( 'Bullet B', $result );
		$this->assertStringContainsString( 'Num 2', $result );
	}

	/**
	 * It should handle malformed HTML with unclosed tags gracefully.
	 */
	public function test_convert_docx_part_rich_text_handles_unclosed_tags() {
		$html = '<p>Texto <strong>negrita sin cerrar';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		// Should not crash, DOMDocument should recover
		$this->assertStringContainsString( 'Texto', $result );
		$this->assertStringContainsString( 'negrita sin cerrar', $result );
	}

	/**
	 * It should handle empty paragraphs.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_paragraphs() {
		$html = '<p></p><p>Contenido</p><p></p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );
		$this->assertGreaterThan( 0, $paragraphs->length );
		$this->assertStringContainsString( 'Contenido', $result );
	}

	/**
	 * It should handle unicode characters correctly.
	 */
	public function test_convert_docx_part_rich_text_handles_unicode() {
		$html = 'Español: áéíóú ñ Ñ — € ™ © ® • ¿¡';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Español', $result );
	}

	/**
	 * It should handle br tags as line breaks.
	 */
	public function test_convert_docx_part_rich_text_handles_br_tags() {
		$html = 'Primera línea<br>Segunda línea<br/>Tercera línea';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Primera línea', $result );
		$this->assertStringContainsString( 'Segunda línea', $result );
		$this->assertStringContainsString( 'Tercera línea', $result );
		$this->assertStringContainsString( '<w:br', $result );
	}

	/**
	 * It should keep visibility block content when referenced field has array data.
	 */
	public function test_process_visibility_blocks_keeps_content_with_array_data() {
		$content = 'Before [onshow;block=begin;bloc=items]Block content[onshow;block=end] After';
		$fields  = array( 'items' => array( array( 'name' => 'Test' ) ) );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringContainsString( 'Block content', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
		$this->assertStringNotContainsString( '[onshow;block=end]', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should remove visibility block when referenced array field is empty.
	 */
	public function test_process_visibility_blocks_removes_content_with_empty_array() {
		$content = 'Before [onshow;block=begin;bloc=items]Block content[onshow;block=end] After';
		$fields  = array( 'items' => array() );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Block content', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should keep visibility block content when referenced scalar field has value.
	 */
	public function test_process_visibility_blocks_keeps_content_with_scalar_value() {
		$content = 'Before [onshow;block=begin;bloc=total]Total: [total][onshow;block=end] After';
		$fields  = array( 'total' => '1000' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringContainsString( 'Total: [total]', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
	}

	/**
	 * It should remove visibility block when referenced scalar field is empty.
	 */
	public function test_process_visibility_blocks_removes_content_with_empty_scalar() {
		$content = 'Before [onshow;block=begin;bloc=total]Total: [total][onshow;block=end] After';
		$fields  = array( 'total' => '' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Total:', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should remove visibility block when referenced field does not exist.
	 */
	public function test_process_visibility_blocks_removes_content_with_missing_field() {
		$content = 'Before [onshow;block=begin;bloc=missing]Block content[onshow;block=end] After';
		$fields  = array( 'other' => 'value' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Block content', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should collapse fragmented ODT spans to recover placeholders.
	 */
	public function test_normalize_template_placeholders_collapses_odt_spans() {
		$source = '<text:span text:style-name="T6">[</text:span>'
			. '<text:span text:style-name="T7">lugar</text:span>'
			. '<text:span text:style-name="T6">;ope=</text:span>'
			. '<text:span text:style-name="T8">utf8,</text:span>'
			. '<text:span text:style-name="T6">upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should collapse nested ODT spans across multiple levels.
	 */
	public function test_normalize_template_placeholders_collapses_nested_odt_spans() {
		$source = '<text:span text:style-name="T6">[lugar</text:span>'
			. '</text:span>'
			. '<text:span text:style-name="X">'
			. '<text:span text:style-name="T7">;ope=utf8,upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/template.odt' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should collapse fragmented DOCX runs to recover placeholders.
	 */
	public function test_normalize_template_placeholders_collapses_docx_runs() {
		$source = '<w:r><w:t>[lugar</w:t></w:r>'
			. '<w:r><w:t xml:space="preserve">;ope=utf8,upper]</w:t></w:r>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.docx' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should not alter already-intact placeholders.
	 */
	public function test_normalize_template_placeholders_preserves_intact_placeholders() {
		$source = '<text:span text:style-name="T1">[lugar;ope=utf8,upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertSame( $source, $result );
	}

	/**
	 * It should not alter normal text spans (only placeholders).
	 */
	public function test_normalize_template_placeholders_leaves_normal_text_intact() {
		$source = '<text:span text:style-name="T1">Es por ello</text:span>'
			. '<text:span text:style-name="T2"> que se considera</text:span>'
			. '<text:span text:style-name="T1"> de sumo interés</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertSame( $source, $result );
	}

	/**
	 * Helper to call private process_visibility_blocks method.
	 *
	 * @param string $content Template content.
	 * @param array  $fields  Merge fields.
	 * @return string Processed content.
	 */
	private function call_process_visibility_blocks( $content, $fields ) {
		$reflection = new ReflectionClass( 'Documentate_OpenTBS' );
		$method     = $reflection->getMethod( 'process_visibility_blocks' );
		$method->setAccessible( true );
		return $method->invoke( null, $content, $fields );
	}

	// -----------------------------------------------------------------------
	// split_odt_content_paragraphs: double-line-break → real paragraphs
	// -----------------------------------------------------------------------

	/**
	 * Helper: build a minimal content.xml string with a single justified paragraph.
	 *
	 * @param string $span_content Raw XML content for the span (may include line-break elements).
	 * @param string $style        Paragraph style name.
	 * @param string $before_span  Optional XML placed before the main span inside the paragraph.
	 * @return string
	 */
	private function make_odt_xml( $span_content, $style = 'P8', $before_span = '' ) {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content'
			. ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text>'
			. '<text:p text:style-name="' . $style . '">'
			. $before_span
			. '<text:span text:style-name="T1">' . $span_content . '</text:span>'
			. '</text:p>'
			. '</office:text></office:body>'
			. '</office:document-content>';
	}

	/**
	 * A single line-break inside a span must produce two tight paragraphs (no gap between them).
	 */
	public function test_split_odt_single_linebreak_creates_new_paragraph() {
		$xml    = $this->make_odt_xml( 'Línea uno<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>Línea dos' );
		$result = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$paragraphs = $xpath->query( '//text:p' );
		$this->assertSame( 2, $paragraphs->length, 'Single line-break should split into two paragraphs' );
		$this->assertStringContainsString( 'Línea uno', $paragraphs->item( 0 )->textContent );
		$this->assertStringContainsString( 'Línea dos', $paragraphs->item( 1 )->textContent );
		$this->assertSame( 0, $xpath->query( '//text:line-break' )->length, 'Line-break element should be consumed' );
	}

	/**
	 * A double line-break must produce two content paragraphs with an empty gap paragraph between them.
	 */
	public function test_split_odt_double_linebreak_produces_two_paragraphs_with_gap() {
		$lb  = '<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>';
		$xml = $this->make_odt_xml( 'Párrafo uno' . $lb . $lb . 'Párrafo dos' );

		$result = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		// double LB → 2 content paragraphs + 1 empty gap paragraph = 3 total.
		$paragraphs = $xpath->query( '//text:p' );
		$this->assertSame( 3, $paragraphs->length );
		$this->assertStringContainsString( 'Párrafo uno', $paragraphs->item( 0 )->textContent );
		$this->assertSame( '', trim( $paragraphs->item( 1 )->textContent ), 'Middle paragraph should be empty (gap)' );
		$this->assertStringContainsString( 'Párrafo dos', $paragraphs->item( 2 )->textContent );
		$this->assertSame( 0, $xpath->query( '//text:line-break' )->length, 'Paragraph-separator line-breaks must be removed' );
	}

	/**
	 * When the split span has a preceding span (inline prefix), the prefix must
	 * appear in the first generated paragraph and not be duplicated.
	 */
	public function test_split_odt_inline_prefix_preserved_in_first_paragraph() {
		$lb         = '<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>';
		$before     = '<text:span xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" text:style-name="T0">Prefijo </text:span>';
		$xml        = $this->make_odt_xml( 'Párrafo uno' . $lb . $lb . 'Párrafo dos', 'P8', $before );

		$result     = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );
		$xpath      = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		// double LB → 3 paragraphs (content + empty gap + content).
		$paragraphs = $xpath->query( '//text:p' );
		$this->assertSame( 3, $paragraphs->length );
		$this->assertStringContainsString( 'Prefijo', $paragraphs->item( 0 )->textContent );
		$this->assertStringNotContainsString( 'Prefijo', $paragraphs->item( 1 )->textContent );
		$this->assertStringNotContainsString( 'Prefijo', $paragraphs->item( 2 )->textContent );
	}

	/**
	 * Two double line-breaks must produce three content paragraphs with empty gap paragraphs between them.
	 */
	public function test_split_odt_two_double_linebreaks_produce_three_paragraphs() {
		$lb  = '<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>';
		$xml = $this->make_odt_xml( 'Uno' . $lb . $lb . 'Dos' . $lb . $lb . 'Tres' );

		$result     = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );
		$xpath      = $this->create_odt_xpath( $this->load_odt_dom( $result ) );
		$paragraphs = $xpath->query( '//text:p' );

		// 3 content + 2 empty gap paragraphs = 5 total.
		$this->assertSame( 5, $paragraphs->length );
		$this->assertStringContainsString( 'Uno',  $paragraphs->item( 0 )->textContent );
		$this->assertSame( '', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertStringContainsString( 'Dos',  $paragraphs->item( 2 )->textContent );
		$this->assertSame( '', trim( $paragraphs->item( 3 )->textContent ) );
		$this->assertStringContainsString( 'Tres', $paragraphs->item( 4 )->textContent );
	}

	/**
	 * Generated paragraphs must inherit the style of the source paragraph.
	 */
	public function test_split_odt_new_paragraphs_inherit_source_style() {
		$lb     = '<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>';
		$xml    = $this->make_odt_xml( 'A' . $lb . $lb . 'B', 'JustifiedStyle' );
		$result = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );

		$dom   = $this->load_odt_dom( $result );
		$xpath = $this->create_odt_xpath( $dom );

		foreach ( $xpath->query( '//text:p' ) as $para ) {
			$this->assertSame(
				'JustifiedStyle',
				$para->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'style-name' ),
				'Each split paragraph (including gap) should carry the original style'
			);
		}
	}

	/**
	 * A paragraph with no line-breaks at all must pass through unchanged.
	 */
	public function test_split_odt_no_linebreaks_unchanged() {
		$xml    = $this->make_odt_xml( 'Texto sin saltos' );
		$result = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );

		$this->assertSame( $xml, $result, 'XML without line-breaks should be returned unchanged' );
	}

	/**
	 * Regression: a justified paragraph with plain-text paragraph breaks converted by TBS to
	 * line-break sequences must produce separate text:p elements with no remaining line-breaks.
	 */
	public function test_split_odt_regression_justified_paragraph_no_double_linebreaks() {
		$lb  = '<text:line-break xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"/>';
		$xml = $this->make_odt_xml(
			'Primer párrafo con texto largo que debería estar justificado correctamente.'
			. $lb . $lb
			. 'Segundo párrafo que no debería mostrar espacios enormes en la última línea.'
			. $lb . $lb
			. 'Tercer párrafo.',
			'JustifiedBody'
		);

		$result = Documentate_OpenTBS::split_odt_content_paragraphs( $xml );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		// 3 content paragraphs + 2 empty gap paragraphs = 5 total.
		$this->assertSame( 5, $xpath->query( '//text:p' )->length );

		// No line-breaks may remain.
		$this->assertSame( 0, $xpath->query( '//text:line-break' )->length );

		// Original XML should differ from result (transformation happened).
		$this->assertNotSame( $xml, $result );
	}

	// -----------------------------------------------------------------------
	// render_odt: the locale is put back on every exit
	// -----------------------------------------------------------------------

	/**
	 * The render switches LC_TIME so TBS prints Spanish month names. LC_TIME is
	 * process wide, so a render that returns early without putting it back
	 * silently changes date formatting for everything the request does next.
	 * Template pre-processing failing is one of those early exits.
	 */
	public function test_render_odt_restores_the_locale_when_pre_processing_fails() {
		$template = $this->write_odt_with_visibility_block();
		$dest     = wp_tempnam( 'documentate-odt-out' );

		$ambient = setlocale( LC_TIME, 0 );
		$limit   = ini_get( 'pcre.backtrack_limit' );
		$jit     = ini_get( 'pcre.jit' );
		setlocale( LC_TIME, 'C' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		try {
			$result = Documentate_OpenTBS::render_odt( $template, array( 'lista' => array( array( 'x' => 1 ) ) ), $dest );
			$after  = setlocale( LC_TIME, 0 );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
			ini_set( 'pcre.jit', $jit );
			setlocale( LC_TIME, $ambient );
			foreach ( array( $template, $dest ) as $path ) {
				if ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_regex_error', $result->get_error_code() );
		$this->assertSame( 'C', $after );
	}

	/**
	 * Write a minimal ODT whose content carries a visibility block, so the
	 * pre-processing regular expression has real work to do on it.
	 *
	 * @return string Absolute path to the ODT.
	 */
	private function write_odt_with_visibility_block() {
		$content = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text>'
			. '<text:p>[onshow;block=begin;bloc=lista]</text:p>'
			. str_repeat( '<text:p>Relacion de conceptos facturados</text:p>', 40 )
			. '<text:p>[onshow;block=end]</text:p>'
			. '</office:text></office:body></office:document-content>';

		// OpenTBS picks the archive format from the file extension, so the
		// template has to be called .odt and not the .tmp wp_tempnam() hands out.
		$path = wp_tempnam( 'documentate-odt-locale' ) . '.odt';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'mimetype', 'application/vnd.oasis.opendocument.text' );
		$zip->addFromString( 'content.xml', $content );
		$zip->addFromString( 'styles.xml', '<?xml version="1.0"?><office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"/>' );
		$zip->addFromString( 'meta.xml', '<?xml version="1.0"?><meta/>' );
		$zip->close();

		return $path;
	}
}
