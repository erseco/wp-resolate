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
}
