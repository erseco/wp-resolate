/**
 * Jest configuration for Documentate JavaScript unit tests.
 *
 * Extends the @wordpress/scripts default unit-test config (babel transform for
 * ES modules, jsdom environment) and scopes the run to tests/js.
 *
 * Coverage: the browser modules of the plugin are measured on every run, so a
 * JavaScript change is never invisible the way it was while the suite reported
 * nothing at all. Two things are worth knowing before reading the numbers:
 *
 * - A module only reaches the report when the test loads it through the module
 *   registry (`require`, or `jest.isolateModules` for the IIFEs that must be
 *   re-evaluated per test). A test that evaluates the source with
 *   `new Function( source )` reports 0 % however thorough it is, so tests are
 *   written the first way.
 * - The report is not uploaded to Codecov. Most of admin/js is wp-admin glue
 *   exercised by the Playwright suite, which produces no coverage data, so
 *   folding these files into the 90 % project gate of codecov.yml would say
 *   they are untested when they are not. The floors below are the gate
 *   instead: they fail `npm run test:unit-js` — and with it CI — when the
 *   modules the jest suite owns lose coverage.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	rootDir: '../../',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	collectCoverage: true,
	collectCoverageFrom: [
		'admin/js/documentate-*.js',
		'public/js/*.js',
		'!admin/js/vendor/**',
		// Built from TypeScript and shipped as a bundle; covered upstream.
		'!admin/js/documentate-autofirma.js',
	],
	coverageDirectory: 'artifacts/coverage-js',
	coverageReporters: [ 'lcov', 'text-summary' ],
	coverageThreshold: {
		'./public/js/documentate-app.js': { lines: 88 },
		'./admin/js/documentate-calculos.js': { lines: 90 },
		'./admin/js/documentate-workflow.js': { lines: 84 },
		'./admin/js/documentate-unsaved-changes.js': { lines: 78 },
		'./admin/js/documentate-libreoffice-wasm.js': { lines: 65 },
	},
};
