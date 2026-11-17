-- 1.1.x -> 1.2.0: UUID identity + per-key storage directory, in place.
-- 1.2.0 shipped as a fresh start (reload database.sql, re-import every file). This is the
-- data-preserving alternative for an instance still on the 1.1.x schema, the one
-- `vendor/bin/magrathea models:verify` reports as:
--   Key::$dbValues[storage_uuid], Folder::$dbValues[uuid], File::$dbValues[uuid],
--   File::$dbValues[thumbnail_path] "has no column ...: writes fail".
--
-- Nothing in storage moves. Files uploaded before this keep their flat 1.1.x paths
-- (`{token}.{ext}`, thumbnail `{thumbnail_token}.{png|jpg}`): every read, delete and orphan
-- check goes through the stored `storage_path`/`thumbnail_path`, so they keep working, and so
-- does every storage URL already handed out. New uploads go under `{storage_uuid}/`.
-- Bearer keys (`access_keys.uuid`) are unchanged: integrations keep working.
--
-- Backfilled ids come from UUID() (v1); the app generates v7 for new rows. Nothing checks the
-- version, and none of these ids is a secret.
--
-- Run once, before 1.3.0_shares.sql. Take a dump first:
--   vendor/bin/magrathea db:dump before-1.2.0

ALTER TABLE `access_keys`
	ADD COLUMN `storage_uuid` char(36) NULL AFTER `uuid`;
UPDATE `access_keys` SET `storage_uuid` = UUID() WHERE `storage_uuid` IS NULL;
ALTER TABLE `access_keys`
	MODIFY `storage_uuid` char(36) NOT NULL COMMENT 'Public, non-secret storage directory name for this key''s objects. Never swap with `uuid`.',
	ADD UNIQUE KEY `storage_uuid` (`storage_uuid`);

ALTER TABLE `folders`
	ADD COLUMN `uuid` char(36) NULL AFTER `id`;
UPDATE `folders` SET `uuid` = UUID() WHERE `uuid` IS NULL;
ALTER TABLE `folders`
	MODIFY `uuid` char(36) NOT NULL,
	ADD UNIQUE KEY `uuid` (`uuid`);

ALTER TABLE `files`
	ADD COLUMN `uuid` char(36) NULL AFTER `id`,
	ADD COLUMN `thumbnail_path` varchar(255) NULL AFTER `no_convert`;
UPDATE `files` SET `uuid` = UUID() WHERE `uuid` IS NULL;
-- 1.1.x stored thumbnails at {thumbnail_token}.png for a PNG, .jpg for anything else
-- (ImageProcessor::BuildThumbnail), and built their URL the same way.
UPDATE `files`
	SET `thumbnail_path` = CONCAT(`thumbnail_token`, '.', IF(LOWER(`extension`) = 'png', 'png', 'jpg'))
	WHERE `thumbnail_token` IS NOT NULL AND `thumbnail_path` IS NULL;
ALTER TABLE `files`
	MODIFY `uuid` char(36) NOT NULL,
	ADD UNIQUE KEY `uuid` (`uuid`);
