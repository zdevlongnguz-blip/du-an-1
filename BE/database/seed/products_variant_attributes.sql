USE `du-an-1`;

-- =====================================================================
-- DỮ LIỆU DEMO: điền 5 thuộc tính mặc định cho biến thể của 6 sản phẩm demo.
--
-- attributes là MẢNG [{key, label, value}] (định nghĩa trong BE/extensions/VariantAttributes.php,
-- chuyển đổi bởi migration 20261119_01). 5 khóa mặc định luôn nằm cố định ở đầu mảng:
--   $[0] dung_tich, $[1] do_giu_nep, $[2] do_bong, $[3] phu_hop, $[4] kieu_toc; khóa tự do từ $[5].
-- Khóa không dấu, giá trị tiếng Việt có dấu, chưa rõ thì value = null.
--
-- Chỉ sửa theo code sản phẩm. Sản phẩm HOANG-VINH-01 giữ nguyên.
-- Không sửa giá, tồn kho, tên biến thể, mô tả. dung_tich ($[0]) đang có thì giữ nguyên.
--
-- Chạy lại nhiều lần an toàn: chỉ UPDATE theo code, kết quả mỗi lần như nhau.
-- Tên file bắt đầu bằng "products_" để chạy SAU catalog_product_variants.sql và pomade_replace_hair_care.sql
-- (run.php chạy seed theo thứ tự tên file), lúc đó biến thể đã có đủ 5 khóa ở đúng vị trí.
-- =====================================================================

START TRANSACTION;

-- Các biến thể của cùng sản phẩm chỉ khác dung tích, nên 4 khóa còn lại giống nhau cho mọi biến thể của sản phẩm đó.
--
-- JSON_SET với đường dẫn '$[i].value': ghi giá trị của phần tử thứ i trong mảng (đếm từ 0).
-- Không đụng $[0] (dung_tich) để giữ dung tích riêng của từng hũ.
-- '$[5]' (Suavecito): vị trí đầu tiên sau 5 khóa mặc định. Chưa có thì JSON_SET thêm vào cuối mảng,
-- đã có thì ghi đè, nên chạy lại vẫn đúng (Suavecito chỉ có một khóa tự do là mui_huong).

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'rất mạnh',
  '$[2].value', 'không bóng, mờ tự nhiên',
  '$[3].value', 'tóc dày, tóc cứng, tóc rễ tre, tóc mỏng cần tạo phồng',
  '$[4].value', 'textured crop, quiff, tóc rối tự nhiên, side part'
)
WHERE `p`.`code` = 'CLAYMATION';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'rất mạnh',
  '$[2].value', 'bóng nhẹ',
  '$[3].value', 'tóc dày, tóc cứng, tóc ngắn',
  '$[4].value', 'dựng đứng, chĩa gai (spiky), mohican'
)
WHERE `p`.`code` = 'GOT2B-GLUED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'trung bình',
  '$[2].value', 'bóng cao',
  '$[3].value', 'tóc mỏng, tóc mềm, tóc thẳng',
  '$[4].value', 'vuốt ngược (slick back), pompadour cổ điển'
)
WHERE `p`.`code` = 'POMADE-REUZEL-RED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'mạnh',
  '$[2].value', 'bóng vừa',
  '$[3].value', 'tóc dày, tóc cứng, tóc rễ tre',
  '$[4].value', 'side part, pompadour'
)
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE';

-- Suavecito có thêm khóa tự do mui_huong.
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'trung bình',
  '$[2].value', 'bóng nhẹ',
  '$[3].value', 'tóc thường, tóc mỏng, người mới tập vuốt tóc',
  '$[4].value', 'side part, vuốt ngược, tóc công sở gọn gàng',
  '$[5]', JSON_OBJECT('key', 'mui_huong', 'label', 'Mùi hương', 'value', 'mùi cam chanh')
)
WHERE `p`.`code` = 'POMADE-SUAVECITO-ORIGINAL';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  `v`.`attributes`,
  '$[1].value', 'trung bình',
  '$[2].value', 'bóng vừa',
  '$[3].value', 'tóc thường, tóc dày vừa phải',
  '$[4].value', 'side part, pompadour, quiff'
)
WHERE `p`.`code` = 'POMADE-LAYRITE-ORIGINAL';

COMMIT;
