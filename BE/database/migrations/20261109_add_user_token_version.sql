USE `du-an-1`;

SET @users_token_version_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'token_version'
);

SET @users_token_version_migration = IF(
    @users_token_version_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `token_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_active`',
    'SET @users_token_version_exists = @users_token_version_exists'
);

PREPARE users_token_version_statement FROM @users_token_version_migration;
EXECUTE users_token_version_statement;
DEALLOCATE PREPARE users_token_version_statement;