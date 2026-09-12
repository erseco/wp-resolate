# Documentate coverage and complexity

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
  `php -d error_reporting=E_ALL^E_DEPRECATED vendor/bin/phpmd includes/app/class-documentate-app-detail.php text phpmd.xml`
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

