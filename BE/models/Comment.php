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
}