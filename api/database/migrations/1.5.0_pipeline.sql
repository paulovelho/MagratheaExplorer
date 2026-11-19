-- 1.5.0: verbatim storage, sha256, per-key upload limits, the processing pipeline, key
-- provisioning with hashed secrets, Idempotency-Key, URL rotation, direct uploads and recursive
-- folder deletes. Upgrading a 1.4.x instance: apply this whole file once, after
-- 1.4.0_magrathea3.sql. The full procedure is in docs/v1.5.0-migration.md.
--
-- Take a dump first. Part 2 drops the plaintext key secrets, so the dump is the ONLY way back:
--   vendor/bin/magrathea db:dump before-1.5.0
--
-- MySQL/MariaDB commit every ALTER and CREATE on their own: this file can't be rolled back, and
-- it can't be re-run on a database it stopped halfway through (its ADD COLUMNs would fail). If
-- any statement fails, restore the dump, fix the cause, and run the whole file again.
--
-- Afterwards `vendor/bin/magrathea models:verify` must report no ERROR.
--
-- Two ALTERs rebuild a whole table (writes to it wait meanwhile): `files` in part 2 and, on
-- older MySQL versions, `folders` in part 3. On a large instance, run this off-peak.


-- =============================================================================================
-- Part 1. Verbatim storage, sha256, per-key upload limit, the processing pipeline
-- =============================================================================================

-- Verbatim storage.
-- `access_keys.verbatim_default`: what an upload that doesn't send `verbatim` does on this key.
-- NULL = the instance default (`verbatim_default` in magrathea.conf, false when absent), so
-- existing keys keep stripping and converting until the key or the instance says otherwise.
ALTER TABLE `access_keys`
	ADD COLUMN `verbatim_default` tinyint(1) NULL COMMENT 'Uploads without `verbatim`: NULL = the instance default (magrathea.conf `verbatim_default`).' AFTER `usage_limit_mb`;

-- `files.verbatim`: the file was stored exactly as sent. No existing file was.
ALTER TABLE `files`
	ADD COLUMN `verbatim` tinyint(1) NOT NULL DEFAULT 0 AFTER `no_convert`;

-- sha256.
-- `files.sha256`: lowercase hex of the stored bytes (the sender's hash only when nothing
-- re-encoded them). NULL on existing files: `php cron.php --backfill-sha256` fills them in
-- from storage, any time after this migration. The index is for per-key lookups by content.
ALTER TABLE `files`
	ADD COLUMN `sha256` char(64) NULL AFTER `size`,
	ADD KEY `idx_files_key_sha256` (`key_id`, `sha256`);

-- Per-key upload limit.
-- `access_keys.max_upload_size`: the largest upload this key accepts, in bytes. NULL = the
-- instance default (admin → App Config `max_upload_size`), so existing keys keep today's limit.
-- PHP's own limits still apply on top: `GET /key` reports the effective one.
ALTER TABLE `access_keys`
	ADD COLUMN `max_upload_size` bigint(20) NULL COMMENT 'Bytes. NULL = the instance default (ConfigApp `max_upload_size`).' AFTER `verbatim_default`;

-- Processing pipeline: properties, renditions, the queue.
-- `access_keys.rendition_profile`: the renditions this key's images get (a JSON list of specs,
-- see RenditionProfile). NULL = the instance default: one `thumb`, ConfigApp `thumbnail_size`
-- square, as before 1.5.0.
ALTER TABLE `access_keys`
	ADD COLUMN `rendition_profile` JSON NULL COMMENT 'Rendition specs (RenditionProfile). NULL = the instance default (one `thumb`, ConfigApp `thumbnail_size`).' AFTER `max_upload_size`;

-- `files.properties`: what the processor read (DPI, alpha, ICC, ...). NULL = never processed.
-- `files.processing_status`: none = no renditions are made for this file, pending = queued or
-- running, ready = done, failed = see `processing_error`.
ALTER TABLE `files`
	ADD COLUMN `properties` JSON NULL COMMENT 'What the processor read (1.5.0). NULL = never processed.' AFTER `duration`,
	ADD COLUMN `processing_status` enum('none','pending','ready','failed') NOT NULL DEFAULT 'none' AFTER `properties`,
	ADD COLUMN `processing_error` varchar(1000) NULL AFTER `processing_status`,
	MODIFY COLUMN `thumbnail_path` varchar(255) NULL COMMENT 'Unused since 1.5.0 (file_renditions). Kept for rollback.';

CREATE TABLE `file_renditions` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`file_id` int(11) NOT NULL,
	`name` varchar(32) NOT NULL,
	`token` char(21) NOT NULL UNIQUE,
	`storage_path` varchar(255) NOT NULL,
	`extension` varchar(16) NOT NULL,
	`mime_type` varchar(127) NOT NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`size` bigint(20) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_file_rendition` (`file_id`, `name`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`)
);

CREATE TABLE `processing_jobs` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`file_id` int(11) NOT NULL,
	`status` enum('queued','running','done','failed','superseded') NOT NULL DEFAULT 'queued',
	`attempts` int(11) NOT NULL DEFAULT 0,
	`lease_until` DATETIME NULL,
	`profile` JSON NOT NULL COMMENT 'The rendition specs, copied at enqueue: a profile edit mid-flight cannot mix specs.',
	`tokens` JSON NOT NULL COMMENT '{rendition name: token}, minted at enqueue: a retry overwrites the same objects.',
	`error` varchar(1000) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_jobs_claim` (`status`, `lease_until`),
	KEY `idx_jobs_file` (`file_id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`)
);

-- Today's thumbnails become `thumb` renditions: same object, same token, same URL. Their
-- width/height/size are unknown (NULL) until the file is regenerated. `files.thumbnail_token`
-- and `thumbnail_path` are no longer written or read, but stay for a rollback to 1.4.x (a later
-- version drops them). `updated_at = updated_at`: the files themselves didn't change.
INSERT INTO `file_renditions` (`file_id`, `name`, `token`, `storage_path`, `extension`, `mime_type`)
SELECT `id`, 'thumb', `thumbnail_token`, `thumbnail_path`,
	SUBSTRING_INDEX(`thumbnail_path`, '.', -1),
	IF(`thumbnail_path` LIKE '%.png', 'image/png', 'image/jpeg')
FROM `files` WHERE `thumbnail_token` IS NOT NULL AND `thumbnail_path` IS NOT NULL;

UPDATE `files` SET `processing_status` = 'ready', `updated_at` = `updated_at`
WHERE `thumbnail_token` IS NOT NULL AND `thumbnail_path` IS NOT NULL;


-- =============================================================================================
-- Part 2. Files of 2 GiB and over, key provisioning, hashed key secrets, Idempotency-Key
-- =============================================================================================

-- Files of 2 GiB and over.
-- `files.size` was a signed int(11), so 2147483647 bytes was the most it could hold. A bigger
-- upload passed every check, was put in storage, and then its row failed to insert (strict
-- mode) or got 2147483647 as its size (without it). `access_keys.total_size` and
-- `file_renditions.size` are already bigint.
-- The ALTER rebuilds `files` (a copy of the table, writes wait meanwhile): on a large instance,
-- run it off-peak.
ALTER TABLE `files`
	MODIFY `size` bigint(20) NOT NULL;

-- Key provisioning, and key secrets stored as hashes.

-- 1. Key secrets become sha256 hashes.
-- Until now `access_keys.uuid` held every key's bearer secret in plaintext, so every dump had
-- them all. From here on only sha256(lowercase secret) is stored: integrations keep sending the
-- same secret and keep working, but nobody can read a key's secret again, the admin included.
-- A lost one can only be rotated (admin → Keys, "Rotate secret", or POST /key/rotate-secret).
-- LOWER() because the old column compared case-insensitively: a key sent in upper case worked,
-- and still does (the API lowercases before hashing).
-- The plaintext column is dropped. The dump taken before this file is the only way back, and it
-- holds every secret in plaintext: once 1.5.0 has settled, delete it and every older dump.
ALTER TABLE `access_keys`
	ADD COLUMN `secret_hash` char(64) NULL AFTER `id`;
UPDATE `access_keys` SET `secret_hash` = SHA2(LOWER(TRIM(`uuid`)), 256);
ALTER TABLE `access_keys`
	MODIFY `secret_hash` char(64) NOT NULL COMMENT 'sha256 of the bearer secret, lowercased. The secret is shown once (creation, rotation) and never stored.',
	ADD UNIQUE KEY `uniq_key_secret_hash` (`secret_hash`),
	DROP COLUMN `uuid`;

-- 2. Keys created through POST /provision/keys are flagged: provisioning's `max_keys` counts
-- them, and the admin's key list filters by it.
ALTER TABLE `access_keys`
	ADD COLUMN `provisioned` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Created through POST /provision/keys.' AFTER `name`;

-- 3. Provisioning: one row, edited in admin → Provisioning. The secret that POST /provision/keys
-- takes (only its hash), how many keys it may create, and the settings every key it creates
-- copies (same meaning as the key's columns: NULL = the instance default).
CREATE TABLE `provisioning` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`secret_hash` char(64) NULL COMMENT 'sha256 of the provisioning secret (shown once, when generated). NULL = provisioning is off.',
	`max_keys` int(11) NULL COMMENT 'Most provisioned keys at once, pending deletions included. NULL = no limit.',
	`usage_limit` int(11) NULL,
	`usage_limit_mb` int(11) NULL,
	`verbatim_default` tinyint(1) NULL,
	`max_upload_size` bigint(20) NULL COMMENT 'Bytes.',
	`rendition_profile` JSON NULL,
	`last_used_at` DATETIME NULL COMMENT 'Last successful authentication, to the minute.',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT INTO `provisioning` (`id`) VALUES (1);

-- 4. Failed provisioning authentications, per client IP and 15-minute window (rate limit).
-- Purged after a day by `cron.php --process`.
CREATE TABLE `auth_failures` (
	`bucket` varchar(64) NOT NULL COMMENT '"prov:" + the client IP (IPv6: its /64)',
	`window_start` int(11) unsigned NOT NULL COMMENT 'Unix time the window starts',
	`failures` int(11) NOT NULL DEFAULT 0,
	PRIMARY KEY (`bucket`, `window_start`),
	KEY `idx_auth_failures_window` (`window_start`)
);

-- Idempotency-Key: POST /files and POST /provision/keys take an `Idempotency-Key` header, and a
-- retry with the same one gets what the first request made instead of a second file or key. One
-- row per (credential, key), kept at least 24 h (magrathea.conf `idempotency_retention_h`),
-- purged by `cron.php --process`. No model.
CREATE TABLE `idempotency_keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`credential_type` enum('key','provisioning') NOT NULL,
	`credential_id` int(11) NOT NULL COMMENT 'access_keys.id, or provisioning.id',
	`idempotency_key` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Binary collation: ABC and abc are two keys',
	`request_hash` char(64) NOT NULL COMMENT 'sha256 of the route and the canonical request, an upload''s bytes included',
	`status` enum('in_progress','done') NOT NULL DEFAULT 'in_progress',
	`attempt` int(11) NOT NULL DEFAULT 1 COMMENT 'Bumped by each takeover: only the request holding the current attempt can record a resource',
	`lease_until` int(11) unsigned NOT NULL COMMENT 'Unix time (the DB clock). After it, an in_progress row without a resource can be taken over',
	`resource_type` enum('file','key') NULL,
	`resource_id` int(11) NULL COMMENT 'files.id or access_keys.id. No FK: a deleted resource must not block its record, nor the record its deletion',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_idempotency` (`credential_type`, `credential_id`, `idempotency_key`),
	KEY `idx_idempotency_created` (`created_at`)
);


-- =============================================================================================
-- Part 3. The storage garbage queue, direct uploads, recursive folder delete
-- =============================================================================================
-- (POST /files/lookup needs no schema change.)

-- URL rotation and the storage garbage queue.
-- Storage deletes that mustn't fail the request (the objects a rotation or a processing run
-- replaced, an upload whose row didn't commit) are tried at once; one that fails is queued here,
-- and `cron.php --process` tries it again, 100 a run, up to 20 times. Past that it stays, and
-- admin -> Processing lists it. No model.
CREATE TABLE `storage_garbage` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`storage_path` varchar(255) NOT NULL COMMENT 'Object key, {storage_uuid}/{token}.{ext}',
	`attempts` int(11) NOT NULL DEFAULT 0 COMMENT 'Failed retries (the first, failed delete not counted)',
	`last_error` varchar(1000) NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_storage_garbage_path` (`storage_path`),
	KEY `idx_storage_garbage_retry` (`attempts`, `updated_at`)
);

-- Direct uploads: a key's backend mints a single-use token (POST /upload-tokens) and a browser
-- posts one file with it (POST /upload/:token, no key). Only the token's sha256 is stored.
-- Tokens a week past their expiry are purged by `cron.php --process`. The key's tokens are
-- deleted with it (KeyControl::DeleteKeyNow()).
CREATE TABLE `upload_tokens` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE COMMENT 'Public id: GET /upload-tokens/:uuid',
	`token_hash` char(64) NOT NULL UNIQUE COMMENT 'sha256 of the token in upload_url. The token itself is never stored',
	`key_id` int(11) NOT NULL,
	`folder_id` int(11) NOT NULL COMMENT 'No FK: checked when the upload arrives, and must not block a folder delete',
	`max_size` bigint(20) NOT NULL COMMENT 'Bytes',
	`file_types` varchar(64) NULL COMMENT 'Comma-separated file_type values. NULL = any',
	`verbatim` tinyint(1) NULL COMMENT 'As sent. NULL = the key''s default at upload time',
	`no_convert` tinyint(1) NULL,
	`tags` varchar(1000) NULL COMMENT 'JSON list of tag names',
	`status` enum('pending','uploading','used','failed') NOT NULL DEFAULT 'pending',
	`file_id` int(11) NULL COMMENT 'The file it made. No FK: informational, must not block a file delete',
	`error_code` int(11) NULL COMMENT 'Why a failed token was refused (an error code)',
	`error` varchar(1000) NULL,
	`expires_at` int(11) unsigned NOT NULL COMMENT 'Unix time, the DB clock (UNIX_TIMESTAMP()): no local-time DST overlap',
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_upload_tokens_expiry` (`status`, `expires_at`),
	KEY `idx_upload_tokens_key` (`key_id`),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`)
);

-- Recursive folder delete: DELETE /folder/:uuid?recursive=1 marks the whole subtree, so the API
-- stops seeing it at once, deletes small subtrees right away and leaves big ones to
-- `cron.php --process`. NULL = a live folder.
-- The ALTER rebuilds `folders` on older MySQL versions (writes wait meanwhile).
ALTER TABLE `folders`
	ADD COLUMN `deleting_at` DATETIME NULL COMMENT 'Set by a recursive delete on the whole subtree; the cron deletes it. NULL = live' AFTER `name`,
	ADD KEY `idx_folders_deleting` (`deleting_at`);
