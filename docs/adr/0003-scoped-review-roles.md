# ADR 0003: Scoped review roles: revisión and jefatura de servicio

- Status: Accepted
- Date: 2026-09-08

## Context

The workflow modelled its middle step as "gestión documental", a role that
saw every document in the pipeline regardless of scope, and its final step as
the site administrator. Neither matches the organisation. The person who
reviews a document and completes its official data is an assistant to the
head of service, and works for that service only. The head of service, who
approves and publishes, is not a site administrator and must not need
`manage_options` to do their job. Visibility should follow the
organisational tree: an área sees its own documents, a service sees those of
every área under it.

## Decision

Add a `documentate_aprobar` capability and a dedicated `documentate_jefatura`
role ("Jefatura de servicio"), detected by `Documentate_Roles::is_head()`:
the capability plus `edit_others_posts`, or `manage_options`. Rename the
existing `documentate_gestion` role to "Revisión"; its slug, the
`documentate_gestionar` capability, the `rol='gestion'` template attribute,
the `documentate_type_con_gestion` term meta, the `en_gestion` status and
every transition key stay as they are — they are stored contracts. Only
labels change: `en_gestion` reads "En revisión", `pending` "En aprobación".

Replace the pipeline bypass with pure tree scope. Every non-administrator
sees only documents whose category is their scope category or a descendant,
whatever the status and whatever the role. Revisión and jefatura reach
several áreas by being assigned a category higher up (the service), so
`Documentate_Scope_Filter` needs no role branch and its checks are static.
Notifications go to the reviewers or heads whose scope covers the document.

Lock editing by status and role in
`Documentate_Workflow::user_can_modify_status()`: área edits drafts; revisión
drafts and `en_gestion`; jefatura drafts, `en_gestion` and `pending`;
administración everything. The transition table gains `jefatura` as a `who`
value for approving and returning, and keeps `admin` for archiving,
unarchiving and un-approving from wp-admin.

## Consequences

Sites upgrading get roles version 3 from `Documentate_Roles::ensure_caps()`:
the old role is renamed, the new one created, and administrators receive both
capabilities, so existing administrators keep working unchanged. Reviewers
who relied on the bypass now see only their scope; they must be given a
higher scope category (typically the service) to keep covering several
áreas. Appointing one head is `grant_head( $user_id )`, or the wp-admin role
picker. Nothing stored is migrated: no meta, option, status or capability key
is renamed, so a rollback is only a label change.

## References

- [ADR 0002](0002-native-document-edit-locks.md), which the status locks build on
- `ARCHITECTURE.md` §2.5 and §3
- `docs/flujo-documentos.md`
