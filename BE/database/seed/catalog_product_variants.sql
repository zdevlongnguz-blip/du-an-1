USE `du-an-1`;

-- DỮ LIỆU DEMO biến thể sản phẩm (chỉ để minh họa; xóa hoặc sửa tùy ý, giá bên dưới là giá giả định).
-- Chạy lại nhiều lần an toàn: đổi tên biến thể mặc định chỉ khi còn tên 'Mặc định',
-- thêm biến thể mới chỉ khi SKU chưa tồn tại. Sản phẩm không có trong DB thì bỏ qua.
-- attributes là MẢNG [{key, label, value}] với đủ 3 khóa mặc định ở đầu (xem BE/extensions/VariantAttributes.php); độ giữ nếp / độ bóng là cột số hold_level, shine_level.

-- Sản phẩm CLAYMATION
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 56g', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '56g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL))
WHERE `p`.`code` = 'CLAYMATION' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'CLAYMATION-113G', 'Hũ 113g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 790000, 740000, 20, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'CLAYMATION'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'CLAYMATION-113G');

-- Sản phẩm POMADE-REUZEL-RED
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 113g', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL))
WHERE `p`.`code` = 'POMADE-REUZEL-RED' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-RED-340G', 'Hũ 340g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '340g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 890000, NULL, 8, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-RED'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'POMADE-REUZEL-RED-340G');

-- Sản phẩm POMADE-REUZEL-BLUE
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 113g', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL))
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-BLUE-340G', 'Hũ 340g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '340g'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 940000, 899000, 6, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'POMADE-REUZEL-BLUE-340G');

-- Sản phẩm MOROCCANOIL
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 100ml', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '100ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL))
WHERE `p`.`code` = 'MOROCCANOIL' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'MOROCCANOIL-25ML', 'Chai 25ml', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '25ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 290000, NULL, 40, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'MOROCCANOIL'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'MOROCCANOIL-25ML');

-- Sản phẩm SHAMPOO-THAODUOC
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 250ml', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '250ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL), JSON_OBJECT('key', 'mui_huong', 'label', 'Mùi hương', 'value', 'Thảo dược'))
WHERE `p`.`code` = 'SHAMPOO-THAODUOC' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'SHAMPOO-THAODUOC-500ML', 'Chai 500ml', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '500ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL), JSON_OBJECT('key', 'mui_huong', 'label', 'Mùi hương', 'value', 'Thảo dược')), 220000, 199000, 30, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'SHAMPOO-THAODUOC'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'SHAMPOO-THAODUOC-500ML');

-- Sản phẩm COCOON-BUOI
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 140ml', `v`.`attributes` = JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '140ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL))
WHERE `p`.`code` = 'COCOON-BUOI' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'COCOON-BUOI-310ML', 'Chai 310ml', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '310ml'), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 235000, NULL, 45, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'COCOON-BUOI'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'COCOON-BUOI-310ML');

-- Tạo lại metadata của mọi sản phẩm vì thuộc tính biến thể vừa đổi (seed chạy riêng lẻ cũng không làm metadata lệch).
-- ĐÚNG câu SQL của Product::METADATA_REFRESH_SQL (nguồn duy nhất): giá trị thuộc tính các biến thể đang bán,
-- bỏ trùng và sắp xếp theo từng byte (utf8mb4_bin), nối bằng " | ". JSON_TABLE biến mảng JSON thành các dòng.
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
