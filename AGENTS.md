# AGENTS.md — Documentate Plugin: Agent Instructions

This is the **canonical instruction file** for all coding agents (GitHub Copilot,
Claude Code, Gemini Code Assist, Codex, Aider, and others) working on this
repository. Other agent files (`CLAUDE.md`, `GEMINI.md`,
`.github/copilot-instructions.md`) point here.

---

## Project Overview

**Documentate** is a WordPress plugin (PHP 8.3, wp-env, Docker) that generates
official resolutions and structured administrative documents. It uses:

- Custom post type `documentate_document`
- Custom taxonomy `documentate_doc_type` (template definitions)
- OpenTBS for ODT/DOCX template merging
- FPDF for native PDF rendering from an HTML layout (the default engine)
- Collabora Online (server-side) / LibreOffice WASM in the browser, still selectable
  (`@matbee/libreoffice-converter`) for optional format conversion
- PHPUnit for unit tests, Playwright for E2E tests
- PHPCS with WordPress Coding Standards for PHP linting and formatting
  (canonical); Mago remains available only as optional secondary tooling
- `wp-env` (Docker) for local WordPress and test environments

Read `ARCHITECTURE.md` before implementing new features or significant changes.

---

## Before Changing Code

- Make **small, focused diffs**. Do not refactor unrelated code.
- Do not rename files, classes, hooks, or public APIs unless the task requires it.
- Preserve all existing features and UI unless explicitly asked to change them.
- Keep documentation and tests aligned with every code change.
- Prefer existing project patterns over introducing new abstractions.
- Follow existing naming, hook, and file-organisation conventions.
- Avoid dead code, speculative abstractions, and broad rewrites.

---

## How to Validate Changes

### Environment setup (requires Docker)

```bash
make up          # Start wp-env Docker containers (http://localhost:8989)
make down        # Stop containers
make clean       # Reset WordPress environment
```

### Full local verification (preferred when Docker is available)

```bash
make check       # Runs: lint -> phpmd -> check-plugin -> test
                 # (verification only; does not modify source files)
```

### Individual commands

| Command                  | What it does                                             |
|--------------------------|----------------------------------------------------------|
| `make fix`               | Auto-fix PHP with PHPCBF / WPCS                         |
| `make lint`              | Lint PHP with PHPCS / WPCS — **always required**         |
| `make phpmd`             | Complexity budget vs. `phpmd-baseline.xml` — **always required** |
| `make mago-format`       | Optional secondary Mago formatter (may be removed)       |
| `make mago-lint`         | Optional secondary Mago lint (may be removed)            |
| `make check-plugin`      | Run WordPress plugin-check — **always required**         |
| `make test`              | Run PHPUnit unit tests — **always required**             |
| `make test-generation`   | PHPUnit generation suite only (OpenTBS/templates)        |
| `make test-coverage`     | PHPUnit with Xdebug coverage (needs `--xdebug=coverage`) |
| `make test-e2e`          | Run Playwright E2E tests against wp-env                  |
| `make test-e2e-wasm`     | LibreOffice-WASM spec (`DOCUMENTATE_E2E_WASM=1`; opt-in) |
| `make test-e2e-visual`   | Playwright with interactive UI                           |
| `make capturas`          | Walk the document workflow with a real browser, write `capturas/informe.html` (re-seeds demo data; `SOLO=escritorio\|movil`; refuses to run alongside a live E2E/capturas job) |

Targeted test runs:

```bash
make test FILTER=MyTestClass      # run tests matching a pattern
make test FILE=tests/unit/Foo.php # run a specific test file
```

---

## When to Run Which Checks

| Situation                                           | Required checks                              |
|-----------------------------------------------------|----------------------------------------------|
| Any PHP change                                      | `make fix`, `make lint`, `make phpmd`, `make test` |
| Any PHP change merged to main                       | also `make check-plugin`                     |
| UI, admin flows, editor flows, or browser behaviour | also `make test-e2e`                         |
| Full pre-merge verification                         | `make check` (covers all of the above)       |
| **Before every `git push` / opening a PR**          | at least `make lint` and `make test`         |

If Docker / wp-env is unavailable, still write code that is designed to pass all
checks, and state clearly which checks could not be run locally.

---

## Failure Policy

A task is **not complete** if any of the following remain:

- Lint errors reported by `make lint`
- Plugin-check errors reported by `make check-plugin`
- Failing PHPUnit tests (`make test`)
- Failing E2E tests relevant to the change (`make test-e2e`)
- Warnings or errors that would break CI (see `.github/workflows/ci.yml`)

---

## Coding Expectations

### PHP

- **Indentation**: tab characters (tab-width = 4), as required by WordPress Coding Standards and
  enforced by `.editorconfig`.
- **Naming**: `snake_case` for functions/variables, `CamelCase` for classes,
  `lowercase-with-hyphens` for file names (e.g. `class-documentate-admin.php`).
- Every function and method must have an English PHPDoc block immediately above it.
- Keep the main plugin file `documentate.php` minimal.
- Each class lives in its own file: `class-documentate-component.php`.
- Admin code -> `admin/`, core logic -> `includes/`, tests -> `tests/`.

### Security (this plugin generates official documents — security is critical)

- Escape output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Sanitize input: `sanitize_text_field()`, `sanitize_textarea_field()`,
  `absint()`, `sanitize_key()`.
- Unslash superglobals before sanitising (e.g. `wp_unslash( $_POST )`).
- Use WordPress nonces for all forms and AJAX endpoints.
- Check capabilities with `current_user_can()` before privileged operations.
- Use `$wpdb->prepare()` — never interpolate variables into SQL.

### PDF layouts

- A layout lives in `templates/pdf/<slug>.html` and is chosen per document type.
- Keep every field name identical to the ODT or DOCX template of that type; the
  schema comes from the office template, so a differing name never merges.
- A `type='html'` field carries `;strconv=no`, or its markup arrives escaped and
  prints as visible tags. Never add `protect=no`: it disables bracket protection,
  which is the only thing stopping a user's field value from being read as engine
  markup — `file=` would then read any path off the server.
- A repeated table row uses `block=tr`. `block=tbs:row` is an OpenTBS alias and
  is not registered for HTML layouts.
- Paragraphs are set solid, matching the `Standard` style the templates use, so
  a layout writes each blank line of its template as an empty paragraph. Read
  the template's own spacing rather than guessing: `fo:margin-*` and
  `fo:line-height` on the paragraph styles, `fo:padding` and `style:width` on
  the table styles, and a leading `<text:line-break/>` also reads as a blank
  line.
- A table declares the width and the cell padding of the template it
  reproduces, in millimetres: `<table width="165mm" cellpadding="0.49">`. The
  width is also accepted as a percentage. Without them a table fills its column
  and takes the default padding.
- A cell declares the rest through `style`: `border: none`, `background:
  #rrggbb` (or `none`) and `font-weight: normal|bold`. A `th` is boxed, filled
  and bold by default, which several templates do not do — read their
  `fo:border`, `fo:background-color` and paragraph style rather than assuming.
- The address furniture is drawn in Helvetica whatever the body font is: every
  template asks for a `swiss` family there.

### Translations

La interfaz está en español directamente en el código; no hay i18n ni ficheros
de traducción.

### PHPDoc

- Every function and method needs an English PHPDoc block.
- Align `@param`/`@return` tags so variable names line up, with at least one
  space after the longest type name. WPCS / plugin-check enforces this as
  `WordPress.Commenting.FunctionComment.SpacingAfterParamType`. PHPCBF may
  not fully fix alignment automatically, so verify by hand.

  ```php
  /**
   * @param string $title  Document title.
   * @param int    $count  Number of revisions.
   * @param array  $extra  Optional metadata.
   * @return WP_Post|WP_Error
   */
  ```

### Complexity budget

- Keep methods small. `phpmd.xml` sets the budget: **cyclomatic complexity 15**,
  **NPath complexity 500**, **method length 150 lines**, **class length 2500
  lines**, **class complexity (WeightedMethodCount) 100** — the class rule
  fires when the class *reaches* the threshold (`>= 100`), not only above it.
- `make phpmd` enforces this and is part of `make check` and of CI
  (`.github/workflows/ci.yml`, right after `make lint`): it runs
  `phpmd.xml` against `includes,admin,public,documentate.php,uninstall.php`
  with `--baseline-file phpmd-baseline.xml` and **fails the build** on any
  violation that isn't already in the baseline. `phpmd-baseline.xml` records
  the debt inherited from the OpenTBS conversion code (mostly
  `includes/class-documentate-opentbs.php`, plus a few other pre-existing
  files) — see the comment at the top of `phpmd.xml`. Nothing new may be
  added to it: shrinking it is fine (simplify the flagged method/class until
  PHPMD stops reporting it, then regenerate with
  `vendor/bin/phpmd ... --update-baseline`); growing it is not — a new
  violation must be fixed by splitting the method or class, never by adding
  it to `phpmd-baseline.xml` or raising a threshold in `phpmd.xml`.
  `.github/workflows/phpmd.yml` is separate and unrelated to this gate: it
  runs the ruleset with `--ignore-violations-on-exit`/`continue-on-error` to
  upload SARIF to code scanning, and it never fails the build.
- The baseline is named `phpmd-baseline.xml`, not `phpmd.baseline.xml`, on
  purpose: PHPMD auto-discovers a file with the latter name next to the
  ruleset and would then apply it to every run, `.github/workflows/phpmd.yml`
  included, and the code-scanning dashboard would stop showing the inherited
  debt. With this name only the commands that pass `--baseline-file` are
  baselined — the gate — while the dashboard keeps reporting the whole
  picture (48 violations today).
- Check a single file or directory by hand with
  `php -d error_reporting=E_ALL^E_DEPRECATED vendor/bin/phpmd includes/app/class-documentate-app-detalle.php text phpmd.xml`
  — no baseline, so everything the file carries shows up. Add
  `--baseline-file phpmd-baseline.xml` to ask the gate's question instead.
- When a method approaches either threshold, extract pure helpers (input
  parsing, authorization checks, response building) instead of disabling the
  rule or raising the threshold.
- A long sequence of `if`/ternary guards multiplies NPath quickly — split
  them into focused private methods with descriptive names.

### Frontend

- Use Bootstrap 5 and jQuery for admin UI.
- Enqueue assets via `wp_enqueue_script()` / `wp_enqueue_style()`.
- Use minified assets in production.

### Tests

- Write tests for new behaviour (TDD preferred). Read the `testing` skill
  before writing them (AAA pattern, one behaviour per test, data providers,
  mocking `pre_http_request`, WordPress factories).
- Tests live in `tests/unit/`; use factory classes from `tests/includes/`.
- Run `make test` to execute the PHPUnit suite inside wp-env;
  `make test-coverage` produces the same report CI sends to Codecov.

#### Coverage: 90 % minimum, no tricks

- Line coverage must stay **at or above 90 %**, both for the whole plugin
  (`project`) and for the lines touched by a PR (`patch`). Codecov enforces
  both thresholds from `codecov.yml`; a PR that drops either below 90 % is
  not ready.
- The number must reflect real tests of real behaviour. **Never** raise it
  by gaming the measurement:
  - no `@codeCoverageIgnore`, `@codeCoverageIgnoreStart/End` or
    `// @codeCoverageIgnore` annotations;
  - no adding files or directories to the `ignore:` list of `codecov.yml` or
    to the `<exclude>` filter of `phpunit.xml.dist` to hide untested code
    (only vendored third-party sources and generated assets belong there);
  - no lowering the thresholds, disabling the Codecov status checks or
    marking them informational;
  - no assertion-free tests, tests that only instantiate a class, or tests
    that mirror the implementation line by line without checking an
    observable outcome (return value, stored data, hook side effect,
    rendered output, thrown exception);
  - no `@covers` annotations pointing at code the test does not exercise.
- If a branch is hard to reach, refactor the code so it becomes testable
  (extract the pure part, inject the dependency) instead of excluding it.
- **JavaScript is measured by jest, not by Codecov.** `npm run test:unit-js`
  collects coverage for `admin/js/documentate-*.js` and `public/js/*.js` and
  fails when a module its suite owns falls below the floor in
  `tests/js/jest.config.js`; the report lands in `artifacts/coverage-js/`.
  A jest test only shows up in that report when it loads the module with
  `require()` (or `jest.isolateModules()` for the IIFEs that need a fresh
  evaluation per test) — `new Function( source )()` reports 0 %. Touching a
  browser module means adding or extending its jest test and, when it gains
  one, its floor.
- Untestable-by-design code (`exit`, `wp_die` with output, external
  processes) is a narrow exception: keep it in the thinnest possible wrapper
  and test everything around it.

---

## Definition of Done

A change is ready when **all** of the following are true:

1. `make lint` passes with no errors.
2. `make check-plugin` passes with no errors.
3. `make test` passes with no failures, and coverage stays at or above 90 %
   (project and patch) without any of the tricks listed under *Tests*.
4. `make test-e2e` passes for the affected flows (if UI/browser behaviour changed).
5. PHPDoc is updated for any modified functions or classes.
6. No unrelated files, classes, or hooks were renamed or removed.

---

## Skills

Recurring procedures live as skills under:

- `.agents/skills/` — GitHub Copilot, Codex, Cursor and the other agents that share this path
- `.claude/skills/` — Claude Code

Grok Build does not need a third copy under `.grok/skills/`: it automatically
reads Claude Code skills alongside `.grok/`
([Skills, Plugins & Marketplaces](https://docs.x.ai/build/features/skills-plugins-marketplaces)).

Install and refresh them with the GitHub CLI (`gh skill add` is an alias of
`gh skill install`). Repeat for each host directory you care about:

```bash
gh skill add WordPress/agent-skills wp-performance --agent github-copilot
gh skill add WordPress/agent-skills wp-performance --agent claude-code
gh skill update --all
```

`gh skill` copies the skill into each host directory and injects source
metadata into the `SKILL.md` frontmatter so later updates work. Older Claude
Code entries remain as **symlinks** into `.agents/skills/`; newer ones are
copies. Do not convert one layout into the other by hand, and never duplicate
a skill by copying `SKILL.md` yourself.

### Skill compatibility

Project compatibility requirements always take precedence over generic skill
recommendations. This plugin supports WordPress 6.1+, while some vendored
WordPress agent skills target WordPress 7.0+.

Do not introduce APIs or behavior that require a newer WordPress version unless
the project minimum version is intentionally being raised in the same change.
When following a skill, verify that every suggested WordPress API is available
in the plugin's supported version range.

| Skill | Read it before | Origin |
| --- | --- | --- |
| `wp-plugin-development` | Touching hooks, activation/uninstall, the Settings API, options, cron or release packaging | [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills), GPL-2.0-or-later |
| `wp-rest-api` | Adding or debugging routes: `register_rest_route`, `permission_callback`, schema/args, `register_meta`, `show_in_rest` | idem |
| `wp-plugin-directory-guidelines` | Editing `readme.txt`, license headers or plugin naming — this is what `make check-plugin` enforces | idem |
| `blueprint` | Editing `blueprint.json` or the Playground preview | idem |
| `wp-performance` | Profiling or improving backend performance (WP-CLI profile/doctor, autoload, object cache, cron, HTTP API) | idem |
| `wp-project-triage` | Inspecting what kind of WordPress repo this is before changing tooling or layout | idem |
| `wp-plugin-security` | Writing or reviewing code that handles input, output, AJAX/REST, capabilities or files | [`fernandotellado/ai-skills`](https://github.com/fernandotellado/ai-skills), GPL-2.0-or-later |
| `security-audit` | Hunting vulnerabilities and validating findings | [`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill) |
| `testing` | Writing or reviewing PHPUnit tests: structure, naming, data providers, mocking, coverage targets | [`dr-robert-li/cowork-wordpress-expert`](https://github.com/dr-robert-li/cowork-wordpress-expert), MIT |

All of them are **third party and vendored verbatim**. Do not reformat or edit
them: diverging from upstream makes `gh skill update` harder. Fix the problem
upstream and re-install instead.

Provenance lives in each `SKILL.md` frontmatter (`metadata.github-repo`,
`github-path`, `github-tree-sha`).

Skills and the agent instruction files are excluded from the release ZIP via
`.gitattributes`.

---

## AutoFirma Integration

`includes/autofirma/` adapts the AutoFirma intermediate-server protocol. Two
invariants there look like bugs and are not:

- **`/documentate/v1/autofirma/intermediate/<token>/{storage,retrieve}` uses
  `permission_callback => '__return_true'` on purpose.** AutoFirma is a desktop
  application; it does not carry the WordPress session cookie, so those routes
  cannot require a nonce or a capability. What authorises them is the 32-char
  opaque token, issued only by
  `/autofirma/intermediate-sessions`, which *does* check `edit_posts`, and which
  expires with its transient. Do not "harden" the token routes with
  `current_user_can()` or a nonce check — that breaks signing outright.
- **The protocol itself lives in `erseco/autofirma-intermediate-server`** and is
  copied into `includes/vendor/autofirma-intermediate-server/` by a Composer
  script. The browser side is `@erseco/autofirma-client`, bundled by
  `npm run build:autofirma`. Do not reimplement either one in this plugin; fix
  it upstream and bump the dependency.

Never introduce a fallback that returns the unsigned document when AutoFirma is
missing or fails. A file that looks signed but is not is worse than an error.
Certificate metadata arriving from JavaScript is untrusted input.

---

## Architecture Reference

Read `ARCHITECTURE.md` for details on:

- Data flow and CPT/taxonomy structure
- OpenTBS document generation pipeline
- Native PDF rendering (`includes/pdf/`, layouts in `templates/pdf/`)
- Conversion engines (Collabora, LibreOffice WASM in the browser)
- Access control and scope filtering
- Roles, statuses and the approval workflow (área → gestión documental → administración)
- Fields by role in templates and the document data model
- The front-end application under `/documentate/`

`Documentate_Transiciones::reglas()` (`includes/class-documentate-transiciones.php`)
is the **single source of truth** for which status a document can move to,
from which status, for which role, and whether a reason is required. Both
wp-admin and the front-end app read it to draw their buttons and to validate
every save — never hard-code a transition or a status label anywhere else;
extend the rule table instead.

---

## Tooling Reference

The canonical PHP linter/formatter is **PHPCS with WordPress Coding Standards**
(`.phpcs.xml.dist`), installed via Composer:

```bash
composer install          # installs PHPCS, WPCS, PHPUnit, optional Mago, …
composer phpcs            # same as: make lint
composer phpcbf           # same as: make fix
```

**Mago** is optional secondary tooling only (not used by CI, `make lint`,
`make fix`, or `make check`). It may be removed later:

```bash
composer mago:lint        # same as: make mago-lint
composer mago:format      # same as: make mago-format
```

Always inspect the `Makefile` to understand exactly what each `make` target runs.

---

## Aider-specific Usage

- Load this file as the conventions file: `/read AGENTS.md`.
- Use `/ask` to plan, then `/code` or `/architect` to apply.
- Review every diff before accepting, especially in architect mode.
