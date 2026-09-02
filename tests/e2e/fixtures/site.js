/**
 * Site-level helpers shared by E2E specs: WP-CLI fixtures on the wp-env
 * instance the browser uses, and role logins in a fresh browser context.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

/** Password of every user the specs create. */
const PASSWORD = 'password';

/**
 * Directory used as a cross-process mutex around WP-CLI.
 *
 * `wp-env run` rewrites `~/.wp-env/<env>/wp-env-cache.json` on every call, so
 * two workers invoking it at the same time can leave the file half written and
 * every later call answers "Environment not initialized". Playwright runs spec
 * files in parallel, so the calls are serialised here instead.
 *
 * @type {string}
 */
const LOCK_DIR = path.join( os.tmpdir(), 'documentate-e2e-wp-cli.lock' );

/** How long a lock may be held before it is considered abandoned (ms). */
const LOCK_TTL = 180_000;

/** How long a worker waits for the lock before giving up loudly (ms). */
const LOCK_WAIT = 240_000;

/**
 * Sleep without yielding to the event loop.
 *
 * The WP-CLI helpers are synchronous (they are called from `beforeAll` and
 * from plain helpers), so the wait for the lock has to be synchronous too.
 *
 * @param {number} ms Milliseconds to wait.
 * @return {void}
 */
function sleep( ms ) {
	Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, ms );
}

/**
 * Run a callback while holding the WP-CLI lock.
 *
 * @param {Function} callback What to run.
 * @return {*} Whatever the callback returns.
 */
function withLock( callback ) {
	const deadline = Date.now() + LOCK_WAIT;

	for (;;) {
		try {
			fs.mkdirSync( LOCK_DIR );
			break;
		} catch ( error ) {
			if ( 'EEXIST' !== error.code ) {
				throw error;
			}
			// Only an abandoned lock is taken away: a worker that is merely
			// slow still holds it, and stealing it would put two `wp-env run`
			// calls in flight — the very thing the lock prevents.
			const created = fs.statSync( LOCK_DIR, { throwIfNoEntry: false } );
			if ( ! created || Date.now() - created.mtimeMs > LOCK_TTL ) {
				fs.rmSync( LOCK_DIR, { recursive: true, force: true } );
				continue;
			}
			if ( Date.now() > deadline ) {
				const seconds = Math.round( LOCK_WAIT / 1000 );
				throw new Error(
					`The WP-CLI lock (${ LOCK_DIR }) has been held by another worker for more than ${ seconds } s.`
				);
			}
			sleep( 100 );
		}
	}

	try {
		return callback();
	} finally {
		fs.rmSync( LOCK_DIR, { recursive: true, force: true } );
	}
}

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
/**
 * Run a WP-CLI command and swallow a failure.
 *
 * For clean-up, where the thing being removed may already be gone and the
 * report is more useful without the noise.
 *
 * @param {string} cmd WP-CLI command (without the leading `wp`).
 * @return {string} Trimmed stdout, or an empty string when the call failed.
 */
function runWpCmdSafe( cmd ) {
	try {
		return runWpCmd( cmd );
	} catch ( error ) {
		return '';
	}
}

function runWpCmd( cmd ) {
	try {
		return withLock( () =>
			execSync(
				`npx @wordpress/env run cli --config=.wp-env.docker.json wp ${ cmd }`,
				// wp-env narrates every call on stderr, echoing the whole
				// command back: captured, not inherited, so a fixture built in
				// one round trip does not bury the test report.
				{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
			).trim()
		);
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
			// wp-login.php focuses a field 200 ms after it loads and, on the
			// page a failed attempt lands on (the login prefilled), empties
			// #user_pass as it does so. Filling before that ran would post an
			// empty password and make every retry fail the same way, so the
			// form is only filled once the focus has moved.
			await page
				.waitForFunction(
					() => {
						const field = document.getElementById( 'user_pass' );
						const active =
							field && field.ownerDocument.activeElement;

						return (
							!! active &&
							[ 'user_login', 'user_pass' ].includes( active.id )
						);
					},
					undefined,
					{ timeout: 10_000 }
				)
				.catch( () => {} );
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

/**
 * Bytes of a minimal but structurally actual PDF.
 *
 * `Documentate_App_Attachments::validar()` runs `wp_check_filetype_and_ext()`,
 * which sniffs the content, so the fixture cannot be an arbitrary string.
 *
 * @type {Buffer}
 */
const PDF_FIXTURE = Buffer.from(
	'%PDF-1.4\n' +
		'1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' +
		'2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\n' +
		'trailer<</Root 1 0 R>>\n' +
		'%%EOF'
);

/**
 * PHP that builds a whole spec fixture in one go.
 *
 * The plan travels base64-encoded so nothing has to be quoted twice (shell,
 * then WP-CLI), and the answer is wrapped in markers so a stray PHP notice
 * printed by WordPress cannot corrupt the JSON.
 *
 * @type {string}
 */
const PHP_FIXTURE = `
$plan = json_decode( base64_decode( '__PLAN__' ), true );
$out = array(
	'categories' => array(),
	'types' => array(),
	'users' => array(),
	'documents' => array(),
	'statuses' => array(),
);

foreach ( (array) $plan['categories'] as $key => $def ) {
	$name = is_array( $def ) ? $def['name'] : $def;
	$parent = is_array( $def ) && isset( $def['parent'] ) ? (int) $out['categories'][ $def['parent'] ] : 0;
	$term = wp_insert_term( $name, 'category', array( 'parent' => $parent ) );
	$out['categories'][ $key ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

foreach ( (array) $plan['types'] as $key => $def ) {
	if ( is_array( $def ) ) {
		$term = get_term_by( 'slug', $def['slug'], 'documentate_doc_type' );
		$out['types'][ $key ] = $term ? (int) $term->term_id : 0;
		continue;
	}
	$term = wp_insert_term( $def, 'documentate_doc_type' );
	$out['types'][ $key ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

foreach ( (array) $plan['users'] as $key => $u ) {
	$id = wp_insert_user(
		array(
			'user_login' => $u['login'],
			'user_email' => $u['login'] . '@example.com',
			'user_pass' => $u['pass'],
			'role' => $u['role'],
		)
	);
	$id = is_wp_error( $id ) ? 0 : (int) $id;
	$out['users'][ $key ] = $id;
	if ( $id && isset( $u['scope'] ) ) {
		update_user_meta( $id, 'documentate_scope_term_id', (int) $out['categories'][ $u['scope'] ] );
	}
	if ( $id && ! empty( $u['management'] ) && class_exists( 'Documentate_Roles' ) ) {
		Documentate_Roles::grant_management( $id );
	}
}

foreach ( (array) $plan['documents'] as $key => $d ) {
	$author = isset( $d['author'] ) ? (int) $out['users'][ $d['author'] ] : 1;
	$id = wp_insert_post(
		array(
			'post_type' => 'documentate_document',
			'post_title' => $d['title'],
			'post_status' => 'draft',
			'post_author' => $author > 0 ? $author : 1,
		)
	);
	$id = is_wp_error( $id ) ? 0 : (int) $id;
	$out['documents'][ $key ] = $id;
	if ( ! $id ) {
		continue;
	}

	if ( isset( $d['category'] ) ) {
		wp_set_post_categories( $id, array( (int) $out['categories'][ $d['category'] ] ) );
	}
	if ( isset( $d['type'] ) ) {
		$type = (int) $out['types'][ $d['type'] ];
		wp_set_object_terms( $id, array( $type ), 'documentate_doc_type' );
		update_post_meta( $id, 'documentate_locked_doc_type', (string) $type );
	}
	if ( isset( $d['name'] ) ) {
		update_post_meta( $id, '_documentate_nombre_interno', $d['name'] );
	}

	$status = isset( $d['status'] ) ? $d['status'] : 'draft';
	// Only "en revisión" needs the intermediate stop: a type that goes
	// through gestión documental has no draft -> pending rule, while
	// draft -> publish is a move administración may always make.
	if ( 'pending' === $status ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'en_gestion' ) );
	}
	if ( 'draft' !== $status ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
	}
	$out['statuses'][ $key ] = get_post_status( $id );
}

echo '<<<DOCUMENTATE>>>' . wp_json_encode( $out ) . '<<</DOCUMENTATE>>>';
`;

/**
 * PHP that removes everything a plan created, attachments included.
 *
 * @type {string}
 */
const PHP_CLEANUP = `
$plan = json_decode( base64_decode( '__PLAN__' ), true );

foreach ( (array) $plan['documents'] as $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		continue;
	}
	$attachments = get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'fields' => 'ids' ) );
	foreach ( (array) $attachments as $attachment ) {
		wp_delete_attachment( (int) $attachment, true );
	}
	wp_delete_post( $id, true );
}

foreach ( (array) $plan['users'] as $login ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		wp_delete_user( (int) $user->ID, 1 );
	}
}

foreach ( (array) $plan['categories'] as $id ) {
	wp_delete_term( (int) $id, 'category' );
}

foreach ( (array) $plan['types'] as $id ) {
	wp_delete_term( (int) $id, 'documentate_doc_type' );
}

echo '<<<DOCUMENTATE>>>{"ok":1}<<</DOCUMENTATE>>>';
`;

/**
 * Run PHP on the development site and read back the JSON it printed.
 *
 * One WP-CLI invocation takes about two seconds and every worker queues on the
 * same lock, so a fixture that needs twenty operations must not spend twenty
 * round trips on them.
 *
 * @param {string} php  PHP source with a `__PLAN__` placeholder.
 * @param {Object} plan Data the PHP reads.
 * @return {Object} Decoded answer.
 */
function runPhp( php, plan ) {
	const data = Buffer.from( JSON.stringify( plan ), 'utf8' ).toString(
		'base64'
	);
	const code = Buffer.from(
		php.replace( '__PLAN__', data ),
		'utf8'
	).toString( 'base64' );
	const output = runWpCmd(
		`eval "eval( base64_decode( '${ code }' ) );" --user=1`
	);

	const start = output.indexOf( '<<<DOCUMENTATE>>>' );
	const end = output.indexOf( '<<</DOCUMENTATE>>>' );
	if ( start < 0 || end < start ) {
		throw new Error( `Unexpected WP-CLI answer: ${ output }` );
	}

	return JSON.parse( output.slice( start + 17, end ) );
}

/**
 * Build the categories, document types, users and documents a spec needs.
 *
 * @param {Object}          plan               Fixture plan.
 * @param {Object}          [plan.categories]  Category name, or `{ name, parent }`, by key.
 * @param {Object}          [plan.types]       Type name to create, or `{ slug }` to look up, by key.
 * @param {Object}          [plan.users]    `{ login, role, scope, management }` by key (the password is PASSWORD);
 *                                             `management: true` appoints that account gestión documental.
 * @param {Object}          [plan.documents]  `{ title, category, type, author, status, name }` by key.
 * @return {{categories: Object, types: Object, users: Object, documents: Object, statuses: Object}} Created IDs.
 */
function createFixture( plan ) {
	const full = {
		categories: plan.categories || {},
		types: plan.types || {},
		users: plan.users || {},
		documents: plan.documents || {},
	};

	Object.keys( full.users ).forEach( ( key ) => {
		full.users[ key ] = {
			pass: PASSWORD,
			...full.users[ key ],
		};
	} );

	const done = runPhp( PHP_FIXTURE, full );

	Object.keys( full.documents ).forEach( ( key ) => {
		const requested = full.documents[ key ].status || 'draft';
		const actual = done.statuses[ key ];
		if ( actual !== requested ) {
			throw new Error(
				`Fixture "${ key }" was asked for status "${ requested }" and the site stored "${ actual }".`
			);
		}
	} );

	return done;
}

/**
 * Remove everything createFixture() built, in one WP-CLI round trip.
 *
 * Cleanup is best effort: a failure here must not turn a green run red.
 *
 * @param {Object}   cleanup              What to remove.
 * @param {number[]} [cleanup.documents] Document IDs (their attachments go too).
 * @param {string[]} [cleanup.users]   User logins.
 * @param {number[]} [cleanup.categories] Category term IDs.
 * @param {number[]} [cleanup.types]      Document type term IDs.
 * @return {void}
 */
function removeFixture( cleanup ) {
	const numbers = ( values ) =>
		( values || [] ).map( ( v ) => parseInt( v, 10 ) ).filter( Boolean );

	try {
		runPhp( PHP_CLEANUP, {
			documents: numbers( cleanup.documents ),
			users: ( cleanup.users || [] ).filter( Boolean ),
			categories: numbers( cleanup.categories ),
			types: numbers( cleanup.types ),
		} );
	} catch {
		// Ignore: the entities may already be gone.
	}
}

module.exports = {
	PASSWORD,
	PDF_FIXTURE,
	runWpCmd,
	runWpCmdSafe,
	loginAs,
	createFixture,
	removeFixture,
};
