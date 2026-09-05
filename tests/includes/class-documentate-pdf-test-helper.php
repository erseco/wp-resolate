<?php
/**
 * Extracts text and image operations from PDF bytes produced by FPDF, for assertions.
 *
 * The helper walks the indirect objects of the file, keeps the page objects in
 * the order FPDF wrote them, and reads the content stream each one points at
 * through its /Contents entry. Streams that no page references — image
 * XObjects, embedded fonts, ToUnicode maps — are never scanned, so raw bytes
 * that happen to look like a text operator cannot leak into the results.
 *
 * Only the operators FPDF emits are understood: `BT x y Td (text) Tj ET` for
 * text, `q w 0 0 h x y cm /Name Do Q` for an image placement, and the escapes
 * `\\`, `\(`, `\)` and `\r` inside a PDF string. Text is decoded from cp1252,
 * the encoding FPDF uses for the core fonts.
 *
 * @package Documentate
 */

/**
 * Reads text and image placements back out of PDF bytes.
 */
class Documentate_Pdf_Test_Helper {

	/**
	 * Return every text operation as [page, x, y, text(UTF-8)].
	 *
	 * Coordinates are the raw PDF user-space values in points, measured from
	 * the bottom-left corner of the page. Pages are numbered from one.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<int,array{page:int,x:float,y:float,text:string}>
	 */
	public static function text_ops( $pdf ) {
		$ops = array();

		foreach ( self::page_contents( (string) $pdf ) as $page => $content ) {
			$ops = array_merge( $ops, self::text_ops_in( $content, $page ) );
		}

		return $ops;
	}

	/**
	 * Return every image placement as [page, name, x, y, w, h].
	 *
	 * A placement is attributed to the page whose content stream invokes it,
	 * which is the only way to tell an image drawn on page one from the same
	 * image drawn on page two: the image dictionary is written once however
	 * many pages use it, so counting `/Subtype /Image` says nothing about where
	 * anything was drawn.
	 *
	 * Only the `q w 0 0 h x y cm /Name Do Q` form FPDF emits is understood.
	 * Sizes and coordinates are the raw PDF user-space values in points, with
	 * x and y measured from the bottom-left corner of the page. Pages are
	 * numbered from one.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<int,array{page:int,name:string,x:float,y:float,w:float,h:float}>
	 */
	public static function image_ops( $pdf ) {
		$ops = array();

		foreach ( self::page_contents( (string) $pdf ) as $page => $content ) {
			$ops = array_merge( $ops, self::image_ops_in( $content, $page ) );
		}

		return $ops;
	}

	/**
	 * Only the texts, in drawing order.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return string[]
	 */
	public static function texts( $pdf ) {
		return array_column( self::text_ops( $pdf ), 'text' );
	}

	/**
	 * Count pages by /Type /Page objects (not /Pages).
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return int
	 */
	public static function page_count( $pdf ) {
		$count = 0;

		foreach ( self::objects( (string) $pdf ) as $object ) {
			if ( self::is_page_object( $object['dict'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Decoded content stream of every page, keyed by page number from one.
	 *
	 * Pages whose content cannot be read are left out, so the keys are not
	 * necessarily contiguous, but the numbering still follows the page objects.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<int,string>
	 */
	private static function page_contents( $pdf ) {
		$objects  = self::objects( $pdf );
		$contents = array();
		$page     = 0;

		foreach ( $objects as $object ) {
			if ( ! self::is_page_object( $object['dict'] ) ) {
				continue;
			}
			++$page;

			$content = self::content_of( $object['dict'], $objects );
			if ( '' !== $content ) {
				$contents[ $page ] = $content;
			}
		}

		return $contents;
	}

	/**
	 * Split the file into indirect objects, keyed by object number.
	 *
	 * The scan is linear and jumps over stream data instead of searching
	 * through it, so binary bytes that look like `12 0 obj` cannot desynchronise
	 * it. Insertion order is the order the objects appear in the file, which for
	 * FPDF is also the page order: `_putpages()` writes page one first.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return array<int,array{dict:string,stream:?string}>
	 */
	private static function objects( $pdf ) {
		$objects = array();
		$length  = strlen( $pdf );
		$offset  = 0;

		while ( $offset < $length && preg_match( '/(\d+)\s+0\s+obj\b/', $pdf, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$number = (int) $match[1][0];
			$body   = $match[0][1] + strlen( $match[0][0] );

			$ends_at = strpos( $pdf, 'endobj', $body );
			$ends_at = false === $ends_at ? $length : $ends_at;

			$stream_at = strpos( $pdf, 'stream', $body );
			if ( false === $stream_at || $stream_at > $ends_at ) {
				$objects[ $number ] = array(
					'dict'   => substr( $pdf, $body, $ends_at - $body ),
					'stream' => null,
				);
				$offset             = $ends_at + strlen( 'endobj' );
				continue;
			}

			$dict               = substr( $pdf, $body, $stream_at - $body );
			$stream             = self::read_stream( $pdf, $dict, $stream_at );
			$objects[ $number ] = array(
				'dict'   => $dict,
				'stream' => $stream['data'],
			);
			$offset             = $stream['next'];
		}

		return $objects;
	}

	/**
	 * Read the stream that starts at the given `stream` keyword.
	 *
	 * The /Length entry is used when it lands exactly on `endstream`; otherwise
	 * the stream is read up to the first `endstream` instead, so a file with a
	 * wrong or missing length still parses.
	 *
	 * @param string $pdf       Raw PDF bytes.
	 * @param string $dict      Dictionary of the object holding the stream.
	 * @param int    $stream_at Offset of the `stream` keyword.
	 * @return array{data:string,next:int} Stream data as stored, and the offset just past it.
	 */
	private static function read_stream( $pdf, $dict, $stream_at ) {
		if ( ! preg_match( '/stream\r?\n/A', $pdf, $keyword, 0, $stream_at ) ) {
			return array(
				'data' => '',
				'next' => $stream_at + strlen( 'stream' ),
			);
		}

		$starts_at = $stream_at + strlen( $keyword[0] );
		$size      = null;

		if ( preg_match( '#/Length\s+(\d+)#', $dict, $declared ) ) {
			$candidate = (int) $declared[1];
			if ( $starts_at + $candidate <= strlen( $pdf )
				&& preg_match( '/\r?\nendstream/A', $pdf, $tail, 0, $starts_at + $candidate ) ) {
				$size = $candidate;
			}
		}

		if ( null === $size ) {
			$ends_at = strpos( $pdf, 'endstream', $starts_at );
			$ends_at = false === $ends_at ? strlen( $pdf ) : $ends_at;
			$size    = strlen( rtrim( substr( $pdf, $starts_at, $ends_at - $starts_at ), "\r\n" ) );
		}

		return array(
			'data' => substr( $pdf, $starts_at, $size ),
			'next' => $starts_at + $size,
		);
	}

	/**
	 * Whether a dictionary belongs to a page, and not to the /Pages tree node.
	 *
	 * @param string $dict Object dictionary.
	 * @return bool
	 */
	private static function is_page_object( $dict ) {
		return 1 === preg_match( '#/Type\s*/Page(?![a-zA-Z])#', $dict );
	}

	/**
	 * Decode the content stream a page object points at through /Contents.
	 *
	 * @param string $dict    Page dictionary.
	 * @param array  $objects Every object in the file, keyed by number.
	 * @return string Decoded content stream, or an empty string when there is none.
	 */
	private static function content_of( $dict, array $objects ) {
		if ( ! preg_match( '#/Contents\s+(\d+)\s+0\s+R#', $dict, $reference ) ) {
			return '';
		}

		$number = (int) $reference[1];
		if ( ! isset( $objects[ $number ] ) || null === $objects[ $number ]['stream'] ) {
			return '';
		}

		$content = $objects[ $number ]['stream'];
		if ( false === strpos( $objects[ $number ]['dict'], '/FlateDecode' ) ) {
			return $content;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a stream that fails to inflate is reported as empty, not as a warning.
		$inflated = @gzuncompress( $content );

		return false === $inflated ? '' : $inflated;
	}

	/**
	 * Pull the text-showing operations out of one decoded content stream.
	 *
	 * @param string $content Decoded content stream.
	 * @param int    $page    Number of the page the stream belongs to.
	 * @return array<int,array{page:int,x:float,y:float,text:string}>
	 */
	private static function text_ops_in( $content, $page ) {
		$pattern = '/BT\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Td\s+\(((?:\\\\.|[^\\\\()])*)\)\s*Tj\s+ET/s';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$ops = array();
		foreach ( $matches as $match ) {
			$ops[] = array(
				'page' => $page,
				'x'    => (float) $match[1],
				'y'    => (float) $match[2],
				'text' => self::decode_string( $match[3] ),
			);
		}

		return $ops;
	}

	/**
	 * Pull the image placements out of one decoded content stream.
	 *
	 * @param string $content Decoded content stream.
	 * @param int    $page    Number of the page the stream belongs to.
	 * @return array<int,array{page:int,name:string,x:float,y:float,w:float,h:float}>
	 */
	private static function image_ops_in( $content, $page ) {
		$pattern = '#q\s+(-?[\d.]+)\s+0\s+0\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+cm\s*/([A-Za-z0-9]+)\s+Do\s+Q#';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$ops = array();
		foreach ( $matches as $match ) {
			$ops[] = array(
				'page' => $page,
				'name' => $match[5],
				'x'    => (float) $match[3],
				'y'    => (float) $match[4],
				'w'    => (float) $match[1],
				'h'    => (float) $match[2],
			);
		}

		return $ops;
	}

	/**
	 * Undo the escaping FPDF applies to a PDF string and convert it to UTF-8.
	 *
	 * @param string $raw Raw bytes between the parentheses of a PDF string.
	 * @return string
	 */
	private static function decode_string( $raw ) {
		$unescaped = strtr(
			$raw,
			array(
				'\\\\' => '\\',
				'\\('  => '(',
				'\\)'  => ')',
				'\\r'  => "\r",
			)
		);

		return mb_convert_encoding( $unescaped, 'UTF-8', 'Windows-1252' );
	}
}
