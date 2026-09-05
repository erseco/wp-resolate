# AutoFirma support

Documentate can sign generated PDF files with the local AutoFirma application. The feature is enabled per template: the **Sign and Download** action is shown only when the selected DOCX or ODT template contains a `[sign]` placeholder.

The PDF is produced by whichever engine the site uses for the normal PDF action: drawn natively on the server by default, or converted through Collabora Online or LibreOffice WASM if one of those is selected. AutoFirma receives the same bytes either way. The resulting PDF is then signed locally with AutoFirma using PAdES and downloaded as a new file.

## Basic marker

Add this placeholder to the DOCX or ODT template:

```text
[sign]
```

This enables signing and uses the default visible signature rectangle:

- Last page.
- 72 points from the left edge.
- 72 points from the bottom edge.
- 240 points wide.
- 80 points high.

`sign` is a reserved command, not a document field. It is excluded from the generated edit form and replaced with an empty value when the document is rendered.

## Positioned signature

Use parameters when the signature must appear at a specific PDF location:

```text
[sign;page=2;x=72;y=72;width=240;height=80]
```

| Parameter | Meaning | Default |
|---|---|---:|
| `page` | PDF page number. Use `-1` for the last page. | `-1` |
| `x` | Horizontal offset in PDF points from the bottom-left corner. | `72` |
| `y` | Vertical offset in PDF points from the bottom-left corner. | `72` |
| `width` | Visible signature rectangle width in PDF points. | `240` |
| `height` | Visible signature rectangle height in PDF points. | `80` |
| `text` | Optional AutoFirma layer 2 text for this template. | Plugin setting |

Coordinates use the PDF coordinate system, whose origin is the bottom-left corner. One point is 1/72 inch. Zero is a valid value for `x` and `y`.

Example for a signature at the bottom-right area of an A4 page:

```text
[sign;page=-1;x=300;y=40;width=220;height=70]
```

## Visible signature text

The default visible text can be changed in **Settings → Documentate → AutoFirma visible signature text**.

The initial value matches the standard AutoFirma text:

```text
Firmado por $$SUBJECTCN$$ el día $$SIGNDATE=dd/MM/yyyy$$ con un certificado emitido por $$ISSUERCN$$
```

Supported AutoFirma variables include:

- `$$SUBJECTCN$$`: common name of the certificate holder.
- `$$ISSUERCN$$`: common name of the certificate issuer.
- `$$CERTSERIAL$$`: certificate serial number.
- `$$SIGNDATE=dd/MM/yyyy$$`: signing date using a Java `SimpleDateFormat` pattern.

A template can override the global setting with the `text` parameter:

```text
[sign;page=-1;x=72;y=72;width=240;height=80;text=Firmado digitalmente por $$SUBJECTCN$$.]
```

The precedence is:

1. The `text` parameter in `[sign]`.
2. The value saved in the Documentate settings.
3. The standard AutoFirma text shown above.

## Example document

The plugin includes `fixtures/demo-autofirma.docx`. In non-production demo environments it creates an **AutoFirma** document type and a sample document automatically.

The template contains regular editable fields and this signing command:

```text
[sign;page=-1;x=72;y=72;width=240;height=80]
```

## Signing workflow

1. Save the Documentate document.
2. Select **Sign and Download** in the document actions box.
3. Documentate generates the PDF through the configured conversion engine.
4. Select a certificate in AutoFirma.
5. The browser downloads `<document-slug>-<post-id>-signed.pdf`.

Cancelling the certificate dialog cancels the operation without displaying an error.

## Runtime dependencies

The integration uses:

- `@erseco/autofirma-client` for the browser API and AutoScript communication.
- `erseco/autofirma-intermediate-server` for temporary HTTP storage and retrieval.

The intermediate server is used when the browser cannot communicate directly with AutoFirma, especially on mobile devices. Documentate opens an authenticated, short-lived session and exposes token-protected storage and retrieval URLs. Protocol payloads are encrypted by AutoScript and AutoFirma and are stored temporarily in WordPress transients.

The production plugin package includes the compiled JavaScript client, `autoscript.js` and the PHP runtime required by the intermediate server. Installing the WordPress ZIP does not require running npm or Composer.

## Requirements

- AutoFirma installed and registered for browser communication.
- A valid signing certificate available to AutoFirma.
- A configured PDF generation path in Documentate.
- A DOCX or ODT template containing `[sign]`.
- Pretty permalinks when the intermediate server is needed. Plain permalinks produce REST URLs that are incompatible with the AutoFirma protocol query string.

The signed result is downloaded by the browser. It is not added to the WordPress Media Library.

## Troubleshooting

When the signing action is not shown, verify that the active document type uses the template containing `[sign]`. The marker must use the exact reserved name `sign`.

When an old editable **Sign** field remains visible, reload the document edit screen. Documentate removes the reserved field from previously stored schemas during the next admin request.

When AutoFirma does not open, verify the local installation and browser protocol registration. Browser restrictions, remote desktop environments and managed devices can prevent the native application from being launched.

When the visible signature appears outside the expected area, adjust `x`, `y`, `width` and `height`. These values refer to the final PDF, not to centimetres or the DOCX/ODT page coordinates.
