# Local storage instance

Storage setup for an instance that keeps its files on the server's own disk. Do the
[common setup](deploy.md#common-setup) first; this covers only what's specific to local
storage.

Files are written to `local_path` as `{key-storage-uuid}/{token}.{ext}` and served by
the web server at `local_url`.

## storage.conf

```ini
[production]
	driver = "local"
	local_path = "/var/www/explorer-storage"
	local_url = "https://files.example.com/storage"
```

- Keep `local_path` **outside** `api/src/`, so uploaded files never sit inside the PHP
  app's docroot. They're served only through the `/storage` alias.
- `local_url` is `app_url` + `/storage`, the path the vhost aliases.

## What `deploy.sh install` does for it

It creates `local_path` and copies `api/storage/.htaccess` into it. That file:

- turns the `?disposition=…&filename=…` query string on download URLs into a real
  `Content-Disposition` header, so files download under their original name instead of
  `{token}.{ext}`
- disables directory listings. A listing of a key's folder would be a full inventory of
  that key's files.

If you move or recreate `local_path` by hand, copy that `.htaccess` back.

## Web server

Apache needs, besides the docroot:

```apache
Alias /storage /var/www/explorer-storage
<Directory /var/www/explorer-storage>
    Options -Indexes +FollowSymLinks
    AllowOverride All          # lets the storage .htaccess run
    Require all granted
    Header set X-Content-Type-Options "nosniff"
</Directory>
```

and both modules enabled: `a2enmod rewrite headers`. Without `mod_headers`, files still
download, but under their opaque token name.

The `nosniff` header stops browsers from guessing a user-uploaded file's type and
rendering it as something more dangerous than its sniffed `Content-Type`. The storage
`.htaccess` doesn't set it; `scripts/deploy.sh vhost` includes it, and so does the `/storage`
block in `api/site.caddy.sample`.

On Caddy, the `handle_path /storage/*` block in `api/site.caddy.sample` does the same
job; no `.htaccess` is involved.

## Operations

- **Disk space**: every upload plus its thumbnail lands here. Monitor the disk; the
  admin **Storage** page shows total usage across keys.
- **Backups**: back up `local_path` **and** the database together. Files without their
  DB rows are unreachable, and DB rows without their files are broken links.
