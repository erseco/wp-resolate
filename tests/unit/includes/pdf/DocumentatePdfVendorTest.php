<?php
/**
 * Tests for the FPDF runtime copy vendored under admin/vendor/.
 *
 * @package Documentate
 */

/**
 * Test class for the vendored FPDF library.
 */
class DocumentatePdfVendorTest extends WP_UnitTestCase {

	/**
	 * The runtime copy ships fpdf.php and its core font metrics, and renders a PDF.
	 */
	public function test_fpdf_runtime_copy_is_present_and_loads() {
		$path = DOCUMENTATE_PLUGIN_DIR . 'admin/vendor/setasign/fpdf/fpdf.php';
		$this->assertFileExists( $path );
		$this->assertFileExists( DOCUMENTATE_PLUGIN_DIR . 'admin/vendor/setasign/fpdf/font/times.json' );
		require_once $path;
		$this->assertTrue( class_exists( 'FPDF', false ) );
		$pdf = new FPDF();
		$pdf->AddPage();
		$pdf->SetFont( 'Times', '', 11 );
		$pdf->Cell( 0, 10, 'Documentate' );
		$this->assertStringStartsWith( '%PDF-', $pdf->Output( 'S' ) );
	}
}
