USE `du-an-1`;

-- =====================================================================
-- Migration 20261114_02: lưu phương thức thanh toán và mã hóa đơn SePay của đơn hàng
-- - payment_method: 'cod' (thanh toán khi nhận hàng) hoặc 'sepay' (thanh toán online).
--   Đơn đã có từ trước được gán 'cod' (giá trị mặc định).
-- - payment_invoice_number: mã hóa đơn của lần thanh toán SePay gần nhất, dùng để hỏi lại SePay
--   khi người dùng quay về từ cổng thanh toán. NULL nếu đơn chưa bắt đầu thanh toán online.
-- Chạy lặp được: mỗi bước kiểm tra cột/index đã có chưa trước khi thêm.
-- =====================================================================

-- Bước 1: thêm cột payment_method (bỏ qua nếu đã có)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_method') = 0, 'ALTER TABLE `orders`
  ADD COLUMN `payment_method` ENUM(''cod'', ''sepay'') NOT NULL DEFAULT ''cod'' COMMENT ''Phương thức thanh toán: cod = khi nhận hàng, sepay = online qua SePay'' AFTER `payment_status`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 2: thêm cột payment_invoice_number (bỏ qua nếu đã có)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_invoice_number') = 0, 'ALTER TABLE `orders`
  ADD COLUMN `payment_invoice_number` VARCHAR(64) NULL DEFAULT NULL COMMENT ''Mã hóa đơn SePay của lần thanh toán online gần nhất (NULL nếu chưa thanh toán online)'' AFTER `payment_method`', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 3: index hỗ trợ truy vấn tự hủy đơn online quá hạn (bỏ qua nếu đã có)
SET @sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_online_pending') = 0, 'ALTER TABLE `orders`
  ADD KEY `idx_orders_online_pending` (`payment_method`, `order_status`, `payment_status`, `created_at`)', 'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
