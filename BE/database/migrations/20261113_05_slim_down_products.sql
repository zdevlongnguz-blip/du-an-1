USE `du-an-1`;

-- =====================================================================
-- Migration 05: thu gọn bảng products thành bảng thông tin chung
-- Bỏ price, sale_price, stock (đã chuyển sang product_variants ở migration 02).
-- Có bước chặn: nếu còn sản phẩm chưa có biến thể thì migration dừng với lỗi, KHÔNG xóa cột giá/kho.
-- Bổ sung ghi chú tiếng Việt cho mọi cột, CHECK chống chuỗi rỗng, created_at bắt buộc có giá trị.
-- Khóa ngoại category_id đổi từ CASCADE sang RESTRICT: không cho xóa danh mục còn sản phẩm.
-- =====================================================================

-- Bước 1: bổ sung created_at còn NULL (để cột được đặt NOT NULL)
UPDATE `products` SET `created_at` = `updated_at` WHERE `created_at` IS NULL;

-- Bước 2: kiểm tra an toàn trước khi xóa cột giá/kho
SET @products_without_variant = (
  SELECT COUNT(*) FROM `products` AS `p`
  WHERE NOT EXISTS (SELECT 1 FROM `product_variants` AS `v` WHERE `v`.`product_id` = `p`.`id`)
);
-- Nếu còn sản phẩm chưa có biến thể: gọi bảng không tồn tại có tên mô tả lỗi để dừng migration
SET @sql = IF(IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price') = 1 AND @products_without_variant > 0, 1, 0), 'SELECT 1 FROM `LOI_MIGRATION_con_san_pham_chua_co_bien_the`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 3: bỏ cột giá/kho, ghi chú tiếng Việt cho mọi cột, thêm CHECK
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price') = 1, 'ALTER TABLE `products`
  DROP COLUMN `price`,
  DROP COLUMN `sale_price`,
  DROP COLUMN `stock`,
  MODIFY `id` INT NOT NULL AUTO_INCREMENT COMMENT ''Khóa chính của sản phẩm'',
  MODIFY `category_id` INT NOT NULL COMMENT ''Danh mục của sản phẩm (categories.id)'',
  MODIFY `code` VARCHAR(100) NOT NULL COMMENT ''Mã sản phẩm, duy nhất'',
  MODIFY `name` VARCHAR(255) NOT NULL COMMENT ''Tên sản phẩm'',
  MODIFY `slug` VARCHAR(255) NOT NULL COMMENT ''Đường dẫn thân thiện của sản phẩm, duy nhất'',
  MODIFY `image` VARCHAR(255) DEFAULT NULL COMMENT ''Ảnh đại diện (thumbnail) của sản phẩm'',
  MODIFY `description` TEXT DEFAULT NULL COMMENT ''Mô tả chung của sản phẩm'',
  MODIFY `view_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Số lượt xem sản phẩm'',
  MODIFY `sales_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Số lượt bán của sản phẩm (tổng mọi biến thể)'',
  MODIFY `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT ''Thời điểm tạo sản phẩm'',
  MODIFY `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT ''Thời điểm cập nhật gần nhất'',
  ADD CONSTRAINT `chk_products_code` CHECK (CHAR_LENGTH(TRIM(`code`)) > 0),
  ADD CONSTRAINT `chk_products_name` CHECK (CHAR_LENGTH(TRIM(`name`)) > 0),
  ADD CONSTRAINT `chk_products_slug` CHECK (CHAR_LENGTH(TRIM(`slug`)) > 0),
  COMMENT = ''Thông tin chung của sản phẩm. Giá, giá giảm, tồn kho nằm ở product_variants''', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 4a: gỡ khóa ngoại danh mục kiểu CASCADE (nếu đang là CASCADE)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'fk_products_category' AND DELETE_RULE = 'CASCADE') = 1, 'ALTER TABLE `products` DROP FOREIGN KEY `fk_products_category`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 4b: tạo lại khóa ngoại danh mục kiểu RESTRICT (nếu chưa có)
SET @sql = IF(IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND CONSTRAINT_NAME = 'fk_products_category') = 0, 1, 0), 'ALTER TABLE `products` ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
