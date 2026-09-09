-- Magrathea system tables (framework-required, unchanged from platypustechnology/magratheaphp2)

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
	`last_login` timestamp,
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

CREATE TABLE `access_keys` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`uuid` char(36) NOT NULL UNIQUE,
	`name` varchar(255) NOT NULL UNIQUE,
	`uses` int(11) NOT NULL DEFAULT 0,
	`usage_limit` int(11) NULL,
	`total_size` bigint(20) NOT NULL DEFAULT 0,
	`usage_limit_mb` int(11) NULL,
	`expiration` datetime NULL,
	`active` tinyint(1) NOT NULL DEFAULT 1,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE `scheduled_deletions` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`key_id` int(11) NOT NULL,
	`requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`execute_at` datetime NOT NULL,
	`cancelled_at` TIMESTAMP NULL,
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`)
);

CREATE TABLE `folders` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`key_id` int(11) NOT NULL,
	`parent_id` int(11) NULL,
	`name` varchar(255) NOT NULL,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY `uniq_sibling_name` (`key_id`, `parent_id`, `name`),
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`)
);

CREATE TABLE `files` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`token` char(21) NOT NULL UNIQUE,
	`thumbnail_token` char(21) NULL UNIQUE,
	`key_id` int(11) NOT NULL,
	`folder_id` int(11) NOT NULL,
	`name` varchar(255) NOT NULL,
	`storage_path` varchar(255) NOT NULL,
	`extension` varchar(16) NULL,
	`mime_type` varchar(127) NOT NULL,
	`file_type` enum('image','audio','video','document','other') NOT NULL,
	`size` int(11) NOT NULL,
	`width` int(11) NULL,
	`height` int(11) NULL,
	`duration` int(11) NULL,
	`no_convert` tinyint(1) NOT NULL DEFAULT 0,
	`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (`key_id`) REFERENCES `access_keys`(`id`),
	FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`)
);

CREATE TABLE `tags` (
	`id` int(11) PRIMARY KEY AUTO_INCREMENT,
	`name` varchar(100) NOT NULL UNIQUE
);

CREATE TABLE `file_tags` (
	`file_id` int(11) NOT NULL,
	`tag_id` int(11) NOT NULL,
	PRIMARY KEY (`file_id`, `tag_id`),
	FOREIGN KEY (`file_id`) REFERENCES `files`(`id`),
	FOREIGN KEY (`tag_id`) REFERENCES `tags`(`id`)
);
