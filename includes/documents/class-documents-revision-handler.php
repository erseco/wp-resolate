<?php
/**
 * Revision handling for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Handles revision management for document custom post type.
 */
class Documents_Revision_Handler {
	/**
	 * Register hooks for revision handling.
	 */
	public function register_hooks() {
		add_action( 'wp_save_post_revision', array( $this, 'copy_meta_to_revision' ), 10, 2 );
		add_action( 'wp_restore_post_revision', array( $this, 'restore_meta_from_revision' ), 10, 2 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions_for_cpt' ), 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'force_revision_on_meta' ), 10, 3 );
		add_filter( '_wp_post_revision_fields', array( $this, 'add_revision_fields' ), 10, 2 );
	}

	/**
	 * Copy custom meta to the newly created revision.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Revision post ID.
	 * @return void
	 */
	public function copy_meta_to_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'documentate_document' !== $parent->post_type ) {
			return;
		}

		$keys = Documents_Meta_Handler::get_meta_fields_for_post( $post_id );
		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( is_array( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $unused ) {
					unset( $unused );
					if ( is_string( $meta_key ) && str_starts_with( $meta_key, 'documentate_field_' ) ) {
						$keys[] = $meta_key;
					}
				}
			}
		}
		$keys = array_values( array_unique( $keys ) );

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			}
			delete_metadata( 'post', $revision_id, $key );
			add_metadata( 'post', $revision_id, $key, $value, true );
		}

		wp_cache_delete( $revision_id, 'post_meta' );
	}

	/**
	 * Restore custom meta when a revision is restored.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 * @return void
	 */
	public function restore_meta_from_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'documentate_document' !== $parent->post_type ) {
			return;
		}

		foreach ( Documents_Meta_Handler::get_meta_fields_for_post( $post_id ) as $key ) {
			$value = get_metadata( 'post', $revision_id, $key, true );
			if ( null !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Limit number of revisions for this CPT.
	 *
	 * @param int      $num  Default number of revisions.
	 * @param \WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions_for_cpt( $num, $post ) {
		if ( $post && 'documentate_document' === $post->post_type ) {
			return 15;
		}
		return $num;
	}

	/**
	 * Force creating a revision on save even if core fields don't change.
	 *
	 * @param bool     $post_has_changed Default change detection.
	 * @param \WP_Post $last_revision    Last revision object.
	 * @param \WP_Post $post             Current post object.
	 * @return bool
	 */
	public function force_revision_on_meta( $post_has_changed, $last_revision, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $post && 'documentate_document' === $post->post_type ) {
			return true;
		}
		return $post_has_changed;
	}

	/**
	 * Add custom meta fields to the revisions UI.
	 *
	 * @param array    $fields Existing fields.
	 * @param \WP_Post $post   Post being compared.
	 * @return array
	 */
	public function add_revision_fields( $fields, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return $fields;
	}

	/**
	 * Generic provider for WYSIWYG meta fields in revisions diff.
	 *
	 * @param string   $value    Current value (unused).
	 * @param \WP_Post $revision Revision post object.
	 * @return string
	 */
	public function revision_field_value( $value, $revision = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$field = str_replace( '_wp_post_revision_field_', '', current_filter() );
		$rev_id = 0;
		$args = func_get_args();
		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && isset( $arg->ID ) ) {
				$rev_id = intval( $arg->ID );
				break;
			}
			if ( is_array( $arg ) && isset( $arg['ID'] ) && is_numeric( $arg['ID'] ) ) {
				$maybe = get_post( intval( $arg['ID'] ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
			if ( is_numeric( $arg ) ) {
				$maybe = get_post( intval( $arg ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
		}
		if ( $rev_id <= 0 ) {
			return '';
		}
		$raw = get_metadata( 'post', $rev_id, $field, true );
		return self::readable_text( $raw );
	}

	/**
	 * The text a person reads in a field value, for wp_text_diff().
	 *
	 * Block tags become line breaks, the rest of the markup goes and the
	 * entities are decoded, so a comparison shows the words that changed and
	 * never a `<b>`.
	 *
	 * @param string $html HTML input.
	 * @return string
	 */
	public static function readable_text( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}
		// ponytail: text-only diff, a change of formatting alone is invisible; diff the HTML per line if it must show.
		$text = preg_replace( '/<(?:p|div|br|li|tr|h[1-6])[^>]*>/i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
