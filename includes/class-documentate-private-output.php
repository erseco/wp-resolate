<?php
/**
 * Filesystem protection for generated documents, served only by gated handlers.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Protects the existing output directory without changing download paths.
 */
class Documentate_Private_Output {

	/** Migration marker, scoped to the site's upload directory. */
	const OPTION = 'documentate_private_output_version';

	/** Apache rules remain readable by the server, unlike document bytes. */
	const RULES = "# Documentate: use the authenticated download endpoints.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

	/** A guard someone else wrote is honoured when it already denies access. */
	const DENIALS = array( 'Require all denied', 'Deny from all' );

	/**
	 * Harden existing installations once, retrying failed upgrades next time.
	 *
	 * Hooked on `admin_init` and gated on the migration marker, so a front-end
	 * request does no filesystem work at all. Every generation re-runs
	 * `directory()` through `ensure_output_dir()`, which is what repairs a
	 * guard someone deleted; this only carries an existing site over once.
	 */
	public static function upgrade() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}
		if ( get_option( self::OPTION ) === self::version( self::path( $uploads ) ) ) {
			return;
		}
		try {
			self::directory();
		} catch ( RuntimeException $error ) {
			// Do not break unrelated pages. Generation retries and fails closed.
			return;
		}
	}

	/**
	 * Output directory for an upload directory, without touching the disk.
	 *
	 * @param array<string,mixed> $uploads Result of `wp_upload_dir()`.
	 * @return string
	 */
	private static function path( array $uploads ) {
		return trailingslashit( $uploads['basedir'] ) . 'documentate';
	}

	/**
	 * Migration marker for a directory, so moving uploads re-runs the pass.
	 *
	 * @param string $path Output directory.
	 * @return string
	 */
	private static function version( $path ) {
		return '1:' . $path;
	}

	/**
	 * Ensure guards and migrate old files once for this upload directory.
	 *
	 * @return string Protected output directory.
	 * @throws RuntimeException When protection cannot be installed.
	 */
	public static function directory() {
		$uploads = wp_upload_dir();
		$path    = self::path( $uploads );
		if ( ! empty( $uploads['error'] ) || is_link( $path ) || ! wp_mkdir_p( $path ) ) {
			self::fail();
		}
		self::mode( $path, 0700 );
		self::guard( $path . '/.htaccess', self::RULES, self::DENIALS );
		self::guard( $path . '/index.html', '', array() );
		$version = self::version( $path );
		if ( get_option( self::OPTION ) !== $version ) {
			self::migrate( $path );
			update_option( self::OPTION, $version, false );
		}
		return $path;
	}

	/**
	 * Reserve an owner-only file before a renderer writes any sensitive bytes.
	 *
	 * @param string $path Local path in the protected output directory.
	 * @throws RuntimeException When the file cannot be protected.
	 */
	public static function prepare( $path ) {
		$directory = self::directory();
		if ( dirname( $path ) !== $directory || is_link( $path ) ) {
			self::fail();
		}
		if ( ! file_exists( $path ) ) {
			// The empty file carries no secret until mode() has succeeded.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive local reservation; warnings must not corrupt downloads.
			$handle = @fopen( $path, 'x' );
			if ( false === $handle ) {
				self::fail();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively reserved local file.
			fclose( $handle );
		}
		if ( ! is_file( $path ) ) {
			self::fail();
		}
		self::mode( $path, 0600 );
	}

	/**
	 * Drop a reservation a failed render never wrote anything into.
	 *
	 * `prepare()` creates the destination empty before the renderer runs, so
	 * an error would otherwise leave a 0-byte document behind. A file that
	 * holds bytes is an earlier, good document and is left alone.
	 *
	 * @param string $path Local path in the protected output directory.
	 */
	public static function discard( $path ) {
		if ( is_link( $path ) || ! is_file( $path ) || 0 !== filesize( $path ) ) {
			return;
		}
		if ( dirname( $path ) !== self::path( wp_upload_dir() ) ) {
			return;
		}
		wp_delete_file( $path );
	}

	/**
	 * Write and verify an access guard; refuse symlinks, honour equivalents.
	 *
	 * Security plugins and hosts routinely place their own `.htaccess` and
	 * index file in `uploads/`. Overwriting their rules would be wrong and
	 * refusing to run would block every document on the site, so a file that
	 * already denies access — or, for the directory index, any file at all —
	 * is accepted as it stands.
	 *
	 * @param string        $path     Guard path.
	 * @param string        $contents Guard contents to write when absent.
	 * @param array<string> $accept   Markers that make foreign contents
	 *                                acceptable; empty accepts any file.
	 */
	private static function guard( $path, $contents, array $accept ) {
		if ( is_link( $path ) ) {
			self::fail( basename( $path ) );
		}
		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed guard in plugin-owned uploads directory, verified below.
			@file_put_contents( $path, $contents );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			self::fail( basename( $path ) );
		}
		$found = (string) file_get_contents( $path );
		if ( $found !== $contents && ! self::accepted( $found, $accept ) ) {
			self::fail( basename( $path ) );
		}
		self::mode( $path, 0644 );
	}

	/**
	 * Whether foreign guard contents already protect the directory.
	 *
	 * @param string        $found  Contents on disk.
	 * @param array<string> $accept Markers, or empty to accept any file.
	 * @return bool
	 */
	private static function accepted( $found, array $accept ) {
		if ( empty( $accept ) ) {
			return true;
		}
		foreach ( $accept as $marker ) {
			if ( false !== stripos( $found, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Restrict existing generated files without following symlinks or subfolders.
	 *
	 * @param string $path Output directory.
	 */
	private static function migrate( $path ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isLink() || ! $entry->isFile() || in_array( $entry->getFilename(), array( '.htaccess', 'index.html' ), true ) ) {
				continue;
			}
			self::mode( $entry->getPathname(), 0600 );
		}
	}

	/**
	 * Apply and verify permissions rather than assuming chmod succeeded.
	 *
	 * @param string $path Validated local path.
	 * @param int    $mode Required POSIX permissions.
	 */
	private static function mode( $path, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Exact local permissions; WP_Filesystem may select a remote transport.
		$changed = @chmod( $path, $mode );
		clearstatcache( true, $path );
		if ( ! $changed || ( fileperms( $path ) & 0777 ) !== $mode ) {
			self::fail();
		}
	}

	/**
	 * Fail closed, naming the guard when one is to blame.
	 *
	 * Only the file name is reported: the message reaches every user allowed
	 * to generate a document, and the server path is not theirs to know.
	 *
	 * @param string $guard File name of the guard that could not be verified.
	 * @throws RuntimeException Always.
	 */
	private static function fail( $guard = '' ) {
		$message = 'No se ha podido guardar el PDF generado.';
		if ( '' !== $guard ) {
			$message .= ' ' . sprintf(
				'No se ha podido verificar la protección «%s» del directorio de salida.',
				$guard
			);
		}
		throw new RuntimeException( esc_html( $message ) );
	}
}
