USE `du-an-1`;

-- =====================================================================
-- Migration 20261123_01: tiêu chí lọc "giữ nếp / độ bóng" thành cột số + cột metadata để lọc theo tag.
--
-- 1. product_variants.hold_level  (1-3): độ giữ nếp 1 = nhẹ (low), 2 = trung bình (medium), 3 = mạnh (high).
--    product_variants.shine_level (0-3): độ bóng   0 = không bóng (mờ), 1 = bóng nhẹ, 2 = bóng vừa, 3 = bóng cao.
--    Trước đây 2 thông tin này là chữ tự do trong attributes (JSON) nên không lọc được ("Cực cao", "bóng lộn", "3"...).
--    Cột số có CHECK giới hạn miền giá trị, lọc bằng "=" đơn giản. NULL = chưa rõ.
-- 2. Chuyển giá trị chữ cũ trong JSON sang số (bảng ánh xạ bên dưới), rồi XÓA 2 khóa do_giu_nep, do_bong khỏi JSON.
--    Từ nay attributes chỉ còn 3 khóa mặc định (dung_tich, phu_hop, kieu_toc) + khóa tự do.
-- 3. products.metadata: chuỗi gom GIÁ TRỊ thuộc tính của các biến thể ĐANG BÁN, cách nhau bằng " | ",
--    dùng để lọc theo tag bằng LIKE. Không gom tên, mã, mô tả, danh mục, giá, giữ nếp, độ bóng (đã có ô lọc riêng).
--    BE tự tạo lại metadata mỗi khi admin thêm/sửa/xóa biến thể (Product::refreshMetadata). Ở đây chỉ điền lần đầu.
-- 4. Tạo lại view v_product_catalog để có thêm cột metadata.
--
-- Chạy lặp được: thêm cột thì kiểm tra information_schema trước; chuyển giá trị chỉ ghi vào dòng còn NULL;
-- xóa khóa JSON chỉ chạy trên dòng còn khóa đó; metadata và view luôn tính lại nên chạy lại cho cùng kết quả.
-- =====================================================================

-- Bước 1: thêm cột hold_level, shine_level (CHECK ngay trong định nghĩa cột, MySQL 8.0.16+).
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'hold_level') = 0,
  'ALTER TABLE `product_variants` ADD COLUMN `hold_level` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT ''Độ giữ nếp: 1 = nhẹ, 2 = trung bình, 3 = mạnh. NULL = chưa rõ. Tiêu chí lọc ở catalog và trợ lý AI'' CHECK (`hold_level` BETWEEN 1 AND 3) AFTER `attributes`',
  'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'shine_level') = 0,
  'ALTER TABLE `product_variants` ADD COLUMN `shine_level` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT ''Độ bóng: 0 = không bóng (mờ), 1 = bóng nhẹ, 2 = bóng vừa, 3 = bóng cao. NULL = chưa rõ. Tiêu chí lọc ở catalog và trợ lý AI'' CHECK (`shine_level` BETWEEN 0 AND 3) AFTER `hold_level`',
  'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 2: thêm cột products.metadata.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'metadata') = 0,
  'ALTER TABLE `products` ADD COLUMN `metadata` TEXT NULL DEFAULT NULL COMMENT ''Giá trị thuộc tính của các biến thể đang bán (dung tích, phù hợp, kiểu tóc, khóa tự do) nối bằng " | ", để lọc theo tag. BE tự tạo lại khi admin sửa biến thể, không sửa tay'' AFTER `description`',
  'DO 0');
PREPARE migration_stmt FROM @sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- Bước 3: chuyển giá trị chữ cũ sang số. Chỉ ghi vào dòng đang NULL để không đè giá trị admin đã chọn.
-- Cách lấy giá trị của một khóa trong mảng [{key, label, value}]:
--   JSON_SEARCH(... '$[*].key') trả đường dẫn của phần tử có key cần tìm, ví dụ "$[1].key";
--   SUBSTRING_INDEX(..., '.', 1) cắt còn "$[1]"; nối thêm ".value" rồi JSON_EXTRACT để đọc giá trị.
-- LOWER + TRIM để so khớp không phân biệt hoa thường, khoảng trắng thừa ("Cực cao", " mạnh ").
UPDATE `product_variants`
SET `hold_level` = CASE LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(`attributes`,
        CONCAT(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_SEARCH(`attributes`, 'one', 'do_giu_nep', NULL, '$[*].key')), '.', 1), '.value')))))
    WHEN 'nhẹ' THEN 1 WHEN '1' THEN 1
    WHEN 'trung bình' THEN 2 WHEN 'vừa' THEN 2 WHEN '2' THEN 2
    WHEN 'mạnh' THEN 3 WHEN 'rất mạnh' THEN 3 WHEN 'cực cao' THEN 3 WHEN 'cao' THEN 3 WHEN '3' THEN 3
    ELSE NULL END
WHERE `hold_level` IS NULL
  AND JSON_SEARCH(`attributes`, 'one', 'do_giu_nep', NULL, '$[*].key') IS NOT NULL;

-- "bóng lộn" (bóng loáng) xếp vào bóng cao; "không bóng, mờ tự nhiên" xếp vào không bóng.
UPDATE `product_variants`
SET `shine_level` = CASE LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(`attributes`,
        CONCAT(SUBSTRING_INDEX(JSON_UNQUOTE(JSON_SEARCH(`attributes`, 'one', 'do_bong', NULL, '$[*].key')), '.', 1), '.value')))))
    WHEN 'không bóng' THEN 0 WHEN 'không bóng, mờ tự nhiên' THEN 0 WHEN 'mờ' THEN 0 WHEN '0' THEN 0
    WHEN 'bóng nhẹ' THEN 1 WHEN '1' THEN 1
    WHEN 'bóng vừa' THEN 2 WHEN '2' THEN 2
    WHEN 'bóng cao' THEN 3 WHEN 'bóng lộn' THEN 3 WHEN '3' THEN 3
    ELSE NULL END
WHERE `shine_level` IS NULL
  AND JSON_SEARCH(`attributes`, 'one', 'do_bong', NULL, '$[*].key') IS NOT NULL;

-- Bước 4: xóa 2 khóa khỏi JSON (đã chuyển sang cột). JSON_REMOVE xóa phần tử theo đường dẫn "$[i]".
-- Phải chạy SAU bước 3 (bước 3 cần đọc giá trị cũ). Dòng không còn khóa thì WHERE loại ra, nên chạy lại an toàn.
UPDATE `product_variants`
SET `attributes` = JSON_REMOVE(`attributes`,
    SUBSTRING_INDEX(JSON_UNQUOTE(JSON_SEARCH(`attributes`, 'one', 'do_giu_nep', NULL, '$[*].key')), '.', 1))
WHERE JSON_SEARCH(`attributes`, 'one', 'do_giu_nep', NULL, '$[*].key') IS NOT NULL;

UPDATE `product_variants`
SET `attributes` = JSON_REMOVE(`attributes`,
    SUBSTRING_INDEX(JSON_UNQUOTE(JSON_SEARCH(`attributes`, 'one', 'do_bong', NULL, '$[*].key')), '.', 1))
WHERE JSON_SEARCH(`attributes`, 'one', 'do_bong', NULL, '$[*].key') IS NOT NULL;

-- Bước 5: điền metadata lần đầu. ĐÚNG câu SQL của Product::METADATA_REFRESH_SQL (nguồn duy nhất của quy tắc gom):
-- giá trị (không lấy nhãn) của mọi khóa, chỉ biến thể đang bán, bỏ rỗng/null, bỏ trùng, nối bằng " | ".
-- JSON_TABLE: biến mảng JSON thành các dòng để GROUP_CONCAT gom lại.
-- COLLATE utf8mb4_bin: bỏ trùng và sắp xếp theo từng byte, kết quả giống hệt bản BE tạo ra lúc admin sửa biến thể.
-- group_concat_max_len: mặc định chỉ 1024 byte và bị CẮT ngầm; nới lên 65535 (sức chứa cột TEXT) cho phiên này.
SET SESSION group_concat_max_len = 65535;

UPDATE `products` AS `p`
SET `p`.`metadata` = (
  SELECT GROUP_CONCAT(DISTINCT TRIM(`jt`.`attr_value`) ORDER BY TRIM(`jt`.`attr_value`) SEPARATOR ' | ')
  FROM `product_variants` AS `v`,
       JSON_TABLE(`v`.`attributes`, '$[*]' COLUMNS (`attr_value` VARCHAR(255) COLLATE utf8mb4_bin PATH '$.value')) AS `jt`
  WHERE `v`.`product_id` = `p`.`id`
    AND `v`.`is_active` = 1
    AND `jt`.`attr_value` IS NOT NULL
    AND TRIM(`jt`.`attr_value`) <> ''
);

-- Bước 6: tạo lại view để có thêm cột metadata (giữ nguyên mọi cột cũ).
CREATE OR REPLACE VIEW `v_product_catalog` AS
SELECT
  `p`.`id`, `p`.`category_id`, `p`.`code`, `p`.`name`, `p`.`slug`, `p`.`image`, `p`.`description`, `p`.`metadata`,
  `p`.`view_count`, `p`.`sales_count`, `p`.`created_at`, `p`.`updated_at`,
  COUNT(`v`.`id`) AS `variant_count`,
  MIN(`v`.`final_price`) AS `min_final_price`,
  MAX(`v`.`final_price`) AS `max_final_price`,
  COALESCE(SUM(`v`.`stock`), 0) AS `total_stock`,
  COALESCE(MAX(`v`.`stock` > 0), 0) AS `in_stock`
FROM `products` AS `p`
LEFT JOIN `product_variants` AS `v` ON `v`.`product_id` = `p`.`id` AND `v`.`is_active` = 1
GROUP BY `p`.`id`;
