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
     * Có thể giới hạn trong một danh mục và bỏ một sản phẩm, dùng cho khối "sản phẩm cùng danh mục bán chạy"
     * ở trang chi tiết (bỏ chính sản phẩm đang xem).
     *
     * @param string   $sortBy     Tiêu chí xếp hạng: views hoặc sales
     * @param int|null $categoryId Chỉ lấy sản phẩm thuộc danh mục này (null = toàn shop)
     * @param int|null $excludeId  Bỏ sản phẩm có ID này khỏi kết quả (null = không bỏ)
     * @return array Danh sách tối đa 5 sản phẩm
     */
    public function getTop($sortBy = 'sales', $categoryId = null, $excludeId = null)
    {
        // Chỉ cho phép hai cột đã quy định để không đưa tên cột từ request vào SQL.
        $sortColumns = [
            'views' => '`products`.`view_count`',
            'sales' => '`products`.`sales_count`',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['sales'];

        // Điều kiện động: chỉ thêm khi có tham số. Giá trị đi qua bindValue (PARAM_INT),
        // không nối thẳng vào SQL, để chống SQL injection.
        $conditions = [];
        $params = [];
        if ($categoryId !== null) {
            $conditions[] = '`products`.`category_id` = :category_id';
            $params['category_id'] = (int) $categoryId;
        }
        if ($excludeId !== null) {
            $conditions[] = '`products`.`id` <> :exclude_id';
            $params['exclude_id'] = (int) $excludeId;
        }
        $whereSql = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';

        // Dùng cùng nguồn và cùng cột với danh sách sản phẩm (view + biến thể mặc định) để FE nhận đủ giá và tồn kho.
        $sql = "SELECT `products`.*, `products`.`total_stock` AS `stock`, `dv`.`price`, `dv`.`sale_price`, `categories`.`name` AS `category_name`
                FROM `v_product_catalog` AS `products`
                LEFT JOIN `categories` ON `products`.`category_id` = `categories`.`id`
                LEFT JOIN `product_variants` AS `dv` ON `dv`.`product_id` = `products`.`id` AND `dv`.`is_default` = 1 AND `dv`.`is_active` = 1
                {$whereSql}
                ORDER BY {$sortColumn} DESC, `products`.`id` DESC
                LIMIT 5";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
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
     * Kiểm tra một file ảnh trong uploads còn được dùng ở đâu không, trước khi xóa file khỏi ổ đĩa.
     *
     * Vì sao cần: nhiều bản ghi có thể dùng CHUNG một file (ví dụ dữ liệu mẫu: default.jpg dùng cho 4 sản phẩm
     * và cả album). Xóa file khi bản ghi khác vẫn trỏ tới thì các sản phẩm đó mất ảnh.
     * Kiểm tra cả 3 nơi lưu tên file ảnh: products.image, product_images.image_path, banners.image.
     * Gọi SAU khi đã xóa/sửa bản ghi hiện tại, để chỉ còn đếm các bản ghi khác.
     *
     * @param string $imagePath Tên file ảnh (như lưu trong DB)
     * @return bool true nếu vẫn còn bản ghi dùng file này (KHÔNG được xóa file)
     */
    public function isImageInUse(string $imagePath = ''): bool
    {
        if ($imagePath === '') {
            return false;
        }

        // UNION ALL: gộp kết quả 3 câu SELECT; chỉ cần biết có ít nhất 1 dòng hay không (LIMIT 1).
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `products` WHERE `image` = :p1 '
            . 'UNION ALL SELECT 1 FROM `product_images` WHERE `image_path` = :p2 '
            . 'UNION ALL SELECT 1 FROM `banners` WHERE `image` = :p3 '
            . 'LIMIT 1'
        );
        $statement->bindValue(':p1', $imagePath, PDO::PARAM_STR);
        $statement->bindValue(':p2', $imagePath, PDO::PARAM_STR);
        $statement->bindValue(':p3', $imagePath, PDO::PARAM_STR);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /**
     * Lấy chi tiết sản phẩm cho trang quản trị (form sửa), tìm theo ID.
     *
     * Khác getBySlug (trang khách xem):
     * - Không tăng lượt xem (admin mở form sửa không làm sai thống kê).
     * - Trả MỌI biến thể, kể cả biến thể đã tắt bán (is_active = 0), kèm is_active.
     * - Album ảnh trả riêng (bảng product_images), không trộn ảnh đại diện vào, để admin quản lý từng phần.
     *
     * @param int $productId ID sản phẩm
     * @return array|null Sản phẩm kèm variants, images; null nếu không tồn tại
     */
    public function getAdminDetail(int $productId = 0): ?array
    {
        if ($productId < 1) {
            return null;
        }

        // LEFT JOIN categories: sản phẩm vẫn trả về dù danh mục bị xóa (category_name = null).
        $statement = $this->pdo->prepare(
            'SELECT `products`.*, `categories`.`name` AS `category_name` '
            . 'FROM `products` '
            . 'LEFT JOIN `categories` ON `categories`.`id` = `products`.`category_id` '
            . 'WHERE `products`.`id` = :id LIMIT 1'
        );
        $statement->bindValue(':id', $productId, PDO::PARAM_INT);
        $statement->execute();
        $product = $statement->fetch(PDO::FETCH_ASSOC);
        if ($product === false) {
            return null;
        }

        // Biến thể mặc định đứng đầu, sau đó theo thứ tự tạo.
        // order_count: số đơn hàng có chứa biến thể (truy vấn con), để FE khóa nút Xóa trước khi admin bấm.
        $variantStatement = $this->pdo->prepare(
            'SELECT `v`.`id`, `v`.`sku`, `v`.`variant_name`, `v`.`attributes`, `v`.`price`, `v`.`sale_price`, `v`.`final_price`, '
            . '`v`.`stock`, `v`.`is_default`, `v`.`is_active`, '
            . '(SELECT COUNT(DISTINCT `od`.`order_id`) FROM `order_details` AS `od` WHERE `od`.`variant_id` = `v`.`id`) AS `order_count` '
            . 'FROM `product_variants` AS `v` WHERE `v`.`product_id` = :product_id '
            . 'ORDER BY `v`.`is_default` DESC, `v`.`id` ASC'
        );
        $variantStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $variantStatement->execute();
        $product['variants'] = $variantStatement->fetchAll(PDO::FETCH_ASSOC);

        $imageStatement = $this->pdo->prepare(
            'SELECT `id`, `image_path`, `sort_order` FROM `product_images` '
            . 'WHERE `product_id` = :product_id ORDER BY `sort_order` ASC, `id` ASC'
        );
        $imageStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $imageStatement->execute();
        $product['images'] = $imageStatement->fetchAll(PDO::FETCH_ASSOC);
        $product['order_count'] = $this->countOrdersForProduct($productId);

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
            // Biến thể mới có sẵn 5 thuộc tính mặc định với value = null (cột chỉ nhận mảng JSON).
            $variantStatement->bindValue(':attributes', VariantAttributes::defaultsJson(), PDO::PARAM_STR);
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
     * @param int|null $expectedStock Số kho admin thấy lúc mở form; bắt buộc khi $data có 'stock' (controller kiểm tra).
     * @return bool True nếu sản phẩm tồn tại và có trường hợp lệ để cập nhật.
     * @throws BusinessConflictException Khi tồn kho đã bị thay đổi so với $expectedStock.
     */
    public function updateAdmin(int $productId = 0, array $data = [], ?int $expectedStock = null): bool
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
            // Có sửa tồn kho (của biến thể mặc định): khóa dòng và so với số kho admin thấy lúc mở form.
            if (array_key_exists('stock', $data) && $expectedStock !== null) {
                $this->assertStockUnchanged('`product_id` = :product_id AND `is_default` = 1', ['product_id' => $productId], $expectedStock);
            }

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
        // Transaction + khóa dòng: khóa mọi biến thể rồi đến sản phẩm (FOR UPDATE), sau đó mới đếm đơn hàng.
        // Checkout cũng khóa các dòng biến thể này, nên không thể có đơn mới chen vào giữa lúc "kiểm tra" và "xóa".
        // THỨ TỰ KHÓA quan trọng: checkout khóa biến thể TRƯỚC rồi mới đụng tới products (khi ghi order_details).
        // Ở đây cũng khóa biến thể trước; nếu khóa products trước sẽ ngược thứ tự và có thể deadlock (InnoDB hủy 1 bên).
        $this->pdo->beginTransaction();
        try {
            $lockVariants = $this->pdo->prepare('SELECT `id` FROM `product_variants` WHERE `product_id` = :product_id FOR UPDATE');
            $lockVariants->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $lockVariants->execute();
            $productStatement = $this->pdo->prepare('SELECT * FROM `products` WHERE `id` = :id FOR UPDATE');
            $productStatement->bindValue(':id', $productId, PDO::PARAM_INT);
            $productStatement->execute();
            $product = $productStatement->fetch(PDO::FETCH_ASSOC);
            if ($product === false) {
                $this->pdo->rollBack();
                return null;
            }

            // KHÓA NGHIỆP VỤ: sản phẩm đã có trong đơn hàng thì không xóa hẳn (giữ lịch sử, hoàn kho khi hủy đơn...).
            $orderCount = $this->countOrdersForProduct($productId);
            if ($orderCount > 0) {
                throw new BusinessConflictException(
                    "Sản phẩm đã có trong {$orderCount} đơn hàng nên không xóa được. Hãy tắt bán các biến thể thay vì xóa.",
                    ['order_count' => $orderCount]
                );
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
            $this->pdo->commit();

            return $images;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Đếm số đơn hàng (không trùng) có chứa sản phẩm này, theo product_id hoặc theo bất kỳ biến thể nào của nó.
     * Tính cả đơn đã hủy: đơn hủy vẫn là lịch sử cần giữ liên kết.
     *
     * @param int $productId ID sản phẩm
     * @return int Số đơn hàng
     */
    public function countOrdersForProduct(int $productId = 0): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT `od`.`order_id`) FROM `order_details` AS `od` '
            . 'WHERE `od`.`product_id` = :product_id '
            . 'OR `od`.`variant_id` IN (SELECT `id` FROM `product_variants` WHERE `product_id` = :product_id_2)'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->bindValue(':product_id_2', $productId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Đếm số đơn hàng (không trùng) có chứa biến thể này (tính cả đơn đã hủy).
     *
     * @param int $variantId ID biến thể
     * @return int Số đơn hàng
     */
    public function countOrdersForVariant(int $variantId = 0): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT `order_id`) FROM `order_details` WHERE `variant_id` = :variant_id'
        );
        $statement->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * KHÓA GHI ĐÈ TỒN KHO (khóa theo phiên bản): khóa dòng biến thể rồi so số kho hiện tại với số kho admin thấy
     * lúc mở form (expected). Khác nhau nghĩa là trong lúc admin sửa, đã có đơn hàng / hủy đơn làm kho đổi:
     * ghi đè lúc này sẽ xóa mất thay đổi đó (có thể bán quá số hàng thật), nên ném lỗi để admin tải lại.
     * PHẢI gọi bên trong transaction (FOR UPDATE giữ khóa tới khi commit/rollback).
     *
     * @param string $whereSql      Điều kiện chọn đúng 1 biến thể (cột cố định trong code, không lấy từ request)
     * @param array  $params        Tham số bind (tên => số nguyên)
     * @param int    $expectedStock Số kho admin thấy lúc mở form
     * @return void
     * @throws BusinessConflictException Khi kho đã thay đổi
     */
    private function assertStockUnchanged(string $whereSql = '', array $params = [], int $expectedStock = 0): void
    {
        $statement = $this->pdo->prepare("SELECT `stock` FROM `product_variants` WHERE {$whereSql} LIMIT 1 FOR UPDATE");
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $statement->execute();
        $current = $statement->fetchColumn();
        if ($current === false) {
            // Không còn dòng để so (biến thể vừa bị xóa): không được coi như "khớp" rồi lưu tiếp.
            throw new BusinessConflictException('Biến thể không còn tồn tại (có thể vừa bị xóa). Tải lại trang rồi thử lại.');
        }
        if ((int) $current !== $expectedStock) {
            throw new BusinessConflictException(
                'Tồn kho vừa thay đổi (có đơn hàng mới hoặc đơn bị hủy), hiện còn ' . (int) $current
                . '. Kiểm tra lại số kho rồi lưu.',
                ['current_stock' => (int) $current]
            );
        }
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

    /**
     * Đổi thứ tự hiển thị (sort_order) của một ảnh slide trong album.
     *
     * @param int $imageId   ID ảnh album
     * @param int $sortOrder Thứ tự mới (số nhỏ hiện trước)
     * @return bool true nếu tìm thấy ảnh (kể cả khi thứ tự không đổi)
     */
    public function updateAdminImageOrder(int $imageId = 0, int $sortOrder = 0): bool
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM `product_images` WHERE `id` = :id');
        $exists->bindValue(':id', $imageId, PDO::PARAM_INT);
        $exists->execute();
        if ($exists->fetchColumn() === false) {
            return false;
        }

        $statement = $this->pdo->prepare('UPDATE `product_images` SET `sort_order` = :sort_order WHERE `id` = :id');
        $statement->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
        $statement->bindValue(':id', $imageId, PDO::PARAM_INT);
        $statement->execute();

        return true;
    }

    // ===================== BIẾN THỂ (quản trị) =====================

    /**
     * Lấy một biến thể theo ID (dùng để kiểm tra trước khi sửa/xóa).
     *
     * @param int $variantId ID biến thể
     * @return array|null Dòng biến thể (product_id, price, sale_price, is_default, is_active...), null nếu không có
     */
    public function getVariantById(int $variantId = 0): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `sku`, `variant_name`, `price`, `sale_price`, `stock`, `is_default`, `is_active` '
            . 'FROM `product_variants` WHERE `id` = :id LIMIT 1'
        );
        $statement->bindValue(':id', $variantId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Tạo biến thể mới (không mặc định) cho một sản phẩm.
     * Trùng SKU (toàn hệ thống) hoặc trùng tên biến thể trong cùng sản phẩm: DB ném PDOException mã 1062.
     *
     * @param int   $productId ID sản phẩm cha
     * @param array $data      sku, variant_name, attributes (chuỗi JSON mảng), price, sale_price, stock, is_active
     * @return int ID biến thể vừa tạo
     */
    public function createVariant(int $productId = 0, array $data = []): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `product_variants` '
            . '(`product_id`, `sku`, `variant_name`, `attributes`, `price`, `sale_price`, `stock`, `is_default`, `is_active`) '
            . 'VALUES (:product_id, :sku, :variant_name, :attributes, :price, :sale_price, :stock, 0, :is_active)'
        );
        $statement->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $statement->bindValue(':sku', $data['sku'], PDO::PARAM_STR);
        $statement->bindValue(':variant_name', $data['variant_name'], PDO::PARAM_STR);
        $statement->bindValue(':attributes', $data['attributes'], PDO::PARAM_STR);
        $statement->bindValue(':price', $data['price'], PDO::PARAM_STR);
        $statement->bindValue(':sale_price', $data['sale_price'], $data['sale_price'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':stock', $data['stock'], PDO::PARAM_INT);
        $statement->bindValue(':is_active', $data['is_active'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Cập nhật một phần biến thể. Chỉ các khóa có trong $data được ghi (danh sách cột cố định, không lấy từ request).
     * Nếu $makeDefault = true: trong CÙNG transaction, bỏ mặc định của biến thể cũ rồi đặt biến thể này làm mặc định
     * (và bật bán, vì DB không cho biến thể mặc định ở trạng thái ngừng bán).
     *
     * Vì sao phải bỏ mặc định cũ TRƯỚC: DB có ràng buộc UNIQUE "mỗi sản phẩm tối đa 1 biến thể mặc định",
     * đặt mặc định mới trước sẽ bị từ chối. Transaction đảm bảo không có lúc sản phẩm mất biến thể mặc định.
     *
     * @param int   $variantId   ID biến thể
     * @param int   $productId   ID sản phẩm cha (để bỏ mặc định của biến thể khác cùng sản phẩm)
     * @param array $data        Các cột cần sửa
     * @param bool  $makeDefault true nếu đặt biến thể này làm mặc định
     * @param int|null $expectedStock Số kho admin thấy lúc mở form; bắt buộc khi $data có 'stock' (controller kiểm tra)
     * @return void
     * @throws BusinessConflictException Khi tồn kho đã bị thay đổi so với $expectedStock
     */
    public function updateVariant(int $variantId = 0, int $productId = 0, array $data = [], bool $makeDefault = false, ?int $expectedStock = null): void
    {
        $columnTypes = [
            'sku' => PDO::PARAM_STR,
            'variant_name' => PDO::PARAM_STR,
            'attributes' => PDO::PARAM_STR,
            'price' => PDO::PARAM_STR,
            'sale_price' => PDO::PARAM_STR,
            'stock' => PDO::PARAM_INT,
            'is_active' => PDO::PARAM_INT,
        ];
        if ($makeDefault) {
            $data['is_active'] = 1;
        }

        $this->pdo->beginTransaction();
        try {
            // Khóa dòng biến thể đích TRƯỚC mọi thao tác và chắc chắn nó vẫn còn: nếu vừa bị xóa ở nơi khác mà vẫn
            // chạy tiếp bước "bỏ mặc định của biến thể khác", sản phẩm sẽ không còn biến thể mặc định nào.
            $lock = $this->pdo->prepare('SELECT `id` FROM `product_variants` WHERE `id` = :id AND `product_id` = :product_id FOR UPDATE');
            $lock->bindValue(':id', $variantId, PDO::PARAM_INT);
            $lock->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $lock->execute();
            if ($lock->fetchColumn() === false) {
                throw new BusinessConflictException('Biến thể không còn tồn tại (có thể vừa bị xóa). Tải lại trang rồi thử lại.');
            }

            // Có sửa tồn kho: so với số kho admin thấy lúc mở form (xem assertStockUnchanged).
            if (array_key_exists('stock', $data) && $expectedStock !== null) {
                $this->assertStockUnchanged('`id` = :id', ['id' => $variantId], $expectedStock);
            }

            if ($makeDefault) {
                $clear = $this->pdo->prepare(
                    'UPDATE `product_variants` SET `is_default` = 0 WHERE `product_id` = :product_id AND `id` <> :id'
                );
                $clear->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $clear->bindValue(':id', $variantId, PDO::PARAM_INT);
                $clear->execute();
            }

            $sets = [];
            foreach ($columnTypes as $column => $type) {
                if (array_key_exists($column, $data)) {
                    $sets[] = "`{$column}` = :{$column}";
                }
            }
            if ($makeDefault) {
                $sets[] = '`is_default` = 1';
            }
            if ($sets !== []) {
                $statement = $this->pdo->prepare(
                    'UPDATE `product_variants` SET ' . implode(', ', $sets) . ' WHERE `id` = :id'
                );
                foreach ($columnTypes as $column => $type) {
                    if (array_key_exists($column, $data)) {
                        $value = $data[$column];
                        $statement->bindValue(":{$column}", $value, $value === null ? PDO::PARAM_NULL : $type);
                    }
                }
                $statement->bindValue(':id', $variantId, PDO::PARAM_INT);
                $statement->execute();
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Xóa hẳn một biến thể CHƯA TỪNG lên đơn. Giỏ hàng đang chứa biến thể này bị xóa theo (khóa ngoại CASCADE).
     * Controller chặn xóa biến thể mặc định trước khi gọi.
     *
     * KHÓA NGHIỆP VỤ: biến thể đã có trong đơn hàng (kể cả đơn đã hủy) thì không xóa, vì đơn đang chờ cần
     * biến thể để hoàn kho khi hủy, và lịch sử cần liên kết; admin dùng "Tắt bán" thay vì xóa.
     * Khóa dòng (FOR UPDATE) trong transaction để không có đơn mới chen vào giữa lúc kiểm tra và xóa.
     *
     * @param int $variantId ID biến thể
     * @return bool true nếu đã xóa, false nếu không tìm thấy (hoặc là biến thể mặc định)
     * @throws BusinessConflictException Khi biến thể đã có trong đơn hàng
     */
    public function deleteVariant(int $variantId = 0): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT `id` FROM `product_variants` WHERE `id` = :id AND `is_default` = 0 FOR UPDATE');
            $lock->bindValue(':id', $variantId, PDO::PARAM_INT);
            $lock->execute();
            if ($lock->fetchColumn() === false) {
                $this->pdo->rollBack();
                return false;
            }

            $orderCount = $this->countOrdersForVariant($variantId);
            if ($orderCount > 0) {
                throw new BusinessConflictException(
                    "Biến thể đã có trong {$orderCount} đơn hàng nên không xóa được. Hãy dùng \"Tắt bán\" để ngừng bán.",
                    ['order_count' => $orderCount]
                );
            }

            $statement = $this->pdo->prepare('DELETE FROM `product_variants` WHERE `id` = :id');
            $statement->bindValue(':id', $variantId, PDO::PARAM_INT);
            $statement->execute();
            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
