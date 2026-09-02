# GitHub Copilot Instructions — wp-documentate

> **Full instructions are in [`/AGENTS.md`](/AGENTS.md).** This file repeats the
> non-negotiable rules that Copilot must always follow.

---

## Critical Rules

### Change discipline
- Make **minimal, focused diffs**. Do not refactor unrelated code.
- Do not rename files, classes, hooks, or public APIs unless the task requires it.
- Preserve all existing features and UI unless explicitly asked to remove them.

### Validation — always run before considering a task complete

```bash
make fix                   # auto-format PHP with PHPCBF / WPCS
make lint                  # lint PHP with PHPCS / WPCS       (always required)
make check-plugin          # WordPress plugin-check           (always required)
make test                  # PHPUnit unit tests               (always required)
make test-e2e              # Playwright E2E                   (UI/browser changes)
make check                 # verify only (does not reformat)
make mago-lint             # optional secondary Mago lint
make mago-format           # optional secondary Mago format
```

### Failure policy — a task is NOT done if any of these remain
- Lint errors (`make lint`)
- Plugin-check errors (`make check-plugin`)
- Failing PHPUnit tests (`make test`)
- Failing E2E tests for affected flows (`make test-e2e`)
- Warnings or errors that would break CI

---

## Agent skills

This repository ships skills in `.agents/skills/`, which Copilot loads on demand.
They are installed with `gh skill add` (see `AGENTS.md`). Consult the relevant
one before working in its area:

- `wp-plugin-development` — hooks, activation/uninstall, Settings API, options, cron, packaging
- `wp-rest-api` — `register_rest_route`, `permission_callback`, schema/args, `register_meta`, `show_in_rest`
- `wp-plugin-directory-guidelines` — `readme.txt`, license headers, naming; what `make check-plugin` enforces
- `blueprint` — `blueprint.json` and the Playground preview
- `wp-performance` — backend profiling (WP-CLI profile/doctor, autoload, object cache, cron, HTTP API)
- `wp-project-triage` — inspect what kind of WordPress repo this is before changing tooling
- `wp-plugin-security` — input, output, AJAX/REST, capabilities, files
- `security-audit` — vulnerability hunting and finding validation

They are vendored verbatim from upstream: never reformat or edit them in place.

---

## Key Coding Rules

- **PHP indentation**: tab characters (tab-width = 4), per WordPress Coding Standards and `.editorconfig`.
- **Linter/formatter**: PHPCS / WPCS via `make lint` / `make fix` (canonical). Mago is optional only.
- **Escaping**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- **Sanitising**: `sanitize_text_field()`, `sanitize_textarea_field()`, `absint()`.
- **Unslash** superglobals before sanitising: `wp_unslash( $_POST['field'] )`.
- **Nonces** on all forms and AJAX endpoints.
- **Capability checks** with `current_user_can()` before privileged actions.
- **SQL**: always use `$wpdb->prepare()`.
- **UI text**: Spanish; all code, comments, and docblocks in English.
- La interfaz está en español directamente en el código; no hay i18n ni
  ficheros de traducción.
- **AutoFirma exception**: the intermediate routes in `includes/autofirma/` use
  `permission_callback => '__return_true'` deliberately — AutoFirma is a desktop
  app with no WordPress session, and a 32-char session token authorises them.
  Adding a nonce or capability check there breaks signing. See `AGENTS.md`.

---

## Environment

Requires Docker. Use `make up` to start wp-env before running plugin-check or tests.
See the `Makefile` for the exact commands behind each `make` target.
