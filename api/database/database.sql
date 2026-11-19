-- Magrathea system tables (framework-required, from platypustechnology/magratheaphp)

CREATE TABLE `_magrathea_config` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`name` varchar(255) UNIQUE,
	`value` varchar(255) DEFAULT NULL,
	`is_system` BOOLEAN NULL DEFAULT FALSE,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `_magrathea_roles` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`name` varchar(255) DEFAULT NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);

INSERT INTO `_magrathea_roles`
( `name` ) VALUES ( "super_admin" );

CREATE TABLE `_magrathea_users` (
	`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
	`email` varchar(255) UNIQUE,
	`password` varchar(255) DEFAULT NULL,
	`last_login` DATETIME NULL DEFAULT NULL,
	`role_id` int(11) NULL,
	`active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);

CREATE TABLE `_magrathea_logs` (
	`id` bigint(11) unsigned NOT NULL AUTO_INCREMENT,
	`user_id` int(11) NOT NULL,
	`action` varchar(255) NOT NULL,
	`victim` varchar(255) NULL,
	`info` text DEFAULT NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ,
	`updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`id`)
);

-- MagratheaExplorer project tables

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `access_keys`;
CREATE TABLE `access_keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`secret_hash` char(64) NOT NULL COMMENT 'sha256 of the bearer secret, lowercased. The secret is shown once (creation, rotation) and never stored.',
	`storage_uuid` char(36) NOT NULL UNIQUE COMMENT 'Public, non-secret storage directory name for this key''s objects.',
	`name` varchar(255) NOT NULL UNIQUE,
	`provisioned` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Created through POST /provision/keys.',
	`uses` int(11) NOT NULL DEFAULT 0,
	`usage_limit` int(11) NULL,
	`total_size` bigint(20) NOT NULL DEFAULT 0,
	`usage_limit_mb` int(11) NULL,
	`verbatim_default` tinyint(1) NULL COMMENT 'Uploads without `verbatim`: NULL = the instance default (magrathea.conf `verbatim_default`).',
	`max_upload_size` bigint(20) NULL COMMENT 'Bytes. NULL = the instance default (ConfigApp `max_upload_size`).',
	`rendition_profile` JSON NULL COMMENT 'Rendition specs (RenditionProfile). NULL = the instance default (one `thumb`, ConfigApp `thumbnail_size`).',
	`expiration` datetime NULL,
	`active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_key_secret_hash` (`secret_hash`)
);

-- One row (id 1), edited in admin -> Provisioning: POST /provision/keys's secret (its hash), its
-- key limit, and the settings every key it creates copies (NULL = the instance default).
DROP TABLE IF EXISTS `provisioning`;
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

-- Failed provisioning authentications per client IP and 15-minute window (rate limit). No model.
DROP TABLE IF EXISTS `auth_failures`;
CREATE TABLE `auth_failures` (
	`bucket` varchar(64) NOT NULL COMMENT '"prov:" + the client IP (IPv6: its /64)',
	`window_start` int(11) unsigned NOT NULL COMMENT 'Unix time the window starts',
	`failures` int(11) NOT NULL DEFAULT 0,
	PRIMARY KEY (`bucket`, `window_start`),
	KEY `idx_auth_failures_window` (`window_start`)
);

-- Idempotency-Key records, one per (credential, key), kept at least 24 h, purged by
-- `cron.php --process`. No model.
DROP TABLE IF EXISTS `idempotency_keys`;
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

-- Storage deletes that failed, retried by `cron.php --process` (StorageGarbage). No model.
DROP TABLE IF EXISTS `storage_garbage`;
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

DROP TABLE IF EXISTS `scheduled_deletions`;
CREATE TABLE `scheduled_deletions` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`key_id` int(11) NOT NULL,
	`requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`execute_at` datetime NOT NULL,
	`cancelled_at` TIMESTAMP NULL,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`)
);

DROP TABLE IF EXISTS `folders`;
CREATE TABLE `folders` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`key_id` int(11) NOT NULL,
	`parent_id` int(11) NULL,
	`name` varchar(255) NOT NULL,
	`deleting_at` DATETIME NULL COMMENT 'Set by a recursive delete on the whole subtree; the cron deletes it. NULL = live',
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_sibling_name` (`key_id`, `parent_id`, `name`),
	KEY `idx_folders_deleting` (`deleting_at`),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`)
);

DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`token` char(21) NOT NULL UNIQUE,
	`thumbnail_token` char(21) NULL UNIQUE,
	`key_id` int(11) NOT NULL,
	`folder_id` int(11) NOT NULL,
	`name` varchar(255) NOT NULL,
	`storage_path` varchar(255) NOT NULL,
	`extension` varchar(16) NULL,
	`mime_type` varchar(127) NOT NULL,
	`file_type` enum('image','audio','video','document','other') NOT NULL,
	`size` bigint(20) NOT NULL,
	`sha256` char(64) NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`duration` int(11) NULL,
	`properties` JSON NULL COMMENT 'What the processor read (1.5.0). NULL = never processed.',
	`processing_status` enum('none','pending','ready','failed') NOT NULL DEFAULT 'none',
	`processing_error` varchar(1000) NULL,
	`no_convert` tinyint(1) NOT NULL DEFAULT 0,
	`verbatim` tinyint(1) NOT NULL DEFAULT 0,
	`thumbnail_path` varchar(255) NULL COMMENT 'Unused since 1.5.0 (file_renditions). Kept for rollback.',
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_files_key_sha256` (`key_id`, `sha256`),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`)
);

DROP TABLE IF EXISTS `file_renditions`;
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

DROP TABLE IF EXISTS `processing_jobs`;
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

-- Direct-upload tokens (POST /upload-tokens, POST /upload/:token). Only the token's hash is stored.
DROP TABLE IF EXISTS `upload_tokens`;
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

DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`name` varchar(100) NOT NULL UNIQUE,
	`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

DROP TABLE IF EXISTS `file_tags`;
CREATE TABLE `file_tags` (
	`file_id` int(11) NOT NULL,
	`tag_id` int(11) NOT NULL,
	PRIMARY KEY (`file_id`, `tag_id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`),
	FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`)
);

DROP TABLE IF EXISTS `shares`;
CREATE TABLE `shares` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE COMMENT 'The share link itself. Public but unguessable -- holding it IS the access check.',
	`key_id` int(11) NOT NULL,
	`file_id` int(11) NULL,
	`folder_id` int(11) NULL,
	`views` int(11) NOT NULL DEFAULT 0,
	`last_viewed_at` datetime NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY `idx_shares_key` (`key_id`),
	KEY `idx_shares_file` (`file_id`),
	KEY `idx_shares_folder` (`folder_id`),
	CONSTRAINT `chk_share_target` CHECK (
		(`file_id` IS NOT NULL AND `folder_id` IS NULL) OR
		(`file_id` IS NULL AND `folder_id` IS NOT NULL)
	),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`),
	FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`)
);

SET FOREIGN_KEY_CHECKS = 1;
