# Cloudflare R2 instance

Storage setup for an instance that keeps its files in Cloudflare R2. Do the
[common setup](deploy.md#common-setup) first; this covers only what's specific to R2.

Files are stored in an R2 bucket through R2's S3-compatible API (the `s3` driver, which
also works for real AWS S3), and served directly by Cloudflare from the bucket's public
URL. Your server never serves file downloads.

The bucket's public domain has to be on Cloudflare DNS (step 2). If that domain's DNS
must stay at another provider, use [AWS S3](setup-s3-aws.md) instead.

## 1. Create the bucket

Cloudflare dashboard → **R2** → **Create bucket**, e.g. `explorer-files`. Pick the
location hint closest to your users.

## 2. Make it publicly readable

Explorer hands out plain public URLs, so the bucket needs a public domain. In the
bucket's **Settings → Public access**:

- **Custom domain (use this in production)**: connect e.g. `cdn.files.example.com`. You
  get Cloudflare caching, and you can add response headers (step 5).
- **r2.dev subdomain**: one click, but rate-limited and meant for testing only.

The custom domain is **not** the hostname Explorer runs on (`app_url`). Connecting a
hostname to the bucket points its DNS at R2, so using the app's hostname would take the
API and the admin panel offline. Use a separate one: e.g. `files.example.com` for the
app, `cdn.files.example.com` for the bucket.

Its main domain (`example.com`) must be a zone **on the same Cloudflare account as the
bucket, with its DNS managed by Cloudflare**: nameservers pointed at Cloudflare and the
zone *Active*, not *Pending*. Otherwise Cloudflare refuses the connection with "That
domain was not found on your account". If the domain's DNS is hosted elsewhere, either
move it to Cloudflare first (check that every existing record, MX included, was imported
before switching nameservers), or use another domain that's already on Cloudflare.
Once the zone is there, Cloudflare creates the custom domain's DNS record itself; you
don't add one.

Whichever one you use is your `s3_public_url`. **It's required for R2.** Without it, file
URLs fall back to the S3 API endpoint, which is not public, so every file link fails.

## 3. Create an API token

**R2 → Manage R2 API Tokens → Create API token**:

- Permissions: **Object Read & Write**
- Scope: **only** the bucket from step 1

Save the **Access Key ID** and **Secret Access Key**: the secret is shown only once. The
S3 endpoint is on the same screen: `https://<account_id>.r2.cloudflarestorage.com`.

## 4. storage.conf

```ini
[production]
	driver = "s3"
	s3_endpoint = "https://<account_id>.r2.cloudflarestorage.com"
	s3_region = "auto"                  ; R2 always uses "auto"
	s3_bucket = "explorer-files"
	s3_access_key = "<access key id>"
	s3_secret_key = "<secret access key>"
	s3_path_style = true
	s3_public_url = "https://cdn.files.example.com"   ; no trailing slash needed
```

The `local_*` keys are ignored with `driver = "s3"` and can stay empty.

## 5. Add `nosniff` on the public domain

R2 can't store an `X-Content-Type-Options` header on an object (S3's `PutObject` has no
such field), so add it at Cloudflare: on the custom domain's zone, go to **Rules →
Transform Rules → Modify Response Header**, match hostname `cdn.files.example.com`, and
**set** `X-Content-Type-Options` = `nosniff`. Without it, browsers may guess a
user-uploaded file's type and render it as something more dangerous. You can't do this
on `r2.dev`, which is one more reason not to use it in production.

## Web server

Just the docroot. Leave out the `/storage` alias; `scripts/deploy.sh vhost` already
leaves it out when `driver = "s3"`.

## How R2 serving differs from local storage

- **Headers are set once, at upload.** `Content-Type`, `Content-Disposition` (with the
  original filename) and `Cache-Control: public, max-age=31536000, immutable` are saved
  on the object when it's uploaded. Changing them for existing files means re-uploading.
  The long cache is safe because every upload gets a new random token; a file is
  never overwritten at the same URL.
- **Deletes may lag at the edge.** Deleting a file removes the object from R2, but
  Cloudflare's cache on the custom domain can keep serving it until it expires. If
  takedowns need to be immediate, purge the URL in Cloudflare too.
- **No CORS setup needed.** The admin app only links to file URLs; it never reads them
  from JavaScript. You'd only need a CORS policy on the bucket if a client starts
  `fetch()`ing files directly.

## Operations

- **Backups**: the database is still on your server and needs backing up. R2 holds the
  files; turn on bucket object versioning or copy to a second bucket if you need
  protection against accidental deletes.
- **Cost**: R2 bills for storage and operations, not for downloads.
