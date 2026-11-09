USE `du-an-1`;

SET @comments_status_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'comments'
      AND COLUMN_NAME = 'status'
);

SET @comments_status_migration = IF(
    @comments_status_exists = 0,
    'ALTER TABLE `comments` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 AFTER `content`',
    'SET @comments_status_exists = @comments_status_exists'
);

PREPARE comments_status_statement FROM @comments_status_migration;
EXECUTE comments_status_statement;
DEALLOCATE PREPARE comments_status_statement;