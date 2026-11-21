USE `du-an-1`;

-- =====================================================================
-- Migration: mỗi biến thể có 1 ảnh đại diện riêng (cột product_variants.image).
--
-- Dùng ảnh này để:
--   - làm bộ ảnh slide ở trang chi tiết sản phẩm (thay cho bảng product_images),
--   - hiện đúng ảnh của biến thể khách đang mua trong giỏ hàng và đơn hàng.
--
-- Cột cho phép NULL (ảnh biến thể không bắt buộc). Khi NULL, BE dùng ảnh của sản phẩm (products.image) thay thế.
-- products.image vẫn giữ nguyên làm ảnh đại diện chung (thẻ sản phẩm ở trang chủ, danh mục).
-- Bảng product_images CHƯA xóa ở migration này: giữ lại để không mất dữ liệu, sẽ dọn ở migration sau.
--
-- Chạy lặp được: chỉ thêm cột khi chưa có.
-- =====================================================================

-- Bước 1: thêm cột image nếu chưa có.
-- information_schema.COLUMNS liệt kê các cột đang có; đếm = 0 nghĩa là chưa thêm.
-- MySQL không có "ADD COLUMN IF NOT EXISTS", nên ghép câu lệnh rồi chạy bằng PREPARE/EXECUTE.
SET @variant_image_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'product_variants'
      AND COLUMN_NAME = 'image'
);

SET @variant_image_add = IF(
    @variant_image_exists = 0,
    'ALTER TABLE `product_variants` ADD COLUMN `image` VARCHAR(255) NULL COMMENT ''Ảnh đại diện của biến thể (tên file trong uploads). NULL = dùng ảnh của sản phẩm'' AFTER `attributes`',
    'SET @variant_image_exists = @variant_image_exists'
);

PREPARE variant_image_add_statement FROM @variant_image_add;
EXECUTE variant_image_add_statement;
DEALLOCATE PREPARE variant_image_add_statement;

-- Không điền sẵn ảnh cho biến thể cũ: để NULL thì BE tự dùng ảnh sản phẩm, và admin nhìn biết ngay
-- biến thể nào chưa có ảnh riêng. Nếu chép products.image sang, khi admin đổi ảnh sản phẩm thì các biến thể
-- vẫn trỏ vào file ảnh cũ.
