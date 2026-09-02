<?php
/**
 * Tests for Documentate_Files: which paths the plugin agrees to read.
 *
 * @package Documentate
 */

/**
 * Class DocumentateFilesTest
 *
 * @covers Documentate_Files
 */
class DocumentateFilesTest extends WP_UnitTestCase {

	/**
	 * Files created by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Remove whatever a test wrote outside the uploads folder.
	 */
	public function tear_down() {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	/**
	 * Write a file and remember it for the clean-up.
	 *
	 * @param string $path      Absolute path.
	 * @param string $content File contents.
	 * @return string The path.
	 */
	private function write_file( $path, $content = 'x' ) {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * An attachment stored where WordPress puts uploads is served.
	 */
	public function test_an_upload_resolves_to_its_path() {
		$uploads = wp_get_upload_dir();
		$path = $this->write_file( trailingslashit( $uploads['basedir'] ) . 'documentate-test/anexo.pdf', '%PDF-1.4' );

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file' => $path,
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertSame( realpath( $path ), Documentate_Files::attachment_path( $attachment_id ) );
	}

	/**
	 * A row pointing outside the uploads folder is refused, however it got there.
	 */
	public function test_a_path_outside_the_uploads_folder_is_refused() {
		$outside = $this->write_file( get_temp_dir() . 'documentate-fuera.txt' );

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file' => $outside,
				'post_mime_type' => 'text/plain',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', $outside );

		$this->assertSame( '', Documentate_Files::attachment_path( $attachment_id ) );
		$this->assertSame( '', Documentate_Files::path_inside_uploads( $outside ) );
	}

	/**
	 * Traversal out of the uploads folder does not come back in.
	 */
	public function test_traversal_out_of_the_uploads_folder_is_refused() {
		$outside = $this->write_file( get_temp_dir() . 'documentate-traversal.txt' );
		$uploads = wp_get_upload_dir();

		$this->assertSame(
			'',
			Documentate_Files::path_inside_uploads(
				trailingslashit( $uploads['basedir'] ) . '../../' . basename( $outside )
			)
		);
	}

	/**
	 * A sibling directory whose name merely starts like the uploads one is not it.
	 */
	public function test_a_lookalike_sibling_directory_is_not_the_uploads_folder() {
		$uploads = wp_get_upload_dir();
		$twin = $this->write_file( rtrim( $uploads['basedir'], '/' ) . '-otra-cosa/anexo.pdf' );

		$this->assertSame( '', Documentate_Files::path_inside_uploads( $twin ) );

		// Clean the directory the fixture created next to uploads.
		unlink( $twin );
		rmdir( dirname( $twin ) );
		$this->temp_files = array();
	}

	/**
	 * Nothing, a directory or a missing file all resolve to nothing.
	 */
	public function test_what_is_not_a_readable_file_resolves_to_nothing() {
		$uploads = wp_get_upload_dir();

		$this->assertSame( '', Documentate_Files::path_inside_uploads( '' ) );
		$this->assertSame( '', Documentate_Files::path_inside_uploads( $uploads['basedir'] ) );
		$this->assertSame( '', Documentate_Files::path_inside_uploads( trailingslashit( $uploads['basedir'] ) . 'no-existe.pdf' ) );
		$this->assertSame( '', Documentate_Files::attachment_path( 0 ) );
		$this->assertSame( '', Documentate_Files::attachment_path( -3 ) );
	}

	/**
	 * A file name cannot end the Content-Disposition header early.
	 */
	public function test_a_file_name_cannot_break_out_of_the_header() {
		$name = Documentate_Files::header_file_name( "anexo\r\nX-Injected: 1\".pdf" );

		$this->assertStringNotContainsString( "\r", $name );
		$this->assertStringNotContainsString( "\n", $name );
		$this->assertStringNotContainsString( '"', $name );
		$this->assertStringContainsString( 'anexo', $name );
	}

	/**
	 * An ordinary name survives readable: WordPress folds the accents, which
	 * is exactly what it did to the file on disk when it was uploaded.
	 */
	public function test_an_ordinary_name_stays_readable() {
		$this->assertSame( 'acta-de-la-reunion.pdf', Documentate_Files::header_file_name( 'acta-de-la-reunión.pdf' ) );
		$this->assertSame( 'propuesta_2026.odt', Documentate_Files::header_file_name( 'propuesta_2026.odt' ) );
	}
}
