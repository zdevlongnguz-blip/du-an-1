USE `du-an-1`;

-- DỮ LIỆU DEMO biến thể sản phẩm (chỉ để minh họa; xóa hoặc sửa tùy ý, giá bên dưới là giá giả định).
-- Chạy lại nhiều lần an toàn: đổi tên biến thể mặc định chỉ khi còn tên 'Mặc định',
-- thêm biến thể mới chỉ khi SKU chưa tồn tại. Sản phẩm không có trong DB thì bỏ qua.

-- Sản phẩm CLAYMATION
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 56g', `v`.`attributes` = JSON_OBJECT('dung_tich', '56g')
WHERE `p`.`code` = 'CLAYMATION' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'CLAYMATION-113G', 'Hũ 113g', JSON_OBJECT('dung_tich', '113g'), 790000, 740000, 20, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'CLAYMATION'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'CLAYMATION-113G');

-- Sản phẩm POMADE-REUZEL-RED
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 113g', `v`.`attributes` = JSON_OBJECT('dung_tich', '113g')
WHERE `p`.`code` = 'POMADE-REUZEL-RED' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-RED-340G', 'Hũ 340g', JSON_OBJECT('dung_tich', '340g'), 890000, NULL, 8, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-RED'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'POMADE-REUZEL-RED-340G');

-- Sản phẩm POMADE-REUZEL-BLUE
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Hũ 113g', `v`.`attributes` = JSON_OBJECT('dung_tich', '113g')
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-BLUE-340G', 'Hũ 340g', JSON_OBJECT('dung_tich', '340g'), 940000, 899000, 6, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'POMADE-REUZEL-BLUE-340G');

-- Sản phẩm MOROCCANOIL
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 100ml', `v`.`attributes` = JSON_OBJECT('dung_tich', '100ml')
WHERE `p`.`code` = 'MOROCCANOIL' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'MOROCCANOIL-25ML', 'Chai 25ml', JSON_OBJECT('dung_tich', '25ml'), 290000, NULL, 40, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'MOROCCANOIL'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'MOROCCANOIL-25ML');

-- Sản phẩm SHAMPOO-THAODUOC
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 250ml', `v`.`attributes` = JSON_OBJECT('dung_tich', '250ml', 'mui_huong', 'Thảo dược')
WHERE `p`.`code` = 'SHAMPOO-THAODUOC' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'SHAMPOO-THAODUOC-500ML', 'Chai 500ml', JSON_OBJECT('dung_tich', '500ml', 'mui_huong', 'Thảo dược'), 220000, 199000, 30, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'SHAMPOO-THAODUOC'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'SHAMPOO-THAODUOC-500ML');

-- Sản phẩm COCOON-BUOI
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`variant_name` = 'Chai 140ml', `v`.`attributes` = JSON_OBJECT('dung_tich', '140ml')
WHERE `p`.`code` = 'COCOON-BUOI' AND `v`.`is_default` = 1 AND `v`.`variant_name` = 'Mặc định';

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'COCOON-BUOI-310ML', 'Chai 310ml', JSON_OBJECT('dung_tich', '310ml'), 235000, NULL, 45, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'COCOON-BUOI'
  AND NOT EXISTS (SELECT 1 FROM `product_variants` AS `x` WHERE `x`.`sku` = 'COCOON-BUOI-310ML');
