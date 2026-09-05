<?php
/**
 * Tests for the rows the generic PDF layout prints.
 *
 * The generic layout injects `html` verbatim and escapes `text`, so which of
 * the two a value lands in decides whether a reader sees formatting or tags.
 * The table a repeater becomes is built here rather than merged, so it is also
 * the one place where stored data could reach the layout as markup.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Generic_Rows.
 */
class DocumentatePdfGenericRowsTest extends WP_UnitTestCase {

	/**
	 * A plain value is escaped by the layout, so it goes in `text`.
	 */
	public function test_a_plain_scalar_goes_in_the_text_column() {
		$row = Documentate_Pdf_Generic_Rows::scalar( 'Nombre', 'Ana & Luis', false );

		$this->assertSame(
			array(
				'label' => 'Nombre',
				'text'  => 'Ana & Luis',
				'html'  => '',
			),
			$row
		);
	}

	/**
	 * A rich value is injected verbatim, so it goes in `html`. Putting it in
	 * `text` would print its tags instead of drawing them.
	 */
	public function test_a_rich_scalar_goes_in_the_html_column() {
		$row = Documentate_Pdf_Generic_Rows::scalar( 'Cuerpo', '<p>Con <strong>énfasis</strong></p>', true );

		$this->assertSame( '', $row['text'] );
		$this->assertSame( '<p>Con <strong>énfasis</strong></p>', $row['html'] );
	}

	/**
	 * A value that is not a string is still printed.
	 *
	 * A number field is normalised to an int or a float and a boolean field to
	 * 1 or 0, so a row that kept only strings would silently drop them. Zero
	 * is the one that a falsy guard would swallow, and zero is an amount.
	 *
	 * @dataProvider provide_non_string_values
	 *
	 * @param mixed  $value    Prepared field value.
	 * @param string $expected Text the row should carry.
	 */
	public function test_a_value_that_is_not_a_string_is_still_printed( $value, $expected ) {
		$row = Documentate_Pdf_Generic_Rows::scalar( 'Importe', $value, false );

		$this->assertSame( $expected, $row['text'] );
	}

	/**
	 * Values a normaliser hands back, and the text they should print as.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function provide_non_string_values() {
		return array(
			'integer'          => array( 1234, '1234' ),
			'float'            => array( 99.5, '99.5' ),
			'zero'             => array( 0, '0' ),
			'boolean as one'   => array( 1, '1' ),
			'boolean as zero'  => array( 0, '0' ),
			'true'             => array( true, '1' ),
			'nothing at all'   => array( null, '' ),
			'an array of rows' => array( array( 'a' ), '' ),
		);
	}

	/**
	 * The header of a repeater table reads the labels of the item schema, in
	 * the order the schema declares them.
	 */
	public function test_the_table_header_reads_the_item_schema_labels_in_order() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Anexos',
			array(
				'code'  => array( 'label' => 'Código' ),
				'title' => array( 'label' => 'Título' ),
			),
			array(
				array(
					'code'  => 'Anexo I',
					'title' => 'Finalidad',
				),
			)
		);

		$this->assertSame( 'Anexos', $row['label'] );
		$this->assertSame( '', $row['text'] );
		$this->assertStringContainsString( '<thead><tr><th>Código</th><th>Título</th></tr></thead>', $row['html'] );
		$this->assertStringContainsString( '<tr><td>Anexo I</td><td>Finalidad</td></tr>', $row['html'] );
	}

	/**
	 * An item field with no label of its own is headed by its key rather than
	 * by a blank cell.
	 */
	public function test_an_unlabelled_item_field_is_headed_by_its_key() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Filas',
			array( 'code' => array( 'label' => '' ) ),
			array( array( 'code' => 'A' ) )
		);

		$this->assertStringContainsString( '<th>code</th>', $row['html'] );
	}

	/**
	 * A cell prints the text of its markup: the tags are stripped and what is
	 * left is escaped, so a stored value can neither print its own tags nor
	 * close the table it is drawn into and reach the rest of the page.
	 */
	public function test_a_cell_cannot_carry_its_own_markup_into_the_layout() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Filas',
			array( 'texto' => array( 'label' => 'Texto' ) ),
			array( array( 'texto' => '</td></tr></table><h1>Suelto</h1> a & b' ) )
		);

		$this->assertStringContainsString( '<td>Suelto a &amp; b</td>', $row['html'] );
		$this->assertStringNotContainsString( '<h1>', $row['html'] );
		$this->assertSame( 1, substr_count( $row['html'], '</table>' ) );
	}

	/**
	 * The schema decides the columns, so a record carrying a key the schema no
	 * longer declares does not widen the table for every other record.
	 */
	public function test_a_record_key_outside_the_schema_adds_no_column() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Filas',
			array( 'code' => array( 'label' => 'Código' ) ),
			array(
				array(
					'code'    => 'A',
					'retired' => 'no debe salir',
				),
			)
		);

		$this->assertStringNotContainsString( 'no debe salir', $row['html'] );
		$this->assertSame( 1, substr_count( $row['html'], '<th>' ) );
	}

	/**
	 * A repeater whose schema declares no item fields still prints its records,
	 * headed by the keys the records themselves carry.
	 */
	public function test_a_repeater_without_an_item_schema_reads_its_columns_off_the_records() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Filas',
			array(),
			array(
				array( 'uno' => 'A' ),
				array(
					'uno' => 'B',
					'dos' => 'C',
				),
			)
		);

		$this->assertStringContainsString( '<th>uno</th><th>dos</th>', $row['html'] );
		$this->assertStringContainsString( '<tr><td>A</td><td></td></tr>', $row['html'] );
		$this->assertStringContainsString( '<tr><td>B</td><td>C</td></tr>', $row['html'] );
	}

	/**
	 * A repeater with no records draws no table at all: an empty grid under a
	 * heading says less than nothing.
	 */
	public function test_a_repeater_with_no_records_draws_no_table() {
		$row = Documentate_Pdf_Generic_Rows::repeater( 'Filas', array( 'code' => array( 'label' => 'Código' ) ), array() );

		$this->assertSame(
			array(
				'label' => 'Filas',
				'text'  => '',
				'html'  => '',
			),
			$row
		);
	}

	/**
	 * A record that is not a list of cells is skipped rather than drawn as a
	 * broken row.
	 */
	public function test_a_record_that_is_not_a_row_is_skipped() {
		$row = Documentate_Pdf_Generic_Rows::repeater(
			'Filas',
			array( 'code' => array( 'label' => 'Código' ) ),
			array( 'suelto', array( 'code' => 'A' ) )
		);

		$this->assertStringNotContainsString( 'suelto', $row['html'] );
		$this->assertSame( 1, substr_count( $row['html'], '<tr><td>' ) );
	}
}
