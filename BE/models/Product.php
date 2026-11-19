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
     * Tìm sản phẩm theo từ khóa, dùng cho tool của trợ lý AI (Gemini).
     * Khác getFiltered: không đếm tổng, không phân trang, chỉ trả các cột AI cần để gọn token.
     *
     * Tìm trong tên, mã và mô tả sản phẩm. Ưu tiên sản phẩm còn hàng, rồi đến sản phẩm mới hơn.
     *
     * @param string $keyword Từ khóa khách hỏi (rỗng = không tìm, trả mảng rỗng)
     * @param int    $limit   Số sản phẩm tối đa trả về, được giới hạn trong khoảng 1-10
     * @return array Danh sách sản phẩm (id, name, slug, code, category_name, min_final_price, max_final_price, in_stock)
     */
    public function searchByKeyword($keyword = '', $limit = 5)
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '') {
            return [];
        }

        // Giới hạn số dòng để tránh AI yêu cầu quá nhiều dữ liệu cùng lúc.
        $limit = min(10, max(1, (int)$limit));

        // Ký tự % và _ trong LIKE có nghĩa đặc biệt ("bất kỳ chuỗi" và "một ký tự").
        // Thêm dấu \ phía trước để coi chúng là ký tự thường, tránh khách vô tình tìm rộng hơn ý muốn.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);

        // LEFT JOIN (không phải INNER JOIN) để sản phẩm vẫn được trả về khi chưa gán danh mục
        // hoặc danh mục đã bị xóa; khi đó category_name là NULL.
        // slug chưa dùng trong tool, nhưng sẽ cần để điều hướng tới trang chi tiết (đã chốt trong kế hoạch).
        $sql = "SELECT `products`.`id`, `products`.`name`, `products`.`slug`, `products`.`code`,
                       `categories`.`name` AS `category_name`,
                       `products`.`min_final_price`, `products`.`max_final_price`, `products`.`in_stock`,
                       `products`.`variant_count`
                FROM `v_product_catalog` AS `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                WHERE `products`.`name` LIKE :kw_name
                   OR `products`.`code` LIKE :kw_code
                   OR `products`.`description` LIKE :kw_desc
                ORDER BY `products`.`in_stock` DESC, `products`.`id` DESC
                LIMIT :limit";

        // Mỗi cột dùng một tên tham số riêng (:kw_name, :kw_code, :kw_desc) để không phụ thuộc
        // vào cách PDO xử lý tham số trùng tên.
        $likeValue = '%' . $escaped . '%';
        $stmt = $this->pdo->prepare($sql);
        // Bọc %...% ở đây để controller/tool không phải biết cú pháp LIKE.
        $stmt->bindValue(':kw_name', $likeValue, PDO::PARAM_STR);
        $stmt->bindValue(':kw_code', $likeValue, PDO::PARAM_STR);
        $stmt->bindValue(':kw_desc', $likeValue, PDO::PARAM_STR);
        // LIMIT không dùng được dấu ? hay tham số chuỗi, phải bind đúng kiểu số nguyên.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lấy các biến thể ĐANG BÁN của nhiều sản phẩm trong một truy vấn, nhóm theo sản phẩm.
     * Dùng cho tool AI search_product để AI biết từng phiên bản: tên, SKU, giá, tồn kho.
     *
     * Thứ tự trong mỗi sản phẩm: biến thể mặc định trước, rồi giá bán tăng dần.
     * Mỗi sản phẩm chỉ giữ tối đa $perProduct biến thể để kết quả gửi AI không quá dài.
     *
     * @param array $productIds ID các sản phẩm cần lấy biến thể (số nguyên dương)
     * @param int   $perProduct Số biến thể tối đa mỗi sản phẩm (1-10)
     * @return array Mảng [product_id => [các biến thể]], sản phẩm không có biến thể đang bán thì không có key
     */
    public function getActiveVariantsByProductIds($productIds = [], $perProduct = 5)
    {
        // Chỉ giữ ID là số nguyên dương, bỏ trùng. array_values đánh lại chỉ số 0, 1, 2...
        $ids = array_values(array_unique(array_filter(
            array_map('intval', is_array($productIds) ? $productIds : []),
            static fn($id) => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $perProduct = min(10, max(1, (int)$perProduct));

        // IN (...) với số phần tử thay đổi: tạo sẵn tên tham số :id0, :id1... rồi bind từng giá trị,
        // không nối thẳng ID vào SQL (vẫn dùng prepared statement để chống SQL injection).
        $placeholders = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
        }

        $sql = 'SELECT `product_id`, `sku`, `variant_name`, `price`, `sale_price`, `final_price`, `stock`, `is_default`
                FROM `product_variants`
                WHERE `is_active` = 1 AND `product_id` IN (' . implode(', ', $placeholders) . ')
                ORDER BY `product_id`, `is_default` DESC, `final_price` ASC, `id` ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($ids as $i => $id) {
            $stmt->bindValue(':id' . $i, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        // Nhóm theo product_id và cắt bớt ở PHP; giới hạn theo nhóm bằng SQL sẽ phức tạp hơn với người mới.
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int)$row['product_id'];
            if (count($grouped[$productId] ?? []) < $perProduct) {
                $grouped[$productId][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * Lấy danh mục và tên sản phẩm cửa hàng đang có, để đưa vào system prompt của trợ lý AI.
     * Chỉ tính sản phẩm còn ít nhất một phiên bản đang bán (variant_count > 0 trong v_product_catalog).
     * Không lấy giá hay tồn kho: những thông tin đó AI vẫn phải tra bằng tool search_product để có số mới nhất.
     *
     * Dùng bảng categories qua JOIN thay vì model Category, vì hiện file Category.php có đoạn chữ nằm trước
     * thẻ <?php, nạp vào sẽ in chữ đó ra đầu response JSON của API chat.
     *
     * @param int $maxPerCategory Số tên sản phẩm tối đa mỗi danh mục (1-50), tránh prompt quá dài
     * @return array Danh sách ['category' => tên danh mục, 'products' => [tên sản phẩm...]], theo thứ tự id danh mục
     */
    public function getCatalogOverview($maxPerCategory = 30)
    {
        $maxPerCategory = min(50, max(1, (int)$maxPerCategory));

        // LEFT JOIN: danh mục chưa có sản phẩm nào vẫn hiện (products rỗng), để AI biết danh mục đó tồn tại.
        $sql = 'SELECT `c`.`id` AS `category_id`, `c`.`name` AS `category_name`, `v`.`name` AS `product_name`
                FROM `categories` AS `c`
                LEFT JOIN `v_product_catalog` AS `v` ON `v`.`category_id` = `c`.`id` AND `v`.`variant_count` > 0
                ORDER BY `c`.`id` ASC, `v`.`name` ASC';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Gom theo danh mục và cắt bớt ở PHP cho dễ đọc với người mới.
        $groups = [];
        foreach ($rows as $row) {
            $id = (int)$row['category_id'];
            if (!isset($groups[$id])) {
                $groups[$id] = ['category' => $row['category_name'], 'products' => []];
            }
            if ($row['product_name'] !== null && count($groups[$id]['products']) < $maxPerCategory) {
                $groups[$id]['products'][] = $row['product_name'];
            }
        }

        return array_values($groups);
    }

    /**
     * Lấy các biến thể ĐANG BÁN theo danh sách SKU, kèm tên và ảnh của sản phẩm cha.
     * Dùng cho tool AI create_order_draft: AI chỉ biết SKU (lấy từ search_product), server tự tra giá và tồn kho.
     * Chỉ đọc để lên đơn nháp, không khóa dòng; lúc khách xác nhận, Order::checkoutItems sẽ khóa và kiểm tra lại.
     *
     * @param array $skus Danh sách SKU (chuỗi), tối đa 20 phần tử
     * @return array Mảng [sku VIẾT THƯỜNG => dòng biến thể], SKU không tồn tại hoặc ngừng bán thì không có key.
     *               Nơi gọi tra bằng mb_strtolower($sku, 'UTF-8'); giá trị 'sku' trong dòng vẫn là SKU gốc trong DB.
     */
    public function getActiveVariantsBySkus($skus = [])
    {
        // Chỉ giữ chuỗi không rỗng, bỏ trùng (không phân biệt hoa thường, vì cột sku cũng vậy),
        // giới hạn 20 SKU để câu truy vấn không quá dài.
        $unique = [];
        foreach (is_array($skus) ? $skus : [] as $sku) {
            if (is_string($sku) && trim($sku) !== '') {
                $unique[mb_strtolower(trim($sku), 'UTF-8')] = trim($sku);
            }
        }
        $skus = array_slice(array_values($unique), 0, 20);
        if ($skus === []) {
            return [];
        }

        // IN (...) với số phần tử thay đổi: tạo tên tham số :sku0, :sku1... rồi bind từng giá trị (chống SQL injection).
        $placeholders = [];
        foreach ($skus as $i => $sku) {
            $placeholders[] = ':sku' . $i;
        }

        $sql = 'SELECT `v`.`id`, `v`.`product_id`, `v`.`sku`, `v`.`variant_name`, `v`.`price`, `v`.`sale_price`,
                       `v`.`final_price`, `v`.`stock`, `p`.`name` AS `product_name`, `p`.`image`
                FROM `product_variants` AS `v`
                INNER JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id`
                WHERE `v`.`is_active` = 1 AND `v`.`sku` IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->pdo->prepare($sql);
        foreach ($skus as $i => $sku) {
            $stmt->bindValue(':sku' . $i, trim($sku), PDO::PARAM_STR);
        }
        $stmt->execute();

        // Key theo SKU viết thường: cột sku không phân biệt hoa thường, nên DB có thể trả "MOROCCANOIL"
        // khi khách gửi "moroccanoil". Nơi gọi chỉ cần mb_strtolower để tra, không phải tự đổi key nữa.
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[mb_strtolower((string) $row['sku'], 'UTF-8')] = $row;
        }

        return $result;
    }

    /**
     * Dựng một món của đơn nháp (phần chung) từ một dòng biến thể của getActiveVariantsBySkus.
     * Dùng chung cho tool AI lên đơn nháp (GeminiToolCreateOrderDraft) và API tra lại kho (api-order-draft-items),
     * để hai nơi luôn tính giá, giá gốc, tồn kho theo cùng một quy tắc.
     *
     * @param array $variant Dòng biến thể (sku, product_name, variant_name, image, price, sale_price, final_price, stock)
     * @return array ['sku', 'name', 'variant_name', 'image', 'price', 'original_price', 'stock']
     */
    public static function buildDraftItem($variant = [])
    {
        $variant = is_array($variant) ? $variant : [];
        return [
            'sku' => (string) ($variant['sku'] ?? ''),
            'name' => (string) ($variant['product_name'] ?? ''),
            'variant_name' => (string) ($variant['variant_name'] ?? ''),
            'image' => $variant['image'] ?? null,
            // final_price là giá bán thật (giá giảm nếu có, không thì giá gốc) do DB tính.
            'price' => (float) ($variant['final_price'] ?? 0),
            // Giá niêm yết chỉ gửi khi đang giảm giá, để FE gạch giá cũ.
            'original_price' => ($variant['sale_price'] ?? null) !== null ? (float) $variant['price'] : null,
            // Tồn kho kẹp tối đa số lượng cho phép mỗi món: FE dùng làm giới hạn khi khách tăng số lượng.
            'stock' => min(max(0, (int) ($variant['stock'] ?? 0)), Order::MAX_ITEM_QUANTITY),
        ];
    }

    /**
     * Lấy chi tiết sản phẩm theo slug: tổng hợp giá/tồn kho, danh sách biến thể đang bán và album ảnh.
     *
     * @param string $slug Slug duy nhất của sản phẩm
     * @return array|null Dữ liệu sản phẩm, hoặc null nếu không tìm thấy
     */
    public function getBySlug($slug)
    {
        // Lấy tổng hợp từ view và giá của biến thể mặc định, giống nguồn của danh sách sản phẩm.
        $sql = "SELECT `products`.*, `categories`.`name` AS `category_name`,
                       `catalog`.`variant_count`, `catalog`.`min_final_price`, `catalog`.`max_final_price`,
                       `catalog`.`total_stock` AS `stock`, `catalog`.`in_stock`,
                       `dv`.`price`, `dv`.`sale_price`
                FROM `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                LEFT JOIN `v_product_catalog` AS `catalog` ON `catalog`.`id` = `products`.`id`
                LEFT JOIN `product_variants` AS `dv` ON `dv`.`product_id` = `products`.`id` AND `dv`.`is_default` = 1 AND `dv`.`is_active` = 1
                WHERE `products`.`slug` = :slug
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product === false) {
            return null;
        }

        $variantsSql = "SELECT `id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `final_price`, `stock`, `is_default`
                        FROM `product_variants`
                        WHERE `product_id` = :product_id AND `is_active` = 1
                        ORDER BY `is_default` DESC, `id` ASC";
        $variantsStmt = $this->pdo->prepare($variantsSql);
        $variantsStmt->bindValue(':product_id', (int)$product['id'], PDO::PARAM_INT);
        $variantsStmt->execute();
        $product['variants'] = $variantsStmt->fetchAll(PDO::FETCH_ASSOC);

        $imagesSql = "SELECT `id`, `image_path`, `sort_order`
                      FROM `product_images`
                      WHERE `product_id` = :product_id
                      ORDER BY `sort_order` ASC, `id` ASC";
        $imagesStmt = $this->pdo->prepare($imagesSql);
        $imagesStmt->bindValue(':product_id', (int)$product['id'], PDO::PARAM_INT);
        $imagesStmt->execute();
        $slides = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Ảnh đại diện luôn đứng đầu album; bỏ qua nếu đã có trong danh sách slide để không trùng.
        $cover = trim((string)($product['image'] ?? ''));
        $images = [];
        if ($cover !== '') {
            $images[] = ['id' => null, 'image_path' => $cover, 'sort_order' => -1];
        }
        foreach ($slides as $slide) {
            if ($slide['image_path'] !== $cover) {
                $images[] = $slide;
            }
        }
        $product['images'] = $images;

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
     * Tạo sản phẩm kèm một biến thể mặc định trong cùng transaction.
     * Giá, giá giảm và tồn kho không nằm ở bảng products mà ở product_variants,
     * nên request admin vẫn gửi price/sale_price/stock và ta ghi chúng vào biến thể mặc định.
     * Nếu bước nào lỗi (ví dụ trùng mã/slug, lỗi 1062) thì hoàn tác cả hai bảng, không để lại sản phẩm không có biến thể.
     *
     * @param array $data Các cột sản phẩm hợp lệ (đã được controller kiểm tra).
     * @return int ID sản phẩm mới.
     */
    public function createAdmin(array $data = []): int
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO `products` (`category_id`, `code`, `name`, `slug`, `image`, `description`) '
                . 'VALUES (:category_id, :code, :name, :slug, :image, :description)'
            );
            $statement->bindValue(':category_id', $data['category_id'], PDO::PARAM_INT);
            $statement->bindValue(':code', $data['code'], PDO::PARAM_STR);
            $statement->bindValue(':name', $data['name'], PDO::PARAM_STR);
            $statement->bindValue(':slug', $data['slug'], PDO::PARAM_STR);
            $statement->bindValue(':image', $data['image'], $data['image'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue(':description', $data['description'], $data['description'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->execute();
            $productId = (int) $this->pdo->lastInsertId();

            // SKU của biến thể mặc định lấy bằng mã sản phẩm (cùng quy ước với dữ liệu đã backfill).
            // SKU phải duy nhất toàn hệ thống nên trùng SKU cũng trả lỗi 1062 như trùng mã.
            $variantStatement = $this->pdo->prepare(
                'INSERT INTO `product_variants` '
                . '(`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`) '
                . 'VALUES (:product_id, :sku, :variant_name, :attributes, :price, :sale_price, :stock, 1, 1)'
            );
            $variantStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $variantStatement->bindValue(':sku', $data['code'], PDO::PARAM_STR);
            $variantStatement->bindValue(':variant_name', 'Mặc định', PDO::PARAM_STR);
            $variantStatement->bindValue(':attributes', '{}', PDO::PARAM_STR);
            $variantStatement->bindValue(':price', $data['price'], PDO::PARAM_STR);
            $variantStatement->bindValue(':sale_price', $data['sale_price'], $data['sale_price'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $variantStatement->bindValue(':stock', $data['stock'], PDO::PARAM_INT);
            $variantStatement->execute();

            $this->pdo->commit();

            return $productId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Lấy giá và giá giảm hiện tại của biến thể mặc định để controller kiểm tra giá giảm < giá gốc khi sửa một phần.
     *
     * @param int $productId ID sản phẩm.
     * @return array|null ['price' => ..., 'sale_price' => ...] hoặc null nếu sản phẩm không có biến thể mặc định.
     */
    public function getDefaultVariantPricing(int $productId = 0): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT `price`, `sale_price` FROM `product_variants` '
            . 'WHERE `product_id` = :product_id AND `is_default` = 1 LIMIT 1'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
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
     * Thông tin chung (tên, mã, slug, danh mục, ảnh, mô tả) ghi vào bảng products;
     * price, sale_price, stock ghi vào biến thể mặc định của sản phẩm. Cả hai trong cùng transaction.
     * Tên cột lấy từ danh sách cố định bên dưới (allowlist) nên không có rủi ro SQL injection qua tên cột.
     *
     * @param int $productId ID sản phẩm cần cập nhật.
     * @param array $data Các trường thay đổi.
     * @return bool True nếu sản phẩm tồn tại và có trường hợp lệ để cập nhật.
     */
    public function updateAdmin(int $productId = 0, array $data = []): bool
    {
        $productColumns = [
            'category_id' => PDO::PARAM_INT,
            'code' => PDO::PARAM_STR,
            'name' => PDO::PARAM_STR,
            'slug' => PDO::PARAM_STR,
            'image' => PDO::PARAM_STR,
            'description' => PDO::PARAM_STR,
        ];
        $variantColumns = [
            'price' => PDO::PARAM_STR,
            'sale_price' => PDO::PARAM_STR,
            'stock' => PDO::PARAM_INT,
        ];

        $productSets = [];
        $variantSets = [];
        foreach ($data as $column => $value) {
            if (isset($productColumns[$column])) {
                $productSets[] = "`{$column}` = :{$column}";
            } elseif (isset($variantColumns[$column])) {
                $variantSets[] = "`{$column}` = :{$column}";
            }
        }
        if (($productSets === [] && $variantSets === []) || $this->find($productId) === false) {
            return false;
        }

        $this->pdo->beginTransaction();
        try {
            if ($productSets !== []) {
                $statement = $this->pdo->prepare(
                    'UPDATE `products` SET ' . implode(', ', $productSets) . ' WHERE `id` = :id'
                );
                foreach ($data as $column => $value) {
                    if (isset($productColumns[$column])) {
                        $type = $value === null ? PDO::PARAM_NULL : $productColumns[$column];
                        $statement->bindValue(':' . $column, $value, $type);
                    }
                }
                $statement->bindValue(':id', $productId, PDO::PARAM_INT);
                $statement->execute();
            }

            if ($variantSets !== []) {
                $statement = $this->pdo->prepare(
                    'UPDATE `product_variants` SET ' . implode(', ', $variantSets)
                    . ' WHERE `product_id` = :product_id AND `is_default` = 1'
                );
                foreach ($data as $column => $value) {
                    if (isset($variantColumns[$column])) {
                        $type = $value === null ? PDO::PARAM_NULL : $variantColumns[$column];
                        $statement->bindValue(':' . $column, $value, $type);
                    }
                }
                $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $statement->execute();
            }

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
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
