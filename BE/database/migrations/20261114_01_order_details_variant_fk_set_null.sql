USE `du-an-1`;

-- =====================================================================
-- Migration 20261114_01: xóa sản phẩm/biến thể thì order_details.variant_id về NULL
-- Vấn đề: khóa ngoại kép (variant_id, product_id) chỉ có hiệu lực khi cả hai cột khác NULL.
-- Khi xóa sản phẩm, product_id về NULL trước (fk_order_details_product) nên khóa kép
-- không còn khớp, variant_id vẫn giữ ID biến thể đã bị xóa (khác với tài liệu schema).
-- Cách sửa: thêm khóa ngoại đơn variant_id -> product_variants(id) ON DELETE SET NULL.
-- Giữ nguyên khóa kép để DB vẫn bảo đảm biến thể thuộc đúng sản phẩm của dòng đơn.
-- Chạy lặp được: bước dọn dữ liệu và bước thêm khóa ngoại đều kiểm tra trạng thái trước.
-- =====================================================================

-- Bước 1: dọn các dòng đơn đang trỏ tới biến thể không còn tồn tại (đã bị xóa trước khi có migration này).
-- Chỉ đặt variant_id = NULL; snapshot (tên, SKU, nhãn biến thể, giá) giữ nguyên.
UPDATE `order_details` AS `od`
LEFT JOIN `product_variants` AS `v` ON `v`.`id` = `od`.`variant_id`
SET `od`.`variant_id` = NULL
WHERE `od`.`variant_id` IS NOT NULL AND `v`.`id` IS NULL;

-- Bước 2: thêm khóa ngoại đơn trên variant_id (bỏ qua nếu đã có)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details' AND CONSTRAINT_NAME = 'fk_order_details_variant_only') = 0, 'ALTER TABLE `order_details`
  ADD CONSTRAINT `fk_order_details_variant_only`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE SET NULL', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
