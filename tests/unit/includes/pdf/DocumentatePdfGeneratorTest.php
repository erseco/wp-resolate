<?php
/**
 * Tests for the orchestrator that turns a document into a native PDF.
 *
 * This is the first point where a real document produces a real PDF, so the
 * assertions read the finished bytes back: the text a reader would see, the
 * Info dictionary a viewer shows, and what is left on disk when the write
 * fails. Everything goes through `Documentate_Document_Generator::generate_pdf()`,
 * which is the entry point the admin screens and the REST routes call.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Generator.
 */
class DocumentatePdfGeneratorTest extends Documentate_Generation_Test_Base {

	/**
	 * Layouts written into the shipped layout directory, removed when the test
	 * ends. They live inside the plugin, so one left behind would show up in
	 * the document-type layout picker and change what a later test sees.
	 *
	 * @var string[]
	 */
	private $written = array();

	/**
	 * Remove the layouts the test wrote.
	 */
	public function tear_down(): void {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->written = array();

		parent::tear_down();
	}

	/**
	 * A document type carrying a template, the generic layout and no data.
	 *
	 * @param string $fixture Template fixture under tests/fixtures/templates.
	 * @return array Document type as create_doc_type_with_template() returns it.
	 */
	private function doc_type( $fixture ) {
		return $this->create_doc_type_with_template( $fixture, 'Tipo PDF ' . uniqid() );
	}

	/**
	 * Write a layout into the shipped layout directory and return its slug.
	 *
	 * The slug carries the process id because a fatal — rather than a failed
	 * assertion — skips tear_down(), and a stray layout would then be offered
	 * in the document-type picker of every later run.
	 *
	 * @param string $body Layout body markup.
	 * @return string Slug the layout was written under.
	 */
	private function shipped_layout( $body ) {
		$slug            = 'resolucion-test-' . getmypid();
		$path            = DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/' . $slug . '.html';
		$this->written[] = $path;

		file_put_contents(
			$path,
			'<!doctype html><html lang="es"><head><meta charset="utf-8">'
			. '<title>Resolución de prueba</title>'
			. '<meta name="documentate-folio" content="footer">'
			. '</head><body>' . $body . '</body></html>'
		);

		return $slug;
	}

	/**
	 * The layout that stands in for the shipped `resolucion` one, which Task 11
	 * writes. It carries the same tags as the real ODT template and the same
	 * fixed headings, so a document rendered on it proves the layout named by
	 * the document type is the one that was drawn.
	 *
	 * @return string Slug the layout was written under.
	 */
	private function resolution_layout() {
		return $this->shipped_layout(
			'<h1>[post_title]</h1>'
			. '<p>[objeto]</p>'
			. '<h2>ANTECEDENTES DE HECHO</h2>'
			. '<div>[antecedentes;strconv=no;protect=no]</div>'
			. '<h2>FUNDAMENTOS DE DERECHO</h2>'
			. '<div>[fundamentos;strconv=no;protect=no]</div>'
			. '<h2>RESUELVO</h2>'
			. '<div>[resuelvo;strconv=no;protect=no]</div>'
			. '[anexos;block=begin]'
			. '<h3>[anexos.code] [anexos.title]</h3>'
			. '<div>[anexos.summary;strconv=no;protect=no]</div>'
			. '[anexos;block=end]'
		);
	}

	/**
	 * Generate a document as a PDF and hand back its bytes.
	 *
	 * @param int $post_id Document to render.
	 * @return string Raw PDF bytes.
	 */
	private function pdf_bytes( $post_id ) {
		$path = $this->generate_document( $post_id, 'pdf' );
		$this->assertIsString( $path, 'PDF generation should return a path.' );
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Read one entry of the PDF Info dictionary back as UTF-8.
	 *
	 * @param string $bytes Raw PDF bytes.
	 * @param string $key   Info key without the slash, e.g. `Subject`.
	 * @return string|null The value, or null when the key is not there.
	 */
	private function info_value( $bytes, $key ) {
		if ( ! preg_match( '#/' . preg_quote( $key, '#' ) . ' \((.*?)(?<!\\\\)\)#s', $bytes, $match ) ) {
			return null;
		}

		$raw = strtr(
			$match[1],
			array(
				'\\\\' => '\\',
				'\\('  => '(',
				'\\)'  => ')',
				'\\r'  => "\r",
			)
		);

		if ( 0 === strpos( $raw, "\xFE\xFF" ) ) {
			return mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		}

		return $raw;
	}

	/**
	 * Directory the generated documents are written into.
	 *
	 * @return string
	 */
	private function output_dir() {
		return trailingslashit( wp_upload_dir()['basedir'] ) . 'documentate';
	}

	/**
	 * A document type that names no layout renders on the generic one, which
	 * prints every schema field the document carries.
	 *
	 * The ampersand is the point of the scalar: it has to survive the escaping
	 * TinyButStrong applies on merge and the cp1252 encoding FPDF writes with,
	 * and come back out as a single character rather than as `&amp;`. The bold
	 * around the surname is the second point: the generator promotes a scalar
	 * carrying markup to a rich value, exactly as the ODT path does, so the
	 * emphasis is drawn instead of being printed as tags.
	 */
	public function test_generate_writes_a_pdf_with_the_generic_layout() {
		$type = $this->doc_type( 'comprehensive-test.odt' );
		$post = $this->create_document_with_data(
			$type['term_id'],
			array(
				'name' => 'Ana & <b>Luis</b>',
				'body' => '<p>Rico <strong>sí</strong></p>',
			)
		);

		$path = $this->generate_document( $post, 'pdf' );
		$this->assertIsString( $path, 'PDF generation should return a path.' );
		$this->assertFileExists( $path );
		$this->assertStringEndsWith( '.pdf', $path );

		$bytes = (string) file_get_contents( $path );
		$this->assertStringStartsWith( '%PDF-', $bytes );

		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );
		$texts = array_column( $ops, 'text' );

		$this->assertContains( 'Ana & ', $texts, 'The ampersand is drawn as one character.' );
		$this->assertContains( 'Luis', $texts, 'The bold of the scalar is a run of its own.' );
		$this->assertSame(
			$this->baseline_of( $ops, 'Ana & ' ),
			$this->baseline_of( $ops, 'Luis' ),
			'Both runs of the field are drawn on the same line.'
		);

		$this->assertContains( 'sí', $texts, 'A rich field keeps its emphasis, so its bold run is drawn on its own.' );
		$this->assertStringContainsString( '/Title (', $bytes );

		// Only the body of the layout is drawn. Its head names the layout and
		// carries the page options, and none of that belongs on the page.
		$this->assertNotContains( 'Genérica', $texts );
	}

	/**
	 * The file lands exactly where build_output_path() says, and the temporary
	 * file the renderer writes through is not left beside it.
	 */
	public function test_generate_returns_the_output_path_and_leaves_no_temporary_file() {
		$type = $this->doc_type( 'minimal-scalar.odt' );
		$post = $this->create_document_with_data( $type['term_id'], array( 'name' => 'Nombre' ) );

		$path = $this->generate_document( $post, 'pdf' );

		$this->assertSame( Documentate_Document_Generator::build_output_path( $post, 'pdf' ), $path );
		$this->assertSame( array(), glob( $this->output_dir() . '/*.tmp' ) );
	}

	/**
	 * A write that cannot be completed reports it and cleans up after itself.
	 *
	 * The target path is occupied by a directory, so the rename into place
	 * fails however the bytes were produced. What must not happen is a stray
	 * half-written file left in the output directory for the next reader.
	 */
	public function test_generate_reports_a_write_it_could_not_finish() {
		$type = $this->doc_type( 'minimal-scalar.odt' );
		$post = $this->create_document_with_data( $type['term_id'], array( 'name' => 'Nombre' ) );

		$target = Documentate_Document_Generator::build_output_path( $post, 'pdf' );
		mkdir( $target );

		try {
			$result = Documentate_Document_Generator::generate_pdf( $post );
		} finally {
			rmdir( $target );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_pdf_write_failed', $result->get_error_code() );
		$this->assertSame( array(), glob( $this->output_dir() . '/*.tmp' ) );
	}

	/**
	 * The layout named by the document type is the one the document is drawn
	 * on, headings and all.
	 */
	public function test_generate_uses_the_layout_from_term_meta() {
		Documentate_Demo_Data::ensure_default_media();
		$template_id = Documentate_Demo_Data::import_fixture_file( 'resolucion.odt' );
		$type        = $this->create_doc_type_with_attachment( $template_id, 'odt' );

		update_term_meta( $type['term_id'], 'documentate_type_pdf_layout', $this->resolution_layout() );

		$post = $this->create_document_with_data(
			$type['term_id'],
			array(
				'objeto'       => 'Objeto X',
				'antecedentes' => '<ul><li>Primero</li></ul>',
			)
		);

		$texts = Documentate_Pdf_Test_Helper::texts( $this->pdf_bytes( $post ) );

		$this->assertContains( 'ANTECEDENTES DE HECHO', $texts );
		$this->assertContains( 'Objeto X', $texts );
		$this->assertContains( 'Primero', $texts );
		$this->assertNotContains( 'Resolución de prueba', $texts, 'The title in the layout head is not drawn on the page.' );
	}

	/**
	 * A layout richer than the schema of the document being rendered prints
	 * nothing for the fields it cannot fill. A literal "[objeto]" in an
	 * official resolution is worse than a gap.
	 */
	public function test_unmerged_tags_never_reach_the_pdf() {
		$type = $this->doc_type( 'minimal-scalar.odt' );
		update_term_meta( $type['term_id'], 'documentate_type_pdf_layout', $this->resolution_layout() );

		$post  = $this->create_document_with_data( $type['term_id'], array() );
		$texts = implode( "\n", Documentate_Pdf_Test_Helper::texts( $this->pdf_bytes( $post ) ) );

		$this->assertStringContainsString( 'RESUELVO', $texts, 'The layout was drawn at all.' );
		$this->assertDoesNotMatchRegularExpression( '/\[[a-z_]+(;|\]|\.)/', $texts );
	}

	/**
	 * A repeater becomes a table: the values of one record are drawn side by
	 * side on one row, not one under the other as separate paragraphs.
	 */
	public function test_generate_draws_a_repeater_as_a_table() {
		$type = $this->doc_type( 'comprehensive-test.odt' );
		$post = $this->create_document_with_data(
			$type['term_id'],
			array(),
			array(
				'items' => array(
					array(
						'title'   => 'Fila uno',
						'content' => '<p>Con <strong>marcado</strong></p>',
					),
					array(
						'title'   => 'Fila dos',
						'content' => 'Sencillo',
					),
				),
			)
		);

		$ops   = Documentate_Pdf_Test_Helper::text_ops( $this->pdf_bytes( $post ) );
		$texts = array_column( $ops, 'text' );

		$this->assertContains( 'Fila uno', $texts );
		$this->assertContains( 'Fila dos', $texts );

		// A cell prints the text of its markup, never the markup itself.
		$this->assertStringNotContainsString( '<strong>', implode( "\n", $texts ) );
		$this->assertContains( 'Con marcado', $texts );

		$this->assertSame(
			$this->baseline_of( $ops, 'Fila uno' ),
			$this->baseline_of( $ops, 'Con marcado' ),
			'Both values of one record share a baseline, which only a table row does.'
		);
	}

	/**
	 * The document metadata reaches the Info dictionary as UTF-8.
	 *
	 * The euro sign is the whole point: FPDF re-encodes a string it is told is
	 * ISO-8859-1, and a euro would come back as a stray character. What a
	 * viewer shows in its properties panel has to be what was typed.
	 */
	public function test_generate_writes_the_document_metadata() {
		$type = $this->doc_type( 'minimal-scalar.odt' );
		$post = $this->create_document_with_data( $type['term_id'], array( 'name' => 'Nombre' ) );

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Resolución 12 €',
			)
		);
		update_post_meta( $post, '_documentate_meta_subject', 'Asunto – 40 €' );
		update_post_meta( $post, '_documentate_meta_author', 'Ana Pérez' );
		update_post_meta( $post, '_documentate_meta_keywords', 'resolución, ayudas' );

		$bytes = $this->pdf_bytes( $post );

		$this->assertSame( 'Resolución 12 €', $this->info_value( $bytes, 'Title' ) );
		$this->assertSame( 'Asunto – 40 €', $this->info_value( $bytes, 'Subject' ) );
		$this->assertSame( 'Ana Pérez', $this->info_value( $bytes, 'Author' ) );
		$this->assertSame( 'resolución, ayudas', $this->info_value( $bytes, 'Keywords' ) );
	}

	/**
	 * A document carrying one field of every troublesome type.
	 *
	 * The trailing text field is not decoration. The folio is drawn last on
	 * every page, so without a row after `aprobado` the boolean's assertion
	 * would read the bare page number "1" and pass with the values missing
	 * altogether. `cierre` is a plain string, so it survives whatever the row
	 * builder does to the others and keeps every typed field away from the end.
	 *
	 * @return int Post ID.
	 */
	private function document_with_typed_fields() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'documentate_doc_type' ) );

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields'  => array(
					array(
						'name'  => 'importe',
						'slug'  => 'importe',
						'type'  => 'number',
						'title' => 'Importe',
					),
					array(
						'name'  => 'decimal',
						'slug'  => 'decimal',
						'type'  => 'number',
						'title' => 'Decimal',
					),
					array(
						'name'  => 'saldo',
						'slug'  => 'saldo',
						'type'  => 'number',
						'title' => 'Saldo',
					),
					array(
						'name'  => 'aprobado',
						'slug'  => 'aprobado',
						'type'  => 'boolean',
						'title' => 'Aprobado',
					),
					array(
						'name'  => 'cierre',
						'slug'  => 'cierre',
						'type'  => 'text',
						'title' => 'Cierre',
					),
				),
			)
		);

		return $this->create_document_with_data(
			$term_id,
			array(
				'importe'  => '1234',
				'decimal'  => '99,5',
				'saldo'    => '0',
				'aprobado' => 'si',
				'cierre'   => 'Ultima fila',
			)
		);
	}

	/**
	 * A number, a decimal, a zero and a boolean all reach the page.
	 *
	 * These fields do not arrive as strings: normalize_number_value() hands
	 * back an int or a float and normalize_boolean_value() an int. A row that
	 * only accepted strings would print the label of an amount with nothing
	 * under it, so the same document would show a figure in its ODT download
	 * and a gap in its PDF, with nothing reporting it. Zero is here on its own
	 * account: it is a legitimate amount and a falsy guard would swallow it.
	 *
	 * One field per test rather than four assertions in one, so each type
	 * fails on its own account instead of hiding behind the first one to go.
	 *
	 * @dataProvider provide_typed_fields
	 *
	 * @param string $label Label the field is drawn under.
	 * @param string $drawn Text that should follow that label.
	 */
	public function test_a_typed_field_reaches_the_page( $label, $drawn ) {
		$texts = Documentate_Pdf_Test_Helper::texts( $this->pdf_bytes( $this->document_with_typed_fields() ) );

		$this->assertSame( $drawn, $this->text_after( $texts, $label ) );
	}

	/**
	 * Each typed field of the document above, and the text it should draw.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_typed_fields() {
		return array(
			'an integer'      => array( 'Importe', '1234' ),
			'a decimal'       => array( 'Decimal', '99.5' ),
			'a zero'          => array( 'Saldo', '0' ),
			'a boolean'       => array( 'Aprobado', '1' ),
			'the string after them' => array( 'Cierre', 'Ultima fila' ),
		);
	}

	/**
	 * Whatever goes wrong, the caller gets a WP_Error rather than a throwable.
	 *
	 * The export handlers and the AJAX endpoint write their answer into a
	 * download or a JSON body, so an exception escaping the generator would end
	 * the response as a fatal error page instead of a message the user can read.
	 */
	public function test_a_throwable_from_anywhere_becomes_a_wp_error() {
		$type = $this->doc_type( 'minimal-scalar.odt' );
		$post = $this->create_document_with_data( $type['term_id'], array( 'name' => 'Nombre' ) );

		$explode = static function () {
			throw new RuntimeException( 'un filtro ajeno ha fallado' );
		};
		add_filter( 'pre_option_documentate_settings', $explode );

		try {
			$result = Documentate_Document_Generator::generate_pdf( $post );
		} finally {
			remove_filter( 'pre_option_documentate_settings', $explode );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_pdf_error', $result->get_error_code() );
		$this->assertSame( 'un filtro ajeno ha fallado', $result->get_error_message() );
	}

	/**
	 * A document with no type cannot be rendered, and says so instead of
	 * writing an empty PDF.
	 */
	public function test_missing_document_type_is_reported() {
		$post = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );

		$result = Documentate_Document_Generator::generate_pdf( $post );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_pdf_no_type', $result->get_error_code() );
		$this->assertFileDoesNotExist( Documentate_Document_Generator::build_output_path( $post, 'pdf' ) );
	}

	/**
	 * The text drawn immediately after the given one.
	 *
	 * The generic layout draws a row as its label and then its value, and the
	 * empty half of a row draws nothing at all, so the two are consecutive.
	 * Reading the value this way pins it to its own label rather than to
	 * anything else on the page that happens to carry the same characters.
	 *
	 * It cannot tell body text from page furniture, and it must not be trusted
	 * to. The folio is drawn last on every page, so a row that is the final one
	 * of the document is followed by a bare page number that reads exactly like
	 * a value — a document whose last field printed nothing would still return
	 * "1" here. A caller therefore has to leave a row after the one it is
	 * asserting on. The guard below only catches the blunter version of the
	 * same mistake, a label with nothing after it at all.
	 *
	 * @param string[] $texts Texts in drawing order.
	 * @param string   $label Text to look for.
	 * @return string The next text.
	 */
	private function text_after( array $texts, $label ) {
		$at = array_search( $label, $texts, true );
		$this->assertNotFalse( $at, 'The label "' . $label . '" should be drawn.' );
		$this->assertArrayHasKey(
			$at + 1,
			$texts,
			'Nothing follows "' . $label . '", so there is no value to read.'
		);

		return $texts[ $at + 1 ];
	}

	/**
	 * Baseline the given text was drawn at.
	 *
	 * @param array  $ops  Text operations, as the helper reports them.
	 * @param string $text Text to look for.
	 * @return float|null
	 */
	private function baseline_of( array $ops, $text ) {
		foreach ( $ops as $op ) {
			if ( $text === $op['text'] ) {
				return $op['y'];
			}
		}

		return null;
	}
}
