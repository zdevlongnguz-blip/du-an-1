USE `du-an-1`;

-- =====================================================================
-- Migration 01: tạo bảng lưu vector (embedding) của từng biến thể sản phẩm,
-- dùng cho chức năng tìm kiếm nâng cao theo nghĩa (vector search).
--
-- Embedding: mảng 768 số thực đại diện cho "ý nghĩa" của một đoạn văn, do server Python
-- embed-service tạo ra. Hai đoạn văn gần nghĩa thì hai vector giống nhau (đo bằng cosine).
--
-- Mỗi biến thể một dòng. Đoạn văn đem đi tạo vector (embed_text) được ghép từ tên sản phẩm,
-- tên biến thể, danh mục, mô tả và attributes. KHÔNG chứa giá, tồn kho, lượt xem, lượt bán
-- vì các giá trị đó thay đổi liên tục và được lọc/sắp xếp bằng SQL.
--
-- Đây là dữ liệu suy ra: xóa đi vẫn tạo lại được bằng script BE/database/embed_products.php.
-- MySQL 8.0 chưa có kiểu VECTOR nên vector được lưu dạng JSON.
-- Chạy lặp được nhờ IF NOT EXISTS.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `product_variant_embeddings` (
  `variant_id` BIGINT UNSIGNED NOT NULL COMMENT 'Biến thể được tạo vector (product_variants.id), cũng là khóa chính. Xóa biến thể thì xóa luôn vector',
  `embed_text` TEXT COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Đoạn văn đã ghép để tạo vector, giữ lại để xem vector được tạo từ câu nào',
  `text_hash` CHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 của embed_text. Ghép lại đoạn văn mà hash khác nghĩa là dữ liệu đã đổi, cần tạo lại vector',
  `embedding` JSON NOT NULL COMMENT 'Vector dạng mảng JSON các số thực',
  `dim` SMALLINT UNSIGNED NOT NULL COMMENT 'Số chiều của vector (model hiện tại: 768)',
  `model_name` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Tên model đã tạo vector. Đổi model thì phải tạo lại toàn bộ vector',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo vector lần đầu',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm tạo lại vector gần nhất',
  PRIMARY KEY (`variant_id`),
  CONSTRAINT `fk_product_variant_embeddings_variant`
    FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_product_variant_embeddings_embedding` CHECK (JSON_TYPE(`embedding`) = 'ARRAY')
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Vector embedding của từng biến thể sản phẩm, dùng cho tìm kiếm nâng cao theo nghĩa';
