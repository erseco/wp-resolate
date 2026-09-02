<?php
/**
 * Persistence for Documentate document fields.
 *
 * Extracted from Documentate_Documents. Reading a posted editor into meta and
 * composing the post_content that revisions diff against are the same job, and
 * they share nothing with drawing the editor beyond Documents_Meta_Handler.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Writes submitted document fields to meta and composes post_content.
 */
class Documentate_Document_Meta_Saver {
	/**
	 * Save meta box values.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_boxes( $post_id ) {
		if ( ! $this->can_save_meta_boxes( $post_id ) ) {
			return;
		}

		// Handle type selection (lock after set).
		$this->save_doc_type_selection( $post_id );

		$this->save_nombre_interno( $post_id );
		$this->save_anotaciones( $post_id );
		$this->save_dynamic_fields_meta( $post_id );

		// post_content is composed in wp_insert_post_data filter; avoid recursion here.
	}
	/**
	 * Whether this request is allowed to persist the sections metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_save_meta_boxes( $post_id ) {
		if (
			! isset( $_POST['documentate_sections_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['documentate_sections_nonce'] ) ),
				'documentate_sections_nonce',
			)
		) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// Server-side lock: non-admins must not persist field meta on locked docs.
		if (
			class_exists( 'Documentate_Workflow' )
			&& ! Documentate_Workflow::current_user_can_modify_document( $post_id )
		) {
			return false;
		}

		return true;
	}
	/**
	 * Get dynamic fields schema for the selected document type of a post.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of field definitions with keys: slug, label, type.
	 */
	private function get_dynamic_fields_schema_for_post( $post_id ) {
		return Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post_id );
	}
	/**
	 * Unslashed copy of the submitted request body.
	 *
	 * @return array
	 */
	private function read_posted_values() {
		if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in can_save_meta_boxes(); every value is sanitized by type before it is stored.
		return wp_unslash( $_POST );
	}
	/**
	 * Apply the posted document type, keeping an existing assignment locked.
	 *
	 * Once a document has a type, it wins over anything posted: the type is
	 * reapplied rather than replaced.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_doc_type_selection( $post_id ) {
		if (
			! isset( $_POST['documentate_type_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['documentate_type_nonce'] ) ), 'documentate_type_nonce' )
		) {
			return;
		}

		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$current = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;

		if ( $current > 0 ) {
			wp_set_post_terms( $post_id, array( $current ), 'documentate_doc_type', false );
			return;
		}

		$posted = isset( $_POST['documentate_doc_type'] ) ? intval( wp_unslash( $_POST['documentate_doc_type'] ) ) : 0;
		if ( $posted > 0 ) {
			wp_set_post_terms( $post_id, array( $posted ), 'documentate_doc_type', false );
		}
	}
	/**
	 * Persist the "Nombre interno" input rendered under the title.
	 *
	 * Reachable from can_save_meta_boxes() only, so a locked document (the
	 * role-aware lock inside that gate) never has it overwritten either.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_nombre_interno( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in can_save_meta_boxes().
		if ( ! isset( $_POST['documentate_nombre_interno'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in can_save_meta_boxes(); sanitized inside guardar_nombre_interno().
		Documentate_Documento::guardar_nombre_interno( $post_id, wp_unslash( $_POST['documentate_nombre_interno'] ) );
	}
	/**
	 * Persist the "Anotaciones internas" textarea (gestión / administración only).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_anotaciones( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in can_save_meta_boxes().
		if ( ! isset( $_POST['documentate_anotaciones'] ) || ! Documentate_Roles::es_gestion() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in can_save_meta_boxes(); sanitized inside guardar_anotaciones().
		Documentate_Documento::guardar_anotaciones( $post_id, wp_unslash( $_POST['documentate_anotaciones'] ) );
	}
	/**
	 * Persist dynamic field values posted from the sections metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_dynamic_fields_meta( $post_id ) {
		$post_values = $this->read_posted_values();

		$posted_array_fields = isset( $post_values['tpl_fields'] ) && is_array( $post_values['tpl_fields'] )
			? $post_values['tpl_fields']
			: array();

		$known_meta_keys = array();

		// Persist fields defined by the current schema (when available).
		foreach ( (array) $this->get_dynamic_fields_schema_for_post( $post_id ) as $definition ) {
			$slug = ! empty( $definition['slug'] ) ? sanitize_key( $definition['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$meta_key = 'documentate_field_' . $slug;
			$known_meta_keys[ $meta_key ] = true;

			$this->save_schema_field( $post_id, $definition, $slug, $meta_key, $post_values, $posted_array_fields );
		}

		// Persist unknown dynamic fields posted that are not part of the schema
		// (or when no schema is currently available for the post's type).
		$this->save_unknown_field_meta( $post_id, $post_values, $known_meta_keys );
	}
	/**
	 * Persist one field declared by the document type schema.
	 *
	 * A field the current user cannot see (rol = gestion for an área user) is
	 * treated as not posted, so the stored value survives whatever the request
	 * carries for it.
	 *
	 * @param int    $post_id             Post ID.
	 * @param array  $definition          Schema field definition.
	 * @param string $slug                Sanitized field slug.
	 * @param string $meta_key            Meta key the value is stored under.
	 * @param array  $post_values         Unslashed request body.
	 * @param array  $posted_array_fields Submitted repeater rows, keyed by slug.
	 * @return void
	 */
	private function save_schema_field( $post_id, $definition, $slug, $meta_key, array $post_values, array $posted_array_fields ) {
		if ( ! Documentate_Campos_Rol::puede_ver( (array) $definition ) ) {
			return;
		}

		$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';

		if ( 'array' === $type ) {
			// Absent from the request means "not submitted", which must not
			// clear rows the document already has.
			if ( isset( $posted_array_fields[ $slug ] ) ) {
				// The stored rows are handed over so the columns gestión
				// documental owns survive a save by the área.
				$stored = Documents_Meta_Handler::decode_array_field_value( get_post_meta( $post_id, $meta_key, true ) );
				$items = Documentate_Document_Content_Writer::sanitize_array_field_items( $posted_array_fields[ $slug ], $definition, $stored );
				$this->write_or_delete_meta( $post_id, $meta_key, Documentate_Document_Content_Writer::encode_array_field_items( $items ) );
			}
			return;
		}

		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		if ( ! array_key_exists( $meta_key, $post_values ) ) {
			return;
		}

		$this->write_or_delete_meta(
			$post_id,
			$meta_key,
			Documentate_Document_Content_Writer::sanitize_field_by_type( $post_values[ $meta_key ], $type )
		);
	}
	/**
	 * Persist posted field values the current schema does not declare.
	 *
	 * Keeps values written by a previous document type, or by any type when the
	 * schema cannot be resolved.
	 *
	 * @param int   $post_id         Post ID.
	 * @param array $post_values     Unslashed request body.
	 * @param array $known_meta_keys Meta keys already handled by the schema pass.
	 * @return void
	 */
	private function save_unknown_field_meta( $post_id, array $post_values, array $known_meta_keys ) {
		foreach ( $post_values as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'documentate_field_' ) ) {
				continue;
			}
			if ( isset( $known_meta_keys[ $key ] ) || is_array( $value ) ) {
				continue;
			}

			$raw_value = wp_unslash( $value );
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

			$this->write_or_delete_meta( $post_id, $key, Documentate_Document_Content_Writer::sanitize_rich_text_value( $raw_value ) );
		}
	}
	/**
	 * Store a meta value, removing the key when the value is empty.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value.
	 * @return void
	 */
	private function write_or_delete_meta( $post_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}
}
