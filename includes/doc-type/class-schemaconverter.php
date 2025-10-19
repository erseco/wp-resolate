<?php
/**
 * Helpers to reshape schema definitions for UI consumption.
 *
 * @package Resolate
 */

namespace Resolate\DocType;

/**
 * Converts schema v2 structures into the flattened legacy layout expected by current UI code.
 */
class SchemaConverter {

	/**
	 * Convert schema v2 structure into the legacy flat array used by existing UI code.
	 *
	 * @param array $schema_v2 Versioned schema array.
	 * @return array<int,array>
	 */
	public static function to_legacy( $schema_v2 ) {
		if ( ! is_array( $schema_v2 ) ) {
			return array();
		}

		$output = array();

		$fields = isset( $schema_v2['fields'] ) && is_array( $schema_v2['fields'] ) ? $schema_v2['fields'] : array();
		foreach ( $fields as $field ) {
			$legacy = self::map_field( $field );
			if ( $legacy ) {
				$output[] = $legacy;
			}
		}

		$repeaters = isset( $schema_v2['repeaters'] ) && is_array( $schema_v2['repeaters'] ) ? $schema_v2['repeaters'] : array();
		foreach ( $repeaters as $repeater ) {
			$legacy = self::map_repeater( $repeater );
			if ( $legacy ) {
				$output[] = $legacy;
			}
		}

		return $output;
	}

	/**
	 * Map a single schema field to the legacy structure.
	 *
	 * @param array $field Field definition.
	 * @return array|null
	 */
	private static function map_field( $field ) {
		if ( ! is_array( $field ) ) {
			return null;
		}

		$slug = self::sanitize_slug( $field );
		if ( '' === $slug ) {
			return null;
		}

		$label       = self::resolve_label( $field, $slug );
		$placeholder = self::sanitize_placeholder( $field, $slug );
		$type        = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
		if ( '' === $type ) {
			$type = 'textarea';
		}

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'type'        => self::guess_scalar_control_type( $type, $slug, $label, $placeholder ),
			'placeholder' => $placeholder,
			'data_type'   => self::map_data_type( $type ),
		);
	}

	/**
	 * Map a repeater definition to the legacy structure.
	 *
	 * @param array $repeater Repeater definition.
	 * @return array|null
	 */
	private static function map_repeater( $repeater ) {
		if ( ! is_array( $repeater ) ) {
			return null;
		}

		$slug = self::sanitize_slug( $repeater );
		if ( '' === $slug ) {
			return null;
		}

		$label       = self::resolve_label( $repeater, $slug );
		$fields      = isset( $repeater['fields'] ) && is_array( $repeater['fields'] ) ? $repeater['fields'] : array();
		$item_schema = array();

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$item_slug = self::sanitize_slug( $field );
			if ( '' === $item_slug ) {
				continue;
			}

			$item_label = self::resolve_label( $field, $item_slug );
			$item_type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
			if ( '' === $item_type ) {
				$item_type = 'textarea';
			}

			$item_schema[ $item_slug ] = array(
				'label'     => $item_label,
				'type'      => self::guess_array_item_control_type( $item_type, $item_slug, $item_label ),
				'data_type' => self::map_data_type( $item_type ),
			);
		}

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'type'        => 'array',
			'placeholder' => $slug,
			'data_type'   => 'array',
			'item_schema' => $item_schema,
		);
	}

	/**
	 * Determine the legacy data type from v2 type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	private static function map_data_type( $type ) {
		switch ( strtolower( (string) $type ) ) {
			case 'number':
				return 'number';
			case 'date':
				return 'date';
			case 'boolean':
				return 'boolean';
			case 'email':
			case 'url':
			case 'text':
				return 'text';
			case 'html':
			case 'textarea':
				return 'text';
			default:
				return 'text';
		}
	}

	/**
	 * Guess the control type for a scalar field using heuristics similar to legacy parser.
	 *
	 * @param string $field_type Field type string.
	 * @param string $slug       Field slug.
	 * @param string $label      Field label.
	 * @param string $placeholder Placeholder text.
	 * @return string
	 */
	private static function guess_scalar_control_type( $field_type, $slug, $label, $placeholder ) {
		$field_type  = strtolower( (string) $field_type );
		$slug        = strtolower( (string) $slug );
		$label       = strtolower( (string) $label );
		$placeholder = strtolower( (string) $placeholder );
		$haystack    = trim( $slug . ' ' . $label . ' ' . $placeholder );

		if ( in_array( $field_type, array( 'number', 'date', 'boolean', 'email', 'url', 'text' ), true ) ) {
			return 'single';
		}

		if ( 'html' === $field_type ) {
			return 'rich';
		}

		if ( preg_match( '/\\b(title|titulo|título|heading|subject|asunto|name|nombre)\\b/u', $haystack ) ) {
			return 'single';
		}

		if ( preg_match( '/(content|contenido|texto|text|body|descripcion|descripción|detalle|summary|resumen)/u', $haystack ) ) {
			return 'rich';
		}

		return 'textarea';
	}

	/**
	 * Guess the control type for a repeater item.
	 *
	 * @param string $field_type Field type string.
	 * @param string $slug       Item slug.
	 * @param string $label      Item label.
	 * @return string
	 */
	private static function guess_array_item_control_type( $field_type, $slug, $label ) {
		$field_type = strtolower( (string) $field_type );
		$slug       = strtolower( (string) $slug );
		$label      = strtolower( (string) $label );

		if ( in_array( $field_type, array( 'number', 'date', 'boolean', 'email', 'url', 'text' ), true ) ) {
			return 'single';
		}

		if ( preg_match( '/^(number|numero|número|index|indice)$/', $slug ) ) {
			return 'single';
		}

		if ( preg_match( '/\\b(title|titulo|título|heading|name|nombre)\\b/u', $slug . ' ' . $label ) ) {
			return 'single';
		}

		if ( preg_match( '/(content|contenido|texto|text|body|descripcion|descripción)$/u', $slug . ' ' . $label ) ) {
			return 'rich';
		}

		if ( 'html' === $field_type ) {
			return 'rich';
		}

		return 'textarea';
	}

	/**
	 * Resolve a user friendly label.
	 *
	 * @param array  $record   Schema record.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function resolve_label( $record, $fallback ) {
		$candidates = array(
			isset( $record['title'] ) ? sanitize_text_field( $record['title'] ) : '',
			isset( $record['label'] ) ? sanitize_text_field( $record['label'] ) : '',
			isset( $record['name'] ) ? sanitize_text_field( $record['name'] ) : '',
		);

		foreach ( $candidates as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( false !== strpbrk( $candidate, '_-' ) ) {
				return self::humanize( $candidate );
			}
			return $candidate;
		}

		return self::humanize( $fallback );
	}

	/**
	 * Sanitize placeholder keeping supported characters.
	 *
	 * @param array  $record   Schema record.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function sanitize_placeholder( $record, $fallback ) {
		$placeholder = isset( $record['placeholder'] ) ? (string) $record['placeholder'] : '';
		if ( '' === $placeholder ) {
			return $fallback;
		}
		$placeholder = preg_replace( '/[^A-Za-z0-9._:-]/', '', $placeholder );
		return $placeholder ? $placeholder : $fallback;
	}

	/**
	 * Generate a sanitized slug from schema record.
	 *
	 * @param array $record Schema record.
	 * @return string
	 */
	private static function sanitize_slug( $record ) {
		$slug_sources = array(
			isset( $record['slug'] ) ? $record['slug'] : '',
			isset( $record['name'] ) ? $record['name'] : '',
		);

		foreach ( $slug_sources as $source ) {
			$slug = sanitize_key( $source );
			if ( '' !== $slug ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Convert slug into human readable label.
	 *
	 * @param string $slug Slug value.
	 * @return string
	 */
	private static function humanize( $slug ) {
		$slug = str_replace( array( '-', '_', '.' ), ' ', $slug );
		$slug = preg_replace( '/\\s+/', ' ', $slug );
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( strtolower( $slug ) );
	}
}
