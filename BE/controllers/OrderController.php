<?php
/** Controller xử lý checkout, thanh toán SePay (tạo form, IPN, xác minh), lịch sử đơn và quản trị đơn hàng. */
use SePay\Builders\CheckoutBuilder;
use SePay\Exceptions\NotFoundException;
use SePay\Exceptions\SePayException;
use SePay\SePayClient;

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
     * Body: recipient_name, recipient_phone, shipping_address và payment_method tùy chọn ('cod' mặc định, hoặc 'sepay').
     * expected_total (không bắt buộc): tổng tiền khách đang thấy trên trang, dùng để phát hiện giá đã đổi.
     * Không gửi thì không đối chiếu (giữ tương thích với FE cũ).
     * Sai method trả 405, thông tin giao hàng hoặc phương thức thanh toán sai trả 400/422, giỏ rỗng trả 422,
     * thiếu hàng trả 409, giá đã đổi trả 409 (kèm total_amount mới).
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
        $shippingAddress = trim($input['shipping_address']);
        if ($recipientName === '' || strlen($recipientName) > 100
            || $shippingAddress === '' || strlen($shippingAddress) > 500) {
            response_json(['status' => 'error', 'message' => 'Thông tin giao hàng không hợp lệ'], 422);
        }

        // Dùng quy tắc SĐT di động Việt Nam chung (extensions/VietnamPhone.php); lưu dạng chuẩn 0xxxxxxxxx.
        $recipientPhone = VietnamPhone::normalize($input['recipient_phone']);
        if ($recipientPhone === null) {
            response_json(['status' => 'error', 'message' => 'Số điện thoại phải là số di động Việt Nam 10 chữ số (03, 05, 07, 08, 09)'], 422);
        }

        // Không gửi payment_method thì coi như thanh toán khi nhận hàng (giữ tương thích với FE cũ).
        $paymentMethod = $input['payment_method'] ?? 'cod';
        if (!is_string($paymentMethod) || !in_array($paymentMethod, ['cod', 'sepay'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức thanh toán không hợp lệ'], 422);
        }

        $expectedTotal = $this->readExpectedTotal($input);

        $result = $this->order->checkout(
            $userId, $recipientName, $recipientPhone, $shippingAddress, $paymentMethod, $expectedTotal
        );
        if (($result['status'] ?? '') === 'empty_cart') {
            response_json(['status' => 'error', 'message' => 'Giỏ hàng đang trống'], 422);
        }
        if (($result['status'] ?? '') === 'stock_unavailable') {
            response_json(['status' => 'error', 'message' => 'Tồn kho đã thay đổi, vui lòng kiểm tra lại giỏ hàng'], 409);
        }
        if (($result['status'] ?? '') === 'price_changed') {
            $this->respondPriceChanged($result['total_amount']);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đặt hàng thành công',
            'order' => [
                'id' => $result['order_id'],
                'total_amount' => $result['total_amount'],
                'order_status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => $paymentMethod,
            ],
        ], 201);
    }

    /**
     * Tạo đơn từ đơn nháp do trợ lý AI lên (danh sách SKU + số lượng), khách đã kiểm tra và bấm xác nhận.
     * Không đụng tới giỏ hàng. Giá và tồn kho được tính lại trong transaction, không tin giá FE gửi lên.
     *
     * Method: POST. Body JSON:
     * - items (bắt buộc): mảng 1-10 phần tử { sku: string 1-100 ký tự, quantity: số nguyên 1-99 }, SKU không trùng
     * - recipient_name, recipient_phone, shipping_address (bắt buộc): cùng quy tắc với api-order-checkout
     * - payment_method (không bắt buộc): 'cod' (mặc định) hoặc 'sepay'
     * - expected_total (không bắt buộc): tổng tiền khách đang thấy, dùng để phát hiện giá đã đổi
     *
     * Thành công 201: { status, message, order: { id, total_amount, order_status, payment_status, payment_method } }
     * Lỗi: 405 sai method, 400 body sai kiểu, 401 thiếu/sai token (requireAuth ở router),
     *      422 dữ liệu không hợp lệ, 409 hết hàng/ngừng bán hoặc giá đã thay đổi (kèm total_amount mới).
     *
     * @param array $authPayload Payload JWT từ middleware. User ID lấy từ sub, không lấy từ body.
     * @return void
     */
    public function checkoutItems(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_array($input['items'] ?? null)
            || !is_string($input['recipient_name'] ?? null)
            || !is_string($input['recipient_phone'] ?? null)
            || !is_string($input['shipping_address'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu đơn hàng không hợp lệ'], 400);
        }

        // Kiểm tra danh sách món: đúng kiểu, không trùng SKU, số lượng trong giới hạn.
        $rawItems = $input['items'];
        if ($rawItems === [] || count($rawItems) > Order::MAX_DIRECT_ITEMS) {
            response_json(['status' => 'error', 'message' => 'Đơn hàng phải có từ 1 đến ' . Order::MAX_DIRECT_ITEMS . ' món'], 422);
        }
        $items = [];
        $seenSkus = [];
        foreach ($rawItems as $rawItem) {
            $sku = is_array($rawItem) && is_string($rawItem['sku'] ?? null) ? trim($rawItem['sku']) : '';
            // Chỉ nhận số nguyên thật; is_int loại cả "2" lẫn 2.5 và true.
            $quantity = is_array($rawItem) ? ($rawItem['quantity'] ?? null) : null;
            if ($sku === '' || strlen($sku) > 100 || !is_int($quantity)
                || $quantity < 1 || $quantity > Order::MAX_ITEM_QUANTITY) {
                response_json(['status' => 'error', 'message' => 'Món hàng hoặc số lượng không hợp lệ'], 422);
            }
            // So trùng theo chữ thường vì SKU trong DB không phân biệt hoa thường.
            $skuKey = mb_strtolower($sku, 'UTF-8');
            if (isset($seenSkus[$skuKey])) {
                response_json(['status' => 'error', 'message' => 'Một món hàng bị lặp trong đơn'], 422);
            }
            $seenSkus[$skuKey] = true;
            $items[] = ['sku' => $sku, 'quantity' => $quantity];
        }

        // Thông tin người nhận: cùng quy tắc với api-order-checkout.
        $recipientName = trim($input['recipient_name']);
        $shippingAddress = trim($input['shipping_address']);
        if ($recipientName === '' || strlen($recipientName) > 100
            || $shippingAddress === '' || strlen($shippingAddress) > 500) {
            response_json(['status' => 'error', 'message' => 'Thông tin giao hàng không hợp lệ'], 422);
        }
        $recipientPhone = VietnamPhone::normalize($input['recipient_phone']);
        if ($recipientPhone === null) {
            response_json(['status' => 'error', 'message' => 'Số điện thoại phải là số di động Việt Nam 10 chữ số (03, 05, 07, 08, 09)'], 422);
        }

        $paymentMethod = $input['payment_method'] ?? 'cod';
        if (!is_string($paymentMethod) || !in_array($paymentMethod, ['cod', 'sepay'], true)) {
            response_json(['status' => 'error', 'message' => 'Phương thức thanh toán không hợp lệ'], 422);
        }

        $expectedTotal = $this->readExpectedTotal($input);

        $result = $this->order->checkoutItems(
            $userId, $items, $recipientName, $recipientPhone, $shippingAddress, $paymentMethod, $expectedTotal
        );
        $status = $result['status'] ?? '';
        if ($status === 'stock_unavailable') {
            response_json([
                'status' => 'error',
                'message' => 'Có món đã hết hàng, ngừng bán hoặc không đủ số lượng. Quý khách vui lòng nhờ trợ lý lên lại đơn.',
            ], 409);
        }
        if ($status === 'price_changed') {
            $this->respondPriceChanged($result['total_amount']);
        }
        if ($status !== 'ok') {
            response_json(['status' => 'error', 'message' => 'Dữ liệu đơn hàng không hợp lệ'], 422);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đặt hàng thành công',
            'order' => [
                'id' => $result['order_id'],
                'total_amount' => $result['total_amount'],
                'order_status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => $paymentMethod,
            ],
        ], 201);
    }

    /**
     * Tra lại giá và tồn kho hiện tại của các món trong đơn nháp AI (trang /ai-checkout).
     * FE gọi khi mở trang và khi đặt hàng bị 409, để cập nhật đơn nháp theo kho/giá mới nhất.
     * Chỉ đọc, không khóa dòng: lúc khách xác nhận, Order::checkoutItems vẫn khóa và kiểm tra lại.
     *
     * Method: GET. Query: skus[]=SKU1&skus[]=SKU2 (mảng 1-10 SKU, mỗi SKU 1-100 ký tự, không trùng).
     * Thành công 200: { status, items: [{ sku, name, variant_name, image, price, original_price, stock, available }] }
     *   - Mỗi SKU gửi lên có đúng một dòng, giữ thứ tự gửi lên.
     *   - SKU không tồn tại hoặc ngừng bán: available = false, stock = 0, các trường còn lại null.
     *   - stock kẹp tối đa Order::MAX_ITEM_QUANTITY, giống đơn nháp do trợ lý lên.
     * Lỗi: 405 sai method, 401 thiếu/sai token (requireAuth ở router), 422 danh sách SKU không hợp lệ.
     *
     * @return void
     */
    public function draftItems(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        // PHP tự gom skus[]=A&skus[]=B thành mảng ['A', 'B']; gửi skus=A (không có []) thì là chuỗi, coi như sai.
        $rawSkus = $_GET['skus'] ?? null;
        if (!is_array($rawSkus) || $rawSkus === [] || count($rawSkus) > Order::MAX_DIRECT_ITEMS) {
            response_json(['status' => 'error', 'message' => 'Danh sách món phải có từ 1 đến ' . Order::MAX_DIRECT_ITEMS . ' SKU'], 422);
        }

        $skus = [];
        $seenSkus = [];
        foreach ($rawSkus as $rawSku) {
            $sku = is_string($rawSku) ? trim($rawSku) : '';
            if ($sku === '' || strlen($sku) > 100) {
                response_json(['status' => 'error', 'message' => 'SKU không hợp lệ'], 422);
            }
            // So trùng theo chữ thường vì SKU trong DB không phân biệt hoa thường.
            $skuKey = mb_strtolower($sku, 'UTF-8');
            if (isset($seenSkus[$skuKey])) {
                response_json(['status' => 'error', 'message' => 'Một món hàng bị lặp trong danh sách'], 422);
            }
            $seenSkus[$skuKey] = true;
            $skus[] = $sku;
        }

        // Trả lại tồn kho của các đơn SePay quá hạn chưa thanh toán trước khi đọc, giống bước tạo đơn,
        // để không báo "hết hàng" oan cho món mà lúc xác nhận server vẫn cho đặt.
        Order::releaseExpiredStock();

        // Chỉ biến thể đang bán mới có trong kết quả; key là SKU viết thường để khớp với SKU khách gửi.
        $variants = (new Product())->getActiveVariantsBySkus($skus);

        $items = [];
        foreach ($skus as $sku) {
            $variant = $variants[mb_strtolower($sku, 'UTF-8')] ?? null;
            if ($variant === null) {
                // Không tìm thấy hoặc ngừng bán: vẫn trả một dòng để FE biết món này không đặt được.
                $items[] = [
                    'sku' => $sku,
                    'name' => null,
                    'variant_name' => null,
                    'image' => null,
                    'price' => null,
                    'original_price' => null,
                    'stock' => 0,
                    'available' => false,
                ];
                continue;
            }

            // Dựng theo cùng quy tắc với tool AI lên đơn nháp (Product::buildDraftItem), để hai nơi không lệch nhau.
            $item = Product::buildDraftItem($variant);
            $item['available'] = $item['stock'] > 0;
            $items[] = $item;
        }

        response_json(['status' => 'success', 'items' => $items], 200);
    }

    /**
     * Tạo dữ liệu form thanh toán SePay cho đơn online (payment_method = sepay) còn pending/unpaid của user.
     * Lưu mã hóa đơn của lần thanh toán này để sau đó hỏi lại SePay khi người dùng quay về.
     * Sai method trả 405, đơn không tồn tại trả 404, đơn COD hoặc sai trạng thái trả 409.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function createPayment(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $orderId = $this->getOrderId();
        // Hủy các đơn online quá hạn trước, để đơn vừa hết hạn không còn được phép thanh toán.
        $this->order->expireStaleOnlineOrders(SEPAY_PAYMENT_TIMEOUT_MINUTES);
        $invoice = $this->order->getInvoice($orderId, $userId);
        if ($invoice === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        $order = $invoice['order'];
        if ($order['payment_method'] !== 'sepay') {
            response_json(['status' => 'error', 'message' => 'Đơn hàng này không dùng thanh toán online'], 409);
        }
        if ($order['order_status'] !== 'pending' || $order['payment_status'] !== 'unpaid') {
            response_json(['status' => 'error', 'message' => 'Đơn hàng không ở trạng thái chờ thanh toán'], 409);
        }

        if (SEPAY_ENVIRONMENT !== SePayClient::ENVIRONMENT_SANDBOX) {
            response_json(['status' => 'error', 'message' => 'Tích hợp hiện chỉ hỗ trợ môi trường sandbox'], 503);
        }
        if (SEPAY_MERCHANT_ID === '' || SEPAY_SECRET_KEY === '') {
            response_json(['status' => 'error', 'message' => 'Chưa cấu hình thông tin merchant SePay'], 503);
        }

        foreach ([SEPAY_SUCCESS_URL, SEPAY_ERROR_URL, SEPAY_CANCEL_URL] as $returnUrl) {
            if (filter_var($returnUrl, FILTER_VALIDATE_URL) === false) {
                response_json(['status' => 'error', 'message' => 'URL quay về từ SePay chưa được cấu hình hợp lệ'], 503);
            }
        }

        $amountText = (string) $order['total_amount'];
        // Giá VND trong bảng phải là số nguyên; regex này chỉ chấp nhận dạng 123 hoặc 123.00.
        if (!preg_match('/^([0-9]+)(?:\\.00)?$/D', $amountText, $amountMatches)) {
            response_json(['status' => 'error', 'message' => 'Số tiền đơn hàng không hợp lệ'], 422);
        }
        $amount = (int) $amountMatches[1];
        if ($amount < 1) {
            response_json(['status' => 'error', 'message' => 'Số tiền đơn hàng không hợp lệ'], 422);
        }

        // Mã ngẫu nhiên giúp mỗi lần thử có invoice riêng; phần ID đơn vẫn giữ để đối soát sau này.
        $invoiceNumber = 'FULE-' . $orderId . '-' . strtoupper(bin2hex(random_bytes(4)));
        // Lưu mã hóa đơn trước khi chuyển sang SePay để bước xác minh sau này biết cần hỏi SePay mã nào.
        if (!$this->order->setPaymentInvoice($orderId, $userId, $invoiceNumber)) {
            response_json(['status' => 'error', 'message' => 'Đơn hàng không ở trạng thái chờ thanh toán'], 409);
        }
        $checkoutData = CheckoutBuilder::make()
            ->currency('VND')
            ->orderAmount($amount)
            ->operation('PURCHASE')
            ->orderDescription('Thanh toan don hang #' . $orderId)
            ->orderInvoiceNumber($invoiceNumber)
            ->customerId((string) $userId)
            // Gắn ID đơn vào URL quay về để trang kết quả biết cần hiển thị đơn nào.
            ->successUrl($this->appendOrderId(SEPAY_SUCCESS_URL, $orderId))
            ->errorUrl($this->appendOrderId(SEPAY_ERROR_URL, $orderId))
            ->cancelUrl($this->appendOrderId(SEPAY_CANCEL_URL, $orderId))
            ->build();

        $sepay = new SePayClient(
            SEPAY_MERCHANT_ID,
            SEPAY_SECRET_KEY,
            SePayClient::ENVIRONMENT_SANDBOX
        );
        $checkout = $sepay->checkout();

        response_json([
            'status' => 'success',
            'checkout' => [
                'action' => $checkout->getCheckoutUrl(SePayClient::ENVIRONMENT_SANDBOX),
                'fields' => $checkout->generateFormFields($checkoutData),
            ],
        ]);
    }

    /**
     * Kiểm tra trạng thái thanh toán của đơn bằng cách hỏi lại SePay, dùng khi người dùng quay về từ cổng thanh toán.
     * Cần thiết vì IPN có thể đến chậm hoặc không đến được (ví dụ chạy local, không có địa chỉ công khai).
     * Chỉ tin dữ liệu từ SePay (gọi bằng khóa merchant của server), không tin tham số do trình duyệt gửi.
     * Sai method trả 405, đơn không tồn tại trả 404, SePay không phản hồi trả 502, chưa cấu hình SePay trả 503.
     *
     * @param array $authPayload Payload JWT từ middleware.
     * @return void
     */
    public function verifyPayment(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getUserId($authPayload);
        $orderId = $this->getOrderId();
        $this->order->expireStaleOnlineOrders(SEPAY_PAYMENT_TIMEOUT_MINUTES);
        $info = $this->order->getPaymentInfo($orderId, $userId);
        if ($info === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy đơn hàng'], 404);
        }

        // Chỉ cần hỏi SePay khi đơn online còn chờ thanh toán và đã từng tạo form thanh toán.
        $needsCheck = $info['payment_method'] === 'sepay'
            && $info['order_status'] === 'pending'
            && $info['payment_status'] === 'unpaid'
            && !empty($info['payment_invoice_number']);
        if ($needsCheck) {
            if (SEPAY_MERCHANT_ID === '' || SEPAY_SECRET_KEY === '') {
                response_json(['status' => 'error', 'message' => 'Chưa cấu hình thông tin merchant SePay'], 503);
            }

            try {
                $sepay = new SePayClient(SEPAY_MERCHANT_ID, SEPAY_SECRET_KEY, SePayClient::ENVIRONMENT_SANDBOX);
                $detail = $sepay->orders()->retrieve((string) $info['payment_invoice_number']);
                // Response SePay bọc dữ liệu trong khóa "data"; fallback phòng khi SDK đã bóc sẵn.
                $data = is_array($detail['data'] ?? null) ? $detail['data'] : $detail;
                // CAPTURED = SePay đã thu tiền thành công. Mã hóa đơn phải đúng mã ta đã lưu.
                if (($data['order_status'] ?? '') === 'CAPTURED'
                    && ($data['order_invoice_number'] ?? '') === $info['payment_invoice_number']) {
                    $result = $this->order->markPaidBySePay($orderId, (string) ($data['order_amount'] ?? '0'));
                    if ($result === 'amount_mismatch') {
                        error_log('SePay báo số tiền không khớp cho đơn #' . $orderId);
                    }
                }
            } catch (SePayException $exception) {
                // SePay trả 404 khi chưa có đơn với mã này: người dùng chưa hoàn tất thanh toán, không phải lỗi.
                // SDK không đổi 404 thành NotFoundException nên phải kiểm tra thêm mã HTTP (getCode).
                if (!($exception instanceof NotFoundException) && $exception->getCode() !== 404) {
                    error_log('Không hỏi được SePay cho đơn #' . $orderId . ': ' . $exception->getMessage());
                    response_json(['status' => 'error', 'message' => 'Không kết nối được SePay, vui lòng thử lại sau'], 502);
                }
            }

            $info = $this->order->getPaymentInfo($orderId, $userId) ?? $info;
        }

        response_json([
            'status' => 'success',
            'order' => [
                'id' => (int) $info['id'],
                'order_status' => $info['order_status'],
                'payment_status' => $info['payment_status'],
                'payment_method' => $info['payment_method'],
            ],
        ]);
    }

    /**
     * Nhận IPN (thông báo kết quả thanh toán) do SePay gọi tới server. Endpoint công khai, KHÔNG có JWT.
     * Xác thực bằng header X-Secret-Key phải khớp SEPAY_IPN_SECRET (so sánh hash_equals để tránh dò khóa theo thời gian).
     * Chỉ xử lý ORDER_PAID với order_status CAPTURED; mọi thông báo khác trả 200 và bỏ qua để SePay không gửi lại mãi.
     * Sai method trả 405, thiếu cấu hình secret trả 503, sai secret trả 401, body sai trả 400.
     *
     * @return void
     */
    public function sepayIpn(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        if (SEPAY_IPN_SECRET === '') {
            response_json(['status' => 'error', 'message' => 'Chưa cấu hình SEPAY_IPN_SECRET'], 503);
        }

        $providedSecret = $_SERVER['HTTP_X_SECRET_KEY'] ?? '';
        if (!is_string($providedSecret) || !hash_equals(SEPAY_IPN_SECRET, $providedSecret)) {
            response_json(['status' => 'error', 'message' => 'Xác thực IPN thất bại'], 401);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu IPN không hợp lệ'], 400);
        }

        $order = $input['order'] ?? null;
        if (($input['notification_type'] ?? '') !== 'ORDER_PAID'
            || !is_array($order)
            || ($order['order_status'] ?? '') !== 'CAPTURED') {
            response_json(['status' => 'success', 'message' => 'Bỏ qua thông báo này']);
        }

        // Mã hóa đơn do createPayment tạo có dạng FULE-<ID đơn>-<8 ký tự hex>; tách ID đơn từ đó.
        $invoiceNumber = (string) ($order['order_invoice_number'] ?? '');
        if (!preg_match('/^FULE-([0-9]+)-[0-9A-F]{8}$/D', $invoiceNumber, $matches)) {
            response_json(['status' => 'success', 'message' => 'Mã hóa đơn không thuộc hệ thống']);
        }

        $orderId = (int) $matches[1];
        $result = $this->order->markPaidBySePay($orderId, (string) ($order['order_amount'] ?? '0'));
        if (in_array($result, ['not_found', 'not_payable', 'amount_mismatch'], true)) {
            // Đã thu tiền nhưng không ghi nhận được (ví dụ đơn đã bị hủy do quá hạn): cần người xử lý/hoàn tiền thủ công.
            error_log('SePay IPN không ghi nhận được thanh toán cho đơn #' . $orderId . ': ' . $result);
        }

        response_json(['status' => 'success', 'message' => 'Đã nhận thông báo']);
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
        // Tự hủy đơn online quá hạn trước khi liệt kê để trạng thái hiển thị luôn đúng.
        $this->order->expireStaleOnlineOrders(SEPAY_PAYMENT_TIMEOUT_MINUTES);
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
        $this->order->expireStaleOnlineOrders(SEPAY_PAYMENT_TIMEOUT_MINUTES);
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
     * Đọc expected_total (tổng tiền khách đang thấy) từ body, dùng chung cho api-order-checkout và api-order-checkout-items.
     * Không bắt buộc: thiếu hoặc null thì trả null (không đối chiếu giá).
     * Nhận số (1449000) hoặc chuỗi số ("1449000.00"), tối đa 2 chữ số thập phân. Sai định dạng thì dừng với 422.
     *
     * @param array $input Body JSON đã decode.
     * @return string|null Chuỗi số đã kiểm tra, hoặc null nếu không gửi.
     */
    private function readExpectedTotal(array $input = []): ?string
    {
        if (!array_key_exists('expected_total', $input) || $input['expected_total'] === null) {
            return null;
        }

        $rawTotal = $input['expected_total'];
        $totalText = is_int($rawTotal) ? (string) $rawTotal
            : (is_float($rawTotal) ? number_format($rawTotal, 2, '.', '') : (is_string($rawTotal) ? trim($rawTotal) : ''));
        // Regex đảm bảo chuỗi chỉ gồm chữ số và tối đa một dấu chấm trước khi model đổi sang đơn vị xu.
        if (!preg_match('/^\d{1,12}(\.\d{1,2})?$/', $totalText)) {
            response_json(['status' => 'error', 'message' => 'Tổng tiền đối chiếu không hợp lệ'], 422);
        }

        return $totalText;
    }

    /**
     * Trả 409 khi giá đã đổi so với tổng khách đang thấy, kèm tổng mới để FE hiển thị.
     * Dùng chung cho api-order-checkout và api-order-checkout-items để câu báo giống nhau.
     *
     * @param string $totalAmount Tổng tiền mới do server tính lại (chuỗi DECIMAL).
     * @return void
     */
    private function respondPriceChanged(string $totalAmount = '0'): void
    {
        response_json([
            'status' => 'error',
            'message' => 'Giá sản phẩm vừa thay đổi. Tổng mới là ' . number_format((float) $totalAmount, 0, ',', '.')
                . 'đ, quý khách vui lòng kiểm tra lại trước khi đặt.',
            'total_amount' => $totalAmount,
        ], 409);
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
     * Gắn ?order=<ID> (hoặc &order=<ID>) vào URL quay về từ SePay.
     *
     * @param string $url URL gốc đã cấu hình.
     * @param int $orderId ID đơn hàng.
     * @return string URL có tham số order.
     */
    private function appendOrderId(string $url = '', int $orderId = 0): string
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'order=' . $orderId;
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