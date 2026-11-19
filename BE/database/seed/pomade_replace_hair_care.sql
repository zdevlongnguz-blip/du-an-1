USE `du-an-1`;

-- =====================================================================
-- DỮ LIỆU DEMO: thay danh mục "Dưỡng tóc" bằng danh mục "Pomade".
-- Giá bên dưới là giá giả định để minh họa.
--
-- File này làm 3 việc, trong MỘT transaction (lỗi ở đâu thì hoàn tác toàn bộ):
--   1. Tạo danh mục "Pomade" (slug pomade) nếu chưa có.
--   2. XÓA mọi sản phẩm thuộc danh mục "Dưỡng tóc" (slug duong-toc), rồi xóa luôn danh mục đó.
--   3. Thêm 4 sản phẩm Pomade cùng biến thể và ảnh.
--
-- Ảnh hưởng của bước 2 (theo khóa ngoại hiện có):
--   - product_variants, product_images, comments của các sản phẩm bị xóa: xóa theo (ON DELETE CASCADE).
--   - cart_items trỏ tới biến thể bị xóa: xóa theo (ON DELETE CASCADE).
--   - order_details của đơn cũ: KHÔNG bị xóa. product_id và variant_id thành NULL (ON DELETE SET NULL),
--     tên sản phẩm, SKU, tên biến thể, đơn giá đã chụp lúc mua vẫn giữ nguyên.
--   - Các sản phẩm thuộc danh mục khác (Gel, Sáp vuốt tóc...) giữ nguyên.
--
-- Chạy lại nhiều lần an toàn: chỉ thêm khi chưa có (theo slug danh mục, code sản phẩm, SKU biến thể);
-- lần chạy sau không còn sản phẩm "Dưỡng tóc" nên bước 2 không xóa gì thêm.
-- Chỉ chạy trên DB local/dev, không chạy trên DB dùng chung khi chưa thống nhất với nhóm.
-- attributes là MẢNG [{key, label, value}] với đủ 5 khóa mặc định ở đầu (xem BE/extensions/VariantAttributes.php).
-- =====================================================================

START TRANSACTION;

-- 1. Danh mục Pomade
INSERT INTO `categories` (`name`, `slug`)
SELECT 'Pomade', 'pomade' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `categories` WHERE `slug` = 'pomade');

-- 2. Bỏ danh mục Dưỡng tóc: xóa sản phẩm trước (danh mục dùng ON DELETE RESTRICT), sau đó xóa danh mục.
DELETE `p` FROM `products` AS `p`
JOIN `categories` AS `c` ON `c`.`id` = `p`.`category_id`
WHERE `c`.`slug` = 'duong-toc';

DELETE FROM `categories` WHERE `slug` = 'duong-toc';

-- 3. Sản phẩm Pomade. Mỗi sản phẩm chỉ thêm khi code chưa tồn tại.
INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `image`, `description`)
SELECT `c`.`id`, 'POMADE-REUZEL-RED', 'Pomade Reuzel Red Water Soluble High Sheen', 'pomade-reuzel-red-water-soluble-high-sheen',
       'default.jpg', 'Pomade gốc nước, độ bóng cao, giữ nếp vừa, gội sạch dễ dàng. Hợp kiểu tóc vuốt ngược cổ điển.'
FROM `categories` AS `c`
WHERE `c`.`slug` = 'pomade' AND NOT EXISTS (SELECT 1 FROM `products` WHERE `code` = 'POMADE-REUZEL-RED');

INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `image`, `description`)
SELECT `c`.`id`, 'POMADE-REUZEL-BLUE', 'Pomade Reuzel Blue Strong Hold', 'pomade-reuzel-blue-strong-hold',
       'default.jpg', 'Pomade gốc nước, giữ nếp mạnh, độ bóng vừa. Hợp kiểu side part, pompadour cần đứng nếp cả ngày.'
FROM `categories` AS `c`
WHERE `c`.`slug` = 'pomade' AND NOT EXISTS (SELECT 1 FROM `products` WHERE `code` = 'POMADE-REUZEL-BLUE');

INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `image`, `description`)
SELECT `c`.`id`, 'POMADE-SUAVECITO-ORIGINAL', 'Pomade Suavecito Original Hold', 'pomade-suavecito-original-hold',
       'default.jpg', 'Pomade gốc nước, giữ nếp trung bình, bóng nhẹ, mùi cam chanh. Dễ dùng cho người mới.'
FROM `categories` AS `c`
WHERE `c`.`slug` = 'pomade' AND NOT EXISTS (SELECT 1 FROM `products` WHERE `code` = 'POMADE-SUAVECITO-ORIGINAL');

INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `image`, `description`)
SELECT `c`.`id`, 'POMADE-LAYRITE-ORIGINAL', 'Pomade Layrite Original Hold', 'pomade-layrite-original-hold',
       'default.jpg', 'Pomade gốc nước, giữ nếp trung bình, độ bóng vừa, tạo kiểu lại được trong ngày.'
FROM `categories` AS `c`
WHERE `c`.`slug` = 'pomade' AND NOT EXISTS (SELECT 1 FROM `products` WHERE `code` = 'POMADE-LAYRITE-ORIGINAL');

-- Biến thể. Mỗi sản phẩm có đúng 1 biến thể mặc định (is_default = 1). Chỉ thêm khi SKU chưa tồn tại.
-- Biến thể 340g của Reuzel dùng cùng giá trị với seed/catalog_product_variants.sql để hai file không lệch nhau.
INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-RED', 'Hũ 113g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 390000, NULL, 40, 1, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-RED' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-REUZEL-RED');

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-RED-340G', 'Hũ 340g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '340g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 890000, NULL, 8, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-RED' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-REUZEL-RED-340G');

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-BLUE', 'Hũ 113g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 420000, 390000, 35, 1, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-REUZEL-BLUE');

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-REUZEL-BLUE-340G', 'Hũ 340g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '340g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 940000, 899000, 6, 0, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-REUZEL-BLUE-340G');

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-SUAVECITO-ORIGINAL', 'Hũ 113g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '113g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 320000, NULL, 50, 1, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-SUAVECITO-ORIGINAL' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-SUAVECITO-ORIGINAL');

INSERT INTO `product_variants`
  (`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`)
SELECT `p`.`id`, 'POMADE-LAYRITE-ORIGINAL', 'Hũ 120g', JSON_ARRAY(JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', '120g'), JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', NULL), JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', NULL), JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', NULL), JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', NULL)), 450000, 420000, 25, 1, 1
FROM `products` AS `p`
WHERE `p`.`code` = 'POMADE-LAYRITE-ORIGINAL' AND NOT EXISTS (SELECT 1 FROM `product_variants` WHERE `sku` = 'POMADE-LAYRITE-ORIGINAL');

-- Ảnh album: dùng ảnh mặc định, giống cách seed/products.sql thêm ảnh cho sản phẩm có sẵn.
INSERT INTO `product_images` (`product_id`, `image_path`, `sort_order`)
SELECT `p`.`id`, `p`.`image`, 1
FROM `products` AS `p`
JOIN `categories` AS `c` ON `c`.`id` = `p`.`category_id`
WHERE `c`.`slug` = 'pomade'
  AND `p`.`image` IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `product_images` AS `i`
    WHERE `i`.`product_id` = `p`.`id` AND `i`.`image_path` = `p`.`image`
  );

COMMIT;
