# AWS S3 instance

Storage setup for an instance that keeps its files in AWS S3. Do the
[common setup](deploy.md#common-setup) first; this covers only what's specific to AWS.

Files are stored in a private S3 bucket (the `s3` driver, the same one R2 uses) and
served by CloudFront in front of it. Your server never serves file downloads.

```
upload:   client → Explorer (PHP, your server) → S3 bucket (private)
download: browser → cdn.example.com (CloudFront) → S3 bucket, via Origin Access Control
```

Pick this over [Cloudflare R2](setup-s3-cloudflare.md) when the file domain's DNS has
to stay where it is: R2 custom domains only work on zones managed by Cloudflare DNS,
while CloudFront only needs two CNAME records, at any DNS provider.

The examples use `files.example.com` for the app (`app_url`), `cdn.example.com` for the
files, `explorer-files` for the bucket and `us-east-1` for the region. The app and the
files must be different hostnames: the files hostname points at CloudFront, so using
the app's hostname would take the API and the admin panel offline.

You'll go through four AWS consoles: Billing, S3, CloudFront and IAM. Use the search bar
at the top of the [AWS console](https://console.aws.amazon.com/) to jump between them.
CloudFront and IAM are global; only the bucket has a region, which you choose in step 2.

## 1. AWS account

### Free account plan vs Paid account plan

Since July 2025, signing up for AWS asks you to choose an
[account plan](https://docs.aws.amazon.com/awsaccountbilling/latest/aboutv2/free-tier-plans.html):

| | Free account plan | Paid account plan |
|---|---|---|
| Sign-up credits | USD 100, plus up to 100 more for onboarding activities | the same |
| Can you be charged? | never: services that could exceed the credits are blocked | yes, for usage beyond the credits |
| When it ends | after **6 months**, or when the credits run out, whichever comes first | never |
| What happens then | the account **closes automatically**: you lose access to all resources, and AWS deletes them 90 days later unless you upgrade | nothing; you're billed pay-as-you-go once the credits are gone |

The Free account plan is a sandbox: it guarantees you never get a bill, and the price of
that guarantee is that it shuts down. An Explorer instance on it stops serving files at
the 6-month mark. **For an instance you mean to keep, pick the Paid account plan.** The
credits are the same and are spent first.

If you already picked the Free plan, upgrade any time from the console:
[**Billing → Upgrade plan**](https://console.aws.amazon.com/billing/home?#/freetier/upgrade).
Leftover credits carry over. The plan your account is on shows on the
[Billing and Cost Management](https://console.aws.amazon.com/costmanagement/) home page.
Accounts created before July 2025 don't have account plans and never auto-close.

### Sign up and secure it

1. [Create the account](https://docs.aws.amazon.com/accounts/latest/reference/manage-acct-creating.html)
   at [aws.amazon.com](https://aws.amazon.com/) and pick the account plan. A credit card
   is required either way.
2. [Turn on MFA for the root user](https://docs.aws.amazon.com/IAM/latest/UserGuide/enable-virt-mfa-for-root.html).
   The root user can do anything, including closing the account; never create access
   keys for it. Explorer gets its own, limited user in step 6.
3. [Create a budget](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-create.html):
   **Billing and Cost Management → Budgets → Create budget → Use a template → Zero
   spend budget** (or a monthly cost budget of e.g. USD 1). On the Paid plan, this is
   what tells you by e-mail that something costs money, before it shows up on an invoice.

### What it costs

As of October 2026 ([CloudFront pricing](https://aws.amazon.com/cloudfront/pricing/),
[S3 pricing](https://aws.amazon.com/s3/pricing/)):

- **CloudFront flat-rate Free plan**: USD 0/month for one distribution, with no time
  limit (up to 3 per account). It includes 1M requests and 100 GB of transfer a month,
  the TLS certificate, and 5 GB of S3 Standard storage credit for the account. It
  [never bills overages](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/flat-rate-pricing-plan.html#usage-exceeds-allowance):
  the allowance isn't a hard limit, a first spike up to 3x is ignored, and only
  sustained excess over 2-3 months may get the traffic "adjusted" (served from fewer
  edge locations, slower). Upgrading to Pro restores it.
- **S3**: transfer from S3 to CloudFront is free. Storage over the 5 GB credit and
  request fees are billed normally; both are small for a typical instance, and the
  year-long `Cache-Control` set on upload keeps requests to S3 low.

## 2. Create the bucket

[**S3 → Buckets → Create bucket**](https://docs.aws.amazon.com/AmazonS3/latest/userguide/create-bucket-overview.html):

- **Bucket type**: General purpose.
- **Bucket name**: e.g. `explorer-files`. Names are global across all of AWS, so a
  common one may be taken; add a suffix if needed.
- **AWS Region**: the one closest to your users (`us-east-1` is usually the cheapest).
  Note it: it goes into `storage.conf` in step 7.
- **Object Ownership**: ACLs disabled (the default). Explorer doesn't use ACLs.
- **Block Public Access**: leave **Block all public access** on. CloudFront reads the
  bucket privately (step 3), and nothing else should.
- **Bucket Versioning**: optional; see [Operations](#operations).
- **Default encryption**: leave the default (SSE-S3).

## 3. Create the CloudFront distribution

[**CloudFront → Distributions → Create distribution**](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/GettingStarted.SimpleDistribution.html#GettingStartedCreateDistribution).
The wizard also asks for a **pricing plan**: choose **Free**. You can
[upgrade later](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/flat-rate-pricing-plan.html#pricing-plan-upgrade)
from the distribution's **Manage plan**. The rest of the wizard:

1. **Distribution name**: e.g. `explorer-files`. It's only a label (a `Name` tag).
2. **Single website or app** (not *Multi-tenant architecture*) → **Next**.
3. **Domain setup**: **skip it** unless your domain is in Route 53. For any other DNS
   provider the domain is added after the distribution exists (step 4). **Next**.
4. **Origin type**: **Amazon S3**. **S3 origin**: **Browse S3** and pick the bucket from
   step 2.
5. **Settings**: **Use recommended origin settings**. For a private S3 bucket that means
   ([full list](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/template-preconfigured-origin-settings.html#s3-origin-preconfiguration)):
   - [Origin access control (OAC)](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-restricting-access-to-s3.html),
     so only this distribution can read the bucket
   - viewer protocol policy **Redirect to HTTPS**, methods `GET, HEAD`, compression on
   - cache policy [`CachingOptimized`](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-managed-cache-policies.html),
     which respects the year-long `Cache-Control` saved on each object
   - response headers policy **None**: fixed in step 5

   **Next**.
6. **Enable security protections**: the WAF protections included in the Free plan.
   Keeping them on is fine. **Next**.
7. Review → **Create distribution**. CloudFront adds the OAC statement to the bucket
   policy itself.

Check it did: **S3 → explorer-files → Permissions → Bucket policy** should have a
statement allowing `cloudfront.amazonaws.com` to `s3:GetObject`, conditioned on this
distribution's ARN. Without it, every file answers 403.

Copy the **Distribution domain name** from the distribution's details,
`dxxxxxxxxxxxx.cloudfront.net`. The **Last modified** field says "Deploying" for a few
minutes after each change.

## 4. Add the domain

[Add a domain to the distribution](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/add-domain-existing-distribution.html).
CloudFront creates the free TLS certificate itself, in
[AWS Certificate Manager](https://docs.aws.amazon.com/acm/latest/userguide/acm-overview.html),
region `us-east-1`. You only add DNS records.

Most DNS panels, Hostinger's included (**hPanel → Domains → your domain → DNS /
Nameservers**), append the domain to a record's name themselves. Type only the part
before your domain, without a trailing dot: `_3f1a…c2.cdn`, not
`_3f1a…c2.cdn.example.com.`.

1. Open the distribution → **Settings** → **Alternate domain names** → **Add a domain**.
2. Enter `cdn.example.com` → **Next**.
3. **TLS certificate**: there's no certificate for the domain yet, so create one. It
   shows a validation record:
   ```
   CNAME name:  _3f1a…c2.cdn.example.com.
   CNAME value: _9b7e…d4.xyzabc.acm-validations.aws.
   ```
   Add it at your DNS provider (name `_3f1a…c2.cdn`, type `CNAME`, target
   `_9b7e…d4.xyzabc.acm-validations.aws`). Check it's visible:
   ```sh
   dig +short CNAME _3f1a…c2.cdn.example.com
   ```
   then choose **Validate certificate**. Validation usually takes a few minutes; it can
   take longer while the DNS provider publishes the record. **Keep this record**
   afterwards: ACM uses it to renew the certificate automatically.
4. **Next** → review → **Add domains**.
5. Point the domain at CloudFront. Under **Settings**, **Route domains to CloudFront**
   shows the target. At your DNS provider, add:

   | Name | Type | Target |
   |---|---|---|
   | `cdn` | CNAME | `dxxxxxxxxxxxx.cloudfront.net` |

   Check it with `dig +short cdn.example.com`, which should show the CloudFront
   hostname. Then back in the console choose **Submit**. The domain shows as active
   once CloudFront sees the record.

## 5. Add the `nosniff` header

The recommended settings leave the response headers policy empty, so add it by hand:

1. Open the distribution → **Behaviors** tab → select the **Default (\*)** behavior →
   **Edit**.
2. **Response headers policy**: [`SecurityHeadersPolicy`](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/using-managed-response-headers-policies.html).
3. **Save changes**.

It adds `X-Content-Type-Options: nosniff` (plus HSTS, `X-Frame-Options: SAMEORIGIN` and a
`Referrer-Policy`). Without `nosniff`, browsers may guess a user-uploaded file's type and
render it as something more dangerous. It's an AWS-managed policy, so the Free plan
allows it; custom header policies need the Business plan.

## 6. Create the access key

Explorer needs a key that can write to this bucket and nothing else. In IAM that's three
pieces: a **policy** (what's allowed), a **user** the policy is attached to, and an
**access key** for that user. Create them in that order: the user wizard can only attach
a policy that already exists.

### 6a. The policy

[**IAM → Policies → Create policy**](https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_create-console.html#access_policies_create-json-editor)
(**Policies** is in IAM's left menu, under **Access management**):

1. In **Policy editor**, switch from **Visual** to **JSON**, delete what's there and
   paste (with your bucket name):
   ```json
   {
   	"Version": "2012-10-17",
   	"Statement": [
   		{
   			"Effect": "Allow",
   			"Action": ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"],
   			"Resource": "arn:aws:s3:::explorer-files/*"
   		},
   		{
   			"Effect": "Allow",
   			"Action": "s3:ListBucket",
   			"Resource": "arn:aws:s3:::explorer-files"
   		}
   	]
   }
   ```
   `ListBucket` is optional: it makes S3 answer 404 instead of 403 for a missing
   object, so a missing file and a permissions problem don't look the same when
   debugging.
2. **Next**.
3. **Policy name**: e.g. `explorer-files-rw` → **Create policy**.

### 6b. The user

[**IAM → Users → Create user**](https://docs.aws.amazon.com/IAM/latest/UserGuide/getting-started-workloads.html):

1. **Specify user details**: **User name** `explorer`. Leave **Provide user access to
   the AWS Management Console** unchecked: Explorer only needs an access key. **Next**.
2. **Set permissions**: **Permissions options** → **Attach policies directly**. Below
   it, search for `explorer-files-rw` (or set the type filter to **Customer managed**)
   and tick it. **Next**.
3. **Review and create** → **Create user**.

If you already created the user without a policy: **Users → explorer → Permissions**
tab → **Add permissions** (a dropdown; choose **Add permissions** in it) → **Attach
policies directly** → tick `explorer-files-rw` → **Next** → **Add permissions**.

### 6c. The access key

[**Users → explorer → Security credentials** tab → **Access keys** → **Create access key**](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html):

1. **Use case**: **Application running outside AWS** → **Next**.
2. **Description tag**: optional, e.g. `explorer storage.conf` → **Create access key**.
3. Copy the **Access key** and the **Secret access key**, or **Download .csv file**.
   The secret is shown only once: if it's lost, deactivate and delete the key, then
   create a new one.

## 7. storage.conf

```ini
[production]
	driver = "s3"
	s3_endpoint = "https://s3.us-east-1.amazonaws.com"   ; the bucket's region
	s3_region = "us-east-1"
	s3_bucket = "explorer-files"
	s3_access_key = "<access key id>"
	s3_secret_key = "<secret access key>"
	s3_path_style = false
	s3_public_url = "https://cdn.example.com"            ; no trailing slash needed
```

Where each value comes from:

| Key | Where to find it |
|---|---|
| `s3_bucket` | **S3 → General purpose buckets**: the **Name** column. Just the name (`explorer-files`), not an ARN or URL. |
| `s3_region` | the same list, **AWS Region** column, e.g. "South America (São Paulo) `sa-east-1`". Use only the code: `sa-east-1`. It's also on the bucket's **Properties** tab, under **Bucket overview**. |
| `s3_endpoint` | built from the region code: `https://s3.<region>.amazonaws.com`, e.g. `https://s3.sa-east-1.amazonaws.com`. Without the bucket name in it. The full list is in [S3 endpoints](https://docs.aws.amazon.com/general/latest/gr/s3.html). |
| `s3_access_key`, `s3_secret_key` | the access key from step 6c |
| `s3_public_url` | `https://` + the domain from step 4 |

`s3_endpoint` and `s3_region` must both match the bucket's region. A mismatch makes
uploads fail (see [Troubleshooting](#troubleshooting)).

`s3_path_style` decides how Explorer addresses the bucket when it talks to the S3 API:

- `false`, **virtual-hosted style**: the bucket goes in the hostname,
  `https://explorer-files.s3.sa-east-1.amazonaws.com/<file>`. This is what AWS
  [recommends](https://docs.aws.amazon.com/AmazonS3/latest/userguide/VirtualHosting.html),
  so use it here. It needs a bucket name without dots, or the TLS certificate won't
  match.
- `true`, **path style**: the bucket goes in the path,
  `https://s3.sa-east-1.amazonaws.com/explorer-files/<file>`. R2 needs this; AWS still
  accepts it but treats it as legacy.

It only affects uploads and deletes. The URLs Explorer hands out always start with
`s3_public_url`.

`s3_public_url` is **required**: without it, file URLs fall back to the S3 endpoint, and
the bucket is private, so every file link fails. The `local_*` keys are ignored with
`driver = "s3"` and can stay empty.

## 8. Check it

```sh
curl -I https://cdn.example.com/
```

It should connect with no certificate error. A `403` with an S3 `AccessDenied` body is
expected for the root. In the admin panel, **Storage** should show the `s3` driver and
your settings.

Then upload a file (the smoke test in [deploy.md](deploy.md#8-smoke-test)) and check its
`url`:

```sh
curl -I https://cdn.example.com/<path from the upload response>
```

Look for `200`, the right `content-type`, a `content-disposition` with the original
filename, and `x-content-type-options: nosniff`. A second request should show
`x-cache: Hit from cloudfront`.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Upload fails with `PermanentRedirect` or `AuthorizationHeaderMalformed` | `s3_endpoint` / `s3_region` don't match the bucket's region |
| Upload fails with `AccessDenied` | the IAM policy's bucket name is wrong, or the policy isn't attached to the user whose key is in `storage.conf` |
| Upload fails with `SignatureDoesNotMatch` / `InvalidAccessKeyId` | key or secret copied wrong |
| Upload works, file URL answers 403 | the OAC statement in the bucket policy (step 3) is missing, or `s3_public_url` points somewhere else |
| Browser shows a certificate error on `cdn.example.com` | the domain from step 4 isn't active yet, or the distribution is still deploying |
| **Add a domain** doesn't offer the certificate you requested | it was requested in another region. CloudFront only uses certificates in `us-east-1`; create one there (or let Add a domain create it) and delete the other |
| Certificate validation never completes | the validation CNAME is wrong: often the domain got appended twice (`…cdn.example.com.example.com`). Check with `dig` |
| Can't add the alternate domain (`CNAMEAlreadyExists`) | another distribution, possibly in another AWS account, already claims it |

## Web server

Just the docroot. Leave out the `/storage` alias; `scripts/deploy.sh vhost` already
leaves it out when `driver = "s3"`.

## How S3 serving differs from local storage

- **Headers are set once, at upload.** `Content-Type`, `Content-Disposition` (with the
  original filename) and `Cache-Control: public, max-age=31536000, immutable` are saved
  on the object when it's uploaded. Changing them for existing files means re-uploading.
  The long cache is safe because every upload gets a new random token; a file is
  never overwritten at the same URL.
- **Deletes may lag at the edge.** Deleting a file removes the object from S3, but
  CloudFront can keep serving its cached copy. If takedowns need to be immediate,
  [create an invalidation](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/Invalidation.html)
  for the URL in CloudFront too.
- **No CORS setup needed.** The admin app only links to file URLs; it never reads them
  from JavaScript.

## Operations

- **Backups**: the database is still on your server and needs backing up. S3 holds the
  files; turn on [bucket versioning](https://docs.aws.amazon.com/AmazonS3/latest/userguide/Versioning.html)
  or replication if you need protection against accidental deletes. With versioning,
  deleted files keep using (billed) storage until a lifecycle rule expires old versions.
- **Usage**: the distribution's **Manage plan** shows how much of the monthly allowance
  is used, and AWS e-mails at 50%, 80% and 100%.
- **Rotating the key**: create a second access key for the `explorer` user, put it in
  `storage.conf`, check an upload works, then deactivate and delete the old one.
