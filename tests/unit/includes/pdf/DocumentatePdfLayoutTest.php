<?php
/**
 * Tests for the PDF layout reader and for layout resolution.
 *
 * Two things are covered here: reading one layout file — its title, its
 * validated options and the images it is allowed to embed — and working out
 * which layout a document renders on, from the term meta of its document
 * type. That meta is untrusted, so the tests that matter most are the ones
 * that pin what it may not reach.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Layout.
 */
class DocumentatePdfLayoutTest extends WP_UnitTestCase {

	/**
	 * Paths written by layout_file() and shipped_layout(), removed when the
	 * test ends. shipped_layout() writes inside the plugin, so leaving one
	 * behind would change what a later test sees.
	 *
	 * @var string[]
	 */
	private $written = array();

	/**
	 * Remove the layout files the test wrote.
	 */
	public function tear_down() {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->written = array();

		parent::tear_down();
	}

	/**
	 * Write a layout file with the given head and return its path.
	 *
	 * @param string $head Contents of the <head> element.
	 * @return string
	 */
	private function layout_file( $head ) {
		$path            = wp_tempnam( 'layout' ) . '.html';
		$this->written[] = $path;

		file_put_contents( $path, '<!doctype html><html lang="es"><head><meta charset="utf-8">' . $head . '</head><body><p>cuerpo</p></body></html>' );

		return $path;
	}

	/**
	 * The shipped generic layout is readable and names itself.
	 */
	public function test_for_file_reads_the_shipped_generic_layout() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' );

		$this->assertSame( 'generic', $layout->slug() );
		$this->assertSame( 'Genérica', $layout->title() );
		$this->assertStringEndsWith( 'templates/pdf/generic.html', $layout->path() );
	}

	/**
	 * The generic layout asks for the footer folio and its own margins.
	 */
	public function test_generic_layout_options() {
		$options = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' )->options();

		$this->assertSame( 'footer', $options['folio'] );
		$this->assertSame( array( 25.0, 20.0, 25.0, 20.0 ), $options['margins'] );
		$this->assertSame( 'none', $options['letterhead'] );
		$this->assertFalse( $options['crest'] );
	}

	/**
	 * Every documentate-* meta is read, and a value outside its closed list is
	 * dropped rather than passed on to the document.
	 */
	public function test_options_are_parsed_and_validated() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-letterhead" content="standard">'
			. '<meta name="documentate-addresses" content="band">'
			. '<meta name="documentate-folio" content="bogus">'
			. '<meta name="documentate-crest" content="1">'
			. '<meta name="documentate-margins" content="22.5 15 22.5 30">'
			. '<meta name="documentate-font" content="helvetica">'
			. '<meta name="documentate-font-size" content="10.5">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( 'standard', $options['letterhead'] );
		$this->assertSame( 'band', $options['addresses'] );
		$this->assertSame( 'none', $options['folio'] );
		$this->assertTrue( $options['crest'] );
		$this->assertSame( array( 22.5, 15.0, 22.5, 30.0 ), $options['margins'] );
		$this->assertSame( 'helvetica', $options['font'] );
		$this->assertSame( 10.5, $options['font_size'] );
	}

	/**
	 * A margin list that is not four numbers is ignored, and so is a font size
	 * that is not a positive number.
	 */
	public function test_malformed_numbers_fall_back_to_the_defaults() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="20 20 20">'
			. '<meta name="documentate-font-size" content="cero">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 20.0, 20.0, 20.0, 20.0 ), $options['margins'] );
		$this->assertSame( 11.0, $options['font_size'] );
		$this->assertNull( $options['first_page_margins'] );
	}

	/**
	 * A first-page bottom margin only replaces the bottom of the page margins.
	 */
	public function test_first_page_bottom_margin_overrides_only_the_bottom() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="25 20 25 20">'
			. '<meta name="documentate-first-page-bottom" content="43">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 25.0, 20.0, 43.0, 20.0 ), $options['first_page_margins'] );
		$this->assertSame( array( 25.0, 20.0, 25.0, 20.0 ), $options['margins'] );
	}

	/**
	 * A layout whose first page is laid out differently states all four margins.
	 */
	public function test_first_page_margins_replace_all_four_margins() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="29 20 72 20">'
			. '<meta name="documentate-first-page-margins" content="71 22.5 72 25">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 71.0, 22.5, 72.0, 25.0 ), $options['first_page_margins'] );
		$this->assertSame( array( 29.0, 20.0, 72.0, 20.0 ), $options['margins'] );
	}

	/**
	 * The full first-page form wins over the bottom-only shorthand.
	 */
	public function test_first_page_margins_win_over_the_first_page_bottom_shorthand() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="25 20 25 20">'
			. '<meta name="documentate-first-page-margins" content="71 20 60 20">'
			. '<meta name="documentate-first-page-bottom" content="43">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 71.0, 20.0, 60.0, 20.0 ), $options['first_page_margins'] );
	}

	/**
	 * A malformed first-page margin list falls back to the bottom-only shorthand.
	 */
	public function test_malformed_first_page_margins_fall_back_to_the_shorthand() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="25 20 25 20">'
			. '<meta name="documentate-first-page-margins" content="71 20 sesenta">'
			. '<meta name="documentate-first-page-bottom" content="43">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 25.0, 20.0, 43.0, 20.0 ), $options['first_page_margins'] );
	}

	/**
	 * A file that cannot be read still yields a usable layout on the defaults.
	 */
	public function test_a_missing_file_yields_the_default_options() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/no-existe.html' );

		$this->assertSame( '', $layout->title() );
		$this->assertSame( 'none', $layout->options()['folio'] );
	}

	/**
	 * Only a bare file name that exists under templates/pdf/img resolves: a
	 * layout must not be able to embed an arbitrary file from disk.
	 */
	public function test_image_path_is_confined_to_templates_img() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' );

		$this->assertStringEndsWith( 'templates/pdf/img/membrete.png', $layout->image_path( 'membrete.png' ) );
		$this->assertSame( '', $layout->image_path( '../../documentate.php' ) );
		$this->assertSame( '', $layout->image_path( 'img/../../documentate.php' ) );
		$this->assertSame( '', $layout->image_path( DOCUMENTATE_PLUGIN_DIR . 'documentate.php' ) );
		$this->assertSame( '', $layout->image_path( 'no-existe.png' ) );
		$this->assertSame( '', $layout->image_path( '..' ) );
		$this->assertSame( '', $layout->image_path( '' ) );
	}

	/**
	 * Write a layout into the shipped layout directory and return its slug.
	 *
	 * The directory is spelled out here rather than taken from the class under
	 * test, so the test pins the real shipping location instead of trusting
	 * whatever dir() happens to return.
	 *
	 * @param string $slug Base name of the file, without the extension.
	 * @param string $head Contents of the <head> element.
	 * @return string The slug the layout was written under.
	 */
	private function shipped_layout( $slug, $head ) {
		$path            = DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/' . $slug . '.html';
		$this->written[] = $path;

		file_put_contents( $path, '<!doctype html><html lang="es"><head><meta charset="utf-8">' . $head . '</head><body><p>cuerpo</p></body></html>' );

		return $slug;
	}

	/**
	 * Create a document whose type names the given layout.
	 *
	 * @param string|null $layout Value stored in the layout term meta, or null
	 *                            to leave the type without one.
	 * @return int Post ID.
	 */
	private function document_with_layout( $layout = null ) {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'documentate_doc_type' ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );

		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		if ( null !== $layout ) {
			update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, $layout );
		}

		return $post_id;
	}

	/**
	 * Make get_term_meta() hand back the given value for the layout meta.
	 *
	 * The stored name is untrusted: an administrator types it, and a corrupt
	 * row can hold bytes update_term_meta() would never write. Filtering the
	 * read models that exactly.
	 *
	 * @param mixed $value Value get_term_meta( $id, META_KEY, true ) returns.
	 */
	private function force_layout_meta( $value ) {
		add_filter(
			'get_term_metadata',
			static function ( $check, $term_id, $meta_key, $single ) use ( $value ) {
				if ( Documentate_Pdf_Layout::META_KEY === $meta_key && $single ) {
					return array( $value );
				}

				return $check;
			},
			10,
			4
		);
	}

	/**
	 * dir() is the directory the shipped layouts live in.
	 */
	public function test_dir_is_the_shipped_layout_directory() {
		$this->assertSame( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/', Documentate_Pdf_Layout::dir() );
		$this->assertFileExists( Documentate_Pdf_Layout::dir() . 'generic.html' );
	}

	/**
	 * available() names the shipped generic layout by its own <title>.
	 */
	public function test_available_lists_the_generic_layout_by_its_title() {
		$available = Documentate_Pdf_Layout::available();

		$this->assertArrayHasKey( 'generic', $available );
		$this->assertSame( 'Genérica', $available['generic'] );
	}

	/**
	 * Every layout file is listed, keyed by slug, sorted by slug, and nothing
	 * that is not a layout file gets in.
	 */
	public function test_available_lists_every_layout_file_sorted_by_slug() {
		$this->shipped_layout( 'zzz-test-layout', '<title>Última</title>' );
		$this->shipped_layout( 'aaa-test-layout', '<title>Primera</title>' );

		$available = Documentate_Pdf_Layout::available();

		$this->assertSame( 'Primera', $available['aaa-test-layout'] );
		$this->assertSame( 'Última', $available['zzz-test-layout'] );
		$this->assertArrayNotHasKey( 'img', $available );

		$slugs  = array_keys( $available );
		$sorted = $slugs;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $sorted, $slugs );
		$this->assertSame( 'aaa-test-layout', $slugs[0] );
	}

	/**
	 * A layout that gives itself no title is labelled with its slug, so the
	 * document-type select never offers a blank option.
	 */
	public function test_available_labels_a_titleless_layout_with_its_slug() {
		$this->shipped_layout( 'sin-titulo-test', '' );

		$this->assertSame( 'sin-titulo-test', Documentate_Pdf_Layout::available()['sin-titulo-test'] );
	}

	/**
	 * The layout named by the document type is the one a document renders on.
	 */
	public function test_for_post_uses_the_layout_named_by_the_document_type() {
		$slug = $this->shipped_layout( 'tipo-test-layout', '<title>De prueba</title><meta name="documentate-folio" content="header">' );

		$layout = Documentate_Pdf_Layout::for_post( $this->document_with_layout( $slug ) );

		$this->assertSame( 'tipo-test-layout', $layout->slug() );
		$this->assertSame( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/tipo-test-layout.html', $layout->path() );
		$this->assertSame( 'De prueba', $layout->title() );
		$this->assertSame( 'header', $layout->options()['folio'] );
	}

	/**
	 * The stored name goes through the same reduction the document-type screen
	 * saves it with, so a row edited by hand that only differs in case, or
	 * carries stray punctuation, still finds its layout instead of silently
	 * dropping back to the generic one.
	 */
	public function test_for_post_normalises_the_stored_layout_name() {
		$this->shipped_layout( 'tipo-test-layout', '<title>De prueba</title>' );

		$this->assertSame( 'tipo-test-layout', Documentate_Pdf_Layout::for_post( $this->document_with_layout( 'Tipo-Test-Layout' ) )->slug() );
		$this->assertSame( 'tipo-test-layout', Documentate_Pdf_Layout::for_post( $this->document_with_layout( ' tipo-test-layout ' ) )->slug() );
	}

	/**
	 * A type naming no layout, a document carrying no type and a post that is
	 * not there all render on the generic layout instead of failing.
	 */
	public function test_for_post_falls_back_to_generic_when_no_layout_is_named() {
		$untyped = self::factory()->post->create( array( 'post_type' => 'documentate_document' ) );

		$this->assertSame( 'generic', Documentate_Pdf_Layout::for_post( $this->document_with_layout() )->slug() );
		$this->assertSame( 'generic', Documentate_Pdf_Layout::for_post( $untyped )->slug() );
		$this->assertSame( 'generic', Documentate_Pdf_Layout::for_post( 0 )->slug() );
		$this->assertSame( 'generic', Documentate_Pdf_Layout::for_post( PHP_INT_MAX )->slug() );
	}

	/**
	 * A type naming a layout that was never shipped falls back to generic, and
	 * what comes back is the readable generic file, not an empty stub.
	 */
	public function test_for_post_falls_back_to_generic_for_an_unknown_layout() {
		$layout = Documentate_Pdf_Layout::for_post( $this->document_with_layout( 'no-existe' ) );

		$this->assertSame( 'generic', $layout->slug() );
		$this->assertSame( 'Genérica', $layout->title() );
	}

	/**
	 * The stored layout name cannot reach out of templates/pdf, whatever a
	 * corrupt row leaves in it.
	 *
	 * @dataProvider hostile_layout_names
	 * @param mixed $stored Value sitting in the layout term meta.
	 */
	public function test_for_post_refuses_a_layout_name_that_escapes_the_directory( $stored ) {
		$post_id = $this->document_with_layout();
		$this->force_layout_meta( $stored );

		$layout = Documentate_Pdf_Layout::for_post( $post_id );

		$this->assertSame( 'generic', $layout->slug() );
		$this->assertSame( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html', $layout->path() );
	}

	/**
	 * Layout names a compromised row or a careless administrator can leave in
	 * the term meta.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function hostile_layout_names() {
		return array(
			'parent directory'   => array( '../../etc/passwd' ),
			'sibling of the dir' => array( '../generic' ),
			'absolute path'      => array( '/etc/passwd' ),
			'windows separator'  => array( '..\\..\\windows\\win.ini' ),
			'null byte'          => array( "generic\0.html" ),
			'bare null byte'     => array( "\0" ),
			'own extension'      => array( 'generic.html' ),
			'empty'              => array( '' ),
			'only whitespace'    => array( '   ' ),
			'stream wrapper'     => array( 'php://input' ),
			'remote url'         => array( 'https://example.test/x' ),
		);
	}

	/**
	 * A layout name pointing at a real HTML file outside templates/pdf is
	 * refused: only a shipped layout can be reached.
	 */
	public function test_for_post_refuses_a_real_file_outside_the_layout_directory() {
		$outside         = DOCUMENTATE_PLUGIN_DIR . 'templates/documentate-outside-test.html';
		$this->written[] = $outside;
		file_put_contents( $outside, '<html><head><title>Fuera</title></head><body><p>x</p></body></html>' );

		$layout = Documentate_Pdf_Layout::for_post( $this->document_with_layout( '../documentate-outside-test' ) );

		$this->assertSame( 'generic', $layout->slug() );
		$this->assertNotSame( 'Fuera', $layout->title() );
		$this->assertSame( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html', $layout->path() );
	}

	/**
	 * A layout meta that is not a string at all is refused without raising a
	 * PHP warning on the way.
	 */
	public function test_for_post_refuses_a_layout_name_that_is_not_a_string() {
		$post_id = $this->document_with_layout();
		$this->force_layout_meta( array( 'generic' ) );

		$raised = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$raised ) {
				$raised[] = $errstr;

				return true;
			}
		);

		try {
			$slug = Documentate_Pdf_Layout::for_post( $post_id )->slug();
		} finally {
			// Restore even on a throw: the handler is process-wide, and a
			// stale one would swallow the errors of every later test.
			restore_error_handler();
		}

		$this->assertSame( array(), $raised );
		$this->assertSame( 'generic', $slug );
	}
	/**
	 * An existing type gets the layout its office template is named after.
	 */
	public function test_assign_missing_matches_a_type_to_its_template() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => Documentate_Pdf_Layout::TAXONOMY ) );
		$file    = wp_upload_dir()['basedir'] . '/resolucion.odt';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, 'x' );
		$attachment = self::factory()->attachment->create( array( 'file' => $file ) );
		update_term_meta( $term_id, Documentate_Pdf_Layout::TEMPLATE_META, $attachment );

		Documentate_Pdf_Layout::assign_missing();

		$this->assertSame( 'resolucion', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * A name WordPress made unique on upload resolves to the same layout.
	 */
	public function test_assign_missing_ignores_an_upload_suffix() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => Documentate_Pdf_Layout::TAXONOMY ) );
		$file    = wp_upload_dir()['basedir'] . '/propuestagasto-2.odt';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, 'x' );
		$attachment = self::factory()->attachment->create( array( 'file' => $file ) );
		update_term_meta( $term_id, Documentate_Pdf_Layout::TEMPLATE_META, $attachment );

		Documentate_Pdf_Layout::assign_missing();

		$this->assertSame( 'propuestagasto', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * Nothing is guessed, nothing already chosen is overwritten, and the pass
	 * does not run twice.
	 */
	public function test_assign_missing_leaves_unknown_and_chosen_types_alone() {
		$unknown = self::factory()->term->create( array( 'taxonomy' => Documentate_Pdf_Layout::TAXONOMY ) );
		$file    = wp_upload_dir()['basedir'] . '/plantilla-propia.odt';
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, 'x' );
		update_term_meta( $unknown, Documentate_Pdf_Layout::TEMPLATE_META, self::factory()->attachment->create( array( 'file' => $file ) ) );

		$chosen = self::factory()->term->create( array( 'taxonomy' => Documentate_Pdf_Layout::TAXONOMY ) );
		update_term_meta( $chosen, Documentate_Pdf_Layout::META_KEY, 'haceconstar' );
		$other = wp_upload_dir()['basedir'] . '/resolucion.odt';
		file_put_contents( $other, 'x' );
		update_term_meta( $chosen, Documentate_Pdf_Layout::TEMPLATE_META, self::factory()->attachment->create( array( 'file' => $other ) ) );

		Documentate_Pdf_Layout::assign_missing();

		$this->assertSame( '', get_term_meta( $unknown, Documentate_Pdf_Layout::META_KEY, true ), 'An unrecognised template is left alone.' );
		$this->assertSame( 'haceconstar', get_term_meta( $chosen, Documentate_Pdf_Layout::META_KEY, true ), 'A chosen layout is not overwritten.' );

		delete_term_meta( $unknown, Documentate_Pdf_Layout::META_KEY );
		Documentate_Pdf_Layout::assign_missing();
		$this->assertSame( '', get_term_meta( $unknown, Documentate_Pdf_Layout::META_KEY, true ), 'The pass runs once.' );
	}

	/**
	 * A failed term lookup leaves the pass to run again.
	 *
	 * Writing the marker whatever happened would give up for good on the first
	 * hiccup, and every document type created before this release would stay
	 * on the generic layout with nothing to show for it.
	 */
	public function test_assign_missing_does_not_give_up_on_a_failed_lookup() {
		delete_option( Documentate_Pdf_Layout::ASSIGNED_OPTION );

		$fail = static function () {
			return new WP_Error( 'boom', 'sin taxonomía' );
		};
		add_filter( 'terms_pre_query', $fail );
		Documentate_Pdf_Layout::assign_missing();
		remove_filter( 'terms_pre_query', $fail );

		$this->assertFalse( get_option( Documentate_Pdf_Layout::ASSIGNED_OPTION ), 'The marker is only written on a pass that ran.' );

		Documentate_Pdf_Layout::assign_missing();
		$this->assertNotFalse( get_option( Documentate_Pdf_Layout::ASSIGNED_OPTION ), 'The next request runs the pass.' );
	}

}
