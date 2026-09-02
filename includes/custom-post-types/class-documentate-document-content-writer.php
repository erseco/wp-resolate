<?php
/**
 * Composes the post_content a document's revisions diff against.
 *
 * Extracted from Documentate_Document_Meta_Saver. Writing field values to meta
 * and building the structured post_content are separate jobs that happen on the
 * same request; wp-decker draws the same line between its task saver and writer.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Composes the post_content a document's revisions diff against.
 */
class Documentate_Document_Content_Writer {

	/**
	 * Sanitizers by control type; any other type is sanitized as a textarea.
	 *
	 * @var array<string,callable>
	 */
	private static $sanitizer_map = array(
		'single' => 'sanitize_text_field',
		'rich' => array( __CLASS__, 'sanitize_rich_text_value' ),
		'textarea' => 'sanitize_textarea_field',
	);

	/**
	 * Encode repeater rows for storage.
	 *
	 * Uses the JSON_HEX flags so quotes and other special characters become
	 * \uXXXX sequences, avoiding WordPress's automatic slashing and unslashing
	 * of quotes. JSON_UNESCAPED_UNICODE is deliberately NOT used, so accented
	 * characters are encoded the same way and fix_unescaped_unicode_sequences
	 * can handle them consistently.
	 *
	 * @param array $items Sanitized repeater rows.
	 * @return string JSON payload, or an empty string when there are no rows.
	 */
	public static function encode_array_field_items( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$json_flags = JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;

		return (string) wp_json_encode( $items, $json_flags );
	}
	/**
	 * Sanitize posted array field items against the schema definition.
	 *
	 * @param array $items      Raw submitted items.
	 * @param array $definition Schema definition for the field.
	 * @return array<int, array<string, string>>
	 */
	public static function sanitize_array_field_items( $items, $definition ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$schema = Documents_Meta_Handler::normalize_array_item_schema( $definition );
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$filtered = self::sanitize_array_item( $item, $schema );

			if ( self::array_item_has_content( $filtered, $schema ) ) {
				$clean[] = $filtered;
			}
		}

		return array_values( array_slice( $clean, 0, Documents_Meta_Handler::ARRAY_FIELD_MAX_ITEMS ) );
	}
	/**
	 * Sanitize a field value based on its type.
	 *
	 * Uses lookup array instead of switch for reduced complexity.
	 *
	 * @param string $raw_value Raw value to sanitize.
	 * @param string $type      Field type (single, rich, or default to textarea).
	 * @return string Sanitized value.
	 */
	public static function sanitize_field_by_type( $raw_value, $type ) {
		$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';
		$sanitizer = isset( self::$sanitizer_map[ $type ] ) ? self::$sanitizer_map[ $type ] : 'sanitize_textarea_field';

		return call_user_func( $sanitizer, $raw_value );
	}
	/**
	 * Sanitize rich text content by stripping dangerous elements only.
	 *
	 * Only removes security-critical elements (script, style, iframe).
	 * Full sanitization and cleanup is deferred to document generation time.
	 *
	 * @param string $value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_rich_text_value( $value ) {
		$value = wp_unslash( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Normalize line endings.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		// Only strip dangerous elements (security filtering).
		$patterns = array(
			'#<script\b[^>]*>.*?</script>#is',
			'#<style\b[^>]*>.*?</style>#is',
			'#<iframe\b[^>]*>.*?</iframe>#is',
		);

		$clean = preg_replace( $patterns, '', $value );

		return null === $clean ? $value : $clean;
	}
	/**
	 * Whether a sanitized repeater row carries any visible value.
	 *
	 * Rich values are stripped of markup first, so a row holding only empty
	 * tags counts as blank and is dropped.
	 *
	 * @param array $filtered Sanitized row.
	 * @param array $schema   Normalized item schema.
	 * @return bool
	 */
	private static function array_item_has_content( array $filtered, array $schema ) {
		foreach ( $filtered as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return true;
				}
				continue;
			}

			$type = isset( $schema[ $key ]['type'] ) ? $schema[ $key ]['type'] : 'textarea';
			$text = 'rich' === $type
				? wp_strip_all_tags( (string) $value )
				: (string) $value;

			if ( '' !== trim( $text ) ) {
				return true;
			}
		}

		return false;
	}
	/**
	 * Serialise the composed fields into the stored post_content.
	 *
	 * @param array $structured_fields Entries the schema produced.
	 * @param array $unknown_fields    Entries not declared by the schema.
	 * @return string Empty string when there is nothing to store.
	 */
	private static function build_structured_content( array $structured_fields, array $unknown_fields ) {
		$fragments = array();

		foreach ( $structured_fields as $slug => $info ) {
			$fragments[] = self::build_structured_field_fragment( $slug, $info['type'], $info['value'] );
		}

		foreach ( $unknown_fields as $slug => $info ) {
			$fragments[] = self::build_structured_field_fragment( $slug, $info['type'], $info['value'] );
		}

		return implode( "\n\n", $fragments );
	}
	/**
	 * Compose the HTML comment fragment that stores a field value.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	private static function build_structured_field_fragment( $slug, $type, $value ) {
		return Documents_Meta_Handler::build_structured_field_fragment( $slug, $type, $value );
	}
	/**
	 * Collect existing structured content from post.
	 *
	 * @param array<string,mixed> $postarr  Post array.
	 * @param int                 $post_id  Post ID.
	 * @return array<string,array{value:string,type:string}>
	 */
	private static function collect_existing_structured_content( $postarr, $post_id ) {
		$existing_structured = array();
		if ( isset( $postarr['post_content'] ) && '' !== $postarr['post_content'] ) {
			$existing_structured = Documents_Meta_Handler::parse_structured_content( (string) $postarr['post_content'] );
		}
		if ( empty( $existing_structured ) && $post_id > 0 ) {
			$current_content = get_post_field( 'post_content', $post_id, 'edit' );
			if ( is_string( $current_content ) && '' !== $current_content ) {
				$existing_structured = Documents_Meta_Handler::parse_structured_content( $current_content );
			}
			if ( empty( $existing_structured ) ) {
				$existing_structured = Documents_Meta_Handler::get_structured_field_values( $post_id );
			}
		}
		return $existing_structured;
	}
	/**
	 * Build the entry for a repeater field.
	 *
	 * Submitted rows win; otherwise the rows already stored are carried over, so
	 * a save that does not include the repeater cannot silently empty it.
	 *
	 * @param string $slug                Field slug.
	 * @param array  $row                 Schema row.
	 * @param array  $existing_structured Values already stored in post_content.
	 * @param array  $posted_array_fields Submitted repeater rows.
	 * @return array{type:string,value:string}
	 */
	private static function compose_array_field( $slug, $row, array $existing_structured, array $posted_array_fields ) {
		$items = array();

		if ( isset( $posted_array_fields[ $slug ] ) && is_array( $posted_array_fields[ $slug ] ) ) {
			$items = self::sanitize_array_field_items( $posted_array_fields[ $slug ], $row );
		} elseif (
			isset( $existing_structured[ $slug ]['type'] )
			&& 'array' === $existing_structured[ $slug ]['type']
		) {
			$items = Documents_Meta_Handler::get_array_field_items_from_structured( $existing_structured[ $slug ] );
		}

		// Encoded with the same flags as the meta copy, so both representations
		// round-trip identically. wp_slash() preserves backslashes (like \n and
		// \uXXXX) through the wp_unslash() inside wp_insert_post().
		$json_value = self::encode_array_field_items( $items );

		return array(
			'type' => 'array',
			'value' => wp_slash( '' === $json_value ? '[]' : $json_value ),
		);
	}
	/**
	 * Carry over stored values the schema no longer declares.
	 *
	 * A posted value still wins, so editing a field that survived a document
	 * type change is not discarded.
	 *
	 * @param array $existing_structured Values already stored in post_content.
	 * @param array $known_slugs         Slugs the schema already handled.
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function compose_carried_over_fields( array $existing_structured, array $known_slugs ) {
		$fields = array();

		foreach ( $existing_structured as $slug => $info ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || isset( $known_slugs[ $slug ] ) || isset( $fields[ $slug ] ) ) {
				continue;
			}

			$meta_key = 'documentate_field_' . $slug;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $meta_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
				$val = wp_unslash( $_POST[ $meta_key ] );
				$val = is_scalar( $val ) ? (string) $val : '';

				$fields[ $slug ] = array(
					'type' => 'rich',
					'value' => self::sanitize_rich_text_value( $val ),
				);
				continue;
			}

			$type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : 'rich';
			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'rich';
			}

			$fields[ $slug ] = array(
				'type' => $type,
				'value' => (string) $info['value'],
			);
		}

		return $fields;
	}
	/**
	 * Add posted field values that neither the schema nor the stored content knew.
	 *
	 * @param array $structured_fields Entries the schema produced.
	 * @param array $unknown_fields    Entries carried over so far.
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function compose_posted_fields( array $structured_fields, array $unknown_fields ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $_POST as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'documentate_field_' ) ) {
				continue;
			}

			$slug = sanitize_key( substr( $key, strlen( 'documentate_field_' ) ) );
			if ( '' === $slug || isset( $structured_fields[ $slug ] ) || isset( $unknown_fields[ $slug ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}

			$raw_value = wp_unslash( $value );
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

			$unknown_fields[ $slug ] = array(
				'type' => 'rich',
				'value' => self::sanitize_rich_text_value( $raw_value ),
			);
		}

		return $unknown_fields;
	}
	/**
	 * Build the field entries declared by the document type schema.
	 *
	 * A field the current user cannot see (rol = gestion for an área user) is
	 * treated as not posted: whatever the request carries for it is ignored
	 * and the stored value is kept.
	 *
	 * @param array $schema              Schema rows.
	 * @param array $existing_structured Values already stored in post_content.
	 * @param array $known_slugs         Filled with the slugs the schema owns.
	 * @return array<string,array{type:string,value:string}>
	 */
	private static function compose_schema_fields( $schema, array $existing_structured, array &$known_slugs ) {
		$posted_array_fields = self::read_posted_array_fields();
		$fields = array();

		foreach ( (array) $schema as $row ) {
			$slug = ! empty( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
			$known_slugs[ $slug ] = true;
			$visible = Documentate_Campos_Rol::puede_ver( (array) $row );

			if ( 'array' === $type ) {
				$fields[ $slug ] = self::compose_array_field( $slug, $row, $existing_structured, $visible ? $posted_array_fields : array() );
				continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$fields[ $slug ] = self::process_posted_field_value(
				$slug,
				$type,
				'documentate_field_' . $slug,
				$existing_structured,
				$visible
			);
		}

		return $fields;
	}
	/**
	 * Filter post data before save to compose a Gutenberg-friendly post_content.
	 *
	 * @param array $data    Sanitized post data to be inserted.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public static function filter_post_data_compose_content( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || 'documentate_document' !== $data['post_type'] ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : 0;

		// Clear password - documents don't use password protection.
		$data['post_password'] = '';

		// Preserve post dates for existing posts.
		$data = self::preserve_document_dates( $data, $post_id );

		$term_id = self::get_term_id_from_request_or_post( $post_id );
		$schema = $term_id > 0 ? Documents_Meta_Handler::get_term_schema( $term_id ) : array();

		$existing_structured = self::collect_existing_structured_content( $postarr, $post_id );

		$known_slugs = array();
		$structured_fields = self::compose_schema_fields( $schema, $existing_structured, $known_slugs );

		// Values the schema no longer declares, then anything else posted.
		$unknown_fields = self::compose_carried_over_fields( $existing_structured, $known_slugs );
		$unknown_fields = self::compose_posted_fields( $structured_fields, $unknown_fields );

		$data['post_content'] = self::build_structured_content( $structured_fields, $unknown_fields );

		return $data;
	}
	/**
	 * Get term ID from request or existing post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private static function get_term_id_from_request_or_post( $post_id ) {
		$term_id = 0;
		if ( isset( $_POST['documentate_doc_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$term_id = max( 0, intval( wp_unslash( $_POST['documentate_doc_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( $term_id <= 0 && $post_id > 0 ) {
			$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				$term_id = intval( $assigned[0] );
			}
		}
		return $term_id;
	}
	/**
	 * Preserve post dates for existing documents.
	 *
	 * @param array<string,mixed> $data      Post data array.
	 * @param int                 $post_id   Post ID.
	 * @return array<string,mixed>
	 */
	private static function preserve_document_dates( $data, $post_id ) {
		if ( $post_id <= 0 ) {
			return $data;
		}

		$current_post = get_post( $post_id );
		if ( $current_post && 'documentate_document' === $current_post->post_type ) {
			if ( empty( $data['post_date'] ) || '0000-00-00 00:00:00' === $data['post_date'] ) {
				$data['post_date'] = $current_post->post_date;
				$data['post_date_gmt'] = $current_post->post_date_gmt;
			}
		}

		return $data;
	}
	/**
	 * Process a single field value from POST data.
	 *
	 * @param string              $slug     Field slug.
	 * @param string              $type     Field type.
	 * @param string              $meta_key Meta key.
	 * @param array<string,array> $existing Existing structured fields.
	 * @param bool                $posted   Whether the request may carry the field;
	 *                                      false keeps the stored value.
	 * @return array{type:string,value:string}
	 */
	private static function process_posted_field_value( $slug, $type, $meta_key, $existing, $posted = true ) {
		$value = '';

		if ( $posted && isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_input = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_input = is_scalar( $raw_input ) ? (string) $raw_input : '';

			if ( 'rich' !== $type && Documents_Meta_Handler::value_contains_block_html( $raw_input ) ) {
				$type = 'rich';
			}

			$value = self::sanitize_field_by_type( $raw_input, $type );
		} elseif ( isset( $existing[ $slug ] ) ) {
			$value = (string) $existing[ $slug ]['value'];
		}

		return array(
			'type' => $type,
			'value' => (string) $value,
		);
	}
	/**
	 * Submitted repeater rows, keyed by field slug.
	 *
	 * @return array
	 */
	private static function read_posted_array_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['tpl_fields'] ) || ! is_array( $_POST['tpl_fields'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rows are sanitized against the schema by sanitize_array_field_items().
		return wp_unslash( $_POST['tpl_fields'] );
	}
	/**
	 * Sanitize one repeater row against its item schema.
	 *
	 * @param array $item   Raw submitted row.
	 * @param array $schema Normalized item schema.
	 * @return array<string,string>
	 */
	private static function sanitize_array_item( array $item, array $schema ) {
		$filtered = array();

		foreach ( $schema as $key => $settings ) {
			$raw = isset( $item[ $key ] ) ? $item[ $key ] : '';
			$type = isset( $settings['type'] ) ? $settings['type'] : 'textarea';

			// Nested repeater rows (TBS sub-block) are sanitized against their
			// own item schema instead of being cast to a string.
			if ( 'array' === $type ) {
				$filtered[ $key ] = self::sanitize_array_field_items( is_array( $raw ) ? $raw : array(), $settings );
				continue;
			}

			$raw = is_scalar( $raw ) ? (string) $raw : '';
			$filtered[ $key ] = self::sanitize_field_by_type( $raw, $type );
		}

		return $filtered;
	}
}
