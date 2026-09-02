<?php
/**
 * Document metadata meta box.
 *
 * @package Documentate
 */

namespace Documentate\Document\Meta;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

use WP_Post;

/**
 * Registers and handles the document metadata meta box for documentate_document posts.
 */
class Document_Meta_Box {
	const META_KEY_SUBJECT = '_documentate_meta_subject';
	const META_KEY_AUTHOR = '_documentate_meta_author';
	const META_KEY_KEYWORDS = '_documentate_meta_keywords';
	const NONCE_ACTION = 'documentate_document_meta_save';
	const NONCE_NAME = 'documentate_document_meta_nonce';

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
	 * Register the meta box for the current screen.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function register_meta_box( $post ) {
		unset( $post );

		add_meta_box(
			'documentate_document_meta',
			'Metadatos del documento',
			array( $this, 'render' ),
			'documentate_document',
			'side',
			'default',
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

		$title = get_post_field( 'post_title', $post->ID, 'raw' );
		if ( ! is_string( $title ) || '' === $title ) {
			$title = $post->post_title;
		}

		$author = get_post_meta( $post->ID, self::META_KEY_AUTHOR, true );
		$keywords = get_post_meta( $post->ID, self::META_KEY_KEYWORDS, true );

		echo '<p><strong>' . esc_html( 'Título' ) . '</strong></p>';
		echo '<p class="description">' . esc_html( $title ) . '</p>';
		echo '<p><strong>' . esc_html( 'Asunto' ) . '</strong></p>';
		echo '<p class="description">' . esc_html( 'El asunto se obtiene del título del artículo.' ) . '</p>';

		echo '<p><label for="documentate_document_meta_author">' . esc_html( 'Autor' ) . '</label></p>';
		echo '<p><input type="text" id="documentate_document_meta_author" name="documentate_document_meta_author" class="widefat" maxlength="255" value="'
				. esc_attr( $author )
				. '" /></p>';

		echo '<p><label for="documentate_document_meta_keywords">' . esc_html( 'Palabras clave' ) . '</label></p>';
		echo '<p><input type="text" id="documentate_document_meta_keywords" name="documentate_document_meta_keywords" class="widefat" maxlength="512" placeholder="'
				. esc_attr( 'palabra1, palabra2' )
				. '" value="'
				. esc_attr( $keywords )
				. '" /></p>';
		echo '<p class="description">' . esc_html( 'Lista separada por comas.' ) . '</p>';
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
		unset( $update );

		if ( ! $this->should_save( $post_id ) ) {
			return;
		}

		$post = $this->resolve_post( $post_id, $post );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->persist_meta( $post_id, self::META_KEY_SUBJECT, $this->read_subject( $post_id, $post ) );
		$this->persist_meta( $post_id, self::META_KEY_AUTHOR, $this->read_author() );
		$this->persist_meta( $post_id, self::META_KEY_KEYWORDS, $this->read_keywords() );
	}

	/**
	 * Whether this request is an authorised metadata save.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function should_save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
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

		if (
			class_exists( 'Documentate_Workflow' )
			&& ! \Documentate_Workflow::current_user_can_modify_document( $post_id )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the post being saved.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object when the caller supplied one.
	 * @return WP_Post|null
	 */
	private function resolve_post( $post_id, $post ) {
		if ( null === $post ) {
			$post = get_post( $post_id );
		}

		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Read the document subject, which mirrors the post title.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being saved.
	 * @return string
	 */
	private function read_subject( $post_id, WP_Post $post ) {
		$title_source = get_post_field( 'post_title', $post_id, 'raw' );
		if ( ! is_string( $title_source ) || '' === $title_source ) {
			$title_source = $post->post_title;
		}

		return $this->sanitize_limited_text( sanitize_text_field( (string) $title_source ), 255 );
	}

	/**
	 * Read the submitted document author.
	 *
	 * Nonce and capability are verified in should_save().
	 *
	 * @return string
	 */
	private function read_author() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save().
		$author_input = isset( $_POST['documentate_document_meta_author'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save().
			? sanitize_text_field( wp_unslash( $_POST['documentate_document_meta_author'] ) )
			: '';

		return $this->sanitize_limited_text( $author_input, 255 );
	}

	/**
	 * Read the submitted document keywords.
	 *
	 * Nonce and capability are verified in should_save().
	 *
	 * @return string
	 */
	private function read_keywords() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save().
		$keywords_raw = isset( $_POST['documentate_document_meta_keywords'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in should_save().
			? sanitize_text_field( wp_unslash( $_POST['documentate_document_meta_keywords'] ) )
			: '';

		return $this->sanitize_keywords( $keywords_raw );
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

		$value = $this->strip_control_chars( $value );

		$parts = array_map( 'trim', explode( ',', $value ) );
		$clean = array();

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$part = $this->truncate( $part, 255 );
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
