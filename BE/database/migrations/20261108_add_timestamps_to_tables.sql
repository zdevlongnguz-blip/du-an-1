USE `du-an-1`;

SET @categories_created_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'created_at'
);

SET @categories_created_at_migration = IF(
    @categories_created_at_exists = 0,
    'ALTER TABLE `categories` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SET @categories_created_at_exists = @categories_created_at_exists'
);

PREPARE categories_created_at_statement FROM @categories_created_at_migration;
EXECUTE categories_created_at_statement;
DEALLOCATE PREPARE categories_created_at_statement;

SET @categories_updated_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'categories'
      AND COLUMN_NAME = 'updated_at'
);

SET @categories_updated_at_migration = IF(
    @categories_updated_at_exists = 0,
    'ALTER TABLE `categories` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SET @categories_updated_at_exists = @categories_updated_at_exists'
);

PREPARE categories_updated_at_statement FROM @categories_updated_at_migration;
EXECUTE categories_updated_at_statement;
DEALLOCATE PREPARE categories_updated_at_statement;

SET @products_updated_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'updated_at'
);

SET @products_updated_at_migration = IF(
    @products_updated_at_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SET @products_updated_at_exists = @products_updated_at_exists'
);

PREPARE products_updated_at_statement FROM @products_updated_at_migration;
EXECUTE products_updated_at_statement;
DEALLOCATE PREPARE products_updated_at_statement;

SET @banners_created_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'banners'
      AND COLUMN_NAME = 'created_at'
);

SET @banners_created_at_migration = IF(
    @banners_created_at_exists = 0,
    'ALTER TABLE `banners` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'SET @banners_created_at_exists = @banners_created_at_exists'
);

PREPARE banners_created_at_statement FROM @banners_created_at_migration;
EXECUTE banners_created_at_statement;
DEALLOCATE PREPARE banners_created_at_statement;

SET @banners_updated_at_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'banners'
      AND COLUMN_NAME = 'updated_at'
);

SET @banners_updated_at_migration = IF(
    @banners_updated_at_exists = 0,
    'ALTER TABLE `banners` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    'SET @banners_updated_at_exists = @banners_updated_at_exists'
);

PREPARE banners_updated_at_statement FROM @banners_updated_at_migration;
EXECUTE banners_updated_at_statement;
DEALLOCATE PREPARE banners_updated_at_statement;