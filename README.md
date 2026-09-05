# Documentate

![CI](https://img.shields.io/github/actions/workflow/status/ateeducacion/wp-documentate/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/ateeducacion/wp-documentate/graph/badge.svg)](https://codecov.io/gh/ateeducacion/wp-documentate)
![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)

**Documentate** is a WordPress plugin for generating official resolutions and structured administrative documents from ODT/DOCX templates.

It uses OpenTBS for template merging and draws the PDF natively on the server from an HTML layout, with Collabora Online (server) and LibreOffice WASM (browser) still selectable as alternative PDF engines.

## Demo

Try it in the browser with WordPress Playground (includes sample data; changes are lost when you close the tab):

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-documentate/refs/heads/main/blueprint.json)

## Features

- Document types (templates) defined as a custom taxonomy with schema-driven fields
- Generation of ODT/DOCX from templates via OpenTBS
- PDF generation, from one of three engines:
  - **Native PDF rendering** (default): drawn on the server from the HTML layout of the document type
  - **Collabora Online** (server-side): converts the ODT/DOCX template
  - **LibreOffice WASM** in the browser (experimental, client-side)
- Per-user scope filtering (hierarchical categories) for document visibility
- Workflow, revisions, attachments, collaborative editing support
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
make check          # lint + plugin-check + tests + translations (no auto-fix)
```

See `AGENTS.md` for the full agent/developer instructions and `ARCHITECTURE.md` for system design.

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

The WordPress ones come from [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills)
(GPL-2.0-or-later), `wp-plugin-security` from
[`fernandotellado/ai-skills`](https://github.com/fernandotellado/ai-skills),
`security-audit` from
[`cloudflare/security-audit-skill`](https://github.com/cloudflare/security-audit-skill).
All are vendored verbatim — do not reformat or patch them locally. Add or refresh
with `gh skill add` / `gh skill update --all` (see `AGENTS.md`).

None of it reaches the release ZIP; `.gitattributes` marks it `export-ignore`.

### Key make targets

| Target                 | Description                                            |
|------------------------|--------------------------------------------------------|
| `make fix`             | Format PHP with PHPCBF / WordPress Coding Standards    |
| `make lint`            | Lint PHP with PHPCS / WordPress Coding Standards       |
| `make mago-lint`       | Optional secondary Mago lint (may be removed)          |
| `make mago-format`     | Optional secondary Mago format (may be removed)        |
| `make check-plugin`    | WordPress plugin-check                                 |
| `make test`            | PHPUnit unit tests                                     |
| `make test-e2e`        | Playwright E2E tests                                   |
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
