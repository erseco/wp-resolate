/**
 * E2E Tests for Documentate Roles, Scope Filtering, and Authorization.
 *
 * Verifies that:
 * - Administrator: Can see all documents.
 * - Editor: Can only see documents in their assigned category (and its children).
 * - Author: Can only see their own created documents in their assigned category.
 * - Subscriber: Cannot access the documents list.
 * - Security (object-level + workflow locks):
 *   - Editor cannot self-assign scope on their profile.
 *   - Editor is denied when opening an out-of-scope document by ID.
 *   - Editor cannot change content of a published document (server-side).
 *   - Editor can still open / export in-scope documents.
 *
 * Notes on robustness:
 * - Fixtures are created on the SAME wp-env instance the browser uses. The E2E
 *   suite runs against the development site (`WP_BASE_URL`, port 8989), which
 *   wp-env serves from the `cli` container — so WP-CLI fixtures must target
 *   `cli`, not the `tests-cli` (tests) instance.
 * - Every fixture is suffixed with a unique per-run id so parallel specs and
 *   leftovers from previous runs cannot collide with these assertions.
 * - Documents are located through the admin search (`s=<run id>`) so the
 *   assertions do not depend on list-table pagination.
 * - Non-admin roles are authenticated in a fresh browser context, waiting for
 *   the post-login redirect before navigating (a shared context stays admin).
 */
const { test, expect } = require( '../fixtures' );
const {
	runWpCmd,
	loginAs,
	ensureGestionCap,
	crearEscenario,
	limpiarEscenario,
} = require( '../fixtures/site' );

// Unique id for this run so fixtures never collide across parallel specs/retries.
const RUN = `e2e${ Date.now() }`;

const EDITOR_LOGIN = `${ RUN }editor`;
const AUTHOR_LOGIN = `${ RUN }author`;
const SUBSCRIBER_LOGIN = `${ RUN }subscriber`;

const TITLES = {
	adminParent: `Admin Doc Parent ${ RUN }`,
	adminChild: `Admin Doc Child ${ RUN }`,
	authorParent: `Author Doc Parent ${ RUN }`,
	adminOther: `Admin Doc Other ${ RUN }`,
	adminOtherPublished: `Admin Doc Other Published ${ RUN }`,
	editorDraft: `Editor Draft In Scope ${ RUN }`,
};

/** Spanish/English capability denial body text. */
const PERMISSION_DENIED_RE =
	/You need a higher level of permission|Lo siento, no tienes permiso|Sorry, you are not allowed|Insufficient permissions|Permisos insuficientes|No tienes permiso|no est[aá]s autorizado|Access Denied|Acceso denegado/i;

/**
 * Navigate to the documents list filtered to this run's documents.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function gotoRunDocuments( page ) {
	await page.goto(
		`/wp-admin/edit.php?post_type=documentate_document&s=${ encodeURIComponent(
			RUN
		) }`
	);
}

/**
 * Locator for a document row by its (unique) title.
 *
 * @param {import('@playwright/test').Page} page  Playwright page.
 * @param {string}                          title Document title.
 * @return {import('@playwright/test').Locator} Row title link locator.
 */
function rowByTitle( page, title ) {
	return page.locator( 'a.row-title', { hasText: title } );
}

test.describe( 'Roles and Scope Filtering', () => {
	let escenario;
	let parentCatId = 0;
	let otherCatId = 0;
	let editorId = 0;
	/** @type {{ adminParent: number, adminChild: number, authorParent: number, adminOther: number, adminOtherPublished: number, editorDraft: number }} */
	const docs = {};

	test.beforeAll( async () => {
		// Every worker's WP-CLI calls queue on the same lock, so a hook that
		// waits its turn must not die on the ordinary test budget.
		test.setTimeout( 300_000 );

		// The editor role only reviews other áreas while it carries the
		// gestión documental capability; the plugin grants it on activation,
		// but a site whose roles were edited would fail here in a confusing way.
		ensureGestionCap();

		escenario = crearEscenario( {
			categorias: {
				// Categories: parent -> child, plus an out-of-scope category.
				parent: `Scope Parent ${ RUN }`,
				child: { nombre: `Scope Child ${ RUN }`, padre: 'parent' },
				other: `Other Category ${ RUN }`,
			},
			// Document type so published fixtures stay published under the workflow.
			tipos: { propio: `Scope Doc Type ${ RUN }` },
			// Users (unique logins so parallel runs never collide).
			usuarios: {
				subscriber: { login: SUBSCRIBER_LOGIN, rol: 'subscriber' },
				author: {
					login: AUTHOR_LOGIN,
					rol: 'author',
					ambito: 'parent',
				},
				editor: {
					login: EDITOR_LOGIN,
					rol: 'editor',
					ambito: 'parent',
				},
			},
			documentos: {
				adminParent: {
					titulo: TITLES.adminParent,
					categoria: 'parent',
					tipo: 'propio',
					estado: 'publish',
				},
				adminChild: {
					titulo: TITLES.adminChild,
					categoria: 'child',
					tipo: 'propio',
					estado: 'publish',
				},
				authorParent: {
					titulo: TITLES.authorParent,
					categoria: 'parent',
					tipo: 'propio',
					autor: 'author',
					estado: 'publish',
				},
				// Out of scope and still in its own área: nobody outside it may see it.
				adminOther: {
					titulo: TITLES.adminOther,
					categoria: 'other',
					tipo: 'propio',
				},
				// Out of scope but already published: gestión documental reviews
				// every área, so a document that has left its own is theirs to
				// look at.
				adminOtherPublished: {
					titulo: TITLES.adminOtherPublished,
					categoria: 'other',
					tipo: 'propio',
					estado: 'publish',
				},
				// Editable draft owned by the editor, in-scope (export/open checks).
				editorDraft: {
					titulo: TITLES.editorDraft,
					categoria: 'parent',
					tipo: 'propio',
					autor: 'editor',
				},
			},
		} );

		parentCatId = escenario.categorias.parent;
		otherCatId = escenario.categorias.other;
		editorId = escenario.usuarios.editor;
		Object.assign( docs, escenario.documentos );
	} );

	test.afterAll( async () => {
		// Best-effort cleanup of this run's documents, users and terms.
		test.setTimeout( 300_000 );

		limpiarEscenario( {
			documentos: Object.values( escenario.documentos ),
			usuarios: [ SUBSCRIBER_LOGIN, AUTHOR_LOGIN, EDITOR_LOGIN ],
			categorias: Object.values( escenario.categorias ),
			tipos: Object.values( escenario.tipos ),
		} );
	} );

	test( 'Administrator can see all documents', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'edit.php',
			`post_type=documentate_document&s=${ encodeURIComponent( RUN ) }`
		);

		await expect( rowByTitle( page, TITLES.adminParent ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.adminChild ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.authorParent ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.adminOther ) ).toBeVisible();
		await expect(
			rowByTitle( page, TITLES.adminOtherPublished )
		).toBeVisible();
	} );

	test( 'Editor sees their scope, and as gestión what has left its área', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			await gotoRunDocuments( page );

			// In scope (parent + child), regardless of author.
			await expect( rowByTitle( page, TITLES.adminParent ) ).toBeVisible();
			await expect( rowByTitle( page, TITLES.adminChild ) ).toBeVisible();
			await expect(
				rowByTitle( page, TITLES.authorParent )
			).toBeVisible();

			// Out of scope and still a draft: it belongs to its own área.
			await expect( rowByTitle( page, TITLES.adminOther ) ).toHaveCount(
				0
			);

			// Out of scope but already in the pipeline: an editor carries the
			// gestión documental capability, and reviewing means looking
			// outside your own área.
			await expect(
				rowByTitle( page, TITLES.adminOtherPublished )
			).toBeVisible();
		} finally {
			await context.close();
		}
	} );

	test( 'Author can only see their own documents in their scope', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			AUTHOR_LOGIN
		);

		try {
			await gotoRunDocuments( page );

			// Their own document.
			await expect(
				rowByTitle( page, TITLES.authorParent )
			).toBeVisible();

			// Other people's documents, even in the same scope, are hidden.
			await expect( rowByTitle( page, TITLES.adminParent ) ).toHaveCount(
				0
			);
			await expect( rowByTitle( page, TITLES.adminChild ) ).toHaveCount(
				0
			);
			await expect( rowByTitle( page, TITLES.adminOther ) ).toHaveCount(
				0
			);
			// An author is not gestión: the bypass is not theirs.
			await expect(
				rowByTitle( page, TITLES.adminOtherPublished )
			).toHaveCount( 0 );
		} finally {
			await context.close();
		}
	} );

	test( 'Subscriber cannot access documents list', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			SUBSCRIBER_LOGIN
		);

		try {
			await page.goto(
				'/wp-admin/edit.php?post_type=documentate_document'
			);

			await expect( page.locator( 'body' ) ).toContainText(
				PERMISSION_DENIED_RE
			);
		} finally {
			await context.close();
		}
	} );

	// -------------------------------------------------------------------------
	// Authorization hardening (single login — less flaky under CI workers)
	// -------------------------------------------------------------------------

	test( 'Editor authorization: no self-scope, IDOR denied, published frozen, in-scope export OK', async ( {
		browser,
		baseURL,
	} ) => {
		// One browser login for all authorization checks (profile → IDOR →
		// published freeze → in-scope export). Avoids repeated UI logins that
		// time out under CI parallelism.
		test.setTimeout( 180_000 );

		const originalTitle = TITLES.adminParent;
		const hijackedTitle = `Hijacked ${ RUN }`;

		const publishedStatus = runWpCmd(
			`post get ${ docs.adminParent } --field=post_status`
		).trim();
		expect( publishedStatus ).toBe( 'publish' );

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			// --- 1) Scope field hidden; crafted POST cannot self-assign. ---
			await page.goto( '/wp-admin/profile.php', {
				waitUntil: 'domcontentloaded',
			} );
			await expect(
				page.locator( '#documentate_scope_term_id' )
			).toHaveCount( 0 );
			await expect(
				page.locator( 'input[name="documentate_scope_nonce"]' )
			).toHaveCount( 0 );

			const originalScope = runWpCmd(
				`user meta get ${ editorId } documentate_scope_term_id`
			).trim();
			const cookies = await context.cookies();
			const cookieHeader = cookies
				.map( ( c ) => `${ c.name }=${ c.value }` )
				.join( '; ' );

			await page.request.post( '/wp-admin/profile.php', {
				form: {
					email: `${ EDITOR_LOGIN }@example.com`,
					nickname: EDITOR_LOGIN,
					display_name: EDITOR_LOGIN,
					documentate_scope_nonce: 'forged-nonce',
					documentate_scope_term_id: String( otherCatId ),
					action: 'update',
					user_id: String( editorId ),
					from: 'profile',
					submit: 'Update Profile',
				},
				headers: {
					Cookie: cookieHeader,
					Referer: `${ baseURL }/wp-admin/profile.php`,
				},
				maxRedirects: 0,
				failOnStatusCode: false,
			} );

			const afterScope = runWpCmd(
				`user meta get ${ editorId } documentate_scope_term_id`
			).trim();
			expect( afterScope ).toBe( originalScope );
			expect( afterScope ).toBe( String( parentCatId ) );

			// --- 2) Out-of-scope draft by ID: edit + export denied. ---
			await page.goto(
				`/wp-admin/post.php?post=${ docs.adminOther }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).toContainText(
				PERMISSION_DENIED_RE
			);
			await expect(
				page.locator( '#documentate_title_textarea' )
			).toHaveCount( 0 );

			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_export_odt&post_id=${ docs.adminOther }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).toContainText(
				/Insufficient permissions|Permisos insuficientes|not allowed|no tienes permiso/i
			);

			// The same document once it has left its área is open to gestión.
			await page.goto(
				`/wp-admin/post.php?post=${ docs.adminOtherPublished }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				PERMISSION_DENIED_RE
			);
			// A blank sheet or a redirect would also carry no denial, so the
			// editor screen itself has to be there.
			await expect(
				page.locator( '#documentate_title_textarea' )
			).toBeVisible();

			// --- 3) Published in-scope doc: locked UI + content freeze. ---
			await page.goto(
				`/wp-admin/post.php?post=${ docs.adminParent }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				/Sorry, you are not allowed to edit this item/i
			);
			await expect(
				page.locator( '.documentate-mgmt-message' ).filter( {
					hasText: /locked|bloqueado|read-only|solo lectura/i,
				} )
			).toBeVisible( { timeout: 15_000 } );

			const formPayload = await page.evaluate(
				( { postId, newTitle } ) => {
					const form = document.querySelector( '#post' );
					if ( ! form ) {
						return null;
					}
					const data = {};
					form
						.querySelectorAll(
							'input[name], select[name], textarea[name]'
						)
						.forEach( ( el ) => {
							if ( ! el.name || el.disabled ) {
								return;
							}
							if (
								( el.type === 'checkbox' ||
									el.type === 'radio' ) &&
								! el.checked
							) {
								return;
							}
							data[ el.name ] = el.value;
						} );
					data.post_title = newTitle;
					data.post_ID = String( postId );
					data.action = 'editpost';
					data.post_status = 'publish';
					data.hidden_post_status = 'publish';
					const titleArea = document.querySelector(
						'#documentate_title_textarea'
					);
					if ( titleArea && titleArea.name ) {
						data[ titleArea.name ] = newTitle;
					}
					return data;
				},
				{ postId: docs.adminParent, newTitle: hijackedTitle }
			);
			expect( formPayload ).not.toBeNull();
			expect(
				formPayload._wpnonce || formPayload[ '_wpnonce' ]
			).toBeTruthy();

			await page.request.post( '/wp-admin/post.php', {
				form: formPayload,
				failOnStatusCode: false,
			} );

			const storedTitle = runWpCmd(
				`post get ${ docs.adminParent } --field=post_title`
			).trim();
			expect( storedTitle ).toBe( originalTitle );
			expect( storedTitle ).not.toBe( hijackedTitle );
			expect(
				runWpCmd(
					`post get ${ docs.adminParent } --field=post_status`
				).trim()
			).toBe( 'publish' );

			// --- 4) In-scope draft: open + export/preview cap gate OK. ---
			await page.goto(
				`/wp-admin/post.php?post=${ docs.editorDraft }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).not.toContainText(
				PERMISSION_DENIED_RE
			);
			await expect(
				page.locator( '#documentate_title_textarea' )
			).toBeVisible( { timeout: 15_000 } );
			await expect(
				page.locator( '#documentate_actions' )
			).toBeAttached();

			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_export_odt&post_id=${ docs.editorDraft }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			let bodyText = await page.locator( 'body' ).innerText();
			expect( bodyText ).toMatch( /Invalid nonce|Nonce no v[aá]lido/i );
			expect( bodyText ).not.toMatch(
				/Insufficient permissions|Permisos insuficientes/i
			);

			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_preview&post_id=${ docs.editorDraft }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			bodyText = await page.locator( 'body' ).innerText();
			expect( bodyText ).toMatch( /Invalid nonce|Nonce no v[aá]lido/i );
			expect( bodyText ).not.toMatch(
				/Insufficient permissions|Permisos insuficientes/i
			);
		} finally {
			await context.close();
		}
	} );
} );
