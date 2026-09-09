# app/src

Source for the admin app: Vue 3 + Vite. `app/` is a top-level project
sibling to `api/` (see the repo root layout).

`npm run build` outputs to `app/dist` (gitignored), which is what's actually
served at `/app` — `docker/apache/site-dev.conf`'s `Alias /app` and
`docker-compose.yml`'s volume mount both point at `app/dist`, not `app/src`.
Root (`/`) redirects to `/app` (see `api/src/.htaccess`).

Nothing is scaffolded yet — until the Vite build exists, `/app` serves
nothing (`app/dist` is empty/absent).
