-- Migration: thêm thông tin hồ sơ (họ tên, số điện thoại, địa chỉ) cho bảng users.
-- Cả ba cột đều không bắt buộc, mặc định NULL (user cũ và user mới chưa điền đều là NULL).
-- Mỗi cột được kiểm tra trong information_schema trước khi thêm, nên chạy lại nhiều lần vẫn an toàn.

USE `du-an-1`;

-- Cột name: họ tên hiển thị. Độ dài 100 khớp với orders.recipient_name.
SET @users_name_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'name'
);

SET @users_name_migration = IF(
    @users_name_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `name` VARCHAR(100) NULL DEFAULT NULL AFTER `email`',
    'SET @users_name_exists = @users_name_exists'
);

PREPARE users_name_statement FROM @users_name_migration;
EXECUTE users_name_statement;
DEALLOCATE PREPARE users_name_statement;

-- Cột phone: số điện thoại liên hệ. Độ dài 20 khớp với orders.recipient_phone.
SET @users_phone_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone'
);

SET @users_phone_migration = IF(
    @users_phone_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL AFTER `name`',
    'SET @users_phone_exists = @users_phone_exists'
);

PREPARE users_phone_statement FROM @users_phone_migration;
EXECUTE users_phone_statement;
DEALLOCATE PREPARE users_phone_statement;

-- Cột address: địa chỉ giao hàng/liên hệ. Độ dài 500 khớp với orders.shipping_address.
SET @users_address_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'address'
);

SET @users_address_migration = IF(
    @users_address_exists = 0,
    'ALTER TABLE `users` ADD COLUMN `address` VARCHAR(500) NULL DEFAULT NULL AFTER `phone`',
    'SET @users_address_exists = @users_address_exists'
);

PREPARE users_address_statement FROM @users_address_migration;
EXECUTE users_address_statement;
DEALLOCATE PREPARE users_address_statement;
