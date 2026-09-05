<?php
/**
 * FPDF document with the institutional chrome shared by every layout.
 *
 * Every coordinate in this file is a millimetre offset from the top-left
 * corner of an A4 page, measured on the LibreOffice PDF export of the ODT
 * template it reproduces. The template each block comes from is named above
 * the constants that describe it.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

if ( ! class_exists( 'FPDF', false ) ) {
	require_once DOCUMENTATE_PLUGIN_DIR . 'admin/vendor/setasign/fpdf/fpdf.php';
}

// FPDF exposes its geometry and font state as camelCase and PascalCase
// properties. They belong to the parent class and cannot be renamed, so the
// snake_case rule is switched off for this file only.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * A4 portrait page with letterhead, address band, crest and folio.
 *
 * The chrome is drawn from `Header()` and `Footer()`, which FPDF calls inside
 * the page margins, so the body renderer built on top of this class only ever
 * sees the area between them.
 */
class Documentate_Pdf_Document extends FPDF {

	/**
	 * Line spacing as a multiple of the font size.
	 *
	 * Single spacing in the ODT templates, whose paragraphs inherit the
	 * `Standard` style: 12.65 pt of advance for a body line of 11 pt.
	 */
	const LINE_HEIGHT_FACTOR = 1.15;

	/**
	 * Millimetres per PostScript point, for turning a font size into a length.
	 */
	const MM_PER_POINT = 25.4 / 72;

	/**
	 * Tenerife address, as printed on the institutional templates.
	 */
	const ADDRESS_TENERIFE = 'Avda. Buenos Aires 3-5, Edf. Tres de Mayo Planta 4ª | CP 38071 Santa Cruz de Tenerife | Tfno: 922 42 35 00 | Fax: 922 42 38 06';

	/**
	 * Las Palmas address, as printed on the institutional templates.
	 */
	const ADDRESS_LASPALMAS = 'Calle Granadera Canaria 2, Edf. Granadera Canaria Planta 1ª | CP 35071 Las Palmas de Gran Canaria | Tfno: 928 45 54 00 | Fax: 928 45 57 42';

	/**
	 * Face the horizontal address furniture is drawn in.
	 *
	 * Every template asks for a sans-serif for its addresses — Univers LT
	 * Std 45 Light, Tahoma, Trebuchet MS, all `style:font-family-generic`
	 * "swiss" — whatever serif the body of that layout uses. Vertical bands
	 * use the separately bundled BAND_FONT selected for their lighter weight.
	 */
	const ADDRESS_FONT = 'helvetica';

	/**
	 * Bundled light face shared by every vertical address band.
	 */
	const BAND_FONT = 'roboto-light';

	/**
	 * Point size of every address block.
	 */
	const ADDRESS_FONT_SIZE = 7.5;

	/**
	 * Letterhead frame «Imagen2» of propuestagasto.odt: x, y, width, height.
	 */
	const LETTERHEAD_STANDARD = array( 21.25, 19.4, 93.5, 21.7 );

	/**
	 * Resolution ODT image frame, relative to its 20 mm header origin.
	 */
	const LETTERHEAD_RESOLUTION = array( 17.37, 23.55, 97.01, 21.52 );

	/**
	 * Letterhead frame «image1.png» of modelo_informe.odt: x, y, width, height.
	 */
	const LETTERHEAD_LARGE = array( 22.4, 35.3, 63.4, 14.7 );

	/**
	 * Crest frame «Imagen 6» of resolucion.odt, flush with the right margin.
	 */
	const CREST = array( 182.1, 20.0, 7.9, 15.0 );

	/**
	 * Folio frame «Marco2» of resolucion.odt: x, y, width, height.
	 */
	const FOLIO_BOX = array( 139.7, 25.8, 25.7, 9.8 );

	/**
	 * Padding between the folio frame and its label, border included.
	 */
	const FOLIO_TEXT_INSET = 2.9;

	/**
	 * Point size of the folio label.
	 */
	const FOLIO_FONT_SIZE = 11;

	/**
	 * Height of the cell that carries a page number in the footer.
	 */
	const FOLIO_FOOTER_HEIGHT = 5.0;

	/**
	 * Distance from the foot of the body area to the top of the footer folio cell.
	 */
	const FOLIO_FOOTER_LIFT = 4.3;

	/**
	 * Length of the address band, in mm.
	 *
	 * The `svg:width` of the frame the templates rotate into the margin. It
	 * is a little longer than the page, and starts at the top edge, so the
	 * band centres 0.74 mm below the middle of the sheet.
	 */
	const BAND_LENGTH = 298.48;

	/**
	 * Baseline of the first band line, from the left edge of the page.
	 *
	 * With the bundled Roboto Light face, this places the visible band at
	 * 7.09 mm, matching the resolution ODT export rather than the signed PDF.
	 */
	const BAND_BASELINE_X = 9.07;

	/**
	 * Distance between the two band baselines.
	 */
	const BAND_LINE_GAP = 3.02;

	/**
	 * Where the plain header addresses start, and how tall each line is.
	 */
	const HEADER_ADDRESS_Y = 45.5;

	/**
	 * Height of one plain header address line.
	 */
	const HEADER_ADDRESS_LINE = 3.2;

	/**
	 * Footer address block of modelo_informe.odt: top of the first line.
	 */
	const FOOTER_ADDRESS_TOP = 227.1;

	/**
	 * Left edge of each footer address column.
	 */
	const FOOTER_ADDRESS_COLUMNS = array( 47.55, 110.0 );

	/**
	 * Height of one footer address line.
	 */
	const FOOTER_ADDRESS_LINE = 3.24;

	/**
	 * Left-hand footer address column of modelo_informe.odt.
	 */
	const FOOTER_ADDRESS_LEFT = array(
		'C/ Granadera Canaria nº 2',
		'Edificio Granadera Canaria – 1ª planta',
		'35071 Las Palmas de Gran Canaria',
		'Tfno: 928455400  Fax: 928 455742',
	);

	/**
	 * Right-hand footer address column of modelo_informe.odt.
	 */
	const FOOTER_ADDRESS_RIGHT = array(
		'Avenida Buenos Aires nº 5',
		'Edificio Tres de Mayo, 4ª Planta',
		'38071 Santa Cruz de Tenerife',
		'Tfno: 922423500 Fax: 922423806',
	);

	/**
	 * Resolved document options.
	 *
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * Build the document and apply the first-page margins.
	 *
	 * @param array<string,mixed> $options Chrome and page options: `letterhead`
	 *                                     (none|standard|large), `addresses`
	 *                                     (none|band|band-title|header|footer), `folio`
	 *                                     (none|header|footer), `crest` (bool),
	 *                                     `margins` and `first_page_margins`
	 *                                     as (top, right, bottom, left) in mm,
	 *                                     `font` and `font_size`.
	 */
	public function __construct( array $options = array() ) {
		parent::__construct( 'P', 'mm', 'A4' );

		$this->options = wp_parse_args(
			$options,
			array(
				'letterhead'         => 'none',
				'addresses'          => 'none',
				'folio'              => 'none',
				'crest'              => false,
				'margins'            => array( 20, 20, 20, 20 ),
				'first_page_margins' => null,
				'font'               => 'times',
				'font_size'          => 11,
			)
		);

		// Without this, Cell() indents left-aligned text by 1 mm and pushes
		// justified lines the same distance past the right margin.
		$this->cMargin = 0;

		$this->apply_margins( true );
		$this->AliasNbPages();
		$this->SetFont( $this->options['font'], '', $this->options['font_size'] );
		$this->SetCreator( 'Documentate', true );
	}

	/**
	 * Draw the page chrome. FPDF calls this on every AddPage().
	 *
	 * Everything painted here lives inside the top margin, above the body area,
	 * and cannot trigger a page break because FPDF suppresses them while the
	 * header is running.
	 */
	public function Header() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- FPDF hook.
		$first = 1 === $this->PageNo();
		$this->apply_margins( $first );

		if ( $first ) {
			$this->draw_letterhead();
			$this->draw_addresses();
		} elseif ( $this->options['crest'] ) {
			$this->place_image( 'escudo.jpg', self::CREST );
		}

		if ( 'header' === $this->options['folio'] ) {
			$this->draw_folio_box();
		}

		$this->SetXY( $this->lMargin, $this->tMargin );
		$this->SetFont( $this->options['font'], '', $this->options['font_size'] );
	}

	/**
	 * Draw the page foot. FPDF calls this before each page is closed.
	 */
	public function Footer() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- FPDF hook.
		if ( 'footer' === $this->options['addresses'] ) {
			$this->draw_footer_addresses();
		}

		if ( 'footer' === $this->options['folio'] ) {
			$this->draw_footer_folio();
		}
	}

	/**
	 * Draw one line of text rotated counter-clockwise around its cell origin.
	 *
	 * The cell has no height, so FPDF places the baseline 0.3 em past the
	 * origin along the rotated axis.
	 *
	 * @param float  $x         Cell origin X in mm, before rotation.
	 * @param float  $y         Cell origin Y in mm, before rotation.
	 * @param float  $angle_deg Counter-clockwise degrees.
	 * @param string $utf8      Text to draw.
	 * @param float  $width     Cell width in mm, along the rotated axis.
	 * @param string $align     L, C or R.
	 */
	public function rotated_text( $x, $y, $angle_deg, $utf8, $width = 0, $align = 'L' ) {
		$angle = deg2rad( $angle_deg );
		$cx    = $x * $this->k;
		$cy    = ( $this->h - $y ) * $this->k;

		$this->_out(
			sprintf(
				'q %.2F %.2F %.2F %.2F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
				cos( $angle ),
				sin( $angle ),
				- sin( $angle ),
				cos( $angle ),
				$cx,
				$cy,
				- $cx,
				- $cy
			)
		);
		$this->SetXY( $x, $y );
		$this->Cell( $width, 0, self::latin1( $utf8 ), 0, 0, $align );
		$this->_out( 'Q' );
	}

	/**
	 * Left margin of the current page, in mm.
	 *
	 * Page furniture and tables that outlive a page break need it: a layout
	 * may give its first page different margins from the rest.
	 *
	 * @return float
	 */
	public function left_margin() {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- FPDF property.
		return $this->lMargin;
	}

	/**
	 * Width between the left and right margins, in mm.
	 *
	 * @return float
	 */
	public function content_width() {
		return $this->w - $this->lMargin - $this->rMargin;
	}

	/**
	 * Height left on the page before the automatic break triggers, in mm.
	 *
	 * @return float
	 */
	public function remaining_height() {
		return $this->PageBreakTrigger - $this->GetY();
	}

	/**
	 * Height of the body area of a page, in mm.
	 *
	 * It is the most any one block can take without spilling, which is how a
	 * table tells a row that will not fit on this page from one that would
	 * not fit on a page of its own either.
	 *
	 * @return float
	 */
	public function body_height() {
		return $this->PageBreakTrigger - $this->tMargin;
	}

	/**
	 * Switch the automatic page break on or off, keeping the bottom margin.
	 *
	 * A caller that has already reserved the room for what it is about to
	 * draw — a table row it measured before starting it — switches the break
	 * off, so that neither FPDF nor the HTML writer can start a page halfway
	 * through and leave the row's borders behind on the page before.
	 *
	 * @param bool $enabled Whether a page break may be taken.
	 * @return bool The setting that was in force, to hand back afterwards.
	 */
	public function set_auto_page_break( $enabled ) {
		$was = $this->AcceptPageBreak();

		$this->SetAutoPageBreak( (bool) $enabled, $this->bMargin );

		return $was;
	}

	/**
	 * Select the core font face and size a run style asks for.
	 *
	 * @param array<string,mixed> $style Run style: `bold`, `italic`, `underline`, `size`.
	 */
	public function apply_style( array $style ) {
		$face = ( empty( $style['bold'] ) ? '' : 'B' )
			. ( empty( $style['italic'] ) ? '' : 'I' )
			. ( empty( $style['underline'] ) ? '' : 'U' );
		$size = isset( $style['size'] ) ? (float) $style['size'] : (float) $this->options['font_size'];

		$this->SetFont( $this->options['font'], $face, $size );
	}

	/**
	 * The run style that reproduces the font selected right now.
	 *
	 * FPDF keeps its font state to itself, so a caller that has to select
	 * other fonts for a while — measuring a block of text, say — takes this
	 * before it starts and hands it back to `apply_style()` when it is done.
	 * Only the face and the size are reported, which is all `apply_style()`
	 * ever sets: the family is always the one the document was built with.
	 *
	 * @return array<string,mixed>
	 */
	public function current_style() {
		return array(
			'bold'      => false !== strpos( $this->FontStyle, 'B' ),
			'italic'    => false !== strpos( $this->FontStyle, 'I' ),
			'underline' => (bool) $this->underline,
			'size'      => (float) $this->FontSizePt,
		);
	}

	/**
	 * Width of UTF-8 text in the given style, in mm.
	 *
	 * Measuring selects the style, so the caller has to re-apply its own before
	 * drawing again.
	 *
	 * @param string              $text  Text to measure.
	 * @param array<string,mixed> $style Run style, as for apply_style().
	 * @return float
	 */
	public function measure( $text, array $style ) {
		$this->apply_style( $style );

		return $this->GetStringWidth( self::latin1( $text ) );
	}

	/**
	 * Set the PDF word spacing used to justify a line. Zero clears it.
	 *
	 * @param float $mm Extra space per space character, in mm.
	 */
	public function set_word_spacing( $mm ) {
		$this->ws = $mm;
		$this->_out( sprintf( '%.3F Tw', $mm * $this->k ) );
	}

	/**
	 * Line height for the current font size, in mm.
	 *
	 * @return float
	 */
	public function line_height() {
		return $this->FontSizePt * self::MM_PER_POINT * self::LINE_HEIGHT_FACTOR;
	}

	/**
	 * Convert UTF-8 to the Windows-1252 encoding the core fonts use.
	 *
	 * Characters outside cp1252 are transliterated rather than dropped, so a
	 * pasted arrow or ellipsis still reads as something.
	 *
	 * @param string $utf8 Text in UTF-8.
	 * @return string
	 */
	public static function latin1( $utf8 ) {
		$utf8      = (string) $utf8;
		$converted = false;

		if ( function_exists( 'iconv' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv raises a notice for every glyph it has to approximate.
			$converted = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $utf8 );
		}

		if ( false === $converted ) {
			$converted = mb_convert_encoding( $utf8, 'Windows-1252', 'UTF-8' );
		}

		return (string) $converted;
	}

	/**
	 * Absolute path of one of the bundled chrome images.
	 *
	 * @param string $name File name under templates/pdf/img.
	 * @return string
	 */
	public static function image_path( $name ) {
		return DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/img/' . $name;
	}

	/**
	 * Apply the page margins, which the first page may override.
	 *
	 * The top margin is the top of the body area, so it already includes the
	 * height of whatever the header draws above it.
	 *
	 * @param bool $first Whether the page being started is the first one.
	 */
	private function apply_margins( $first ) {
		$margins = $this->options['margins'];
		if ( $first && is_array( $this->options['first_page_margins'] ) ) {
			$margins = $this->options['first_page_margins'];
		}

		list( $top, $right, $bottom, $left ) = array_map( 'floatval', $margins );

		$this->SetMargins( $left, $top, $right );
		$this->SetAutoPageBreak( true, $bottom );
	}

	/**
	 * Draw the letterhead logo the layout asked for.
	 */
	private function draw_letterhead() {
		switch ( $this->options['letterhead'] ) {
			case 'resolution':
				$this->place_image( 'membrete.png', self::LETTERHEAD_RESOLUTION );
				break;
			case 'standard':
				$this->place_image( 'membrete.png', self::LETTERHEAD_STANDARD );
				break;
			case 'large':
				$this->place_image( 'membrete-grande.png', self::LETTERHEAD_LARGE );
				break;
		}
	}

	/**
	 * Draw the addresses the layout keeps in the header.
	 */
	private function draw_addresses() {
		switch ( $this->options['addresses'] ) {
			case 'band':
				$this->draw_address_band();
				break;
			case 'band-title':
				$this->draw_title_address_band();
				break;
			case 'header':
				$this->draw_header_addresses();
				break;
		}
	}

	/**
	 * Draw both addresses as a band running up the left margin.
	 *
	 * Each line is a full-page-height cell, centred, rotated a quarter turn
	 * counter-clockwise about its own origin.
	 */
	private function draw_address_band() {
		$this->select_band_font();
		$baseline = 0.3 * $this->FontSize;

		$this->rotated_text( self::BAND_BASELINE_X - $baseline, self::BAND_LENGTH, 90, self::ADDRESS_TENERIFE, self::BAND_LENGTH, 'C' );
		$this->rotated_text( self::BAND_BASELINE_X + self::BAND_LINE_GAP - $baseline, self::BAND_LENGTH, 90, self::ADDRESS_LASPALMAS, self::BAND_LENGTH, 'C' );
	}

	/**
	 * Align the longest address with the title, keeping both lines centred.
	 *
	 * The fixtures centre both paragraphs within one rotated frame. Use the
	 * longest line as the common cell width and move the frame as a whole,
	 * so only that line's upper end meets the first-page top margin.
	 */
	private function draw_title_address_band() {
		$this->select_band_font();
		$baseline = 0.3 * $this->FontSize;
		$addresses = array( self::ADDRESS_TENERIFE, self::ADDRESS_LASPALMAS );
		$width     = 0.0;
		foreach ( $addresses as $address ) {
			$width = max( $width, $this->GetStringWidth( self::latin1( $address ) ) );
		}

		foreach ( $addresses as $index => $address ) {
			$x     = self::BAND_BASELINE_X + ( $index * self::BAND_LINE_GAP ) - $baseline;
			$this->rotated_text( $x, $this->tMargin + $width, 90, $address, $width, 'C' );
		}
	}

	/**
	 * Load the bundled font from a fixed path, without system or network fonts.
	 */
	private function select_band_font() {
		$this->AddFont( self::BAND_FONT, '', 'Roboto-Light.json', dirname( __DIR__, 2 ) . '/templates/pdf/fonts/roboto' );
		$this->SetFont( self::BAND_FONT, '', self::ADDRESS_FONT_SIZE );
	}

	/**
	 * Draw both addresses as two plain lines under the letterhead.
	 */
	private function draw_header_addresses() {
		$this->SetFont( self::ADDRESS_FONT, '', self::ADDRESS_FONT_SIZE );
		$this->SetXY( $this->lMargin, self::HEADER_ADDRESS_Y );
		$this->Cell( 0, self::HEADER_ADDRESS_LINE, self::latin1( self::ADDRESS_TENERIFE ), 0, 2 );
		$this->Cell( 0, self::HEADER_ADDRESS_LINE, self::latin1( self::ADDRESS_LASPALMAS ), 0, 2 );
	}

	/**
	 * Draw the two-column address block that sits above the foot of the page.
	 *
	 * The ODT leaves its `style:footer-first` empty, so the block starts on the
	 * second page: the first one carries the large letterhead instead. It holds
	 * no page numbering either, which is why nothing here prints one.
	 */
	private function draw_footer_addresses() {
		if ( 1 === $this->PageNo() ) {
			return;
		}

		$this->SetFont( self::ADDRESS_FONT, '', self::ADDRESS_FONT_SIZE );

		list( $left_x, $right_x ) = self::FOOTER_ADDRESS_COLUMNS;
		$column                   = $right_x - $left_x;

		foreach ( self::FOOTER_ADDRESS_LEFT as $index => $line ) {
			$this->SetXY( $left_x, self::FOOTER_ADDRESS_TOP + ( $index * self::FOOTER_ADDRESS_LINE ) );
			$this->Cell( $column, self::FOOTER_ADDRESS_LINE, self::latin1( $line ) );
			$this->Cell( $column, self::FOOTER_ADDRESS_LINE, self::latin1( self::FOOTER_ADDRESS_RIGHT[ $index ] ) );
		}
	}

	/**
	 * Draw the framed «Folio N/M» label in the top right of the header.
	 */
	private function draw_folio_box() {
		list( $x, $y, $width, $height ) = self::FOLIO_BOX;

		$this->SetFont( $this->options['font'], '', self::FOLIO_FONT_SIZE );
		$this->SetXY( $x, $y );
		$this->Cell( $width, $height, '', 1 );
		$this->SetXY( $x + self::FOLIO_TEXT_INSET, $y );
		$this->Cell(
			$width - self::FOLIO_TEXT_INSET,
			$height,
			self::latin1( 'Folio ' . $this->PageNo() . '/' . $this->AliasNbPages )
		);
	}

	/**
	 * Draw the bare page number at the foot of the body area.
	 */
	private function draw_footer_folio() {
		$this->SetFont( $this->options['font'], '', $this->options['font_size'] );
		$this->SetY( $this->PageBreakTrigger - self::FOLIO_FOOTER_LIFT );
		$align = 0 === $this->PageNo() % 2 ? 'L' : 'R';
		$this->Cell( 0, self::FOLIO_FOOTER_HEIGHT, self::latin1( (string) $this->PageNo() ), 0, 0, $align );
	}

	/**
	 * Place a bundled image from an (x, y, width, height) box in millimetres.
	 *
	 * @param string           $name File name under templates/pdf/img.
	 * @param array<int,float> $box  Position and size, in mm.
	 */
	private function place_image( $name, array $box ) {
		list( $x, $y, $width, $height ) = $box;

		$this->Image( self::image_path( $name ), $x, $y, $width, $height );
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
