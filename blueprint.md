# Magrathea Explorer

This project is a new version of MagratheaImages3, but as MagratheaImages3 is focused on images, image handling, image size adjustment, and uses local storage, this project is intended to be used with different modules, which can be [local storage], [Cloudfare R2], or [Amazon S3] — more to come, if makes sense in the future.


## MagratheaImages3

A self-hosted **image hosting and on-demand resizing API**, built on the in-house **MagratheaPHP2** framework (PHP, feature-based architecture, MariaDB backend). Currently on v3.6.2.

MagratheaImages3 lives on `/mnt/Rincewind/MagratheaImages3`

### How it works

**Auth model** — API keys come in pairs tied to a folder on disk:
- `private_key` — write access (upload, delete)
- `public_key` — read access (view/serve images), safe to embed in `<img src>`

Keys are provisioned via `POST /key/create` using a shared server `secret`.

**Core flow**
1. **Upload** — send a file (or base64/URL) to a private-key endpoint under `Images/ImageUploader.php`. Each image gets both a numeric `id` and a `uuid`; metadata is stripped (`MetadataStripper.php`) and the file is stored under the key's folder.
2. **Serve/resize** — `GET /image/{public_key}/{id}/{size}` returns the image, resized on the fly (`ImageResizer.php`, `ResampleCalculator.php`) and cached to disk (`GeneratedFileManager`) so repeat requests skip re-processing. Size uses `WxH` notation (e.g. `800x600`). SVGs are always served raw.
3. **Delete** — `DELETE /key/{private_key}/delete/{id}` (numeric id only).

**Backups** — an R2 (Cloudflare object storage) module (`features/R2/`) periodically backs up the media library off-box; admin UI for it is still pending (phase 4 per your notes).

**Structure** (`src/api/`)
- `features/Apikey/`, `features/Images/`, `features/R2/` — the three feature modules (Magrathea's `feature` code-structure convention)
- `admin.php` / `admin/` — a basic web admin UI (key management, media/generated-file browsers)
- `swagger.yaml` — the machine-readable API contract (also mirrored in `skills.md` for AI agents)
- `error-manager/` — centralized error-code → message mapping
- System endpoints: `/version`, `/settings`, `/changelog`, `/error-codes`, `/validate`

**Deployment** — production runs as bare Apache (2 instances); Docker (`docker-compose.yml`) is dev-only.

**Status** — still in beta as of your last notes, so no urgency pressure on security/edge-case fixes; they can ride along with feature work.


## differences:
The main difference between MagratheaImages3 and MagratheaExplorer is how files are handled:
instead of externally depending on keys and uuids to find a file, this project intends to return a full url to the file that it is managing.

## Technology:
This project will be built with:

- PHP — using MagratheaPHP2
- MySQL
- external or local storage

### how link to files will work:

I will be able, through configuration, to define what kind of storage I want.
It can be local (which I give you a folder to upload the files), R2 or S3 (which I give you configurations to read and handle)
In any case, the whole application (the instance of it, as it can work with many instances) will use only ONE storage system. There will be no combinations of "this key uses R2, this key uses S3" — at least not in a first step, no concrete plans for implementing it in the future.

I'm not very proficient in how those storage systems works, but you will help me build this.

- An user should not find other images or files using the link of an image or file. So the urls should be enough obfuscate (what does not happens in MagratheaImages3, which always give the public key of the file)
- We don't need to return resized content. 
- When an image is uploaded, though, we can have the option of creating a thumbnail — and returning the url to the thumbnail also on the request.
- The API should handle which users have which images/files.
- An user should be able to delete a file, and this would also delete the file from the storage service.
- It should be possible to delete a user key — in this case, the key will go to a new `scheduled_deletion` table, where it will be for 14 days, when user can cancel the action. if not, a cron will handle the deletion of the key, and all the files that belong to the key.
- a file can only belong to one key.


## usage keys

As MagratheaImages3 uses apikeys, this Explorer will also have a special kind of keys.

- an user is represented by his key
- a key has an unique UUID and an unique name/identifier (set on the creation)
- a key might have an expiration date — in this case, files can still be read, but upload is disabled
- a key can become inactive — in this case, files can still be read, but upload is disabled.
- inactive or expired keys can still be deleted by the owner.

open questions:
- MagratheaImages3 handles the creation of new usage keys through a secret inside the API. is it a good method? any recommendation?
- MagratheaImages3 handles the upload of files through a private_key, which identifies the user. As:
  - user should not be aware from his key
  - only the user should be able to upload to his key
  , how can we implement this.
	Think on this problem as an external project connecting to `MagratheaExplorer`. The user from the other API will need a key to upload files here, but they will not be aware they are using another system. So, it will be the external project who will handle these connections

### usage keys database
Currently, MagratheaImages has the following table for apikeys:

```sql
-- as MagratheaImages3 currently use:
CREATE TABLE `keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`private_key` varchar(255) NULL,
	`public_key` varchar(255) NULL,
	`folder` varchar(255) NULL,
	`uses` int(11) NULL,
	`usage_limit` int(11) NULL,
	`expiration` datetime NULL,
	`active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

Which fields will still be necessary? Which ones can we drop?

## files to be uploaded

The system can accept any file, limited to a size defined in `ConfigApp` (5MB max default, if nothing there).

### images
If detected an image, the app will handle it and threat as an image. 
The return might be the link to the image and the link to the thumbnail, a square cut of the image, with the size in pixels defined in the `ConfigApp` (200 default)
We can remove the metadata from the image and compress it without losing quality.

open questions:
- ideally we could also convert to webp or other format to shrink it size. worths it?
- there are some images that we don't want to convert because of the fear of losing quality (metadata can be always removed). this should be specified in the upload request

#### images database
```sql
-- as MagratheaImages3 currently use:
CREATE TABLE `images` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`name` varchar(255) NULL,
	`filename` varchar(255) NULL,
	`extension` varchar(255) NULL,
	`folder` varchar(255) NULL,
	`subfolder` varchar(255) NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`file_type` varchar(255) NULL,
	`size` int(11) NULL,
	`upload_key` varchar(255) NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### audio/video
An user can upload an audio or a small video.
This application will handle them properly.

open questions:
- can we get the length of audio/video?
- do we need encoding for shrinking?

### pdf
We could also shrink pdfs if possible

### other files
If you can predict other types of files that will be pushed to this explorer, suggestions are accepted

#### more about files:
- the size of the file is mandatory. I should always be able to calculate the size of a folder by the sum of the files that belongs to it
- a file will always belong to a folder
- a file can have tags
- a file must have a type

## folder structure
As we don't need to physically split the files into folders when in the storage system, we can internally organize them into folders. this means a new table `folders`

- a folder can belong to another folder
- the top folder is called `root`

## request samples
The result of this project will be an API and a internal configuration system, managed through MagratheaAdmin.

examples of requests I might ask to the API:

- I need all the files from [key] in the [folder]
- I need all the audio files from [key]
- what is the total usage in MB from [key]

as you can see, the key is always required for fetching files. there are no thing as public files (a single request that brings files from multiple keys)

## result of this blueprint
This blueprint is a rough planning of the project.
You will carefully read it, carefully study it, carefully look onto MagratheaImages3 for inspiration.

The result will be a plan for the construction of this project/API. You will save this plan in this folder, in a file `plan.md`
I will read through the plan, we are going to work together on the questions (from both sides, I might have some questions as well). Only after everything is decided, we're gonna build it.

The final project should be (in this order):

- safe (impossible to upload files without a valid key or impossible to find out a private key through a file url)
- light (API requests should be handled fast. I can cache transactions that requires often quick checks — as we do in MagratheaImages3, caching the relation between a key and its folder)
- cheap (I am poor. I have a PHP/mySQL server free, but I will have to pay for a storage service. I don't want to pay much)
- manageable (I shoudl be able to remove orphan images — or avoid them to exist. I have control over what is being saved, and how much upload I am using — with reports or so)
- scalable (I can keep uploading, or can create new instances)


