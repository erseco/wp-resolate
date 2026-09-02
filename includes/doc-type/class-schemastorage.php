<?php
/**
 * Schema storage helpers for document types.
 *
 * @package Documentate
 */

namespace Documentate\DocType;

/**
 * Handles persistence of schema definitions in term meta.
 */
class SchemaStorage {
	const META_KEY = '_documentate_schema_v2';
	const META_SUMMARY_KEY = '_documentate_schema_v2_summary';
	const META_HASH_KEY = '_documentate_schema_v2_hash';
	const META_UPDATED_KEY = '_documentate_schema_v2_updated';

	/**
	 * Retrieve stored schema array for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>
	 */
	public function get_schema( $term_id ) {
		$schema = get_term_meta( $term_id, self::META_KEY, true );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Persist schema definition and summary metadata.
	 *
	 * @param int   $term_id Term ID.
	 * @param array $schema  Schema data produced by SchemaExtractor.
	 * @return void
	 */
	public function save_schema( $term_id, $schema ) {
		if ( ! is_array( $schema ) ) {
			return;
		}

		update_term_meta( $term_id, self::META_KEY, $schema );

		$summary = $this->build_summary( $schema );
		update_term_meta( $term_id, self::META_SUMMARY_KEY, $summary );

		$hash = isset( $schema['meta']['hash'] ) ? (string) $schema['meta']['hash'] : '';
		update_term_meta( $term_id, self::META_HASH_KEY, $hash );

		update_term_meta( $term_id, self::META_UPDATED_KEY, current_time( 'mysql' ) );

		self::forget_derived_answers( $term_id );
	}

	/**
	 * Delete stored schema for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function delete_schema( $term_id ) {
		delete_term_meta( $term_id, self::META_KEY );
		delete_term_meta( $term_id, self::META_SUMMARY_KEY );
		delete_term_meta( $term_id, self::META_HASH_KEY );
		delete_term_meta( $term_id, self::META_UPDATED_KEY );

		self::forget_derived_answers( $term_id );
	}

	/**
	 * Drop what other classes memoised about this schema.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	private static function forget_derived_answers( $term_id ) {
		if ( class_exists( '\\Documentate_Campos_Rol' ) ) {
			\Documentate_Campos_Rol::olvidar_tipo( (int) $term_id );
		}
	}

	/**
	 * Retrieve schema summary metadata.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>
	 */
	public function get_summary( $term_id ) {
		$summary = get_term_meta( $term_id, self::META_SUMMARY_KEY, true );
		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Generate a summary array without persisting it.
	 *
	 * @param array $schema Schema array.
	 * @return array<string,mixed>
	 */
	public function summarize_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return array();
		}
		return $this->build_summary( $schema );
	}

	/**
	 * Retrieve stored hash for idempotency checks.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	public function get_hash( $term_id ) {
		$hash = get_term_meta( $term_id, self::META_HASH_KEY, true );
		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Build a lightweight summary for display and quick checks.
	 *
	 * @param array $schema Schema array.
	 * @return array<string,mixed>
	 */
	private function build_summary( $schema ) {
		$fields = $this->array_member( $schema, 'fields' );
		$repeaters = $this->array_member( $schema, 'repeaters' );
		$meta = $this->array_member( $schema, 'meta' );

		return array(
			'version' => $this->int_member( $schema, 'version' ),
			'field_count' => count( $fields ),
			'repeater_count' => count( $repeaters ),
			'repeaters' => $this->collect_repeater_names( $repeaters ),
			'template_name' => $this->string_member( $meta, 'template_name' ),
			'template_type' => $this->string_member( $meta, 'template_type' ),
			'template_id' => $this->int_member( $meta, 'template_id' ),
			'parsed_at' => $this->string_member( $meta, 'parsed_at' ),
		);
	}

	/**
	 * Read an array member, defaulting to an empty array.
	 *
	 * @param mixed  $source Source array.
	 * @param string $key    Member key.
	 * @return array
	 */
	private function array_member( $source, $key ) {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
	}

	/**
	 * Read a string member, defaulting to an empty string.
	 *
	 * @param mixed  $source Source array.
	 * @param string $key    Member key.
	 * @return string
	 */
	private function string_member( $source, $key ) {
		return isset( $source[ $key ] ) ? (string) $source[ $key ] : '';
	}

	/**
	 * Read an integer member, defaulting to zero.
	 *
	 * @param mixed  $source Source array.
	 * @param string $key    Member key.
	 * @return int
	 */
	private function int_member( $source, $key ) {
		return isset( $source[ $key ] ) ? intval( $source[ $key ] ) : 0;
	}

	/**
	 * Collect the names declared by repeater definitions.
	 *
	 * @param array $repeaters Repeater definitions.
	 * @return string[]
	 */
	private function collect_repeater_names( array $repeaters ) {
		$names = array();

		foreach ( $repeaters as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
