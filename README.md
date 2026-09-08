# Documentate

![CI](https://img.shields.io/github/actions/workflow/status/ateeducacion/wp-documentate/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/ateeducacion/wp-documentate/graph/badge.svg)](https://codecov.io/gh/ateeducacion/wp-documentate)
![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)

**Documentate** is a WordPress plugin for generating official resolutions and structured administrative documents from ODT/DOCX templates, and for running them through an approval workflow between three roles before they're published.

It uses OpenTBS for template merging and draws the PDF natively on the server from an HTML layout, with Collabora Online (server) and LibreOffice WASM (browser) still selectable as alternative PDF engines.

## The document cycle

A document moves through up to four statuses, always forward with an explicit action and always returnable with a reason:

```
Borrador (draft) → [En gestión] → En revisión (pending) → Aprobado (publish) → Archivado
```

`En gestión` only applies to document types that pass through gestión documental (a type-level setting, or automatic whenever the template has a field marked `rol='gestion'`); other types go straight from `Borrador` to `En revisión`. Any forward step can be undone with **Devolver**, which always requires a reason (`motivo`) and shows a "Devuelto" mark and the reason on the document until it's resent.

Three roles share this cycle, detected by capability rather than by a fixed role name:

| Role | Who | Can do |
|---|---|---|
| **Área** | Anyone with `edit_posts` who isn't gestión or admin | Create documents, fill in their own fields, send them on, see only their own scope |
| **Gestión documental** | The `documentate_gestion` role (or any account granted the `documentate_gestionar` capability plus `edit_others_posts`) | Complete the fields marked `rol='gestion'` on documents from every área, pass them to administración or return them |
| **Administración** | `manage_options` | Approve and publish, or return to gestión/área with a reason, archive |

The single source of truth for what each role can do from each status is the rule table in `Documentate_Transitions::rules()` (`includes/class-documentate-transitions.php`) — see `ARCHITECTURE.md` for the full model.

## Demo

Try it in the browser with WordPress Playground (includes sample data; changes are lost when you close the tab):

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-documentate/refs/heads/main/blueprint.json)

It opens the front-end application at `/documentate/`, signed in as `admin`
(administración), with demo documents seeded in every status — draft, en
gestión, devuelto, en revisión, aprobado and archivado. The **Probar como…**
menu in the admin bar (User Switching) jumps to the other demo accounts:
`editor1` (gestión documental, also área for its own scope), `author1` (área)
and `subscriber1` (no access to the app), password `password` for all. The
same menu and a click-to-fill account list on `wp-login.php` are available in
the local wp-env site; both come from the dev-only mu-plugin
`scripts/mu-plugins/documentate-dev-tools.php`, which never ships in the
release ZIP.

`make capturas` walks the whole cycle on desktop with a real
browser and writes an illustrated report to `capturas/informe.html`, plus
`capturas/indice.json`. It creates document 0 (the expenditure proposal) and
an administrative resolution through the UI, follows editing, review and
approval, returns the proposal for correction, and checks PDF/ODT exports.
It also shows the proposal's nested providers and calculated totals.

The **Capturas** workflow runs on PR code changes and can be started manually.
For same-repository PRs it updates one comment with the desktop gallery,
the tested commit and any failed scenes. Images live on a dedicated
`feature/pr-<number>-screenshots` output branch and use public, commit-pinned
URLs; they are not added to the source branch or release ZIP. The HTML report
is downloadable as the `capturas` Actions artifact (30-day retention).
Fork and Dependabot PRs keep the artifact without publishing a comment.
Manual runs also produce only the artifact.

Mobile capture is temporarily opt-in: `make capturas SOLO=movil`. Both local
default runs and the automatic PR workflow capture desktop only.
The script reseeds local demo data, so do not run it alongside E2E tests or
against a production site. A failed scene is captured and reported, and makes
the command fail instead of presenting an incomplete journey as successful.

## Features

- Document types (templates) defined as a custom taxonomy with schema-driven fields
- Three-role approval workflow (área → gestión documental → administración) with
  a "devuelto" (returned, with reason) mark at every step and a full activity log
- Fields by role in the templates: a placeholder marked `rol='gestion'` is only
  shown to, and only saved from, gestión documental / administración
- Generation of ODT/DOCX from templates via OpenTBS
- PDF generation, from one of three engines:
  - **Native PDF rendering** (default): drawn on the server from the HTML layout of the document type
  - **Collabora Online** (server-side): converts the ODT/DOCX template
  - **LibreOffice WASM** in the browser (experimental, client-side)
- Per-user scope filtering (hierarchical categories) for document visibility
- Front-end application under `/documentate/` (bandejas, detail, edit, attachments,
  export) alongside full wp-admin parity
- Revisions, attachments, collaborative editing support
- Multisite compatible

## Installation

1. Download the latest release from the [GitHub Releases page](https://github.com/ateeducacion/wp-documentate/releases).
2. Upload the ZIP via **Plugins → Add New → Upload Plugin**.
3. Activate the plugin.
4. Configure conversion engine and other options under **Settings → Documentate**.

## Development

Requires Docker (wp-env).

```bash
make up             # Start Docker wp-env (http://localhost:8989, admin / password)
make down           # Stop containers
make check          # lint + plugin-check + tests (no auto-fix)
```

See `AGENTS.md` for the full agent/developer instructions and `ARCHITECTURE.md` for system design. `docs/flujo-documentos.md` (Spanish) walks the document cycle and the three roles for the functional team; `docs/campos-por-rol.md` (Spanish) explains the `rol='gestion'` placeholder attribute for whoever edits the ODT templates.

### Working with AI coding agents

`AGENTS.md` is canonical; `CLAUDE.md`, `GEMINI.md` and `.github/copilot-instructions.md` point at it.

Reusable procedures ship as agent skills in `.agents/skills/` and
`.claude/skills/`, installed with `gh skill add`:

| Skill | Read it before |
|-------|----------------|
| `wp-plugin-development` | Hooks, activation/uninstall, Settings API, options, cron, packaging |
| `wp-rest-api` | Routes, `permission_callback`, schema/args, `register_meta`, `show_in_rest` |
| `wp-plugin-directory-guidelines` | `readme.txt`, license headers, naming — what `make check-plugin` enforces |
| `blueprint` | `blueprint.json` and the Playground preview |
| `wp-performance` | Backend profiling (WP-CLI profile/doctor, autoload, object cache, cron, HTTP API) |
| `wp-project-triage` | Inspect what kind of WordPress repo this is before changing tooling |
| `wp-plugin-security` | Input, output, AJAX/REST, capabilities, files |
| `security-audit` | Vulnerability hunting and finding validation |
| `testing` | PHPUnit tests: structure, mocking, data providers, coverage (≥ 90 % here) |

The WordPress ones come from [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills)
(GPL-2.0-or-later), `wp-plugin-security` from
[`fernandotellado/ai-skills`](https://github.com/fernandotellado/ai-skills),
`security-audit` from
[`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill),
`testing` from
[`dr-robert-li/cowork-wordpress-expert`](https://github.com/dr-robert-li/cowork-wordpress-expert) (MIT).
All are vendored verbatim — do not reformat or patch them locally. Add or refresh
with `gh skill add` / `gh skill update --all` (see `AGENTS.md`).

None of it reaches the release ZIP; `.gitattributes` marks it `export-ignore`.

### PHP runtime and tooling

CI and both wp-env configurations use PHP 8.3, matching the deployment runtime.
Composer keeps `require.php >=8.3` and `config.platform.php = 8.3.0` so dependency
updates remain compatible with the whole supported 8.3 series. CI also validates
the lock file and checks the real runtime requirements. After pulling this
change, recreate existing containers with
`npx wp-env start --config=.wp-env.docker.json` before running tests.

`composer lint:syntax` runs PHP's parser over plugin, script and test PHP files. It complements
`make lint` (PHPCS/WPCS and PHPCompatibilityWP), rather than replacing it.
[ADR 0001](docs/adr/0001-php83-and-tooling-cleanup.md) records the tooling decision
and the staged retirement of Collabora, LibreOffice WASM and their Worker.

### Key make targets

| Target                 | Description                                            |
|------------------------|--------------------------------------------------------|
| `make fix`             | Format PHP with PHPCBF / WordPress Coding Standards    |
| `make lint`            | Lint PHP with PHPCS / WordPress Coding Standards       |
| `make check-plugin`    | WordPress plugin-check                                 |
| `make test`            | PHPUnit unit tests                                     |
| `make test-e2e`        | Playwright E2E tests                                   |
| `make capturas`        | Walk the document cycle and write `capturas/informe.html` |
| `make check`           | Full verification suite (does not modify source)       |

### Testing

`make test` runs the PHPUnit suite inside the wp-env `tests-cli` container (MySQL); `make test-e2e` runs the Playwright E2E suite. Both accept `FILE=` / `FILTER=`.

## Document conversion

Selectable under **Settings → Conversion Engine**:

- **Native PDF rendering** (default): draws the PDF in PHP from the HTML layout the document type names. No external service, and no office template is opened.
- **Collabora Online**: server-side web service that converts the rendered ODT/DOCX into a PDF.
- **LibreOffice WASM** (experimental): runs entirely in the browser via [`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter). Large binaries are loaded from a CDN (configurable); requires cross-origin isolation headers (`COOP`/`COEP`). See [`admin/vendor/libreoffice-converter/README.md`](admin/vendor/libreoffice-converter/README.md).

## Adding a PDF layout

A layout is an HTML file in `templates/pdf/`. Its `<head>` states the page
furniture with `<meta name="documentate-*">` values — `letterhead`, `addresses`,
`folio`, `crest`, `margins`, `first-page-margins`, `font`, `font-size` — and its
`<body>` is the document, written with the same TinyButStrong tags as the ODT
template of that document type. Keep the field names identical, or the value
never merges. A rich-text field needs `;strconv=no` so its markup is drawn
rather than escaped, and a repeated table row uses `block=tr`. Do not add
`protect=no`: it would leave a user's own text live as engine markup, and
TinyButStrong's `file=` parameter would then read any path off the server.

A layout reproduces the spacing of its template rather than relying on the
renderer. Paragraphs are set solid, as the `Standard` style of the templates
is, so the blank lines between them are written as empty paragraphs, one per
blank line the template leaves. A table states the width and the cell padding
its template declares — `<table width="165mm" cellpadding="0.49">`, both in
millimetres, a width also accepted as a percentage — and fills the column
without them. A cell states the rest of what its template gives it with
`style`: `border: none` for a cell the template leaves open, `background`
for its `fo:background-color`, and `font-weight` for a heading the template
does not set in bold.

Choose the layout in the document type's **PDF layout** field. A type with none
falls back to `generic.html`, which lists every field with its label.

[`docs/removing-collabora.md`](docs/removing-collabora.md) records what to delete
once the native engine has been proven in production and the converters are
retired.

## Access control

- **Document Types (templates)**: only administrators can create/edit/delete them.
- **Documents**: filtered by a per-user scope category (hierarchical). Administrators see everything. Users without an assigned scope see no documents.

Assign scope under **Users → Edit user → Documentate** section.

## Contextual field help

Schema field definitions support help text before and after the control:

- `before_description`: shown before the input
- `description`: shown after the input (standard behaviour)

Optional styling keys: `before_description_class`, `before_description_style`, `before_description_color`.

## License

GPL-3.0. See [LICENSE.txt](LICENSE.txt).
