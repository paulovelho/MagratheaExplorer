-- MagratheaPHP 3: every model table needs created_at/updated_at.
-- Every SELECT 3.0 builds from a model reads both columns (and GetAll()/GetWhere() sort by
-- created_at), so without them every Tag and ScheduledDeletion query fails -- including the
-- tag lookups on upload. `vendor/bin/magrathea models:verify` reports them as ERRORs.
--
-- Additive only: existing rows get the time of the ALTER. Both tables are small (one row per
-- tag name / per pending key deletion), so the ALTERs are quick. Take a dump first:
--   vendor/bin/magrathea db:dump before-1.4.0

ALTER TABLE `tags`
	ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE `scheduled_deletions`
	ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
