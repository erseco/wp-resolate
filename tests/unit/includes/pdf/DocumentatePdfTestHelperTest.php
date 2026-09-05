<?php
/**
 * Tests for the PDF text extraction helper used by the renderer test suite.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Test_Helper.
 */
class DocumentatePdfTestHelperTest extends WP_UnitTestCase {

	/**
	 * Points per millimetre, the unit conversion FPDF applies to coordinates.
	 */
	private const POINTS_PER_MM = 72 / 25.4;

	/**
	 * Height of an A4 page in points, as FPDF writes it in /MediaBox.
	 */
	private const A4_HEIGHT_PT = 841.89;

	/**
	 * Load the vendored FPDF runtime once per test.
	 */
	public function set_up() {
		parent::set_up();
		require_once DOCUMENTATE_PLUGIN_DIR . 'admin/vendor/setasign/fpdf/fpdf.php';
	}

	/**
	 * Text is read back from both plain and Flate compressed content streams.
	 */
	public function test_text_ops_reads_uncompressed_and_compressed_streams() {
		foreach ( array( false, true ) as $compress ) {
			$pdf = new FPDF();
			$pdf->SetCompression( $compress );
			$pdf->AddPage();
			$pdf->SetFont( 'Times', '', 11 );
			$pdf->Cell( 0, 10, 'Hola' );
			$pdf->AddPage();
			$pdf->Cell( 0, 10, 'Adi' . chr( 243 ) . 's' );
			$bytes = $pdf->Output( 'S' );

			$this->assertSame( 2, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
			$this->assertSame( array( 'Hola', 'Adiós' ), Documentate_Pdf_Test_Helper::texts( $bytes ) );

			$ops = Documentate_Pdf_Test_Helper::text_ops( $bytes );
			$this->assertCount( 2, $ops );
			$this->assertSame( 1, $ops[0]['page'] );
			$this->assertSame( 2, $ops[1]['page'] );
		}
	}

	/**
	 * Each operation carries the point coordinates FPDF wrote for it.
	 */
	public function test_text_ops_reports_the_coordinates_of_each_operation() {
		$pdf = new FPDF();
		$pdf->AddPage();
		$pdf->SetFont( 'Times', '', 11 );
		$pdf->Text( 20, 30, 'Marca' );

		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$this->assertCount( 1, $ops );
		$this->assertSame( 'Marca', $ops[0]['text'] );
		$this->assertEqualsWithDelta( 20 * self::POINTS_PER_MM, $ops[0]['x'], 0.01 );
		$this->assertEqualsWithDelta( self::A4_HEIGHT_PT - 30 * self::POINTS_PER_MM, $ops[0]['y'], 0.01 );
	}

	/**
	 * Pages are numbered from the page objects, so a page without text still counts.
	 */
	public function test_page_index_follows_page_objects_when_an_earlier_page_has_no_text() {
		// No font is set before the first AddPage(), so page one holds no BT operator.
		$pdf = new FPDF();
		$pdf->AddPage();
		$pdf->AddPage();
		$pdf->SetFont( 'Times', '', 11 );
		$pdf->Cell( 0, 10, 'Solo' );
		$bytes = $pdf->Output( 'S' );

		$this->assertSame( 2, Documentate_Pdf_Test_Helper::page_count( $bytes ) );

		$ops = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$this->assertCount( 1, $ops );
		$this->assertSame( 'Solo', $ops[0]['text'] );
		$this->assertSame( 2, $ops[0]['page'] );
	}

	/**
	 * Image streams are never scanned, whatever their raw bytes happen to contain.
	 */
	public function test_image_xobjects_do_not_contribute_text_or_pages() {
		$decoy = 'BT 10.00 700.00 Td (GHOST) Tj ET';
		$path  = $this->write_jpeg_carrying( $decoy );

		try {
			$pdf = new FPDF();
			$pdf->AddPage();
			$pdf->SetFont( 'Times', '', 11 );
			$pdf->Cell( 0, 10, 'Primera' );
			// The temporary file has no extension, so the type is declared here.
			$pdf->Image( $path, 10, 40, 20, 20, 'JPG' );
			$pdf->AddPage();
			$pdf->Cell( 0, 10, 'Segunda' );
			$bytes = $pdf->Output( 'S' );
		} finally {
			unlink( $path );
		}

		// The decoy really is in the file, so the assertions below are worth something.
		$this->assertStringContainsString( $decoy, $bytes );

		$this->assertSame( 2, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( array( 'Primera', 'Segunda' ), Documentate_Pdf_Test_Helper::texts( $bytes ) );

		$ops = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$this->assertSame( array( 1, 2 ), array_column( $ops, 'page' ) );
	}

	/**
	 * The same image drawn on two pages yields one placement per page.
	 *
	 * This is what counting `/Subtype /Image` cannot tell you: the dictionary is
	 * written once, so only the invocations say where the image was drawn.
	 */
	public function test_image_ops_attributes_each_placement_to_its_page() {
		$path = $this->write_jpeg_carrying( 'x' );

		try {
			$pdf = new FPDF();
			$pdf->AddPage();
			$pdf->Image( $path, 10, 40, 20, 20, 'JPG' );
			$pdf->AddPage();
			$pdf->AddPage();
			$pdf->Image( $path, 30, 50, 20, 20, 'JPG' );
			$bytes = $pdf->Output( 'S' );
		} finally {
			unlink( $path );
		}

		$this->assertSame( 1, preg_match_all( '#/Subtype /Image#', $bytes ) );

		$ops = Documentate_Pdf_Test_Helper::image_ops( $bytes );
		$this->assertCount( 2, $ops );
		$this->assertSame( array( 1, 3 ), array_column( $ops, 'page' ) );
		$this->assertSame( array( 'I1', 'I1' ), array_column( $ops, 'name' ) );
	}

	/**
	 * Each placement carries the size and point coordinates FPDF wrote for it.
	 */
	public function test_image_ops_reports_the_geometry_of_each_placement() {
		$path = $this->write_jpeg_carrying( 'x' );

		try {
			$pdf = new FPDF();
			$pdf->AddPage();
			$pdf->Image( $path, 10, 40, 20, 25, 'JPG' );
			$ops = Documentate_Pdf_Test_Helper::image_ops( $pdf->Output( 'S' ) );
		} finally {
			unlink( $path );
		}

		$this->assertCount( 1, $ops );
		$this->assertEqualsWithDelta( 20 * self::POINTS_PER_MM, $ops[0]['w'], 0.01 );
		$this->assertEqualsWithDelta( 25 * self::POINTS_PER_MM, $ops[0]['h'], 0.01 );
		$this->assertEqualsWithDelta( 10 * self::POINTS_PER_MM, $ops[0]['x'], 0.01 );
		// The PDF origin is the foot of the page, so y is the bottom of the image.
		$this->assertEqualsWithDelta( self::A4_HEIGHT_PT - ( 65 * self::POINTS_PER_MM ), $ops[0]['y'], 0.01 );
	}

	/**
	 * Characters FPDF escapes inside a PDF string are restored verbatim.
	 */
	public function test_texts_restores_escaped_characters() {
		$original = "Ref. (A\\B) 50%\rrota";

		$pdf = new FPDF();
		$pdf->AddPage();
		$pdf->SetFont( 'Times', '', 11 );
		$pdf->Cell( 0, 10, $original );

		$this->assertSame( array( $original ), Documentate_Pdf_Test_Helper::texts( $pdf->Output( 'S' ) ) );
	}

	/**
	 * Only /Type /Page objects are counted, never the /Type /Pages tree node.
	 */
	public function test_page_count_ignores_the_pages_tree_node() {
		$pdf = new FPDF();
		$pdf->AddPage();
		$pdf->SetFont( 'Times', '', 11 );
		$pdf->Cell( 0, 10, 'Una' );
		$pdf->AddPage();
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );

		$this->assertStringContainsString( '/Type /Pages', $bytes );
		$this->assertSame( 3, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
	}

	/**
	 * A stream whose /Length entry does not fit is still read up to endstream.
	 */
	public function test_streams_with_an_unusable_length_entry_are_still_read() {
		$bytes = "%PDF-1.3\n"
			. "3 0 obj\n<</Type /Page\n/Parent 1 0 R\n/Contents 4 0 R>>\nendobj\n"
			. "4 0 obj\n<</Length 9999>>\nstream\nBT 10.00 20.00 Td (Roto) Tj ET\nendstream\nendobj\n"
			. "trailer\n<</Size 5>>\n%%EOF\n";

		$this->assertSame( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( array( 'Roto' ), Documentate_Pdf_Test_Helper::texts( $bytes ) );
	}

	/**
	 * Bytes that are not a PDF yield empty results instead of warnings.
	 */
	public function test_helpers_ignore_bytes_that_are_not_a_pdf() {
		$this->assertSame( 0, Documentate_Pdf_Test_Helper::page_count( 'no soy un PDF' ) );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::text_ops( 'no soy un PDF' ) );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::texts( '' ) );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::image_ops( 'no soy un PDF' ) );
	}

	/**
	 * Write a small JPEG whose raw bytes carry the given string in a comment segment.
	 *
	 * The comment survives into the PDF untouched, because FPDF embeds JPEG files
	 * as /DCTDecode streams without re-encoding them.
	 *
	 * @param string $payload ASCII text to hide inside the JPEG.
	 * @return string Path of the temporary file, which the caller must delete.
	 */
	private function write_jpeg_carrying( $payload ) {
		$image = imagecreatetruecolor( 8, 8 );
		imagefilledrectangle( $image, 0, 0, 7, 7, imagecolorallocate( $image, 200, 30, 30 ) );
		ob_start();
		imagejpeg( $image, null, 90 );
		$jpeg = ob_get_clean();
		imagedestroy( $image );

		$comment = "\xFF\xFE" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
		$path    = tempnam( sys_get_temp_dir(), 'documentate-decoy' );
		file_put_contents( $path, substr( $jpeg, 0, 2 ) . $comment . substr( $jpeg, 2 ) );

		return $path;
	}
}
