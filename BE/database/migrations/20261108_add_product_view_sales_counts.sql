USE `du-an-1`;

SET @products_view_count_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'view_count'
);

SET @products_view_count_migration = IF(
    @products_view_count_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `view_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `stock`',
    'SET @products_view_count_exists = @products_view_count_exists'
);

PREPARE products_view_count_statement FROM @products_view_count_migration;
EXECUTE products_view_count_statement;
DEALLOCATE PREPARE products_view_count_statement;

SET @products_sales_count_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'products'
      AND COLUMN_NAME = 'sales_count'
);

SET @products_sales_count_migration = IF(
    @products_sales_count_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `sales_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `view_count`',
    'SET @products_sales_count_exists = @products_sales_count_exists'
);

PREPARE products_sales_count_statement FROM @products_sales_count_migration;
EXECUTE products_sales_count_statement;
DEALLOCATE PREPARE products_sales_count_statement;