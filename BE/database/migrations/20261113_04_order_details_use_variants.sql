USE `du-an-1`;

-- =====================================================================
-- Migration 04: chi tiết đơn hàng gắn với biến thể + lưu snapshot
-- Thêm variant_id (khóa ngoại kép (variant_id, product_id) -> product_variants(id, product_id)
-- để DB đảm bảo biến thể thuộc đúng sản phẩm của dòng đơn).
-- Thêm variant_sku, variant_label: bản chụp tại thời điểm mua, vẫn đọc được nếu biến thể bị xóa sau này.
-- Dòng đơn cũ được gắn biến thể mặc định; không sửa unit_price, product_name hay quantity.
-- Ràng buộc mới: quantity >= 1, unit_price >= 0.
-- =====================================================================

-- Bước 1: thêm cột variant_id, variant_sku, variant_label
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details' AND COLUMN_NAME = 'variant_id') = 0, 'ALTER TABLE `order_details`
  ADD COLUMN `variant_id` BIGINT UNSIGNED NULL COMMENT ''Biến thể đã mua (product_variants.id). NULL nếu biến thể đã bị xóa, khi đó dùng cột snapshot'' AFTER `product_id`,
  ADD COLUMN `variant_sku` VARCHAR(100) NULL COMMENT ''Snapshot SKU của biến thể tại thời điểm mua'' AFTER `product_name`,
  ADD COLUMN `variant_label` VARCHAR(255) NULL COMMENT ''Snapshot tên biến thể tại thời điểm mua, ví dụ: Hũ 113g. NULL với đơn cũ chưa có biến thể'' AFTER `variant_sku`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 2: gắn các dòng đơn cũ vào biến thể mặc định của sản phẩm (nhãn biến thể để NULL vì đơn cũ chưa có biến thể)
UPDATE `order_details` AS `od`
JOIN `product_variants` AS `v` ON `v`.`product_id` = `od`.`product_id` AND `v`.`is_default` = 1
SET `od`.`variant_id` = `v`.`id`, `od`.`variant_sku` = `v`.`sku`
WHERE `od`.`variant_id` IS NULL AND `od`.`product_id` IS NOT NULL;

-- Bước 3: bật khóa ngoại, CHECK và ghi chú tiếng Việt cho toàn bộ cột
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details' AND CONSTRAINT_NAME = 'fk_order_details_variant') = 0, 'ALTER TABLE `order_details`
  MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT ''Khóa chính của dòng chi tiết đơn hàng'',
  MODIFY `order_id` BIGINT UNSIGNED NOT NULL COMMENT ''Đơn hàng chứa dòng này (orders.id). Xóa đơn thì xóa các dòng chi tiết'',
  MODIFY `product_id` INT DEFAULT NULL COMMENT ''Sản phẩm đã mua (products.id). NULL nếu sản phẩm đã bị xóa, khi đó dùng product_name'',
  MODIFY `product_name` VARCHAR(255) NOT NULL COMMENT ''Snapshot tên sản phẩm tại thời điểm mua'',
  MODIFY `quantity` INT UNSIGNED NOT NULL COMMENT ''Số lượng mua, tối thiểu 1'',
  MODIFY `unit_price` DECIMAL(10,2) NOT NULL COMMENT ''Snapshot đơn giá thực tế (VND) tại thời điểm mua, không đổi khi giá biến thể thay đổi sau này'',
  MODIFY `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT ''Thời điểm tạo dòng chi tiết'',
  MODIFY `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT ''Thời điểm cập nhật gần nhất'',
  ADD KEY `idx_order_details_variant_product` (`variant_id`, `product_id`),
  ADD CONSTRAINT `fk_order_details_variant`
    FOREIGN KEY (`variant_id`, `product_id`) REFERENCES `product_variants` (`id`, `product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `chk_order_details_quantity` CHECK (`quantity` >= 1),
  ADD CONSTRAINT `chk_order_details_unit_price` CHECK (`unit_price` >= 0),
  COMMENT = ''Chi tiết từng dòng sản phẩm của đơn hàng (giá và tên lưu snapshot)''', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
