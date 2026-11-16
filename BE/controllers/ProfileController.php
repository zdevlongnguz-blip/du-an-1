<?php

/**
 * Controller xử lý API hồ sơ cá nhân của user đã đăng nhập.
 * Tầng controller: nhận request, kiểm tra quyền/dữ liệu, gọi model rồi trả JSON.
 */
class ProfileController
{
    private User $user;

    /** Khởi tạo model User để đọc thông tin tài khoản. */
    public function __construct()
    {
        $this->user = new User();
    }

    /**
     * Trả hồ sơ của user đang đăng nhập: id, email, role, name, phone, address.
     * ID lấy từ payload JWT (claim sub), không nhận ID do client gửi lên.
     * Sai method trả 405; token sai trả 401; tài khoản không tồn tại hoặc chưa kích hoạt trả 404.
     *
     * @param array $authPayload Payload JWT đã xác minh từ AuthMiddleware.
     * @return void
     */
    public function show(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        response_json([
            'status' => 'success',
            'user' => $this->getProfileOrFail($userId),
        ]);
    }

    /**
     * Cập nhật họ tên, số điện thoại, địa chỉ của user đang đăng nhập.
     * Body JSON bắt buộc có đủ 3 key name, phone, address; mỗi giá trị là chuỗi hoặc null.
     * Chuỗi rỗng (sau khi bỏ khoảng trắng) được lưu thành NULL, tức là xóa trường đó.
     * Các key khác (email, role, password...) bị bỏ qua, không bao giờ được cập nhật ở đây.
     * Sai method trả 405; body hỏng trả 400; sai định dạng/quá dài trả 422; token sai trả 401; không có tài khoản trả 404.
     *
     * @param array $authPayload Payload JWT đã xác minh từ AuthMiddleware.
     * @return void
     */
    public function update(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $input = json_decode(file_get_contents('php://input'), true);

        // array_key_exists thay vì isset: isset coi giá trị null là "không có", nhưng ở đây null là hợp lệ (xóa trường).
        if (!is_array($input)
            || !array_key_exists('name', $input)
            || !array_key_exists('phone', $input)
            || !array_key_exists('address', $input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu hồ sơ không hợp lệ'], 400);
        }

        foreach (['name', 'phone', 'address'] as $field) {
            if ($input[$field] !== null && !is_string($input[$field])) {
                response_json(['status' => 'error', 'message' => 'Dữ liệu hồ sơ không hợp lệ'], 400);
            }
        }

        $name = $this->normalizeOptionalText($input['name']);
        $phone = $this->normalizeOptionalText($input['phone']);
        $address = $this->normalizeOptionalText($input['address']);

        // Giới hạn độ dài giống form đặt hàng (OrderController::checkout) để dữ liệu hồ sơ dùng lại được khi đặt hàng.
        // strlen đếm theo byte: chữ tiếng Việt có dấu chiếm 2-3 byte nên giới hạn này chặt hơn số ký tự của cột.
        if ($name !== null && strlen($name) > 100) {
            response_json(['status' => 'error', 'message' => 'Họ tên tối đa 100 byte'], 422);
        }

        // Dùng quy tắc SĐT di động Việt Nam chung với đặt hàng (extensions/VietnamPhone.php); lưu dạng chuẩn 0xxxxxxxxx.
        if ($phone !== null) {
            $phone = VietnamPhone::normalize($phone);
            if ($phone === null) {
                response_json(['status' => 'error', 'message' => 'Số điện thoại phải là số di động Việt Nam 10 chữ số (03, 05, 07, 08, 09)'], 422);
            }
        }

        if ($address !== null && strlen($address) > 500) {
            response_json(['status' => 'error', 'message' => 'Địa chỉ tối đa 500 byte'], 422);
        }

        $this->user->updateProfile($userId, $name, $phone, $address);

        // Đọc lại từ DB để trả đúng dữ liệu đã lưu; tài khoản không còn hoạt động thì trả 404.
        response_json([
            'status' => 'success',
            'message' => 'Cập nhật hồ sơ thành công',
            'user' => $this->getProfileOrFail($userId),
        ]);
    }

    /**
     * Đọc ID user dương từ claim sub của payload JWT; sai thì trả 401.
     *
     * @param array $authPayload Payload JWT đã xác minh.
     * @return int ID user.
     */
    private function getUserId(array $authPayload = []): int
    {
        // filter_var với FILTER_VALIDATE_INT: kiểm tra sub có đúng là số nguyên dương không.
        // Token không có sub hợp lệ coi như token sai.
        $userId = filter_var($authPayload['sub'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ'], 401);
        }

        return $userId;
    }

    /**
     * Lấy hồ sơ theo ID và chuẩn hóa kiểu dữ liệu; không tìm thấy thì trả 404.
     *
     * @param int $userId ID user đã xác minh.
     * @return array Hồ sơ gồm id, email, role, name, phone, address.
     */
    private function getProfileOrFail(int $userId = 0): array
    {
        $profile = $this->user->findProfileById($userId);
        if (!$profile) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy tài khoản'], 404);
        }

        // PDO thường trả số dưới dạng chuỗi; ép về int để JSON nhất quán với response đăng nhập.
        $profile['id'] = (int) $profile['id'];

        return $profile;
    }

    /**
     * Bỏ khoảng trắng hai đầu; chuỗi rỗng hoặc null đều trở thành null (trường không bắt buộc).
     *
     * @param string|null $value Giá trị gửi lên.
     * @return string|null Chuỗi đã trim hoặc null.
     */
    private function normalizeOptionalText(?string $value = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
