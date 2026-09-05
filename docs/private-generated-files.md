# Private generated documents

Generated PDF, ODT and DOCX files stay in `uploads/documentate/`, preserving
existing preview and download paths. They must be served only by Documentate's
authenticated handlers, which check the document capability and nonce.

Following the layered protection in `wp-registro-visitas-centros`, Documentate
installs Apache 2.4/legacy deny rules and an empty directory index. The directory
is owner-only (0700); documents and reserved temporary files are owner-readable
and writable (0600). Write access is needed to regenerate an existing document.
Guards remain 0644 so Apache can read them. No symlink target is chmodded.

Collabora can recreate its output with `FS_CHMOD_FILE`; the generator restores
0600 afterward. Directory and HTTP guards protect that intermediate interval.

The first WordPress request after upgrading protects existing files. A per-site,
directory-specific version option avoids scanning all documents on later requests.
Guard failures leave the migration pending and block generation. Existing
download handlers continue reading files locally; server access rules do not
prevent authenticated PHP streaming.

## nginx and other servers

nginx ignores `.htaccess`. Owner-only permissions protect installations where
the static server runs as a different user from PHP. If both use the same user,
the deployment MUST deny HTTP access to the output directory, for example:

```nginx
location ^~ /wp-content/uploads/documentate/ {
    deny all;
}
```

Adapt the prefix for custom upload URLs and multisite paths. Apply equivalent
rules on other servers and disable public CDN/offload access to this directory.
Apache requires access overrides enabled. Verify that a previously generated
direct file URL returns 403, while an authorized plugin download still works.
Cached public copies require separate cache invalidation by the administrator.
