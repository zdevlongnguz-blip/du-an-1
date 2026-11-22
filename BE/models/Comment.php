<?php
/** Mô hình truy vấn bình luận của sản phẩm. */
class Comment extends BaseModel
{
    protected $table = 'comments';

    /**
        * Lấy một trang bình luận đang hiển thị theo slug sản phẩm.
     *
     * @param string $productSlug Slug của sản phẩm cần lấy bình luận.
    * @param int $page Trang hiện tại, bắt đầu từ 1 (mặc định là 1).
    * @param int $perPage Số bình luận tối đa trong một trang (mặc định là 10).
     * @return array|null Dữ liệu bình luận và phân trang, hoặc null nếu không có sản phẩm.
     */
    public function getByProductSlug(string $productSlug, int $page = 1, int $perPage = 10): ?array
    {
        $productStatement = $this->pdo->prepare(
            'SELECT `id` FROM `products` WHERE `slug` = :slug LIMIT 1'
        );
        $productStatement->bindValue(':slug', $productSlug, PDO::PARAM_STR);
        $productStatement->execute();
        $productId = $productStatement->fetchColumn();

        if ($productId === false) {
            return null;
        }

        $productId = (int) $productId;
        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM `comments` WHERE `product_id` = :product_id AND `status` = 1'
        );
        $countStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $countStatement->execute();
        $totalItems = (int) $countStatement->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $commentsStatement = $this->pdo->prepare(
            'SELECT `comments`.`id`, `comments`.`product_id`, `comments`.`user_id`, '
            . '`users`.`email`, `users`.`role`, `comments`.`content`, '
            . '`comments`.`created_at`, `comments`.`updated_at` '
            . 'FROM `comments` '
            . 'INNER JOIN `users` ON `users`.`id` = `comments`.`user_id` '
            . 'WHERE `comments`.`product_id` = :product_id AND `comments`.`status` = 1 '
            . 'ORDER BY `comments`.`created_at` DESC, `comments`.`id` DESC LIMIT :limit OFFSET :offset'
        );
        $commentsStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $commentsStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $commentsStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $commentsStatement->execute();

        return [
            'data' => $commentsStatement->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1,
            ],
        ];
    }

    /**
     * Kiểm tra sản phẩm có tồn tại không.
     * Controller gọi hàm này TRƯỚC khi gọi AI kiểm duyệt, để product_id sai không tốn một lượt gọi Colab.
     *
     * @param int $productId ID sản phẩm.
     * @return bool True nếu sản phẩm tồn tại.
     */
    public function productExists(int $productId = 0): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM `products` WHERE `id` = :product_id LIMIT 1');
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /**
     * Tạo bình luận cho user đã đăng nhập, kèm trạng thái và nhãn do AI kiểm duyệt quyết định.
     *
     * @param int $productId ID sản phẩm được bình luận.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @param string $content Nội dung bình luận đã được kiểm tra.
     * @param int $status 1 = hiện ngay, 0 = ẩn/chờ duyệt (mặc định 0 cho an toàn).
     * @param array $labels Nhãn AI: ['moderation_label' => ..., 'sentiment' => ..., 'toxic_prob' => ...],
     *                      thiếu khóa nào thì lưu NULL (chưa chấm).
     * @return int|null ID bình luận mới, hoặc null nếu không tìm thấy sản phẩm.
     */
    public function createForProductId(int $productId = 0, int $userId = 0, string $content = '', int $status = 0, array $labels = []): ?int
    {
        $productStatement = $this->pdo->prepare(
            'SELECT `id` FROM `products` WHERE `id` = :product_id LIMIT 1'
        );
        $productStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $productStatement->execute();
        $foundProductId = $productStatement->fetchColumn();

        if ($foundProductId === false) {
            return null;
        }

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO `comments` (`product_id`, `user_id`, `content`, `status`, '
            . '`moderation_label`, `sentiment`, `toxic_prob`) '
            . 'VALUES (:product_id, :user_id, :content, :status, :moderation_label, :sentiment, :toxic_prob)'
        );
        $insertStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $insertStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $insertStatement->bindValue(':content', $content, PDO::PARAM_STR);
        $insertStatement->bindValue(':status', $status === 1 ? 1 : 0, PDO::PARAM_INT);
        $this->bindLabels($insertStatement, $labels);
        $insertStatement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Lấy bình luận nếu nó thuộc về user, để controller so nội dung cũ trước khi gọi AI kiểm duyệt.
     *
     * @param int $commentId ID bình luận.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @return array|null ['content', 'status', 'moderation_label'] hoặc null nếu không có/không thuộc user.
     */
    public function findOwned(int $commentId = 0, int $userId = 0): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT `content`, `status`, `moderation_label` FROM `comments` '
            . 'WHERE `id` = :id AND `user_id` = :user_id LIMIT 1'
        );
        $statement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Sửa nội dung bình luận thuộc về user, ghi lại trạng thái và nhãn AI của nội dung mới.
     *
     * @param int $commentId ID bình luận cần sửa.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @param string $content Nội dung mới đã được kiểm tra.
     * @param int $status 1 = hiện ngay, 0 = ẩn/chờ duyệt (mặc định 0 cho an toàn).
     * @param array $labels Nhãn AI của nội dung mới (xem createForProductId).
     * @return bool True nếu bình luận thuộc user và được xử lý thành công.
     */
    public function updateOwned(int $commentId = 0, int $userId = 0, string $content = '', int $status = 0, array $labels = []): bool
    {
        $findStatement = $this->pdo->prepare(
            'SELECT `content` FROM `comments` '
            . 'WHERE `id` = :id AND `user_id` = :user_id LIMIT 1'
        );
        $findStatement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $findStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $findStatement->execute();
        $currentContent = $findStatement->fetchColumn();

        if ($currentContent === false) {
            return false;
        }

        if ($currentContent === $content) {
            return true;
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE `comments` SET `content` = :content, `status` = :status, '
            . '`moderation_label` = :moderation_label, `sentiment` = :sentiment, `toxic_prob` = :toxic_prob '
            . 'WHERE `id` = :id AND `user_id` = :user_id'
        );
        $updateStatement->bindValue(':content', $content, PDO::PARAM_STR);
        $updateStatement->bindValue(':status', $status === 1 ? 1 : 0, PDO::PARAM_INT);
        $this->bindLabels($updateStatement, $labels);
        $updateStatement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $updateStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $updateStatement->execute();

        return $updateStatement->rowCount() === 1;
    }

    /**
     * Xóa cứng bình luận đang hiển thị nếu nó thuộc về user.
     *
     * @param int $commentId ID bình luận cần xóa.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @return bool True nếu xóa được bình luận thuộc user.
     */
    public function deleteOwned(int $commentId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM `comments` '
            . 'WHERE `id` = :id AND `user_id` = :user_id'
        );
        $statement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /**
     * Lấy danh sách bình luận cho trang quản trị, lọc trạng thái và phân trang.
     *
     * @param int|null $status 0 chờ duyệt, 1 đã duyệt, null lấy tất cả.
     * @param int $page Trang bắt đầu từ 1.
     * @param int $perPage Số bình luận mỗi trang.
     * @return array Danh sách bình luận và thông tin phân trang.
     */
    public function getAdminList(?int $status = null, int $page = 1, int $perPage = 10): array
    {
        $whereSql = $status === null ? '' : ' WHERE `comments`.`status` = :status';
        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM `comments`' . $whereSql);
        if ($status !== null) {
            $countStatement->bindValue(':status', $status, PDO::PARAM_INT);
        }
        $countStatement->execute();
        $totalItems = (int) $countStatement->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $listStatement = $this->pdo->prepare(
            'SELECT `comments`.`id`, `comments`.`product_id`, `products`.`name` AS `product_name`, '
            . '`comments`.`user_id`, `users`.`email`, `comments`.`content`, `comments`.`status`, '
            . '`comments`.`moderation_label`, `comments`.`sentiment`, `comments`.`toxic_prob`, '
            . '`comments`.`created_at`, `comments`.`updated_at` '
            . 'FROM `comments` INNER JOIN `products` ON `products`.`id` = `comments`.`product_id` '
            . 'INNER JOIN `users` ON `users`.`id` = `comments`.`user_id`' . $whereSql
            . ' ORDER BY `comments`.`created_at` DESC, `comments`.`id` DESC LIMIT :limit OFFSET :offset'
        );
        if ($status !== null) {
            $listStatement->bindValue(':status', $status, PDO::PARAM_INT);
        }
        $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStatement->execute();

        return [
            'data' => $listStatement->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1,
            ],
        ];
    }

    /**
     * Duyệt bình luận; bình luận đã duyệt vẫn được xem là xử lý thành công.
     *
     * @param int $commentId ID bình luận.
     * @return bool True nếu bình luận tồn tại.
     */
    public function approveAdmin(int $commentId = 0): bool
    {
        $statement = $this->pdo->prepare('UPDATE `comments` SET `status` = 1 WHERE `id` = :id');
        $statement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $statement->execute();

        if ($statement->rowCount() > 0) {
            return true;
        }
        $existsStatement = $this->pdo->prepare('SELECT 1 FROM `comments` WHERE `id` = :id LIMIT 1');
        $existsStatement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $existsStatement->execute();

        return $existsStatement->fetchColumn() !== false;
    }

    /**
     * Xóa bình luận khỏi hệ thống quản trị.
     *
     * @param int $commentId ID bình luận.
     * @return bool True nếu có bình luận được xóa.
     */
    public function deleteAdmin(int $commentId = 0): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM `comments` WHERE `id` = :id');
        $statement->bindValue(':id', $commentId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /**
     * Gắn 3 nhãn AI vào câu lệnh đã prepare. Giá trị thiếu hoặc null được lưu NULL (chưa chấm).
     * toxic_prob gửi dạng chuỗi số vì PDO không có kiểu riêng cho DECIMAL; MySQL tự đổi sang DECIMAL(5,4).
     *
     * @param PDOStatement $statement Câu lệnh có sẵn :moderation_label, :sentiment, :toxic_prob.
     * @param array $labels Nhãn AI.
     * @return void
     */
    private function bindLabels(PDOStatement $statement, array $labels = []): void
    {
        $label = $labels['moderation_label'] ?? null;
        $sentiment = $labels['sentiment'] ?? null;
        $toxicProb = $labels['toxic_prob'] ?? null;

        $statement->bindValue(':moderation_label', $label, $label === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':sentiment', $sentiment, $sentiment === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        if ($toxicProb === null) {
            $statement->bindValue(':toxic_prob', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':toxic_prob', sprintf('%.4f', (float) $toxicProb), PDO::PARAM_STR);
        }
    }
}