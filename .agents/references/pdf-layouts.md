# Documentate PDF layouts

### PDF layouts

- A layout lives in `templates/pdf/<slug>.html` and is chosen per document type.
- Keep every field name identical to the ODT or DOCX template of that type; the
  schema comes from the office template, so a differing name never merges.
- A `type='html'` field carries `;strconv=no`, or its markup arrives escaped and
  prints as visible tags. Never add `protect=no`: it disables bracket protection,
  which is the only thing stopping a user's field value from being read as engine
  markup — `file=` would then read any path off the server.
- A repeated table row uses `block=tr`. `block=tbs:row` is an OpenTBS alias and
  is not registered for HTML layouts.
- Paragraphs are set solid, matching the `Standard` style the templates use, so
  a layout writes each blank line of its template as an empty paragraph. Read
  the template's own spacing rather than guessing: `fo:margin-*` and
  `fo:line-height` on the paragraph styles, `fo:padding` and `style:width` on
  the table styles, and a leading `<text:line-break/>` also reads as a blank
  line.
- A table declares the width and the cell padding of the template it
  reproduces, in millimetres: `<table width="165mm" cellpadding="0.49">`. The
  width is also accepted as a percentage. Without them a table fills its column
  and takes the default padding.
- A cell declares the rest through `style`: `border: none`, `background:
  #rrggbb` (or `none`) and `font-weight: normal|bold`. A `th` is boxed, filled
  and bold by default, which several templates do not do — read their
  `fo:border`, `fo:background-color` and paragraph style rather than assuming.
- The address furniture is drawn in Helvetica whatever the body font is: every
  template asks for a `swiss` family there.
