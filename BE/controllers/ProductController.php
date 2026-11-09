<?php
class ProductController{
    private Product $product;
    public function __construct()
    {
        $this->product = new Product();
    }

    public function index(){
        $search = $_GET['search'] ?? $_GET['q'] ?? '';
        $category = $_GET['category'] ?? '';
        if (!is_string($category) || strlen(trim($category)) > 255) {
            response_json([
                'status' => 'error',
                'message' => 'Slug danh mục không hợp lệ',
            ], 400);
        }
        $category = trim($category);
        $category = $category === '' ? null : $category;
        $page = $_GET['page'] ?? 1;
        $perPage = $_GET['per_page'] ?? $_GET['perPage'] ?? 8;

        $products = $this->product->getFiltered(
            $search,
            $category,
            $page,
            $perPage
        );

        response_json([
            'status' => 'success',
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
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

        $products = $this->product->getTop($sortBy);

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
            'product' => $product,
        ]);
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
        $effectivePrice = (float) ($data['price'] ?? $currentProduct['price']);
        $effectiveSalePrice = array_key_exists('sale_price', $data)
            ? $data['sale_price']
            : $currentProduct['sale_price'];
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