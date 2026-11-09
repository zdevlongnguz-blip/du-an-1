<?php
class BannerController
{
    private Banner $banner;

    public function __construct()
    {
        $this->banner = new Banner();
    }

    public function index()
    {
        response_json([
            'status' => 'success',
            'banners' => $this->banner->getAllBanners(),
        ]);
    }

    /**
     * Tạo banner; nhận JSON hoặc multipart/form-data để tải ảnh.
     * Sai method/dữ liệu trả HTTP 405/400/422.
     *
     * @return void
     */
    public function adminCreate(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $input = $this->getAdminInput();
        $data = $this->validateAdminBanner($input, true);
        $imageName = $this->receiveAdminImage();
        $data['image'] = $imageName;

        try {
            $bannerId = $this->banner->createAdmin($data);
        } catch (Throwable $exception) {
            if ($imageName !== null) {
                delete_uploaded_file($imageName);
            }
            throw $exception;
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã tạo banner',
            'banner' => ['id' => $bannerId] + $data,
        ], 201);
    }

    /**
     * Cập nhật banner bằng PUT JSON hoặc POST multipart khi thay ảnh.
     * Dữ liệu sai trả 400/422; banner không tồn tại trả 404.
     *
     * @return void
     */
    public function adminUpdate(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['PUT', 'POST'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $bannerId = $this->getAdminId();
        $currentBanner = $this->banner->find($bannerId);
        if ($currentBanner === false) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy banner'], 404);
        }

        $input = $this->getAdminInput();
        $data = $this->validateAdminBanner($input, false);
        $imageName = $this->receiveAdminImage();
        if ($imageName !== null) {
            $data['image'] = $imageName;
        }
        if ($data === []) {
            response_json(['status' => 'error', 'message' => 'Không có thông tin cần cập nhật'], 422);
        }

        try {
            $updated = $this->banner->updateAdmin($bannerId, $data);
        } catch (Throwable $exception) {
            if ($imageName !== null) {
                delete_uploaded_file($imageName);
            }
            throw $exception;
        }
        if (!$updated) {
            if ($imageName !== null) {
                delete_uploaded_file($imageName);
            }
            response_json(['status' => 'error', 'message' => 'Không tìm thấy banner'], 404);
        }
        if ($imageName !== null) {
            $oldImage = $this->getManagedImageName($currentBanner['image'] ?? null);
            if ($oldImage !== null && $oldImage !== $imageName) {
                delete_uploaded_file($oldImage);
            }
        }

        response_json(['status' => 'success', 'message' => 'Đã cập nhật banner']);
    }

    /**
     * Xóa banner và ảnh do API quản trị đã tải lên.
     * ID sai trả 400; banner không tồn tại trả 404.
     *
     * @return void
     */
    public function adminDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $image = $this->banner->deleteAdmin($this->getAdminId());
        if ($image === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy banner'], 404);
        }
        $imageName = $this->getManagedImageName($image);
        if ($imageName !== null) {
            delete_uploaded_file($imageName);
        }

        response_json(['status' => 'success', 'message' => 'Đã xóa banner']);
    }

    /**
     * Đọc dữ liệu JSON hoặc form multipart.
     *
     * @return array Dữ liệu banner dạng mảng.
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
            response_json(['status' => 'error', 'message' => 'Dữ liệu banner không hợp lệ'], 400);
        }

        return $input;
    }

    /**
     * Kiểm tra các trường banner; khi cập nhật chỉ trả về trường được gửi.
     *
     * @param array $input Dữ liệu từ request.
     * @param bool $isCreate True nếu đây là thao tác tạo mới.
     * @return array Dữ liệu đã chuẩn hóa.
     */
    private function validateAdminBanner(array $input = [], bool $isCreate = false): array
    {
        $data = [];
        foreach (['title' => 255, 'content' => 60000, 'CTA_link' => 2048] as $field => $maxLength) {
            if (!array_key_exists($field, $input)) {
                if ($isCreate) {
                    response_json(['status' => 'error', 'message' => "Thiếu trường {$field}"], 422);
                }
                continue;
            }
            if (!is_string($input[$field]) || trim($input[$field]) === '' || strlen(trim($input[$field])) > $maxLength) {
                response_json(['status' => 'error', 'message' => "Trường {$field} không hợp lệ"], 422);
            }
            $data[$field] = trim($input[$field]);
        }

        return $data;
    }

    /**
     * Kiểm tra và lưu ảnh banner qua helper upload dùng chung.
     *
     * @return string|null Tên file ảnh đã lưu, hoặc null nếu không tải ảnh.
     */
    private function receiveAdminImage(): ?string
    {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
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
     * Chỉ nhận diện file do helper tạo, không xóa ảnh ngoài hoặc file có tên tùy ý.
     *
     * @param mixed $image Tên file hoặc URL ảnh hiện tại.
     * @return string|null Tên file thuộc uploads hoặc null.
     */
    private function getManagedImageName($image): ?string
    {
        if (!is_string($image) || $image === '') {
            return null;
        }
        $name = $image;
        $managedPrefix = url('assets/uploads/');
        if (strpos($image, $managedPrefix) === 0) {
            $path = parse_url($image, PHP_URL_PATH);
            $name = is_string($path) ? basename($path) : '';
        }
        if ($name === '' || basename($name) !== $name
            || preg_match('/^cyber_[a-f0-9]{12}_\d+\.(png|jpe?g|gif|webp)$/i', $name) !== 1) {
            return null;
        }

        return $name;
    }

    /**
     * Đọc ID dương từ query string.
     *
     * @return int ID banner.
     */
    private function getAdminId(): int
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            response_json(['status' => 'error', 'message' => 'ID banner không hợp lệ'], 400);
        }

        return $id;
    }
}