=== Documentate – Generador de resoluciones ===
Contributors: ateeducacion
Tags: documents, resolutions, docx, pdf, opentbs
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate official resolutions and structured administrative documents from ODT/DOCX templates, with export to DOCX and PDF.

== Description ==

Documentate is a WordPress plugin developed by the ATE to create official resolutions and structured administrative documents from ODT/DOCX templates, and to run them through an approval workflow before they're published.

It uses OpenTBS to merge the document data into the template, and draws the PDF natively on the server from an HTML layout. Collabora Online (server-side) and LibreOffice WASM (in the browser) remain selectable as alternative PDF engines.

### Features

- **Document types (templates)** defined as a custom taxonomy with schema-driven fields.
- **Three-role approval workflow**: área creates and sends, revisión completes the official fields, jefatura de servicio approves and publishes — with a "devuelto" (returned, with reason) mark and a full activity log at every step. Site administrators archive and un-approve.
- **Fields by role in the templates**: a placeholder marked `rol='gestion'` is only shown to, and only saved from, revisión / jefatura de servicio / administración.
- **Front-end application** under `/documentate/` (inboxes, detail, edit, attachments, export) alongside full parity in wp-admin.
- **ODT/DOCX generation** from templates via OpenTBS.
- **Native PDF generation** from an HTML layout per document type, with no external service; Collabora Online (server) or LibreOffice WASM (browser, experimental) can be selected instead.
- **Per-user scope filtering** (hierarchical categories) to control document visibility.
- **Revisions, attachments and native WordPress editing locks.**
- **Multisite compatible.**

### Third-party libraries

The plugin bundles these, each under its own licence:

- FPDF by Olivier Plathey (http://www.fpdf.org/), which draws the PDF. It declares the GD and zlib PHP extensions as requirements.
- Roboto Light by The Roboto Project Authors (https://github.com/google/fonts/tree/main/ofl/roboto), used for the vertical address bands. Licensed under the SIL Open Font License 1.1 (SIL OFL 1.1); the licence is included in `templates/pdf/fonts/roboto/OFL.txt`, with source and regeneration details in that directory's `README.md`.
- TinyButStrong and OpenTBS by Skrol29 (https://www.tinybutstrong.com/), which merge a document's fields into its template.

== Installation ==

1. Download the latest release from the GitHub releases page.
2. Upload the plugin to your site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin from the 'Plugins' menu.
4. Configure the conversion engine and other options under **Settings > Documentate**.

== Frequently Asked Questions ==

= Which PDF engines are supported? =
Native PDF rendering (default, no external service), Collabora Online (server-side) and LibreOffice WASM (in the browser, experimental).

= What happens to an existing site when it updates? =
The PDF engine becomes the native renderer, so documents are drawn on the server instead of being sent to a Collabora service. Document types created earlier are matched to the layout their ODT or DOCX template is named after, once, on the first visit to the admin area; a type whose template matches no layout keeps the generic one and can be pointed at another under **PDF layout** in the document type. To carry on converting through Collabora, pick it under Settings → Documentate → Conversion engine.

= How is document visibility controlled? =
Through a per-user scope (hierarchical categories). Administrators see every document; every other user, whatever their role, only sees documents in their scope and its subcategories. Reviewers and heads of service cover several áreas by being assigned a category higher up the tree.

= What are the roles? =
Área creates a document and fills in its own fields; revisión completes the fields marked as official data and passes the document on; jefatura de servicio approves and publishes, or returns it. Site administrators can also archive it. A document can be returned to a previous role with a reason at any step.

== Screenshots ==

1. **Resolution editor**
   Meta fields for the different sections of the document.

2. **DOCX/PDF export**
   Generates documents from ODT/DOCX templates.
