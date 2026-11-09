<?php
class Banner extends BaseModel
{
    protected $table = 'banners';

    public function getAllBanners()
    {
        $sql = "SELECT * FROM `banners` ORDER BY `id` ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tạo banner mới.
     *
     * @param array $data Các trường banner đã được kiểm tra.
     * @return int ID banner mới.
     */
    public function createAdmin(array $data = []): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `banners` (`title`, `image`, `content`, `CTA_link`) '
            . 'VALUES (:title, :image, :content, :CTA_link)'
        );
        $statement->bindValue(':title', $data['title'], PDO::PARAM_STR);
        $statement->bindValue(':image', $data['image'], $data['image'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':content', $data['content'], PDO::PARAM_STR);
        $statement->bindValue(':CTA_link', $data['CTA_link'], PDO::PARAM_STR);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Cập nhật các trường banner được cung cấp.
     *
     * @param int $bannerId ID banner.
     * @param array $data Các trường cần thay đổi.
     * @return bool True nếu banner tồn tại và cập nhật thành công.
     */
    public function updateAdmin(int $bannerId = 0, array $data = []): bool
    {
        $allowedColumns = ['title', 'image', 'content', 'CTA_link'];
        $sets = [];
        foreach ($data as $column => $value) {
            if (in_array($column, $allowedColumns, true)) {
                $sets[] = "`{$column}` = :{$column}";
            }
        }
        if ($sets === []) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE `banners` SET ' . implode(', ', $sets) . ' WHERE `id` = :id'
        );
        foreach ($data as $column => $value) {
            if (in_array($column, $allowedColumns, true)) {
                $statement->bindValue(':' . $column, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
        }
        $statement->bindValue(':id', $bannerId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0 || $this->find($bannerId) !== false;
    }

    /**
     * Xóa banner và trả URL ảnh để controller xóa file do hệ thống tải lên.
     *
     * @param int $bannerId ID banner.
     * @return string|null URL ảnh hoặc null nếu không tìm thấy.
     */
    public function deleteAdmin(int $bannerId = 0): ?string
    {
        $findStatement = $this->pdo->prepare('SELECT `image` FROM `banners` WHERE `id` = :id LIMIT 1');
        $findStatement->bindValue(':id', $bannerId, PDO::PARAM_INT);
        $findStatement->execute();
        $image = $findStatement->fetchColumn();
        if ($image === false) {
            return null;
        }

        $deleteStatement = $this->pdo->prepare('DELETE FROM `banners` WHERE `id` = :id');
        $deleteStatement->bindValue(':id', $bannerId, PDO::PARAM_INT);
        $deleteStatement->execute();

        return $image === null ? '' : (string) $image;
    }
}