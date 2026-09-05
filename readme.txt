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

Documentate is a WordPress plugin developed by the ATE to create official resolutions and structured administrative documents from ODT/DOCX templates.

It uses OpenTBS to merge the document data into the template, and draws the PDF natively on the server from an HTML layout. Collabora Online (server-side) and LibreOffice WASM (in the browser) remain selectable as alternative PDF engines.

### Features

- **Document types (templates)** defined as a custom taxonomy with schema-driven fields.
- **ODT/DOCX generation** from templates via OpenTBS.
- **Native PDF generation** from an HTML layout per document type, with no external service; Collabora Online (server) or LibreOffice WASM (browser, experimental) can be selected instead.
- **Per-user scope filtering** (hierarchical categories) to control document visibility.
- **Workflow, revisions, attachments and collaborative editing.**
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

= How is document visibility controlled? =
Through a per-user scope (hierarchical categories). Administrators see every document; other users only see documents in their scope and its subcategories.

== Screenshots ==

1. **Resolution editor**
   Meta fields for the different sections of the document.

2. **DOCX/PDF export**
   Generates documents from ODT/DOCX templates.
