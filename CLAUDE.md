# CLAUDE.md — Documentate Plugin

> **Full instructions are in [`AGENTS.md`](AGENTS.md).** This file is a short
> summary for Claude Code project memory.

---

## What this project is

**Documentate** is a WordPress plugin (PHP 8.3, wp-env/Docker) that generates
official resolutions and structured documents. Templates are merged with
OpenTBS; the PDF is drawn natively on the server with FPDF from an HTML layout,
and Collabora Online or LibreOffice WASM remain selectable as alternative PDF
engines.

Read `ARCHITECTURE.md` before making significant changes.

---

## Skills

`.agents/skills/` and `.claude/skills/` hold third-party skills installed with
`gh skill add` (Grok Build reads Claude Code skills automatically). Read the relevant one before working in
its area — `wp-plugin-development` (hooks, Settings API, packaging),
`wp-rest-api` (routes, permission callbacks, schema),
`wp-plugin-directory-guidelines` (`readme.txt`, licensing, what
`make check-plugin` enforces), `blueprint` (`blueprint.json`, Playground),
`wp-performance`, `wp-project-triage`, `wp-plugin-security`, `security-audit`,
`testing` (PHPUnit structure, mocking, coverage).
Never reformat or edit a skill in place. Details in `AGENTS.md`.

Coverage must stay **≥ 90 %** (project and patch) with real tests — no
`@codeCoverageIgnore`, no hiding files from `codecov.yml`/`phpunit.xml.dist`,
no assertion-free tests. See *Tests* in `AGENTS.md`.

---

## Critical rules

- Make **small, focused diffs**. No unrelated refactors.
- Do not rename files, classes, hooks, or public APIs unless required.
- Preserve all existing features and UI unless explicitly asked to change them.

---

## Validation commands

```bash
make fix                   # auto-format PHP (PHPCBF / WPCS)
make lint                  # lint PHP (PHPCS / WPCS)       — always required
make phpmd                 # complexity budget vs. baseline — always required
make check-plugin          # WordPress plugin-check         — always required
make test                  # PHPUnit tests (Docker)         — always required
make test-e2e              # Playwright E2E (Docker)        — UI/browser changes
make capturas               # walk the document cycle, write capturas/informe.html
make check                 # verify only (does not reformat)
```

A task is **not done** until all relevant checks pass.

---

## Key coding rules

- PHP indentation: **tabs** (WordPress Coding Standards, `.editorconfig`).
- Linter: PHPCS / WPCS via `make lint` / `make fix` (canonical).
- Escape output, sanitise and unslash input, use nonces, check capabilities.
- UI text in **Spanish**; code, comments, docblocks in **English**.
  Identifiers are English too — file, class, method, property, variable and
  test names (PHPUnit methods, Jest `it()`, Playwright `test()`) — while CSS
  classes, `data-*` attributes, query args and stored keys (meta, options,
  capabilities, `en_gestion`) are contracts and are never renamed for
  language reasons. See *Coding Expectations → Language* in `AGENTS.md`.
- La interfaz está en español directamente en el código; no hay i18n ni
  ficheros de traducción.
- `Documentate_Transitions::rules()` is the single source of truth for
  document statuses/transitions (área → revisión → jefatura de servicio);
  extend that table, never hard-code a status/transition elsewhere. See
  `ARCHITECTURE.md` §3.
- Complexity budget (`phpmd.xml`): cyclomatic 15, NPath 500, method 150
  lines, class 2500 lines, class complexity 100 (fires at `>= 100`).
  `make phpmd` fails on any violation outside `phpmd-baseline.xml` (inherited
  OpenTBS debt) — fix by splitting the method/class, never by growing the
  baseline. See *Complexity budget* in `AGENTS.md`.
- Requires Docker / wp-env for `make check-plugin` and `make test`.
- AutoFirma's intermediate routes use `permission_callback => '__return_true'`
  deliberately — a 32-char session token authorises them, not the WP session.
  See the AutoFirma section of `AGENTS.md` before touching `includes/autofirma/`.

---

## Environment

Docker (wp-env) on port 8989 for tests / check-plugin / WP-CLI.

```bash
make up             # start Docker env (http://localhost:8989, admin/password)
make seed-demo      # crea o completa los datos de ejemplo (lo hace ya `make up`)
make reset-demo     # borra lo que dejan los E2E y deja solo los datos de ejemplo
make down           # stop Docker env
```
