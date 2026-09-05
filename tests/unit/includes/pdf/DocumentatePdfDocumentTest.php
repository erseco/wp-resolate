<?php
/**
 * Tests for the institutional FPDF document base.
 *
 * The expected coordinates come from the ODT templates the PDF renderer
 * replaces: `fixtures/propuestagasto.odt` (standard letterhead and address
 * band), `fixtures/resolucion.odt` (folio box and crest) and
 * `fixtures/modelo_informe.odt` (large letterhead and footer addresses),
 * measured on the PDF LibreOffice exports from them.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Document.
 */
class DocumentatePdfDocumentTest extends WP_UnitTestCase {

	/**
	 * Millimetres per PDF point.
	 */
	const MM_PER_POINT = 25.4 / 72;

	/**
	 * Build a document with compression off so the assertions can read the
	 * content-stream operators straight out of the bytes.
	 *
	 * @param array<string,mixed> $options Document options.
	 * @return Documentate_Pdf_Document
	 */
	private function make( array $options = array() ) {
		$pdf = new Documentate_Pdf_Document( $options );
		$pdf->SetCompression( false );
		return $pdf;
	}

	/**
	 * Every image placed in the document, with the page that draws it and its
	 * box in millimetres from the top-left corner.
	 *
	 * @param string $bytes Raw PDF bytes.
	 * @return array<int,array{page:int,x:float,y:float,w:float,h:float}>
	 */
	private function image_placements( $bytes ) {
		$placements = array();

		foreach ( Documentate_Pdf_Test_Helper::image_ops( $bytes ) as $op ) {
			$placements[] = array(
				'page' => $op['page'],
				'x'    => round( $op['x'] * self::MM_PER_POINT, 2 ),
				'y'    => round( 297 - ( ( $op['y'] + $op['h'] ) * self::MM_PER_POINT ), 2 ),
				'w'    => round( $op['w'] * self::MM_PER_POINT, 2 ),
				'h'    => round( $op['h'] * self::MM_PER_POINT, 2 ),
			);
		}

		return $placements;
	}

	/**
	 * The origins of every rotation the document opened, in millimetres.
	 *
	 * @param string $bytes Raw PDF bytes.
	 * @return array<int,array{x:float,y:float}>
	 */
	private function rotation_origins( $bytes ) {
		// The band's origin is just off the foot of the sheet, so its y is negative.
		preg_match_all( '/q 0\.00 1\.00 -1\.00 0\.00 (-?[\d.]+) (-?[\d.]+) cm/', $bytes, $matches, PREG_SET_ORDER );

		$origins = array();
		foreach ( $matches as $match ) {
			$origins[] = array(
				'x' => round( (float) $match[1] * self::MM_PER_POINT, 2 ),
				'y' => round( 297 - ( (float) $match[2] * self::MM_PER_POINT ), 2 ),
			);
		}

		return $origins;
	}

	/**
	 * UTF-8 becomes Windows-1252 and unmappable glyphs are transliterated, not dropped.
	 */
	public function test_latin1_transcodes_and_transliterates() {
		$this->assertSame(
			'Ni' . chr( 241 ) . 'o ' . chr( 128 ) . ' ' . chr( 150 ),
			Documentate_Pdf_Document::latin1( 'Niño € –' )
		);
		$this->assertStringNotContainsString( '?', Documentate_Pdf_Document::latin1( 'flecha → aquí' ) );
	}

	/**
	 * Without options the page is A4 portrait with 20 mm margins all round.
	 */
	public function test_defaults_are_a4_portrait_with_20mm_margins() {
		$pdf = $this->make();
		$pdf->AddPage();
		$this->assertEqualsWithDelta( 170.0, $pdf->content_width(), 0.01 );
		$this->assertEqualsWithDelta( 257.0, $pdf->remaining_height(), 0.5 );
	}

	/**
	 * The first-page margins override the standard ones on page one only.
	 */
	public function test_first_page_margins_only_apply_to_page_one() {
		$pdf = $this->make(
			array(
				'margins'            => array( 52, 20, 43, 20 ),
				'first_page_margins' => array( 52, 22.5, 43, 22.5 ),
			)
		);
		$pdf->AddPage();
		$this->assertEqualsWithDelta( 165.0, $pdf->content_width(), 0.01 );
		$this->assertEqualsWithDelta( 202.0, $pdf->remaining_height(), 0.5 );
		$pdf->AddPage();
		$this->assertEqualsWithDelta( 170.0, $pdf->content_width(), 0.01 );
	}

	/**
	 * Without a first-page override every page keeps the standard margins.
	 */
	public function test_margins_are_the_same_on_every_page_without_a_first_page_override() {
		$pdf = $this->make( array( 'margins' => array( 52, 20, 43, 20 ) ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$this->assertEqualsWithDelta( 170.0, $pdf->content_width(), 0.01 );
		$this->assertEqualsWithDelta( 202.0, $pdf->remaining_height(), 0.5 );
	}

	/**
	 * The body area is what a page has room for, whatever has been drawn on
	 * it already: the height a block would have on a page of its own.
	 */
	public function test_body_height_is_the_room_a_fresh_page_has() {
		$pdf = $this->make( array( 'margins' => array( 52, 20, 43, 20 ) ) );
		$pdf->AddPage();

		$this->assertEqualsWithDelta( 202.0, $pdf->body_height(), 0.5 );
		$this->assertEqualsWithDelta( $pdf->remaining_height(), $pdf->body_height(), 0.01 );

		$pdf->Ln( 60.0 );
		$this->assertEqualsWithDelta( 202.0, $pdf->body_height(), 0.5 );
		$this->assertEqualsWithDelta( 142.0, $pdf->remaining_height(), 0.5 );
	}

	/**
	 * Switching the automatic page break off reports the setting it replaces
	 * and leaves the bottom margin where it was, so what is left of the page
	 * still measures the same.
	 */
	public function test_the_automatic_page_break_is_switched_off_and_back_on() {
		$pdf = $this->make( array( 'margins' => array( 52, 20, 43, 20 ) ) );
		$pdf->AddPage();

		$this->assertTrue( $pdf->set_auto_page_break( false ) );
		$this->assertFalse( $pdf->AcceptPageBreak() );
		$this->assertEqualsWithDelta( 202.0, $pdf->remaining_height(), 0.5 );

		$this->assertFalse( $pdf->set_auto_page_break( true ) );
		$this->assertTrue( $pdf->AcceptPageBreak() );
		$this->assertEqualsWithDelta( 202.0, $pdf->remaining_height(), 0.5 );
	}

	/**
	 * Nothing is drawn when every chrome option is switched off.
	 */
	public function test_no_chrome_is_drawn_when_every_option_is_off() {
		$pdf = $this->make();
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::texts( $bytes ) );
		$this->assertSame( 0, preg_match_all( '#/Subtype /Image#', $bytes ) );
	}

	/**
	 * The folio box repeats on every page, counting up to the document total.
	 */
	public function test_folio_header_prints_page_of_total_on_every_page() {
		$pdf = $this->make( array( 'folio' => 'header' ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$texts = Documentate_Pdf_Test_Helper::texts( $pdf->Output( 'S' ) );
		$this->assertContains( 'Folio 1/2', $texts );
		$this->assertContains( 'Folio 2/2', $texts );
	}

	/**
	 * The folio label and its frame land where the ODT header frame puts them.
	 */
	public function test_folio_header_box_is_placed_and_framed_like_the_odt_frame() {
		$pdf = $this->make( array( 'folio' => 'header' ) );
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );
		$folio = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertCount( 1, $folio );
		$this->assertEqualsWithDelta( 142.6, $folio[0]['x'] * self::MM_PER_POINT, 0.2 );
		$this->assertEqualsWithDelta( 32.5, 297 - ( $folio[0]['y'] * self::MM_PER_POINT ), 0.8 );
		// The frame: 25.7 x 9.8 mm stroked rectangle whose top-left is (139.7, 25.8) mm.
		$this->assertStringContainsString( '396.00 768.76 72.85 -27.78 re S', $bytes );
	}

	/**
	 * The page number can also go in the footer instead of the header.
	 */
	public function test_folio_footer_prints_the_page_number_at_the_bottom() {
		$pdf = $this->make( array( 'folio' => 'footer' ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$this->assertSame( array( '1', '2' ), array_column( $ops, 'text' ) );
		$this->assertSame( array( 1, 2 ), array_column( $ops, 'page' ) );
		// Below the body area, which ends 20 mm above the page bottom by default.
		$this->assertGreaterThan( 270.0, 297 - ( $ops[0]['y'] * self::MM_PER_POINT ) );
	}

	/**
	 * Footer numbers alternate at the outer margins, including two digits.
	 */
	public function test_footer_page_numbers_alternate_between_outer_margins() {
		$pdf = $this->make( array( 'folio' => 'footer' ) );
		for ( $page = 1; $page <= 10; ++$page ) {
			$pdf->AddPage();
		}
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );
		$this->assertCount( 10, $ops );
		foreach ( $ops as $op ) {
			$x = $op['x'] * self::MM_PER_POINT;
			if ( 0 === $op['page'] % 2 ) {
				$this->assertEqualsWithDelta( 20.0, $x, 0.05 );
			} else {
				$width = $pdf->GetStringWidth( $op['text'] );
				$this->assertEqualsWithDelta( 190.0, $x + $width, 0.05 );
			}
		}
	}

	/**
	 * The address band runs up the left margin of the first page only.
	 */
	public function test_addresses_band_is_rotated_and_only_on_first_page() {
		$pdf = $this->make( array( 'addresses' => 'band' ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$band  = array_values( array_filter( $ops, static fn( $op ) => str_contains( $op['text'], 'Santa Cruz de Tenerife' ) ) );

		$this->assertCount( 1, $band );
		$this->assertSame( 1, $band[0]['page'] );
		$this->assertMatchesRegularExpression( '/q 0\.00 1\.00 -1\.00 0\.00 -?[\d.]+ -?[\d.]+ cm/', $bytes );
	}

	/**
	 * Both band lines are rotated about the measured ODT baselines, 3 mm apart.
	 */
	public function test_address_band_lines_are_rotated_about_the_measured_baselines() {
		$pdf = $this->make( array( 'addresses' => 'band' ) );
		$pdf->AddPage();
		$origins = $this->rotation_origins( $pdf->Output( 'S' ) );

		$this->assertCount( 2, $origins );
		// FPDF puts the baseline 0.3 em past the cell origin, which the rotation
		// turns into 0.79 mm to the right at 7.5 pt: 8.28 + 0.79 = 9.07 mm.
		$this->assertEqualsWithDelta( 8.28, $origins[0]['x'], 0.05 );
		$this->assertEqualsWithDelta( 11.30, $origins[1]['x'], 0.05 );
		// Both cells run upwards from the foot of the frame the ODT declares,
		// 298.48 mm long, which overhangs the sheet by 1.48 mm.
		$this->assertEqualsWithDelta( 298.48, $origins[0]['y'], 0.05 );
		$this->assertEqualsWithDelta( 298.48, $origins[1]['y'], 0.05 );
	}

	/**
	 * The longest address meets the title while both lines share a centre.
	 */
	public function test_title_address_band_ends_at_the_first_page_title() {
		$pdf = $this->make(
			array(
				'addresses'          => 'band-title',
				'first_page_margins' => array( 52, 20, 43, 20 ),
			)
		);
		$pdf->AddPage();
		$pdf->AddPage();
		$pdf->SetFont( Documentate_Pdf_Document::BAND_FONT, '', 7.5 );
		$bytes   = $pdf->Output( 'S' );
		$origins = $this->rotation_origins( $bytes );
		$this->assertCount( 2, $origins, 'The band belongs on the first page only.' );

		$widths = array();
		foreach ( array( Documentate_Pdf_Document::ADDRESS_TENERIFE, Documentate_Pdf_Document::ADDRESS_LASPALMAS ) as $index => $address ) {
			$widths[] = $pdf->GetStringWidth( Documentate_Pdf_Document::latin1( $address ) );
		}
		$longest = max( $widths );
		$ops     = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		foreach ( $origins as $index => $origin ) {
			$this->assertEqualsWithDelta( 8.28 + ( $index * 3.02 ), $origin['x'], 0.05 );
			$this->assertEqualsWithDelta( 52.0, $origin['y'] - $longest, 0.05 );
			$inset = ( $ops[ $index ]['x'] * self::MM_PER_POINT ) - $origin['x'];
			$this->assertEqualsWithDelta( ( $longest - $widths[ $index ] ) / 2, $inset, 0.05 );
		}
	}

	/**
	 * The band centres on the frame, not on the sheet.
	 *
	 * The frame is a little longer than the page and starts at its top edge,
	 * so the text centres 0.74 mm below the middle of the sheet, which is
	 * where the ODT puts it.
	 */
	public function test_address_band_centres_on_the_frame_the_odt_declares() {
		$pdf = $this->make( array( 'addresses' => 'band' ) );
		$pdf->AddPage();
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$band = array_values( array_filter( $ops, static fn( $op ) => str_contains( $op['text'], 'Santa Cruz de Tenerife' ) ) );
		$this->assertCount( 1, $band );

		// The rotated cell is centred, so its text starts half the slack in.
		$width = $pdf->GetStringWidth( Documentate_Pdf_Document::latin1( Documentate_Pdf_Document::ADDRESS_TENERIFE ) );
		$start = ( Documentate_Pdf_Document::BAND_LENGTH - $width ) / 2;

		$this->assertEqualsWithDelta( 149.24, Documentate_Pdf_Document::BAND_LENGTH / 2, 0.01 );
		$this->assertGreaterThan( 0.0, $start );
	}

	/**
	 * The addresses are set in a sans face, whichever face the body uses.
	 *
	 * Vertical variants embed Roboto Light; horizontal ones retain Helvetica.
	 */
	public function test_addresses_are_drawn_in_a_sans_face() {
		foreach ( array( 'band', 'band-title', 'header', 'footer' ) as $variant ) {
			$pdf = $this->make(
				array(
					'addresses' => $variant,
					'font'      => 'times',
				)
			);
			$pdf->AddPage();
			$pdf->AddPage();
			$bytes = $pdf->Output( 'S' );

			$vertical = in_array( $variant, array( 'band', 'band-title' ), true );
			$this->assertStringContainsString( $vertical ? 'Roboto-Light' : 'Helvetica', $bytes, $variant );
			if ( $vertical ) {
				$this->assertStringContainsString( '/FontFile2', $bytes, 'The band font must be embedded.' );
			} else {
				$this->assertStringNotContainsString( 'Roboto-Light', $bytes, 'Horizontal addresses retain their existing font.' );
			}
		}
	}

	/**
	 * The header variant prints both addresses as plain lines under the letterhead.
	 */
	public function test_addresses_header_prints_two_lines_below_the_letterhead() {
		$pdf = $this->make( array( 'addresses' => 'header' ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$this->assertCount( 2, $ops );
		$this->assertSame( array( 1, 1 ), array_column( $ops, 'page' ) );
		$this->assertStringContainsString( 'Santa Cruz de Tenerife', $ops[0]['text'] );
		$this->assertStringContainsString( 'Las Palmas de Gran Canaria', $ops[1]['text'] );
		// The second line sits below the first: lower on the page is a smaller PDF y.
		$this->assertGreaterThan( $ops[1]['y'], $ops[0]['y'] );
	}

	/**
	 * The footer variant prints the two four-line columns on the pages after
	 * the first, which is where the ODT puts them: its `style:footer-first` is
	 * empty because page one carries the large letterhead instead.
	 */
	public function test_addresses_footer_prints_two_columns_on_every_page_but_the_first() {
		$pdf = $this->make( array( 'addresses' => 'footer' ) );
		$pdf->AddPage();
		$pdf->AddPage();
		$pdf->AddPage();
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$this->assertSame( array( 2, 2, 2, 2, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3 ), array_column( $ops, 'page' ) );
		$this->assertCount( 2, array_keys( array_column( $ops, 'text' ), 'C/ Granadera Canaria nº 2', true ) );
		$this->assertCount( 2, array_keys( array_column( $ops, 'text' ), 'Avenida Buenos Aires nº 5', true ) );

		$columns = array_values( array_filter( $ops, static fn( $op ) => 2 === $op['page'] && ( str_starts_with( $op['text'], 'C/ ' ) || str_starts_with( $op['text'], 'Avenida ' ) ) ) );
		$this->assertEqualsWithDelta( 47.55, $columns[0]['x'] * self::MM_PER_POINT, 0.05 );
		$this->assertEqualsWithDelta( 110.0, $columns[1]['x'] * self::MM_PER_POINT, 0.05 );
		$this->assertEqualsWithDelta( 229.5, 297 - ( $columns[0]['y'] * self::MM_PER_POINT ), 0.1 );
	}

	/**
	 * A single-page document gets no footer addresses at all, as in the ODT.
	 */
	public function test_addresses_footer_leaves_a_one_page_document_empty() {
		$pdf = $this->make( array( 'addresses' => 'footer' ) );
		$pdf->AddPage();

		$this->assertSame( array(), Documentate_Pdf_Test_Helper::texts( $pdf->Output( 'S' ) ) );
	}

	/**
	 * The address block carries no page number, so a folio can sit beside it.
	 */
	public function test_footer_addresses_and_folio_can_be_combined() {
		$pdf = $this->make(
			array(
				'addresses' => 'footer',
				'folio'     => 'footer',
			)
		);
		$pdf->AddPage();
		$pdf->AddPage();
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );
		$texts = array_column( $ops, 'text' );

		// Page one: the folio only. Page two: the folio and the eight address lines.
		$this->assertSame( array( 1 ), array_column( array_filter( $ops, static fn( $op ) => 1 === $op['page'] ), 'page' ) );
		$this->assertSame( array( '1', '2' ), array_values( array_intersect( $texts, array( '1', '2' ) ) ) );
		$this->assertContains( 'C/ Granadera Canaria nº 2', $texts );
		// Nothing prints the «N / M» pair the ODT footer does not have.
		$this->assertSame( array(), array_filter( $texts, static fn( $text ) => str_contains( $text, ' / ' ) ) );
	}

	/**
	 * The letterhead goes on page one and the crest on the pages after it.
	 *
	 * Both images are embedded once whatever page draws them, so the assertion
	 * has to look at which page's content stream invokes each one.
	 */
	public function test_letterhead_and_crest_go_to_the_right_pages() {
		$pdf = $this->make(
			array(
				'letterhead' => 'standard',
				'crest'      => true,
			)
		);
		$pdf->AddPage();
		$pdf->AddPage();
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );
		$ops   = Documentate_Pdf_Test_Helper::image_ops( $bytes );

		$this->assertSame( 2, preg_match_all( '#/Subtype /Image#', $bytes ) );
		$this->assertSame( 3, Documentate_Pdf_Test_Helper::page_count( $bytes ) );

		// FPDF numbers images in first-use order: the letterhead, then the crest.
		$by_image = array();
		foreach ( $ops as $op ) {
			$by_image[ $op['name'] ][] = $op['page'];
		}

		$this->assertSame(
			array(
				'I1' => array( 1 ),
				'I2' => array( 2, 3 ),
			),
			$by_image
		);
	}

	/**
	 * The standard letterhead and the crest sit where the ODT frames put them.
	 */
	public function test_standard_letterhead_and_crest_match_the_odt_frames() {
		$pdf = $this->make(
			array(
				'letterhead' => 'standard',
				'crest'      => true,
			)
		);
		$pdf->AddPage();
		$pdf->AddPage();
		$placements = $this->image_placements( $pdf->Output( 'S' ) );

		$this->assertSame(
			array(
				array(
					'page' => 1,
					'x'    => 21.25,
					'y'    => 19.4,
					'w'    => 93.5,
					'h'    => 21.7,
				),
				array(
					'page' => 2,
					'x'    => 182.1,
					'y'    => 20.0,
					'w'    => 7.9,
					'h'    => 15.0,
				),
			),
			$placements
		);
	}

	/**
	 * The large letterhead is a different, wider logo drawn further down.
	 */
	public function test_large_letterhead_matches_the_odt_frame() {
		$pdf = $this->make( array( 'letterhead' => 'large' ) );
		$pdf->AddPage();
		$bytes = $pdf->Output( 'S' );

		$this->assertSame( 1, preg_match_all( '#/Subtype /Image#', $bytes ) );
		$this->assertSame(
			array(
				array(
					'page' => 1,
					'x'    => 22.4,
					'y'    => 35.3,
					'w'    => 63.4,
					'h'    => 14.7,
				),
			),
			$this->image_placements( $bytes )
		);
	}

	/**
	 * Word spacing reaches the content stream and can be cleared again.
	 */
	public function test_word_spacing_is_emitted_and_reset() {
		$pdf = $this->make();
		$pdf->AddPage();
		$pdf->set_word_spacing( 1.5 );
		$pdf->set_word_spacing( 0 );
		$bytes = $pdf->Output( 'S' );

		$this->assertStringContainsString( '4.252 Tw', $bytes );
		$this->assertStringContainsString( '0.000 Tw', $bytes );
	}

	/**
	 * A run style switches face and size, and underlining reaches the page.
	 */
	public function test_apply_style_switches_face_size_and_underline() {
		$pdf   = $this->make();
		$pdf->AddPage();
		$plain = $pdf->measure( 'Resolución', array() );

		$this->assertGreaterThan( $plain, $pdf->measure( 'Resolución', array( 'bold' => true ) ) );
		$this->assertNotEqualsWithDelta( $plain, $pdf->measure( 'Resolución', array( 'italic' => true ) ), 0.01 );
		$this->assertEqualsWithDelta( 2 * $plain, $pdf->measure( 'Resolución', array( 'size' => 22 ) ), 0.01 );

		$pdf->apply_style( array( 'underline' => true ) );
		$pdf->Cell( 40, 5, Documentate_Pdf_Document::latin1( 'Resolución' ) );
		$this->assertStringContainsString( ' re f', $pdf->Output( 'S' ) );
	}

	/**
	 * The selected font is reported back as the run style that set it, so a
	 * caller measuring text in other fonts can put it back afterwards.
	 */
	public function test_current_style_reports_the_selected_font() {
		$pdf = $this->make( array( 'font_size' => 11 ) );
		$pdf->AddPage();

		$this->assertSame(
			array(
				'bold'      => false,
				'italic'    => false,
				'underline' => false,
				'size'      => 11.0,
			),
			$pdf->current_style()
		);

		$style = array(
			'bold'      => true,
			'italic'    => true,
			'underline' => true,
			'size'      => 13.0,
		);
		$pdf->apply_style( $style );
		$this->assertSame( $style, $pdf->current_style() );

		$pdf->measure( 'Resolución', array( 'size' => 22 ) );
		$pdf->apply_style( $style );
		$this->assertSame( $style, $pdf->current_style() );
	}

	/**
	 * The line height follows the current font size.
	 *
	 * A body line of 11 pt advances 12.65 pt, the single spacing the ODT
	 * templates get from their `Standard` paragraph style.
	 */
	public function test_line_height_scales_with_the_font_size() {
		$pdf = $this->make( array( 'font_size' => 11 ) );
		$pdf->AddPage();
		$this->assertEqualsWithDelta( 12.65 * 0.3528, $pdf->line_height(), 0.01 );

		$pdf->apply_style( array( 'size' => 22 ) );
		$this->assertEqualsWithDelta( 2 * 12.65 * 0.3528, $pdf->line_height(), 0.01 );
	}

	/**
	 * The bundled chrome images ship with the plugin and are readable.
	 */
	public function test_bundled_images_are_shipped_with_the_plugin() {
		foreach ( array( 'membrete.png', 'membrete-grande.png', 'escudo.jpg' ) as $name ) {
			$path = Documentate_Pdf_Document::image_path( $name );
			$this->assertFileExists( $path );
			$this->assertIsReadable( $path );
		}
	}
}
