<?php
/** Mô hình truy vấn giỏ hàng và các sản phẩm trong giỏ. */
class Cart extends BaseModel
{
    protected $table = 'carts';

    /**
     * Lấy giỏ hàng của user; chưa có giỏ thì trả danh sách rỗng.
     *
     * @param int $userId ID user lấy từ JWT.
     * @return array Danh sách item và tổng tiền tạm tính.
     */
    public function getByUserId(int $userId = 0): array
    {
        if ($userId < 1) {
            return ['items' => [], 'total_amount' => '0.00'];
        }

        $cartStatement = $this->pdo->prepare(
            'SELECT `id` FROM `carts` WHERE `user_id` = :user_id LIMIT 1'
        );
        $cartStatement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $cartStatement->execute();
        $cartId = $cartStatement->fetchColumn();

        if ($cartId === false) {
            return ['items' => [], 'total_amount' => '0.00'];
        }

        $itemsStatement = $this->pdo->prepare(
            'SELECT `cart_items`.`id`, `cart_items`.`product_id`, `products`.`name`, '
            . '`products`.`image`, `products`.`stock`, `cart_items`.`quantity`, '
            . 'CASE WHEN `products`.`sale_price` IS NOT NULL AND `products`.`sale_price` > 0 '
            . 'AND `products`.`sale_price` < `products`.`price` THEN `products`.`sale_price` '
            . 'ELSE `products`.`price` END AS `unit_price` '
            . 'FROM `cart_items` INNER JOIN `products` ON `products`.`id` = `cart_items`.`product_id` '
            . 'WHERE `cart_items`.`cart_id` = :cart_id ORDER BY `cart_items`.`id` ASC'
        );
        $itemsStatement->bindValue(':cart_id', (int) $cartId, PDO::PARAM_INT);
        $itemsStatement->execute();
        $items = $itemsStatement->fetchAll(PDO::FETCH_ASSOC);

        $totalCents = 0;
        foreach ($items as &$item) {
            $unitPriceCents = $this->toCents((string) $item['unit_price']);
            $item['line_total'] = $this->fromCents($unitPriceCents * (int) $item['quantity']);
            $totalCents += $unitPriceCents * (int) $item['quantity'];
        }
        unset($item);

        return [
            'items' => $items,
            'total_amount' => $this->fromCents($totalCents),
        ];
    }

    /**
     * Thêm sản phẩm vào giỏ; sản phẩm trùng được cộng số lượng.
     *
     * @param int $userId ID user lấy từ JWT.
     * @param int $productId ID sản phẩm.
     * @param int $quantity Số lượng cần thêm.
     * @return string Kết quả: ok, not_found, out_of_stock hoặc stock_exceeded.
     */
    public function addItem(int $userId = 0, int $productId = 0, int $quantity = 1): string
    {
        if ($userId < 1 || $productId < 1 || $quantity < 1) {
            return 'invalid';
        }

        $this->pdo->beginTransaction();
        try {
            $productStatement = $this->pdo->prepare(
                'SELECT `stock` FROM `products` WHERE `id` = :product_id FOR UPDATE'
            );
            $productStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $productStatement->execute();
            $stock = $productStatement->fetchColumn();

            if ($stock === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }
            if ($stock === null || (int) $stock < 1) {
                $this->pdo->rollBack();
                return 'out_of_stock';
            }

            $cartId = $this->getOrCreateCartId($userId);
            $currentStatement = $this->pdo->prepare(
                'SELECT `quantity` FROM `cart_items` WHERE `cart_id` = :cart_id AND `product_id` = :product_id LIMIT 1'
            );
            $currentStatement->bindValue(':cart_id', $cartId, PDO::PARAM_INT);
            $currentStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $currentStatement->execute();
            $currentQuantity = $currentStatement->fetchColumn();

            if ((int) $currentQuantity + $quantity > (int) $stock) {
                $this->pdo->rollBack();
                return 'stock_exceeded';
            }

            $insertStatement = $this->pdo->prepare(
                'INSERT INTO `cart_items` (`cart_id`, `product_id`, `quantity`) '
                . 'VALUES (:cart_id, :product_id, :quantity) '
                . 'ON DUPLICATE KEY UPDATE `quantity` = `quantity` + VALUES(`quantity`)'
            );
            $insertStatement->bindValue(':cart_id', $cartId, PDO::PARAM_INT);
            $insertStatement->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $insertStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
            $insertStatement->execute();
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
     * Đặt lại số lượng item thuộc giỏ của user và kiểm tra tồn kho hiện tại.
     *
     * @param int $userId ID user lấy từ JWT.
     * @param int $itemId ID dòng hàng trong giỏ.
     * @param int $quantity Số lượng mới.
     * @return string Kết quả: ok, not_found hoặc stock_exceeded.
     */
    public function updateItem(int $userId = 0, int $itemId = 0, int $quantity = 1): string
    {
        if ($userId < 1 || $itemId < 1 || $quantity < 1) {
            return 'invalid';
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'SELECT `cart_items`.`product_id`, `products`.`stock` '
                . 'FROM `cart_items` '
                . 'INNER JOIN `carts` ON `carts`.`id` = `cart_items`.`cart_id` '
                . 'INNER JOIN `products` ON `products`.`id` = `cart_items`.`product_id` '
                . 'WHERE `cart_items`.`id` = :item_id AND `carts`.`user_id` = :user_id FOR UPDATE'
            );
            $statement->bindValue(':item_id', $itemId, PDO::PARAM_INT);
            $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $statement->execute();
            $item = $statement->fetch(PDO::FETCH_ASSOC);

            if ($item === false) {
                $this->pdo->rollBack();
                return 'not_found';
            }
            if ($item['stock'] === null || $quantity > (int) $item['stock']) {
                $this->pdo->rollBack();
                return 'stock_exceeded';
            }

            $updateStatement = $this->pdo->prepare(
                'UPDATE `cart_items` SET `quantity` = :quantity WHERE `id` = :item_id'
            );
            $updateStatement->bindValue(':quantity', $quantity, PDO::PARAM_INT);
            $updateStatement->bindValue(':item_id', $itemId, PDO::PARAM_INT);
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
     * Xóa một dòng hàng nếu nó thuộc giỏ của user hiện tại.
     *
     * @param int $userId ID user lấy từ JWT.
     * @param int $itemId ID dòng hàng trong giỏ.
     * @return bool True nếu xóa được dòng hàng.
     */
    public function removeItem(int $userId = 0, int $itemId = 0): bool
    {
        if ($userId < 1 || $itemId < 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'DELETE `cart_items` FROM `cart_items` '
            . 'INNER JOIN `carts` ON `carts`.`id` = `cart_items`.`cart_id` '
            . 'WHERE `cart_items`.`id` = :item_id AND `carts`.`user_id` = :user_id'
        );
        $statement->bindValue(':item_id', $itemId, PDO::PARAM_INT);
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /**
     * Tạo giỏ nếu chưa có và trả về ID của giỏ thuộc user.
     *
     * @param int $userId ID user sở hữu giỏ.
     * @return int ID giỏ hàng.
     */
    private function getOrCreateCartId(int $userId = 0): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `carts` (`user_id`) VALUES (:user_id) '
            . 'ON DUPLICATE KEY UPDATE `id` = LAST_INSERT_ID(`id`)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Chuyển số DECIMAL thành xu nguyên để cộng tiền tránh sai số float.
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