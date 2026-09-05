<?php
/**
 * The rows the generic PDF layout prints.
 *
 * The generic layout is what a document type that names no layout of its own
 * falls back to. It carries no field names, so it prints the schema as it
 * finds it: one row per field, each a label and a value. This class decides
 * what a row looks like; the document generator decides what a value is.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Builds the `documentate_fields` rows of the generic layout.
 */
class Documentate_Pdf_Generic_Rows {

	/**
	 * The row of a scalar field.
	 *
	 * A row carries its value either as text or as HTML, never as both: the
	 * layout escapes the first and injects the second verbatim, so a rich
	 * value put in the wrong one would print its own tags.
	 *
	 * A value does not have to arrive as a string. A number field is
	 * normalised to an int or a float and a boolean field to 1 or 0, so every
	 * scalar is cast rather than only strings being kept: dropping the others
	 * would print the label of an amount with nothing under it, and the same
	 * document would show a figure in its ODT and a gap in its PDF. Zero casts
	 * to "0" and is printed, because zero is an amount like any other.
	 *
	 * @param string $label   Label the row is introduced by.
	 * @param mixed  $value   Prepared field value: a string, a number or a boolean.
	 * @param bool   $is_rich Whether the value is HTML that must be drawn as markup.
	 * @return array{label:string,text:string,html:string}
	 */
	public static function scalar( $label, $value, $is_rich ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return array(
			'label' => (string) $label,
			'text'  => $is_rich ? '' : $value,
			'html'  => $is_rich ? $value : '',
		);
	}

	/**
	 * The row of a repeater, whose records make a table.
	 *
	 * @param string $label       Label the row is introduced by.
	 * @param array  $item_schema Item fields the repeater declares.
	 * @param array  $items       Records stored for it.
	 * @return array{label:string,text:string,html:string}
	 */
	public static function repeater( $label, array $item_schema, array $items ) {
		return array(
			'label' => (string) $label,
			'text'  => '',
			'html'  => self::table( $item_schema, $items ),
		);
	}

	/**
	 * Draw the records of a repeater as an HTML table.
	 *
	 * Every cell is escaped, so a stored value can never close the table it is
	 * being drawn into and reach the rest of the page, and stripped of its own
	 * markup, so a rich field prints its words rather than its tags.
	 *
	 * @param array $item_schema Item fields the repeater declares.
	 * @param array $items       Records stored for it.
	 * @return string Table markup, or an empty string when there is nothing to draw.
	 */
	private static function table( array $item_schema, array $items ) {
		$columns = self::columns( $item_schema, $items );
		if ( empty( $columns ) || empty( $items ) ) {
			return '';
		}

		$head = '';
		foreach ( $columns as $label ) {
			$head .= '<th>' . esc_html( $label ) . '</th>';
		}

		$body = '';
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$body .= '<tr>';
			foreach ( array_keys( $columns ) as $key ) {
				$cell  = isset( $item[ $key ] ) && is_string( $item[ $key ] ) ? $item[ $key ] : '';
				$body .= '<td>' . esc_html( wp_strip_all_tags( $cell ) ) . '</td>';
			}
			$body .= '</tr>';
		}

		return '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
	}

	/**
	 * Columns of a repeater table: record key => header text.
	 *
	 * The item schema decides which columns there are and in which order, so a
	 * record carrying a key the schema has since dropped does not add a column
	 * of its own. Only a schema that declares no item fields at all falls back
	 * to reading the keys off the records.
	 *
	 * @param array $item_schema Item fields the repeater declares.
	 * @param array $items       Records stored for it.
	 * @return array<string,string>
	 */
	private static function columns( array $item_schema, array $items ) {
		$columns = array();

		foreach ( $item_schema as $key => $field ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			$label           = ( is_array( $field ) && isset( $field['label'] ) && '' !== $field['label'] ) ? (string) $field['label'] : $key;
			$columns[ $key ] = $label;
		}

		return empty( $columns ) ? self::columns_from_items( $items ) : $columns;
	}

	/**
	 * Columns read off the records themselves, headed by their own keys.
	 *
	 * @param array $items Records stored for a repeater.
	 * @return array<string,string>
	 */
	private static function columns_from_items( array $items ) {
		$columns = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			foreach ( array_keys( $item ) as $key ) {
				if ( is_string( $key ) && '' !== $key ) {
					$columns[ $key ] = $key;
				}
			}
		}

		return $columns;
	}
}
