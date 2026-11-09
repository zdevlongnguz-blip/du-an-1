<!-- hoang -->

<?php

class CategoryController
{
    private Category $category;

    public function __construct()
    {
        $this->category = new Category();
    }

    public function index()
    {
        $categories = $this->category->getAllCategories();

        response_json([
            'status' => 'success',
            'categories' => $categories
        ]);
    }

    /**
     * Tạo danh mục; sai method hoặc dữ liệu trả HTTP 405/400/422, slug trùng trả 409.
     *
     * @return void
     */
    public function adminCreate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu danh mục không hợp lệ'], 400);
        }
        [$name, $slug] = $this->validateAdminCategory($input);

        try {
            $categoryId = $this->category->createAdmin($name, $slug);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'Slug danh mục đã tồn tại'], 409);
            }
            throw $exception;
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã tạo danh mục',
            'category' => ['id' => $categoryId, 'name' => $name, 'slug' => $slug],
        ], 201);
    }

    /**
     * Cập nhật tên và slug danh mục; không tìm thấy trả 404, slug trùng trả 409.
     *
     * @return void
     */
    public function adminUpdate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $categoryId = $this->getAdminId();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu danh mục không hợp lệ'], 400);
        }
        [$name, $slug] = $this->validateAdminCategory($input);

        try {
            if (!$this->category->updateAdmin($categoryId, $name, $slug)) {
                response_json(['status' => 'error', 'message' => 'Không tìm thấy danh mục'], 404);
            }
        } catch (PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                response_json(['status' => 'error', 'message' => 'Slug danh mục đã tồn tại'], 409);
            }
            throw $exception;
        }

        response_json(['status' => 'success', 'message' => 'Đã cập nhật danh mục']);
    }

    /**
     * Xóa danh mục trống; trả 409 nếu vẫn có sản phẩm đang sử dụng danh mục.
     *
     * @return void
     */
    public function adminDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $result = $this->category->deleteAdmin($this->getAdminId());
        if ($result === 'not_found') {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy danh mục'], 404);
        }
        if ($result === 'in_use') {
            response_json(['status' => 'error', 'message' => 'Không thể xóa danh mục đang có sản phẩm'], 409);
        }

        response_json(['status' => 'success', 'message' => 'Đã xóa danh mục']);
    }

    /**
     * Kiểm tra tên và slug danh mục theo giới hạn cột dữ liệu.
     *
     * @param array $input Dữ liệu JSON nhận từ request.
     * @return array{0: string, 1: string} Tên và slug đã trim.
     */
    private function validateAdminCategory(array $input = []): array
    {
        $name = $input['name'] ?? null;
        $slug = $input['slug'] ?? null;
        if (!is_string($name) || !is_string($slug)
            || trim($name) === '' || strlen(trim($name)) > 255
            || trim($slug) === '' || strlen(trim($slug)) > 255) {
            response_json(['status' => 'error', 'message' => 'Tên hoặc slug danh mục không hợp lệ'], 422);
        }

        return [trim($name), trim($slug)];
    }

    /**
     * Đọc ID dương từ query string.
     *
     * @return int ID danh mục.
     */
    private function getAdminId(): int
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            response_json(['status' => 'error', 'message' => 'ID danh mục không hợp lệ'], 400);
        }

        return $id;
    }
}