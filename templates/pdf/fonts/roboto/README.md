# Roboto Light for vertical address bands

Roboto Light (weight 300, width 100) is embedded in every native PDF vertical
address band. No installed font, remote stylesheet or runtime download is needed.
Horizontal addresses and document body fonts are unchanged.

Source: https://github.com/google/fonts/tree/main/ofl/roboto
Copyright: The Roboto Project Authors. License: SIL OFL 1.1; see `OFL.txt`.

`Roboto-Light.ttf` is a static instance of the Google Fonts variable file
`Roboto[wdth,wght].ttf`, obtained on 2026-09-05. It is the same weight and width
used in the approved comparison. `Roboto-Light.json` contains FPDF metrics;
`Roboto-Light.z` embeds the compressed cp1252 subset. Keep all files with the
license when distributing the plugin. The TTF can also be served locally on the
web; no browser styling is changed by this PDF-only integration.

SHA-256 of the downloaded variable source:
`d7598e12c5dbef095ff8272cfc55da0250bd07fbdecbac8a530b9b277872a134`.
SHA-256 of the bundled static TTF:
`c5b980a39747078da04d5a8344f3cec45b19f545c3203a5995149bc2cb0633d1`.

Regeneration (build-time tools only, not plugin dependencies):

1. Use FontTools 4.60.1:
   `fonttools varLib.instancer 'Roboto[wdth,wght].ttf' wght=300 wdth=100 --update-name-table --output Roboto-Light.ttf`
2. Use the official FPDF 1.9 `makefont` tool with its `ttfparser.php` and
   `cp1252.map`, running from this directory:
   `php /path/to/makefont.php Roboto-Light.ttf cp1252 true true`
3. Verify the resulting PDF contains an embedded `Roboto-Light` font and check
   both `band` and `band-title` layouts visually.
