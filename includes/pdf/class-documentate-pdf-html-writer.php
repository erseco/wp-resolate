<?php
/**
 * Draws an HTML fragment onto a native PDF document.
 *
 * The vocabulary is the small one the plugin produces: the tags a layout is
 * written with, and what survives `wp_kses_post()` plus the tag stripper in a
 * rich field. Anything else is walked through as a neutral block, so unknown
 * markup loses its styling but never its text.
 *
 * Everything is drawn inside the *active column*, which is the area between
 * the page margins while a document is being written and one table cell while
 * the table writer is filling it in. The same walk serves both drawing and
 * measuring: in measuring mode nothing reaches the page and the heights the
 * content would take are added up instead, which is how a table sizes a row
 * before it draws it.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

// ext/dom exposes the tree as camelCase properties (tagName, nodeType,
// childNodes). They belong to the extension and cannot be renamed, so the
// snake_case rule is switched off for this file only.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Renders paragraphs, headings, lists, rules and images out of HTML.
 */
class Documentate_Pdf_Html_Writer {

	/**
	 * Point size of each heading level.
	 */
	const HEADING_SIZES = array(
		'h1' => 14.0,
		'h2' => 13.0,
		'h3' => 12.0,
		'h4' => 11.0,
	);

	/**
	 * Elements that break the flow of text and are drawn on their own.
	 *
	 * The table parts are here for markup that lost its table on the way in:
	 * a stray row or cell then prints one paragraph per cell instead of
	 * running every cell together into one line. A whole table goes to the
	 * table writer instead.
	 */
	const BLOCK_TAGS = array(
		'p',
		'h1',
		'h2',
		'h3',
		'h4',
		'ul',
		'ol',
		'li',
		'table',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'td',
		'th',
		'caption',
		'hr',
		'img',
		'blockquote',
		'pre',
		'div',
	);

	/**
	 * Run style each inline element switches on. Links are handled apart,
	 * because they carry a destination as well as a style.
	 */
	const INLINE_STYLES = array(
		'strong' => 'bold',
		'b'      => 'bold',
		'em'     => 'italic',
		'i'      => 'italic',
		'u'      => 'underline',
	);

	/**
	 * Alignment each CSS value asks for. Anything else is left-aligned.
	 */
	const ALIGNMENTS = array(
		'left'    => 'L',
		'center'  => 'C',
		'right'   => 'R',
		'justify' => 'J',
	);

	/**
	 * Indent one list level adds, and the width of a marker, in mm.
	 */
	const LIST_INDENT = 6.0;

	/**
	 * Space left after a paragraph, in mm.
	 *
	 * The `Standard` paragraph style of the ODT templates declares no
	 * vertical margin, and the rich values merged into a document inherit
	 * the style of the paragraph they land in. Both the layouts and the
	 * templates separate paragraphs with an empty one instead.
	 */
	const PARA_SPACING = 0.0;

	/**
	 * Space left before a heading, in mm.
	 */
	const HEADING_SPACING = 3.0;

	/**
	 * Indent a blockquote is set in by, in mm.
	 */
	const BLOCKQUOTE_INDENT = 10.0;

	/**
	 * Height a horizontal rule takes, line included, in mm.
	 */
	const RULE_HEIGHT = 3.0;

	/**
	 * Width an image is drawn at when the layout does not give one, in mm.
	 */
	const DEFAULT_IMAGE_WIDTH = 60.0;

	/**
	 * Class that starts a new page before the block carrying it.
	 */
	const PAGE_BREAK_CLASS = 'page-break';

	/**
	 * Marker of an unordered list item.
	 */
	const BULLET = '•';

	/**
	 * Document being drawn on.
	 *
	 * @var Documentate_Pdf_Document
	 */
	private $pdf;

	/**
	 * Layout the fragment comes from, which says what it may embed.
	 *
	 * @var Documentate_Pdf_Layout
	 */
	private $layout;

	/**
	 * Line breaker measuring against the document fonts.
	 *
	 * @var Documentate_Pdf_Text_Layout
	 */
	private $text_layout;

	/**
	 * Renderer the tables are handed to, which fills its cells back in
	 * through this writer.
	 *
	 * @var Documentate_Pdf_Table_Writer
	 */
	private $tables;

	/**
	 * Left edge of the active column in mm, or null for the page text area.
	 *
	 * @var float|null
	 */
	private $flow_x = null;

	/**
	 * Width of the active column in mm, or null for the page text area.
	 *
	 * @var float|null
	 */
	private $flow_width = null;

	/**
	 * Indent every block is currently drawn at, in mm. Lists add to it.
	 *
	 * @var float
	 */
	private $indent = 0.0;

	/**
	 * Whether the walk is measuring instead of drawing.
	 *
	 * @var bool
	 */
	private $measuring = false;

	/**
	 * Height the measured content has taken so far, in mm.
	 *
	 * @var float
	 */
	private $measured = 0.0;

	/**
	 * Keep the document and the layout to draw for.
	 *
	 * @param Documentate_Pdf_Document $pdf    Document to draw on.
	 * @param Documentate_Pdf_Layout   $layout Layout the fragment comes from.
	 */
	public function __construct( Documentate_Pdf_Document $pdf, Documentate_Pdf_Layout $layout ) {
		$this->pdf         = $pdf;
		$this->layout      = $layout;
		$this->text_layout = new Documentate_Pdf_Text_Layout( array( $pdf, 'measure' ) );
		$this->tables      = new Documentate_Pdf_Table_Writer( $pdf, $this );
	}

	/**
	 * Draw an HTML fragment from the current position down.
	 *
	 * @param string $html Fragment to draw. Markup that does not parse is
	 *                     recovered by libxml rather than rejected.
	 */
	public function write( $html ) {
		$dom      = new DOMDocument();
		$reported = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="documentate-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $reported );

		$root = $dom->getElementById( 'documentate-root' );
		if ( $root instanceof DOMElement ) {
			$this->children( $root, array() );
		}
	}

	/**
	 * Draw the children of a node inside a column of the page.
	 *
	 * @param DOMNode             $node  Node whose children are drawn.
	 * @param float               $x     Left edge of the column, in mm.
	 * @param float               $width Width of the column, in mm.
	 * @param array<string,mixed> $style Style every run inherits, as for
	 *                                   `Documentate_Pdf_Document::apply_style()`.
	 */
	public function write_block( DOMNode $node, $x, $width, array $style = array() ) {
		$saved            = array( $this->flow_x, $this->flow_width, $this->indent );
		$this->flow_x     = (float) $x;
		$this->flow_width = (float) $width;
		$this->indent     = 0.0;

		$this->pdf->SetX( $this->flow_x );
		$this->children( $node, $style );

		list( $this->flow_x, $this->flow_width, $this->indent ) = $saved;
	}

	/**
	 * Height the children of a node would take in a column of the given width.
	 *
	 * Nothing is drawn and no page is started, so the caller can size a table
	 * row before committing to it. The document is handed back exactly as it
	 * was found: same position, same font, same active column.
	 *
	 * @param DOMNode             $node  Node whose children are measured.
	 * @param float               $width Width of the column, in mm.
	 * @param array<string,mixed> $style Style every run inherits. It has to be
	 *                                   the one the content will be drawn with,
	 *                                   because a bolder face is a wider one and
	 *                                   wraps to more lines.
	 * @return float Height in mm.
	 */
	public function measure_block( DOMNode $node, $width, array $style = array() ) {
		$saved = array( $this->flow_x, $this->flow_width, $this->indent, $this->measuring, $this->measured );
		$font  = $this->pdf->current_style();
		$x     = $this->pdf->GetX();
		$y     = $this->pdf->GetY();

		$this->flow_x     = 0.0;
		$this->flow_width = (float) $width;
		$this->indent     = 0.0;
		$this->measuring  = true;
		$this->measured   = 0.0;

		$this->children( $node, $style );
		$height = $this->measured;

		list( $this->flow_x, $this->flow_width, $this->indent, $this->measuring, $this->measured ) = $saved;
		$this->pdf->apply_style( $font );
		$this->pdf->SetXY( $x, $y );

		return $height;
	}

	/**
	 * Draw a paragraph in the active column.
	 *
	 * @param array<int,array<string,mixed>> $runs   Styled runs, as the text layout takes them.
	 * @param array<string,mixed>            $format Paragraph format: `align` (L, C, R or J),
	 *                                               `indent`, `space_before` and `space_after`
	 *                                               in mm, and `size` in points.
	 */
	public function paragraph( array $runs, array $format ) {
		$format = wp_parse_args(
			$format,
			array(
				'align'        => 'L',
				'indent'       => 0.0,
				'space_before' => 0.0,
				'space_after'  => self::PARA_SPACING,
				'size'         => null,
				'line_height'  => null,
			)
		);

		$width = $this->flow_width() - $format['indent'];
		$lines = $this->text_layout->lines( $runs, $width );

		$this->advance( $format['space_before'] );
		foreach ( $lines as $line ) {
			$this->draw_line( $line, $width, $format );
		}
		$this->advance( $format['space_after'] );
	}

	/**
	 * Collect the styled runs a node and its descendants contribute.
	 *
	 * A `br` arrives as `array( 'br' => true )`, which the text layout turns
	 * into a forced line break.
	 *
	 * @param DOMNode             $node  Node to walk.
	 * @param array<string,mixed> $style Style the node inherits.
	 * @return array<int,array<string,mixed>>
	 */
	public function inline_runs( DOMNode $node, array $style ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return array( array_merge( $style, array( 'text' => (string) $node->nodeValue ) ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return array(); // Comments and processing instructions draw nothing.
		}

		if ( 'br' === strtolower( $node->tagName ) ) {
			return array( array( 'br' => true ) );
		}

		$style = $this->inline_style( $node, $style );
		$runs  = array();
		foreach ( $node->childNodes as $child ) {
			$runs = array_merge( $runs, $this->inline_runs( $child, $style ) );
		}

		return $runs;
	}

	/**
	 * Walk the children of a node as document flow.
	 *
	 * @param DOMNode             $parent Node whose children are drawn.
	 * @param array<string,mixed> $style  Style every run inherits.
	 */
	private function children( DOMNode $parent, array $style ) {
		$this->flow( $parent, $style, $this->format( $parent ) );
	}

	/**
	 * Draw the children of a node: runs of inline content become paragraphs,
	 * block elements are drawn on their own.
	 *
	 * @param DOMNode             $parent Node whose children are drawn.
	 * @param array<string,mixed> $style  Style every run inherits.
	 * @param array<string,mixed> $format Format the implicit paragraphs take.
	 * @return bool Whether anything was drawn.
	 */
	private function flow( DOMNode $parent, array $style, array $format ) {
		$runs    = array();
		$emitted = false;

		foreach ( $parent->childNodes as $child ) {
			if ( ! $this->is_block( $child ) ) {
				$runs = array_merge( $runs, $this->inline_runs( $child, $style ) );
				continue;
			}

			$this->flush( $runs, $format );
			$runs = array();
			$this->block( $child, $style );
			$emitted = true;
		}

		return $this->flush( $runs, $format ) || $emitted;
	}

	/**
	 * Draw the runs gathered so far, unless they hold nothing but the
	 * whitespace that separates two blocks in the source.
	 *
	 * @param array<int,array<string,mixed>> $runs   Runs gathered for a paragraph.
	 * @param array<string,mixed>            $format Format the paragraph takes.
	 * @return bool Whether a paragraph was drawn.
	 */
	private function flush( array $runs, array $format ) {
		if ( ! $this->has_content( $runs ) ) {
			return false;
		}

		$this->paragraph( $runs, $format );

		return true;
	}

	/**
	 * Whether a set of runs holds anything a reader would see.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs to inspect.
	 * @return bool
	 */
	private function has_content( array $runs ) {
		foreach ( $runs as $run ) {
			if ( ! empty( $run['br'] ) ) {
				return true;
			}

			if ( isset( $run['text'] ) && '' !== trim( (string) $run['text'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a node breaks the flow of text.
	 *
	 * @param DOMNode $node Node to classify.
	 * @return bool
	 */
	private function is_block( DOMNode $node ) {
		return $node instanceof DOMElement && in_array( strtolower( $node->tagName ), self::BLOCK_TAGS, true );
	}

	/**
	 * Draw one block element.
	 *
	 * @param DOMElement          $el    Element to draw.
	 * @param array<string,mixed> $style Style every run inherits.
	 */
	private function block( DOMElement $el, array $style ) {
		$tag = strtolower( $el->tagName );

		// A block cannot start a page while the automatic break is off: the
		// document is then inside a table row that reserved its own room.
		if ( ! $this->measuring && $this->pdf->AcceptPageBreak() && $this->has_class( $el, self::PAGE_BREAK_CLASS ) ) {
			$this->pdf->AddPage();
		}

		if ( isset( self::HEADING_SIZES[ $tag ] ) ) {
			$this->heading( $el, $style, self::HEADING_SIZES[ $tag ] );
			return;
		}

		switch ( $tag ) {
			case 'p':
			case 'pre':
				$this->text_block( $el, $style, $this->format( $el ) );
				break;
			case 'blockquote':
				$this->text_block( $el, $style, $this->format( $el, self::BLOCKQUOTE_INDENT ) );
				break;
			case 'ul':
			case 'ol':
				$this->list_block( $el, $style, 'ol' === $tag );
				break;
			case 'hr':
				$this->rule();
				break;
			case 'img':
				$this->image( $el );
				break;
			case 'table':
				$this->table( $el );
				break;
			default:
				$this->children( $el, $style ); // div, li, table parts, unknown.
		}
	}

	/**
	 * Hand a table to the table writer, inside the active column.
	 *
	 * The column travels with the table because a table a rich field brought
	 * with it belongs inside the cell that holds it, not across the page.
	 *
	 * @param DOMElement $el Table element.
	 */
	private function table( DOMElement $el ) {
		$width = $this->flow_width() - $this->indent;

		if ( $this->measuring ) {
			$this->measured += $this->tables->measure( $el, $width );
			return;
		}

		$this->tables->write( $el, $this->flow_x() + $this->indent, $width );
	}

	/**
	 * Draw a paragraph-like element, leaving a blank line when it is empty.
	 *
	 * @param DOMElement          $el     Element to draw.
	 * @param array<string,mixed> $style  Style every run inherits.
	 * @param array<string,mixed> $format Format the paragraphs take.
	 */
	private function text_block( DOMElement $el, array $style, array $format ) {
		if ( ! $this->flow( $el, $style, $format ) ) {
			$this->paragraph( array(), $format );
		}
	}

	/**
	 * Draw a heading: bold, bigger, and set off from the text above it.
	 *
	 * The size travels in the runs so the line breaker measures against the
	 * right font, and in the format so the lines are as tall as it needs.
	 *
	 * @param DOMElement          $el    Element to draw.
	 * @param array<string,mixed> $style Style every run inherits.
	 * @param float               $size  Point size of the heading level.
	 */
	private function heading( DOMElement $el, array $style, $size ) {
		$this->text_block(
			$el,
			array_merge(
				$style,
				array(
					'bold' => true,
					'size' => $size,
				)
			),
			array_merge(
				$this->format( $el ),
				array(
					'space_before' => self::HEADING_SPACING,
					'size'         => $size,
				)
			)
		);
	}

	/**
	 * Draw a list, one marker and one indented item at a time.
	 *
	 * @param DOMElement          $el      List element.
	 * @param array<string,mixed> $style   Style every run inherits.
	 * @param bool                $ordered Whether the items are numbered.
	 */
	private function list_block( DOMElement $el, array $style, $ordered ) {
		$outer  = $this->indent;
		$number = 1;

		$this->indent = $outer + self::LIST_INDENT;

		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$this->marker( $ordered ? $number . '.' : self::BULLET, $outer, $style, $this->format( $child ) );
			++$number;
			$this->text_block( $child, $style, $this->format( $child ) );
		}

		$this->indent = $outer;
	}

	/**
	 * Draw the bullet or number of a list item, on the line its text starts on.
	 *
	 * @param string              $text   Marker to draw.
	 * @param float               $indent Indent the marker sits at, in mm.
	 * @param array<string,mixed> $style  Style the list inherits, so a marker
	 *                                    in a bold cell is bold as well.
	 * @param array               $format Paragraph spacing shared with the item.
	 */
	private function marker( $text, $indent, array $style, array $format ) {
		if ( $this->measuring ) {
			return;
		}

		$this->pdf->apply_style( $style );
		$height = $format['line_height'] ?? $this->pdf->line_height();
		$this->ensure_space( $height );

		$x = $this->flow_x();
		$y = $this->pdf->GetY();

		$this->pdf->SetXY( $x + $indent, $y );
		$this->pdf->Cell( self::LIST_INDENT, $height, Documentate_Pdf_Document::latin1( $text ) );
		$this->pdf->SetXY( $x, $y );
	}

	/**
	 * Draw a horizontal rule across the active column.
	 */
	private function rule() {
		if ( $this->measuring ) {
			$this->measured += self::RULE_HEIGHT;
			return;
		}

		$this->ensure_space( self::RULE_HEIGHT );

		$x = $this->flow_x() + $this->indent;
		$y = $this->pdf->GetY() + ( self::RULE_HEIGHT / 2 );

		$this->pdf->SetDrawColor( 0 );
		$this->pdf->Line( $x, $y, $x + $this->flow_width() - $this->indent, $y );
		$this->pdf->Ln( self::RULE_HEIGHT );
	}

	/**
	 * Draw an image the layout is allowed to embed.
	 *
	 * The height follows the aspect ratio of the file, and the width never
	 * grows past the column, so a layout cannot push an image off the page.
	 *
	 * @param DOMElement $el Image element.
	 */
	private function image( DOMElement $el ) {
		$path = $this->layout->image_path( $el->getAttribute( 'src' ) );
		if ( '' === $path ) {
			return;
		}

		$size = getimagesize( $path );
		if ( ! is_array( $size ) || $size[0] < 1 ) {
			return;
		}

		$available = $this->flow_width() - $this->indent;
		if ( $available <= 0 ) {
			return; // A column with no room left holds no image either.
		}

		$width = (float) $el->getAttribute( 'width' );
		if ( $width <= 0 ) {
			$width = self::DEFAULT_IMAGE_WIDTH;
		}
		$width  = min( $width, $available );
		$height = $width * $size[1] / $size[0];

		if ( $this->measuring ) {
			$this->measured += $height;
			return;
		}

		$this->ensure_space( $height );
		$this->pdf->Image( $path, $this->flow_x() + $this->indent, $this->pdf->GetY(), $width );
		$this->pdf->Ln( $height );
	}

	/**
	 * Draw one line of a paragraph.
	 *
	 * @param array<string,mixed> $line   Line as the text layout closed it.
	 * @param float               $width  Width the line was broken to, in mm.
	 * @param array<string,mixed> $format Paragraph format.
	 */
	private function draw_line( array $line, $width, array $format ) {
		$size = $format['size'];
		$this->pdf->apply_style( array( 'size' => $size ) );
		$height = $format['line_height'] ?? $this->pdf->line_height();

		if ( $this->measuring ) {
			$this->measured += $height;
			return;
		}

		$this->ensure_space( $height );

		$free = $width - $line['width'];
		$x    = $this->flow_x() + $format['indent'] + $this->offset( $free, $format['align'] );

		$spacing = $this->word_spacing( $line, $free, $format['align'] );
		$this->pdf->set_word_spacing( $spacing );
		$this->pdf->SetX( $x );

		foreach ( $line['runs'] as $run ) {
			$this->draw_run( $run, $height, $spacing, $size );
		}

		$this->pdf->set_word_spacing( 0.0 );
		$this->pdf->Ln( $height );
	}

	/**
	 * How far a line is pushed right by its alignment, in mm.
	 *
	 * @param float  $free  Width the line did not use, in mm.
	 * @param string $align L, C, R or J.
	 * @return float
	 */
	private function offset( $free, $align ) {
		if ( 'C' === $align ) {
			return $free / 2;
		}

		return 'R' === $align ? $free : 0.0;
	}

	/**
	 * Extra space each space character of a line is widened by, in mm.
	 *
	 * A line can hold no space at all and still not be the last one of its
	 * paragraph: a word too long for the column is cut into pieces, and a line
	 * whose closing space did not fit ends on a single word. Sharing the
	 * leftover width between no spaces at all would divide by zero, so such a
	 * line is left unstretched.
	 *
	 * @param array<string,mixed> $line  Line as the text layout closed it.
	 * @param float               $free  Width the line did not use, in mm.
	 * @param string              $align L, C, R or J.
	 * @return float
	 */
	private function word_spacing( array $line, $free, $align ) {
		if ( 'J' !== $align || $line['last'] || $line['spaces'] < 1 ) {
			return 0.0;
		}

		return $free / $line['spaces'];
	}

	/**
	 * Draw one styled run of a line.
	 *
	 * The cell is as wide as the text plus the extra the word spacing adds to
	 * it, because the spacing widens every space the run is drawn with and the
	 * run after it has to start past all of them.
	 *
	 * @param array<string,mixed> $run     Run to draw.
	 * @param float               $height  Line height, in mm.
	 * @param float               $spacing Extra width per space, in mm.
	 * @param float|null          $size    Point size of the paragraph, if it sets one.
	 */
	private function draw_run( array $run, $height, $spacing, $size ) {
		// A run only carries the keys its source gave it, so the ones read
		// below are filled in before anything reads them.
		$run = $run + array(
			'text' => '',
			'link' => '',
			'size' => $size,
		);

		$this->pdf->apply_style( $run );

		$text  = Documentate_Pdf_Document::latin1( $run['text'] );
		$width = $this->pdf->GetStringWidth( $text ) + ( $spacing * substr_count( $text, ' ' ) );

		$this->pdf->Cell( $width, $height, $text, 0, 0, 'L', false, $run['link'] );
	}

	/**
	 * Move down the page, or add to the measured height.
	 *
	 * @param float $mm Distance in mm.
	 */
	private function advance( $mm ) {
		if ( $this->measuring ) {
			$this->measured += $mm;
			return;
		}

		$this->pdf->Ln( $mm );
	}

	/**
	 * Start a new page when what comes next does not fit on this one.
	 *
	 * A document with the automatic break switched off is being drawn inside
	 * a table cell, in room its row reserved before it started: a page taken
	 * there would leave the borders of the row on the page before.
	 *
	 * No test covers that check, because as the table writer stands today it
	 * cannot fire. The writer boxes a row only when the row fits in the room
	 * left on the page, and it draws exactly the content it measured, so
	 * every line inside a boxed cell has at least one cell padding of slack
	 * and never reaches the break. The check stays all the same: that
	 * invariant belongs to the table writer's fit rule, not to this method,
	 * and if the rule ever changes this is what keeps a page from being
	 * taken in the middle of a row. It costs one comparison.
	 *
	 * @param float $height Height about to be drawn, in mm.
	 */
	private function ensure_space( $height ) {
		if ( ! $this->measuring && $this->pdf->AcceptPageBreak() && $this->pdf->remaining_height() < $height ) {
			$this->pdf->AddPage();
		}
	}

	/**
	 * Left edge of the active column, in mm.
	 *
	 * @return float
	 */
	private function flow_x() {
		return null === $this->flow_x ? $this->pdf->GetX() : $this->flow_x;
	}

	/**
	 * Width of the active column, in mm.
	 *
	 * @return float
	 */
	private function flow_width() {
		return null === $this->flow_width ? $this->pdf->content_width() : $this->flow_width;
	}

	/**
	 * The style an inline element adds to the one it inherits.
	 *
	 * A link is sanitised before it is written into the document, so a layout
	 * or a pasted field cannot put a `javascript:` URI in a PDF annotation.
	 *
	 * @param DOMElement          $el    Inline element.
	 * @param array<string,mixed> $style Style the element inherits.
	 * @return array<string,mixed>
	 */
	private function inline_style( DOMElement $el, array $style ) {
		$tag = strtolower( $el->tagName );

		if ( isset( self::INLINE_STYLES[ $tag ] ) ) {
			return array_merge( $style, array( self::INLINE_STYLES[ $tag ] => true ) );
		}

		if ( 'a' !== $tag ) {
			return $style;
		}

		$href = esc_url_raw( trim( $el->getAttribute( 'href' ) ) );
		if ( '' === $href ) {
			return $style;
		}

		return array_merge(
			$style,
			array(
				'underline' => true,
				'link'      => $href,
			)
		);
	}

	/**
	 * The paragraph format a node asks for.
	 *
	 * @param DOMNode $node          Node the format is read from.
	 * @param float   $extra_indent  Indent this block adds, in mm.
	 * @return array<string,mixed>
	 */
	private function format( DOMNode $node, $extra_indent = 0.0 ) {
		return array(
			'line_height'  => Documentate_Pdf_Paragraph_Style::line_height( $node ),
			'align'        => $node instanceof DOMElement ? $this->alignment( $node ) : 'L',
			'indent'       => $this->indent + $extra_indent + $this->paragraph_margin( $node, 'left' ),
			'space_before' => $this->paragraph_margin( $node, 'top' ),
			'space_after'  => $this->paragraph_margin( $node, 'bottom' ),
		);
	}

	/**
	 * Read an explicit paragraph margin in millimetres, centimetres or points.
	 *
	 * Negative, non-finite and oversized values are ignored so pasted content
	 * cannot move backwards over earlier text or consume the whole column.
	 *
	 * @param DOMNode $node Node carrying the paragraph style.
	 * @param string  $side Margin side: left, top or bottom.
	 * @return float Margin in millimetres.
	 */
	private function paragraph_margin( DOMNode $node, $side ) {
		if ( ! $node instanceof DOMElement ) {
			return 0.0;
		}

		$pattern = '/(?:^|;)\s*margin-' . $side . '\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*(mm|cm|pt)\s*(?:;|$)/i';
		if ( ! preg_match( $pattern, $node->getAttribute( 'style' ), $match ) ) {
			return 0.0;
		}

		$units = array(
			'mm' => 1.0,
			'cm' => 10.0,
			'pt' => Documentate_Pdf_Document::MM_PER_POINT,
		);
		$value = (float) $match[1] * $units[ strtolower( $match[2] ) ];
		$limit = 'left' === $side ? min( 50.0, $this->flow_width() / 2 ) : 50.0;

		return $value <= $limit ? $value : 0.0;
	}

	/**
	 * Alignment an element asks for: L, C, R or J.
	 *
	 * @param DOMElement $el Element to read.
	 * @return string
	 */
	private function alignment( DOMElement $el ) {
		$align = strtolower( trim( $el->getAttribute( 'align' ) ) );

		if ( preg_match( '/text-align\s*:\s*([a-z]+)/i', $el->getAttribute( 'style' ), $match ) ) {
			$align = strtolower( $match[1] );
		}

		if ( isset( self::ALIGNMENTS[ $align ] ) ) {
			return self::ALIGNMENTS[ $align ];
		}

		return $el->parentNode instanceof DOMElement ? $this->alignment( $el->parentNode ) : 'L';
	}

	/**
	 * Whether an element carries a class.
	 *
	 * @param DOMElement $el   Element to inspect.
	 * @param string     $name Class to look for.
	 * @return bool
	 */
	private function has_class( DOMElement $el, $name ) {
		$classes = preg_split( '/\s+/', $el->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $classes ) && in_array( $name, $classes, true );
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
