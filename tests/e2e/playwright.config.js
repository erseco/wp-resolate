/**
 * Playwright configuration for Documentate E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

// Docker (port 8989) is the source of truth for E2E, in CI and locally.
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8989';

module.exports = defineConfig( {
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	forbidOnly: !! process.env.CI,
	fullyParallel: false,
	workers: process.env.CI
		? '100%'
		: parseInt( process.env.PLAYWRIGHT_WORKERS || '', 10 ) || 2,
	// One retry is enough with the e2e job sharded: a rerun of a whole shard is
	// expensive, and a test that only passes on the third attempt is a flake
	// worth fixing rather than hiding.
	retries: process.env.CI ? 1 : 0,
	timeout: parseInt( process.env.TIMEOUT || '', 10 ) || 100_000,
	reportSlowTests: null,
	testDir: path.join( __dirname, 'specs' ),
	// The WASM conversion spec downloads the LibreOffice bundle and needs a
	// cross-origin-isolated context, so it is not part of the deterministic CI
	// gate. Opt in with DOCUMENTATE_E2E_WASM=1.
	testIgnore: process.env.DOCUMENTATE_E2E_WASM
		? undefined
		: '**/wasm-conversion.spec.js',
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	snapshotPathTemplate:
		'{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}',
	globalSetup: require.resolve(
		'@wordpress/scripts/config/playwright/global-setup.js'
	),
	use: {
		baseURL: baseUrl,
		headless: true,
		viewport: {
			width: 960,
			height: 700,
		},
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
	},
	webServer: {
		// Only used when no server already answers at `url` (reuseExistingServer).
		command: 'npx wp-env start --config=.wp-env.docker.json',
		url: baseUrl,
		timeout: 120_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
