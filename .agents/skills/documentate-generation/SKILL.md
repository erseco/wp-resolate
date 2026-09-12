---
name: documentate-generation
description: Change Documentate template merging, PDF layouts or document conversion.
---

# Documentate generation

Use `ARCHITECTURE.md` for the engine/data flow and
[PDF layout rules](../../references/pdf-layouts.md) for native HTML layouts.

- Template field names are a contract: native layouts must match the ODT/DOCX
  schema. Do not rename fields just to normalize language.
- Preserve bracket protection: never use `protect=no`. Raw HTML fields use
  `;strconv=no`; HTML row repetition uses `block=tr`.
- Derive spacing, table widths, padding and type from the actual office template.
  Do not replace a fidelity check with a successful merge alone.
- Native PDF remains the default; conversion engines are optional. Preserve
  explicit errors and output-type checks, and test the engine path being changed.
- Run `make test-generation`; preview representative output when layout changes.
