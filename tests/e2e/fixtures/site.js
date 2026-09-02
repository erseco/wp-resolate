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
const LOCK_ESPERA = 240_000;

/**
 * Sleep without yielding to the event loop.
 *
 * The WP-CLI helpers are synchronous (they are called from `beforeAll` and
 * from plain helpers), so the wait for the lock has to be synchronous too.
 *
 * @param {number} ms Milliseconds to wait.
 * @return {void}
 */
function dormir( ms ) {
	Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, ms );
}

/**
 * Run a callback while holding the WP-CLI lock.
 *
 * @param {Function} callback What to run.
 * @return {*} Whatever the callback returns.
 */
function conBloqueo( callback ) {
	const limite = Date.now() + LOCK_ESPERA;

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
			const nacido = fs.statSync( LOCK_DIR, { throwIfNoEntry: false } );
			if ( ! nacido || Date.now() - nacido.mtimeMs > LOCK_TTL ) {
				fs.rmSync( LOCK_DIR, { recursive: true, force: true } );
				continue;
			}
			if ( Date.now() > limite ) {
				const segundos = Math.round( LOCK_ESPERA / 1000 );
				throw new Error(
					`The WP-CLI lock (${ LOCK_DIR }) has been held by another worker for more than ${ segundos } s.`
				);
			}
			dormir( 100 );
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
		return conBloqueo( () =>
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
						const campo = document.getElementById( 'user_pass' );
						const activo =
							campo && campo.ownerDocument.activeElement;

						return (
							!! activo &&
							[ 'user_login', 'user_pass' ].includes( activo.id )
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
 * Bytes of a minimal but structurally real PDF.
 *
 * `Documentate_App_Adjuntos::validar()` runs `wp_check_filetype_and_ext()`,
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
const PHP_ESCENARIO = `
$plan = json_decode( base64_decode( '__PLAN__' ), true );
$out = array(
	'categorias' => array(),
	'tipos' => array(),
	'usuarios' => array(),
	'documentos' => array(),
	'estados' => array(),
);

foreach ( (array) $plan['categorias'] as $clave => $def ) {
	$nombre = is_array( $def ) ? $def['nombre'] : $def;
	$padre = is_array( $def ) && isset( $def['padre'] ) ? (int) $out['categorias'][ $def['padre'] ] : 0;
	$term = wp_insert_term( $nombre, 'category', array( 'parent' => $padre ) );
	$out['categorias'][ $clave ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

foreach ( (array) $plan['tipos'] as $clave => $def ) {
	if ( is_array( $def ) ) {
		$term = get_term_by( 'slug', $def['slug'], 'documentate_doc_type' );
		$out['tipos'][ $clave ] = $term ? (int) $term->term_id : 0;
		continue;
	}
	$term = wp_insert_term( $def, 'documentate_doc_type' );
	$out['tipos'][ $clave ] = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
}

foreach ( (array) $plan['usuarios'] as $clave => $u ) {
	$id = wp_insert_user(
		array(
			'user_login' => $u['login'],
			'user_email' => $u['login'] . '@example.com',
			'user_pass' => $u['pass'],
			'role' => $u['rol'],
		)
	);
	$id = is_wp_error( $id ) ? 0 : (int) $id;
	$out['usuarios'][ $clave ] = $id;
	if ( $id && isset( $u['ambito'] ) ) {
		update_user_meta( $id, 'documentate_scope_term_id', (int) $out['categorias'][ $u['ambito'] ] );
	}
	if ( $id && ! empty( $u['gestion'] ) && class_exists( 'Documentate_Roles' ) ) {
		Documentate_Roles::conceder_gestion( $id );
	}
}

foreach ( (array) $plan['documentos'] as $clave => $d ) {
	$autor = isset( $d['autor'] ) ? (int) $out['usuarios'][ $d['autor'] ] : 1;
	$id = wp_insert_post(
		array(
			'post_type' => 'documentate_document',
			'post_title' => $d['titulo'],
			'post_status' => 'draft',
			'post_author' => $autor > 0 ? $autor : 1,
		)
	);
	$id = is_wp_error( $id ) ? 0 : (int) $id;
	$out['documentos'][ $clave ] = $id;
	if ( ! $id ) {
		continue;
	}

	if ( isset( $d['categoria'] ) ) {
		wp_set_post_categories( $id, array( (int) $out['categorias'][ $d['categoria'] ] ) );
	}
	if ( isset( $d['tipo'] ) ) {
		$tipo = (int) $out['tipos'][ $d['tipo'] ];
		wp_set_object_terms( $id, array( $tipo ), 'documentate_doc_type' );
		update_post_meta( $id, 'documentate_locked_doc_type', (string) $tipo );
	}
	if ( isset( $d['nombre'] ) ) {
		update_post_meta( $id, '_documentate_nombre_interno', $d['nombre'] );
	}

	$estado = isset( $d['estado'] ) ? $d['estado'] : 'draft';
	// Only "en revisión" needs the intermediate stop: a type that goes
	// through gestión documental has no draft -> pending rule, while
	// draft -> publish is a move administración may always make.
	if ( 'pending' === $estado ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => 'en_gestion' ) );
	}
	if ( 'draft' !== $estado ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => $estado ) );
	}
	$out['estados'][ $clave ] = get_post_status( $id );
}

echo '<<<DOCUMENTATE>>>' . wp_json_encode( $out ) . '<<</DOCUMENTATE>>>';
`;

/**
 * PHP that removes everything a plan created, attachments included.
 *
 * @type {string}
 */
const PHP_LIMPIEZA = `
$plan = json_decode( base64_decode( '__PLAN__' ), true );

foreach ( (array) $plan['documentos'] as $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		continue;
	}
	$adjuntos = get_children( array( 'post_parent' => $id, 'post_type' => 'attachment', 'fields' => 'ids' ) );
	foreach ( (array) $adjuntos as $adjunto ) {
		wp_delete_attachment( (int) $adjunto, true );
	}
	wp_delete_post( $id, true );
}

foreach ( (array) $plan['usuarios'] as $login ) {
	$usuario = get_user_by( 'login', $login );
	if ( $usuario ) {
		wp_delete_user( (int) $usuario->ID, 1 );
	}
}

foreach ( (array) $plan['categorias'] as $id ) {
	wp_delete_term( (int) $id, 'category' );
}

foreach ( (array) $plan['tipos'] as $id ) {
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
function ejecutarPhp( php, plan ) {
	const datos = Buffer.from( JSON.stringify( plan ), 'utf8' ).toString(
		'base64'
	);
	const codigo = Buffer.from(
		php.replace( '__PLAN__', datos ),
		'utf8'
	).toString( 'base64' );
	const salida = runWpCmd(
		`eval "eval( base64_decode( '${ codigo }' ) );" --user=1`
	);

	const inicio = salida.indexOf( '<<<DOCUMENTATE>>>' );
	const fin = salida.indexOf( '<<</DOCUMENTATE>>>' );
	if ( inicio < 0 || fin < inicio ) {
		throw new Error( `Unexpected WP-CLI answer: ${ salida }` );
	}

	return JSON.parse( salida.slice( inicio + 17, fin ) );
}

/**
 * Build the categories, document types, users and documents a spec needs.
 *
 * @param {Object}          plan               Fixture plan.
 * @param {Object}          [plan.categorias]  Category name, or `{ nombre, padre }`, by key.
 * @param {Object}          [plan.tipos]       Type name to create, or `{ slug }` to look up, by key.
 * @param {Object}          [plan.usuarios]    `{ login, rol, ambito, gestion }` by key (the password is PASSWORD);
 *                                             `gestion: true` appoints that account gestión documental.
 * @param {Object}          [plan.documentos]  `{ titulo, categoria, tipo, autor, estado, nombre }` by key.
 * @return {{categorias: Object, tipos: Object, usuarios: Object, documentos: Object, estados: Object}} Created IDs.
 */
function crearEscenario( plan ) {
	const completo = {
		categorias: plan.categorias || {},
		tipos: plan.tipos || {},
		usuarios: plan.usuarios || {},
		documentos: plan.documentos || {},
	};

	Object.keys( completo.usuarios ).forEach( ( clave ) => {
		completo.usuarios[ clave ] = {
			pass: PASSWORD,
			...completo.usuarios[ clave ],
		};
	} );

	const hecho = ejecutarPhp( PHP_ESCENARIO, completo );

	Object.keys( completo.documentos ).forEach( ( clave ) => {
		const pedido = completo.documentos[ clave ].estado || 'draft';
		const real = hecho.estados[ clave ];
		if ( real !== pedido ) {
			throw new Error(
				`Fixture "${ clave }" was asked for status "${ pedido }" and the site stored "${ real }".`
			);
		}
	} );

	return hecho;
}

/**
 * Remove everything crearEscenario() built, in one WP-CLI round trip.
 *
 * Cleanup is best effort: a failure here must not turn a green run red.
 *
 * @param {Object}   limpieza              What to remove.
 * @param {number[]} [limpieza.documentos] Document IDs (their attachments go too).
 * @param {string[]} [limpieza.usuarios]   User logins.
 * @param {number[]} [limpieza.categorias] Category term IDs.
 * @param {number[]} [limpieza.tipos]      Document type term IDs.
 * @return {void}
 */
function limpiarEscenario( limpieza ) {
	const numeros = ( valores ) =>
		( valores || [] ).map( ( v ) => parseInt( v, 10 ) ).filter( Boolean );

	try {
		ejecutarPhp( PHP_LIMPIEZA, {
			documentos: numeros( limpieza.documentos ),
			usuarios: ( limpieza.usuarios || [] ).filter( Boolean ),
			categorias: numeros( limpieza.categorias ),
			tipos: numeros( limpieza.tipos ),
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
	crearEscenario,
	limpiarEscenario,
};
