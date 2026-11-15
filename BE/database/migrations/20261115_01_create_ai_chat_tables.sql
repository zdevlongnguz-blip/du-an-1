USE `du-an-1`;

-- =====================================================================
-- Migration 01: tạo 2 bảng cho chức năng chat với trợ lý AI (Gemini).
-- ai_conversations: mỗi cuộc hội thoại thuộc về một user đã đăng nhập.
-- ai_messages: từng lượt trong cuộc hội thoại, lưu nguyên văn "parts" của Gemini
-- để giữ functionCall, functionResponse và thoughtSignature khi đọc lại.
-- Chỉ người đã đăng nhập mới được chat, nên không có cột cho khách.
-- Chạy lặp được nhờ IF NOT EXISTS.
-- =====================================================================

-- Bảng cuộc hội thoại: một user có thể có nhiều cuộc hội thoại.
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của cuộc hội thoại',
  `user_id` BIGINT UNSIGNED NOT NULL COMMENT 'Chủ sở hữu (users.id). Mọi truy cập phải kiểm tra user_id khớp với người đang đăng nhập',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm bắt đầu cuộc hội thoại',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm có lượt hội thoại mới gần nhất',
  PRIMARY KEY (`id`),
  KEY `idx_ai_conversations_user` (`user_id`),
  CONSTRAINT `fk_ai_conversations_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Cuộc hội thoại với trợ lý AI, mỗi cuộc thuộc về một user đã đăng nhập';

-- Bảng lượt hội thoại: mỗi dòng là một lượt của user hoặc model.
CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Khóa chính của lượt hội thoại',
  `conversation_id` BIGINT UNSIGNED NOT NULL COMMENT 'Thuộc cuộc hội thoại nào (ai_conversations.id). Xóa cuộc hội thoại thì xóa luôn các lượt',
  `seq` INT UNSIGNED NOT NULL COMMENT 'Thứ tự lượt trong cuộc hội thoại, bắt đầu từ 1. Dùng để sắp xếp chắc chắn, không phụ thuộc thời gian',
  `role` ENUM('user', 'model') NOT NULL COMMENT 'Ai nói: user (người hỏi) hoặc model (Gemini). Giống định dạng Gemini',
  `parts` JSON NOT NULL COMMENT 'Nguyên văn mảng parts của Gemini: text, functionCall, functionResponse, thoughtSignature. Không sửa nội dung khi lưu',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Thời điểm lưu lượt',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Thời điểm cập nhật gần nhất',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ai_messages_conversation_seq` (`conversation_id`, `seq`),
  CONSTRAINT `fk_ai_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_ai_messages_parts` CHECK (JSON_TYPE(`parts`) = 'ARRAY')
) ENGINE=InnoDB
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci
  COMMENT='Từng lượt trong cuộc hội thoại AI, lưu parts của Gemini nguyên văn';
