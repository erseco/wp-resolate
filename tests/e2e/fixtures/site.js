/**
 * Site-level helpers shared by E2E specs: WP-CLI fixtures on the wp-env
 * instance the browser uses, and role logins in a fresh browser context.
 */
const { execSync } = require( 'child_process' );

/** Password of every user the specs create. */
const PASSWORD = 'password';

/**
 * Run a WP-CLI command on the development environment (the site the browser
 * uses, port 8989).
 *
 * wp-env prints progress decorations to stderr, so stdout is the clean command
 * output (e.g. a `--porcelain` id).
 *
 * @param {string} cmd WP-CLI command (without the leading `wp`).
 * @return {string} Trimmed stdout.
 */
function runWpCmd( cmd ) {
	try {
		return execSync(
			`npx @wordpress/env run cli --config=.wp-env.docker.json wp ${ cmd }`,
			{ encoding: 'utf8' }
		).trim();
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error(
			`Error executing WP-CLI command: ${ cmd }`,
			error.stdout,
			error.stderr
		);
		throw error;
	}
}

/**
 * Run a WP-CLI command, ignoring failures (used for best-effort cleanup).
 *
 * @param {string} cmd WP-CLI command (without the leading `wp`).
 */
function runWpCmdSafe( cmd ) {
	try {
		runWpCmd( cmd );
	} catch ( e ) {
		// Ignore: the entity may already be gone.
	}
}

/**
 * Log in as the given user in a fresh (cookie-less) browser context.
 *
 * Retries under CI load: concurrent workers can make the first form post slow
 * or leave us on wp-login.php without a clean navigation.
 *
 * @param {import('@playwright/test').Browser} browser  Playwright browser.
 * @param {string}                             baseURL  Base URL for the context.
 * @param {string}                             username User login.
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>} Context and page.
 */
async function loginAs( browser, baseURL, username ) {
	const context = await browser.newContext( { baseURL } );
	const page = await context.newPage();
	let lastError;

	for ( let attempt = 1; attempt <= 3; attempt++ ) {
		try {
			await page.goto( '/wp-login.php', {
				waitUntil: 'domcontentloaded',
				timeout: 60_000,
			} );
			await page.locator( '#user_login' ).waitFor( { state: 'visible' } );
			await page.fill( '#user_login', username );
			await page.fill( '#user_pass', PASSWORD );

			await Promise.all( [
				page.waitForURL(
					( url ) => ! url.pathname.endsWith( '/wp-login.php' ),
					{ waitUntil: 'domcontentloaded', timeout: 60_000 }
				),
				page.click( '#wp-submit' ),
			] );

			if ( ! page.url().includes( 'wp-login.php' ) ) {
				return { context, page };
			}
			lastError = new Error(
				`Still on wp-login after attempt ${ attempt } for ${ username }`
			);
		} catch ( err ) {
			lastError = err;
		}
		await page.waitForTimeout( 1000 * attempt );
	}

	throw lastError || new Error( `Login failed for ${ username }` );
}

module.exports = { PASSWORD, runWpCmd, runWpCmdSafe, loginAs };
