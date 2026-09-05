# GEMINI.md — Documentate Plugin

> **Full instructions are in [`AGENTS.md`](AGENTS.md).** This file is a short
> summary for Gemini Code Assist.

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
`gh skill add` (Grok Build reads Claude Code skills automatically), covering plugin development, the REST API, the
WordPress.org directory guidelines, Playground blueprints, performance, project
triage, plugin security and security auditing. Read the relevant one before
working in its area, and never reformat or edit a skill in place. Details in
`AGENTS.md`.

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
make check-plugin          # WordPress plugin-check         — always required
make test                  # PHPUnit tests                  — always required
make test-e2e              # Playwright E2E                 — UI/browser changes
make check-untranslated    # translation check              — string changes
make check                 # verify only (does not reformat)
make mago-lint             # optional secondary Mago lint
make mago-format           # optional secondary Mago format
```

A task is **not done** until all relevant checks pass.

---

## Key coding rules

- PHP indentation: **tabs** (WordPress Coding Standards, `.editorconfig`).
- Linter: PHPCS / WPCS via `make lint` / `make fix` (canonical). Mago is optional.
- Escape output, sanitise and unslash input, use nonces, check capabilities.
- UI text in **Spanish**; code, comments, docblocks in **English**.
- Text domain: `documentate`.
- Requires Docker / wp-env for `make check-plugin` and `make test`.
- AutoFirma's intermediate routes use `permission_callback => '__return_true'`
  deliberately — a 32-char session token authorises them, not the WP session.
  See the AutoFirma section of `AGENTS.md` before touching `includes/autofirma/`.

---

## Environment

```bash
make up     # start wp-env (http://localhost:8989, admin/password)
make down   # stop containers
```
