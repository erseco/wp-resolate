<?php
/**
 * Tests for the HTML table renderer.
 *
 * The assertions read the operators back out of the PDF bytes, so they pin
 * what a reader sees: which column each cell is drawn in, how far a row grew,
 * where the page breaks fall, which rows repeat after one, and which
 * rectangles were stroked or filled.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Table_Writer.
 */
class DocumentatePdfTableWriterTest extends WP_UnitTestCase {

	/**
	 * PDF points per millimetre, for turning a millimetre position into the
	 * user-space coordinates the helper reports.
	 */
	const POINTS_PER_MM = 72 / 25.4;

	/**
	 * Build a document with compression off so the assertions can read the
	 * content-stream operators straight out of the bytes.
	 *
	 * @return Documentate_Pdf_Document
	 */
	private function document() {
		$pdf = new Documentate_Pdf_Document();
		$pdf->SetCompression( false );
		$pdf->AddPage();

		return $pdf;
	}

	/**
	 * A writer drawing on the given document, through the generic layout.
	 *
	 * @param Documentate_Pdf_Document $pdf Document to draw on.
	 * @return Documentate_Pdf_Html_Writer
	 */
	private function writer( Documentate_Pdf_Document $pdf ) {
		return new Documentate_Pdf_Html_Writer( $pdf, Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' ) );
	}

	/**
	 * Render an HTML fragment and return the PDF bytes.
	 *
	 * @param string $html Fragment to draw.
	 * @return string
	 */
	private function render( $html ) {
		$pdf = $this->document();
		$this->writer( $pdf )->write( $html );

		return $pdf->Output( 'S' );
	}

	/**
	 * Parse a fragment and return its first table element.
	 *
	 * @param string $html Fragment holding a table.
	 * @return DOMElement
	 */
	private function table( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div id="c">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		return $dom->getElementsByTagName( 'table' )->item( 0 );
	}

	/**
	 * The x of every text operation, keyed by the text drawn.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<string,float>
	 */
	private function x_of( $pdf ) {
		return array_column( Documentate_Pdf_Test_Helper::text_ops( $pdf ), 'x', 'text' );
	}

	/**
	 * The y of every text operation, keyed by the text drawn.
	 *
	 * PDF y grows upwards, so a row drawn lower has the smaller value.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<string,float>
	 */
	private function y_of( $pdf ) {
		return array_column( Documentate_Pdf_Test_Helper::text_ops( $pdf ), 'y', 'text' );
	}

	/**
	 * The page every text operation was drawn on, keyed by the text drawn.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<string,int>
	 */
	private function page_of( $pdf ) {
		return array_column( Documentate_Pdf_Test_Helper::text_ops( $pdf ), 'page', 'text' );
	}

	/**
	 * The page the first text operation starting with a prefix was drawn on.
	 *
	 * A cell that wraps is drawn one line at a time, so the text that names a
	 * row is only the head of the operation that carries it.
	 *
	 * @param array<int,array<string,mixed>> $ops    Text operations.
	 * @param string                         $prefix Text the operation starts with.
	 * @return int Page number, or zero when nothing starts with it.
	 */
	private function page_starting_with( array $ops, $prefix ) {
		foreach ( $ops as $op ) {
			if ( 0 === strpos( $op['text'], $prefix ) ) {
				return $op['page'];
			}
		}

		return 0;
	}

	/**
	 * The font resource a piece of text was drawn with, as `Fn`.
	 *
	 * The match is anchored on the nearest `Tf` before the text, so it reports
	 * the font that was actually selected when the text reached the page, not
	 * one a measuring pass happened to load into the file.
	 *
	 * @param string $pdf  Raw PDF bytes, uncompressed.
	 * @param string $text Text drawn.
	 * @return string Resource name, or an empty string when the text is not drawn.
	 */
	private function font_of( $pdf, $text ) {
		$pattern = '#/(F\d+)\s+[\d.]+\s+Tf(?:(?!/F\d+\s+[\d.]+\s+Tf).)*?\(' . preg_quote( $text, '#' ) . '\)\s*Tj#s';

		return preg_match( $pattern, $pdf, $match ) ? $match[1] : '';
	}

	/**
	 * A cell wraps inside its column and the row grows to the tallest cell,
	 * so the row under it starts well below the row above.
	 */
	public function test_cells_wrap_and_row_grows() {
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><tr><td width="20%">corto</td><td>' . str_repeat( 'largo ', 60 ) . '</td></tr><tr><td>fila2</td><td>x</td></tr></table>' ) );
		$texts = array_column( $ops, 'text' );

		$corto = $ops[ array_search( 'corto', $texts, true ) ];
		$fila2 = $ops[ array_search( 'fila2', $texts, true ) ];

		$this->assertGreaterThan( 20, $corto['y'] - $fila2['y'] );
	}

	/**
	 * A table longer than a page breaks between rows, and the head repeats at
	 * the top of every page it spills onto.
	 */
	public function test_header_row_repeats_after_page_break() {
		$rows  = str_repeat( '<tr><td>' . str_repeat( 'celda ', 20 ) . '</td><td>v</td></tr>', 60 );
		$bytes = $this->render( '<table><thead><tr><th>CONCEPTO</th><th>TOTAL</th></tr></thead><tbody>' . $rows . '</tbody></table>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$pages = Documentate_Pdf_Test_Helper::page_count( $bytes );

		$this->assertGreaterThan( 1, $pages );
		$this->assertCount( $pages, array_keys( array_column( $ops, 'text' ), 'CONCEPTO', true ) );

		$first = array();
		foreach ( $ops as $op ) {
			if ( ! isset( $first[ $op['page'] ] ) ) {
				$first[ $op['page'] ] = $op['text'];
			}
		}

		$this->assertSame( array_fill( 1, $pages, 'CONCEPTO' ), $first );
	}

	/**
	 * A row of all-`th` cells is a head even without a `thead` around it.
	 */
	public function test_a_first_row_of_headers_repeats_without_a_thead() {
		$rows  = str_repeat( '<tr><td>' . str_repeat( 'celda ', 20 ) . '</td><td>v</td></tr>', 60 );
		$bytes = $this->render( '<table><tr><th>CABECERA</th><th>IMPORTE</th></tr>' . $rows . '</table>' );

		$this->assertCount(
			Documentate_Pdf_Test_Helper::page_count( $bytes ),
			array_keys( Documentate_Pdf_Test_Helper::texts( $bytes ), 'CABECERA', true )
		);
	}

	/**
	 * A `thead` says the row is a head, whatever its cells are made of.
	 */
	public function test_a_thead_of_plain_cells_still_repeats() {
		$rows  = str_repeat( '<tr><td>' . str_repeat( 'celda ', 20 ) . '</td><td>v</td></tr>', 60 );
		$bytes = $this->render( '<table><thead><tr><td>SUMA</td><td>x</td></tr></thead><tbody>' . $rows . '</tbody></table>' );

		$this->assertCount(
			Documentate_Pdf_Test_Helper::page_count( $bytes ),
			array_keys( Documentate_Pdf_Test_Helper::texts( $bytes ), 'SUMA', true )
		);
	}

	/**
	 * A foot written before the body is still drawn under it, which is what
	 * every browser does and what a totals line needs. Rows written straight
	 * into the table keep their place among the body rows.
	 */
	public function test_a_foot_written_before_the_body_is_drawn_under_it() {
		$ops = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><thead><tr><td>CABECERA</td></tr></thead><tfoot><tr><td>TOTAL</td></tr></tfoot><tr><td>suelta</td></tr><tbody><tr><td>cuerpo</td></tr></tbody></table>' ) );

		$this->assertSame( array( 'CABECERA', 'suelta', 'cuerpo', 'TOTAL' ), array_column( $ops, 'text' ) );
		$this->assertLessThan( $ops[2]['y'], $ops[3]['y'] );
	}

	/**
	 * A first row of plain cells is body content, so it is not repeated.
	 */
	public function test_a_first_row_of_plain_cells_is_not_repeated() {
		$rows  = str_repeat( '<tr><td>' . str_repeat( 'celda ', 20 ) . '</td><td>v</td></tr>', 60 );
		$bytes = $this->render( '<table><tr><td>PRIMERA</td><td>x</td></tr>' . $rows . '</table>' );

		$this->assertGreaterThan( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertCount( 1, array_keys( Documentate_Pdf_Test_Helper::texts( $bytes ), 'PRIMERA', true ) );
	}

	/**
	 * Percentages on the first row set the columns, a `colspan` cell covers
	 * the columns it spans, and the cell after it starts where the column it
	 * opens does.
	 */
	/**
	 * A table narrower than its column keeps the width it declares.
	 *
	 * The ODT templates size their tables in centimetres rather than filling
	 * the text area, so a layout says so and the columns share that width.
	 */
	public function test_table_honours_its_own_declared_width() {
		foreach ( array( '<table width="165mm">', '<table style="width: 165mm">' ) as $open ) {
			$by = $this->x_of( $this->render( $open . '<tr><td>a</td><td>b</td></tr></table>' ) );

			$this->assertEqualsWithDelta( 82.5 * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.01 );
		}

		$half = $this->x_of( $this->render( '<table width="50%"><tr><td>a</td><td>b</td></tr></table>' ) );
		$this->assertEqualsWithDelta( 42.5 * self::POINTS_PER_MM, $half['b'] - $half['a'], 0.01 );
	}

	/**
	 * A width wider than the column, or none at all, fills the column.
	 */
	public function test_table_width_never_overflows_its_column() {
		$full = $this->x_of( $this->render( '<table><tr><td>a</td><td>b</td></tr></table>' ) );

		foreach ( array( '<table width="400mm">', '<table width="150%">', '<table width="auto">' ) as $open ) {
			$by = $this->x_of( $this->render( $open . '<tr><td>a</td><td>b</td></tr></table>' ) );

			$this->assertEqualsWithDelta( $full['b'] - $full['a'], $by['b'] - $by['a'], 0.01 );
		}
	}

	/**
	 * A table can state the cell padding of the template it reproduces.
	 *
	 * The ODT templates do not all use the same `fo:padding`, and the padding
	 * decides both how tall a row is and how wide its content wraps.
	 */
	public function test_table_honours_its_own_cell_padding() {
		$rows = '<tr><th>C</th></tr><tr><td>v</td></tr>';
		$tall = $this->y_of( $this->render( '<table>' . $rows . '</table>' ) );
		$tight = $this->y_of( $this->render( '<table cellpadding="0">' . $rows . '</table>' ) );

		$this->assertEqualsWithDelta(
			2 * Documentate_Pdf_Table_Writer::PADDING * self::POINTS_PER_MM,
			( $tall['C'] - $tall['v'] ) - ( $tight['C'] - $tight['v'] ),
			0.01,
			'Dropping the padding should shorten the head row by the padding above and below it.'
		);

		foreach ( array( 'banana', '-1', '400' ) as $nonsense ) {
			$default = $this->y_of( $this->render( '<table cellpadding="' . $nonsense . '">' . $rows . '</table>' ) );

			$this->assertEqualsWithDelta(
				$tall['C'] - $tall['v'],
				$default['C'] - $default['v'],
				0.01,
				'A padding that is not a sane length should leave the default in place.'
			);
		}
	}

	/**
	 * A cell can drop its border, as the templates do around their totals.
	 */
	public function test_cell_can_ask_for_no_border() {
		$boxed = $this->render( '<table><tr><td>a</td><td>b</td></tr></table>' );
		$open  = $this->render( '<table><tr><td style="border:none">a</td><td>b</td></tr></table>' );

		$this->assertSame( 2, substr_count( $boxed, ' re S' ) );
		$this->assertSame( 1, substr_count( $open, ' re S' ), 'The open cell should draw no box.' );
	}

	/**
	 * A cell can carry the `fo:background-color` its template gives it.
	 */
	public function test_cell_can_ask_for_a_background() {
		$bytes = $this->render( '<table><tr><td style="background:#dee6ef">a</td><td>b</td></tr></table>' );

		$this->assertStringContainsString( '0.871 0.902 0.937 rg', $bytes );
		$this->assertSame( 1, substr_count( $bytes, ' re B' ), 'Only the cell that asked for it should be filled.' );

		$plain = $this->render( '<table><tr><th style="background:none">a</th></tr></table>' );
		$this->assertSame( 0, substr_count( $plain, ' re B' ), 'A head cell can drop its fill too.' );
	}

	/**
	 * A head cell can drop the bold its template does not set.
	 */
	public function test_head_cell_can_ask_for_a_normal_weight() {
		$bold   = $this->render( '<table><tr><th>CABECERA</th></tr></table>' );
		$normal = $this->render( '<table><tr><th style="font-weight:normal">CABECERA</th></tr></table>' );

		$this->assertNotSame( '', $this->font_of( $bold, 'CABECERA' ) );
		$this->assertNotSame(
			$this->font_of( $bold, 'CABECERA' ),
			$this->font_of( $normal, 'CABECERA' ),
			'The head cell should not be set in bold when it asks not to be.'
		);
		$this->assertStringContainsString( '0.871 0.902 0.937 rg', $normal, 'It should keep its fill.' );
	}

	/**
	 * A table that breaks follows the margins of the page it continues on.
	 *
	 * `autorizacionviaje` and `convocatoriareunion` give their first page a
	 * different left margin from the rest, so a table placed against the
	 * page-1 margin would draw its continuation rows outside the column.
	 */
	public function test_table_follows_the_margins_of_the_page_it_continues_on() {
		$pdf = new Documentate_Pdf_Document(
			array(
				'margins'            => array( 20, 15, 20, 30 ),
				'first_page_margins' => array( 20, 22.5, 20, 22.5 ),
			)
		);
		$pdf->SetCompression( false );
		$pdf->AddPage();

		$rows = str_repeat( '<tr><td>fila</td></tr>', 90 );
		$this->writer( $pdf )->write( '<table>' . $rows . '</table>' );

		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );
		$this->assertGreaterThan( 1, max( array_column( $ops, 'page' ) ), 'The table should break across pages.' );

		$by_page = array();
		foreach ( $ops as $op ) {
			$by_page[ $op['page'] ][] = $op['x'];
		}

		// Cell content sits one padding in from the edge of the table.
		$pad = Documentate_Pdf_Table_Writer::PADDING * self::POINTS_PER_MM;

		$this->assertEqualsWithDelta( ( 22.5 * self::POINTS_PER_MM ) + $pad, min( $by_page[1] ), 0.5, 'The first page keeps its own margin.' );
		$this->assertEqualsWithDelta( ( 30.0 * self::POINTS_PER_MM ) + $pad, min( $by_page[2] ), 0.5, 'A continuation row follows the margin of its own page.' );
	}

	public function test_colspan_widths_and_percent_widths() {
		$by = $this->x_of( $this->render( '<table><tr><td width="25%">a</td><td width="25%">b</td><td width="50%">c</td></tr><tr><td colspan="2">ab</td><td>c2</td></tr></table>' ) );

		$this->assertEqualsWithDelta( $by['a'], $by['ab'], 0.01 );
		$this->assertEqualsWithDelta( $by['c'], $by['c2'], 0.01 );
		$this->assertEqualsWithDelta( 0.25 * 170.0 * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.01 );
	}

	/**
	 * A `style="width:N%"` says the same as a `width` attribute.
	 */
	public function test_a_percent_width_can_come_from_the_style_attribute() {
		$by = $this->x_of( $this->render( '<table><tr><td style="width: 30%">a</td><td>b</td></tr></table>' ) );

		$this->assertEqualsWithDelta( 0.3 * 170.0 * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.01 );
	}

	/**
	 * Columns nothing declares a width for share what is left equally.
	 */
	public function test_undeclared_columns_share_the_width_equally() {
		$by = $this->x_of( $this->render( '<table><tr><td>a</td><td>b</td><td>c</td></tr></table>' ) );

		$this->assertEqualsWithDelta( ( 170.0 / 3 ) * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.02 );
		$this->assertEqualsWithDelta( ( 170.0 / 3 ) * self::POINTS_PER_MM, $by['c'] - $by['b'], 0.02 );

		// The table opens at the left margin, its content one padding in.
		$this->assertEqualsWithDelta( ( 20.0 + Documentate_Pdf_Table_Writer::PADDING ) * self::POINTS_PER_MM, $by['a'], 0.02 );
	}

	/**
	 * A row is as tall as its content plus a padding above and below it, and
	 * what comes after the table is set off from it.
	 */
	public function test_a_row_is_as_tall_as_its_content_plus_the_padding() {
		$ops  = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><tr><td>uno</td></tr><tr><td>dos</td></tr></table><p>después</p>' ) );
		$row  = $this->document()->line_height() + Documentate_Pdf_Html_Writer::PARA_SPACING + ( 2 * Documentate_Pdf_Table_Writer::PADDING );
		$next = $row + Documentate_Pdf_Table_Writer::SPACING - Documentate_Pdf_Table_Writer::PADDING;

		$this->assertSame( array( 'uno', 'dos', 'después' ), array_column( $ops, 'text' ) );
		$this->assertEqualsWithDelta( $row * self::POINTS_PER_MM, $ops[0]['y'] - $ops[1]['y'], 0.1 );
		$this->assertEqualsWithDelta( $next * self::POINTS_PER_MM, $ops[1]['y'] - $ops[2]['y'], 0.1 );
	}

	/**
	 * Percentages that ask for more than the table has are scaled down to it,
	 * so the columns still add up to the width available.
	 */
	public function test_widths_asking_for_more_than_the_table_are_scaled_down() {
		$by = $this->x_of( $this->render( '<table><tr><td width="80%">a</td><td width="80%">b</td></tr></table>' ) );

		$this->assertEqualsWithDelta( 0.5 * 170.0 * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.02 );
	}

	/**
	 * A `col` element sets its column, wherever the widths would otherwise
	 * have come from.
	 */
	public function test_column_widths_can_come_from_col_elements() {
		$by = $this->x_of( $this->render( '<table><colgroup><col width="70%"><col width="30%"></colgroup><tr><td>a</td><td>b</td></tr></table>' ) );

		$this->assertEqualsWithDelta( 0.7 * 170.0 * self::POINTS_PER_MM, $by['b'] - $by['a'], 0.01 );
	}

	/**
	 * Every cell is boxed, unless the table asks for no border at all.
	 */
	public function test_borders_drawn_unless_border_zero() {
		$with    = $this->render( '<table><tr><td>a</td><td>b</td></tr></table>' );
		$without = $this->render( '<table border="0"><tr><td>a</td><td>b</td></tr></table>' );

		$this->assertGreaterThan( substr_count( $without, ' re' ), substr_count( $with, ' re' ) );
		$this->assertSame( 2, substr_count( $with, ' re S' ) );
		$this->assertSame( 0, substr_count( $without, ' re' ) );
	}

	/**
	 * A border is ruled at 0.2 mm, however heavy the pen the table was
	 * reached with.
	 */
	public function test_a_border_is_ruled_at_two_tenths_of_a_millimetre() {
		$pdf = $this->document();
		$pdf->SetLineWidth( 2.0 );
		$this->writer( $pdf )->write( '<table><tr><td>a</td></tr></table>' );

		$weight = sprintf( '%.2F', Documentate_Pdf_Table_Writer::BORDER_WIDTH * self::POINTS_PER_MM );

		$this->assertMatchesRegularExpression( '/' . $weight . ' w(?:(?![\d.]+ w).)*? re S/s', $pdf->Output( 'S' ) );
	}

	/**
	 * A header cell is drawn in a different font from a body cell, over a
	 * blue-grey fill. The fill marks the head even when the table has no
	 * borders.
	 */
	public function test_th_is_bold_with_fill() {
		$bytes = $this->render( '<table><tr><th>CABECERA</th><td>cuerpo</td></tr></table>' );

		$this->assertStringContainsString( 'Times-Bold', $bytes );
		$this->assertStringContainsString( '0.871 0.902 0.937 rg', $bytes ); // #dee6ef, as in the templates.
		$this->assertSame( 1, substr_count( $bytes, ' re B' ) );

		$this->assertNotSame( '', $this->font_of( $bytes, 'CABECERA' ) );
		$this->assertNotSame( $this->font_of( $bytes, 'cuerpo' ), $this->font_of( $bytes, 'CABECERA' ) );

		$plain = $this->render( '<table border="0"><tr><th>CABECERA</th></tr></table>' );
		$this->assertSame( 1, substr_count( $plain, ' re f' ) );
	}

	/**
	 * A rich field keeps its paragraphs and its lists inside a cell.
	 */
	public function test_rich_content_in_a_cell_keeps_its_list() {
		$texts = Documentate_Pdf_Test_Helper::texts( $this->render( '<table><tr><td><p>intro</p><ul><li>uno</li><li>dos</li></ul></td></tr></table>' ) );

		$this->assertContains( '•', $texts );
		$this->assertContains( 'dos', $texts );
	}

	/**
	 * libxml inserts no `tbody`, so rows are collected from both shapes and
	 * neither draws more or less than the other.
	 */
	public function test_rows_are_found_with_and_without_tbody() {
		$a = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><tr><td>a</td><td>b</td></tr></table>' ) );
		$b = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>' ) );

		$this->assertCount( 2, $a );
		$this->assertEquals( $a, $b );
	}

	/**
	 * A table that outgrows the page breaks between rows and never inside
	 * one: both cells of every row land on the same page, and no line is
	 * pushed past the bottom margin.
	 */
	public function test_a_row_is_never_split_across_a_page_break() {
		$rows = '';
		for ( $i = 0; $i < 40; $i++ ) {
			$rows .= '<tr><td>izq' . $i . ' ' . str_repeat( 'relleno ', 12 ) . '</td><td>der' . $i . '</td></tr>';
		}

		$bytes = $this->render( '<table>' . $rows . '</table>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertGreaterThan( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );

		for ( $i = 0; $i < 40; $i++ ) {
			$this->assertSame(
				$this->page_starting_with( $ops, 'izq' . $i . ' ' ),
				$this->page_starting_with( $ops, 'der' . $i ),
				'row ' . $i . ' was split across a page break'
			);
		}

		foreach ( $ops as $op ) {
			$this->assertGreaterThan( 20.0 * self::POINTS_PER_MM, $op['y'] );
		}
	}

	/**
	 * A page break asked for from inside a cell is ignored: honouring it
	 * would leave the row's borders on the page before.
	 */
	public function test_a_page_break_inside_a_cell_is_ignored() {
		$bytes = $this->render( '<table><tr><td><p class="page-break">roto</p></td><td>lado</td></tr></table>' );

		$this->assertSame( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( array( 1, 1 ), array_values( $this->page_of( $bytes ) ) );
	}

	/**
	 * A row no page could hold spills onto the pages it needs instead of
	 * running off the sheet. Every word reaches the paper, and the table
	 * carries on under it.
	 */
	public function test_a_row_taller_than_the_page_flows_onto_the_next_one() {
		$bytes = $this->render( '<table><tr><td>' . str_repeat( 'palabra ', 900 ) . '</td><td>lado</td></tr><tr><td>siguiente</td><td>final</td></tr></table>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$texts = array_column( $ops, 'text' );

		$this->assertGreaterThan( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );

		// Not one of the 900 words is lost, and the rest of the table follows.
		$this->assertSame( 900, substr_count( implode( ' ', $texts ), 'palabra' ) );
		$this->assertContains( 'lado', $texts );
		$this->assertContains( 'final', $texts );

		foreach ( $ops as $op ) {
			$this->assertGreaterThan( 20.0 * self::POINTS_PER_MM, $op['y'], 'drawn below the foot of the page' );
			$this->assertLessThan( ( 297.0 - 20.0 ) * self::POINTS_PER_MM, $op['y'], 'drawn above the head of the page' );
		}

		// The row starts where it stood, so no empty page is left in front of it.
		$this->assertSame( 1, $ops[0]['page'] );

		// What follows the cell that spilled is drawn under it, not over it.
		$lado      = array_search( 'lado', $texts, true );
		$siguiente = array_search( 'siguiente', $texts, true );

		$this->assertLessThan( $ops[ $lado - 1 ]['y'], $ops[ $lado ]['y'] );
		$this->assertLessThan( $ops[ $lado ]['y'], $ops[ $siguiente ]['y'] );
	}

	/**
	 * A table that no longer fits starts on the next page as a whole, head
	 * included, and its head is not drawn twice for it.
	 */
	public function test_a_table_that_does_not_fit_starts_on_the_next_page() {
		$pdf    = $this->document();
		$tables = new Documentate_Pdf_Table_Writer( $pdf, $this->writer( $pdf ) );

		$pdf->SetY( 20.0 + $pdf->body_height() - 5.0 );
		$tables->write( $this->table( '<table><thead><tr><th>UNICA</th></tr></thead><tbody><tr><td>fila</td></tr></tbody></table>' ), 20.0, 100.0 );

		$bytes = $pdf->Output( 'S' );

		$this->assertSame( 2, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( array( 'UNICA', 'fila' ), Documentate_Pdf_Test_Helper::texts( $bytes ) );
		$this->assertSame( 2, $this->page_of( $bytes )['UNICA'] );
	}

	/**
	 * A row that fits on a page of its own, but no longer fits once the head
	 * has been repeated above it, flows as well: the head must not push a
	 * line of it into the foot margin.
	 */
	public function test_a_row_squeezed_by_the_repeated_head_flows_too() {
		$head  = '<thead><tr><th>' . str_repeat( 'cabecera ', 110 ) . '</th></tr></thead>';
		$rows  = '<tr><td>corta</td></tr><tr><td>' . str_repeat( 'palabra ', 610 ) . '</td></tr>';
		$bytes = $this->render( '<table>' . $head . '<tbody>' . $rows . '</tbody></table>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertGreaterThan( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( 610, substr_count( implode( ' ', array_column( $ops, 'text' ) ), 'palabra' ) );

		foreach ( $ops as $op ) {
			$this->assertGreaterThan( 20.0 * self::POINTS_PER_MM, $op['y'], 'drawn into the foot margin' );
		}
	}

	/**
	 * A cell is aligned by its own attributes, which is what right-aligns a
	 * money column.
	 */
	public function test_cell_alignment_follows_the_cell() {
		$by = $this->x_of( $this->render( '<table><tr><td>concepto</td><td style="text-align:right">1.234,56</td></tr><tr><td>otro</td><td>izquierda</td></tr></table>' ) );

		$this->assertGreaterThan( $by['izquierda'], $by['1.234,56'] );
	}

	/**
	 * A table inside a cell is laid out inside that cell, not across the page.
	 */
	public function test_a_nested_table_is_laid_out_inside_its_cell() {
		$bytes = $this->render( '<table><tr><td><table><tr><td>dentro</td><td>anidada</td></tr></table></td><td>fuera</td></tr></table>' );
		$by    = $this->x_of( $bytes );

		$this->assertSame( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertGreaterThan( $by['dentro'], $by['anidada'] );
		$this->assertLessThan( $by['fuera'], $by['anidada'] );
	}

	/**
	 * The height a table reports while it is being measured is the height it
	 * takes when it is drawn, which is what lets a cell hold a table.
	 */
	public function test_measure_matches_the_height_the_table_takes() {
		$pdf    = $this->document();
		$tables = new Documentate_Pdf_Table_Writer( $pdf, $this->writer( $pdf ) );
		$table  = $this->table( '<table><tr><th>Concepto</th><th>Importe</th></tr><tr><td>' . str_repeat( 'suministro ', 12 ) . '</td><td>1,00</td></tr></table>' );

		$measured = $tables->measure( $table, 100.0 );
		$before   = $pdf->GetY();
		$tables->write( $table, 20.0, 100.0 );

		$this->assertGreaterThan( 10.0, $measured );
		$this->assertEqualsWithDelta( $measured, $pdf->GetY() - $before, 0.01 );
	}

	/**
	 * A column too narrow to hold its own padding draws its box and no text,
	 * rather than cutting the text one character to a line for ever.
	 */
	public function test_a_column_too_narrow_for_its_padding_draws_no_text() {
		$bytes = $this->render( '<table><tr><td width="1%">' . str_repeat( 'estrecho', 40 ) . '</td><td>ancho</td></tr><tr><td>x</td><td>c</td></tr></table>' );

		$this->assertSame( array( 'ancho', 'c' ), Documentate_Pdf_Test_Helper::texts( $bytes ) );
		$this->assertSame( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
	}

	/**
	 * A caption is drawn above the table it names.
	 */
	public function test_a_caption_is_drawn_above_the_rows() {
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><caption>Relación de gastos</caption><tr><td>fila</td></tr></table>' ) );
		$texts = array_column( $ops, 'text' );

		$this->assertSame( array( 'Relación de gastos', 'fila' ), $texts );
		$this->assertGreaterThan( $ops[1]['y'], $ops[0]['y'] );
	}

	/**
	 * A table with nothing in it draws nothing and moves nothing.
	 */
	public function test_an_empty_table_draws_nothing() {
		$pdf    = $this->document();
		$tables = new Documentate_Pdf_Table_Writer( $pdf, $this->writer( $pdf ) );
		$before = $pdf->GetY();

		$tables->write( $this->table( '<table></table>' ), 20.0, 100.0 );

		$this->assertSame( 0.0, $tables->measure( $this->table( '<table></table>' ), 100.0 ) );
		$this->assertEqualsWithDelta( $before, $pdf->GetY(), 0.001 );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::texts( $pdf->Output( 'S' ) ) );
	}
}
