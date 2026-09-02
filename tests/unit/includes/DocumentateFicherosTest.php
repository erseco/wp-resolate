<?php
/**
 * Tests for Documentate_Ficheros: which paths the plugin agrees to read.
 *
 * @package Documentate
 */

/**
 * Class DocumentateFicherosTest
 *
 * @covers Documentate_Ficheros
 */
class DocumentateFicherosTest extends WP_UnitTestCase {

	/**
	 * Files created by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $temporales = array();

	/**
	 * Remove whatever a test wrote outside the uploads folder.
	 */
	public function tear_down() {
		foreach ( $this->temporales as $ruta ) {
			if ( file_exists( $ruta ) ) {
				unlink( $ruta );
			}
		}
		$this->temporales = array();

		parent::tear_down();
	}

	/**
	 * Write a file and remember it for the clean-up.
	 *
	 * @param string $ruta      Absolute path.
	 * @param string $contenido File contents.
	 * @return string The path.
	 */
	private function escribir( $ruta, $contenido = 'x' ) {
		$directorio = dirname( $ruta );
		if ( ! is_dir( $directorio ) ) {
			wp_mkdir_p( $directorio );
		}
		file_put_contents( $ruta, $contenido ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->temporales[] = $ruta;

		return $ruta;
	}

	/**
	 * An attachment stored where WordPress puts uploads is served.
	 */
	public function test_an_upload_resolves_to_its_path() {
		$subidas = wp_get_upload_dir();
		$ruta = $this->escribir( trailingslashit( $subidas['basedir'] ) . 'documentate-test/anexo.pdf', '%PDF-1.4' );

		$adjunto_id = self::factory()->attachment->create_object(
			array(
				'file' => $ruta,
				'post_mime_type' => 'application/pdf',
			)
		);

		$this->assertSame( realpath( $ruta ), Documentate_Ficheros::ruta_de_adjunto( $adjunto_id ) );
	}

	/**
	 * A row pointing outside the uploads folder is refused, however it got there.
	 */
	public function test_a_path_outside_the_uploads_folder_is_refused() {
		$fuera = $this->escribir( get_temp_dir() . 'documentate-fuera.txt' );

		$adjunto_id = self::factory()->attachment->create_object(
			array(
				'file' => $fuera,
				'post_mime_type' => 'text/plain',
			)
		);
		update_post_meta( $adjunto_id, '_wp_attached_file', $fuera );

		$this->assertSame( '', Documentate_Ficheros::ruta_de_adjunto( $adjunto_id ) );
		$this->assertSame( '', Documentate_Ficheros::ruta_dentro_de_subidas( $fuera ) );
	}

	/**
	 * Traversal out of the uploads folder does not come back in.
	 */
	public function test_traversal_out_of_the_uploads_folder_is_refused() {
		$fuera = $this->escribir( get_temp_dir() . 'documentate-traversal.txt' );
		$subidas = wp_get_upload_dir();

		$this->assertSame(
			'',
			Documentate_Ficheros::ruta_dentro_de_subidas(
				trailingslashit( $subidas['basedir'] ) . '../../' . basename( $fuera )
			)
		);
	}

	/**
	 * A sibling directory whose name merely starts like the uploads one is not it.
	 */
	public function test_a_lookalike_sibling_directory_is_not_the_uploads_folder() {
		$subidas = wp_get_upload_dir();
		$gemelo = $this->escribir( rtrim( $subidas['basedir'], '/' ) . '-otra-cosa/anexo.pdf' );

		$this->assertSame( '', Documentate_Ficheros::ruta_dentro_de_subidas( $gemelo ) );

		// Clean the directory the fixture created next to uploads.
		unlink( $gemelo );
		rmdir( dirname( $gemelo ) );
		$this->temporales = array();
	}

	/**
	 * Nothing, a directory or a missing file all resolve to nothing.
	 */
	public function test_what_is_not_a_readable_file_resolves_to_nothing() {
		$subidas = wp_get_upload_dir();

		$this->assertSame( '', Documentate_Ficheros::ruta_dentro_de_subidas( '' ) );
		$this->assertSame( '', Documentate_Ficheros::ruta_dentro_de_subidas( $subidas['basedir'] ) );
		$this->assertSame( '', Documentate_Ficheros::ruta_dentro_de_subidas( trailingslashit( $subidas['basedir'] ) . 'no-existe.pdf' ) );
		$this->assertSame( '', Documentate_Ficheros::ruta_de_adjunto( 0 ) );
		$this->assertSame( '', Documentate_Ficheros::ruta_de_adjunto( -3 ) );
	}

	/**
	 * A file name cannot end the Content-Disposition header early.
	 */
	public function test_a_file_name_cannot_break_out_of_the_header() {
		$nombre = Documentate_Ficheros::nombre_para_cabecera( "anexo\r\nX-Injected: 1\".pdf" );

		$this->assertStringNotContainsString( "\r", $nombre );
		$this->assertStringNotContainsString( "\n", $nombre );
		$this->assertStringNotContainsString( '"', $nombre );
		$this->assertStringContainsString( 'anexo', $nombre );
	}

	/**
	 * An ordinary name survives readable: WordPress folds the accents, which
	 * is exactly what it did to the file on disk when it was uploaded.
	 */
	public function test_an_ordinary_name_stays_readable() {
		$this->assertSame( 'acta-de-la-reunion.pdf', Documentate_Ficheros::nombre_para_cabecera( 'acta-de-la-reunión.pdf' ) );
		$this->assertSame( 'propuesta_2026.odt', Documentate_Ficheros::nombre_para_cabecera( 'propuesta_2026.odt' ) );
	}
}
