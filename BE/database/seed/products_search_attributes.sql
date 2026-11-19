USE `du-an-1`;

-- =====================================================================
-- DỮ LIỆU DEMO cho tìm kiếm nâng cao (vector search):
-- viết lại mô tả và bổ sung 5 thuộc tính mặc định vào product_variants.attributes.
--
-- 5 khóa mặc định (định nghĩa trong BE/embedServices/ProductEmbedText.php):
--   dung_tich, do_giu_nep, do_bong, phu_hop, kieu_toc. Chưa rõ thì để null.
-- Khóa không dấu, giá trị tiếng Việt có dấu.
--
-- Chỉ sửa 6 sản phẩm theo code. Sản phẩm HOANG-VINH-01 giữ nguyên.
-- Không sửa giá, tồn kho, tên biến thể. dung_tich đang có thì giữ nguyên.
--
-- Chạy lại nhiều lần an toàn: chỉ UPDATE theo code, kết quả mỗi lần như nhau.
-- Tên file bắt đầu bằng "products_" để chạy SAU catalog_product_variants.sql
-- (run.php chạy seed theo thứ tự tên file).
-- Sau khi chạy seed, chạy BE/database/embed_products.php để tạo lại vector.
-- =====================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------
-- Mô tả sản phẩm (đặc điểm chung, khách đọc được ở trang chi tiết)
-- ---------------------------------------------------------------------
UPDATE `products` SET `description` = 'Sáp đất sét (clay) của Hanz de Fuko, giữ nếp rất mạnh, hoàn thiện mờ không bóng. Tạo độ phồng và kết cấu tơi tự nhiên, tóc vẫn mềm và vuốt lại được trong ngày.'
WHERE `code` = 'CLAYMATION';

UPDATE `products` SET `description` = 'Gel vuốt tóc của Got2b, giữ nếp cực mạnh, tóc cứng và đứng nếp suốt cả ngày, chịu được gió và mồ hôi. Hợp các kiểu tóc dựng đứng, chĩa gai cá tính.'
WHERE `code` = 'GOT2B-GLUED';

UPDATE `products` SET `description` = 'Pomade gốc nước của Reuzel, độ bóng cao, giữ nếp vừa phải, gội sạch dễ dàng chỉ với nước. Hợp kiểu tóc vuốt ngược bóng mượt phong cách cổ điển.'
WHERE `code` = 'POMADE-REUZEL-RED';

UPDATE `products` SET `description` = 'Pomade gốc nước của Reuzel, giữ nếp mạnh, độ bóng vừa. Hợp kiểu side part, pompadour cần đứng nếp cả ngày, dễ gội sạch.'
WHERE `code` = 'POMADE-REUZEL-BLUE';

UPDATE `products` SET `description` = 'Pomade gốc nước của Suavecito, giữ nếp trung bình, bóng nhẹ, mùi cam chanh dễ chịu. Dễ tán đều, dễ gội, phù hợp người mới tập vuốt tóc.'
WHERE `code` = 'POMADE-SUAVECITO-ORIGINAL';

UPDATE `products` SET `description` = 'Pomade gốc nước của Layrite, giữ nếp trung bình, độ bóng vừa. Có thể tạo kiểu lại nhiều lần trong ngày mà không bết tóc.'
WHERE `code` = 'POMADE-LAYRITE-ORIGINAL';

-- ---------------------------------------------------------------------
-- Thuộc tính biến thể. Các biến thể của cùng sản phẩm chỉ khác dung tích,
-- nên 4 khóa còn lại giống nhau cho mọi biến thể của sản phẩm đó.
--
-- JSON_INSERT: chỉ thêm khóa khi CHƯA có (giữ nguyên dung_tich đang có, chưa có thì thêm null).
-- JSON_SET: thêm mới hoặc ghi đè khóa. Truyền NULL sẽ lưu giá trị JSON null.
-- ---------------------------------------------------------------------
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'rất mạnh',
  '$.do_bong', 'không bóng, mờ tự nhiên',
  '$.phu_hop', 'tóc dày, tóc cứng, tóc rễ tre, tóc mỏng cần tạo phồng',
  '$.kieu_toc', 'textured crop, quiff, tóc rối tự nhiên, side part'
)
WHERE `p`.`code` = 'CLAYMATION';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'rất mạnh',
  '$.do_bong', 'bóng nhẹ',
  '$.phu_hop', 'tóc dày, tóc cứng, tóc ngắn',
  '$.kieu_toc', 'dựng đứng, chĩa gai (spiky), mohican'
)
WHERE `p`.`code` = 'GOT2B-GLUED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'trung bình',
  '$.do_bong', 'bóng cao',
  '$.phu_hop', 'tóc mỏng, tóc mềm, tóc thẳng',
  '$.kieu_toc', 'vuốt ngược (slick back), pompadour cổ điển'
)
WHERE `p`.`code` = 'POMADE-REUZEL-RED';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'mạnh',
  '$.do_bong', 'bóng vừa',
  '$.phu_hop', 'tóc dày, tóc cứng, tóc rễ tre',
  '$.kieu_toc', 'side part, pompadour'
)
WHERE `p`.`code` = 'POMADE-REUZEL-BLUE';

-- Suavecito có thêm khóa tự do mui_huong (lấy từ mô tả gốc).
UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'trung bình',
  '$.do_bong', 'bóng nhẹ',
  '$.phu_hop', 'tóc thường, tóc mỏng, người mới tập vuốt tóc',
  '$.kieu_toc', 'side part, vuốt ngược, tóc công sở gọn gàng',
  '$.mui_huong', 'mùi cam chanh'
)
WHERE `p`.`code` = 'POMADE-SUAVECITO-ORIGINAL';

UPDATE `product_variants` AS `v`
JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
SET `v`.`attributes` = JSON_SET(
  JSON_INSERT(`v`.`attributes`, '$.dung_tich', NULL),
  '$.do_giu_nep', 'trung bình',
  '$.do_bong', 'bóng vừa',
  '$.phu_hop', 'tóc thường, tóc dày vừa phải',
  '$.kieu_toc', 'side part, pompadour, quiff'
)
WHERE `p`.`code` = 'POMADE-LAYRITE-ORIGINAL';

COMMIT;
