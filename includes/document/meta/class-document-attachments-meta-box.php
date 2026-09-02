<?php
/**
 * Document attachments meta box.
 *
 * @package Documentate
 */

namespace Documentate\Document\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

use WP_Post;

/**
 * Registers and handles the attachments meta box for documentate_document posts.
 *
 * Allows users to upload, select, reorder, and remove file attachments
 * associated with a document entry via the WordPress Media Library.
 */
class Document_Attachments_Meta_Box {
	const META_KEY = '_documentate_attachments';
	const NONCE_ACTION = 'documentate_attachments_save';
	const NONCE_NAME = 'documentate_attachments_nonce';

	/**
	 * Register hooks for the meta box.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes_documentate_document', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_documentate_document', array( $this, 'save' ), 10, 3 );
	}

	/**
	 * Register the meta box on the document edit screen.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function register_meta_box( $post ) {
		unset( $post );

		add_meta_box(
			'documentate_document_attachments',
			'Adjuntos',
			array( $this, 'render' ),
			'documentate_document',
			'normal',
			'default',
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render( WP_Post $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$attachment_ids = self::get_attachment_ids( $post->ID );

		echo '<div id="documentate-attachments-wrapper">';
		echo '<ul id="documentate-attachments-list" class="documentate-attachments-list">';

		foreach ( $attachment_ids as $attachment_id ) {
			$this->render_attachment_item( $attachment_id );
		}

		echo '</ul>';
		echo '<input type="hidden" id="documentate-attachments-field" name="documentate_attachments" value="'
				. esc_attr( implode( ',', $attachment_ids ) )
				. '" />';
		echo '<p>';
		echo '<button type="button" class="button" id="documentate-attachments-add">'
				. esc_html( 'Añadir archivos' )
				. '</button>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Render a single attachment item in the list.
	 *
	 * @param int $attachment_id The attachment post ID.
	 * @return void
	 */
	private function render_attachment_item( $attachment_id ) {
		$filename = basename( get_attached_file( $attachment_id ) );
		$mime_type = get_post_mime_type( $attachment_id );
		$icon_url = wp_mime_type_icon( $mime_type );
		$url = wp_get_attachment_url( $attachment_id );

		echo '<li class="documentate-attachment-item" data-id="' . esc_attr( $attachment_id ) . '">';
		echo '<span class="documentate-attachment-handle dashicons dashicons-menu"></span>';
		echo '<img class="documentate-attachment-icon" src="' . esc_url( $icon_url ) . '" alt="" />';
		echo '<a class="documentate-attachment-filename" href="'
				. esc_url( $url )
				. '" target="_blank">'
				. esc_html( $filename )
				. '</a>';
		echo '<button type="button" class="button-link documentate-attachment-remove" title="'
				. esc_attr( 'Eliminar' )
				. '">';
		echo '<span class="dashicons dashicons-no-alt"></span>';
		echo '</button>';
		echo '</li>';
	}

	/**
	 * Handle meta box saves.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 * @param bool         $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function save( $post_id, $post = null, $update = false ) {
		unset( $update, $post );

		if ( ! $this->should_save_attachments( $post_id ) ) {
			return;
		}

		$ids = self::sanitize_ids( $this->read_attachments_post_value() );
		if ( empty( $ids ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		update_post_meta( $post_id, self::META_KEY, $ids );
	}

	/**
	 * Whether the current request may persist attachment meta for this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function should_save_attachments( $post_id ) {
		if ( ! $this->verify_attachments_nonce() ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// Respect workflow locks (published/pending/archived for non-admins).
		if (
			class_exists( 'Documentate_Workflow' )
			&& ! \Documentate_Workflow::current_user_can_modify_document( $post_id )
		) {
			return false;
		}

		$post = get_post( $post_id );
		return $post instanceof WP_Post;
	}

	/**
	 * Verify the attachments meta box nonce from the request.
	 *
	 * @return bool
	 */
	private function verify_attachments_nonce() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Read the raw attachments field from the request.
	 *
	 * @return string Comma-separated attachment IDs, or empty string.
	 */
	private function read_attachments_post_value() {
		// Nonce verified in should_save_attachments() / verify_attachments_nonce().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['documentate_attachments'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( $_POST['documentate_attachments'] ) );
	}

	/**
	 * Retrieve attachment IDs stored for a document.
	 *
	 * @param int $post_id The document post ID.
	 * @return int[] Array of attachment IDs.
	 */
	public static function get_attachment_ids( $post_id ) {
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $stored ) ) );
	}

	/**
	 * Sanitize a comma-separated string of attachment IDs.
	 *
	 * @param string $raw Raw comma-separated ID string.
	 * @return int[] Array of positive integer IDs.
	 */
	public static function sanitize_ids( $raw ) {
		if ( '' === $raw ) {
			return array();
		}

		$parts = explode( ',', $raw );
		$ids = array();

		foreach ( $parts as $part ) {
			$trimmed = trim( $part );
			if ( ! is_numeric( $trimmed ) || (int) $trimmed <= 0 ) {
				continue;
			}
			$ids[] = absint( $trimmed );
		}

		return $ids;
	}
}
