---
name: documentate-autofirma
description: Change Documentate signing sessions, AutoFirma transport or signed-file handling.
---

# Documentate AutoFirma

Inspect `includes/autofirma/` and its tests for the affected path.

- Intermediate storage/retrieve routes use an expiring opaque token, not a browser
  cookie or WordPress nonce. Session creation requires `edit_posts`. Preserve both
  boundaries instead of adding cookie authentication to desktop transport.
- The protocol implementation is a Composer dependency copied into
  `includes/vendor/autofirma-intermediate-server/`; browser transport comes from
  `@erseco/autofirma-client`, bundled by `npm run build:autofirma`. Do not patch the
  generated/vendor copy or implement a competing protocol.
- Treat JavaScript certificate metadata as untrusted. Failed or unavailable
  signing must never silently return an unsigned document as if it were signed.
- Test unauthorized session creation, expired/invalid tokens and signing failure
  as appropriate; browser verification must distinguish signed from unsigned files.
