<?php
class Product extends BaseModel
{
    protected $table = 'products';

    public function getFiltered($search = '', $category = null, $page = 1, $perPage = 8)
    {
        $page = max(1, (int)$page);
        $perPage = max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;
        $params = [];
        $conditions = [];

        $search = trim((string)$search);
        if ($search !== '') {
            $conditions[] = "(`products`.`name` LIKE :keyword OR `products`.`code` LIKE :keyword OR `products`.`description` LIKE :keyword)";
            $params['keyword'] = "%{$search}%";
        }

        if ($category !== null && $category !== '') {
            $conditions[] = "`categories`.`slug` = :category";
            $params['category'] = (string)$category;
        }

        $whereSql = !empty($conditions) ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT `products`.*, `products`.`total_stock` AS `stock`, `dv`.`price`, `dv`.`sale_price`, `categories`.`name` AS `category_name`
                FROM `v_product_catalog` AS `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                LEFT JOIN `product_variants` AS `dv` ON `dv`.`product_id` = `products`.`id` AND `dv`.`is_default` = 1 AND `dv`.`is_active` = 1
                {$whereSql}
                ORDER BY `products`.`id` DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) AS total_items
                     FROM `v_product_catalog` AS `products`
                     LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                     {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalItems = (int)($countRow['total_items'] ?? 0);

        $totalPages = $totalItems > 0 ? (int)ceil($totalItems / $perPage) : 1;

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Lấy tối đa 5 sản phẩm xếp theo lượt xem hoặc lượt bán.
     *
     * @param string $sortBy Tiêu chí xếp hạng: views hoặc sales
     * @return array Danh sách tối đa 5 sản phẩm
     */
    public function getTop($sortBy = 'sales')
    {
        // Chỉ cho phép hai cột đã quy định để không đưa tên cột từ request vào SQL.
        $sortColumns = [
            'views' => '`products`.`view_count`',
            'sales' => '`products`.`sales_count`',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['sales'];

        // Dùng cùng nguồn và cùng cột với danh sách sản phẩm (view + biến thể mặc định) để FE nhận đủ giá và tồn kho.
        $sql = "SELECT `products`.*, `products`.`total_stock` AS `stock`, `dv`.`price`, `dv`.`sale_price`, `categories`.`name` AS `category_name`
                FROM `v_product_catalog` AS `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                LEFT JOIN `product_variants` AS `dv` ON `dv`.`product_id` = `products`.`id` AND `dv`.`is_default` = 1 AND `dv`.`is_active` = 1
                ORDER BY {$sortColumn} DESC, `products`.`id` DESC
                LIMIT 5";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy thông tin sản phẩm theo slug kèm danh sách ảnh album.
     *
     * @param string $slug Slug duy nhất của sản phẩm
     * @return array|null Dữ liệu sản phẩm và images, hoặc null nếu không tìm thấy
     */
    public function getBySlug($slug)
    {
        $sql = "SELECT `products`.*, `categories`.`name` AS `category_name`
                FROM `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                WHERE `products`.`slug` = :slug
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product === false) {
            return null;
        }

        $imagesSql = "SELECT `id`, `image_path`, `sort_order`
                      FROM `product_images`
                      WHERE `product_id` = :product_id
                      ORDER BY `sort_order` ASC, `id` ASC";
        $imagesStmt = $this->pdo->prepare($imagesSql);
        $imagesStmt->bindValue(':product_id', (int)$product['id'], PDO::PARAM_INT);
        $imagesStmt->execute();
        $product['images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        return $product;
    }

    /**
     * Tăng lượt xem cho sản phẩm và trả về số mới để response phản ánh cập nhật.
     *
     * @param int $productId ID sản phẩm.
     * @return int|null Lượt xem mới, hoặc null nếu sản phẩm không tồn tại.
     */
    public function incrementViewCount(int $productId = 0): ?int
    {
        if ($productId < 1) {
            return null;
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE `products` SET `view_count` = COALESCE(`view_count`, 0) + 1 WHERE `id` = :id'
        );
        $updateStatement->bindValue(':id', $productId, PDO::PARAM_INT);
        $updateStatement->execute();
        if ($updateStatement->rowCount() !== 1) {
            return null;
        }

        $countStatement = $this->pdo->prepare(
            'SELECT `view_count` FROM `products` WHERE `id` = :id LIMIT 1'
        );
        $countStatement->bindValue(':id', $productId, PDO::PARAM_INT);
        $countStatement->execute();

        return (int) $countStatement->fetchColumn();
    }

    /**
     * Tạo sản phẩm từ dữ liệu đã được controller kiểm tra.
     *
     * @param array $data Các cột sản phẩm hợp lệ.
     * @return int ID sản phẩm mới.
     */
    public function createAdmin(array $data = []): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `price`, `sale_price`, `image`, `description`, `stock`) '
            . 'VALUES (:category_id, :code, :name, :slug, :price, :sale_price, :image, :description, :stock)'
        );
        $statement->bindValue(':category_id', $data['category_id'], PDO::PARAM_INT);
        $statement->bindValue(':code', $data['code'], PDO::PARAM_STR);
        $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);
        $statement->bindValue(':slug', $data['slug'], PDO::PARAM_STR);
        $statement->bindValue(':price', $data['price'], PDO::PARAM_STR);
        $statement->bindValue(':sale_price', $data['sale_price'], $data['sale_price'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':image', $data['image'], $data['image'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':description', $data['description'], $data['description'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':stock', $data['stock'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Kiểm tra danh mục có tồn tại trước khi gán cho sản phẩm.
     *
     * @param int $categoryId ID danh mục.
     * @return bool True nếu tìm thấy danh mục.
     */
    public function categoryExists(int $categoryId = 0): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM `categories` WHERE `id` = :id LIMIT 1');
        $statement->bindValue(':id', $categoryId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /**
     * Cập nhật các trường sản phẩm được controller cho phép.
     *
     * @param int $productId ID sản phẩm cần cập nhật.
     * @param array $data Các trường thay đổi.
     * @return bool True nếu sản phẩm tồn tại và cập nhật thành công.
     */
    public function updateAdmin(int $productId = 0, array $data = []): bool
    {
        $columns = [
            'category_id' => PDO::PARAM_INT,
            'code' => PDO::PARAM_STR,
            'name' => PDO::PARAM_STR,
            'slug' => PDO::PARAM_STR,
            'price' => PDO::PARAM_STR,
            'sale_price' => PDO::PARAM_STR,
            'image' => PDO::PARAM_STR,
            'description' => PDO::PARAM_STR,
            'stock' => PDO::PARAM_INT,
        ];
        $sets = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $sets[] = "`{$column}` = :{$column}";
            }
        }
        if ($sets === []) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE `products` SET ' . implode(', ', $sets) . ' WHERE `id` = :id'
        );
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $type = $value === null ? PDO::PARAM_NULL : $columns[$column];
                $statement->bindValue(':' . $column, $value, $type);
            }
        }
        $statement->bindValue(':id', $productId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0 || $this->find($productId) !== false;
    }

    /**
     * Xóa sản phẩm và trả về tên ảnh đại diện/ảnh album để controller dọn file.
     *
     * @param int $productId ID sản phẩm cần xóa.
     * @return array|null Danh sách tên ảnh, hoặc null nếu không tìm thấy.
     */
    public function deleteAdmin(int $productId = 0): ?array
    {
        $product = $this->find($productId);
        if ($product === false) {
            return null;
        }

        $imageStatement = $this->pdo->prepare(
            'SELECT `image_path` FROM `product_images` WHERE `product_id` = :product_id'
        );
        $imageStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $imageStatement->execute();
        $images = array_column($imageStatement->fetchAll(PDO::FETCH_ASSOC), 'image_path');
        if (!empty($product['image'])) {
            $images[] = $product['image'];
        }

        $deleteStatement = $this->pdo->prepare('DELETE FROM `products` WHERE `id` = :id');
        $deleteStatement->bindValue(':id', $productId, PDO::PARAM_INT);
        $deleteStatement->execute();

        return $images;
    }

    /**
     * Thêm ảnh vào album sản phẩm nếu sản phẩm tồn tại.
     *
     * @param int $productId ID sản phẩm.
     * @param string $imagePath Tên ảnh đã lưu trong uploads.
     * @param int $sortOrder Vị trí hiển thị trong album.
     * @return int|null ID ảnh mới, hoặc null nếu không có sản phẩm.
     */
    public function addAdminImage(int $productId = 0, string $imagePath = '', int $sortOrder = 0): ?int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `product_images` (`product_id`, `image_path`, `sort_order`) '
            . 'SELECT `id`, :image_path, :sort_order FROM `products` WHERE `id` = :product_id'
        );
        $statement->bindValue(':image_path', $imagePath, PDO::PARAM_STR);
        $statement->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1 ? (int) $this->pdo->lastInsertId() : null;
    }

    /**
     * Xóa một ảnh album và trả tên file để controller xóa file vật lý.
     *
     * @param int $imageId ID ảnh album.
     * @return string|null Tên file ảnh, hoặc null nếu không tìm thấy.
     */
    public function deleteAdminImage(int $imageId = 0): ?string
    {
        $findStatement = $this->pdo->prepare(
            'SELECT `image_path` FROM `product_images` WHERE `id` = :id LIMIT 1'
        );
        $findStatement->bindValue(':id', $imageId, PDO::PARAM_INT);
        $findStatement->execute();
        $imagePath = $findStatement->fetchColumn();
        if ($imagePath === false) {
            return null;
        }

        $deleteStatement = $this->pdo->prepare('DELETE FROM `product_images` WHERE `id` = :id');
        $deleteStatement->bindValue(':id', $imageId, PDO::PARAM_INT);
        $deleteStatement->execute();

        return (string) $imagePath;
    }
}
