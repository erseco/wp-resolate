<?php
/**
 * The "PDF layout" field of the document type screens.
 *
 * A document type points at the HTML layout its PDF is drawn from through the
 * `documentate_type_pdf_layout` term meta. This class is the whole of that
 * field: it renders the picker on both taxonomy forms, decides which option
 * the picker opens on, and turns what the browser submits into a slug that is
 * safe to store.
 *
 * @package documentate
 * @subpackage Documentate/admin
 */

defined( 'ABSPATH' ) || exit();

/**
 * Renders the layout picker and validates what it submits.
 */
class Documentate_Doc_Type_Pdf_Layout_Field {

	/**
	 * Render the layout select and its description.
	 *
	 * Both taxonomy forms show the same control, so they share it rather than
	 * each carrying its own copy of the option list.
	 *
	 * @param string $selected Layout slug the select opens on.
	 * @return void
	 */
	public function render( $selected ) {
		?>
		<select id="<?php echo esc_attr( Documentate_Pdf_Layout::META_KEY ); ?>" name="<?php echo esc_attr( Documentate_Pdf_Layout::META_KEY ); ?>">
			<?php foreach ( Documentate_Pdf_Layout::available() as $slug => $title ) : ?>
				<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $slug, $selected ); ?>><?php echo esc_html( $title ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Layout used to render the PDF. "Generic" lists every field.', 'documentate' ); ?></p>
		<?php
	}

	/**
	 * Layout the edit form of a document type opens on.
	 *
	 * A type that names no layout, and one naming a layout that is no longer
	 * shipped, both open on the generic layout — which is the one
	 * Documentate_Pdf_Layout::for_post() falls back to anyway, so the picker
	 * shows what the document would really be drawn with.
	 *
	 * @param int $term_id Document type term ID.
	 * @return string Layout slug that is certainly among the shipped ones.
	 */
	public function stored( $term_id ) {
		return $this->known(
			get_term_meta( (int) $term_id, Documentate_Pdf_Layout::META_KEY, true ),
			Documentate_Pdf_Layout::DEFAULT_SLUG
		);
	}

	/**
	 * Layout slug the taxonomy form submitted, once it can be trusted.
	 *
	 * A form that carries no layout at all clears the stored one, which is how
	 * the rest of the screen already treats a field it was not sent.
	 *
	 * The caller must have verified the taxonomy nonce before asking.
	 *
	 * @return string Layout slug, or an empty string when none was chosen.
	 */
	public function submitted() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller, Documentate_Doc_Types_Admin::save_term().
		$submitted = isset( $_POST[ Documentate_Pdf_Layout::META_KEY ] )
			? wp_unslash( $_POST[ Documentate_Pdf_Layout::META_KEY ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised by known().
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->known( $submitted, '' );
	}

	/**
	 * Reduce an untrusted layout name to one that is certainly shipped.
	 *
	 * The name reaches this class from a browser field or from a term meta row
	 * that anyone with database access could have written, so it is first cut
	 * down to the characters a key may have — which leaves nothing a path
	 * could be built out of, no separator, no dot, no null byte — and then has
	 * to name one of the layouts actually shipped under `templates/pdf/`.
	 *
	 * Storing the empty fallback rather than a name that is not there is what
	 * makes Documentate_Pdf_Layout::for_post() fall back to the generic layout
	 * instead of pointing the renderer at a missing file.
	 *
	 * @param mixed  $name     Layout name as submitted or as stored.
	 * @param string $fallback Value used when the name does not belong to a shipped layout.
	 * @return string
	 */
	private function known( $name, $fallback ) {
		$slug = is_string( $name ) ? sanitize_key( $name ) : '';

		return array_key_exists( $slug, Documentate_Pdf_Layout::available() ) ? $slug : $fallback;
	}
}
