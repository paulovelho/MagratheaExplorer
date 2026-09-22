-- 1.2.0 -> 1.3.0: share links.
-- Additive only: one new table, no ALTER, no backfill, no ordering requirement against
-- serving traffic. Rollback is `DROP TABLE shares;`.
-- (database.sql is the fresh-install schema; this folder is for upgrading a running instance.)

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
