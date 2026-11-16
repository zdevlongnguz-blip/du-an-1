<?php

/**
 * Model bảng ai_conversations: kiểm tra quyền sở hữu cuộc hội thoại với trợ lý AI (tầng model).
 * Việc tạo cuộc hội thoại mới nằm trong AiMessage::saveTurns để dùng chung một transaction với các lượt.
 */
class AiConversation extends BaseModel
{
    protected $table = 'ai_conversations';

    /**
     * Kiểm tra cuộc hội thoại có thuộc user này không.
     * Vì sao: nếu không kiểm tra, người dùng có thể đọc hoặc ghi tiếp hội thoại của người khác
     * chỉ bằng cách đoán ID.
     *
     * @param int $conversationId ID cuộc hội thoại cần kiểm tra
     * @param int $userId         ID user đang đăng nhập
     * @return bool True nếu cuộc hội thoại tồn tại và thuộc user này
     */
    public function belongsToUser($conversationId = 0, $userId = 0)
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `ai_conversations` WHERE `id` = :id AND `user_id` = :user_id LIMIT 1'
        );
        $statement->bindValue(':id', (int)$conversationId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /**
     * Lấy ID cuộc hội thoại gần nhất (có lượt mới nhất) của user.
     * Dùng để nạp lại khung chat sau khi đăng nhập lại hoặc tải lại trang.
     *
     * @param int $userId ID user lấy từ JWT
     * @return int|null ID cuộc hội thoại, null nếu user chưa chat lần nào
     */
    public function findLatestIdByUser($userId = 0)
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `ai_conversations` WHERE `user_id` = :user_id '
            . 'ORDER BY `updated_at` DESC, `id` DESC LIMIT 1'
        );
        $statement->bindValue(':user_id', (int)$userId, PDO::PARAM_INT);
        $statement->execute();
        $id = $statement->fetchColumn();

        return $id === false ? null : (int)$id;
    }
}
