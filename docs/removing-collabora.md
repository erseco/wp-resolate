# Retiring Collabora and the LibreOffice WASM converter

The plugin draws PDFs natively by default.
`Documentate_Document_Generator::generate_pdf()` hands the post to
`Documentate_Pdf_Generator::generate()`, which renders an HTML layout with FPDF
on the server. No conversion service is involved in producing a PDF that way.

Collabora Online and the in-browser LibreOffice WASM converter are still shipped
and still selectable, on purpose. The `conversion_engine` setting carries three
values (`fpdf`, the default and native, plus `collabora` and `wasm`) and
`generate_pdf()` branches on it, so a site that hits a layout problem can fall
back without a release. This document is the shopping list for the day that
safety net is no longer wanted.

**Nothing here is urgent.** Do not start until the conditions in the first
section hold.

---

## 1. When to do it

All three must be true:

- **Every document type has an HTML layout.** A type with no layout falls back to
  the generic one; that is acceptable for a while, but a deliberate layout per
  type is the signal that the native path is really in use. Check the
  `documentate_type_pdf_layout` term meta (`Documentate_Pdf_Layout::META_KEY`, at
  `includes/pdf/class-documentate-pdf-layout.php:32`) on every term of the
  `documentate_doc_type` taxonomy on the production site, not just on the demo
  data.
- **No production site has `conversion_engine` set to `collabora` or `wasm`.**
  Read `documentate_settings` on each site: an absent key means the default,
  which is already the native engine. A site that stored one of the other two
  values chose it deliberately, and loses PDF generation the day that engine is
  removed.
- **The native engine has run a full document cycle in production without
  incident** — draft through área, gestión documental and administración, ending
  in a signed PDF — and no one has switched a site back to Collabora to get a
  document out.

Section 2.2 sets out what `generate_pdf()` looks like before you start, and what
of it survives.

---

## 2. Two things that will bite you

Read this section before deleting anything. Both are cases where code that looks
like conversion code is not.

### 2.1 `is_playground()` is environment logic living in the Collabora class

`Documentate_Collabora_Converter::is_playground()` is defined at
`includes/class-documentate-collabora-converter.php:51-80` (docblock at 51-55,
body at 56-80). It detects WordPress Playground by constant, site URL and request
header. It has nothing to do with converting documents, and it has six callers:

| Caller | Survives removal? |
| --- | --- |
| `includes/class-documentate-demo-data.php:57`, inside `should_allow_demo_seeding()` (method at 52-62) | **Yes — this is the trap** |
| `includes/class-documentate-conversion-manager.php:152` | No, file is deleted |
| `includes/class-documentate-admin-helper.php:586` | No, the converter popup route goes |
| `includes/class-documentate-admin-helper.php:787`, in `resolve_conversion_capabilities()` | No, method goes |
| `includes/class-documentate-admin-helper.php:1363`, in `add_conversion_mode_config()` | No, method goes |
| `admin/class-documentate-admin-settings.php:118`, in `conversion_engine_render()` | No, field goes |

Only the first one outlives the removal, and it is load-bearing:
`should_allow_demo_seeding()` decides whether demo data — including the demo
login accounts — may be created. It returns `true` inside Playground and on any
non-production `wp_get_environment_type()`. Its own callers are
`documentate.php:82`, `includes/class-documentate-autofirma.php:322` and
`includes/class-documentate-demo-data.php:684`.

**Relocate `is_playground()` before you delete the Collabora class.** Move it to
`Documentate_Demo_Data` (it is the only remaining consumer) and drop the
`class_exists()` guard plus the `require_once` of the Collabora converter that
`should_allow_demo_seeding()` currently performs at
`includes/class-documentate-demo-data.php:53-55`. Copy the method body verbatim,
including the `phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized`
comment above the `$_SERVER` header check; without it `make lint` fails.

If you delete the class first, demo seeding fatals on a fresh Playground boot,
and the failure surfaces as "the Playground preview is blank" rather than as
anything to do with Collabora.

`tests/unit/includes/DocumentateDemoDataTest.php:141`
(`test_should_allow_demo_seeding_in_playground`) covers this. It must still pass,
against the relocated method.

### 2.2 `generate_pdf()` branches, and only one branch is native

`Documentate_Document_Generator::generate_pdf()`
(`includes/class-documentate-document-generator.php:124`) reads the engine and
splits: `fpdf` goes to `Documentate_Pdf_Generator::generate()`, anything else to
`convert_to_pdf()` (line 152), which renders the office template through
`render_pdf_source()` (line 185) and hands it to
`Documentate_Conversion_Manager::convert()`.

Removing the converters means deleting that second branch, `convert_to_pdf()`
and `render_pdf_source()` with it, and letting `generate_pdf()` call the native
generator unconditionally. Keep the `try`/`catch` around it: the export handlers
and the AJAX endpoint write their answer into a download or a JSON body, and
`test_a_throwable_from_anywhere_becomes_a_wp_error` in
`tests/unit/includes/pdf/DocumentatePdfGeneratorTest.php` holds that line.

**The cross-format office fallback is already gone.** `generate_docx()`
(line 31) and `generate_odt()` (line 59) render their own template or return
`documentate_template_missing`. Neither converts, and neither mentions the
conversion manager any more. Option A of what used to be a product decision here
was taken when the engine became a setting, so nothing is left to decide: a
document type offers the editable download in the format it has a template for,
and in no other.

---

## 3. Files to delete

Verified present at the time of writing.

**PHP**

- `includes/class-documentate-conversion-manager.php`
- `includes/class-documentate-collabora-converter.php` — **only after** relocating
  `is_playground()` (section 2.1)
- `includes/class-documentate-libreoffice-wasm-converter.php`
- `admin/documentate-converter-template.php`
- `admin/documentate-collabora-playground-template.php`

**JavaScript and vendored assets**

- `admin/js/documentate-libreoffice-wasm.js` — loaded only from
  `admin/documentate-converter-template.php:32`, which is itself deleted
- `admin/vendor/libreoffice-converter/` (whole directory). Only `README.md` in it
  is tracked; `dist/` and `wasm/` are generated by the copy script and ignored, so
  a plain `git rm` will leave them behind on disk. Remove the directory from the
  working tree too, or the next `make package` will ship them.
- `scripts/copy-libreoffice-converter.mjs`

**Separate deployment**

- `cloudflare-worker/` (whole directory) — the Collabora proxy used by the
  Playground demo. If it is deployed on a Cloudflare account, tear that down too;
  deleting the directory does not undeploy it.

---

## 4. PHP to edit

### 4.1 `includes/class-documentate-admin-helper.php`

This file is not on the delete list and carries the largest share of the
conversion code. Remove:

- The `admin_post_documentate_converter` action registered at line 111, and the
  `render_converter_page()` method it points at (starts at line 540). That method
  is the only reason the plugin sends COOP/COEP headers, and the only thing that
  includes the two converter popup templates.
- `resolve_conversion_capabilities()` (starts at line 771) and its callers in
  `build_actions_state()` (starts at line 727). The `ready` / `use_popup` /
  `needs_popup_base` triple collapses: with no converter, nothing is ready and
  there is no popup. Its first branch already returns that collapsed answer for
  the native engine, and is what the whole method becomes.
- `add_conversion_mode_config()` (starts at line 1355) entirely, and the call to
  it at the end of `build_actions_script_config()` (starts at line 1269). Delete
  the three `require_once` lines for the conversion manager and the two
  converters at the top of that method.
- The `loadingWasm`, `convertingBrowser` and `wasmError` strings in
  `get_actions_script_strings()` (lines 1311-1313).
- `uses_native_pdf_engine()` (starts at line 760) and its three callers, in
  `build_actions_state()`, `build_pdf_message()` and
  `add_conversion_mode_config()`. With one engine left the question always has
  the same answer.
- `build_pdf_message()` (starts at line 849) keeps only its first branch: no
  template, the message; a template, the empty string. The `$can_convert`
  argument goes with the rest.
- `own_format_state()` (starts at line 879) needs no change at all. It already
  offers a format only when the document type has a template in it, and asks
  nothing about conversion.

### 4.2 `admin/class-documentate-admin-settings.php`

Remove the four settings, their labels, their render callbacks and their
validators. `conversion_engine` now offers three radios, `fpdf` first and
preselected on a site that never chose one; the whole field goes, since a single
engine needs no picker.

| Setting key | Render callback | Validator |
| --- | --- | --- |
| `conversion_engine` | `conversion_engine_render()` (line 113) | `validate_conversion_settings()` (line 309) |
| `collabora_base_url` | `collabora_base_url_render()` | `validate_collabora_settings()` (line 326) |
| `collabora_lang` | `collabora_lang_render()` | `validate_collabora_settings()` (line 326) |
| `collabora_disable_ssl` | `collabora_disable_ssl_render()` | `validate_collabora_settings()` (line 326) |

Both validators go entirely. Also drop the four label entries in the
settings-labels array (lines 81-84) and the `validate_collabora_settings()` call
in the main `validate()` chain (line 296). Keep `validate_autofirma_settings()`
and `validate_collaborative_settings()`.

**Do not touch `collaborative_enabled` or `collaborative_signaling`.** Those are
the Yjs real-time editor and are unrelated despite the similar name.

Leave the stored option keys alone in the database. There is no migration to
write: unknown keys in `documentate_settings` are simply ignored once nothing
reads them.

### 4.3 `includes/class-documentate-document-generator.php`

Delete `convert_to_pdf()` and `render_pdf_source()`, and with them the only
`require_once .../class-documentate-conversion-manager.php` left in the file, at
the top of `generate_pdf()`. `generate_docx()` and `generate_odt()` already end
at their template-missing error and need no edit. See section 2.2.

### 4.4 `documentate.php`

Delete both constants and the comment block above the second one:

- `DOCUMENTATE_COLLABORA_DEFAULT_URL` (lines 33-35)
- `DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL` and its explanatory comment (lines 37-43)

Removing the second one also retires the
`documentate_libreoffice_wasm_binary_base_url` filter that the comment
documents. It is a public extension point, so mention it in the changelog.

---

## 5. JavaScript

### 5.1 `admin/js/documentate-actions.js`

Delete these functions:

| Function | Starts at |
| --- | --- |
| `loadPdfJs()` | 158 |
| `showPdfViewer()` | 191 |
| `initConverterChannel()` | 455 |
| `cleanupConverterPopup()` | 494 |
| `handleConversionSuccess()` | 508 |
| `handleCdnConversion()` | 558 |
| `handleCollaboraPlaygroundConversion()` | 611 |

`showPdfViewer()` has exactly one caller, at line 693, inside
`handleCollaboraPlaygroundConversion()`. It is the pdf.js preview overlay for
browser-side conversion and goes with it. Confirm no new caller has appeared
before deleting.

In `handleActionClick()` (starts at line 719), remove the `cdnMode` and
`sourceFormat` reads at lines 723-724 and the two dispatch branches at lines
747-748 (`config.collaboraPlayground`) and 753-754 (`cdnMode`).

### 5.2 `admin/css/documentate-actions.css`

Delete lines 264-387, from the `/* PDF Viewer Modal */` comment through the last
rule, `.documentate-pdf-viewer__page-info`. That block runs to the end of the
file. It styles only the overlay `showPdfViewer()` built.

### 5.3 `assets/js/documentate-autofirma.js`

This is the **source**; `admin/js/documentate-autofirma.js` and its `.map` are
build output. Never edit the built file by hand.

Remove:

- The `HTMLAnchorElement.prototype.click` monkey patch — the saved native
  reference at line 15 and the override at lines 334-348. It exists to intercept
  the download that browser-side conversion triggers from a popup. With no popup
  there is nothing to intercept, and leaving a prototype patch in place for no
  reason is the kind of thing that breaks an unrelated plugin two years later.
  The saved reference is used only by the patch itself (line 347), so both go
  together.
- The `pendingBrowserSignature` module variable at line 16. Every read and write
  of it is inside the code above.
- `inheritPdfConversionAttributes()` (line 255) and its call at line 388, which
  copies `data-documentate-cdn-mode` and `data-documentate-source-format` onto
  the sign button.
- `usesBrowserConversion()` (line 299) and its call at line 363.
- `prepareBrowserSignature()` (line 313), called at line 364, together with its
  120-second watchdog timer at line 326.

**Keep `signGeneratedPdf()`** (line 230). The removed code is one of its two
callers; the other, at line 381, is the ordinary server-side signing path and is
the whole point of the file.

Then rebuild:

```
npm run build:autofirma
```

Commit the regenerated `admin/js/documentate-autofirma.js` and
`admin/js/documentate-autofirma.js.map`. They are tracked.

---

## 6. Build and packaging

- **`package.json`** — remove the `@matbee/libreoffice-converter` dependency
  (line 6) and the `copy:libreoffice-converter` script (line 20). The `postinstall`
  and `postupdate` scripts (lines 22-23) chain it with `build:autofirma`; rewrite
  both as just `npm run build:autofirma`. Refresh `package-lock.json` with
  `npm install`.
- **`Makefile`** — delete the `package-assets` target (lines 314-325) and the
  `$(MAKE) package-assets` line inside `package` (line 333). Update the comment
  inside `package` that mentions "the WASM glue" (around line 343).
- **`.gitignore`** — delete the LibreOffice WASM block, lines 46-54.
- **`.distignore`** — delete `/cloudflare-worker` (line 83) and its comment (line
  82), and update the comment on line 6 that mentions the WASM glue under
  `admin/vendor/`.
- **`.gitattributes`** — delete `/cloudflare-worker export-ignore` (line 22).
- **`.github/dependabot.yml`** — delete the npm ecosystem entry for
  `/cloudflare-worker`, lines 39-47, comment header included.
- **`linter-baseline.toml`** — delete **two** `[[issues]]` blocks, not one: the
  `str-contains` entry naming `includes/class-documentate-collabora-converter.php`
  (line 28) and the `no-sprintf-concat` entry naming
  `includes/class-documentate-conversion-manager.php` (line 34). Leave the
  `no-nested-ternary` entry for `includes/class-documentate-admin-helper.php`
  alone unless
  your edits happen to remove that ternary, in which case the baseline count must
  come down too or Mago will complain about an unmatched baseline entry.
- **`.github/workflows/release.yml`** — the comment at line 45 explains why Node
  is set up. Node is still needed for `build:autofirma`, so keep the step and
  reword the comment.
- **`.github/codeql/codeql-config.yml`** — line 9 names the LibreOffice WASM
  converter bundle among the paths excluded from analysis. Drop that from the
  exclusion once the bundle is gone.

---

## 7. Playground

`blueprint.json` line 59 is a final `runPHP` step that writes
`conversion_engine` and `collabora_base_url` into `documentate_settings`,
pointing at the Cloudflare worker proxy. Delete the whole step.

`.github/workflows/playground-preview.yml` line 20 has a comment listing what the
blueprint sets up, including the "Collabora proxy". Update the wording.

After this, confirm the Playground preview still boots and still seeds demo data
— that is the check that the section 2.1 relocation actually worked.

---

## 8. Tests to delete

- `tests/unit/includes/DocumentateConversionManagerTest.php`
- `tests/unit/includes/DocumentateCollaboaConverterTest.php` (note the typo in the
  filename, it is spelled that way in the repo)
- `tests/unit/includes/DocumentateLibreofficeWasmConverterTest.php`
- `tests/unit/admin/DocumentateConverterTemplateTest.php`
- `tests/unit/admin/DocumentateConverterTemplateRenderTest.php`
- `tests/js/documentate-libreoffice-wasm.test.js`
- `tests/e2e/specs/wasm-conversion.spec.js`

Tests to **edit**, not delete — each still covers behaviour that survives:

- `tests/unit/admin/DocumentateAdminSettingsTest.php` — drop the engine and
  Collabora field cases, keep the rest of the settings coverage.
- `tests/unit/includes/DocumentateAdminHelperTest.php` — drop the conversion
  capability and converter page cases.
- `tests/unit/includes/DocumentateActionsMetaboxStateTest.php` — the metabox
  state no longer depends on a converter being available; rewrite the
  expectations rather than deleting the file. Its `set_conversion()` helper and
  the three cases that drive the popup (`test_browser_wasm_engine_enables_popup_conversion`,
  `test_source_format_is_not_flagged_for_popup_conversion` and
  `test_native_engine_never_flags_the_browser_popup`) go with the converters;
  everything about which format is offered survives untouched.
- `tests/unit/includes/DocumentatePdfEngineSelectionTest.php` — **delete it.**
  Its whole subject is the choice between the three engines. Before deleting,
  move `test_native_engine_renders_the_pdf_without_any_http_request` somewhere
  that survives: it is the only test asserting that generating a PDF reaches no
  network at all, and that assertion is the point of the removal.
- `tests/unit/includes/DocumentateDocumentGeneratorConversionTest.php` — **delete
  it.** Every case in it pins the engine to Collabora and asserts the conversion
  request; nothing in it outlives the engine.
- `tests/unit/includes/DocumentateGenerateDocumentAjaxTest.php` — remove the
  `@covers Documentate_Conversion_Manager` annotation at line 11.
- `tests/unit/includes/DocumentateDemoDataTest.php` — repoint the Playground case
  at the relocated `is_playground()`.
- `tests/e2e/page-objects/settings.page.js` — remove `engineOptions`,
  `collaboraOption`, `wasmOption`, `collaboraUrlInput`, `selectCollabora()`,
  `selectWasm()`, `setCollaboraUrl()`, `getCollaboraUrl()`, `hasEngineOptions()`,
  `expectCollaboraSelected()`, `expectWasmSelected()` and `expectCollaboraUrl()`.
- `tests/e2e/specs/settings.spec.js` — four of its tests drive those accessors
  (`settings page shows conversion engine options`, `can select conversion
  engine`, `can configure Collabora base URL`, and the persistence test at line
  79, which round-trips the Collabora URL). Rewrite the persistence test against a
  setting that still exists; delete the other three.

Coverage must stay at or above 90 percent, project and patch. Deleting a tested
class removes covered lines from both sides of the ratio, so the number should
hold, but check it rather than assuming.

---

## 9. Translations

Every removed `__()` string still has an entry in `languages/documentate.pot` and
a translation in `languages/documentate-es_ES.po` — around twenty of them, from
"Collabora Online URL" to "Collabora Online returned HTTP code %d during
conversion". Regenerate rather than hand-editing:

```
composer check-untranslated
```

That chains `make-pot`, the two `pot-remove-*` cleanups, `update-po`,
`untranslated`, `make-mo` and `make-php`, so it rebuilds the `.pot`, prunes the
obsolete `.po` entries and refreshes `languages/documentate-es_ES.mo` and
`languages/documentate-es_ES.l10n.php`. Commit all four language files.

Run this **after** the PHP edits, not before — `make-pot` scans the PHP sources
for the strings.

---

## 10. Documentation to update

Easy to forget, and the reason someone later reinstalls a Collabora server that
nothing talks to:

- `README.md` — lines 11, 24-25 and 98-99 describe the two engines.
- `readme.txt` — lines 17, 23 and 42. This one is user-facing on WordPress.org.
- `ARCHITECTURE.md` — lines 9 and 29-34 document the conversion layer as a
  component. Replace with the native PDF pipeline.
- `AGENTS.md` — lines 18 and 332.
- `CLAUDE.md` line 12 and `GEMINI.md` line 12, both of which say "optionally
  Collabora / ZetaJS for format conversion".
- `docs/autofirma.md` line 5 — **already inaccurate today.** It claims the PDF for
  signing is converted through Collabora or LibreOffice WASM; since the native
  renderer landed, it comes from `Documentate_Pdf_Generator`. Fix it whether or
  not you do the rest of this removal.
- Delete this file, `docs/removing-collabora.md`, last.

---

## 11. Final check

Run this from the repository root, once everything above is done and the
translations have been regenerated:

```
grep -rn -i "collabora\|wasm\|convert-to" . \
  --exclude-dir=node_modules --exclude-dir=.git --exclude-dir=vendor \
  --exclude-dir=admin/vendor
```

Afterwards this should return **only** the following, all unrelated:

1. **`collaborative_*`** — the Yjs real-time editor: the `collaborative_enabled`
   and `collaborative_signaling` settings, `admin/js/documentate-collaborative-editor.js`,
   `admin/css/documentate-collaborative-editor.css`. Similar name, different
   feature. Never remove these.
2. **The English words "collaborator" and "collaboration"** — a test helper
   docblock repeated in `tests/unit/includes/custom-post-types/` and a comment at
   `admin/class-documentate-admin.php:435` about Yjs handling collaboration.
3. **`--convert-to`** at `tests/fixtures/templates/generate-templates.php:231` —
   a LibreOffice **command line** used to build test fixtures offline. It is not
   Collabora and not the WASM converter. Leave it.

Anything else is a leftover. Two that catch people out:

- **This file.** `docs/removing-collabora.md` matches on nearly every line.
  Delete it as the last commit of the removal, then re-run the grep.
- **`languages/`.** If the `.pot` or `.po` still match, section 9 was skipped or
  was run before the PHP edits.

Then run the full suite:

```
make fix
make lint
make phpmd
make check-plugin
make test
npm run test:unit-js
make test-e2e
```

There is no `npm test` script in this repo; the Jest suite runs through
`test:unit-js`.

and boot the Playground preview once, to confirm demo seeding survived the
`is_playground()` relocation.
