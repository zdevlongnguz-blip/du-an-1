<?php

/**
 * Model bảng ai_messages: đọc và ghi từng lượt trong cuộc hội thoại với Gemini (tầng model).
 * Cột parts lưu nguyên văn mảng parts của Gemini dạng JSON, để đọc lại đúng functionCall và thoughtSignature.
 */
class AiMessage extends BaseModel
{
    protected $table = 'ai_messages';

    /**
     * Mở kết nối DB rồi chuyển bộ mã của kết nối sang utf8mb4.
     *
     * Vì sao: BaseModel kết nối bằng charset=utf8 (mỗi ký tự tối đa 3 byte), nên emoji (4 byte)
     * bị đổi thành "?" khi đọc lại. Chỉ đổi cho model này để không ảnh hưởng các phần khác dùng BaseModel.
     */
    public function __construct()
    {
        parent::__construct();
        $this->pdo->exec('SET NAMES utf8mb4');
    }

    /**
     * Lấy các lượt gần nhất của cuộc hội thoại, theo thứ tự cũ -> mới, đúng định dạng Gemini.
     *
     * Lịch sử phải bắt đầu bằng câu hỏi dạng chữ của user. Nếu cắt trúng giữa cặp
     * functionCall/functionResponse thì bỏ các lượt đầu cho tới khi hợp lệ,
     * giống cách Gemini::trimHistory xử lý.
     *
     * @param int $conversationId ID cuộc hội thoại
     * @param int $limit          Số lượt tối đa lấy (1-100)
     * @return array Danh sách ['role' => 'user'|'model', 'parts' => [...]]
     */
    public function getRecent($conversationId = 0, $limit = 30)
    {
        $limit = min(100, max(1, (int)$limit));

        // LIMIT chỉ cắt được từ đầu theo thứ tự sắp xếp, nên lấy mới nhất trước rồi đảo lại cho đúng thứ tự.
        $statement = $this->pdo->prepare(
            'SELECT `role`, `parts` FROM `ai_messages` '
            . 'WHERE `conversation_id` = :conversation_id ORDER BY `seq` DESC LIMIT :limit'
        );
        $statement->bindValue(':conversation_id', (int)$conversationId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = array_reverse($statement->fetchAll(PDO::FETCH_ASSOC));

        $history = [];
        foreach ($rows as $row) {
            $parts = json_decode($row['parts'], true);
            // Bỏ lượt có parts hỏng hoặc rỗng, đúng điều kiện của Gemini::cleanHistory.
            // Nếu để Gemini tự bỏ, số lượt gửi đi sẽ lệch với số lượt controller đếm
            // và controller lưu sai phần lịch sử mới.
            if (!is_array($parts) || $parts === []) {
                continue;
            }
            $history[] = ['role' => $row['role'], 'parts' => $parts];
        }

        // Bỏ các lượt đầu cho tới khi gặp câu hỏi dạng chữ của user.
        while ($history !== [] && !($history[0]['role'] === 'user' && isset($history[0]['parts'][0]['text']))) {
            array_shift($history);
        }

        return $history;
    }

    /**
     * Lấy các lượt gần nhất kèm thời điểm lưu, theo thứ tự cũ -> mới. Dùng để hiển thị lại khung chat
     * (khác getRecent: getRecent chỉ trả role + parts đúng định dạng gửi cho Gemini).
     *
     * @param int $conversationId ID cuộc hội thoại (nơi gọi phải kiểm tra quyền sở hữu trước)
     * @param int $limit          Số lượt tối đa lấy (1-100)
     * @return array Danh sách ['role' => 'user'|'model', 'parts' => [...], 'created_at' => 'Y-m-d H:i:s']
     */
    public function getRecentWithTime($conversationId = 0, $limit = 30)
    {
        $limit = min(100, max(1, (int)$limit));

        // Lấy mới nhất trước (LIMIT cắt từ đầu) rồi đảo lại cho đúng thứ tự thời gian.
        $statement = $this->pdo->prepare(
            'SELECT `role`, `parts`, `created_at` FROM `ai_messages` '
            . 'WHERE `conversation_id` = :conversation_id ORDER BY `seq` DESC LIMIT :limit'
        );
        $statement->bindValue(':conversation_id', (int)$conversationId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $turns = [];
        foreach (array_reverse($statement->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $parts = json_decode($row['parts'], true);
            if (!is_array($parts) || $parts === []) {
                continue;
            }
            $turns[] = ['role' => $row['role'], 'parts' => $parts, 'created_at' => $row['created_at']];
        }

        return $turns;
    }

    /**
     * Ghi các lượt mới vào cuộc hội thoại; nếu chưa có cuộc hội thoại thì tạo mới. Tất cả trong một transaction.
     *
     * Transaction: hoặc ghi đủ (cuộc hội thoại + mọi lượt), hoặc không ghi gì.
     * Nhờ vậy lỗi giữa chừng không để lại cuộc hội thoại rỗng hay lịch sử thiếu nửa chừng.
     * FOR UPDATE khóa hàng để hai request cùng lúc không lấy cùng một số thứ tự (seq).
     *
     * @param int   $conversationId ID cuộc hội thoại; 0 = tạo cuộc hội thoại mới cho $userId
     * @param array $turns          Các lượt mới theo đúng thứ tự, mỗi lượt ['role' => ..., 'parts' => [...]]
     * @param int   $userId         ID user lấy từ JWT, bắt buộc khi tạo cuộc hội thoại mới
     * @return int ID cuộc hội thoại đã lưu
     * @throws InvalidArgumentException Khi cần tạo cuộc hội thoại mới mà thiếu $userId
     * @throws RuntimeException         Khi không mã hóa được parts sang JSON
     * @throws Throwable                Khi lỗi DB, transaction được hoàn tác trước khi ném lại
     */
    public function saveTurns($conversationId = 0, $turns = [], $userId = 0)
    {
        $conversationId = (int)$conversationId;
        $userId = (int)$userId;
        if ($conversationId < 1 && $userId < 1) {
            throw new InvalidArgumentException('Thiếu user để tạo cuộc hội thoại mới.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($conversationId < 1) {
                $createStatement = $this->pdo->prepare('INSERT INTO `ai_conversations` (`user_id`) VALUES (:user_id)');
                $createStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $createStatement->execute();
                $conversationId = (int)$this->pdo->lastInsertId();
            }

            $seqStatement = $this->pdo->prepare(
                'SELECT COALESCE(MAX(`seq`), 0) FROM `ai_messages` WHERE `conversation_id` = :conversation_id FOR UPDATE'
            );
            $seqStatement->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
            $seqStatement->execute();
            $seq = (int)$seqStatement->fetchColumn();

            $insertStatement = $this->pdo->prepare(
                'INSERT INTO `ai_messages` (`conversation_id`, `seq`, `role`, `parts`) '
                . 'VALUES (:conversation_id, :seq, :role, :parts)'
            );
            foreach ($turns as $turn) {
                $seq++;
                // JSON_UNESCAPED_UNICODE giữ tiếng Việt đọc được trong DB. Object rỗng {} vẫn được giữ đúng.
                $parts = json_encode($turn['parts'], JSON_UNESCAPED_UNICODE);
                if ($parts === false) {
                    throw new RuntimeException('Không mã hóa được lịch sử chat sang JSON.');
                }

                $insertStatement->bindValue(':conversation_id', $conversationId, PDO::PARAM_INT);
                $insertStatement->bindValue(':seq', $seq, PDO::PARAM_INT);
                $insertStatement->bindValue(':role', $turn['role'], PDO::PARAM_STR);
                $insertStatement->bindValue(':parts', $parts, PDO::PARAM_STR);
                $insertStatement->execute();
            }

            // Cột updated_at chỉ tự đổi khi giá trị thay đổi, nên đặt tường minh để biết hội thoại vừa có lượt mới.
            $touchStatement = $this->pdo->prepare(
                'UPDATE `ai_conversations` SET `updated_at` = CURRENT_TIMESTAMP WHERE `id` = :id'
            );
            $touchStatement->bindValue(':id', $conversationId, PDO::PARAM_INT);
            $touchStatement->execute();

            $this->pdo->commit();

            return $conversationId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
