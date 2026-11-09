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
     * Tạo bình luận hiển thị ngay cho user đã đăng nhập.
     *
     * @param string $productSlug Slug sản phẩm được bình luận.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @param string $content Nội dung bình luận đã được kiểm tra.
     * @return int|null ID bình luận mới, hoặc null nếu không tìm thấy sản phẩm.
     */
    public function createForProductSlug(string $productSlug, int $userId, string $content): ?int
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

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO `comments` (`product_id`, `user_id`, `content`, `status`) '
            . 'VALUES (:product_id, :user_id, :content, 0)'
        );
        $insertStatement->bindValue(':product_id', (int) $productId, PDO::PARAM_INT);
        $insertStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $insertStatement->bindValue(':content', $content, PDO::PARAM_STR);
        $insertStatement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Sửa nội dung bình luận nếu bình luận đang hiển thị thuộc về user.
     *
     * @param int $commentId ID bình luận cần sửa.
     * @param int $userId ID lấy từ payload JWT đã xác minh.
     * @param string $content Nội dung mới đã được kiểm tra.
     * @return bool True nếu bình luận thuộc user và được xử lý thành công.
     */
    public function updateOwned(int $commentId, int $userId, string $content): bool
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
            'UPDATE `comments` SET `content` = :content '
            . 'WHERE `id` = :id AND `user_id` = :user_id'
        );
        $updateStatement->bindValue(':content', $content, PDO::PARAM_STR);
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
}