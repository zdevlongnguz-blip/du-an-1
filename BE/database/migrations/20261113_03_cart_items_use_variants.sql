USE `du-an-1`;

-- =====================================================================
-- Migration 03: giỏ hàng chuyển sang trỏ vào biến thể (cart_items.variant_id)
-- Thay cart_items.product_id bằng variant_id (khóa ngoại tới product_variants, xóa biến thể thì xóa dòng giỏ).
-- Dòng giỏ cũ được ánh xạ sang biến thể mặc định của sản phẩm tương ứng.
-- Dòng giỏ có số lượng 0 hoặc không ánh xạ được sẽ bị xóa (giỏ hàng chỉ là dữ liệu tạm).
-- Ràng buộc mới: mỗi biến thể chỉ xuất hiện 1 dòng trong 1 giỏ; số lượng >= 1.
-- =====================================================================

-- Bước 1: thêm cột variant_id (tạm thời cho phép NULL để ánh xạ dữ liệu cũ)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND COLUMN_NAME = 'variant_id') = 0, 'ALTER TABLE `cart_items` ADD COLUMN `variant_id` BIGINT UNSIGNED NULL COMMENT ''Biến thể sản phẩm được thêm vào giỏ (product_variants.id)'' AFTER `cart_id`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 2: ánh xạ dòng giỏ cũ sang biến thể mặc định của sản phẩm
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND COLUMN_NAME = 'product_id') = 1, 'UPDATE `cart_items` AS `ci`
JOIN `product_variants` AS `v` ON `v`.`product_id` = `ci`.`product_id` AND `v`.`is_default` = 1
SET `ci`.`variant_id` = `v`.`id`
WHERE `ci`.`variant_id` IS NULL', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 3: dọn dòng giỏ không hợp lệ trước khi bật ràng buộc NOT NULL và CHECK
DELETE FROM `cart_items` WHERE `variant_id` IS NULL OR `quantity` = 0;

-- Bước 4: bật ràng buộc mới, ghi chú tiếng Việt cho toàn bộ cột
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND CONSTRAINT_NAME = 'fk_cart_items_variant') = 0, 'ALTER TABLE `cart_items`
  MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Khóa chính của dòng giỏ hàng'',
  MODIFY `cart_id` BIGINT UNSIGNED NOT NULL COMMENT ''Giỏ hàng chứa dòng này (carts.id). Xóa giỏ thì xóa các dòng'',
  MODIFY `variant_id` BIGINT UNSIGNED NOT NULL COMMENT ''Biến thể sản phẩm được thêm vào giỏ (product_variants.id). Xóa biến thể thì xóa dòng giỏ'',
  MODIFY `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Số lượng mua, tối thiểu 1'',
  MODIFY `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT ''Thời điểm thêm vào giỏ'',
  MODIFY `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT ''Thời điểm cập nhật gần nhất'',
  ADD UNIQUE KEY `uq_cart_items_cart_variant` (`cart_id`, `variant_id`),
  ADD KEY `idx_cart_items_variant_id` (`variant_id`),
  ADD CONSTRAINT `fk_cart_items_variant`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `chk_cart_items_quantity` CHECK (`quantity` >= 1),
  COMMENT = ''Các dòng sản phẩm (theo biến thể) trong giỏ hàng''', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 5: gỡ khóa ngoại, index và cột product_id cũ
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cart_items' AND COLUMN_NAME = 'product_id') = 1, 'ALTER TABLE `cart_items`
  DROP FOREIGN KEY `fk_cart_items_product`,
  DROP INDEX `uq_cart_items_cart_product`,
  DROP INDEX `idx_cart_items_product_id`,
  DROP COLUMN `product_id`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
