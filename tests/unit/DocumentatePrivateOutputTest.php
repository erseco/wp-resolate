<?php
/**
 * Tests for private generated-file storage.
 *
 * @package Documentate
 */

/**
 * Isolated storage fixtures avoid touching actual generated documents.
 */
class DocumentatePrivateOutputTest extends WP_UnitTestCase {

	/** @var string Temporary upload root. */
	private $root;

	/**
	 * Create a private test upload root.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once DOCUMENTATE_PLUGIN_DIR . 'includes/class-documentate-private-output.php';
		$this->root = sys_get_temp_dir() . '/documentate-private-' . wp_generate_password( 12, false );
		mkdir( $this->root );
		add_filter( 'upload_dir', array( $this, 'uploads' ) );
		delete_option( Documentate_Private_Output::OPTION );
	}

	/**
	 * Remove only the isolated test fixtures.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'uploads' ) );
		foreach ( array_merge( glob( $this->root . '/documentate/*' ), glob( $this->root . '/documentate/.*' ) ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->root . '/documentate' ) ) {
			rmdir( $this->root . '/documentate' );
		}
		foreach ( glob( $this->root . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->root );
		parent::tear_down();
	}

	/**
	 * Route only this test's uploads to its isolated root.
	 *
	 * @param array $uploads WordPress upload paths.
	 * @return array
	 */
	public function uploads( $uploads ) {
		$uploads['basedir'] = $this->root;
		$uploads['error']   = false;
		return $uploads;
	}

	/**
	 * Old files are hardened once and their bytes remain available to PHP.
	 */
	public function test_existing_files_are_migrated_and_streamable() {
		mkdir( $this->root . '/documentate' );
		$file = $this->root . '/documentate/old.pdf';
		file_put_contents( $file, '%PDF-private' );
		chmod( $file, 0644 );
		$path = Documentate_Private_Output::directory();
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		$this->assertSame( 0700, fileperms( $path ) & 0777 );
		$this->assertSame( Documentate_Private_Output::RULES, file_get_contents( $path . '/.htaccess' ) );
		$this->assertSame( '', file_get_contents( $path . '/index.html' ) );
		$this->assertSame( '%PDF-private', file_get_contents( $file ) );
		chmod( $file, 0644 );
		Documentate_Private_Output::directory();
		clearstatcache( true, $file );
		$this->assertSame( 0644, fileperms( $file ) & 0777, 'Completed migration does not scan old files again.' );
	}

	/**
	 * Reservation protects bytes before rendering and permits regeneration.
	 */
	public function test_prepare_reserves_owner_only_files_without_truncating() {
		$file = Documentate_Private_Output::directory() . '/new.pdf';
		Documentate_Private_Output::prepare( $file );
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		file_put_contents( $file, 'first' );
		Documentate_Private_Output::prepare( $file );
		$this->assertSame( 'first', file_get_contents( $file ) );
		file_put_contents( $file, 'second' );
		$this->assertSame( 'second', file_get_contents( $file ) );
	}

	/**
	 * Existing symlinks never change permissions on their targets.
	 */
	public function test_symlinks_are_not_followed() {
		mkdir( $this->root . '/documentate' );
		$outside = $this->root . '/outside.pdf';
		file_put_contents( $outside, 'outside' );
		chmod( $outside, 0644 );
		$link = $this->root . '/documentate/link.pdf';
		symlink( $outside, $link );
		Documentate_Private_Output::directory();
		$this->assertSame( 0644, fileperms( $outside ) & 0777 );
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::prepare( $link );
	}

	/**
	 * Unexpected guards are not overwritten or silently treated as protection.
	 */
	public function test_guard_failure_blocks_generation_and_leaves_upgrade_pending() {
		mkdir( $this->root . '/documentate' );
		file_put_contents( $this->root . '/documentate/.htaccess', 'Require all granted' );
		Documentate_Private_Output::upgrade();
		$this->assertFalse( get_option( Documentate_Private_Output::OPTION ) );
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::directory();
	}

	/**
	 * A temporary file a crashed render left behind is eventually swept.
	 *
	 * The reservation carries a random suffix, so a retry never reuses the
	 * name and nothing else in the plugin ever looks at it again: without the
	 * sweep every render killed between prepare() and the rename would leave
	 * one more file in the protected directory for good.
	 */
	public function test_stale_temporary_files_are_swept_and_fresh_ones_kept() {
		$directory = Documentate_Private_Output::directory();
		$stale     = $directory . '/resolucion.pdf.abcd1234.tmp';
		$fresh     = $directory . '/resolucion.pdf.efgh5678.tmp';
		$document  = $directory . '/resolucion.pdf';

		foreach ( array( $stale, $fresh, $document ) as $file ) {
			file_put_contents( $file, 'x' );
		}
		touch( $stale, time() - ( 2 * HOUR_IN_SECONDS ) );

		Documentate_Private_Output::sweep_stale( $directory );

		$this->assertFileDoesNotExist( $stale );
		$this->assertFileExists( $fresh, 'A render running right now is not touched.' );
		$this->assertFileExists( $document, 'Only reservations are swept.' );
	}

	/**
	 * The sweep refuses any directory but the protected one.
	 */
	public function test_the_sweep_refuses_a_foreign_directory() {
		Documentate_Private_Output::directory();
		$outside = $this->root . '/suelto.tmp';
		file_put_contents( $outside, 'x' );
		touch( $outside, time() - ( 2 * HOUR_IN_SECONDS ) );

		Documentate_Private_Output::sweep_stale( $this->root );

		$this->assertFileExists( $outside );
	}

	/**
	 * A caller cannot reserve files outside the protected directory.
	 */
	public function test_outside_path_is_refused() {
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::prepare( $this->root . '/outside.pdf' );
	}

	/**
	 * Preparing a document recreates a guard deleted after the migration.
	 *
	 * `upgrade()` is the one-time pass and does no work once the marker is
	 * current, so repair belongs to the path every generation goes through.
	 */
	public function test_generation_repairs_missing_guards() {
		Documentate_Private_Output::upgrade();
		$guard = $this->root . '/documentate/.htaccess';
		$this->assertNotFalse( get_option( Documentate_Private_Output::OPTION ) );

		unlink( $guard );
		Documentate_Private_Output::upgrade();
		$this->assertFileDoesNotExist( $guard, 'The completed migration does no filesystem work.' );

		Documentate_Private_Output::directory();
		$this->assertSame( Documentate_Private_Output::RULES, file_get_contents( $guard ) );
	}

	/**
	 * A guard somebody else wrote is honoured when it already denies access.
	 *
	 * Security plugins and hosts place their own rules in `uploads/`. Refusing
	 * to run would block every document on the site for no gain.
	 */
	public function test_foreign_guard_that_denies_access_is_kept() {
		mkdir( $this->root . '/documentate' );
		$guard = $this->root . '/documentate/.htaccess';
		file_put_contents( $guard, "# Hardened elsewhere\nDeny from all\n" );
		file_put_contents( $this->root . '/documentate/index.html', '<!-- Silence is golden -->' );

		Documentate_Private_Output::upgrade();

		$this->assertNotFalse( get_option( Documentate_Private_Output::OPTION ), 'Generation is not blocked.' );
		$this->assertSame( "# Hardened elsewhere\nDeny from all\n", file_get_contents( $guard ), 'Their rules are left alone.' );
	}

	/**
	 * A guard that protects nothing still stops generation, and says which.
	 */
	public function test_foreign_guard_without_a_denial_is_refused_by_name() {
		mkdir( $this->root . '/documentate' );
		file_put_contents( $this->root . '/documentate/.htaccess', "# Caching rules only\n" );

		try {
			Documentate_Private_Output::directory();
			$this->fail( 'An unverifiable guard should stop generation.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( '.htaccess', $error->getMessage() );
		}
	}

	/**
	 * A symlinked guard cannot overwrite a file outside the output directory.
	 */
	public function test_symlinked_guard_is_refused() {
		mkdir( $this->root . '/documentate' );
		$outside = $this->root . '/outside';
		file_put_contents( $outside, 'unchanged' );
		symlink( $outside, $this->root . '/documentate/.htaccess' );
		Documentate_Private_Output::upgrade();
		$this->assertSame( 'unchanged', file_get_contents( $outside ) );
		$this->assertFalse( get_option( Documentate_Private_Output::OPTION ) );
	}
	/**
	 * A failed render leaves no empty document behind, and never eats a good one.
	 */
	public function test_discard_removes_only_an_untouched_reservation() {
		$directory = Documentate_Private_Output::directory();
		$empty     = $directory . '/reserva.pdf';
		$written   = $directory . '/anterior.pdf';

		Documentate_Private_Output::prepare( $empty );
		Documentate_Private_Output::prepare( $written );
		file_put_contents( $written, '%PDF-anterior' );

		Documentate_Private_Output::discard( $empty );
		Documentate_Private_Output::discard( $written );

		$this->assertFileDoesNotExist( $empty, 'The reservation a failed render never wrote to is dropped.' );
		$this->assertSame( '%PDF-anterior', file_get_contents( $written ), 'An earlier document survives a later failure.' );
	}

	/**
	 * Nothing outside the output directory is ever unlinked.
	 */
	public function test_discard_refuses_a_path_outside_the_output_directory() {
		Documentate_Private_Output::directory();
		$outside = $this->root . '/suelto.pdf';
		touch( $outside );

		Documentate_Private_Output::discard( $outside );

		$this->assertFileExists( $outside );
	}

}
