<?php
/**
 * Document metadata meta box.
 *
 * @package Resolate
 */

namespace Resolate\Document\Meta;

use WP_Post;

/**
 * Registers and handles the document metadata meta box for resolate_document posts.
 */
class Document_Meta_Box {

	const META_KEY_SUBJECT  = '_resolate_meta_subject';
	const META_KEY_AUTHOR   = '_resolate_meta_author';
	const META_KEY_KEYWORDS = '_resolate_meta_keywords';
	const NONCE_ACTION      = 'resolate_document_meta_save';
	const NONCE_NAME        = 'resolate_document_meta_nonce';

	/**
	 * Register hooks for the meta box.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes_resolate_document', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_resolate_document', array( $this, 'save' ), 10, 3 );
	}

	/**
	 * Register the meta box for the current screen.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function register_meta_box( $post ) {
		unset( $post );

		add_meta_box(
			'resolate_document_meta',
			__( 'Metadatos del documento', 'resolate' ),
			array( $this, 'render' ),
			'resolate_document',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box fields.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render( WP_Post $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$title    = get_the_title( $post->ID );
		$subject  = get_post_meta( $post->ID, self::META_KEY_SUBJECT, true );
		$author   = get_post_meta( $post->ID, self::META_KEY_AUTHOR, true );
		$keywords = get_post_meta( $post->ID, self::META_KEY_KEYWORDS, true );

		echo '<p><label for="resolate_document_meta_title"><strong>' . esc_html__( 'Titulo', 'resolate' ) . '</strong></label></p>';
		echo '<p><input type="text" id="resolate_document_meta_title" class="regular-text" value="' . esc_attr( $title ) . '" disabled="disabled" readonly="readonly" /></p>';
		echo '<p class="description">' . esc_html__( 'El titulo procede del titulo de la entrada.', 'resolate' ) . '</p>';

		echo '<p><label for="resolate_document_meta_subject">' . esc_html__( 'Asunto', 'resolate' ) . '</label></p>';
		echo '<p><input type="text" id="resolate_document_meta_subject" name="resolate_document_meta_subject" class="regular-text" maxlength="255" value="' . esc_attr( $subject ) . '" /></p>';

		echo '<p><label for="resolate_document_meta_author">' . esc_html__( 'Autoria', 'resolate' ) . '</label></p>';
		echo '<p><input type="text" id="resolate_document_meta_author" name="resolate_document_meta_author" class="regular-text" maxlength="255" value="' . esc_attr( $author ) . '" /></p>';

		echo '<p><label for="resolate_document_meta_keywords">' . esc_html__( 'Palabras clave', 'resolate' ) . '</label></p>';
		echo '<p><input type="text" id="resolate_document_meta_keywords" name="resolate_document_meta_keywords" class="regular-text" maxlength="512" placeholder="' . esc_attr__( 'palabra1, palabra2', 'resolate' ) . '" value="' . esc_attr( $keywords ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Lista separada por comas.', 'resolate' ) . '</p>';
	}

	/**
	 * Handle meta box saves.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public function save( $post_id, $post = null, $update = false ) {
		unset( $post, $update );

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$subject  = isset( $_POST['resolate_document_meta_subject'] ) ? $this->sanitize_limited_text( wp_unslash( $_POST['resolate_document_meta_subject'] ), 255 ) : '';
		$author   = isset( $_POST['resolate_document_meta_author'] ) ? $this->sanitize_limited_text( wp_unslash( $_POST['resolate_document_meta_author'] ), 255 ) : '';
		$keywords = isset( $_POST['resolate_document_meta_keywords'] ) ? $this->sanitize_keywords( wp_unslash( $_POST['resolate_document_meta_keywords'] ) ) : '';

		$this->persist_meta( $post_id, self::META_KEY_SUBJECT, $subject );
		$this->persist_meta( $post_id, self::META_KEY_AUTHOR, $author );
		$this->persist_meta( $post_id, self::META_KEY_KEYWORDS, $keywords );
	}

	/**
	 * Persist a meta value or delete when empty.
	 *
	 * @param int    $post_id Document post ID.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value.
	 * @return void
	 */
	private function persist_meta( $post_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Sanitize plain text values enforcing maximum length.
	 *
	 * @param string $value      Raw value.
	 * @param int    $max_length Max length.
	 * @return string
	 */
	private function sanitize_limited_text( $value, $max_length ) {
		$value = is_string( $value ) ? $value : '';
		$value = sanitize_text_field( $value );
		$value = $this->strip_control_chars( $value );

		return $this->truncate( $value, $max_length );
	}

	/**
	 * Sanitize the keywords string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_keywords( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = sanitize_text_field( $value );
		$value = $this->strip_control_chars( $value );

		$parts = array_map( 'trim', explode( ',', $value ) );
		$clean = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$part    = $this->truncate( $part, 255 );
			$clean[] = $part;
		}

		if ( empty( $clean ) ) {
			return '';
		}

		$keywords = implode( ', ', $clean );

		return $this->truncate( $keywords, 512 );
	}

	/**
	 * Remove control characters from a string.
	 *
	 * @param string $value String value.
	 * @return string
	 */
	private function strip_control_chars( $value ) {
		$sanitized = preg_replace( '/[\x00-\x1F\x7F]/u', '', $value );
		if ( null === $sanitized ) {
			return $value;
		}

		return $sanitized;
	}

	/**
	 * Truncate a string by characters.
	 *
	 * @param string $value String value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private function truncate( $value, $max ) {
		if ( $max <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $value, 'UTF-8' ) > $max ) {
				return mb_substr( $value, 0, $max, 'UTF-8' );
			}

			return $value;
		}

		if ( strlen( $value ) > $max ) {
			return substr( $value, 0, $max );
		}

		return $value;
	}
}
