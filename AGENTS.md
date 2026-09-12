# AGENTS.md — Documentate

Documentate generates administrative documents from ODT/DOCX templates and native
PDF layouts. WordPress 6.1+, PHP 8.3+. `documentate_document` is the CPT;
`documentate_doc_type` defines templates. `ARCHITECTURE.md` describes data flow,
permissions, generation engines and the `/documentate/` application.

## Project boundaries

- `documentate.php` stays small; domain logic lives in `includes/`, wp-admin in
  `admin/`, browser assets in `public/` and `admin/js/`.
- `Documentate_Transitions::rules()` is the sole transition policy for admin and
  frontend validation/buttons. Do not duplicate statuses or transition labels.
- Preserve database keys, roles/capabilities, hooks, CSS/DOM identifiers, URL and
  form parameters, Spanish slugs, and list-table column IDs. They are persisted
  or externally consumed contracts, even when newer identifiers are English.
- The UI is Spanish directly in code; there is no translation-catalog workflow.
  Code identifiers, PHPDoc, test names and developer docs are English. Functional
  guides under `docs/` keep their existing language.
- Native PDF is the default engine; Collabora and browser LibreOffice WASM remain
  optional. Use `documentate-generation` for templates/layouts and conversion.
- AutoFirma's opaque-token intermediate routes intentionally lack WordPress
  cookie/nonce authentication; session creation checks `edit_posts`, and expiring
  tokens authorize desktop-client transport. Read `documentate-autofirma` before
  changing these routes. Never substitute an unsigned file after signing fails.

## Verification

`make up` uses the Docker configuration at `.wp-env.docker.json` (8989/8990);
keep it distinct from the lightweight `.wp-env.json` configuration.

- PHP: `make lint`, `make phpmd`, `make test`; `FILE=` / `FILTER=` narrow PHPUnit.
- `make check`: lint, PHPMD, Plugin Check and PHPUnit; it does not run E2E.
- Browser modules: `npm run test:unit-js`; UI flows: `make test-e2e`.
- Browser LibreOffice: `make test-e2e-wasm` is opt-in, not the default E2E suite.
- `make capturas` re-seeds demo data and must not overlap E2E/capturas jobs.
- `make fix` is an explicit formatting mutation, not a required step for a clean diff.

Preserve the 90% project/patch PHP coverage gates and JS module floors. Do not
hide code with ignores/excludes, weaken thresholds, or add assertion-free tests.
The PHPMD baseline may shrink but must not grow; use `phpmd-baseline.xml` only
through the existing gate. [Testing notes](.agents/references/testing.md) explain
coverage attribution and baseline handling.

## Working conventions

- Branches use English names with `feature/` or `hotfix/`; PRs target `main`.
- Follow the repository PHPCS ruleset and current source. English PHPDoc precedes
  functions/methods. Unslash request data before sanitizing; escape at output.
- Check capabilities and resource ownership as well as nonces at write boundaries;
  follow the full caller chain before declaring a deliberately delegated guard missing.
- Read only the domain docs needed by the task. Keep changes focused and report
  what changed, what was verified, and any unresolved check failure concisely.
- Agent guidance/workflow changes need frontmatter, link, provenance and `actionlint`
  checks. Runtime changes need the relevant tests above. Do not weaken CI gates.
- No production deployment, release publication or data mutation is implied by
  a local implementation task. Respect authorization already given in the session.

## Skills

Load only the skill relevant to the task. Local contracts override generic examples.
- [blueprint](.agents/skills/blueprint/SKILL.md): WordPress Playground blueprint JSON.
- [documentate-autofirma](.agents/skills/documentate-autofirma/SKILL.md): Signing sessions and transport.
- [documentate-generation](.agents/skills/documentate-generation/SKILL.md): Office templates, PDF layouts and conversion.
- [github-actions-hardening](.agents/skills/github-actions-hardening/SKILL.md): Author/review GitHub Actions workflows.
- [playwright-cli](.agents/skills/playwright-cli/SKILL.md): Terminal browser exploration; keep the existing test runner.
- [security-audit](.agents/skills/security-audit/SKILL.md): Requested vulnerability audits.
- [testing](.agents/skills/testing/SKILL.md): PHPUnit test design; local coverage rules win.
- [wp-performance](.agents/skills/wp-performance/SKILL.md): Measured backend performance work.
- [wp-plugin-development](.agents/skills/wp-plugin-development/SKILL.md): WordPress hooks, lifecycle and settings.
- [wp-plugin-directory-guidelines](.agents/skills/wp-plugin-directory-guidelines/SKILL.md): Distribution/readme and directory checks.
- [wp-plugin-security](.agents/skills/wp-plugin-security/SKILL.md): WordPress input/output and authorization review.
- [wp-project-triage](.agents/skills/wp-project-triage/SKILL.md): Identify existing WordPress tooling and layout.
- [wp-rest-api](.agents/skills/wp-rest-api/SKILL.md): REST schemas, routes and permissions.

### Skill maintenance

Install upstream skills with `gh skills install OWNER/REPO skills/NAME --dir .agents/skills`.
Keep upstream text and `metadata.github-*` unchanged; fix upstream and reinstall.
Local skills have no GitHub provenance and the updater skips them. Put project
exceptions in local guidance, not inside installed upstream folders.

WordPress skills may target 7.0+: verify APIs against this project's supported
versions. Do not upgrade requirements, scaffold new packages or change architecture
merely because a generic skill recommends it. Resolve example `skills/...` paths
under the actual `.agents/skills/` installation; use existing commands first.

New Claude entries are symlinks to `../../.agents/skills/NAME`.
Preserve existing Claude copies; the workflow updates both host directories.

`.github/workflows/update-agent-skills.yml` checks weekly/on dispatch, scoped to
installed skills, and opens a review PR on `main`. It never merges updates.
Review prompt diffs as behavior changes. PRs made with the default GitHub token
may not trigger CI; do not assume green checks will appear automatically.

Maintainer preference: use `actions/checkout@v7` and
`devantler-tech/actions/update-agent-skills@v13.3.3`; prefer the floating major
`v13` when upstream provides it. Use `peter-evans/create-pull-request@v8` too. Keep all actions in the skill-update workflow on version tags, not SHAs.
