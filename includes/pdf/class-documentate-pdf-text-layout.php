<?php
/**
 * Line breaking for the styled runs the native PDF renderer draws.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Breaks a sequence of styled text runs into lines that fit a given width.
 *
 * The class knows nothing about FPDF. It is given a measuring callable,
 * `fn( string $text, array $style ): float`, so the same algorithm serves the
 * real document and a test that counts characters. Widths are millimetres,
 * the unit the document works in.
 */
class Documentate_Pdf_Text_Layout {

	/**
	 * Run keys that pick a font or a link, and so decide where a run ends.
	 */
	const STYLE_KEYS = array( 'bold', 'italic', 'underline', 'link', 'size' );

	/**
	 * Width of a string in a style, in mm.
	 *
	 * @var callable
	 */
	private $measure;

	/**
	 * Keep the callable the layout measures with.
	 *
	 * @param callable $measure fn( string $text, array $style ): float, in mm.
	 */
	public function __construct( callable $measure ) {
		$this->measure = $measure;
	}

	/**
	 * Break runs into lines that fit $width.
	 *
	 * A line is only ever cut where a space was, never at a run boundary, so
	 * text the markup split across styles without a space between the parts
	 * travels from line to line as one piece.
	 *
	 * `spaces` counts the spaces left inside the drawn line, so the caller can
	 * justify it by sharing out the width it did not use. `last` marks the
	 * lines that must not be stretched: the one that ends the paragraph and
	 * the one before a forced break.
	 *
	 * `spaces` can be 0 on any line, `last` ones and others alike: a word too
	 * long for the column is cut into lines of one piece each, and a line the
	 * space itself did not fit on ends with a single word. A caller that
	 * justifies by dividing the leftover width by `spaces` must check it
	 * first and leave such a line unstretched, or it divides by zero.
	 *
	 * @param array<int,array<string,mixed>> $runs  Runs, or array('br'=>true) for a forced break.
	 * @param float                          $width Available width, mm.
	 * @return array<int,array{runs:array,width:float,spaces:int,last:bool}>
	 */
	public function lines( array $runs, $width ) {
		$lines   = array();
		$current = array();
		$used    = 0.0;

		foreach ( $this->tokens( $runs ) as $token ) {
			if ( $token['br'] ) {
				$lines[] = $this->close( $current, true );
				$current = array();
				$used    = 0.0;
				continue;
			}

			if ( $token['space'] && $this->space_is_redundant( $current ) ) {
				continue; // A line neither opens with a space nor doubles one.
			}

			if ( $used + $token['width'] > $width && ! empty( $current ) ) {
				list( $head, $current ) = $this->wrap( $current, $token, $width );

				$lines[] = $this->close( $head, false );
				$used    = $this->width_of( $current );

				if ( $token['space'] ) {
					continue; // The space fell at the break; it is not carried over.
				}
			}

			foreach ( $this->fit( $token, $width ) as $index => $piece ) {
				if ( $index > 0 ) {
					$lines[] = $this->close( $current, false );
					$current = array();
					$used    = 0.0;
				}

				$current[] = $piece;
				$used     += $piece['width'];
			}
		}

		$lines[] = $this->close( $current, true );

		return $lines;
	}

	/**
	 * Split a line to make room for a token that no longer fits: the tokens
	 * that stay on it, and the tokens that go down with the incoming one.
	 *
	 * There is one token per word *and* per run, so `…Consejeria</a>, procede`
	 * arrives as `Consejeria` and `,` with nothing between them. Breaking at
	 * that boundary would orphan the comma at the head of the next line, so
	 * the cut is made at the line's last space instead and everything after
	 * that space travels with the token that displaced it.
	 *
	 * The line stays whole when it holds no space to cut at, and when what
	 * would move down leaves no room for the incoming token either. Both hand
	 * the caller an empty line to fill, which is what lets a stretch too long
	 * for any line be cut character by character instead of moving for ever.
	 *
	 * @param array<int,array<string,mixed>> $tokens Tokens gathered for the line.
	 * @param array<string,mixed>            $token  Token that did not fit.
	 * @param float                          $width  Available width, mm.
	 * @return array{0:array,1:array} Tokens that stay, tokens that move down.
	 */
	private function wrap( array $tokens, array $token, $width ) {
		if ( $token['space'] ) {
			return array( $tokens, array() ); // The break already falls at a space.
		}

		list( $head, $tail ) = $this->split_at_last_space( $tokens );

		if ( empty( $head ) || $this->width_of( $tail ) + $token['width'] > $width ) {
			return array( $tokens, array() );
		}

		return array( $head, $tail );
	}

	/**
	 * Cut a line's tokens after its last space.
	 *
	 * @param array<int,array<string,mixed>> $tokens Tokens gathered for the line.
	 * @return array{0:array,1:array} The head, ending in that space, and the tail
	 *                                after it. The head is empty when the line
	 *                                holds no space at all.
	 */
	private function split_at_last_space( array $tokens ) {
		for ( $index = count( $tokens ) - 1; $index >= 0; $index-- ) {
			if ( $tokens[ $index ]['space'] ) {
				return array( array_slice( $tokens, 0, $index + 1 ), array_slice( $tokens, $index + 1 ) );
			}
		}

		return array( array(), $tokens );
	}

	/**
	 * Total width of a stretch of tokens, in mm.
	 *
	 * @param array<int,array<string,mixed>> $tokens Tokens to add up.
	 * @return float
	 */
	private function width_of( array $tokens ) {
		$width = 0.0;

		foreach ( $tokens as $token ) {
			$width += $token['width'];
		}

		return $width;
	}

	/**
	 * Whether a space would be wasted on the line as it stands: there is
	 * nothing before it, or what is there already ends in a space.
	 *
	 * Each run is tokenised on its own, so `'a '` followed by `' b'` arrives
	 * as two spaces in a row. Collapsing them here keeps the pair from being
	 * drawn twice and from counting twice towards the justification.
	 *
	 * @param array<int,array<string,mixed>> $tokens Tokens gathered for the line.
	 * @return bool
	 */
	private function space_is_redundant( array $tokens ) {
		$end = count( $tokens );

		return 0 === $end || $tokens[ $end - 1 ]['space'];
	}

	/**
	 * Flatten the runs into one measured token per word and per space.
	 *
	 * Whitespace collapses here: any run of it becomes a single space, so the
	 * line builder only ever sees words separated by one space each.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs as given to lines().
	 * @return array<int,array<string,mixed>>
	 */
	private function tokens( array $runs ) {
		$tokens = array();

		foreach ( $runs as $run ) {
			if ( ! empty( $run['br'] ) ) {
				// A break draws nothing, so it is built here rather than by
				// token(): measuring it would select a font for no reason.
				$tokens[] = array(
					'text'  => '',
					'style' => array(),
					'width' => 0.0,
					'space' => false,
					'br'    => true,
				);
				continue;
			}

			$style = array_intersect_key( $run, array_flip( self::STYLE_KEYS ) );
			ksort( $style );
			$text = isset( $run['text'] ) ? (string) $run['text'] : '';

			// PREG_SPLIT_DELIM_CAPTURE alternates words and separators, so the
			// odd offsets are the whitespace. Text that is not valid UTF-8
			// fails the split and is kept whole rather than dropped.
			$parts = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( ! is_array( $parts ) ) {
				$parts = array( $text );
			}

			foreach ( $parts as $offset => $part ) {
				if ( '' === $part ) {
					continue;
				}

				$space    = ( 1 === $offset % 2 );
				$tokens[] = $this->token( $space ? ' ' : $part, $style, $space );
			}
		}

		return $tokens;
	}

	/**
	 * Measure a piece of text and wrap it as a token.
	 *
	 * @param string              $text  Token text.
	 * @param array<string,mixed> $style Style the token is drawn in.
	 * @param bool                $space Whether the token is a space.
	 * @return array<string,mixed>
	 */
	private function token( $text, array $style, $space = false ) {
		return array(
			'text'  => $text,
			'style' => $style,
			'width' => (float) call_user_func( $this->measure, $text, $style ),
			'space' => $space,
			'br'    => false,
		);
	}

	/**
	 * Place a token, cutting a word that no whole line could hold.
	 *
	 * @param array<string,mixed> $token Token to place.
	 * @param float               $width Available width, mm.
	 * @return array<int,array<string,mixed>> One token, or the pieces it was cut into.
	 */
	private function fit( array $token, $width ) {
		if ( $token['width'] <= $width ) {
			return array( $token );
		}

		$pieces = array();
		$rest   = $token['text'];

		while ( '' !== $rest ) {
			$take     = $this->prefix_length( $rest, $token['style'], $width );
			$pieces[] = $this->token( (string) mb_substr( $rest, 0, $take, 'UTF-8' ), $token['style'] );
			$rest     = (string) mb_substr( $rest, $take, null, 'UTF-8' );
		}

		return $pieces;
	}

	/**
	 * How many leading characters of $text fit in $width, at least one.
	 *
	 * The search halves the word instead of walking it, which costs a
	 * logarithmic number of measurements rather than one per character. It
	 * never answers zero, so the caller cutting a word always advances.
	 *
	 * @param string              $text  Text to cut.
	 * @param array<string,mixed> $style Style the text is drawn in.
	 * @param float               $width Available width, mm.
	 * @return int
	 */
	private function prefix_length( $text, array $style, $width ) {
		$low  = 1;
		$high = mb_strlen( $text, 'UTF-8' );

		while ( $low < $high ) {
			$middle = (int) ceil( ( $low + $high ) / 2 );
			$piece  = (string) mb_substr( $text, 0, $middle, 'UTF-8' );

			if ( (float) call_user_func( $this->measure, $piece, $style ) <= $width ) {
				$low = $middle;
			} else {
				$high = $middle - 1;
			}
		}

		return $low;
	}

	/**
	 * Close a line: drop the space at its edge, join tokens of equal style
	 * into runs, and total up what the caller needs to justify it.
	 *
	 * @param array<int,array<string,mixed>> $tokens Tokens gathered for the line.
	 * @param bool                           $last   Whether the line ends the paragraph or precedes a forced break.
	 * @return array{runs:array,width:float,spaces:int,last:bool}
	 */
	private function close( array $tokens, $last ) {
		$end = count( $tokens );
		if ( $end > 0 && $tokens[ $end - 1 ]['space'] ) {
			--$end;
		}

		$drawn  = array_slice( $tokens, 0, $end );
		$runs   = array();
		$style  = null;
		$spaces = 0;

		foreach ( $drawn as $token ) {
			$spaces += $token['space'] ? 1 : 0;

			// Same style as the token before: one cell, not one per word.
			if ( $style === $token['style'] ) {
				$runs[ count( $runs ) - 1 ]['text'] .= $token['text'];
				continue;
			}

			$style  = $token['style'];
			$runs[] = array_merge( array( 'text' => $token['text'] ), $style );
		}

		return array(
			'runs'   => $runs,
			'width'  => $this->width_of( $drawn ),
			'spaces' => $spaces,
			'last'   => $last,
		);
	}
}
