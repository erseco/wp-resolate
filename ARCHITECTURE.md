# Documentate - Plugin Architecture and AI Context

This document provides a high-level overview of the **Documentate** WordPress plugin's architecture, data flow, and key components. It serves as a guide for AI agents and new developers to understand how the system is built and where to find specific functionality.

## 1. High-Level Purpose

**Documentate** is a WordPress plugin designed to generate official resolutions and structured documents, and to run them through an approval workflow (área → revisión → jefatura de servicio, §3) before publication. It uses a custom post type (`documentate_document`) to store document data, which is categorized by a custom taxonomy (`documentate_doc_type`).

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
    - Every other user — área, revisión and jefatura de servicio alike — sees only the documents
      whose category is their scope category or one of its descendants, whatever the status. There
      is no role-based bypass: revisión and jefatura reach several áreas because their scope is a
      category higher up the tree (e.g. the service), not because of who they are.
      `Documentate_Scope_Filter::get_scope_term_ids()` and `user_can_access_document()` are static
      and are what every list, tray and notification asks.
  - **Frontend / REST Protection:** `Documentate_Document_Access_Protection` aggressively blocks frontend access (`template_redirect`), REST API access, and comments queries for the `documentate_document` CPT if the user lacks the `edit_posts` capability.

## 3. Roles, Statuses and the Approval Workflow

Every document goes through a small approval workflow before it is final. Three
roles share it — área, revisión and jefatura de servicio — detected by
capability rather than by a fixed role name
(`includes/class-documentate-roles.php`, `Documentate_Roles`), plus the site
administrator standing outside it:

- **Área** (`is_area()`): anyone with `edit_posts` who is neither revisión nor
  jefatura. Creates documents, edits its own drafts, and sees the documents of
  its scope category and descendants.
- **Revisión** (`is_management()`): the dedicated `documentate_gestion` role
  (display name "Revisión"; the slug is a stored contract), or any account
  carrying the `documentate_gestionar` capability together with
  `edit_others_posts` (the capability alone does nothing — revisión must also
  be able to open documents it did not author, which is what
  `edit_others_posts` gates). The person next to the head of service who
  reviews documents and completes their official fields (`rol='gestion'`).
  Edits drafts and documents in `en_gestion`.
- **Jefatura de servicio** (`is_head()`): the dedicated `documentate_jefatura`
  role, or any account carrying `documentate_aprobar` plus `edit_others_posts`.
  Approves and publishes, or returns with a reason (to revisión or to the
  área). Edits drafts, `en_gestion` and `pending`. Counts as revisión too
  (superset). Not a site administrator.
- **Administración** (`is_administration()`): `manage_options`. Counts as
  jefatura and revisión, unrestricted scope; the only role that archives,
  unarchives and un-approves ("Devolver a aprobación") from wp-admin, or
  creates a document in any status. `role_label()` still labels them
  "Administración".

`Documentate_Roles::ensure_caps()` (roles version 3, hooked on `init` and from
plugin activation) creates both roles, renames the old one to "Revisión" and
grants `documentate_gestionar` and `documentate_aprobar` to administrators;
`grant_management( $user_id )` / `grant_head( $user_id )` appoint one account;
`uninstall.php` removes both roles and capabilities.

Edit rights follow the status (`Documentate_Workflow::user_can_modify_status()`):
área edits drafts only; revisión drafts + `en_gestion`; jefatura drafts +
`en_gestion` + `pending`; administración everything. A document in someone
else's hands shows a lock notice ("Lo tiene revisión / la jefatura de
servicio") and a greyed-out Editar in the app. Taking over an edit lock asks
exactly the same question, so whoever holds the document in its status may
take it from whoever has it open, and nobody else.

### Statuses

`draft` (Borrador) → **`en_gestion`** (En revisión, custom status registered by
`Documentate_Statuses`) → `pending` (En aprobación) → `publish` (Aprobado) →
`archived` (Archivado). Stored keys are contracts; only the labels changed. A
document type only visits `en_gestion` when it is "con revisión" — either the
taxonomy term meta `documentate_type_con_gestion` is set (the wp-admin checkbox
"Pasa por revisión"), or its schema has any field with `rol='gestion'`
(`Documentate_Document_Data::has_management()` / `Documentate_Field_Roles::type_has_management()`).
Types that are not con revisión skip straight from `draft` to `pending`.

"Devuelto" (returned) is not a status, it is a mark: post meta
`_documentate_devuelto` holds who returned it, when, why, and where from/to
(`Documentate_Document_Data::mark_returned()` / `returned()`). It is set by every
return and cleared by every forward transition, so a document can sit in, say,
`en_gestion` while showing "Devuelto por la jefatura de servicio: «…»" until it
is resent.

### `Documentate_Transitions` — the single source of truth

`includes/class-documentate-transitions.php` holds one static rule table
(`rules()`) that is the **only** place that says which move is legal from
which status, for which role (`who`: `area`, `gestion`, `jefatura`, `admin`),
for which kind of document type (con/sin revisión), and whether a reason is
mandatory. Both the wp-admin metabox and the
front-end app read `available( $post, $user_id )` to draw their buttons and
call `apply( $post_id, $key, $reason )` to run one; `allowed()` is what
`Documentate_Workflow`'s status-change filter uses to reject anything that
doesn't match a rule, whichever screen it was posted from. Do not duplicate
this table or hard-code a transition elsewhere — extend `rules()` instead.

Every applied transition is recorded by `Documentate_Activity::record_event()`
(see §4) and, where the table says so, triggers a notification
(`includes/class-documentate-notifications.php`) to the reviewers or heads of
service whose scope covers the document (heads and administrators get the
"pending" mail).

## 4. Fields by Role and the Document Data Model

### Fields by role in templates

Any OpenTBS placeholder can carry a `rol` attribute (alias `role`), value
`area` (default) or `gestion` — e.g.
`[gasto_numero;type='number';title='Gasto total';rol='gestion']`. Setting it
on a repeater block (`[servicios;block=begin;...;rol='gestion']`) propagates
it to every field of the repeater. The schema extractor/converter carries the
attribute through both repeater code paths; `Documentate_Field_Roles`
(`includes/class-documentate-field-roles.php`) is what everything else asks:

- `field_role( $field )` — the effective rol of a schema row.
- `can_view( $row, $user_id )` — área sees only `area` rows; revisión,
  jefatura and administración see everything.
- `type_has_management( $term_id )` — whether a document type has any `gestion`
  field (used to decide if it needs the `en_gestion` step at all).
- `group_by_role( $schema_rows )` — splits a schema into `area`/`gestion` groups for
  rendering.

Visibility is enforced on **write**, not just on render: the meta-box saver
and the document content writer both call `can_view()` before accepting a
posted value for a field, so a request forged by área never changes a
`gestion` field even if the input existed in the HTML.

### Document data model additions

Post meta on `documentate_document`, read through
`includes/class-documentate-document-data.php` (`Documentate_Document_Data`):

| Meta key | Holds | Accessor |
|---|---|---|
| `_documentate_nombre_interno` | Short internal name (≤ 80 chars, stored without the type's prefix) | `internal_name()` / `short_name()` (prefix + name, e.g. "RES · Bases 2026") |
| `_documentate_anotaciones` | Internal notes, revisión/jefatura/admin only, never rendered into the document | `notes()` / `save_notes()` |
| `_documentate_devuelto` | JSON: who returned it, when, why, from/to (§3) | `mark_returned()` / `returned()` / `clear_returned()` |
| `_documentate_attachments` | Attached source file (pre-existing) | `attachment()` returns the first attachment as a `WP_Post` |

Other helpers on the same class: `type()`, `type_prefix()`, `area()`,
`person()`, `course()` (value of a `curso` schema field, if the type has one).

### Activity

`includes/class-documentate-activity.php` (`Documentate_Activity`) keeps a
per-document log as WordPress comments of two types, so it reuses
`wp_insert_comment`/`get_comments` rather than a new table:

- `documentate_evento` — system events ("envió el documento a revisión",
  "devolvió el documento al área: «…»", …), written by
  `Documentate_Transitions::apply()`. These never trigger WordPress's
  comment-notification email (`Documentate_Disable_Comment_Notifications`
  excludes the type), and `Documentate_Document_Access_Protection` excludes
  the CPT from `comment_feed_where` so an event never leaks through a public
  comment feed.
- `comment` — a free-text note from any role, via `add_comment()` (not
  `wp_new_comment()`, to skip flood control and `wp_die`).

`entries( $post_id )` returns both, newest first, for the "Actividad" card in
the app and in wp-admin.

## 5. The Front-End Application (`/documentate/`)

`includes/app/` holds a small application served by one WordPress page via the
`[documentate_app]` shortcode; every view lives under a single URL,
distinguished by query args (`vista`, `doc`, `bandeja`, `estado`, `area`):

- `class-documentate-app.php` (`Documentate_App`) — shortcode, asset
  enqueueing, admin-bar entry, and wiring of the `template_redirect` handlers.
  `require_login()` runs first among them: an anonymous visitor of the
  application page is sent to `wp_login_url()` — which on this site is where
  CAS takes over — and comes back to the view they asked for, arguments
  included, so a link from a notification lands on its document. The "sign in"
  notice of `render()` stays for whoever puts the shortcode on a page of their
  own.
- `class-documentate-app-shell.php` (`Documentate_App_Shell`) — header (who is
  signed in: initials avatar, name, role via `Documentate_Roles::role_label()`
  and ámbito via `scope_label()`, in a native `<details>` menu with "Salir"),
  tabs per role (`sections()`), sheet and dialogs shared by every view.
- `class-documentate-app-list.php`, `-detail.php`, `-edit.php` —
  bandejas/list, document detail (status stepper, actividad, export) and the
  edit screen (fields grouped by role, attachment dropzone, transition
  buttons) respectively.
- `class-documentate-app-tray.php` (`Documentate_App_Tray`) — which
  trays a role may open, which one the request means, the active status/área
  filters, and the `WP_Query` arguments and counts behind them. The list view
  and the tab badges ask it; they never build a query themselves.
- `class-documentate-app-list-row.php` (`Documentate_App_List_Row`) — one
  row of that list: the text the quick filter matches against, the paper-clip
  of a document with a file, the sublines and the single action offered.
- `class-documentate-app-actions.php` (`Documentate_App_Actions`) — the
  actual POST handlers: create, save, transition (delegates to
  `Documentate_Transitions::apply()`), comment. Every handler is
  nonce-checked, capability-checked, and redirects after POST with a feedback
  flag in the query string.
- `class-documentate-app-attachments.php` (`Documentate_App_Attachments`) — validates
  and sideloads the single source-file attachment (PDF/ODT/DOCX, ≤ 20 MB) via
  `media_handle_sideload()`.

Tabs differ per role: área gets "Mis documentos" / "Nuevo documento"; revisión
"Documentos" (every document of its scope) / "Para revisar" (`en_gestion`) /
"Nuevo documento"; jefatura "Documentos" / "Para aprobar" (`pending`) / "Nuevo
documento"; administración "Todos los documentos" / "Para aprobar" / "Nuevo
documento". Every tray is scoped (§2.5); only the actionable tab carries a
badge, and "Nuevo documento" a plus icon. Whoever looks after several áreas
— revisión, jefatura and administración — also gets the área select, which
offers the categories of their ámbito (every one of them for administración)
and narrows a tray without ever reaching past it.

Preview/export (PDF, ODT, DOCX) reuses the same admin metabox actions:
`Documentate_Admin_Helper::render_actions_for_post()` /
`enqueue_actions_assets_for_post()` render and enqueue the export block on the
app's detail/edit views, so Collabora, LibreOffice-WASM-in-Playground and the
disabled/unavailable states behave identically in wp-admin and in the app.

### Editing ownership

`Documentate_App_Lock` shares WordPress's `_edit_lock` with wp-admin. Opening
an authorized edit view checks `wp_check_post_lock()` before acquiring the
lock with `wp_set_post_lock()`. The app sends the core `wp-refresh-post-lock`
payload through Heartbeat every 15 seconds; a takeover makes the old form
inert and displays an explicit takeover notice. Save and transition handlers
check ownership before changing any fields, files or status.

The takeover POST checks the nonce, document scope and workflow permission.
Successful transitions release the current user's lock immediately. Leaving
the editor uses core `wp-remove-post-lock`; abandoned locks expire using the
WordPress window (150 seconds by default). Ownership is per WordPress user,
including multiple tabs logged into the same account. See
[ADR 0002](docs/adr/0002-native-document-edit-locks.md).

## 6. Directory Structure

- `admin/`: Classes and assets for the WordPress admin dashboard (Settings page, Meta boxes, custom UI).
- `includes/`: Core plugin logic.
  - `custom-post-types/` & `documents/`: CPT registration and meta handling.
  - `doc-type/`: Taxonomy registration and schema extraction logic.
  - `opentbs/`: Embedded TinyButStrong and OpenTBS libraries.
  - `app/`: Front-end application (`/documentate/`) — routing, views, actions, attachments. See §5.
  - `autofirma/`: AutoFirma intermediate-server protocol (see `AGENTS.md`).
- `fixtures/`: Sample `.odt` templates and generated files used for testing and demos.
- `tests/`: PHPUnit tests (unit and e2e) following WordPress standard practices.
- `docs/`: Design notes (technical, English) and functional guides for the team (Spanish) —
  `docs/flujo-documentos.md`, `docs/campos-por-rol.md`.

## 7. Potential Improvements and Known Issues to Watch

While analyzing the codebase, a few areas stand out for future refinement:

1. **REST API Comment Protection Granularity:**
   - In `class-documentate-rest-comment-protection.php`, the checks currently rely heavily on `is_user_logged_in()`. This means *any* logged-in user (even a Subscriber) might bypass the REST restriction block, although other core WordPress capability checks might eventually stop them. It is generally safer to check for a specific capability like `current_user_can('edit_posts')`, similar to how `class-documentate-document-access-protection.php` does it.
2. **Settings Validation Capabilities:**
   - `class-documentate-admin-settings.php` handles sanitization well, but ensure that any endpoint saving these settings explicitly verifies `current_user_can('manage_options')` if done outside the standard Options API flow.
3. **Hardcoded Post Types in Protection:**
   - `class-documentate-rest-comment-protection.php` defaults to protecting `documentate_task` in its filter. It should probably dynamically read the registered CPTs or default to `documentate_document`.

## 8. Development Workflow

- The project uses `wp-env` for local development.
- Code must adhere to WordPress Coding Standards (validated via `phpcs`).
- Run `make up` to start the environment and `make test` to run tests.
- Always read `AGENTS.md` and `CONVENTIONS.md` for specific coding rules.
