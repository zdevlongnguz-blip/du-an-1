USE `du-an-1`;

-- =====================================================================
-- Migration: đổi product_variants.attributes từ object sang MẢNG { key, label, value }.
--
-- Trước: {"dung_tich": "113g", "mui_huong": "mùi cam chanh"}
-- Sau:   [{"key": "dung_tich",  "label": "Dung tích",  "value": "113g"},
--         {"key": "do_giu_nep", "label": "Độ giữ nếp", "value": null},
--         ... (đủ 5 khóa mặc định, đứng đầu theo thứ tự cố định)
--         {"key": "mui_huong",  "label": "Mùi hương",  "value": "mùi cam chanh"}]   <- khóa tự do đứng sau
--
-- Lý do: giữ đúng thứ tự (object JSON bị MySQL tự sắp lại khóa) và mỗi thuộc tính có nhãn tiếng Việt.
-- Định dạng và 5 khóa mặc định được định nghĩa ở BE/extensions/VariantAttributes.php (phải khớp với file này).
--
-- Áp dụng cho MỌI biến thể, kể cả dữ liệu cũ: chỉ đổi định dạng và thêm khóa mặc định còn thiếu (value = null),
-- giá trị đang có giữ nguyên.
--
-- Chạy lặp được: mỗi bước kiểm tra trạng thái trước; dòng đã là mảng thì không đổi lại.
-- MySQL không rollback được lệnh ALTER, nên nếu lỗi giữa chừng, sửa nguyên nhân rồi chạy lại.
-- =====================================================================

-- Bước 1: bỏ ràng buộc cũ "attributes phải là object" (nếu còn), để ghi được dạng mảng.
-- information_schema.CHECK_CONSTRAINTS liệt kê các ràng buộc CHECK đang có trong DB.
SET @attributes_object_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_product_variants_attributes'
);

SET @attributes_drop_object_check = IF(
    @attributes_object_check_exists > 0,
    'ALTER TABLE `product_variants` DROP CHECK `chk_product_variants_attributes`',
    'SET @attributes_object_check_exists = @attributes_object_check_exists'
);

PREPARE attributes_drop_object_check_statement FROM @attributes_drop_object_check;
EXECUTE attributes_drop_object_check_statement;
DEALLOCATE PREPARE attributes_drop_object_check_statement;

-- Bước 2: đổi dữ liệu. Chỉ xử lý dòng còn là object (JSON_TYPE = 'OBJECT'), nên chạy lại không ảnh hưởng.
-- - Phần đầu: 5 khóa mặc định theo thứ tự cố định; JSON_EXTRACT trả NULL khi thiếu khóa -> value = null.
-- - Phần sau: khóa tự do. JSON_KEYS lấy danh sách khóa của object, JSON_TABLE biến danh sách đó thành các dòng
--   để JSON_ARRAYAGG gom lại thành mảng. Khóa tự do hay gặp có nhãn sẵn, khóa khác dùng key thay "_" bằng dấu cách.
-- - JSON_MERGE_PRESERVE nối hai mảng thành một.
UPDATE `product_variants` AS `v`
SET `v`.`attributes` = JSON_MERGE_PRESERVE(
    JSON_ARRAY(
        JSON_OBJECT('key', 'dung_tich', 'label', 'Dung tích', 'value', JSON_EXTRACT(`v`.`attributes`, '$.dung_tich')),
        JSON_OBJECT('key', 'do_giu_nep', 'label', 'Độ giữ nếp', 'value', JSON_EXTRACT(`v`.`attributes`, '$.do_giu_nep')),
        JSON_OBJECT('key', 'do_bong', 'label', 'Độ bóng', 'value', JSON_EXTRACT(`v`.`attributes`, '$.do_bong')),
        JSON_OBJECT('key', 'phu_hop', 'label', 'Phù hợp', 'value', JSON_EXTRACT(`v`.`attributes`, '$.phu_hop')),
        JSON_OBJECT('key', 'kieu_toc', 'label', 'Kiểu tóc', 'value', JSON_EXTRACT(`v`.`attributes`, '$.kieu_toc'))
    ),
    COALESCE(
        (
            SELECT JSON_ARRAYAGG(JSON_OBJECT(
                'key', `custom_keys`.`attribute_key`,
                'label', CASE `custom_keys`.`attribute_key`
                    WHEN 'mui_huong' THEN 'Mùi hương'
                    WHEN 'xuat_xu' THEN 'Xuất xứ'
                    ELSE REPLACE(`custom_keys`.`attribute_key`, '_', ' ')
                END,
                'value', JSON_EXTRACT(`v`.`attributes`, CONCAT('$."', `custom_keys`.`attribute_key`, '"'))
            ))
            FROM JSON_TABLE(
                JSON_KEYS(`v`.`attributes`),
                '$[*]' COLUMNS (`attribute_key` VARCHAR(100) PATH '$')
            ) AS `custom_keys`
            WHERE `custom_keys`.`attribute_key` NOT IN ('dung_tich', 'do_giu_nep', 'do_bong', 'phu_hop', 'kieu_toc')
        ),
        JSON_ARRAY()
    )
)
WHERE JSON_TYPE(`v`.`attributes`) = 'OBJECT';

-- Bước 3: giá trị mặc định của cột đổi từ object rỗng sang mảng rỗng, cập nhật mô tả cột.
-- MODIFY ghi lại toàn bộ định nghĩa cột, chạy lại nhiều lần vẫn cho cùng kết quả.
ALTER TABLE `product_variants`
    MODIFY COLUMN `attributes` JSON NOT NULL DEFAULT (JSON_ARRAY())
    COMMENT 'Thuộc tính của biến thể: mảng JSON [{key, label, value}]. Luôn có 5 khóa mặc định đứng đầu (dung_tich, do_giu_nep, do_bong, phu_hop, kieu_toc), value null nếu chưa rõ; khóa tự do đứng sau. Không lưu giá hay tồn kho ở đây';

-- Bước 4: thêm ràng buộc mới "attributes phải là mảng" (nếu chưa có).
SET @attributes_array_check_exists = (
    SELECT COUNT(*)
    FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND CONSTRAINT_NAME = 'chk_product_variants_attributes_array'
);

SET @attributes_add_array_check = IF(
    @attributes_array_check_exists = 0,
    'ALTER TABLE `product_variants` ADD CONSTRAINT `chk_product_variants_attributes_array` CHECK (JSON_TYPE(`attributes`) = ''ARRAY'')',
    'SET @attributes_array_check_exists = @attributes_array_check_exists'
);

PREPARE attributes_add_array_check_statement FROM @attributes_add_array_check;
EXECUTE attributes_add_array_check_statement;
DEALLOCATE PREPARE attributes_add_array_check_statement;
