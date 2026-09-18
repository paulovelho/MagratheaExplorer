# Changelog

## 1.1.1
2026-09-19
- **new:** Admin duplicate-finder tool: lists files that collide on (key, folder, name) -- the shape a re-run import can produce -- grouped side by side, with a one-click purge that keeps the newest copy of each group and deletes the rest.
- **new:** Downloaded files keep their original filename instead of the opaque storage token, via a proper `Content-Disposition` header (both local storage, through a new `.htaccess`/Caddy rule, and S3/R2, via object metadata).
- **new:** Custom favicon for both the app and the admin panel.

## 1.1.0
2026-09-09
- **new:** Admin-only folder import tool: recursively mirrors a directory already on the server's filesystem into a chosen key/folder, running every file through the normal upload pipeline (MIME sniffing, image re-encode/thumbnail/metadata strip). Re-running an import reuses the folder tree it already created. Bypasses the key's usage_limit/usage_limit_mb caps (still blocked by inactive/expired/pending-deletion).

## 1.0.0
2026-09-08
- **new:** Initial release: key/folder/file management, local + S3/R2 storage, image processing pipeline (metadata stripping, webp conversion, thumbnails), scheduled key deletion, tags.
