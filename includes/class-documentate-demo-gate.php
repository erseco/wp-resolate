<?php
/**
 * The gate that decides whether demo content may exist on this site.
 *
 * Demo seeding creates login accounts with a known password, so the answer
 * must never depend on anything the request carries. It lives apart from
 * Documentate_Demo_Data — which seeds — because a gate is worth reading, and
 * changing, on its own.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit();

/**
 * Class Documentate_Demo_Gate
 */
class Documentate_Demo_Gate {

	/**
	 * Option that arms the seeders (set on activation, read on init).
	 *
	 * @var string
	 */
	const OPTION_SEED = 'documentate_seed_demo_documents';

	/**
	 * Whether demo content may be seeded in the current environment.
	 *
	 * Only facts the server states are trusted: the Playground constant and
	 * the environment type. Documentate_Collabora_Converter::is_playground()
	 * is deliberately NOT reused — it also believes the request header
	 * X-WordPress-Playground and the site URL, which anybody can set, and a
	 * false positive there only picks a conversion engine, while a false
	 * positive here creates accounts.
	 *
	 * @param string|null $environment Environment to evaluate. Only the test
	 *                                 suite passes one: wp_get_environment_type()
	 *                                 caches its answer for the whole request,
	 *                                 so a production site cannot be simulated
	 *                                 any other way.
	 * @return bool
	 */
	public static function is_allowed( $environment = null ) {
		if ( defined( 'WORDPRESS_PLAYGROUND' ) && WORDPRESS_PLAYGROUND ) {
			return true;
		}

		$environment = null === $environment ? wp_get_environment_type() : (string) $environment;

		return 'production' !== $environment;
	}

	/**
	 * The same question, dropping the seed flag when the answer is no.
	 *
	 * The flag travels with the database: a staging dump restored on a
	 * production site would otherwise sit there waiting for one request to
	 * say yes.
	 *
	 * @param string|null $environment Environment to evaluate (tests only).
	 * @return bool
	 */
	public static function allowed_or_disarm( $environment = null ) {
		if ( self::is_allowed( $environment ) ) {
			return true;
		}

		delete_option( self::OPTION_SEED );

		return false;
	}
}
