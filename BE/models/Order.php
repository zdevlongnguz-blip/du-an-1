<?php
/** Mô hình xử lý đơn hàng, tồn kho và dữ liệu hóa đơn. */
class Order extends BaseModel
{
    protected $table = 'orders';

    /**
    * Tạo đơn từ giỏ, chụp giá/tên, trừ tồn kho và cộng sales_count đồng thời.
     *
     * @param int $userId ID user lấy từ JWT.
     * @param string $recipientName Tên người nhận.
     * @param string $recipientPhone Số điện thoại người nhận.
     * @param string $shippingAddress Địa chỉ giao hàng.
     * @return array Kết quả ok/order_id/total_amount, empty_cart hoặc stock_unavailable.
     */
    public function checkout(
        int $userId = 0,
        string $recipientName = '',
        string $recipientPhone = '',
        string $shippingAddress = ''
    ): array {
        if ($userId < 1) {
            return ['status' => 'invalid_user'];
        }

        $this->pdo->beginTransaction();
        try {
            $cartStatement = $this->pdo->prepare(
                'SELECT `id` FROM `carts` WHERE `user_id` = :user_id LIMIT 1 FOR UPDATE'
            );
            $cartStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $cartStatement->execute();
            $cartId = $cartStatement->fetchColumn();

            if ($cartId === false) {
                $this->pdo->rollBack();
                return ['status' => 'empty_cart'];
            }

            $itemsStatement = $this->pdo->prepare(
                'SELECT `cart_items`.`product_id`, `cart_items`.`quantity`, `products`.`name`, '
                . '`products`.`price`, `products`.`sale_price`, `products`.`stock` '
                . 'FROM `cart_items` INNER JOIN `products` ON `products`.`id` = `cart_items`.`product_id` '
                . 'WHERE `cart_items`.`cart_id` = :cart_id ORDER BY `cart_items`.`id` ASC FOR UPDATE'
            );
            $itemsStatement->bindValue(':cart_id', (int) $cartId, PDO::PARAM_INT);
            $itemsStatement->execute();
            $items = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);

            if ($items === []) {
                $this->pdo->rollBack();
                return ['status' => 'empty_cart'];
            }

            $totalCents = 0;
            foreach ($items as &$item) {
                $quantity = (int) $item['quantity'];
                $stock = (int) ($item['stock'] ?? 0);
                if ($quantity < 1 || $stock < $quantity) {
                    $this->pdo->rollBack();
                    return ['status' => 'stock_unavailable'];
                }

                $unitPrice = (string) $item['price'];
                if ($item['sale_price'] !== null
                    && (float) $item['sale_price'] > 0
                    && (float) $item['sale_price'] < (float) $item['price']) {
                    $unitPrice = (string) $item['sale_price'];
                }

                $item['unit_price'] = $unitPrice;
                $totalCents += $this->toCents($unitPrice) * $quantity;
            }
            unset($item);

            $totalAmount = $this->fromCents($totalCents);
            $orderStatement = $this->pdo->prepare(
                'INSERT INTO `orders` '
                . '(`user_id`, `recipient_name`, `recipient_phone`, `shipping_address`, `total_amount`) '
                . 'VALUES (:user_id, :recipient_name, :recipient_phone, :shipping_address, :total_amount)'
            );
            $orderStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $orderStatement->bindValue(':recipient_name', $recipientName, PDO::PARAM_STR);
            $orderStatement->bindValue(':recipient_phone', $recipientPhone, PDO::PARAM_STR);
            $orderStatement->bindValue(':shipping_address', $shippingAddress, PDO::PARAM_STR);
            $orderStatement->bindValue(':total_amount', $totalAmount, PDO::PARAM_STR);
            $orderStatement->execute();
            $orderId = (int) $this->pdo->lastInsertId();

            $detailStatement = $this->pdo->prepare(
                'INSERT INTO `order_details` '
                . '(`order_id`, `product_id`, `product_name`, `quantity`, `unit_price`) '
                . 'VALUES (:order_id, :product_id, :product_name, :quantity, :unit_price)'
            );
            $stockStatement = $this->pdo->prepare(
                'UPDATE `products` SET `stock` = `stock` - :quantity, '
                . '`sales_count` = COALESCE(`sales_count`, 0) + :sold_quantity '
                . 'WHERE `id` = :product_id AND `stock` >= :required_quantity'
            );

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $productId = (int) $item['product_id'];
                $detailStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $detailStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $detailStatement->bindValue(':product_name', $item['name'], PDO::PARAM_STR);
                $detailStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $detailStatement->bindValue(':unit_price', $item['unit_price'], PDO::PARAM_STR);
                $detailStatement->execute();

                $stockStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->bindValue(':sold_quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $stockStatement->bindValue(':required_quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->execute();
                if ($stockStatement->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return ['status' => 'stock_unavailable'];
                }
            }

            $clearCartStatement = $this->pdo->prepare(
                'DELETE FROM `cart_items` WHERE `cart_id` = :cart_id'
            );
            $clearCartStatement->bindValue(':cart_id', (int) $cartId, PDO::PARAM_INT);
            $clearCartStatement->execute();
            $this->pdo->commit();

            return [
                'status' => 'ok',
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Lấy danh sách đơn theo user và phân trang; userId null nghĩa là lấy tất cả cho admin.
     *
     * @param int|null $userId ID user cần lọc, null để lấy toàn bộ.
     * @param int $page Trang bắt đầu từ 1.
     * @param int $perPage Số đơn tối đa mỗi trang.
     * @return array Danh sách đơn và thông tin phân trang.
     */
    public function getList(?int $userId = null, int $page = 1, int $perPage = 10): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $whereSql = $userId === null ? '' : ' WHERE `user_id` = :user_id';

        $countStatement = $this->pdo->prepare('SELECT COUNT(*) FROM `orders`' . $whereSql);
        if ($userId !== null) {
            $countStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $countStatement->execute();
        $totalItems = (int) $countStatement->fetchColumn();

        $listStatement = $this->pdo->prepare(
            'SELECT `id`, `user_id`, `recipient_name`, `recipient_phone`, `shipping_address`, '
            . '`total_amount`, `order_status`, `payment_status`, `created_at`, `updated_at` '
            . 'FROM `orders`' . $whereSql . ' ORDER BY `created_at` DESC, `id` DESC LIMIT :limit OFFSET :offset'
        );
        if ($userId !== null) {
            $listStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStatement->execute();

        return [
            'data' => $listStatement->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_items' => $totalItems,
                'total_pages' => $totalItems > 0 ? (int) ceil($totalItems / $perPage) : 1,
            ],
        ];
    }

    /**
     * Lấy chi tiết đơn làm dữ liệu hóa đơn; user chỉ xem đơn của mình.
     *
     * @param int $orderId ID đơn hàng.
     * @param int $userId ID user lấy từ JWT.
     * @param bool $isAdmin True nếu route đã xác thực admin.
     * @return array|null Thông tin đơn và snapshot dòng hàng, hoặc null nếu không có quyền/tồn tại.
     */
    public function getInvoice(int $orderId = 0, int $userId = 0, bool $isAdmin = false): ?array
    {
        $sql = 'SELECT `id`, `user_id`, `recipient_name`, `recipient_phone`, `shipping_address`, '
            . '`total_amount`, `order_status`, `payment_status`, `created_at`, `updated_at` '
            . 'FROM `orders` WHERE `id` = :order_id';
        if (!$isAdmin) {
            $sql .= ' AND `user_id` = :user_id';
        }

        $orderStatement = $this->pdo->prepare($sql . ' LIMIT 1');
        $orderStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        if (!$isAdmin) {
            $orderStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $orderStatement->execute();
        $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

        if ($order === false) {
            return null;
        }

        $detailsStatement = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `product_name`, `quantity`, `unit_price` '
            . 'FROM `order_details` WHERE `order_id` = :order_id ORDER BY `id` ASC'
        );
        $detailsStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $detailsStatement->execute();
        $details = $detailsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($details as &$detail) {
            $detail['line_total'] = $this->fromCents(
                $this->toCents((string) $detail['unit_price']) * (int) $detail['quantity']
            );
        }
        unset($detail);

        return ['order' => $order, 'items' => $details];
    }

    /**
    * Hủy đơn pending chưa thanh toán, hoàn kho và trừ lại sales_count.
     *
     * @param int $orderId ID đơn hàng.
     * @param int $userId ID user lấy từ JWT.
     * @return string Kết quả: ok, not_found hoặc not_cancellable.
     */
    public function cancelByUser(int $orderId = 0, int $userId = 0): string
    {
        if ($orderId < 1 || $userId < 1) {
            return 'not_found';
        }

        $this->pdo->beginTransaction();
        try {
            $orderStatement = $this->pdo->prepare(
                'SELECT `order_status`, `payment_status` FROM `orders` '
                . 'WHERE `id` = :order_id AND `user_id` = :user_id LIMIT 1 FOR UPDATE'
            );
            $orderStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $orderStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $orderStatement->execute();
            $order = $orderStatement->fetch(PDO::FETCH_ASSOC);

            if ($order === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }
            if ($order['order_status'] !== 'pending' || $order['payment_status'] !== 'unpaid') {
                $this->pdo->rollBack();
                return 'not_cancellable';
            }

            $this->restoreStock($orderId);
            $updateStatement = $this->pdo->prepare(
                'UPDATE `orders` SET `order_status` = \'cancelled\' '
                . 'WHERE `id` = :order_id AND `user_id` = :user_id AND `order_status` = \'pending\' '
                . 'AND `payment_status` = \'unpaid\''
            );
            $updateStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $updateStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $updateStatement->execute();
            $this->pdo->commit();

            return 'ok';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Cập nhật trạng thái đơn/thanh toán sau khi kiểm tra luồng chuyển hợp lệ.
     *
     * @param int $orderId ID đơn hàng.
     * @param string $orderStatus Trạng thái đơn mới.
     * @param string $paymentStatus Trạng thái thanh toán mới.
     * @return string Kết quả: ok, not_found hoặc invalid_transition.
     */
    public function updateByAdmin(
        int $orderId = 0,
        string $orderStatus = 'pending',
        string $paymentStatus = 'unpaid'
    ): string {
        $this->pdo->beginTransaction();
        try {
            $orderStatement = $this->pdo->prepare(
                'SELECT `order_status`, `payment_status` FROM `orders` '
                . 'WHERE `id` = :order_id LIMIT 1 FOR UPDATE'
            );
            $orderStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $orderStatement->execute();
            $current = $orderStatement->fetch(PDO::FETCH_ASSOC);

            if ($current === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }

            $allowedNextStatuses = [
                'pending' => ['pending', 'confirmed', 'cancelled'],
                'confirmed' => ['confirmed', 'shipping', 'cancelled'],
                'shipping' => ['shipping', 'delivered'],
                'delivered' => ['delivered'],
                'cancelled' => ['cancelled'],
            ];
            if (!in_array($orderStatus, $allowedNextStatuses[$current['order_status']] ?? [], true)
                || !in_array($paymentStatus, ['unpaid', 'paid'], true)
                || ($current['payment_status'] === 'paid' && $paymentStatus !== 'paid')
                || ($orderStatus === 'cancelled' && $paymentStatus === 'paid')) {
                $this->pdo->rollBack();
                return 'invalid_transition';
            }

            if ($orderStatus === 'cancelled' && $current['order_status'] !== 'cancelled') {
                $this->restoreStock($orderId);
            }

            $updateStatement = $this->pdo->prepare(
                'UPDATE `orders` SET `order_status` = :order_status, `payment_status` = :payment_status '
                . 'WHERE `id` = :order_id'
            );
            $updateStatement->bindValue(':order_status', $orderStatus, PDO::PARAM_STR);
            $updateStatement->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
            $updateStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
            $updateStatement->execute();
            $this->pdo->commit();

            return 'ok';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
    * Hoàn tồn kho và trừ sales_count theo chi tiết đơn trong transaction.
     *
     * @param int $orderId ID đơn cần hoàn tồn kho.
     * @return void
     */
    private function restoreStock(int $orderId = 0): void
    {
        $detailsStatement = $this->pdo->prepare(
            'SELECT `product_id`, `quantity` FROM `order_details` '
            . 'WHERE `order_id` = :order_id AND `product_id` IS NOT NULL'
        );
        $detailsStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $detailsStatement->execute();
        $details = $detailsStatement->fetchAll(PDO::FETCH_ASSOC);

        $stockStatement = $this->pdo->prepare(
            'UPDATE `products` SET `stock` = COALESCE(`stock`, 0) + :quantity, '
            . '`sales_count` = GREATEST(COALESCE(`sales_count`, 0) - :sold_quantity, 0) '
            . 'WHERE `id` = :product_id'
        );
        foreach ($details as $detail) {
            $stockStatement->bindValue(':quantity', (int) $detail['quantity'], PDO::PARAM_INT);
            $stockStatement->bindValue(':sold_quantity', (int) $detail['quantity'], PDO::PARAM_INT);
            $stockStatement->bindValue(':product_id', (int) $detail['product_id'], PDO::PARAM_INT);
            $stockStatement->execute();
        }
    }

    /**
     * Chuyển số DECIMAL thành xu nguyên để tính tổng không qua float.
     *
     * @param string $amount Số tiền dạng DECIMAL.
     * @return int Số xu nguyên.
     */
    private function toCents(string $amount = '0'): int
    {
        $parts = explode('.', $amount, 2);
        $whole = (int) ($parts[0] ?? 0);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');

        return $whole * 100 + (int) $fraction;
    }

    /**
     * Đổi số xu nguyên về chuỗi DECIMAL có hai chữ số thập phân.
     *
     * @param int $cents Số xu nguyên.
     * @return string Số tiền dạng DECIMAL.
     */
    private function fromCents(int $cents = 0): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}