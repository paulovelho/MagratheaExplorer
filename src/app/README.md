# /app

Placeholder for the Angular/Vue admin app. Served at `/app` (see
`docker/apache/site-dev.conf`'s `Alias /app`), sibling to the versioned JSON
API at `/api/v1` (`../api`).

Nothing lives here yet — framework choice and source layout (in-place vs. a
separate top-level source folder that builds into this one) are open
questions for the next step. `index.html` is a temporary stand-in so the
route resolves to something in the meantime.
