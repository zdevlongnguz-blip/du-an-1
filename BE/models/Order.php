<?php
/** Mô hình xử lý đơn hàng, tồn kho và dữ liệu hóa đơn. */
class Order extends BaseModel
{
    protected $table = 'orders';

    /**
     * Tạo đơn từ giỏ: chụp snapshot (tên, SKU, tên biến thể, giá), trừ tồn kho của
     * từng biến thể và cộng sales_count của sản phẩm, tất cả trong một transaction.
     * Transaction: nhóm nhiều câu SQL thành một khối, lỗi ở đâu thì hoàn tác toàn bộ.
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

            // Giá và tồn kho nằm ở product_variants; products chỉ cho tên và id sản phẩm.
            // FOR UPDATE khóa các dòng này đến hết transaction để hai đơn cùng lúc không đặt trùng hàng.
            // Sắp xếp theo ID biến thể để mọi giao dịch khóa theo cùng thứ tự, tránh deadlock
            // (hai giao dịch chờ nhau mãi).
            $itemsStatement = $this->pdo->prepare(
                'SELECT `cart_items`.`variant_id`, `cart_items`.`quantity`, '
                . '`product_variants`.`product_id`, `product_variants`.`sku`, `product_variants`.`variant_name`, '
                . '`product_variants`.`final_price`, `product_variants`.`stock`, `product_variants`.`is_active`, '
                . '`products`.`name` '
                . 'FROM `cart_items` '
                . 'INNER JOIN `product_variants` ON `product_variants`.`id` = `cart_items`.`variant_id` '
                . 'INNER JOIN `products` ON `products`.`id` = `product_variants`.`product_id` '
                . 'WHERE `cart_items`.`cart_id` = :cart_id ORDER BY `cart_items`.`variant_id` ASC FOR UPDATE'
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
                // Biến thể ngừng bán hoặc không đủ hàng thì không cho đặt.
                if ($quantity < 1 || (int) $item['is_active'] !== 1 || $stock < $quantity) {
                    $this->pdo->rollBack();
                    return ['status' => 'stock_unavailable'];
                }

                // final_price là giá thực tế (giá giảm nếu có, không thì giá gốc) do DB tự tính.
                $unitPrice = (string) $item['final_price'];
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
                . '(`order_id`, `product_id`, `variant_id`, `product_name`, `variant_sku`, `variant_label`, '
                . '`quantity`, `unit_price`) '
                . 'VALUES (:order_id, :product_id, :variant_id, :product_name, :variant_sku, :variant_label, '
                . ':quantity, :unit_price)'
            );
            // Trừ kho nguyên tử: điều kiện stock >= số lượng nằm ngay trong câu UPDATE,
            // nên nếu có người mua trước làm hết hàng thì rowCount = 0 và ta hoàn tác đơn.
            $stockStatement = $this->pdo->prepare(
                'UPDATE `product_variants` SET `stock` = `stock` - :quantity '
                . 'WHERE `id` = :variant_id AND `stock` >= :required_quantity AND `is_active` = 1'
            );
            // sales_count là tổng lượt bán của sản phẩm (cộng dồn mọi biến thể).
            $salesStatement = $this->pdo->prepare(
                'UPDATE `products` SET `sales_count` = `sales_count` + :sold_quantity WHERE `id` = :product_id'
            );

            foreach ($items as $item) {
                $quantity = (int) $item['quantity'];
                $productId = (int) $item['product_id'];
                $variantId = (int) $item['variant_id'];
                $detailStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $detailStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $detailStatement->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
                $detailStatement->bindValue(':product_name', $item['name'], PDO::PARAM_STR);
                $detailStatement->bindValue(':variant_sku', $item['sku'], PDO::PARAM_STR);
                $detailStatement->bindValue(':variant_label', $item['variant_name'], PDO::PARAM_STR);
                $detailStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $detailStatement->bindValue(':unit_price', $item['unit_price'], PDO::PARAM_STR);
                $detailStatement->execute();

                $stockStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
                $stockStatement->bindValue(':required_quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->execute();
                if ($stockStatement->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return ['status' => 'stock_unavailable'];
                }

                $salesStatement->bindValue(':sold_quantity', $quantity, PDO::PARAM_INT);
                $salesStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
                $salesStatement->execute();
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
     * Mỗi dòng hàng gồm snapshot tên sản phẩm, SKU và tên biến thể lúc mua.
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
            // variant_id/variant_sku/variant_label là snapshot lúc mua, vẫn đọc được dù biến thể bị xóa sau này
            // (variant_id khi đó là NULL). Đơn cũ chưa có biến thể thì variant_label là NULL.
            'SELECT `id`, `product_id`, `variant_id`, `product_name`, `variant_sku`, `variant_label`, '
            . '`quantity`, `unit_price` '
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
     * Hủy đơn pending chưa thanh toán, hoàn kho theo biến thể và trừ lại sales_count.
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
     * Hoàn tồn kho của từng biến thể và trừ lại sales_count của sản phẩm theo chi tiết đơn.
     * Phải gọi trong transaction đang mở của người gọi.
     * Dòng đơn có variant_id/product_id NULL (biến thể hoặc sản phẩm đã bị xóa) thì bỏ qua
     * phần tương ứng vì không còn dòng nào để hoàn.
     *
     * @param int $orderId ID đơn cần hoàn tồn kho.
     * @return void
     */
    private function restoreStock(int $orderId = 0): void
    {
        // Sắp xếp theo variant_id để khóa theo thứ tự cố định, tránh deadlock giữa các giao dịch.
        $detailsStatement = $this->pdo->prepare(
            'SELECT `product_id`, `variant_id`, `quantity` FROM `order_details` '
            . 'WHERE `order_id` = :order_id ORDER BY `variant_id` ASC, `id` ASC'
        );
        $detailsStatement->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $detailsStatement->execute();
        $details = $detailsStatement->fetchAll(PDO::FETCH_ASSOC);

        // Hoàn kho không kiểm tra is_active: biến thể ngừng bán sau khi đặt vẫn phải được trả lại số hàng.
        $stockStatement = $this->pdo->prepare(
            'UPDATE `product_variants` SET `stock` = `stock` + :quantity WHERE `id` = :variant_id'
        );
        // GREATEST(..., 0): sales_count là số không âm (UNSIGNED), không để trừ xuống dưới 0.
        $salesStatement = $this->pdo->prepare(
            'UPDATE `products` SET `sales_count` = GREATEST(CAST(`sales_count` AS SIGNED) - :sold_quantity, 0) '
            . 'WHERE `id` = :product_id'
        );
        foreach ($details as $detail) {
            $quantity = (int) $detail['quantity'];
            if ($detail['variant_id'] !== null) {
                $stockStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
                $stockStatement->bindValue(':variant_id', (int) $detail['variant_id'], PDO::PARAM_INT);
                $stockStatement->execute();
            }
            if ($detail['product_id'] !== null) {
                $salesStatement->bindValue(':sold_quantity', $quantity, PDO::PARAM_INT);
                $salesStatement->bindValue(':product_id', (int) $detail['product_id'], PDO::PARAM_INT);
                $salesStatement->execute();
            }
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