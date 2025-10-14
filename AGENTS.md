<!-- AGENTS.md -->

# Agents Coding Conventions for Plugin “Resolate”

These are natural-language guidelines for agents to follow when developing the Resolate WordPress plugin.

## Project conventions

- Follow **WordPress Coding Standards**:
  - PHP code: 4 spaces indentation, PSR‑12 style where compatible, proper escaping, sanitization, use WP APIs.
  - Use English for source code (identifiers, comments, docblocks).
  - Write all implementation notes, inline comments, and documentation in English.
  - Use Spanish for user‑facing translations/strings and test assertions to check no untranslated strings remain.
  - Ensure all code passes `phpcs --standard=WordPress` and is auto-fixable with `phpcbf --standard=WordPress` where applicable.
  - Install coding standard tooling with Composer in the project root: `composer require --dev dealerdirect/phpcodesniffer-composer-installer:^1.0 wp-coding-standards/wpcs:^3.0`.
  - After installation, run `vendor/bin/phpcs --standard=WordPress .` to lint and `vendor/bin/phpcbf --standard=WordPress .` to auto-fix violations.

## Testing and development workflow

- Use **TDD** (Test‑Driven Development) with factories to create test fixtures.
- Tests live under `/tests/` and use factory classes.
- Run `phpcs --standard=WordPress` and `phpcbf --standard=WordPress` (or equivalent tooling) before submitting changes; the codebase must stay clean.
- Use `make lint` (PHP lint) and `make fix` (beautifier) to enforce standards.
- Use `make test` to run all unit tests.
- Ensure all PHPUnit test suites pass locally before requesting review.
- Use `make check-untranslated` to detect any untranslated Spanish strings.

## Tooling quick start

- Run `composer install` in the project root to install PHP_CodeSniffer, WordPress Coding Standards, and other developer tools (requires outbound network access).
- Use `./vendor/bin/phpcbf --standard=.phpcs.xml.dist` first to apply automatic fixes, then `./vendor/bin/phpcs --standard=.phpcs.xml.dist` to ensure the codebase is clean.
- Composer scripts mirror these commands: `composer phpcbf` and `composer phpcs` respect the repository ignore list defined in `.phpcs.xml.dist`.
- The `.phpcs.xml.dist` ruleset bundles the WordPress standard, limits scanning to PHP files, enables colorized output, suppresses warnings, and excludes vendor, assets, node_modules, tests/js, wp, tests, and `.composer` directories.
- When working outside the `wp-env` Docker environment, call the binaries from `./vendor/bin/` directly. Inside wp-env, reuse the Make targets (`make fix` and `make lint`) which wrap `phpcbf`/`phpcs` with the same `.phpcs.xml.dist` ruleset path (`wp-content/plugins/resolate/.phpcs.xml.dist`).
- The repository `composer.json` already whitelists the `dealerdirect/phpcodesniffer-composer-installer` plugin and exposes the scripts `composer phpcbf` and `composer phpcs`; these call the local binaries under `./vendor/bin/` with the shared `.phpcs.xml.dist` ruleset, so prefer them to keep tooling consistent.
- Run the beautifier before linting when fixing coding standards violations: `composer phpcbf` (or the equivalent binary invocation) followed by `composer phpcs`.

## Linting workflow checklist

1. Install/update tooling with `composer install` (run once per environment).
2. For automated fixes, execute `composer phpcbf` or `make fix` when inside wp-env.
3. Validate coding standards with `composer phpcs` or `make lint` inside wp-env.
4. Address any reported violations manually, then repeat steps 2 and 3 until clean.
5. Commit only after the lint command returns without errors.

## Environment and tools

- Develop plugin within `@wordpress/env` environment.
- Use Alpine‑based Docker containers if setting up with Docker.
- For Linux commands: assume **Ubuntu Server**.
- On macOS desktop (when relevant): use **Homebrew** to install tools.
- Use `vim` as terminal editor, not `nano`.

## Frontend technologies

- In admin or public UI, use **Bootstrap 5** and **jQuery** consistently.
- Keep frontend assets minimal: enqueue properly via WP APIs, use minified versions.

## Code style and structure

- All PHP functions and methods must have English docblock comments immediately before declaration.
- Prefer simplicity and clarity: avoid overly complex abstractions.
- Load translation strings properly (`__()`, `_e()`), text domain declared in main plugin file.
- Keep plugin bootstrap file small (`resolate.php`), modularize into separate files/classes with specific responsibility.

## Aider-specific usage

- Always load `AGENTS.md` as conventions file: e.g. `/read AGENTS.md` or via config.
- Do not expect Aider to modify `AGENTS.md` or `README.md` contents.
- Use `/ask` mode to plan large changes, then use `/code` or `/architect` to apply.
- Review every diff Aider produces, especially in architect mode before accepting.
- After planning, say “go ahead” to proceed.
- Avoid adding unnecessary files to the chat—add only those being modified.

