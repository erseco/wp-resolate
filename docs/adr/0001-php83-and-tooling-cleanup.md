# ADR 0001: PHP 8.3 and a smaller verification toolchain

- Date: 2026-09-08
- Status: Accepted; runtime pinning and syntax checks implemented in this PR.
  Dependency and converter removals are follow-up work.

## Context

The deployment runs PHP 8.3.33. The repository already declares PHP >=8.3,
resolves Composer dependencies against 8.3.0, and installs PHP 8.3 in the main
CI, screenshot and release jobs. However, neither `.wp-env.json` nor
`.wp-env.docker.json` specified `phpVersion`; the separate PHPMD scanning job
also left its PHP version implicit. Host PHP does not select container PHP.
The local tests container reported 8.3.33 during this review, but that alone
cannot guarantee the next clean CI environment uses the same minor version.

The reference project, `wp-registro-visitas-centros`, uses:

- `.wp-env.json`: PHP 8.3 for WordPress and its tests.
- `.github/workflows/ci.yml`: PHP 8.3, Composer validation, PHPCS and `php -l`.
- `.phpcs.xml.dist`: WordPress Coding Standards and PHPCompatibilityWP.
- `Makefile`: PHPCS/PHPCBF, PHPMD and PHPUnit as separate checks.

Those are project rules and tools, not a syntax validator supplied by wp-env.
Its Composer platform is still 8.1.0, so copying its configuration wholesale
would weaken Documentate's existing PHP 8.3 policy.

## Decision

### Runtime and Composer

Pin both wp-env configurations and the remaining PHPMD job to **8.3**. Keep
setup-php at 8.3 in the existing jobs. CI explicitly checks both `cli` and
`tests-cli` after startup, failing if either is outside the 8.3 series.

Keep `require.php >=8.3`, `config.platform.php = 8.3.0`, the corresponding lock
metadata and PHPCompatibilityWP's `testVersion = 8.3-`. The platform value is a
dependency-resolution floor, not an installed interpreter. Raising it to
8.3.33 could admit a dependency that rejects earlier 8.3 installations while
the plugin still advertises support for them. CI runs `composer validate
--no-check-publish` and `composer check-platform-reqs`; the latter checks the
actual runtime and extensions, ignoring the simulated platform.

Use the maintained 8.3 patch series rather than freeze every environment to
8.3.33. This reproduces the deployment's language version, not its operating
system, FPM configuration, extensions or resource limits. No production
phpinfo dump or session data belongs in the repository.

### Syntax and WordPress rules

Add `composer lint:syntax`, using native `php -l` over plugin, script and test PHP files, and
run it under PHP 8.3 in CI. This catches parse/compile errors without executing
files, including scripts, tests and bundled PHP outside the PHPCS ruleset.
No additional linter package is needed. POSIX `find -exec ... +` also works
inside wp-env without Git installed, includes new unstaged files and propagates
parser failures. PHP 8.3 supports checking multiple files per invocation.

Keep these complementary checks:

| Check | Responsibility |
| --- | --- |
| PHP 8.3 `php -l` | Syntax/compile errors; it does not execute code or resolve runtime behavior |
| PHPCS + WPCS, PHPCompatibilityWP | WordPress conventions, escaping/capability patterns and supported PHP API compatibility |
| PHPCBF | The one canonical automatic formatter |
| PHPMD | The existing complexity budget, with no new baseline entries |
| WordPress plugin-check | Plugin packaging and WordPress.org checks |
| PHPUnit, Jest and Playwright | Behavior, coverage and real browser flows |

This is the useful part of the reference project's approach. Replacing WPCS
with syntax checks would lose WordPress-specific checks. Replacing PHP's
parser with a second formatter would not test the deployed interpreter.
Keep coverage floors and mandatory checks unchanged.

Mago is already optional and is absent from the required CI gate. Remove it
in a focused cleanup PR: its Composer dependency and scripts, Make targets,
`mago.toml`, and documentation references. Recompute the lock file without
upgrading unrelated dependencies; check which transitive packages remain in
use. Do not introduce another formatter as its replacement.

### Wrangler, Collabora and LibreOffice WASM

The desired final PDF path is the native FPDF renderer. Office exports remain
OpenTBS; AutoFirma signing and its intermediate server remain supported.

Wrangler is only a development dependency of `cloudflare-worker/package.json`.
It deploys the Collabora CORS proxy; it is not used by native PDF generation,
PHPUnit, or the main plugin build. It still has a live repository consumer:
`blueprint.json` explicitly selects Collabora and points to the Worker, and
the PR preview workflow reuses that blueprint. Therefore it is a retirement
candidate, but deleting the Worker before migrating the preview would break
that preview's configured PDF path. This review does not establish whether
any deployed WordPress site also uses the proxy.

Use separate, reviewable PRs in this order:

1. **Move the preview to native PDF.** Change the blueprint's engine setting
   and verify demo seeding, document 0 and another document, PDF/ODT export and
   signing behavior. Keep Playground itself: its PHP WebAssembly runtime is
   unrelated to the LibreOffice WASM converter being retired.
2. **Retire LibreOffice WASM.** First verify deployment settings and define a
   tested migration for saved `wasm` selections. Remove its converter, browser
   popup/assets, npm dependency/copy script, postinstall hook portion, Jest
   suite and opt-in scheduled E2E job together. Preserve the AutoFirma build
   in npm hooks and the PDF actions shared by the native path.
3. **Retire Collabora.** Verify each production document type has a suitable
   native layout and migrate saved `collabora` selections. Retain the tested
   native no-network PDF path and all office exports. Relocate the Playground
   detection used by demo seeding before deleting the Collabora class. Remove
   only converter-specific settings, handlers and tests, retaining coverage
   of the shared generation/error paths.
4. **Remove the proxy tooling when it has no consumers.** Delete
   `cloudflare-worker/` (including Wrangler, Vitest and its lock file), the
   Worker Dependabot entry, and obsolete packaging exclusions/references.
   Disabling a deployed Worker is a separate operational step after verifying
   its consumers; deleting repository files does not undeploy it.

Steps 2 and 3 may be separate stages; step 4 can happen as soon as both the
preview and any actual proxy consumers have migrated. Do not remove checks
for converter behavior while that behavior is still shipped. After each
stage run the applicable required checks and verify project/patch coverage
remains at least 90%. Keep collaborative editing, AutoFirma, offline
LibreOffice fixture generation and WordPress Playground.

The older [removal inventory](../removing-collabora.md) is a starting point for
locating code, not an executable checklist: line numbers and translation
commands are stale. This ADR governs sequencing. The current plugin has a
Spanish interface directly in source and no translation build pipeline.

## Consequences

Tests stop depending on wp-env's moving default PHP minor version. Syntax
checks complement the existing WordPress rules with no new dependency.
Compiler and behavior tests remain separate, so passing one never substitutes
for the other. Existing local containers must be recreated with `npx wp-env
start --config=.wp-env.docker.json` after changing the runtime configuration.

The cleanup has an explicit endpoint and order while each intermediate PR
retains working exports. This PR does not remove any converter or deployed
service and does not change the plugin's minimum WordPress version.

## References

- [wp-env configuration and container commands](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
- [Composer platform configuration](https://getcomposer.org/doc/06-config.md#platform)
- [Composer check-platform-reqs](https://getcomposer.org/doc/03-cli.md#check-platform-reqs)
- [PHP CLI options: syntax checking](https://www.php.net/manual/en/features.commandline.options.php)
- [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards)
