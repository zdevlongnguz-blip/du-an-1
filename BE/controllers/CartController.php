<?php
/** Controller xử lý các API giỏ hàng của user đã đăng nhập. */
class CartController
{
    private Cart $cart;

    /** Khởi tạo model truy cập giỏ hàng. */
    public function __construct()
    {
        $this->cart = new Cart();
    }

    /**
     * Lấy giỏ và tổng tiền tạm tính của user hiện tại.
     * Sai method trả 405; payload JWT sai trả 401.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function index(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        response_json(['status' => 'success', 'cart' => $this->cart->getByUserId($userId)]);
    }

    /**
     * Thêm biến thể vào giỏ; quantity mặc định là 1 nếu không gửi.
     * Sai dữ liệu trả 422, không có biến thể trả 404, ngừng bán/thiếu hàng trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function add(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu giỏ hàng không hợp lệ'], 400);
        }

        $variantId = filter_var($input['variant_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT);
        if ($variantId === false || $variantId < 1 || $quantity === false || $quantity < 1) {
            response_json(['status' => 'error', 'message' => 'ID biến thể hoặc số lượng không hợp lệ'], 422);
        }

        $result = $this->cart->addItem($userId, $variantId, $quantity);
        if ($result === 'not_found') {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy phiên bản sản phẩm'], 404);
        }
        if ($result === 'unavailable') {
            response_json(['status' => 'error', 'message' => 'Phiên bản sản phẩm đã ngừng bán'], 409);
        }
        if ($result === 'out_of_stock' || $result === 'stock_exceeded') {
            response_json(['status' => 'error', 'message' => 'Số lượng vượt quá tồn kho'], 409);
        }
        if ($result !== 'ok') {
            response_json(['status' => 'error', 'message' => 'Dữ liệu giỏ hàng không hợp lệ'], 422);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã thêm sản phẩm vào giỏ hàng',
            'cart' => $this->cart->getByUserId($userId),
        ], 201);
    }

    /**
     * Đặt số lượng mới cho item thuộc giỏ của user hiện tại.
     * Sai method trả 405; item không thuộc user trả 404; vượt tồn kho trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function update(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $itemId = $this->getItemId();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu giỏ hàng không hợp lệ'], 400);
        }

        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1) {
            response_json(['status' => 'error', 'message' => 'Số lượng phải là số nguyên dương'], 422);
        }

        $result = $this->cart->updateItem($userId, $itemId, $quantity);
        if ($result === 'not_found') {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm trong giỏ'], 404);
        }
        if ($result === 'unavailable') {
            response_json(['status' => 'error', 'message' => 'Phiên bản sản phẩm đã ngừng bán'], 409);
        }
        if ($result === 'stock_exceeded') {
            response_json(['status' => 'error', 'message' => 'Số lượng vượt quá tồn kho'], 409);
        }
        if ($result !== 'ok') {
            response_json(['status' => 'error', 'message' => 'Dữ liệu giỏ hàng không hợp lệ'], 422);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã cập nhật giỏ hàng',
            'cart' => $this->cart->getByUserId($userId),
        ]);
    }

    /**
     * Xóa item thuộc giỏ của user hiện tại.
     * Sai method trả 405; item không thuộc user trả 404.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function delete(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $itemId = $this->getItemId();
        if (!$this->cart->removeItem($userId, $itemId)) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm trong giỏ'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã xóa sản phẩm khỏi giỏ hàng',
            'cart' => $this->cart->getByUserId($userId),
        ]);
    }

    /**
     * Lấy user ID từ claim `sub` đã được middleware xác minh.
     *
     * @param array $authPayload Payload JWT đã xác minh.
     * @return int ID user.
     */
    private function getUserId(array $authPayload = []): int
    {
        $userId = filter_var($authPayload['sub'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ'], 401);
        }

        return $userId;
    }

    /**
     * Đọc ID item dương từ query string.
     *
     * @return int ID dòng hàng trong giỏ.
     */
    private function getItemId(): int
    {
        $itemId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId === false || $itemId < 1) {
            response_json(['status' => 'error', 'message' => 'ID sản phẩm trong giỏ không hợp lệ'], 400);
        }

        return $itemId;
    }
}