USE `du-an-1`;

-- Thêm 3 cột lưu kết quả AI kiểm duyệt bình luận (Comment Guard, chạy trên Google Colab).
-- moderation_label: nhãn model 1 (visolex/visobert-hsd): CLEAN / OFFENSIVE / HATE.
-- sentiment       : nhãn model 2 (5CD-AI/Vietnamese-Sentiment-visobert): POS / NEG / NEU.
--                   NULL khi câu đã bị model 1 chặn (model 2 không chạy).
-- toxic_prob      : xác suất độc hại 0..1 của model 1, lưu để sau này chỉnh ngưỡng theo dữ liệu thật.
-- Cả 3 cột đều NULL nghĩa là CHƯA CHẤM (bình luận cũ, hoặc lúc gửi thì dịch vụ AI bị lỗi).
-- Dùng VARCHAR thay vì ENUM: Colab có thể thêm nhãn mới mà không phải viết migration đổi ENUM.
-- Cột status cũ vẫn là cột quyết định hiện (1) hay ẩn (0); 3 cột này chỉ lưu kết quả AI.

-- MySQL không có "ADD COLUMN IF NOT EXISTS", nên kiểm tra cột trong information_schema trước,
-- rồi ghép câu lệnh và chạy bằng PREPARE. Nhờ vậy chạy file này nhiều lần cũng không lỗi.

SET @comments_label_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'moderation_label'
);
SET @comments_label_sql = IF(
    @comments_label_exists = 0,
    'ALTER TABLE `comments` ADD COLUMN `moderation_label` VARCHAR(20) NULL DEFAULT NULL AFTER `status`',
    'SET @comments_label_exists = @comments_label_exists'
);
PREPARE comments_label_statement FROM @comments_label_sql;
EXECUTE comments_label_statement;
DEALLOCATE PREPARE comments_label_statement;

SET @comments_sentiment_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'sentiment'
);
SET @comments_sentiment_sql = IF(
    @comments_sentiment_exists = 0,
    'ALTER TABLE `comments` ADD COLUMN `sentiment` VARCHAR(10) NULL DEFAULT NULL AFTER `moderation_label`',
    'SET @comments_sentiment_exists = @comments_sentiment_exists'
);
PREPARE comments_sentiment_statement FROM @comments_sentiment_sql;
EXECUTE comments_sentiment_statement;
DEALLOCATE PREPARE comments_sentiment_statement;

-- DECIMAL(5,4): tối đa 1 chữ số trước dấu phẩy và 4 chữ số sau, đủ cho 0.0000 đến 1.0000.
SET @comments_toxic_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'toxic_prob'
);
SET @comments_toxic_sql = IF(
    @comments_toxic_exists = 0,
    'ALTER TABLE `comments` ADD COLUMN `toxic_prob` DECIMAL(5,4) NULL DEFAULT NULL AFTER `sentiment`',
    'SET @comments_toxic_exists = @comments_toxic_exists'
);
PREPARE comments_toxic_statement FROM @comments_toxic_sql;
EXECUTE comments_toxic_statement;
DEALLOCATE PREPARE comments_toxic_statement;
