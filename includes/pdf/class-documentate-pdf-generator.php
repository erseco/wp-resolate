<?php
/**
 * Turns a document into a PDF file, without leaving the server.
 *
 * This is where the pieces of the native renderer meet: the layout the
 * document type names, the TinyButStrong merge that fills it with the
 * document's own field values, and the writer that draws the merged HTML onto
 * an FPDF page carrying the institutional furniture.
 *
 * @package Documentate
 */

use Documentate\Document\Meta\Document_Meta;

defined( 'ABSPATH' ) || exit();

/**
 * Renders a document post as a PDF on disk.
 */
class Documentate_Pdf_Generator {

	/**
	 * Metadata key of Document_Meta::get() => FPDF setter that writes it.
	 *
	 * @var array<string,string>
	 */
	private const METADATA_SETTERS = array(
		'title'    => 'SetTitle',
		'subject'  => 'SetSubject',
		'author'   => 'SetAuthor',
		'keywords' => 'SetKeywords',
	);

	/**
	 * Render a document as a PDF and leave it in the output directory.
	 *
	 * Nothing thrown here reaches the caller. The admin screens, the export
	 * handlers and the AJAX endpoint all read the result as a path or a
	 * WP_Error, and a throwable escaping instead would end a download or a
	 * JSON response as a fatal error page.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path of the written file, or the reason
	 *                         it could not be produced.
	 */
	public static function generate( $post_id ) {
		try {
			return self::render( (int) $post_id );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'documentate_pdf_error', $e->getMessage() );
		}
	}

	/**
	 * Resolve the layout, merge the document into it and draw the result.
	 *
	 * @param int $post_id Document post ID.
	 * @return string|WP_Error Absolute path of the written file, or the reason
	 *                         it could not be produced.
	 */
	private static function render( $post_id ) {
		if ( null === Documentate_Document_Generator::get_document_type_id( $post_id ) ) {
			return new WP_Error(
				'documentate_pdf_no_type',
				__( 'The document has no document type.', 'documentate' )
			);
		}

		$layout = Documentate_Pdf_Layout::for_post( $post_id );
		$fields = Documentate_Document_Generator::build_merge_fields( $post_id );

		// The generic layout has no field names of its own: it prints whatever
		// the document type declares, as one row per schema field.
		if ( Documentate_Pdf_Layout::DEFAULT_SLUG === $layout->slug() ) {
			$fields['documentate_fields'] = Documentate_Document_Generator::build_generic_rows( $post_id );
		}

		$html = Documentate_Pdf_Merger::merge( $layout->path(), $fields );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return self::write( $post_id, $layout, $html );
	}

	/**
	 * Draw the merged layout and put the finished file in its place.
	 *
	 * The bytes go to a temporary file beside the target and are renamed into
	 * it, which is one atomic step on the local filesystem. A second
	 * generation of the same document — the admin screen and a REST call can
	 * run at once — therefore either replaces the file whole or leaves the
	 * previous one alone, and a reader downloading it never gets a PDF that
	 * stops halfway.
	 *
	 * @param int                    $post_id Document post ID.
	 * @param Documentate_Pdf_Layout $layout  Layout the document is drawn on.
	 * @param string                 $html    Merged layout.
	 * @return string|WP_Error Absolute path of the written file, or the reason
	 *                         it could not be written.
	 */
	private static function write( $post_id, Documentate_Pdf_Layout $layout, $html ) {
		$target = Documentate_Document_Generator::build_output_path( $post_id, 'pdf' );
		$tmp    = $target . '.' . wp_generate_password( 8, false ) . '.tmp';

		try {
			Documentate_Private_Output::prepare( $tmp );
			$pdf = new Documentate_Pdf_Document( $layout->options() );
			self::apply_metadata( $pdf, Document_Meta::get( $post_id ) );
			$pdf->AddPage();

			$writer = new Documentate_Pdf_Html_Writer( $pdf, $layout );
			$writer->write( self::body( $html ) );

			$pdf->Output( 'F', $tmp );

			// rename() rather than WP_Filesystem::move(): the move has to be the
			// one atomic step of the whole write, and move() falls back to a
			// copy followed by a delete, which is the half-written file this is
			// here to prevent. FPDF wrote the temporary file with plain PHP for
			// the same reason, so the two halves match.
			//
			// A rename that cannot be done is reported as a WP_Error below. The
			// warning PHP would print alongside it is silenced because this runs
			// while a download or a JSON response is being produced, and a
			// warning written into that stream corrupts what the caller reads.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @rename( $tmp, $target ) ) {
				return self::failed(
					$tmp,
					new WP_Error(
						'documentate_pdf_write_failed',
						__( 'The generated PDF could not be saved.', 'documentate' )
					)
				);
			}

			return $target;
		} catch ( \Throwable $e ) {
			return self::failed( $tmp, new WP_Error( 'documentate_pdf_error', $e->getMessage() ) );
		}
	}

	/**
	 * Report a failure, taking the half-written temporary file with it.
	 *
	 * @param string   $tmp   Temporary file the renderer was writing through.
	 * @param WP_Error $error Reason the PDF could not be produced.
	 * @return WP_Error The error, so a caller can return this call directly.
	 */
	private static function failed( $tmp, WP_Error $error ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		return $error;
	}

	/**
	 * Write the document metadata into the PDF Info dictionary.
	 *
	 * Every value is handed over as UTF-8. Without saying so, FPDF takes the
	 * string for ISO-8859-1 and re-encodes it, which turns a euro sign or an
	 * en dash in a subject line into a different character altogether.
	 *
	 * @param Documentate_Pdf_Document $pdf  Document being written.
	 * @param array<string,string>     $meta Metadata as Document_Meta::get() returns it.
	 * @return void
	 */
	private static function apply_metadata( Documentate_Pdf_Document $pdf, array $meta ) {
		foreach ( self::METADATA_SETTERS as $key => $setter ) {
			$value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
			if ( '' === $value ) {
				continue;
			}

			$pdf->$setter( $value, true );
		}
	}

	/**
	 * The part of a merged layout that is drawn on the page.
	 *
	 * A layout is a whole HTML document, and its head carries the options the
	 * page furniture is built from rather than anything to print. A fragment
	 * that has no body element is drawn as it stands, so a layout written as a
	 * bare fragment still renders.
	 *
	 * @param string $html Merged layout.
	 * @return string
	 */
	private static function body( $html ) {
		$html = (string) $html;

		if ( preg_match( '#<body\b[^>]*>(.*)</body>#is', $html, $match ) ) {
			return $match[1];
		}

		return $html;
	}
}
