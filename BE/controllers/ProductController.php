<?php
class ProductController{
    // Tìm kiếm vector: câu tìm kiếm tối đa 200 ký tự (model chỉ đọc khoảng 256 token).
    const VECTOR_MAX_QUERY_LENGTH = 200;

    // Tìm kiếm vector không tự loại sản phẩm nào (sản phẩm nào cũng có điểm), nên phải cắt:
    // chỉ giữ sản phẩm có điểm >= 80% điểm cao nhất (ngưỡng tương đối), tối đa 20 sản phẩm.
    const VECTOR_RELATIVE_THRESHOLD = 0.8;
    const VECTOR_MAX_RESULTS = 20;

    private Product $product;
    public function __construct()
    {
        $this->product = new Product();
    }

    /**
     * Danh sách sản phẩm có lọc và 2 chế độ tìm kiếm. Không yêu cầu đăng nhập.
     *
     * Cờ search_mode CHỈ ảnh hưởng ô tìm kiếm. Danh mục và khoảng giá luôn lọc bằng SQL ở cả 2 chế độ.
     * - keyword (mặc định): bộ lọc + LIKE + sắp xếp + phân trang, tất cả bằng SQL (như trước đây).
     * - vector: bộ lọc chạy trước ra "vòng bo" -> so nghĩa câu tìm kiếm trong vòng bo -> cắt ngưỡng
     *   -> trả DANH SÁCH ĐỀ XUẤT theo thứ tự liên quan. Không phân trang, không sắp xếp lại (bỏ qua sort, page, per_page).
     *   search rỗng, công tắc VECTOR_SEARCH_ENABLED tắt hoặc server Python lỗi thì TỰ DÙNG keyword (không báo lỗi).
     *
     * Method: GET. Query (đều không bắt buộc; không gửi tham số mới thì kết quả y như trước):
     * - search (hoặc q), category, page, per_page (hoặc perPage): như cũ
     * - search_mode: keyword | vector (mặc định keyword)
     * - sort (chỉ keyword): newest | price_asc | price_desc | views | sales (mặc định newest)
     * - min_price, max_price: số >= 0 (VND), giữ sản phẩm có ít nhất 1 biến thể đang bán trong khoảng giá
     *
     * Thành công 200: { status, products: { data, pagination }, filters, search_mode_used, notice }
     *   search_mode_used: keyword | vector, chế độ thực tế đã dùng.
     *   notice: chuỗi khi muốn vector nhưng phải dùng keyword, ngược lại là null.
     *   Khi dùng vector: mỗi sản phẩm có thêm score và matched_variant (biến thể khớp nhất:
     *   variant_id, sku, variant_name, final_price, stock, attributes, embed_text = đoạn văn đã dùng tạo vector);
     *   pagination là 1 trang chứa toàn bộ danh sách;
     *   filters.page, filters.per_page, filters.sort là null (không áp dụng).
     * Lỗi 400: tham số sai.
     */
    public function index(){
        $search = $_GET['search'] ?? $_GET['q'] ?? '';
        if (!is_string($search)) {
            response_json(['status' => 'error', 'message' => 'Từ khóa tìm kiếm không hợp lệ'], 400);
        }
        $category = $_GET['category'] ?? '';
        if (!is_string($category) || strlen(trim($category)) > 255) {
            response_json([
                'status' => 'error',
                'message' => 'Slug danh mục không hợp lệ',
            ], 400);
        }
        $category = trim($category);
        $category = $category === '' ? null : $category;

        $searchMode = $_GET['search_mode'] ?? 'keyword';
        if (!in_array($searchMode, ['keyword', 'vector'], true)) {
            response_json(['status' => 'error', 'message' => 'search_mode phải là keyword hoặc vector'], 400);
        }
        if ($searchMode === 'vector' && mb_strlen(trim($search)) > self::VECTOR_MAX_QUERY_LENGTH) {
            response_json([
                'status' => 'error',
                'message' => 'Từ khóa tìm kiếm thông minh tối đa ' . self::VECTOR_MAX_QUERY_LENGTH . ' ký tự',
            ], 400);
        }

        $sort = $_GET['sort'] ?? 'newest';
        if (!is_string($sort) || !array_key_exists($sort, Product::CATALOG_SORT_SQL)) {
            response_json([
                'status' => 'error',
                'message' => 'sort phải là một trong: ' . implode(', ', array_keys(Product::CATALOG_SORT_SQL)),
            ], 400);
        }

        $minPrice = $this->readPriceParam('min_price');
        $maxPrice = $this->readPriceParam('max_price');
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            response_json(['status' => 'error', 'message' => 'min_price không được lớn hơn max_price'], 400);
        }

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho, để danh sách không hiện "hết hàng" oan.
        Order::releaseExpiredStock();
        $page = $_GET['page'] ?? 1;
        $perPage = $_GET['per_page'] ?? $_GET['perPage'] ?? 8;

        // ---- Chọn chế độ tìm kiếm thực tế ----
        $products = null;
        $modeUsed = 'keyword';
        $notice = null;
        if ($searchMode === 'vector' && trim($search) !== '') {
            if (!defined('VECTOR_SEARCH_ENABLED') || VECTOR_SEARCH_ENABLED !== true) {
                $notice = 'Tìm kiếm thông minh đang tắt, đã dùng tìm kiếm thường.';
            } else {
                $products = $this->vectorSearch(trim($search), $category, $minPrice, $maxPrice);
                if ($products === null) {
                    $notice = 'Tìm kiếm thông minh tạm thời không khả dụng, đã dùng tìm kiếm thường.';
                } else {
                    $modeUsed = 'vector';
                }
            }
        }

        // Tìm kiếm thường (hoặc vector không dùng được): SQL thuần, có sắp xếp và phân trang.
        if ($products === null) {
            $products = $this->product->getFiltered(
                $search,
                $category,
                $page,
                $perPage,
                $minPrice,
                $maxPrice,
                $sort
            );
        }
        $products['data'] = array_map([$this, 'formatProductListItem'], $products['data']);

        $isVector = $modeUsed === 'vector';
        response_json([
            'status' => 'success',
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $category,
                // Chế độ vector không phân trang, không sắp xếp lại nên 3 giá trị này không áp dụng.
                'page' => $isVector ? null : $page,
                'per_page' => $isVector ? null : $perPage,
                'search_mode' => $searchMode,
                'sort' => $isVector ? null : $sort,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
            ],
            'search_mode_used' => $modeUsed,
            'notice' => $notice,
        ]);
    }

    /**
     * Tìm kiếm vector: so nghĩa câu tìm kiếm TRONG vòng bo đã lọc bằng SQL, trả danh sách đề xuất.
     *
     * Các bước:
     * 1. SQL lấy toàn bộ vòng bo (danh mục + khoảng giá), không LIMIT.
     * 2. Tạo vector cho câu tìm kiếm, so cosine với từng biến thể; mỗi sản phẩm lấy điểm biến thể cao nhất.
     * 3. Cắt ngưỡng: giữ sản phẩm có điểm >= 80% điểm cao nhất, tối đa 20, xếp theo điểm giảm dần.
     * Sản phẩm chưa có vector (server Python tắt lúc admin lưu) sẽ không có điểm và không xuất hiện.
     *
     * @param string      $search   Câu tìm kiếm (khác rỗng)
     * @param string|null $category Slug danh mục
     * @param float|null  $minPrice Giá thấp nhất
     * @param float|null  $maxPrice Giá cao nhất
     * @return array|null ['data', 'pagination' (1 trang chứa toàn bộ)]; null nếu server Python lỗi (để lùi về keyword)
     */
    private function vectorSearch($search = '', $category = null, $minPrice = null, $maxPrice = null)
    {
        $candidates = $this->product->getCatalogCandidates($category, $minPrice, $maxPrice);

        $scoreByProduct = [];
        // Biến thể điểm cao nhất của mỗi sản phẩm, trả kèm để biết biến thể nào khớp và vì sao (embed_text).
        $bestVariantByProduct = [];
        if ($candidates !== []) {
            try {
                $queryVector = (new EmbedService())->embed([$search])[0];
            } catch (Throwable $e) {
                // Trả null để index() lùi về tìm kiếm thường, không để lỗi Python làm hỏng trang sản phẩm.
                return null;
            }

            // Điểm của mỗi sản phẩm = điểm cao nhất trong các biến thể của nó.
            $variants = (new ProductVariantEmbedding())->getVariantVectors(array_column($candidates, 'id'), $minPrice, $maxPrice);
            foreach ($variants as $variant) {
                $score = EmbedService::cosineSimilarity($queryVector, $variant['vector']);
                $productId = $variant['product_id'];
                if (!isset($scoreByProduct[$productId]) || $score > $scoreByProduct[$productId]) {
                    $scoreByProduct[$productId] = $score;
                    // Bỏ vector (768 số) khỏi dữ liệu trả về, chỉ giữ thông tin cần hiển thị.
                    unset($variant['vector'], $variant['product_id']);
                    $bestVariantByProduct[$productId] = $variant;
                }
            }
        }

        // Cắt ngưỡng tương đối. Điểm cao nhất <= 0 nghĩa là không có gì liên quan.
        $results = [];
        $topScore = $scoreByProduct !== [] ? max($scoreByProduct) : 0.0;
        if ($topScore > 0) {
            $threshold = $topScore * self::VECTOR_RELATIVE_THRESHOLD;
            foreach ($candidates as $product) {
                $score = $scoreByProduct[(int) $product['id']] ?? null;
                if ($score !== null && $score >= $threshold) {
                    $product['score'] = round($score, 4);
                    $product['matched_variant'] = $bestVariantByProduct[(int) $product['id']];
                    $results[] = $product;
                }
            }
            // usort: hàm so sánh trả số dương nghĩa là $b đứng trước $a, nên điểm cao lên đầu.
            usort($results, static fn($a, $b) => $b['score'] <=> $a['score']);
            $results = array_slice($results, 0, self::VECTOR_MAX_RESULTS);
        }

        // Không phân trang: trả cả danh sách đề xuất. Vẫn giữ khóa pagination (1 trang chứa toàn bộ)
        // để FE đang đọc products.pagination không bị lỗi.
        $totalItems = count($results);

        return [
            'data' => $results,
            'pagination' => [
                'current_page' => 1,
                'per_page' => $totalItems,
                'total_items' => $totalItems,
                'total_pages' => 1,
            ],
        ];
    }

    /**
     * Đọc tham số giá từ query. Không truyền hoặc rỗng = null (không lọc), sai định dạng hoặc âm thì trả 400.
     *
     * @param string $name Tên tham số (min_price hoặc max_price)
     * @return float|null Giá trị hợp lệ hoặc null
     */
    private function readPriceParam($name = '')
    {
        if (!isset($_GET[$name]) || $_GET[$name] === '') {
            return null;
        }

        // FILTER_VALIDATE_FLOAT chấp nhận số nguyên hoặc thập phân, sai định dạng trả false.
        $value = filter_var($_GET[$name], FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < 0) {
            response_json(['status' => 'error', 'message' => $name . ' phải là số không âm'], 400);
        }

        return $value;
    }

    /**
     * Đồng bộ vector tìm kiếm của một sản phẩm sau khi admin tạo/sửa thành công.
     *
     * Không bao giờ làm hỏng việc lưu sản phẩm: công tắc VECTOR_SEARCH_ENABLED tắt thì bỏ qua;
     * server Python lỗi thì nuốt lỗi, sản phẩm tạm chưa có trong tìm kiếm thông minh
     * cho tới khi chạy lại BE/database/embed_products.php.
     * Sửa giá/tồn kho không đổi đoạn văn nên không gọi server Python (xem ProductEmbedSync).
     *
     * @param int $productId ID sản phẩm vừa lưu
     * @return void
     */
    private function syncSearchEmbedding($productId = 0)
    {
        if ((int) $productId <= 0 || !defined('VECTOR_SEARCH_ENABLED') || VECTOR_SEARCH_ENABLED !== true) {
            return;
        }

        try {
            ProductEmbedSync::sync([(int) $productId]);
        } catch (Throwable $e) {
            // Cố ý bỏ qua: tìm kiếm thông minh là phần phụ, không chặn admin lưu sản phẩm.
        }
    }

    private function formatProductListItem(array $product): array
    {
        $product['stock'] = (int)($product['stock'] ?? 0);
        unset($product['total_stock']);
        $product['variant_count'] = (int)$product['variant_count'];
        $product['in_stock'] = (bool)$product['in_stock'];
        $product['min_final_price'] = $product['min_final_price'] === null ? null : (float)$product['min_final_price'];
        $product['max_final_price'] = $product['max_final_price'] === null ? null : (float)$product['max_final_price'];
        $product['price'] = $product['price'] === null ? null : (float)$product['price'];
        $product['sale_price'] = $product['sale_price'] === null ? null : (float)$product['sale_price'];
        return $product;
    }

    /**
     * Lấy tối đa 5 sản phẩm được xem nhiều nhất hoặc bán nhiều nhất.
     * Mặc định sắp xếp theo lượt bán; sort_by sai trả HTTP 400.
     */
    public function top()
    {
        $sortBy = $_GET['sort_by'] ?? 'sales';
        if (!is_string($sortBy) || !in_array($sortBy, ['views', 'sales'], true)) {
            response_json([
                'status' => 'error',
                'message' => 'sort_by phải là views hoặc sales',
            ], 400);
        }

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho (xem Order::releaseExpiredStock).
        Order::releaseExpiredStock();
        $products = $this->product->getTop($sortBy);
        $products = array_map([$this, 'formatProductListItem'], $products);

        response_json([
            'status' => 'success',
            'sort_by' => $sortBy,
            'products' => $products,
        ]);
    }

    /**
    * Trả về chi tiết sản phẩm theo slug, gồm ảnh và tăng view_count khi tìm thấy.
     * Thiếu slug trả HTTP 400; không có sản phẩm trả HTTP 404.
     */
    public function detail()
    {
        $slug = $_GET['slug'] ?? null;
        if (!is_string($slug) || trim($slug) === '' || strlen(trim($slug)) > 255) {
            response_json(['status' => 'error', 'message' => 'Slug sản phẩm không hợp lệ'], 400);
        }

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho, để trang chi tiết không hiện "hết hàng" oan.
        Order::releaseExpiredStock();
        $product = $this->product->getBySlug(trim($slug));
        if ($product === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        $viewCount = $this->product->incrementViewCount((int) $product['id']);
        if ($viewCount !== null) {
            $product['view_count'] = $viewCount;
        }

        response_json([
            'status' => 'success',
            'product' => $this->formatProductDetail($product),
        ]);
    }

    /**
     * Chuẩn hoá kiểu dữ liệu của chi tiết sản phẩm và từng biến thể trước khi trả cho FE.
     */
    private function formatProductDetail(array $product): array
    {
        $product = $this->formatProductListItem($product);
        $product['id'] = (int)$product['id'];
        $product['category_id'] = $product['category_id'] === null ? null : (int)$product['category_id'];
        $product['view_count'] = (int)$product['view_count'];
        $product['sales_count'] = (int)$product['sales_count'];

        $product['variants'] = array_map(function (array $variant): array {
            return [
                'id' => (int)$variant['id'],
                'sku' => $variant['sku'],
                'variant_name' => $variant['variant_name'],
                // Mảng chuẩn [{key, label, value}, ...], luôn có 5 khóa mặc định ở đầu (xem VariantAttributes).
                'attributes' => VariantAttributes::decode($variant['attributes']),
                'price' => (float)$variant['price'],
                'sale_price' => $variant['sale_price'] === null ? null : (float)$variant['sale_price'],
                'final_price' => (float)$variant['final_price'],
                'stock' => (int)$variant['stock'],
                'is_default' => (bool)$variant['is_default'],
            ];
        }, $product['variants']);

        $product['images'] = array_map(function (array $image): array {
            return [
                'id' => $image['id'] === null ? null : (int)$image['id'],
                'image_path' => $image['image_path'],
                'sort_order' => (int)$image['sort_order'],
            ];
        }, $product['images']);

        return $product;
    }

    //test mock data

    public function camChim(){
        $camChims = [
            [
                'id' => 1,
                'name' => 'Trần Hà Linh',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRWRUgFJDVfIftWG6y8iggQuPYJSg0kP8FpqSuxMKGu4OD708czJy5mX9gF&s=10'
            ],
            [
                'id' => 2,
                'name' => 'Linh Tây',
                'image' => 'https://benhvienthammysiamthailand.vn/wp-content/uploads/2024/04/1-copy-7-1.jpg'  
            ]
        ];
        response_json([
            'status' => 'success',
            'camChims' => $camChims
        ]);
    }

    /**
     * Tạo sản phẩm quản trị; nhận JSON hoặc multipart/form-data để tải ảnh đại diện.
     * Sai method/dữ liệu trả 405/400/422; mã hoặc slug trùng trả 409.
     *
     * @return void
     */
    public function adminCreate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = $this->getAdminInput();
        $data = $this->validateAdminProduct($input, true);
        if (!$this->product->categoryExists($data['category_id'])) {
            response_json(['status' => 'error', 'message' => 'Danh mục không hợp lệ'], 422);
        }
        $uploadedImage = $this->receiveAdminImage();
        $data['image'] = $uploadedImage;

        try {
            $productId = $this->product->createAdmin($data);
        } catch (PDOException $exception) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'Mã hoặc slug sản phẩm đã tồn tại'], 409);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            throw $exception;
        }

        // Tạo vector để sản phẩm mới xuất hiện trong tìm kiếm thông minh (lỗi thì bỏ qua, không chặn việc tạo).
        $this->syncSearchEmbedding($productId);

        response_json([
            'status' => 'success',
            'message' => 'Đã tạo sản phẩm',
            'product' => ['id' => $productId] + $data,
        ], 201);
    }

    /**
     * Cập nhật một phần sản phẩm; dùng PUT với JSON hoặc POST multipart khi đổi ảnh.
     * Sai dữ liệu trả 400/422; không tìm thấy sản phẩm trả 404; mã/slug trùng trả 409.
     *
     * @return void
     */
    public function adminUpdate(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['PUT', 'POST'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $productId = $this->getAdminId();
        $currentProduct = $this->product->find($productId);
        if ($currentProduct === false) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        $input = $this->getAdminInput();
        $data = $this->validateAdminProduct($input, false);
        // Giá nằm ở biến thể mặc định, không còn ở bảng products. Lấy giá hiện tại từ đó
        // để kiểm tra giá giảm < giá gốc khi request chỉ gửi một trong hai trường.
        $currentPricing = $this->product->getDefaultVariantPricing($productId) ?? ['price' => 0, 'sale_price' => null];
        $effectivePrice = (float) ($data['price'] ?? $currentPricing['price']);
        $effectiveSalePrice = array_key_exists('sale_price', $data)
            ? $data['sale_price']
            : $currentPricing['sale_price'];
        if ($effectiveSalePrice !== null && (float) $effectiveSalePrice >= $effectivePrice) {
            response_json(['status' => 'error', 'message' => 'Giá khuyến mãi phải thấp hơn giá gốc'], 422);
        }
        $uploadedImage = $this->receiveAdminImage();
        if ($uploadedImage !== null) {
            $data['image'] = $uploadedImage;
        }
        if ($data === []) {
            response_json(['status' => 'error', 'message' => 'Không có thông tin cần cập nhật'], 422);
        }

        try {
            $updated = $this->product->updateAdmin($productId, $data);
        } catch (PDOException $exception) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            if ($exception->getCode() === '23000') {
                response_json(['status' => 'error', 'message' => 'Mã hoặc slug sản phẩm đã tồn tại'], 409);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            throw $exception;
        }

        if (!$updated) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        // Tên/mô tả đổi thì tạo lại vector; chỉ đổi giá/tồn kho thì không gọi server Python.
        $this->syncSearchEmbedding($productId);

        response_json(['status' => 'success', 'message' => 'Đã cập nhật sản phẩm']);
    }

    /**
     * Xóa sản phẩm và các file ảnh đại diện/album đang thuộc sản phẩm đó.
     * ID sai trả 400; không tìm thấy sản phẩm trả 404.
     *
     * @return void
     */
    public function adminDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $images = $this->product->deleteAdmin($this->getAdminId());
        if ($images === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }
        foreach (array_unique($images) as $image) {
            if (is_string($image) && $image !== '' && basename($image) === $image) {
                delete_uploaded_file($image);
            }
        }

        response_json(['status' => 'success', 'message' => 'Đã xóa sản phẩm']);
    }

    /**
     * Tải một ảnh vào album sản phẩm.
     * Thiếu file/sai dữ liệu trả 400/422; sản phẩm không tồn tại trả 404.
     *
     * @return void
     */
    public function adminImageCreate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $sortOrderInput = $_POST['sort_order'] ?? '0';
        $sortOrder = is_string($sortOrderInput) && ctype_digit($sortOrderInput) ? (int) $sortOrderInput : -1;
        if ($productId === false || $productId < 1 || $sortOrder < 0 || $sortOrder > 10000) {
            response_json(['status' => 'error', 'message' => 'Thông tin ảnh sản phẩm không hợp lệ'], 422);
        }

        $image = $this->receiveAdminImage(true);
        try {
            $imageId = $this->product->addAdminImage($productId, $image, $sortOrder);
        } catch (Throwable $exception) {
            delete_uploaded_file($image);
            throw $exception;
        }
        if ($imageId === null) {
            delete_uploaded_file($image);
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã thêm ảnh sản phẩm',
            'image' => ['id' => $imageId, 'product_id' => $productId, 'image_path' => $image, 'sort_order' => $sortOrder],
        ], 201);
    }

    /**
     * Xóa ảnh khỏi album và xóa file tương ứng trong uploads.
     * ID sai trả 400; ảnh không tồn tại trả 404.
     *
     * @return void
     */
    public function adminImageDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $imageId = $this->getAdminId();
        $imagePath = $this->product->deleteAdminImage($imageId);
        if ($imagePath === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy ảnh sản phẩm'], 404);
        }
        if (basename($imagePath) === $imagePath) {
            delete_uploaded_file($imagePath);
        }

        response_json(['status' => 'success', 'message' => 'Đã xóa ảnh sản phẩm']);
    }

    /**
     * Đọc dữ liệu JSON hoặc form; multipart dùng POST để PHP nhận được $_FILES.
     *
     * @return array Dữ liệu request dạng mảng.
     */
    private function getAdminInput(): array
    {
        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && (!empty($_POST) || strpos($contentType, 'multipart/form-data') === 0)) {
            return $_POST;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu request không hợp lệ'], 400);
        }

        return $input;
    }

    /**
     * Kiểm tra các trường sản phẩm, giữ nguyên các trường không gửi khi cập nhật.
     *
     * @param array $input Dữ liệu request.
     * @param bool $isCreate True khi tạo mới và cần đủ trường bắt buộc.
     * @return array Dữ liệu đã chuẩn hóa để lưu.
     */
    private function validateAdminProduct(array $input = [], bool $isCreate = false): array
    {
        $data = [];
        $required = ['category_id', 'code', 'name', 'slug', 'price', 'stock'];
        foreach ($required as $field) {
            if ($isCreate && !array_key_exists($field, $input)) {
                response_json(['status' => 'error', 'message' => "Thiếu trường {$field}"], 422);
            }
        }

        if (array_key_exists('category_id', $input)) {
            $value = filter_var($input['category_id'], FILTER_VALIDATE_INT);
            if ($value === false || $value < 1 || !$this->product->categoryExists($value)) {
                response_json(['status' => 'error', 'message' => 'Danh mục không hợp lệ'], 422);
            }
            $data['category_id'] = $value;
        }
        foreach (['code' => 100, 'name' => 255, 'slug' => 255] as $field => $maxLength) {
            if (array_key_exists($field, $input)) {
                if (!is_string($input[$field]) || trim($input[$field]) === '' || strlen(trim($input[$field])) > $maxLength) {
                    response_json(['status' => 'error', 'message' => "Trường {$field} không hợp lệ"], 422);
                }
                $data[$field] = trim($input[$field]);
            }
        }
        if (array_key_exists('price', $input)) {
            if (!is_numeric($input['price']) || (float) $input['price'] < 0 || (float) $input['price'] > 99999999.99) {
                response_json(['status' => 'error', 'message' => 'Giá sản phẩm không hợp lệ'], 422);
            }
            $data['price'] = (string) $input['price'];
        }
        if (array_key_exists('sale_price', $input)) {
            $value = $input['sale_price'];
            if ($value === '' || $value === null) {
                $data['sale_price'] = null;
            } elseif (!is_numeric($value) || (float) $value < 0 || (float) $value > 99999999.99) {
                response_json(['status' => 'error', 'message' => 'Giá khuyến mãi không hợp lệ'], 422);
            } else {
                $data['sale_price'] = (string) $value;
            }
        } elseif ($isCreate) {
            $data['sale_price'] = null;
        }
        if (isset($data['sale_price'], $data['price']) && (float) $data['sale_price'] >= (float) $data['price']) {
            response_json(['status' => 'error', 'message' => 'Giá khuyến mãi phải thấp hơn giá gốc'], 422);
        }
        if (array_key_exists('description', $input)) {
            if (!is_string($input['description']) || strlen($input['description']) > 60000) {
                response_json(['status' => 'error', 'message' => 'Mô tả sản phẩm không hợp lệ'], 422);
            }
            $data['description'] = trim($input['description']) === '' ? null : trim($input['description']);
        } elseif ($isCreate) {
            $data['description'] = null;
        }
        if (array_key_exists('stock', $input)) {
            $stock = filter_var($input['stock'], FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0 || $stock > 2147483647) {
                response_json(['status' => 'error', 'message' => 'Tồn kho không hợp lệ'], 422);
            }
            $data['stock'] = $stock;
        }

        return $data;
    }

    /**
     * Kiểm tra và lưu ảnh upload bằng các helper dùng chung của backend.
     *
     * @param bool $required True nếu endpoint bắt buộc có ảnh.
     * @return string|null Tên file đã lưu, hoặc null nếu ảnh không bắt buộc và không gửi.
     */
    private function receiveAdminImage(bool $required = false): ?string
    {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($required) {
                response_json(['status' => 'error', 'message' => 'Vui lòng chọn ảnh'], 422);
            }
            return null;
        }

        try {
            validate_upload($file);
        } catch (Throwable $exception) {
            response_json(['status' => 'error', 'message' => $exception->getMessage()], 422);
            return null;
        }

        try {
            return save_uploaded_file($file);
        } catch (Throwable $exception) {
            response_json(['status' => 'error', 'message' => 'Không thể lưu ảnh trên máy chủ'], 500);
            return null;
        }
    }

    /**
     * Lấy ID dương từ query string dùng chung cho các endpoint xóa.
     *
     * @return int ID hợp lệ.
     */
    private function getAdminId(): int
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            response_json(['status' => 'error', 'message' => 'ID không hợp lệ'], 400);
        }

        return $id;
    }

}