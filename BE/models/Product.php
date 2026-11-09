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

        $sql = "SELECT `products`.*, `categories`.`name` AS `category_name`
                FROM `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
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
                     FROM `products`
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

        $sql = "SELECT `products`.*, `categories`.`name` AS `category_name`
                FROM `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
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
}
