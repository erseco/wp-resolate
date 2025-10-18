<?php
/**
 * Schema storage helpers for document types.
 *
 * @package Resolate
 */

namespace Resolate\DocType;

/**
 * Handles persistence of schema definitions in term meta.
 */
class SchemaStorage {

	const META_KEY          = '_resolate_schema_v2';
	const META_SUMMARY_KEY  = '_resolate_schema_v2_summary';
	const META_HASH_KEY     = '_resolate_schema_v2_hash';
	const META_UPDATED_KEY  = '_resolate_schema_v2_updated';

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
		$fields      = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();
		$repeaters   = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
		$version     = isset( $schema['version'] ) ? intval( $schema['version'] ) : 0;
		$template    = isset( $schema['meta']['template_name'] ) ? (string) $schema['meta']['template_name'] : '';
		$parsed_at   = isset( $schema['meta']['parsed_at'] ) ? (string) $schema['meta']['parsed_at'] : '';
		$template_id = isset( $schema['meta']['template_id'] ) ? intval( $schema['meta']['template_id'] ) : 0;
		$type        = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : '';

		$repeater_names = array();
		foreach ( $repeaters as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$name = isset( $definition['name'] ) ? (string) $definition['name'] : '';
			if ( '' !== $name ) {
				$repeater_names[] = $name;
			}
		}

		return array(
			'version'       => $version,
			'field_count'   => count( $fields ),
			'repeater_count'=> count( $repeaters ),
			'repeaters'     => $repeater_names,
			'template_name' => $template,
			'template_type' => $type,
			'template_id'   => $template_id,
			'parsed_at'     => $parsed_at,
		);
	}
}
