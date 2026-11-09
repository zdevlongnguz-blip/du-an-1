<?php
/** Controller xử lý checkout, lịch sử đơn và quản trị trạng thái đơn hàng. */
class OrderController
{
    private Order $order;

    /** Khởi tạo model truy cập đơn hàng. */
    public function __construct()
    {
        $this->order = new Order();
    }

    /**
     * Tạo đơn từ giỏ hàng và trừ tồn kho trong transaction.
     * Sai method trả 405, giỏ rỗng trả 422, thiếu hàng trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function checkout(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)
            || !is_string($input['recipient_name'] ?? null)
            || !is_string($input['recipient_phone'] ?? null)
            || !is_string($input['shipping_address'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Thông tin giao hàng không hợp lệ'], 400);
        }

        $recipientName = trim($input['recipient_name']);
        $recipientPhone = trim($input['recipient_phone']);
        $shippingAddress = trim($input['shipping_address']);
        if ($recipientName === '' || strlen($recipientName) > 100
            || !preg_match('/^[0-9+()\-\s]{7,20}$/', $recipientPhone)
            || $shippingAddress === '' || strlen($shippingAddress) > 500) {
            response_json(['status' => 'error', 'message' => 'Thông tin giao hàng không hợp lệ'], 422);
        }

        $result = $this->order->checkout($userId, $recipientName, $recipientPhone, $shippingAddress);
        if (($result['status'] ?? '') === 'empty_cart') {
            response_json(['status' => 'error', 'message' => 'Giỏ hàng đang trống'], 422);
        }
        if (($result['status'] ?? '') === 'stock_unavailable') {
            response_json(['status' => 'error', 'message' => 'Tồn kho đã thay đổi, vui lòng kiểm tra lại giỏ hàng'], 409);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đặt hàng thành công',
            'order' => [
                'id' => $result['order_id'],
                'total_amount' => $result['total_amount'],
                'order_status' => 'pending',
                'payment_status' => 'unpaid',
            ],
        ], 201);
    }

    /**
     * Lấy danh sách đơn của user đang đăng nhập theo trang.
     * Sai method trả 405; phân trang sai trả 400.
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
        $pageInput = $_GET['page'] ?? '1';
        $perPageInput = $_GET['per_page'] ?? '10';
        $page = is_string($pageInput) && ctype_digit($pageInput) ? (int) $pageInput : 0;
        $perPage = is_string($perPageInput) && ctype_digit($perPageInput) ? (int) $perPageInput : 0;
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            response_json(['status' => 'error', 'message' => 'Thông tin phân trang không hợp lệ'], 400);
        }

        response_json([
            'status' => 'success',
            'orders' => $this->order->getList($userId, $page, $perPage),
        ]);
    }

    /**
     * Lấy chi tiết đơn làm dữ liệu hóa đơn; user chỉ xem đơn của mình.
     * Sai ID trả 400; không tồn tại hoặc không thuộc user trả 404.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function invoice(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $orderId = $this->getOrderId();
        $isAdmin = ($authPayload['role'] ?? '') === 'admin';
        $invoice = $this->order->getInvoice($orderId, $userId, $isAdmin);
        if ($invoice === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        response_json(['status' => 'success', 'invoice' => $invoice]);
    }

    /**
     * Hủy đơn pending chưa thanh toán của user và hoàn lại tồn kho.
     * Sai method trả 405; không có đơn trả 404; đơn không thể hủy trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function cancel(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $result = $this->order->cancelByUser($this->getOrderId(), $userId);
        if ($result === 'not_found') {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy đơn hàng'], 404);
        }
        if ($result !== 'ok') {
            response_json(['status' => 'error', 'message' => 'Chỉ có thể hủy đơn đang chờ và chưa thanh toán'], 409);
        }

        response_json(['status' => 'success', 'message' => 'Đã hủy đơn hàng']);
    }

    /**
     * Lấy danh sách tất cả đơn cho admin.
     * Sai method trả 405; user không phải admin trả 403.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function adminIndex(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $this->requireAdmin($authPayload);
        $pageInput = $_GET['page'] ?? '1';
        $perPageInput = $_GET['per_page'] ?? '10';
        $page = is_string($pageInput) && ctype_digit($pageInput) ? (int) $pageInput : 0;
        $perPage = is_string($perPageInput) && ctype_digit($perPageInput) ? (int) $perPageInput : 0;
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            response_json(['status' => 'error', 'message' => 'Thông tin phân trang không hợp lệ'], 400);
        }

        response_json([
            'status' => 'success',
            'orders' => $this->order->getList(null, $page, $perPage),
        ]);
    }

    /**
     * Admin cập nhật trạng thái vận chuyển và thanh toán của đơn.
     * Sai dữ liệu trả 400/422; không có đơn trả 404; chuyển trạng thái sai trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function adminUpdate(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        $this->requireAdmin($authPayload);
        $orderId = $this->getOrderId();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)
            || !is_string($input['order_status'] ?? null)
            || !is_string($input['payment_status'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Trạng thái đơn hàng không hợp lệ'], 400);
        }

        $orderStatuses = ['pending', 'confirmed', 'shipping', 'delivered', 'cancelled'];
        $paymentStatuses = ['unpaid', 'paid'];
        if (!in_array($input['order_status'], $orderStatuses, true)
            || !in_array($input['payment_status'], $paymentStatuses, true)) {
            response_json(['status' => 'error', 'message' => 'Trạng thái đơn hàng không hợp lệ'], 422);
        }

        $result = $this->order->updateByAdmin($orderId, $input['order_status'], $input['payment_status']);
        if ($result === 'not_found') {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy đơn hàng'], 404);
        }
        if ($result !== 'ok') {
            response_json(['status' => 'error', 'message' => 'Không thể chuyển đơn sang trạng thái này'], 409);
        }

        response_json(['status' => 'success', 'message' => 'Đã cập nhật đơn hàng']);
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
     * Dừng request nếu payload JWT không đại diện cho admin.
     *
     * @param array $authPayload Payload JWT đã xác minh.
     * @return void
     */
    private function requireAdmin(array $authPayload = []): void
    {
        $this->getUserId($authPayload);
        if (($authPayload['role'] ?? '') !== 'admin') {
            response_json(['status' => 'error', 'message' => 'Bạn không có quyền thực hiện thao tác này'], 403);
        }
    }

    /**
     * Đọc ID đơn dương từ query string.
     *
     * @return int ID đơn hàng.
     */
    private function getOrderId(): int
    {
        $orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            response_json(['status' => 'error', 'message' => 'ID đơn hàng không hợp lệ'], 400);
        }

        return $orderId;
    }

}