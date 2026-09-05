<?php
/**
 * Tests for the TinyButStrong merge that fills an HTML PDF layout.
 *
 * The merge is the step between a document's field values and the HTML the
 * PDF renderer walks. What matters here is not that TBS works — it is that
 * this wrapper asks TBS for the right thing: scalars escaped, rich fields
 * verbatim, repeaters expanded, and above all that a layout the document
 * cannot fill never leaks a literal tag or a TBS error message into the
 * bytes that become a PDF.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Merger.
 */
class DocumentatePdfMergerTest extends WP_UnitTestCase {

	/**
	 * Layout files written by layout_file(), removed when the test ends.
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
	 * Write a layout holding the given body and schedule its removal.
	 *
	 * @param string $body Layout body markup.
	 * @return string Absolute path to the layout file.
	 */
	private function layout_file( $body ) {
		$path = wp_tempnam( 'layout' );
		file_put_contents( $path, '<html><head><title>T</title></head><body>' . $body . '</body></html>' );
		$this->written[] = $path;

		return $path;
	}

	/**
	 * Merge one body fragment wrapped in a minimal HTML layout.
	 *
	 * @param string $body   Layout body markup.
	 * @param array  $fields Merge fields.
	 * @return string|WP_Error Merged HTML, or the error the merge reported.
	 */
	private function merge( $body, array $fields ) {
		return Documentate_Pdf_Merger::merge( $this->layout_file( $body ), $fields );
	}

	/**
	 * A scalar is HTML-escaped and its line breaks become <br /> tags.
	 */
	public function test_scalars_are_escaped_and_newlines_become_br() {
		$out = $this->merge( '<p>[x]</p>', array( 'x' => "a <b> & c\nd" ) );

		$this->assertStringContainsString( 'a &lt;b&gt; &amp; c<br />', $out );
		$this->assertStringNotContainsString( '<p>a <b>', $out );
	}

	/**
	 * A rich HTML field asking for strconv=no and protect=no is injected as it
	 * stands: its tags survive and its square brackets are not protected into
	 * entities.
	 */
	public function test_rich_fields_are_injected_verbatim_with_strconv_no() {
		$out = $this->merge( '<div>[cuerpo;strconv=no;protect=no]</div>', array( 'cuerpo' => '<p>Hola [1]</p>' ) );

		$this->assertStringContainsString( '<p>Hola [1]</p>', $out );
		$this->assertStringNotContainsString( '&#91;', $out );
	}

	/**
	 * A repeater expands its block once per row, and a nested repeater expands
	 * its own rows inside the parent section.
	 */
	public function test_blocks_rows_and_nested_sub_blocks() {
		$body = '[servicios;block=begin;sub1=conceptos]<table><tr><td>[servicios.proveedor]</td></tr><tr><td>[servicios_sub1.concepto;block=tr]</td></tr></table>[servicios;block=end]';

		$out = $this->merge(
			$body,
			array(
				'servicios' => array(
					array(
						'proveedor' => 'P1',
						'conceptos' => array(
							array( 'concepto' => 'c1' ),
							array( 'concepto' => 'c2' ),
						),
					),
				),
			)
		);

		$this->assertSame( 3, substr_count( $out, '<tr>' ) );
		$this->assertStringContainsString( 'P1', $out );
		$this->assertStringContainsString( 'c1', $out );
		$this->assertStringContainsString( 'c2', $out );
	}

	/**
	 * A nested repeater can also be delimited with its own begin and end
	 * markers. Its name is not a field of its own, so it looks like a leftover
	 * when the layout is read, but the parent's merge consumes its tags. Asking
	 * TBS to clear a block it can no longer find would fail the whole merge.
	 */
	public function test_a_nested_block_with_its_own_markers_is_not_cleared_afterwards() {
		$body = '[servicios;block=begin;sub1=conceptos]<p>[servicios.proveedor]</p>'
			. '[servicios_sub1;block=begin]<span>[servicios_sub1.concepto]</span>[servicios_sub1;block=end]'
			. '[servicios;block=end]';

		$out = $this->merge(
			$body,
			array(
				'servicios' => array(
					array(
						'proveedor' => 'P1',
						'conceptos' => array(
							array( 'concepto' => 'c1' ),
							array( 'concepto' => 'c2' ),
						),
					),
				),
			)
		);

		$this->assertNotWPError( $out );
		$this->assertStringContainsString( '<span>c1</span>', $out );
		$this->assertStringContainsString( '<span>c2</span>', $out );
	}

	/**
	 * Square brackets are ordinary punctuation in administrative Spanish, and a
	 * rich field is injected verbatim, so merged content is full of things that
	 * look exactly like an unmerged tag. Only the layout may nominate a tag for
	 * clearing; what a user typed is never a candidate.
	 */
	public function test_brackets_written_by_a_user_are_not_mistaken_for_leftover_tags() {
		$out = $this->merge(
			'<div>[cuerpo;strconv=no;protect=no]</div><p>[huerfano]</p>',
			array( 'cuerpo' => '<p>Nota [sic] al margen y [nota] entre corchetes.</p>' )
		);

		$this->assertStringContainsString( '<p>Nota [sic] al margen y [nota] entre corchetes.</p>', $out );
		$this->assertStringNotContainsString( '[huerfano', $out );
	}

	/**
	 * An orphan block inside a repeater is the dangerous case. TBS pairs the
	 * first block=begin with the last block=end, so once the repeater has
	 * expanded there are as many copies of the orphan's markers as there are
	 * rows, and clearing it would delete everything between the first and the
	 * last — the rows in between with it, silently, ErrCount still zero.
	 */
	public function test_an_orphan_block_inside_a_repeater_does_not_swallow_its_rows() {
		$body = '[servicios;block=begin]<p>[servicios.proveedor]</p>'
			. '[extra;block=begin]<span>[extra.z]</span>[extra;block=end]'
			. '[servicios;block=end]';

		$out = $this->merge(
			$body,
			array(
				'servicios' => array(
					array( 'proveedor' => 'P1' ),
					array( 'proveedor' => 'P2' ),
					array( 'proveedor' => 'P3' ),
				),
			)
		);

		$this->assertNotWPError( $out );
		$this->assertSame( 3, substr_count( $out, '<p>' ) );
		$this->assertStringContainsString( '<p>P1</p>', $out );
		$this->assertStringContainsString( '<p>P2</p>', $out );
		$this->assertStringContainsString( '<p>P3</p>', $out );
		$this->assertStringNotContainsString( '[extra', $out );
	}

	/**
	 * The layout may orphan the very name a user wrote in a rich field. Only
	 * clearing the orphan while the buffer still holds nothing but the layout
	 * keeps the two apart.
	 */
	public function test_a_rich_fields_brackets_survive_a_layout_orphan_of_the_same_name() {
		$out = $this->merge(
			'<div>[cuerpo;strconv=no;protect=no]</div><p>[nota]</p>',
			array( 'cuerpo' => '<p>Vease [nota] al margen.</p>' )
		);

		$this->assertStringContainsString( '<p>Vease [nota] al margen.</p>', $out );
		$this->assertStringContainsString( '<p></p>', $out );
	}

	/**
	 * block=tr is the form an HTML layout uses for a repeated row, so an
	 * orphaned row carries block=tr and not block=begin. Handing it to
	 * MergeField makes TBS raise an alert and kills the whole document, so the
	 * routing has to look for any block= parameter.
	 */
	public function test_an_orphaned_block_tr_row_is_removed_and_the_merge_succeeds() {
		$out = $this->merge(
			'<table><tr><td>fija</td></tr><tr><td>[extra.y;block=tr]</td></tr></table>',
			array()
		);

		$this->assertNotWPError( $out );
		$this->assertStringNotContainsString( '[extra', $out );
		$this->assertSame( 1, substr_count( $out, '<tr>' ) );
		$this->assertStringContainsString( 'fija', $out );
	}

	/**
	 * The narrowest version of the same trap: a rich field whose text names its
	 * own tag. The name really is a layout tag, so only the fact that a field
	 * answered for it keeps the user's sentence intact.
	 */
	public function test_a_rich_field_may_name_its_own_tag_in_its_text() {
		$out = $this->merge(
			'<div>[cuerpo;strconv=no;protect=no]</div>',
			array( 'cuerpo' => '<p>El campo [cuerpo] se rellena solo.</p>' )
		);

		$this->assertStringContainsString( '<p>El campo [cuerpo] se rellena solo.</p>', $out );
	}

	/**
	 * A scalar keeps its brackets too. TBS protects the opening bracket of a
	 * merged value into an entity so it can never be read back as a tag, so
	 * either spelling is correct here; what must not happen is the bracketed
	 * word disappearing.
	 */
	public function test_brackets_in_a_scalar_value_survive_the_merge() {
		$out = $this->merge( '<p>[x]</p>', array( 'x' => 'Ley 39/2015 [sic]' ) );

		$this->assertMatchesRegularExpression( '/<p>Ley 39\/2015 (?:\[|&#91;)sic\]<\/p>/', $out );
	}

	/**
	 * A layout richer than the document's schema must not print its unmatched
	 * tags: a leftover field is emptied and a leftover block is dropped, while
	 * the automatic onshow fields TBS itself resolves are left alone.
	 */
	public function test_leftover_tags_are_cleared() {
		$out = $this->merge(
			'<p>[huerfano]</p>[lista;block=begin]<p>[lista.x]</p>[lista;block=end]<p>[onshow.ok]</p>',
			array( 'ok' => 'si' )
		);

		$this->assertStringNotContainsString( '[huerfano', $out );
		$this->assertStringNotContainsString( '[lista', $out );
		$this->assertStringContainsString( '<p></p>', $out );
		$this->assertStringContainsString( '<p>si</p>', $out );
	}

	/**
	 * TBS reports a broken layout by echoing the message. Echoing into an AJAX
	 * response or a PDF byte stream corrupts it, so the merge must stay silent
	 * and report a WP_Error instead.
	 */
	public function test_tbs_errors_become_wp_errors_not_output() {
		ob_start();
		$out    = $this->merge( '[b;block=begin;sub1=falta][b.x][b;block=end]', array( 'b' => array( array( 'x' => 1 ) ) ) );
		$echoed = ob_get_clean();

		$this->assertSame( '', $echoed );
		$this->assertWPError( $out );
		$this->assertSame( 'documentate_pdf_merge_error', $out->get_error_code() );
	}

	/**
	 * A visibility block over an empty field is dropped, and the TBS operators
	 * and formats the ODT templates already use behave the same way here.
	 */
	public function test_visibility_blocks_and_upper_and_frm() {
		$body = '[onshow;block=begin;bloc=lista]<p>hay</p>[onshow;block=end]<p>[t;ope=utf8,upper]</p><p>[n;frm=\'0.000,00 €\']</p><p>[d;frm=\'d\'] de [d;frm=\'mmmm (locale)\']</p>';

		$out = $this->merge(
			$body,
			array(
				'lista' => array(),
				't'     => 'ñu',
				'n'     => 1234.5,
				'd'     => '2026-03-01',
			)
		);

		$this->assertStringNotContainsString( 'hay', $out );
		$this->assertStringContainsString( '<p>ÑU</p>', $out );
		$this->assertStringContainsString( '<p>1.234,50 €</p>', $out );
		// The month name comes from strftime() and needs the es_ES locale to
		// be installed; the test containers only carry C, so English is
		// accepted as the documented fallback. TBS cuts "(locale)" out of the
		// format string and leaves the space that preceded it behind.
		$this->assertMatchesRegularExpression( '/<p>1 de (marzo|march) ?<\/p>/i', $out );
		$this->assertStringNotContainsString( '2026-03-01', $out );
	}

	/**
	 * A visibility block over a field that does have data is kept.
	 */
	public function test_visibility_blocks_keep_a_block_whose_field_has_data() {
		$out = $this->merge(
			'[onshow;block=begin;bloc=lista]<p>hay</p>[onshow;block=end]',
			array( 'lista' => array( array( 'x' => 1 ) ) )
		);

		$this->assertStringContainsString( '<p>hay</p>', $out );
	}

	/**
	 * The schema extractor reads parameters TBS knows nothing about. TBS must
	 * store and ignore them rather than treat them as merge instructions.
	 */
	public function test_unknown_documentate_parameters_are_ignored() {
		$out = $this->merge( '<p>[f;type=\'text\';title=\'F\';rol=\'gestion\';required=\'true\']</p>', array( 'f' => 'v' ) );

		$this->assertStringContainsString( '<p>v</p>', $out );
	}

	/**
	 * PHP turns a numeric field name into an integer array key, and no TBS tag
	 * can be spelled that way. Such a key is skipped rather than handed to TBS,
	 * which would report the block it cannot find as a merge error.
	 */
	public function test_keys_that_cannot_name_a_tag_are_skipped() {
		$out = $this->merge(
			'<p>[x]</p>',
			array(
				'x' => 'v',
				'0' => array( array( 'y' => 1 ) ),
				''  => 'nada',
			)
		);

		$this->assertNotWPError( $out );
		$this->assertStringContainsString( '<p>v</p>', $out );
	}

	/**
	 * A layout path that is not there is an error, not a warning and an empty
	 * document.
	 */
	public function test_missing_layout_is_an_error() {
		$out = Documentate_Pdf_Merger::merge( '/no/existe.html', array() );

		$this->assertWPError( $out );
		$this->assertSame( 'documentate_pdf_layout_missing', $out->get_error_code() );
	}

	/**
	 * The visibility pass runs a regular expression over the whole layout and
	 * returns null when PCRE gives up. That must surface as an error instead
	 * of blanking the document.
	 */
	public function test_a_pcre_failure_in_the_visibility_pass_is_an_error() {
		$limit = ini_get( 'pcre.backtrack_limit' );
		$jit   = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		try {
			$out = $this->merge(
				'[onshow;block=begin;bloc=lista]' . str_repeat( '<p>x</p>', 50 ) . '[onshow;block=end]',
				array( 'lista' => array( array( 'x' => 1 ) ) )
			);
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
			ini_set( 'pcre.jit', $jit );
		}

		$this->assertWPError( $out );
		$this->assertSame( 'documentate_regex_error', $out->get_error_code() );
	}

	/**
	 * Scanning the layout for orphans is a regular expression too, and PCRE
	 * gives up on some inputs. Treating that as "no orphans found" would let
	 * every one of them through into the document, so it is an error, like the
	 * visibility pass beside it.
	 */
	public function test_a_pcre_failure_while_scanning_for_orphans_is_an_error() {
		$limit = ini_get( 'pcre.backtrack_limit' );
		$jit   = ini_get( 'pcre.jit' );
		ini_set( 'pcre.jit', '0' );
		ini_set( 'pcre.backtrack_limit', '1' );

		try {
			// No visibility block, so the pass before this one has nothing to
			// backtrack over and the failure lands on the orphan scan.
			$out = $this->merge( str_repeat( '<p>[campo;ope=utf8,upper]</p>', 40 ), array() );
		} finally {
			ini_set( 'pcre.backtrack_limit', $limit );
			ini_set( 'pcre.jit', $jit );
		}

		$this->assertWPError( $out );
		$this->assertSame( 'documentate_regex_error', $out->get_error_code() );
	}

	/**
	 * TBS formats "(locale)" dates with strftime(), deprecated since PHP 8.1.
	 * With display_errors on, that notice is printed straight into the buffer
	 * this class exists to keep clean, which would corrupt a PDF stream or a
	 * JSON response just as a TBS error would.
	 */
	public function test_the_strftime_deprecation_is_not_printed_into_the_output() {
		$display = ini_get( 'display_errors' );
		$repeats = ini_get( 'ignore_repeated_errors' );
		$level   = error_reporting();
		ini_set( 'display_errors', '1' );
		// The containers run with ignore_repeated_errors on, so a notice an
		// earlier test already printed from the same line would be swallowed
		// here and the assertion below would hold for the wrong reason.
		ini_set( 'ignore_repeated_errors', '0' );
		error_reporting( E_ALL );
		// PHPUnit installs a handler that eats deprecations. Stand it down so
		// the notice is printed the way it would be in a real request.
		set_error_handler( null );

		ob_start();
		try {
			$out = $this->merge( '<p>[d;frm=\'mmmm (locale)\']</p>', array( 'd' => '2026-03-01' ) );
		} finally {
			$echoed = ob_get_clean();
			restore_error_handler();
			ini_set( 'display_errors', $display );
			ini_set( 'ignore_repeated_errors', $repeats );
			error_reporting( $level );
		}

		$this->assertSame( '', $echoed );
		$this->assertNotWPError( $out );
	}

	/**
	 * Muting that one deprecation must not mute anything else, and must not
	 * outlive the merge.
	 */
	public function test_muting_the_deprecation_leaves_other_errors_and_the_handler_alone() {
		$seen = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$seen ) {
				$seen[] = $errstr;
				return true;
			}
		);

		try {
			$this->merge( '<p>[d;frm=\'mmmm (locale)\']</p>', array( 'd' => '2026-03-01' ) );
			trigger_error( 'sigue viva', E_USER_WARNING );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array( 'sigue viva' ), $seen );
	}

	/**
	 * The merge switches LC_TIME so month names come out in Spanish. It must
	 * put back the locale the request had, whatever happened.
	 */
	public function test_the_previous_locale_is_restored() {
		$this->with_lc_time(
			'C',
			function () {
				$this->merge( '<p>[d;frm=\'mmmm (locale)\']</p>', array( 'd' => '2026-03-01' ) );

				$this->assertSame( 'C', setlocale( LC_TIME, 0 ) );
			}
		);
	}

	/**
	 * The locale is restored even when the merge fails part-way through.
	 */
	public function test_the_previous_locale_is_restored_after_an_error() {
		$this->with_lc_time(
			'C',
			function () {
				$out = $this->merge( '[b;block=begin;sub1=falta][b.x][b;block=end]', array( 'b' => array( array( 'x' => 1 ) ) ) );

				$this->assertWPError( $out );
				$this->assertSame( 'C', setlocale( LC_TIME, 0 ) );
			}
		);
	}

	/**
	 * A layout whose visibility block never closes is malformed. TBS cannot
	 * resolve the stray marker, so the merge must refuse rather than hand the
	 * renderer a document with a marker in it.
	 */
	public function test_a_visibility_block_that_never_closes_is_an_error() {
		$out = $this->merge(
			'[onshow;block=begin;bloc=lista]<p>dentro</p>',
			array( 'lista' => array( array( 'x' => 1 ) ) )
		);

		$this->assertWPError( $out );
		$this->assertSame( 'documentate_pdf_merge_error', $out->get_error_code() );
	}

	/**
	 * A field value cannot smuggle a TinyButStrong parameter into the document.
	 *
	 * The engine's `file=` parameter reads any path with a plain `fopen()` and
	 * splices the contents in. Rich fields are injected verbatim so their markup
	 * survives, which is exactly what makes them a place to try it: a user with
	 * no more than the area role types the marker into a text field. Protection
	 * turns the opening bracket into an entity, so the marker is inert, and the
	 * writer's DOM parse decodes it again, so a bracketed word still prints.
	 */
	public function test_a_field_value_cannot_read_a_file_off_the_server() {
		$secret = wp_tempnam( 'documentate-probe' );
		file_put_contents( $secret, 'TOP-SECRET-VALUE' );

		$out = $this->merge(
			'<div>[cuerpo;strconv=no]</div>',
			array( 'cuerpo' => '<p>Nota [sic] y [onshow;file=' . $secret . '] al final.</p>' )
		);

		unlink( $secret );

		$this->assertIsString( $out );
		$this->assertStringNotContainsString( 'TOP-SECRET-VALUE', $out );
		$this->assertStringContainsString( 'sic', $out );
	}

	/**
	 * No shipped layout disables bracket protection.
	 *
	 * `strconv=no` is what a rich field needs; `protect=no` is what would make
	 * the marker above live. They are one word apart, so the guard is a test
	 * rather than a comment.
	 */
	public function test_no_shipped_layout_turns_protection_off() {
		foreach ( glob( Documentate_Pdf_Layout::dir() . '*.html' ) as $layout ) {
			$this->assertStringNotContainsString(
				'protect=no',
				file_get_contents( $layout ),
				basename( $layout ) . ' must not disable bracket protection.'
			);
		}
	}

	/**
	 * Run a callback with LC_TIME pinned to a known locale.
	 *
	 * Reading the ambient locale instead would let a merge that leaks its own
	 * locale go unnoticed, because the leaked value is what the next test
	 * would read as its baseline.
	 *
	 * @param string   $locale   Locale to pin LC_TIME to.
	 * @param callable $callback Assertions to run under that locale.
	 * @return void
	 */
	private function with_lc_time( $locale, callable $callback ) {
		$ambient = setlocale( LC_TIME, 0 );
		setlocale( LC_TIME, $locale );

		try {
			$callback();
		} finally {
			setlocale( LC_TIME, $ambient );
		}
	}
}
