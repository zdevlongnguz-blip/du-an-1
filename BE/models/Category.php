<!-- dựng thêm 1 api lấy về full danh sách categories từ Database
cả 2 api products và categories đều không cần chặn middleware auth (tức là không bắt người dùng đăng nhập mới xem được danh sách sản phẩm và danh mục) -->


<?php
class Category extends BaseModel
{
    protected $table = 'categories';

    public function getAllCategories()
    {
        $sql = "SELECT * FROM categories ORDER BY id ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tạo danh mục mới.
     *
     * @param string $name Tên danh mục.
     * @param string $slug Slug duy nhất.
     * @return int ID danh mục mới.
     */
    public function createAdmin(string $name = '', string $slug = ''): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `categories` (`name`, `slug`) VALUES (:name, :slug)'
        );
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Sửa tên hoặc slug của danh mục.
     *
     * @param int $categoryId ID danh mục.
     * @param string $name Tên danh mục.
     * @param string $slug Slug danh mục.
     * @return bool True nếu danh mục tồn tại.
     */
    public function updateAdmin(int $categoryId = 0, string $name = '', string $slug = ''): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `categories` SET `name` = :name, `slug` = :slug WHERE `id` = :id'
        );
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':slug', $slug, PDO::PARAM_STR);
        $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0 || $this->find($categoryId) !== false;
    }

    /**
     * Xóa danh mục nhưng không để khóa ngoại xóa theo các sản phẩm đang dùng nó.
     *
     * @param int $categoryId ID danh mục.
     * @return string Kết quả: deleted, not_found hoặc in_use.
     */
    public function deleteAdmin(int $categoryId = 0): string
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM `categories` WHERE `id` = :id '
            . 'AND NOT EXISTS (SELECT 1 FROM `products` WHERE `category_id` = :product_category_id)'
        );
        $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $statement->bindValue(':product_category_id', $categoryId, PDO::PARAM_INT);
        $statement->execute();
        if ($statement->rowCount() === 1) {
            return 'deleted';
        }

        $existsStatement = $this->pdo->prepare('SELECT 1 FROM `categories` WHERE `id` = :id LIMIT 1');
        $existsStatement->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $existsStatement->execute();

        return $existsStatement->fetchColumn() === false ? 'not_found' : 'in_use';
    }
}