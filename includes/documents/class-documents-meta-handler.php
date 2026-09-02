<?php
/**
 * Meta handling for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

use Documentate\DocType\SchemaConverter;
use Documentate\DocType\SchemaStorage;

/**
 * Handles document meta persistence and retrieval.
 */
class Documents_Meta_Handler {
	/**
	 * Hard cap on how many rows a repeater stores.
	 *
	 * @var int
	 */
	const ARRAY_FIELD_MAX_ITEMS = 20;

	/**
	 * Parse the structured post_content string into slug/value pairs.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function parse_structured_content( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return array();
		}

		$pattern = '/<!--\s*documentate-field\s+([^>]*)-->(.*?)<!--\s*\/documentate-field\s*-->/si';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$fields = array();
		foreach ( $matches as $match ) {
			$attrs = self::parse_structured_field_attributes( $match[1] );
			$slug = isset( $attrs['slug'] ) ? sanitize_key( $attrs['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$type = isset( $attrs['type'] ) ? sanitize_key( $attrs['type'] ) : '';
			$fields[ $slug ] = array(
				'value' => trim( (string) $match[2] ),
				'type' => $type,
			);
		}

		return $fields;
	}

	/**
	 * Parse attribute string from a structured field marker.
	 *
	 * @param string $attribute_string Raw attribute string.
	 * @return array<string,string>
	 */
	private static function parse_structured_field_attributes( $attribute_string ) {
		$result = array();
		$pattern = '/([a-zA-Z0-9_-]+)="([^"]*)"/';
		if ( preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key = strtolower( $match[1] );
				$result[ $key ] = $match[2];
			}
		}
		return $result;
	}

	/**
	 * Compose the HTML comment fragment that stores a field value.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	public static function build_structured_field_fragment( $slug, $type, $value ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}
		$type = sanitize_key( $type );
		if ( ! in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
			$type = '';
		}

		$attributes = 'slug="' . esc_attr( $slug ) . '"';
		if ( '' !== $type ) {
			$attributes .= ' type="' . esc_attr( $type ) . '"';
		}

		$value = (string) $value;
		return '<!-- documentate-field ' . $attributes . " -->\n" . $value . "\n<!-- /documentate-field -->";
	}

	/**
	 * Get sanitized schema array for a document type term.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	public static function get_term_schema( $term_id ) {
		$storage = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );

		if ( is_array( $schema_v2 ) && ! empty( $schema_v2 ) ) {
			return SchemaConverter::to_legacy( $schema_v2 );
		}

		return array();
	}

	/**
	 * Get dynamic fields schema for the selected document type of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of field definitions with keys: slug, label, type.
	 */
	public static function get_dynamic_fields_schema_for_post( $post_id ) {
		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$term_id = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;
		if ( $term_id <= 0 ) {
			return array();
		}
		return self::get_term_schema( $term_id );
	}

	/**
	 * Return the list of custom meta keys used by this CPT for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function get_meta_fields_for_post( $post_id ) {
		$fields = array();
		$known = array();

		$dynamic = self::get_dynamic_fields_schema_for_post( $post_id );
		if ( ! empty( $dynamic ) ) {
			foreach ( $dynamic as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$key = 'documentate_field_' . sanitize_key( $def['slug'] );
				if ( '' === $key ) {
					continue;
				}
				$fields[] = $key;
				$known[ $key ] = true;
			}
		}

		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( ! empty( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $values ) {
					unset( $values );
					if ( ! str_starts_with( $meta_key, 'documentate_field_' ) ) {
						continue;
					}
					if ( isset( $known[ $meta_key ] ) ) {
						continue;
					}
					$fields[] = $meta_key;
				}
			}
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * Check if a value contains HTML that requires rich text handling.
	 *
	 * Detects both block-level elements (p, div, table, etc.) and inline
	 * formatting elements (strong, em, a, etc.) to ensure content is
	 * preserved correctly during sanitization.
	 *
	 * @param string $value Field value to check.
	 * @return bool True if value contains HTML tags that need rich handling.
	 */
	public static function value_contains_block_html( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		// Block-level elements that need rich text handling.
		$block_tags = array(
			'table',
			'thead',
			'tbody',
			'tr',
			'td',
			'th',
			'ul',
			'ol',
			'li',
			'p',
			'div',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'blockquote',
			'pre',
		);
		foreach ( $block_tags as $tag ) {
			if ( false !== stripos( $value, '<' . $tag ) ) {
				return true;
			}
		}
		// Inline formatting elements - detect these to preserve rich content
		// even when TinyMCE doesn't wrap in <p> tags.
		// Use regex to match complete tag names (avoid '<script' matching '<s').
		$inline_tags = array( 'strong', 'b', 'em', 'i', 'u', 'a', 'span', 'br', 'sub', 'sup', 's', 'strike' );
		foreach ( $inline_tags as $tag ) {
			// Match <tag> or <tag with attributes (e.g., <a href="...">).
			if ( preg_match( '/<' . $tag . '(?:\s|>|\/)/i', $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recover accidentally unescaped Unicode sequences (e.g., u00e1) in strings.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function fix_unescaped_unicode_sequences( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'u00' ) ) {
			return $text;
		}

		$callback = static function ( $m ) {
			$hex = $m[1];
			if ( 4 !== strlen( $hex ) ) {
				return $m[0];
			}
			$code = hexdec( $hex );
			$utf16 = pack( 'n', $code );
			if ( function_exists( 'mb_convert_encoding' ) ) {
				return mb_convert_encoding( $utf16, 'UTF-8', 'UTF-16BE' );
			}
			if ( function_exists( 'iconv' ) ) {
				return (string) iconv( 'UTF-16BE', 'UTF-8', $utf16 );
			}
			return $m[0];
		};

		return (string) preg_replace_callback( '/u([0-9a-fA-F]{4})/i', $callback, $text );
	}

	/**
	 * Create a human readable label for an unknown dynamic field meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	public static function humanize_field_label( $meta_key ) {
		$slug = str_replace( 'documentate_field_', '', (string) $meta_key );
		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', $slug ) );
		if ( '' === $slug ) {
			return (string) $meta_key;
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $slug );
	}
	/**
	 * Decode a structured JSON value into array items.
	 *
	 * @param string $value JSON encoded string.
	 * @return array<int, array<string, string>>
	 */
	public static function decode_array_field_value( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return array();
		}

		// WordPress may double-encode HTML entities when saving to meta.
		// Decode them before attempting to parse as JSON.
		if ( false !== strpos( $value, '&' ) ) {
			$value = wp_specialchars_decode( $value, ENT_QUOTES );
		}

		// The value should be valid JSON encoded with JSON_HEX flags.
		// JSON_HEX_QUOT/TAG/AMP/APOS encode special characters as \uXXXX sequences.
		// These are standard JSON and will be decoded correctly by json_decode.
		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return self::normalize_decoded_array_items( $decoded );
	}
	/**
	 * Normalize decoded repeater rows, keeping nested sub-repeater rows.
	 *
	 * A row value that is itself a list of rows (a TBS sub-block, e.g. the
	 * conceptos of one provider) is normalized recursively instead of being
	 * flattened to an empty string.
	 *
	 * @param array $decoded Decoded rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function normalize_decoded_array_items( array $decoded ) {
		$items = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized = array();
			foreach ( $item as $key => $val ) {
				if ( is_array( $val ) ) {
					$normalized[ sanitize_key( $key ) ] = self::normalize_decoded_array_items( $val );
					continue;
				}
				$normalized[ sanitize_key( $key ) ] = is_scalar( $val )
					? (string) self::fix_unescaped_unicode_sequences( (string) $val )
					: '';
			}
			$items[] = $normalized;
		}

		return array_slice( $items, 0, self::ARRAY_FIELD_MAX_ITEMS );
	}
	/**
	 * Create a human readable label for an unknown dynamic field meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	public static function humanize_unknown_field_label( $meta_key ) {
		$slug = str_replace( 'documentate_field_', '', (string) $meta_key );
		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', $slug ) );
		if ( '' === $slug ) {
			return (string) $meta_key;
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $slug );
	}
	/**
	 * Read a repeater field from meta as a JSON string.
	 *
	 * Falls back to the pre-JSON meta keys, including the historical
	 * documentate_annexes one, so older documents keep their rows.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Sanitized field slug.
	 * @return string JSON payload, or an empty string when nothing is stored.
	 */
	public static function read_array_field_from_meta( $post_id, $slug ) {
		$stored = get_post_meta( $post_id, 'documentate_field_' . $slug, true );
		if ( is_string( $stored ) && '' !== $stored ) {
			return (string) $stored;
		}

		$legacy = get_post_meta( $post_id, 'documentate_' . $slug, true );
		if ( empty( $legacy ) && 'annexes' === $slug ) {
			$legacy = get_post_meta( $post_id, 'documentate_annexes', true );
		}

		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			return (string) wp_json_encode( $legacy, JSON_UNESCAPED_UNICODE );
		}

		return '';
	}
	/**
	 * Normalize the item schema for an array field definition.
	 *
	 * Each entry keeps the rol its column declares, so the editor and the
	 * content writer agree on which columns gestión documental owns.
	 *
	 * @param array $definition Field definition from the schema.
	 * @return array<string, array{label:string,type:string,data_type:string,rol:string}>
	 */
	public static function normalize_array_item_schema( $definition ) {
		$schema = array();

		if ( isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] ) ) {
			foreach ( $definition['item_schema'] as $key => $item ) {
				$item_key = sanitize_key( $key );
				if ( '' === $item_key ) {
					continue;
				}

				$label = isset( $item['label'] )
					? sanitize_text_field( $item['label'] )
					: self::humanize_unknown_field_label( $item_key );
				$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';

				// Nested repeater (TBS sub-block): keep the array type and
				// normalize its own item schema recursively.
				if ( 'array' === $type && isset( $item['item_schema'] ) && is_array( $item['item_schema'] ) ) {
					$schema[ $item_key ] = array(
						'label' => $label,
						'type' => 'array',
						'data_type' => 'array',
						'rol' => \Documentate_Campos_Rol::rol_del_campo( (array) $item ),
						'item_schema' => self::normalize_array_item_schema( $item ),
					);
					continue;
				}

				if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
				}

				$data_type = isset( $item['data_type'] ) ? sanitize_key( $item['data_type'] ) : 'text';
				if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
					$data_type = 'text';
				}

				$schema[ $item_key ] = array(
					'label' => $label,
					'type' => $type,
					'data_type' => $data_type,
					'rol' => \Documentate_Campos_Rol::rol_del_campo( (array) $item ),
				);
			}
		}

		if ( empty( $schema ) ) {
			$schema['content'] = array(
				'label' => __( 'Content', 'documentate' ),
				'type' => 'textarea',
				'data_type' => 'text',
				'rol' => \Documentate_Campos_Rol::ROL_AREA,
			);
		}

		return $schema;
	}
	/**
	 * Rebuild field values from post meta.
	 *
	 * Used for documents saved before the values moved into post_content, so
	 * the editor still shows what was stored back then.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function build_field_values_from_meta( $post_id ) {
		$fallback = array();

		foreach ( self::get_dynamic_fields_schema_for_post( $post_id ) as $row ) {
			$slug = ! empty( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$value = get_post_meta( $post_id, 'documentate_field_' . $slug, true );
			if ( '' === $value ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';

			if ( 'array' === $type ) {
				$encoded = self::read_array_field_from_meta( $post_id, $slug );
				if ( '' !== $encoded ) {
					$fallback[ $slug ] = array(
						'value' => $encoded,
						'type' => 'array',
					);
				}
				continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$fallback[ $slug ] = array(
				'value' => (string) $value,
				'type' => $type,
			);
		}

		return $fallback;
	}
	/**
	 * Retrieve structured field values stored in post_content.
	 *
	 * Falls back to dynamic meta keys when the content has not been migrated yet.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function get_structured_field_values( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$fields = self::parse_structured_content( $post->post_content );
		if ( ! empty( $fields ) ) {
			return $fields;
		}

		return self::build_field_values_from_meta( $post_id );
	}
	/**
	 * Decode stored structured field data into array items.
	 *
	 * @param array $entry Structured entry with type/value keys.
	 * @return array<int, array<string, string>>
	 */
	public static function get_array_field_items_from_structured( $entry ) {
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$value = isset( $entry['value'] ) ? (string) $entry['value'] : '';
		return self::decode_array_field_value( $value );
	}
}
