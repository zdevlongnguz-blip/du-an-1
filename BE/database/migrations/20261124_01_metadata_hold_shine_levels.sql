USE `du-an-1`;

-- =====================================================================
-- Migration 20261124_01: thêm cụm chữ độ giữ nếp / độ bóng vào products.metadata.
--
-- Vì sao: 2 mức này lưu ở cột số riêng (product_variants.hold_level, shine_level) nên trước đây metadata
-- không có chữ nào về chúng, ô tìm kiếm (catalog và trợ lý AI) không bắt được "giữ nếp mạnh", "bóng cao".
-- Sau migration, metadata của mỗi sản phẩm = giá trị mọi thuộc tính + cụm chữ 2 mức của các biến thể ĐANG BÁN:
--   giữ nếp: "giữ nếp nhẹ" / "giữ nếp trung bình" / "giữ nếp mạnh" (có tiền tố để không nhầm với độ bóng)
--   độ bóng: "không bóng (mờ)" / "bóng nhẹ" / "bóng vừa" / "bóng cao"
-- Các cụm này KHÔNG hiện ở tag gợi ý của catalog (đã có 2 ô chọn riêng), chỉ để ô tìm kiếm bắt được.
--
-- Câu UPDATE dưới đây là bản chép của Product::metadataRefreshSql() (nguồn duy nhất, xem giải thích ở đó).
-- Không đổi cấu trúc bảng; chạy lặp được (mỗi lần chỉ tính lại metadata từ dữ liệu hiện có).
-- group_concat_max_len: mặc định chỉ 1024 byte và bị CẮT ngầm; nới lên 65535 (sức chứa cột TEXT) cho phiên này.
-- =====================================================================
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
