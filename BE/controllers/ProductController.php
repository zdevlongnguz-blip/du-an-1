<?php
class ProductController{
    private Product $product;
    public function __construct()
    {
        $this->product = new Product();
    }

    /**
     * Danh sách sản phẩm có lọc, sắp xếp và phân trang: GET ?action=api-products. Không yêu cầu đăng nhập.
     *
     * Query (đều không bắt buộc; không gửi tham số mới thì kết quả như trước):
     * - search (hoặc q): tìm rộng: tên, mã, mô tả, metadata sản phẩm hoặc SKU, tên phiên bản đang bán chứa từ khóa (tối đa 100 ký tự)
     * - category: slug danh mục
     * - min_price, max_price: khoảng giá bán (VND)
     * - hold: độ giữ nếp 1-3; shine: độ bóng 0-3
     * - tags: các tag cách nhau bằng dấu phẩy, ví dụ "tóc dày,quiff" (tối đa 5, phải khớp tất cả)
     * - sort: newest (mặc định) | oldest | price_asc | price_desc | views | sales
     * - page, per_page (hoặc perPage)
     * Giá, giữ nếp, độ bóng: sản phẩm khớp khi có ít nhất 1 biến thể đang bán thỏa cả 3 cùng lúc.
     * Quy tắc kiểm tra ở CatalogFilter (dùng chung với trợ lý AI).
     *
     * Thành công 200: { status, products: { data, pagination }, filters: { ...bộ lọc đã áp dụng, page, per_page } }
     * Lỗi 400: tham số sai (sai kiểu, ngoài miền giá trị, giá thấp nhất lớn hơn giá cao nhất...).
     *
     * @return void
     */
    public function index(){
        // search: hỗ trợ tên cũ "q" để link cũ vẫn chạy.
        $input = $_GET;
        $input['search'] = $_GET['search'] ?? $_GET['q'] ?? '';
        try {
            $filters = CatalogFilter::fromInput($input);
        } catch (InvalidArgumentException $exception) {
            response_json(['status' => 'error', 'message' => $exception->getMessage()], 400);
        }

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho, để danh sách không hiện "hết hàng" oan.
        Order::releaseExpiredStock();
        $page = $_GET['page'] ?? 1;
        $perPage = $_GET['per_page'] ?? $_GET['perPage'] ?? 8;

        $products = $this->product->getFiltered($filters, $page, $perPage);
        $products['data'] = array_map([$this, 'formatProductListItem'], $products['data']);

        response_json([
            'status' => 'success',
            'products' => $products,
            // Echo lại bộ lọc đã áp dụng (đã chuẩn hóa) để FE hiển thị đúng trạng thái ô lọc.
            'filters' => $filters + [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Dữ liệu dựng các ô lọc ở trang catalog: GET ?action=api-product-filters. Không yêu cầu đăng nhập.
     * Giúp FE không viết cứng nhãn hay khoảng giá: thang giữ nếp / độ bóng (giá trị + nhãn), khoảng giá đang có,
     * tag gợi ý phổ biến. Danh mục lấy riêng ở api-categories.
     *
     * Thành công 200: { status, filters: { hold_label, shine_label, hold_levels: [{value, label}],
     *   shine_levels: [{value, label}], sorts: [{value, label}],
     *   limits: { max_search_length, max_tags, max_tag_length }, price_range: { min, max },
     *   popular_tags: [{ tag, count }] } }
     * Lỗi 405: sai method.
     *
     * @return void
     */
    public function filterOptions(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $config = VariantAttributes::config();
        $options = $this->product->getFilterOptions(10);

        response_json([
            'status' => 'success',
            'filters' => [
                'hold_label' => $config['hold_label'],
                'shine_label' => $config['shine_label'],
                'hold_levels' => $config['hold_levels'],
                'shine_levels' => $config['shine_levels'],
                // Nhãn sắp xếp và giới hạn đầu vào lấy từ CatalogFilter (nguồn duy nhất), FE không viết cứng.
                'sorts' => CatalogFilter::sortOptions(),
                'limits' => CatalogFilter::limits(),
                'price_range' => $options['price_range'],
                'popular_tags' => $options['popular_tags'],
            ],
        ]);
    }

    private function formatProductListItem(array $product): array
    {
        $product['stock'] = (int)($product['stock'] ?? 0);
        // metadata: cột kỹ thuật chỉ để lọc tag ở server, không cần gửi FE.
        unset($product['total_stock'], $product['metadata']);
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
     * Tùy chọn: category_id (chỉ lấy trong danh mục), exclude_id (bỏ một sản phẩm, ví dụ sản phẩm đang xem).
     * Hai tham số này sai định dạng trả HTTP 400; không truyền thì bỏ qua.
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

        // 2 tham số tùy chọn cho khối "cùng danh mục bán chạy" ở trang chi tiết. Không truyền (hoặc rỗng) thì bỏ qua,
        // nên trang chủ gọi như cũ vẫn nhận top 5 toàn shop.
        $categoryId = $this->readOptionalId('category_id');
        $excludeId = $this->readOptionalId('exclude_id');

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho (xem Order::releaseExpiredStock).
        Order::releaseExpiredStock();
        $products = $this->product->getTop($sortBy, $categoryId, $excludeId);
        $products = array_map([$this, 'formatProductListItem'], $products);

        response_json([
            'status' => 'success',
            'sort_by' => $sortBy,
            'products' => $products,
        ]);
    }

    /**
     * Đọc một ID tùy chọn từ query string (ví dụ category_id, exclude_id).
     * Không truyền hoặc chuỗi rỗng thì trả null (bỏ qua bộ lọc). Sai định dạng thì trả HTTP 400.
     *
     * @param string $name Tên tham số trên URL
     * @return int|null ID số nguyên dương, hoặc null nếu không truyền
     */
    private function readOptionalId($name = '')
    {
        $raw = $_GET[$name] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        // filter_var(..., FILTER_VALIDATE_INT): chỉ nhận chuỗi là số nguyên ("10"), từ chối "abc", "1.5", mảng (?x[]=1).
        $id = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;
        if ($id === false || $id < 1) {
            response_json([
                'status' => 'error',
                'message' => $name . ' phải là số nguyên dương',
            ], 400);
        }

        return $id;
    }

    /**
     * Tra tồn kho hiện tại của mọi biến thể trong một sản phẩm: GET ?action=api-product-stock&id=<product_id>.
     * Công khai (không cần đăng nhập): số kho vốn đã hiện ở trang chi tiết. Trang chi tiết gọi mỗi lần khách bấm
     * "+"/"−", khi thêm giỏ bị từ chối, và khi khách quay lại tab, để số "Còn N" không bị cũ.
     * Khác api-product-detail: không tăng lượt xem. Khác api-order-draft-items: trả số kho THẬT, không kẹp tối đa 99.
     *
     * Thành công 200: { status, variants: [{ id, sku, stock, is_active }] } (cả biến thể đã tắt bán, kèm is_active).
     * Lỗi: 405 sai method, 400 id không hợp lệ, 404 không có sản phẩm.
     *
     * @return void
     */
    public function stock(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) {
            response_json(['status' => 'error', 'message' => 'ID sản phẩm không hợp lệ'], 400);
        }

        $variants = $this->product->getVariantStocks($productId);
        if ($variants === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        // Ép kiểu để FE nhận đúng number/boolean thay vì chuỗi từ MySQL.
        $variants = array_map(static function (array $variant): array {
            return [
                'id' => (int) $variant['id'],
                'sku' => $variant['sku'],
                'stock' => (int) $variant['stock'],
                'is_active' => (int) $variant['is_active'] === 1,
            ];
        }, $variants);

        response_json(['status' => 'success', 'variants' => $variants]);
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
                // Mảng [{key, label, value}] để hiển thị bảng thông số: 3 khóa mặc định + khóa tự do (JSON),
                // kèm 2 dòng "Độ giữ nếp", "Độ bóng" lấy nhãn từ 2 cột số (xem VariantAttributes::forDisplay).
                'attributes' => VariantAttributes::forDisplay($variant['attributes'], $variant['hold_level'], $variant['shine_level']),
                // Mức số của 2 tiêu chí lọc (null = chưa rõ), để FE so khớp với bộ lọc nếu cần.
                'hold_level' => $variant['hold_level'] === null ? null : (int)$variant['hold_level'],
                'shine_level' => $variant['shine_level'] === null ? null : (int)$variant['shine_level'],
                // Ảnh riêng của biến thể (tên file), null = chưa có, FE dùng product.image thay thế.
                'image' => $variant['image'] === null || $variant['image'] === '' ? null : $variant['image'],
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
     * Chi tiết sản phẩm cho form sửa ở trang quản trị: GET ?action=api-admin-product-detail&id=<id>.
     * Không tăng lượt xem; trả mọi biến thể (kể cả đã tắt) và album ảnh riêng (xem Product::getAdminDetail).
     * Quyền admin do AdminMiddleware kiểm tra ở router (401/403).
     * Sai method trả 405; id sai trả 400; không tìm thấy trả 404.
     *
     * @return void
     */
    public function adminDetail(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $product = $this->product->getAdminDetail($this->getAdminId());
        if ($product === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        // Ép kiểu số để FE nhận đúng number/boolean thay vì chuỗi từ MySQL.
        $product['id'] = (int) $product['id'];
        $product['category_id'] = $product['category_id'] === null ? null : (int) $product['category_id'];
        $product['view_count'] = (int) $product['view_count'];
        $product['sales_count'] = (int) $product['sales_count'];
        // Cờ nghiệp vụ để FE khóa nút trước (BE vẫn kiểm tra lại khi admin thao tác):
        // - order_count: số đơn hàng có chứa sản phẩm / biến thể.
        // - can_delete: false nếu đã có đơn (biến thể: thêm điều kiện không phải mặc định).
        $product['order_count'] = (int) $product['order_count'];
        $product['can_delete'] = $product['order_count'] === 0;
        $product['variants'] = array_map(static function (array $variant): array {
            $orderCount = (int) $variant['order_count'];
            return [
                'id' => (int) $variant['id'],
                'order_count' => $orderCount,
                'can_delete' => (int) $variant['is_default'] !== 1 && $orderCount === 0,
                'sku' => $variant['sku'],
                'variant_name' => $variant['variant_name'],
                // Mảng chuẩn [{key, label, value}] để SỬA: 3 khóa mặc định + khóa tự do (không ghép 2 mức,
                // vì form admin sửa 2 mức bằng ô chọn riêng, gửi lên qua hold_level / shine_level).
                'attributes' => VariantAttributes::decode($variant['attributes']),
                'hold_level' => $variant['hold_level'] === null ? null : (int) $variant['hold_level'],
                'shine_level' => $variant['shine_level'] === null ? null : (int) $variant['shine_level'],
                // Ảnh riêng của biến thể (tên file), null = chưa có ảnh riêng.
                'image' => $variant['image'] === null || $variant['image'] === '' ? null : $variant['image'],
                'price' => (float) $variant['price'],
                'sale_price' => $variant['sale_price'] === null ? null : (float) $variant['sale_price'],
                'final_price' => (float) $variant['final_price'],
                'stock' => (int) $variant['stock'],
                'is_default' => (int) $variant['is_default'] === 1,
                'is_active' => (int) $variant['is_active'] === 1,
            ];
        }, $product['variants']);
        $product['images'] = array_map(static function (array $image): array {
            return [
                'id' => (int) $image['id'],
                'image_path' => $image['image_path'],
                'sort_order' => (int) $image['sort_order'],
            ];
        }, $product['images']);
        // metadata là cột BE tự tạo (gom thuộc tính để lọc tag), admin không sửa trực tiếp nên không gửi về form.
        unset($product['metadata']);
        // Cấu hình thuộc tính (khóa mặc định, thang giữ nếp / độ bóng) để form admin dựng ô nhập, không viết cứng.
        $product['attribute_config'] = VariantAttributes::config();

        response_json(['status' => 'success', 'product' => $product]);
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
        // Sửa tồn kho phải kèm expected_stock (số kho lúc mở form) để chống ghi đè mất đơn hàng xen giữa.
        $expectedStock = array_key_exists('stock', $data) ? $this->readExpectedStock($input) : null;
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
            $updated = $this->product->updateAdmin($productId, $data, $expectedStock);
        } catch (BusinessConflictException $exception) {
            // Kho vừa đổi: không lưu gì (transaction đã rollback), dọn ảnh vừa tải lên, trả 409 kèm số kho hiện tại.
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            $this->respondConflict($exception);
        } catch (PDOException $exception) {
            if ($uploadedImage !== null) {
                delete_uploaded_file($uploadedImage);
            }
            // Chỉ lỗi 1062 (trùng UNIQUE) mới là "mã/slug đã tồn tại". SQLSTATE 23000 còn gồm lỗi khóa ngoại
            // (ví dụ danh mục vừa bị xóa), báo nhầm "trùng mã" sẽ làm admin hiểu sai; các lỗi đó để ném tiếp.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'Mã hoặc slug sản phẩm đã tồn tại'], 409);
            }
            $this->respondIfPriceCheckFailed($exception);
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

        // Đã đổi sang ảnh đại diện mới: dọn file ảnh cũ để không đọng file rác trong uploads,
        // nhưng chỉ khi không còn bản ghi nào khác dùng file đó (xem deleteImageIfUnused).
        $oldImage = (string) ($currentProduct['image'] ?? '');
        if ($uploadedImage !== null && $oldImage !== '' && $oldImage !== $uploadedImage) {
            $this->deleteImageIfUnused($oldImage);
        }

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

        try {
            $images = $this->product->deleteAdmin($this->getAdminId());
        } catch (BusinessConflictException $exception) {
            // Sản phẩm đã có trong đơn hàng: không xóa (khóa nghiệp vụ), trả 409 kèm số đơn.
            $this->respondConflict($exception);
        }
        if ($images === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }
        // Chỉ xóa file không còn bản ghi nào khác dùng (file dùng chung như default.jpg được giữ lại).
        foreach (array_unique($images) as $image) {
            $this->deleteImageIfUnused($image);
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
        // Ảnh album có thể trùng file với ảnh đại diện hoặc sản phẩm khác: chỉ xóa file khi không còn ai dùng.
        $this->deleteImageIfUnused($imagePath);

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
     * Đổi thứ tự ảnh slide: PUT (JSON) hoặc POST ?action=api-admin-product-image-update&id=<id ảnh>, body { sort_order }.
     * Sai method trả 405; id sai 400; sort_order sai 422; không có ảnh 404. Quyền admin kiểm tra ở router.
     *
     * @return void
     */
    public function adminImageUpdate(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['PUT', 'POST'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $imageId = $this->getAdminId();
        $input = $this->getAdminInput();
        $sortOrder = filter_var($input['sort_order'] ?? null, FILTER_VALIDATE_INT);
        if ($sortOrder === false || $sortOrder < 0 || $sortOrder > 10000) {
            response_json(['status' => 'error', 'message' => 'Thứ tự ảnh phải là số nguyên từ 0 đến 10000'], 422);
        }
        if (!$this->product->updateAdminImageOrder($imageId, $sortOrder)) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy ảnh sản phẩm'], 404);
        }

        response_json(['status' => 'success', 'message' => 'Đã cập nhật thứ tự ảnh']);
    }

    /**
     * Thêm biến thể (không mặc định): POST ?action=api-admin-variant-create, body JSON
     * { product_id, sku, variant_name, price, sale_price?, stock, is_active?, attributes? }.
     * Thành công 201 { variant: { id } }. Sai dữ liệu 422; sản phẩm không có 404; trùng SKU / tên 409.
     *
     * @return void
     */
    public function adminVariantCreate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = $this->getAdminInput();
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) {
            response_json(['status' => 'error', 'message' => 'Sản phẩm không hợp lệ'], 422);
        }
        if ($this->product->find($productId) === false) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        $data = $this->validateAdminVariant($input, true);
        try {
            $variantId = $this->product->createVariant($productId, $data);
        } catch (PDOException $exception) {
            // 1062: vi phạm UNIQUE (SKU trùng toàn hệ thống, hoặc tên biến thể trùng trong cùng sản phẩm).
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'SKU đã tồn tại hoặc tên biến thể đã có trong sản phẩm này'], 409);
            }
            $this->respondIfPriceCheckFailed($exception);
            throw $exception;
        }
        // Biến thể mới có thể mang thuộc tính mới: tạo lại metadata để bộ lọc tag thấy ngay.
        $this->syncMetadata($productId);

        response_json(['status' => 'success', 'message' => 'Đã thêm biến thể', 'variant' => ['id' => $variantId]], 201);
    }

    /**
     * Sửa một phần biến thể: PUT (JSON) hoặc POST ?action=api-admin-variant-update&id=<id>.
     * Body gửi trường nào sửa trường đó: sku, variant_name, price, sale_price, stock, is_active, attributes,
     * is_default (chỉ nhận true: đặt làm mặc định; biến thể mặc định cũ tự bỏ mặc định).
     * Quy tắc: không được ngừng bán biến thể mặc định (422) — đặt biến thể khác làm mặc định trước.
     * Sai dữ liệu 422; không có biến thể 404; trùng SKU / tên 409.
     *
     * @return void
     */
    public function adminVariantUpdate(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['PUT', 'POST'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $variantId = $this->getAdminId();
        $current = $this->product->getVariantById($variantId);
        if ($current === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy biến thể'], 404);
        }

        $input = $this->getAdminInput();
        $makeDefault = false;
        if (array_key_exists('is_default', $input)) {
            if (!$this->readBool($input['is_default'])) {
                response_json(['status' => 'error', 'message' => 'Không thể bỏ mặc định trực tiếp; hãy đặt biến thể khác làm mặc định'], 422);
            }
            $makeDefault = (int) $current['is_default'] !== 1;
        }

        $data = $this->validateAdminVariant($input, false);

        // KHÓA NGHIỆP VỤ: SKU bất biến sau khi tạo. Đơn hàng lưu variant_sku để đối soát, đơn nháp của trợ lý AI
        // tra kho theo SKU; đổi SKU làm các nơi đó lệch hoặc báo "ngừng bán" sai. Gửi lại đúng SKU cũ thì bỏ qua.
        if (array_key_exists('sku', $data)) {
            if ($data['sku'] !== $current['sku']) {
                response_json(['status' => 'error', 'message' => 'Không đổi được SKU sau khi tạo biến thể (đơn hàng và đơn nháp dùng SKU để đối chiếu).'], 422);
            }
            unset($data['sku']);
        }
        // Sửa tồn kho phải kèm expected_stock (số kho lúc mở form) để chống ghi đè mất đơn hàng xen giữa.
        $expectedStock = array_key_exists('stock', $data) ? $this->readExpectedStock($input) : null;

        $isDefaultAfter = $makeDefault || (int) $current['is_default'] === 1;
        if ($isDefaultAfter && array_key_exists('is_active', $data) && $data['is_active'] === 0) {
            response_json(['status' => 'error', 'message' => 'Không thể ngừng bán biến thể mặc định; hãy đặt biến thể khác làm mặc định trước'], 422);
        }

        // Giá khuyến mãi phải thấp hơn giá gốc, so với giá sau khi sửa (trường không gửi thì lấy giá hiện tại).
        $effectivePrice = (float) ($data['price'] ?? $current['price']);
        $effectiveSale = array_key_exists('sale_price', $data) ? $data['sale_price'] : $current['sale_price'];
        if ($effectiveSale !== null && (float) $effectiveSale >= $effectivePrice) {
            response_json(['status' => 'error', 'message' => 'Giá khuyến mãi phải thấp hơn giá gốc'], 422);
        }
        if ($data === [] && !$makeDefault) {
            response_json(['status' => 'error', 'message' => 'Không có thông tin cần cập nhật'], 422);
        }

        try {
            $this->product->updateVariant($variantId, (int) $current['product_id'], $data, $makeDefault, $expectedStock);
        } catch (BusinessConflictException $exception) {
            // Kho vừa đổi: không lưu gì (transaction đã rollback), trả 409 kèm số kho hiện tại.
            $this->respondConflict($exception);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'SKU đã tồn tại hoặc tên biến thể đã có trong sản phẩm này'], 409);
            }
            $this->respondIfPriceCheckFailed($exception);
            throw $exception;
        }
        // Sửa thuộc tính hoặc bật/tắt bán đều làm đổi metadata (chỉ gom biến thể đang bán): tạo lại.
        $this->syncMetadata((int) $current['product_id']);

        response_json(['status' => 'success', 'message' => $makeDefault ? 'Đã đặt làm biến thể mặc định' : 'Đã cập nhật biến thể']);
    }

    /**
     * Xóa hẳn một biến thể: DELETE ?action=api-admin-variant-delete&id=<id>.
     * Không xóa được biến thể mặc định (409). Giỏ hàng chứa biến thể này bị xóa theo; đơn hàng cũ giữ lịch sử.
     * Sai method 405; id sai 400; không có biến thể 404.
     *
     * @return void
     */
    public function adminVariantDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $variant = $this->product->getVariantById($this->getAdminId());
        if ($variant === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy biến thể'], 404);
        }
        if ((int) $variant['is_default'] === 1) {
            response_json(['status' => 'error', 'message' => 'Không thể xóa biến thể mặc định; hãy đặt biến thể khác làm mặc định trước'], 409);
        }
        $deleted = false;
        try {
            $deleted = $this->product->deleteVariant((int) $variant['id']);
        } catch (BusinessConflictException $exception) {
            // Biến thể đã có trong đơn hàng: không xóa (khóa nghiệp vụ), trả 409 kèm số đơn.
            $this->respondConflict($exception);
        }
        if (!$deleted) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy biến thể'], 404);
        }
        // Biến thể bị xóa không còn đóng góp thuộc tính: tạo lại metadata của sản phẩm.
        $this->syncMetadata((int) $variant['product_id']);
        // Dọn file ảnh riêng của biến thể vừa xóa, chỉ khi không còn bản ghi nào khác dùng file đó.
        $this->deleteImageIfUnused((string) ($variant['image'] ?? ''));

        response_json(['status' => 'success', 'message' => 'Đã xóa biến thể']);
    }

    /**
     * Đổi hoặc bỏ ảnh riêng của biến thể: POST ?action=api-admin-variant-image&id=<id biến thể>.
     * - Gửi multipart/form-data có file `image`: lưu ảnh mới, thay ảnh cũ.
     * - Gửi `remove=1` (không kèm file): bỏ ảnh riêng, biến thể dùng lại ảnh sản phẩm.
     * Phải dùng POST vì PHP chỉ đọc file upload ($_FILES) với POST, không đọc với PUT.
     * Thành công 200 { variant: { id, image } }. Sai method 405; id sai 400; thiếu/sai file 422; không có biến thể 404.
     * Quyền admin kiểm tra ở router.
     *
     * @return void
     */
    public function adminVariantImage(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $variantId = $this->getAdminId();
        $variant = $this->product->getVariantById($variantId);
        if ($variant === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy biến thể'], 404);
        }
        $oldImage = (string) ($variant['image'] ?? '');

        $remove = $this->readBool($_POST['remove'] ?? false);
        // Bỏ ảnh thì không nhận file; đổi ảnh thì bắt buộc có file (receiveAdminImage trả 422 nếu thiếu/sai).
        $newImage = $remove ? null : $this->receiveAdminImage(true);

        try {
            $updated = $this->product->updateVariantImage($variantId, $newImage);
        } catch (Throwable $exception) {
            // Ghi DB lỗi: xóa file vừa lưu để không đọng file rác.
            if ($newImage !== null) {
                delete_uploaded_file($newImage);
            }
            throw $exception;
        }
        if (!$updated) {
            if ($newImage !== null) {
                delete_uploaded_file($newImage);
            }
            response_json(['status' => 'error', 'message' => 'Không tìm thấy biến thể'], 404);
        }

        // Thứ tự quan trọng: ghi DB trỏ sang ảnh mới TRƯỚC, rồi mới dọn file cũ (nếu không còn ai dùng).
        if ($oldImage !== '' && $oldImage !== $newImage) {
            $this->deleteImageIfUnused($oldImage);
        }

        response_json([
            'status' => 'success',
            'message' => $remove ? 'Đã bỏ ảnh riêng của biến thể' : 'Đã cập nhật ảnh biến thể',
            'variant' => ['id' => $variantId, 'image' => $newImage],
        ]);
    }

    /**
     * Kiểm tra dữ liệu biến thể từ request, giữ nguyên trường không gửi khi sửa.
     *
     * @param array $input    Dữ liệu request
     * @param bool  $isCreate true khi tạo mới (bắt buộc sku, variant_name, price, stock)
     * @return array Dữ liệu đã chuẩn hóa (attributes là chuỗi JSON mảng)
     */
    private function validateAdminVariant(array $input = [], bool $isCreate = false): array
    {
        $data = [];
        foreach (['sku', 'variant_name', 'price', 'stock'] as $field) {
            if ($isCreate && !array_key_exists($field, $input)) {
                response_json(['status' => 'error', 'message' => "Thiếu trường {$field}"], 422);
            }
        }

        foreach (['sku' => 100, 'variant_name' => 255] as $field => $maxLength) {
            if (array_key_exists($field, $input)) {
                $value = is_string($input[$field]) ? trim($input[$field]) : '';
                if ($value === '' || mb_strlen($value) > $maxLength) {
                    response_json(['status' => 'error', 'message' => "Trường {$field} không hợp lệ"], 422);
                }
                $data[$field] = $value;
            }
        }
        if (array_key_exists('price', $input)) {
            if (!is_numeric($input['price']) || (float) $input['price'] < 0 || (float) $input['price'] > 99999999.99) {
                response_json(['status' => 'error', 'message' => 'Giá biến thể không hợp lệ'], 422);
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
        if ($isCreate && $data['sale_price'] !== null && (float) $data['sale_price'] >= (float) $data['price']) {
            response_json(['status' => 'error', 'message' => 'Giá khuyến mãi phải thấp hơn giá gốc'], 422);
        }
        if (array_key_exists('stock', $input)) {
            $stock = filter_var($input['stock'], FILTER_VALIDATE_INT);
            if ($stock === false || $stock < 0 || $stock > 2147483647) {
                response_json(['status' => 'error', 'message' => 'Tồn kho không hợp lệ'], 422);
            }
            $data['stock'] = $stock;
        }
        if (array_key_exists('is_active', $input)) {
            $data['is_active'] = $this->readBool($input['is_active']) ? 1 : 0;
        } elseif ($isCreate) {
            $data['is_active'] = 1;
        }

        // Độ giữ nếp (1-3) / độ bóng (0-3): chọn từ thang cố định (VariantAttributes). Rỗng hoặc null = chưa rõ.
        $levelFields = [
            'hold_level' => [VariantAttributes::HOLD_LEVELS, 'Độ giữ nếp'],
            'shine_level' => [VariantAttributes::SHINE_LEVELS, 'Độ bóng'],
        ];
        foreach ($levelFields as $field => [$levels, $label]) {
            if (array_key_exists($field, $input)) {
                $level = VariantAttributes::readLevel($input[$field], $levels);
                if ($level === false) {
                    response_json(['status' => 'error', 'message' => "{$label} phải là một trong: " . implode(', ', array_keys($levels))], 422);
                }
                $data[$field] = $level;
            } elseif ($isCreate) {
                $data[$field] = null;
            }
        }

        // attributes: mảng [{key, label, value}]. normalize luôn trả đủ 3 khóa mặc định ở đầu, bỏ phần tử sai định dạng
        // và bỏ khóa do_giu_nep / do_bong (đã chuyển sang 2 cột số ở trên).
        if (array_key_exists('attributes', $input)) {
            if (!is_array($input['attributes']) || count($input['attributes']) > 30) {
                response_json(['status' => 'error', 'message' => 'Thuộc tính biến thể không hợp lệ'], 422);
            }
            $attributes = VariantAttributes::normalize($input['attributes']);
            foreach ($attributes as $attribute) {
                if (mb_strlen((string) $attribute['label']) > 100 || mb_strlen((string) ($attribute['value'] ?? '')) > 255) {
                    response_json(['status' => 'error', 'message' => 'Nhãn thuộc tính tối đa 100 ký tự, giá trị tối đa 255 ký tự'], 422);
                }
            }
            // JSON_UNESCAPED_UNICODE: giữ chữ có dấu thay vì đổi thành \uXXXX.
            $data['attributes'] = json_encode($attributes, JSON_UNESCAPED_UNICODE);
        } elseif ($isCreate) {
            $data['attributes'] = VariantAttributes::defaultsJson();
        }

        return $data;
    }

    /**
     * Đọc expected_stock (số kho admin thấy lúc mở form) — bắt buộc khi request sửa tồn kho.
     * Thiếu hoặc sai định dạng trả 422. Dùng để khóa ghi đè tồn kho (xem Product::assertStockUnchanged).
     *
     * @param array $input Dữ liệu request
     * @return int Số kho lúc mở form
     */
    private function readExpectedStock(array $input = []): int
    {
        $expected = filter_var($input['expected_stock'] ?? null, FILTER_VALIDATE_INT);
        if ($expected === false || $expected < 0) {
            response_json(['status' => 'error', 'message' => 'Sửa tồn kho cần kèm expected_stock (số kho lúc mở form)'], 422);
        }

        return $expected;
    }

    /**
     * Trả HTTP 409 cho lỗi vi phạm quy tắc nghiệp vụ, kèm dữ liệu thêm (current_stock, order_count...) cho FE.
     *
     * Kiểu trả về never: hàm luôn kết thúc request (response_json có exit), code phía sau lời gọi không chạy tiếp.
     *
     * @param BusinessConflictException|null $exception Lỗi nghiệp vụ từ model (null: trả thông báo chung)
     * @return never
     */
    private function respondConflict(?BusinessConflictException $exception = null): never
    {
        if ($exception === null) {
            response_json(['status' => 'error', 'message' => 'Thao tác bị từ chối do dữ liệu vừa thay đổi. Tải lại rồi thử lại.'], 409);
            exit;
        }
        response_json(['status' => 'error', 'message' => $exception->getMessage()] + $exception->getDetails(), 409);
        exit;
    }

    /**
     * Lỗi 3819 = vi phạm ràng buộc CHECK của DB. Với bảng biến thể, trường hợp thực tế là
     * "giá khuyến mãi phải thấp hơn giá gốc" bị phá khi 2 admin sửa giá cùng lúc (kiểm tra ở controller dùng giá cũ).
     * Trả 422 có thông báo dễ hiểu thay vì để lỗi DB thành 500. Lỗi khác thì bỏ qua để nơi gọi ném tiếp.
     *
     * @param PDOException|null $exception Lỗi từ PDO
     * @return void
     */
    private function respondIfPriceCheckFailed(?PDOException $exception = null): void
    {
        if ($exception !== null && ($exception->errorInfo[1] ?? null) === 3819) {
            response_json(['status' => 'error', 'message' => 'Giá khuyến mãi phải thấp hơn giá gốc (giá vừa được thay đổi, hãy tải lại rồi kiểm tra).'], 422);
        }
    }

    /**
     * Tạo lại products.metadata sau khi admin thêm/sửa/xóa biến thể, KHÔNG để lỗi ở bước này làm hỏng response.
     *
     * Vì sao bắt lỗi: lúc gọi hàm này, thay đổi chính của admin ĐÃ được lưu (transaction đã commit). Nếu để lỗi văng ra,
     * admin nhận 500 cho một lần lưu thực ra đã thành công rồi bấm lưu lại. Metadata chỉ là bản sao gom lại để lọc tag,
     * lần sửa biến thể kế tiếp sẽ tự tạo lại. Lỗi được ghi log (PHP + log API) để dev biết mà xử lý.
     *
     * @param int $productId ID sản phẩm vừa đổi biến thể
     * @return void
     */
    private function syncMetadata(int $productId = 0): void
    {
        try {
            $this->product->refreshMetadata($productId);
        } catch (Throwable $exception) {
            error_log('[ProductController] Không tạo lại được metadata sản phẩm #' . $productId . ': ' . $exception->getMessage());
            if (class_exists('ApiLogger', false)) {
                ApiLogger::note('Không tạo lại được metadata sản phẩm #' . $productId . ' (bộ lọc tag có thể lệch tới lần sửa sau).', 'WARN');
            }
        }
    }

    /**
     * Đọc giá trị đúng/sai từ request: nhận true/false, 1/0, "1"/"0", "true"/"false".
     *
     * @param mixed $value Giá trị đầu vào
     * @return bool
     */
    private function readBool($value = false): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * Xóa file ảnh trong uploads, nhưng CHỈ khi không còn bản ghi nào dùng file đó.
     * Gọi sau khi đã xóa/sửa bản ghi trong DB. Bỏ qua tên file có đường dẫn (chống xóa nhầm ngoài thư mục uploads).
     *
     * @param mixed $image Tên file ảnh như lưu trong DB
     * @return void
     */
    private function deleteImageIfUnused($image = ''): void
    {
        if (!is_string($image) || $image === '' || basename($image) !== $image) {
            return;
        }
        if ($this->product->isImageInUse($image)) {
            return;
        }
        delete_uploaded_file($image);
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