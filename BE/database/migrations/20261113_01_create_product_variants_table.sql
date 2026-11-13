USE `du-an-1`;

-- =====================================================================
-- Migration 01: tạo bảng product_variants (biến thể sản phẩm)
-- Bảng products chỉ còn thông tin chung; giá, giá giảm, tồn kho nằm ở biến thể.
-- Cột attributes (JSON) chỉ lưu thuộc tính mô tả mềm dẻo (dung tích, mùi hương...).
-- Giá và tồn kho là cột thật để có khóa ngoại, CHECK, index và trừ kho nguyên tử.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của biến thể',
  `product_id` INT NOT NULL COMMENT 'Sản phẩm cha (products.id). Xóa sản phẩm thì xóa luôn các biến thể',
  `sku` VARCHAR(100) NOT NULL COMMENT 'Mã SKU của biến thể, duy nhất toàn hệ thống',
  `variant_name` VARCHAR(255) NOT NULL COMMENT 'Tên hiển thị của biến thể, ví dụ: Hũ 113g. Duy nhất trong cùng một sản phẩm',
  `attributes` JSON NOT NULL DEFAULT (JSON_OBJECT()) COMMENT 'Thuộc tính mô tả mềm dẻo dạng JSON object, ví dụ: {"dung_tich":"113g","mui_huong":"Thảo dược"}. Không lưu giá hay tồn kho ở đây',
  `price` DECIMAL(10,2) NOT NULL COMMENT 'Giá niêm yết của biến thể (VND), không được âm',
  `sale_price` DECIMAL(10,2) DEFAULT NULL COMMENT 'Giá khuyến mãi (VND). NULL = không giảm giá. Nếu có thì phải nhỏ hơn giá niêm yết',
  `final_price` DECIMAL(10,2) GENERATED ALWAYS AS (COALESCE(`sale_price`, `price`)) STORED COMMENT 'Giá bán thực tế do DB tự tính: giá khuyến mãi nếu có, ngược lại là giá niêm yết. Dùng để sắp xếp, lọc theo giá',
  `stock` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số lượng tồn kho của biến thể. Kiểu UNSIGNED nên DB từ chối trừ kho xuống số âm',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Biến thể mặc định của sản phẩm: 1 = mặc định, 0 = không. Mỗi sản phẩm tối đa 1 biến thể mặc định',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Trạng thái bán: 1 = đang bán, 0 = ngừng bán (ẩn nhưng vẫn giữ lịch sử đơn hàng)',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo biến thể',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
  `default_product_id` INT GENERATED ALWAYS AS (IF(`is_default` = 1, `product_id`, NULL)) VIRTUAL COMMENT 'Cột kỹ thuật do DB tự tính, dùng để ép mỗi sản phẩm chỉ có 1 biến thể mặc định. Không ghi trực tiếp',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_variants_sku` (`sku`),
  UNIQUE KEY `uq_product_variants_product_name` (`product_id`, `variant_name`),
  UNIQUE KEY `uq_product_variants_id_product` (`id`, `product_id`),
  UNIQUE KEY `uq_product_variants_default` (`default_product_id`),
  KEY `idx_product_variants_product_active_price` (`product_id`, `is_active`, `final_price`),
  KEY `idx_product_variants_final_price` (`final_price`),
  CONSTRAINT `fk_product_variants_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_product_variants_sku` CHECK (CHAR_LENGTH(TRIM(`sku`)) > 0),
  CONSTRAINT `chk_product_variants_name` CHECK (CHAR_LENGTH(TRIM(`variant_name`)) > 0),
  CONSTRAINT `chk_product_variants_attributes` CHECK (JSON_TYPE(`attributes`) = 'OBJECT'),
  CONSTRAINT `chk_product_variants_price` CHECK (`price` >= 0),
  CONSTRAINT `chk_product_variants_sale_price` CHECK (`sale_price` IS NULL OR (`sale_price` >= 0 AND `sale_price` < `price`)),
  CONSTRAINT `chk_product_variants_is_default` CHECK (`is_default` IN (0, 1)),
  CONSTRAINT `chk_product_variants_is_active` CHECK (`is_active` IN (0, 1)),
  CONSTRAINT `chk_product_variants_default_active` CHECK (`is_default` = 0 OR `is_active` = 1)
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Biến thể sản phẩm: giá, giá giảm, tồn kho và thuộc tính JSON theo từng biến thể';
