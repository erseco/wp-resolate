# Unsaved-changes guard for document generation

Date: 2026-08-03

## Problem

The document actions (Preview, Download PDF, Sign and Download, DOCX, ODT) generate
files server-side from **saved** post meta. When the editor has typed into a field
but not saved, the generated document silently reflects the previous version. The
user sees a stale document with no indication that anything is wrong.

A guard already exists but never fires. `hasUnsavedChanges()` is duplicated in
`admin/js/documentate-actions.js` and `assets/js/documentate-autofirma.js`, and both
copies are dead code:

1. `wp.data.select('core/editor')` never exists. The CPT disables Gutenberg
   (`Documents_CPT_Registration::disable_gutenberg()`, `show_in_rest => false`),
   so the screen is the classic editor.
2. `wp.autosave.server.isDirty()` does not exist in WordPress. The public API of
   `wp-includes/js/autosave.js` is `{ tempBlockSave, triggerSave, postChanged,
   suspend, resume }`. The `typeof … === 'function'` test always fails.
3. Execution falls through to TinyMCE `isDirty()`, which only observes rich-text
   editors — not the plain inputs, selects, repeater rows or TipTap editors that
   hold most of a document's content.

Switching branch 2 to the real `postChanged()` would not fix it either: core only
compares `title::content::excerpt` and never inspects custom meta.

## Non-goals

The workflow buttons (`Save Draft`, `Send to Review`, `Approve & Publish`) are out
of scope. `DocumentateWorkflow.submitWithStatus()` submits the `#post` form, so
they already persist every pending edit. Blocking them would remove the very
action that resolves the problem.

## Approach

Save-then-act, following the classic editor's own Preview Changes button
(`#post-preview` in `wp-admin/js/post.js`), which sets `#wp-preview` to `dopreview`
and submits the form rather than disabling anything.

### One gate instead of two

Clicks on `[data-documentate-action]` are currently handled in two places that do
not compose: `documentate-actions.js` delegates on the bubble phase, while
`documentate-autofirma.js` registers `document.addEventListener('click', …, true)`
on the **capture** phase and calls `stopImmediatePropagation()`, so the `sign`
action never reaches the other handler.

A new module `admin/js/documentate-unsaved-changes.js` registers a single capture
listener before both. `documentate-actions` declares it as a dependency and
`documentate-autofirma` already depends on `documentate-actions`, so the load order
is guaranteed; capture listeners on the same target fire in registration order.

When the document is clean the gate does nothing and the existing flow is
untouched. When it is dirty the gate calls `preventDefault()` +
`stopImmediatePropagation()` and opens the confirmation modal. This covers preview,
PDF, sign, DOCX, ODT and any future action using the same attribute.

Both dead `hasUnsavedChanges()` copies are deleted.

### Dirty detection

Event-driven and biased toward "dirty", the approach `acf.unload` uses. A false
positive costs one modal that offers "Use saved version"; a false negative is the
bug being fixed. Snapshot comparison of `$('#post').serialize()` was rejected
because core's volatile hidden inputs produce false positives.

All listeners are delegated on `#post` so dynamically added repeater rows are
covered without rebinding:

| Source | Hook |
| --- | --- |
| Simple fields and repeaters | `input change` on `input, textarea, select` |
| TipTap / ProseMirror editors | `input` on `.ProseMirror` (contenteditable emits `input` natively) |
| TinyMCE | `tinyMCE.on('AddEditor')` → `editor.on('Dirty change')` after `init` |
| Repeater add / remove / sort | click on `.documentate-array-add`, `.documentate-array-remove`; `sortupdate` |

TinyMCE's `SetContent` is deliberately not observed: it fires during
initialisation and would mark a freshly loaded document as dirty.

The module exposes `window.documentateUnsavedChanges` with `isDirty()`,
`markDirty()`, `markClean()` and `subscribe(callback)`.

### Confirmation modal

Built by the new module with its own `documentate-unsaved-modal` class, reusing the
visual language of the existing `documentate-loading-modal`.

```
⚠  Hay cambios sin guardar

El documento se genera a partir de la última versión guardada.
Si continúas sin guardar, no verás tus cambios.

[ Guardar y previsualizar ]   [ Usar versión guardada ]   [ Cancelar ]
```

The primary label adapts to the action: *Guardar y previsualizar* / *Guardar y
descargar PDF* / *Guardar y firmar*.

Accessibility: `role="dialog"`, `aria-modal="true"`, `aria-labelledby`, focus moved
to the primary button on open, `Esc` cancels, focus returns to the triggering
button on close, and `wp.a11y.speak()` announces the state.

"Use saved version" sets a one-shot bypass flag and re-dispatches a `MouseEvent`
on the original button so the normal handlers run.

### Save and resume

Saving reuses the real path — `tinyMCE.triggerSave()`, then
`$(window).off('beforeunload.edit-post')`, then `$('#post').submit()` — **without
touching `#post_status`**, so `Documentate_Document_Meta_Saver`, nonces, revisions
and the workflow all behave exactly as on a manual save. No new PHP save endpoint.

Before submitting, the pending action is recorded in `sessionStorage` under
`documentate_pending_action_<postId>` as `{ action, format, ts }`. After the reload
the module reads the entry, deletes it immediately, discards it if older than two
minutes, and replays the action.

The replay is a synthetic click, so it only does something once
`documentate-actions` has delegated its handler. Both modules bind on DOM ready and
the guard loads first — `documentate-actions` depends on it — so its ready callback
runs while nothing is listening yet. `resumePendingAction()` is therefore exposed on
`window.documentateUnsavedChanges` and called at the end of `documentate-actions`'
`init()`, after the handler is bound, rather than from the guard's own ready
callback. Calling it from there consumed the stored entry and dispatched a click
that landed on nothing: the page saved and reloaded, and the document was never
generated.

### Popup blocking

`documentate-actions.js` opens previews with `window.open(url, '_blank')` inside an
AJAX success callback, outside the user gesture, so browsers already block it
intermittently. After a reload there is no gesture at all and it would always be
blocked.

On the resumed path, `preview` attempts `window.open` and, when it returns `null`,
switches the loading modal to a "Vista previa lista" state with an
`[Abrir vista previa]` anchor — a real gesture. Downloads use
`window.location.href` and need no gesture. `sign` always resumes behind a button
because AutoScript requires user activation.

### Passive indicator

A `● Cambios sin guardar` line inside the actions block — the wp-admin meta box
and the export block of the front-end application alike — fed by `subscribe()`
and shown only while dirty. `subscribe()` is only wired when the line is on the
page, so a view that leaves it out never warns at all. It is a `role="status"`
live region so the change is announced once rather than on every keystroke.

## Files

| File | Change |
| --- | --- |
| `admin/js/documentate-unsaved-changes.js` | new — detector, gate, modal, resume, indicator |
| `admin/css/documentate-actions.css` | modal and indicator styles |
| `admin/js/documentate-actions.js` | delete `hasUnsavedChanges()`; popup fallback for preview; resume the pending action after binding |
| `assets/js/documentate-autofirma.js` | delete `hasUnsavedChanges()` and its `confirm()` |
| `admin/js/documentate-autofirma.js` | regenerated via `npm run build:autofirma` |
| `includes/class-documentate-admin-helper.php` | enqueue, dependency, localized strings, indicator markup |

No changes to the generation layer or the workflow.

## Testing

- **Jest** — `isDirty()` after `input` on a plain field, after TinyMCE `Dirty`,
  after `input` on `.ProseMirror`; starts clean; `sessionStorage` TTL; gate passes
  through when clean. Plus one test that evaluates the guard and
  `documentate-actions` in load order and asserts the resumed action reaches
  `$.ajax`, which a test binding its own listener up front cannot catch.
- **PHPUnit** — the handle is enqueued with the right dependencies and strings.
- **Playwright** — edit a field, click Preview, assert the modal; assert the
  "use saved version" branch generates; assert the save branch reloads and resumes.
