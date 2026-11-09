USE `du-an-1`;

SET @verification_expires_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'verification_expires_at'
);

SET @verification_expiry_migration = IF(
    @verification_expires_at_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `verification_expires_at` DATETIME DEFAULT NULL AFTER `verification_token`',
    'SET @verification_expires_at_exists = @verification_expires_at_exists'
);

PREPARE verification_expiry_statement FROM @verification_expiry_migration;
EXECUTE verification_expiry_statement;
DEALLOCATE PREPARE verification_expiry_statement;