# ADR 0002: Replace unfinished collaboration with native editing locks

- Status: Accepted
- Date: 2026-09-08

## Context

The unfinished collaborative editor loaded TipTap, Yjs and WebRTC signaling
from external modules. Enabling it also removed WordPress post locks and
deregistered Heartbeat. The new application needs exclusive editing with
explicit takeover, interoperable with wp-admin.

## Decision

Remove the collaborative JS/CSS, settings UI/validator, avatar AJAX endpoint,
status metabox, editor branches and lock-disabling hooks. Classic TinyMCE
remains the rich text editor, including repeater fields. Old settings cannot
reactivate the removed feature and are discarded on the next settings save.
Collabora/WASM document conversion is separate and remains for a later PR.

Reuse WordPress's `_edit_lock`, `wp_check_post_lock()`, `wp_set_post_lock()`
and `wp_refresh_post_lock()` instead of adding a lock table or polling API.
The app loads Heartbeat only on document edit views, sending the native
`wp-refresh-post-lock` payload every 15 seconds. The first authorized editor
acquires the lock; subsequent visitors see the owner and a takeover form.
Taking ownership requires a POST nonce plus document and workflow permission.

A lost lock makes the original form inert and opens an accessible blocking
notice. Unsaved fields remain in the DOM; taking ownership reloads the editor
and explicitly warns that those unsaved changes will be discarded. App saves
and transitions independently reject another editor's active lock with HTTP
409 before any mutation. Comments remain available because they append
activity rather than overwrite the document.

Successful transitions release only the current user's unchanged lock, so the
next workflow participant can work immediately. A pagehide beacon uses core
`wp-remove-post-lock` and its `update-post_<id>` nonce; core's token comparison
prevents the old page from releasing a different owner's lock. As in the classic editor, form submission suppresses
the beacon: otherwise core can recreate a just-deleted lock with its five-second
release grace period and block the next workflow participant. If the browser
vanishes, WordPress expires the lock (150 seconds by default). Back/forward
cache restoration reloads the page to check ownership again.

## Consequences

No extra dependency, signaling service, custom timer or lock storage is needed.
App and wp-admin sessions recognize each other's ownership. This follows core's
user-level semantics: two tabs using the same account are the same editor,
and check/acquire is not a transactional compare-and-swap. Heartbeat reports
takeover on its next response; the server guard protects stale app writes in
the meantime. This is exclusive editing, not simultaneous text merging.

## References

- [Heartbeat API](https://developer.wordpress.org/plugins/javascript/heartbeat-api/)
- [wp_check_post_lock](https://developer.wordpress.org/reference/functions/wp_check_post_lock/)
- [wp_set_post_lock](https://developer.wordpress.org/reference/functions/wp_set_post_lock/)
- [wp_refresh_post_lock](https://developer.wordpress.org/reference/functions/wp_refresh_post_lock/)
- [wp_ajax_wp_remove_post_lock](https://developer.wordpress.org/reference/functions/wp_ajax_wp_remove_post_lock/)
