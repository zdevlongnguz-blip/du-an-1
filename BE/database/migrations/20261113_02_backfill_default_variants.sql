USE `du-an-1`;

-- =====================================================================
-- Migration 02: tạo biến thể mặc định từ dữ liệu giá/kho cũ của products
-- Mỗi sản phẩm hiện có được tạo đúng 1 biến thể mặc định tên 'Mặc định' (sku = products.code).
-- Sao chép nguyên giá, giá giảm, tồn kho cũ nên không mất dữ liệu.
-- Chạy lại nhiều lần vẫn an toàn: chỉ tạo cho sản phẩm chưa có biến thể nào.
-- Giá giảm không hợp lệ (>= giá gốc hoặc âm) được đưa về NULL; tồn kho NULL/âm được đưa về 0.
-- =====================================================================

-- Chỉ chạy khi cột products.price còn tồn tại (chưa chuyển sang biến thể)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price') = 1, 'INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, `p`.`code`, ''Mặc định'', JSON_OBJECT(), `p`.`price`,
  CASE WHEN `p`.`sale_price` IS NOT NULL AND `p`.`sale_price` >= 0 AND `p`.`sale_price` < `p`.`price`
       THEN `p`.`sale_price` ELSE NULL END,
  GREATEST(COALESCE(`p`.`stock`, 0), 0), 1, 1
FROM `products` AS `p`
WHERE NOT EXISTS (
  SELECT 1 FROM `product_variants` AS `v` WHERE `v`.`product_id` = `p`.`id`
)', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
