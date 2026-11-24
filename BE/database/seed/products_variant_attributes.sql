USE `du-an-1`;

-- =====================================================================
-- DỮ LIỆU DEMO: điền thuộc tính cho biến thể của 6 sản phẩm demo.
--
-- Từ migration 20261123_01, thông tin biến thể chia 2 loại (xem BE/extensions/VariantAttributes.php):
-- 1. Độ giữ nếp / độ bóng là CỘT SỐ:
--    hold_level  1 = nhẹ, 2 = trung bình, 3 = mạnh
--    shine_level 0 = không bóng (mờ), 1 = bóng nhẹ, 2 = bóng vừa, 3 = bóng cao
-- 2. attributes là MẢNG [{key, label, value}], 3 khóa mặc định luôn nằm cố định ở đầu mảng:
--    $[0] dung_tich, $[1] phu_hop, $[2] kieu_toc; khóa tự do từ $[3].
-- Khóa không dấu, giá trị tiếng Việt có dấu, chưa rõ thì value = null.
--
-- Chỉ sửa theo code sản phẩm. Sản phẩm HOANG-VINH-01 giữ nguyên.
-- Không sửa giá, tồn kho, tên biến thể, mô tả. dung_tich ($[0]) đang có thì giữ nguyên.
--
-- Chạy lại nhiều lần an toàn: chỉ UPDATE theo code, kết quả mỗi lần như nhau.
-- Tên file bắt đầu bằng "products_" để chạy SAU catalog_product_variants.sql và pomade_replace_hair_care.sql
-- (run.php chạy seed theo thứ tự tên file), lúc đó biến thể đã có đủ 3 khóa mặc định ở đúng vị trí.
-- Cuối file tạo lại products.metadata vì thuộc tính vừa đổi (cùng quy tắc với Product::refreshMetadata).
-- =====================================================================

START TRANSACTION;

-- Các biến thể của cùng sản phẩm chỉ khác dung tích, nên các thông tin còn lại giống nhau cho mọi biến thể của sản phẩm đó.
--
-- JSON_SET với đường dẫn '$[i].value': ghi giá trị của phần tử thứ i trong mảng (đếm từ 0).
-- Không đụng $[0] (dung_tich) để giữ dung tích riêng của từng hũ.
-- '$[3]' (Suavecito): vị trí đầu tiên sau 3 khóa mặc định. Chưa có thì JSON_SET thêm vào cuối mảng,
-- đã có thì ghi đè, nên chạy lại vẫn đúng (Suavecito chỉ có một khóa tự do là mui_huong).

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 3,
    `v`.`shine_level` = 0,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc dày, tóc cứng, tóc rễ tre, tóc mỏng cần tạo phồng',
      '$[2].value', 'textured crop, quiff, tóc rối tự nhiên, side part'
    )
WHERE `p`.`code` = 'CLAYMATION';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 3,
    `v`.`shine_level` = 1,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc dày, tóc cứng, tóc ngắn',
      '$[2].value', 'dựng đứng, chĩa gai (spiky), mohican'
    )
WHERE `p`.`code` = 'GOT2B-GLUED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 2,
    `v`.`shine_level` = 3,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc mỏng, tóc mềm, tóc thẳng',
      '$[2].value', 'vuốt ngược (slick back), pompadour cổ điển'
    )
WHERE `p`.`code` = 'POMADE-REUZEL-RED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 3,
    `v`.`shine_level` = 2,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc dày, tóc cứng, tóc rễ tre',
      '$[2].value', 'side part, pompadour'
    )
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE';

-- Suavecito có thêm khóa tự do mui_huong.
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 2,
    `v`.`shine_level` = 1,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc thường, tóc mỏng, người mới tập vuốt tóc',
      '$[2].value', 'side part, vuốt ngược, tóc công sở gọn gàng',
      '$[3]', JSON_OBJECT('key', 'mui_huong', 'label', 'Mùi hương', 'value', 'mùi cam chanh')
    )
WHERE `p`.`code` = 'POMADE-SUAVECITO-ORIGINAL';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`hold_level` = 2,
    `v`.`shine_level` = 2,
    `v`.`attributes` = JSON_SET(
      `v`.`attributes`,
      '$[1].value', 'tóc thường, tóc dày vừa phải',
      '$[2].value', 'side part, pompadour, quiff'
    )
WHERE `p`.`code` = 'POMADE-LAYRITE-ORIGINAL';

-- Tạo lại metadata của mọi sản phẩm vì thuộc tính biến thể vừa đổi (seed chạy riêng lẻ cũng không làm metadata lệch).
-- ĐÚNG câu SQL của Product::metadataRefreshSql() (nguồn duy nhất): giá trị thuộc tính + cụm chữ độ giữ nếp /
-- độ bóng của các biến thể đang bán, bỏ trùng, sắp xếp theo từng byte (utf8mb4_bin), nối bằng " | ".
-- group_concat_max_len: mặc định chỉ 1024 byte và bị CẮT ngầm; nới lên 65535 (sức chứa cột TEXT) cho phiên này.
SET SESSION group_concat_max_len = 65535;

UPDATE `products` AS `p`
SET `p`.`metadata` = (
  SELECT GROUP_CONCAT(DISTINCT TRIM(`jt`.`attr_value`) ORDER BY TRIM(`jt`.`attr_value`) SEPARATOR ' | ')
  FROM `product_variants` AS `v`,
       JSON_TABLE(
         JSON_MERGE_PRESERVE(
           COALESCE(JSON_EXTRACT(`v`.`attributes`, '$[*].value'), JSON_ARRAY()),
           JSON_ARRAY(
             CASE `v`.`hold_level` WHEN 1 THEN 'giữ nếp nhẹ' WHEN 2 THEN 'giữ nếp trung bình' WHEN 3 THEN 'giữ nếp mạnh' END,
             CASE `v`.`shine_level` WHEN 0 THEN 'không bóng (mờ)' WHEN 1 THEN 'bóng nhẹ' WHEN 2 THEN 'bóng vừa' WHEN 3 THEN 'bóng cao' END
           )
         ),
         '$[*]' COLUMNS (`attr_value` VARCHAR(255) COLLATE utf8mb4_bin PATH '$')
       ) AS `jt`
  WHERE `v`.`product_id` = `p`.`id`
    AND `v`.`is_active` = 1
    AND `jt`.`attr_value` IS NOT NULL
    AND TRIM(`jt`.`attr_value`) <> ''
);

COMMIT;
