<?php
/**
 * Bounded paragraph metrics inherited from the office layout's HTML.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

// DOM's parentNode is an extension property, not a plugin variable.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Resolves paragraph line advance independently of drawing and measurement.
 */
class Documentate_Pdf_Paragraph_Style {

	/**
	 * Read inherited fixed line advance, measured from the office template.
	 *
	 * Only finite point values from 4 to 60 are accepted. This cannot move the
	 * cursor backwards or create unbounded gaps from pasted rich content.
	 *
	 * @param DOMNode $node Paragraph or containing element.
	 * @return float|null Line advance in millimetres, or the font default.
	 */
	public static function line_height( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return null;
		}
		if ( preg_match( '/(?:^|;)\s*line-height\s*:\s*([0-9]+(?:\.[0-9]+)?)pt\s*(?:;|$)/i', $node->getAttribute( 'style' ), $match ) ) {
			$points = (float) $match[1];
			if ( is_finite( $points ) && $points >= 4 && $points <= 60 ) {
				return $points * Documentate_Pdf_Document::MM_PER_POINT;
			}
		}
		return $node->parentNode instanceof DOMElement ? self::line_height( $node->parentNode ) : null;
	}
}
