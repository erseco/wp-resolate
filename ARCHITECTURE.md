# Documentate - Plugin Architecture and AI Context

This document provides a high-level overview of the **Documentate** WordPress plugin's architecture, data flow, and key components. It serves as a guide for AI agents and new developers to understand how the system is built and where to find specific functionality.

## 1. High-Level Purpose

**Documentate** is a WordPress plugin designed to generate official resolutions and structured documents. It uses a custom post type (`documentate_document`) to store document data, which is categorized by a custom taxonomy (`documentate_doc_type`).

The core functionality involves taking structured data entered by users in WordPress and merging it into an `.odt` or `.docx` template using **OpenTBS**. The PDF is drawn natively on the server from an HTML layout; a site may instead select **Collabora Online** (server-side) or **LibreOffice WASM** in the browser (via [`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter)) to produce the PDF by converting the template.

## 2. Core Components

### 2.1. Custom Post Types and Taxonomies

- **`documentate_document` (CPT):** Represents an individual document. The content of the document is stored using the classic editor (Gutenberg is explicitly disabled). Field values are typically stored in the `post_content` using HTML comments as separators to allow for version diffing.
- **`documentate_doc_type` (Taxonomy):** Represents a "Template" or "Document Type". Each document belongs to a specific type. The type defines which `.odt` and `.docx` templates should be used when generating the final file.

### 2.2. Document Generation (OpenTBS)

- Template field and signature detection shares a request-local parser cache,
  keyed by path and SHA-256 of the compressed template. Replacing a template
  invalidates the entry even when its size and modification time are unchanged.
  XML normalization distinguishes ODT `style:*` elements from HTML `style`
  elements to avoid repeated full-document regex scans when opening the editor.
- **Location:** `includes/class-documentate-document-generator.php` and `includes/class-documentate-opentbs.php`.
- **Flow:**
  1. User triggers a document generation (e.g., clicking "Preview" or "Export" in the admin UI).
  2. The system fetches the attached `.odt` template for the selected `documentate_doc_type`.
  3. The `Documentate_OpenTBS` wrapper uses the `tbs_class` and `tbs_plugin_opentbs` libraries to merge WordPress post data (title, content, author, custom fields) into the `.odt` template placeholders.
  4. The result is a generated `.odt` file.

### 2.3. PDF Rendering (native)

- **Location:** `includes/pdf/`, layouts in `templates/pdf/`.
- **Flow:**
  1. `Documentate_Pdf_Layout::for_post()` resolves the layout the document type names in the `documentate_type_pdf_layout` term meta, falling back to `generic.html`. A layout is an HTML file whose `<head>` carries `<meta name="documentate-*">` values for the page furniture: `letterhead`, `addresses`, `folio`, `crest`, `margins`, `first-page-margins`, `font` and `font-size`.
  2. `Documentate_Pdf_Merger` merges the document's fields into that HTML with TinyButStrong, using the same tags the ODT template uses. A rich-text field carries `strconv=no` so its markup is injected verbatim, but never `protect=no`: bracket protection is what keeps a user's own text from being read as engine markup, and the writer's DOM parse decodes the entity again so a bracketed word still prints. Tags the schema does not answer are cleared before the merge, so a bracketed word a user typed is never mistaken for one.
  3. `Documentate_Pdf_Document` (an FPDF subclass) draws the institutional chrome: the letterhead on the first page, the addresses either rotated up the left margin or across the header, the crest on continuation pages, and the folio.
  4. `Documentate_Pdf_Html_Writer` walks the merged HTML and draws it between the margins, with `Documentate_Pdf_Text_Layout` deciding line breaks and `Documentate_Pdf_Table_Writer` drawing tables whose rows grow, repeat their header after a page break, and spill rather than run off the sheet.
  5. `Documentate_Pdf_Generator` joins those and writes the file atomically.
- **Adding a layout:** put `templates/pdf/<slug>.html` beside the others, keep every field name identical to the ODT template of the same document type, and choose it in the document type's *PDF layout* field. The renderer follows the templates' own metrics — single line spacing, no space between paragraphs, `#dee6ef` behind a heading cell — so a layout reproduces the template's blank lines as empty paragraphs and states a table's `style:width` and `fo:padding` with `width` and `cellpadding`, in millimetres. `docs/removing-collabora.md` records what to delete when the converters are eventually retired.

The resolution layout uses its own ODT letterhead frame (`letterhead=resolution`)
and justified rich-text sections. Paragraph alignment inherits from containers,
unless the paragraph explicitly selects another alignment. Demo repeater JSON
must be slashed at WordPress metadata and post-content write boundaries so that
newlines and escaped quotes survive storage. Every vertical address band embeds
the bundled Roboto Light (weight 300), selected after visual comparison with the
published resolution. Its visible left edge is 7.09 mm from the page edge,
matching the resolution ODT export (not the signed PDF's wider inset).
Horizontal addresses retain Helvetica. Font assets, license,
provenance and regeneration instructions live in `templates/pdf/fonts/roboto/`.

The native `propuestagasto` layout selects `addresses=band-title` to align the
upper end of the longest rotated address with the first-page body margin, while
both address lines remain centred within the same frame. Other layouts
retain the centred `band` option. Paragraph styles can declare `margin-left`,
`margin-top` and `margin-bottom` in mm, cm or pt (nonnegative, at most 50 mm);
left margins are also limited to half the active column. Fixed `line-height`
values in points (4–60) inherit through containers and reproduce the measured
ODT advances: resolution/authorization 14.5 pt, report/reply 15.85 pt,
meeting notice 20.7 pt, expenses 18.95 pt (tables 14.5 pt), and payment memo
11.4 pt (title 12.05 pt; table cells 11.55/10.4 pt). Layout-wide styles belong
on an inner container because generation extracts the body's contents.
`Documentate_Pdf_Paragraph_Style` resolves these independently of rendering.
The same margins are
used when measuring table cells and when drawing them. The expenditure layout
uses these for its indented section labels and the ODT's 2.12 mm legal-paragraph
spacing; supplier tables retain the `#dee6ef` fill and reserve their border inset.
Footer page numbers sit on the outer edge: right on odd pages, left on even pages.

### 2.4. Document Conversion (alternative engines)

- **Location:** `includes/class-documentate-conversion-manager.php`, `includes/class-documentate-collabora-converter.php`, `includes/class-documentate-libreoffice-wasm-converter.php`.
- **Flow:**
  1. `Documentate_Conversion_Manager::get_engine()` names the engine, defaulting to `fpdf`. Under it, `generate_pdf()` draws the PDF natively and no converter is involved; the editable download is always the rendered template itself, never converted.
  2. Under either of the other two, the rendered `.odt` or `.docx` is converted to `.pdf`:
     - **Collabora Online:** Makes a remote API call to a Collabora server to perform the conversion (server-side, recommended for background/batch generation).
     - **LibreOffice WASM (browser):** Runs `@matbee/libreoffice-converter` client-side. The conversion happens in a cross-origin isolated popup that loads plugin-local WASM assets (`admin/vendor/libreoffice-converter`). It is browser-only: there is no server-side path, and it requires COOP/COEP headers plus `SharedArrayBuffer`. See `admin/vendor/libreoffice-converter/README.md` for the large-asset handling.

### 2.5. Access Control and Scopes

- **Location:** `includes/class-documentate-user-scope.php`, `includes/class-documentate-scope-filter.php`, `includes/class-documentate-document-access-protection.php`.
- **Logic:**
  - **Template Management:** Only Administrators can create or edit `documentate_doc_type` terms.
  - **Scope Filtering:** Documents are filtered based on a "Scope category" assigned to the user's profile.
    - Administrators see everything.
    - Standard users only see documents assigned to their scope category or its subcategories.
  - **Frontend / REST Protection:** `Documentate_Document_Access_Protection` aggressively blocks frontend access (`template_redirect`), REST API access, and comments queries for the `documentate_document` CPT if the user lacks the `edit_posts` capability.

## 3. Directory Structure

- `admin/`: Classes and assets for the WordPress admin dashboard (Settings page, Meta boxes, custom UI).
- `includes/`: Core plugin logic.
  - `custom-post-types/` & `documents/`: CPT registration and meta handling.
  - `doc-type/`: Taxonomy registration and schema extraction logic.
  - `opentbs/`: Embedded TinyButStrong and OpenTBS libraries.
- `fixtures/`: Sample `.odt` templates and generated files used for testing and demos.
- `tests/`: PHPUnit tests (unit and e2e) following WordPress standard practices.

## 4. Potential Improvements and Known Issues to Watch

While analyzing the codebase, a few areas stand out for future refinement:

1. **REST API Comment Protection Granularity:**
   - In `class-documentate-rest-comment-protection.php`, the checks currently rely heavily on `is_user_logged_in()`. This means *any* logged-in user (even a Subscriber) might bypass the REST restriction block, although other core WordPress capability checks might eventually stop them. It is generally safer to check for a specific capability like `current_user_can('edit_posts')`, similar to how `class-documentate-document-access-protection.php` does it.
2. **Settings Validation Capabilities:**
   - `class-documentate-admin-settings.php` handles sanitization well, but ensure that any endpoint saving these settings explicitly verifies `current_user_can('manage_options')` if done outside the standard Options API flow.
3. **Hardcoded Post Types in Protection:**
   - `class-documentate-rest-comment-protection.php` defaults to protecting `documentate_task` in its filter. It should probably dynamically read the registered CPTs or default to `documentate_document`.

## 5. Development Workflow

- The project uses `wp-env` for local development.
- Code must adhere to WordPress Coding Standards (validated via `phpcs`).
- Run `make up` to start the environment and `make test` to run tests.
- Always read `AGENTS.md` and `CONVENTIONS.md` for specific coding rules.
