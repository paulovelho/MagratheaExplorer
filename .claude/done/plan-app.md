# Explorer app — build plan

## Scope

A single-purpose SPA: log in with a Magrathea Explorer key, browse that
key's folders/files like a desktop file explorer, upload new files. Nothing
beyond that unless decided otherwise later (see "Out of scope" below) — key
administration itself already lives in the separate `MagratheaExplorerAdmin`
PHP admin panel, this app is the end-user-facing view of one key's content.

## Stack (decided)

- **Vue 3 (Composition API) + Vite.** Chosen over React (more assembly
  required for this scope: router + state-management decisions up front)
  and Angular (DI/RxJS/module system are overhead for a single-screen app).
  Vue's single-file components fit the actual UI shape here — a reactive
  folder tree/list, breadcrumbs, and an upload widget — with the least
  ceremony.
- **`vue-router`**, even though it's one screen conceptually — the current
  folder should live in the URL (`/app/folder/:id`) so back/forward and
  bookmarking behave like a real file explorer.
- **No state-management library.** The data (current folder's children,
  the logged-in key) is small enough for local component state plus a
  couple of composables.
- **Plain `fetch`** for reads. **`XMLHttpRequest`** specifically for
  uploads, to get `upload.onprogress` — `fetch` doesn't expose upload
  progress natively.
- **No CSS framework required** — plain CSS is enough at this size.

## Auth

- The key *is* the API credential: `Authorization: Bearer <key-uuid>`, no
  cookies, no server session (`docs/openapi.yaml`).
- **Login screen** — single input for the key. On submit, call `GET /key`
  to validate. Success → store the key in `localStorage`, navigate into the
  explorer. 401 → show an error, store nothing.
- Every request after that reads the key from `localStorage` and attaches
  the header. A 401 from *any* call clears the stored key and bounces back
  to login (covers revoked/expired/deleted keys mid-session).
- Logout = clear `localStorage`. No server call needed — there's no session
  to invalidate.

## Screens / components

1. **Login** (`/app/login`) — key input, error state, submit button.
2. **Explorer** (`/app/folder/:id?`, root when `id` is omitted):
   - Breadcrumb trail from root to current folder. Build it client-side as
     a path stack while navigating (push on descend, pop on ancestor
     click) rather than re-fetching ancestors via `GET /folder/{id}` on
     every load — cheaper and avoids N round trips on a deep path.
   - One combined list: folders first, then files, Explorer-style (name,
     size, modified date; thumbnail image instead of a generic icon where
     `thumbnail_url` is present).
   - Open a folder → `router.push` to `/app/folder/:id`.
   - "New folder" → `POST /folders`.
   - Rename — folder via `PUT /folder/{id}`, file via `PUT /file/{id}`.
   - Delete — folder via `DELETE /folder/{id}` (surface the 400
     "not empty" response clearly rather than a generic error); file via
     `DELETE /file/{id}`.
   - Upload — button plus a drop zone over the list, `POST /files`
     (multipart, `folder_id` = current folder), per-file progress bar via
     XHR.
3. **Key usage** — small persistent readout ("X MB of Y used"), not its own
   screen, backed by `GET /key/usage`. Refresh after every upload/delete.

## Out of scope for v1 (flagging, not building)

- Tag management UI (`/file/{id}/tags*`) — endpoints exist, no UI need was
  stated.
- Key self-service deletion / cancel-deletion (`DELETE /key`,
  `/key/cancel-deletion`) — that's key lifecycle, which the admin panel
  already owns; wasn't asked for here.

## API surface used

From `docs/openapi.yaml`: `GET /key`, `GET /key/usage`,
`GET /folders?parent_id=`, `POST /folders`, `GET|PUT|DELETE /folder/{id}`,
`GET /files?folder_id=&file_type=&tag=`, `POST /files`,
`GET|PUT|DELETE /file/{id}`.

## Project layout / serving (already wired)

- `app/src/` — Vue source (`main.js`, `App.vue`, `router/`, `views/`,
  `components/`, `composables/`) — not scaffolded yet.
- `app/dist/` — Vite build output, gitignored, served at `/app`
  (`docker/apache/site-dev.conf` + `docker-compose.yml`'s volume mount).
- Root `/` redirects to `/app` (`api/src/.htaccess`, `docker/caddy/site.caddy`).

## Open questions (need a call before/while building)

- **API base URL** — plan assumes same-origin `/api/v1` (works as-is under
  the shared vhost). Confirm there's no separate API host in prod that
  would need CORS instead.
- **File icons** — generic icon per `file_type` enum
  (image/audio/video/document/other), or per-extension? Recommend generic:
  simpler, and matches the enum the API already returns.
- **List sort** — recommend folders-first then files by name ascending, no
  persisted sort preference for v1. Flag now in case you want date/size
  sort sooner.
