<?php

/**
 * Model bảng product_variant_embeddings: lưu và đọc vector của từng biến thể sản phẩm (tầng model).
 * Dùng cho tìm kiếm nâng cao theo nghĩa. Không ghi vào products hay product_variants.
 */

class ProductVariantEmbedding extends BaseModel
{
    protected $table = 'product_variant_embeddings';

    /**
     * Lấy dữ liệu biến thể để ghép đoạn văn tạo vector, kèm hash và model của vector hiện có.
     *
     * Chỉ lấy các cột ổn định (tên, mô tả, danh mục, attributes). Không lấy giá, tồn kho,
     * lượt xem, lượt bán vì chúng không được đưa vào đoạn văn.
     *
     * LEFT JOIN bảng embedding: biến thể chưa có vector vẫn được lấy ra, khi đó
     * current_hash và current_model là NULL.
     *
     * @param array $productIds Chỉ lấy biến thể của các sản phẩm này (mảng rỗng = mọi biến thể)
     * @return array Mỗi phần tử: variant_id, product_name, variant_name, category_name, description,
     *               attributes (chuỗi JSON), current_hash, current_model
     */
    public function getVariantsForEmbedding($productIds = [])
    {
        $productIds = $this->cleanIds($productIds);

        $sql = 'SELECT `v`.`id` AS `variant_id`, `p`.`name` AS `product_name`, `v`.`variant_name`, '
            . '`c`.`name` AS `category_name`, `p`.`description`, `v`.`attributes`, '
            . '`e`.`text_hash` AS `current_hash`, `e`.`model_name` AS `current_model` '
            . 'FROM `product_variants` AS `v` '
            . 'JOIN `products` AS `p` ON `p`.`id` = `v`.`product_id` '
            . 'JOIN `categories` AS `c` ON `c`.`id` = `p`.`category_id` '
            . 'LEFT JOIN `product_variant_embeddings` AS `e` ON `e`.`variant_id` = `v`.`id`';
        if ($productIds !== []) {
            // Tạo sẵn số dấu ? bằng số ID (ví dụ "?, ?") cho mệnh đề IN, ID được truyền qua execute().
            $sql .= ' WHERE `v`.`product_id` IN (' . implode(', ', array_fill(0, count($productIds), '?')) . ')';
        }
        $sql .= ' ORDER BY `v`.`id`';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($productIds);

        return $stmt->fetchAll();
    }

    /**
     * Thêm mới hoặc ghi đè vector của một biến thể.
     *
     * INSERT ... ON DUPLICATE KEY UPDATE: nếu variant_id (khóa chính) đã có dòng thì cập nhật dòng đó,
     * chưa có thì thêm mới. Gộp "thêm hoặc sửa" vào một câu lệnh.
     *
     * @param int    $variantId ID biến thể
     * @param string $embedText Đoạn văn đã ghép
     * @param string $textHash  SHA-256 của đoạn văn
     * @param array  $embedding Vector (mảng số thực)
     * @param string $modelName Tên model đã tạo vector
     * @return bool true nếu ghi thành công
     */
    public function upsert($variantId = 0, $embedText = '', $textHash = '', $embedding = [], $modelName = '')
    {
        $variantId = (int) $variantId;
        if ($variantId <= 0 || !is_array($embedding) || count($embedding) === 0) {
            return false;
        }

        $sql = 'INSERT INTO `product_variant_embeddings` '
            . '(`variant_id`, `embed_text`, `text_hash`, `embedding`, `dim`, `model_name`) '
            . 'VALUES (:variant_id, :embed_text, :text_hash, :embedding, :dim, :model_name) '
            . 'ON DUPLICATE KEY UPDATE `embed_text` = VALUES(`embed_text`), `text_hash` = VALUES(`text_hash`), '
            . '`embedding` = VALUES(`embedding`), `dim` = VALUES(`dim`), `model_name` = VALUES(`model_name`)';

        // Dùng tham số :ten thay vì nối chuỗi vào SQL để chống SQL injection.
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
        $stmt->bindValue(':embed_text', (string) $embedText, PDO::PARAM_STR);
        $stmt->bindValue(':text_hash', (string) $textHash, PDO::PARAM_STR);
        $stmt->bindValue(':embedding', json_encode(array_values($embedding)), PDO::PARAM_STR);
        $stmt->bindValue(':dim', count($embedding), PDO::PARAM_INT);
        $stmt->bindValue(':model_name', (string) $modelName, PDO::PARAM_STR);

        return $stmt->execute();
    }

    /**
     * Tìm kiếm vector, bước 2: lấy vector của các biến thể đang bán thuộc những sản phẩm trong vòng bo.
     *
     * Có lọc giá thì chỉ lấy biến thể nằm trong khoảng giá, để điểm của sản phẩm được tính
     * từ đúng những biến thể khách có thể mua trong khoảng giá đó.
     * Giá chỉ dùng để LỌC, không nằm trong vector.
     *
     * @param array      $productIds ID các sản phẩm trong vòng bo
     * @param float|null $minPrice   Giá bán thấp nhất (null = không lọc)
     * @param float|null $maxPrice   Giá bán cao nhất (null = không lọc)
     * @return array Mỗi phần tử: product_id, variant_id, sku, variant_name, final_price, stock,
     *               attributes (mảng), embed_text, vector (mảng số thực đã giải mã)
     */
    public function getVariantVectors($productIds = [], $minPrice = null, $maxPrice = null)
    {
        $productIds = $this->cleanIds($productIds);
        if ($productIds === []) {
            return [];
        }

        $sql = 'SELECT `v`.`product_id`, `v`.`id` AS `variant_id`, `v`.`sku`, `v`.`variant_name`, '
            . '`v`.`final_price`, `v`.`stock`, `v`.`attributes`, `e`.`embed_text`, `e`.`embedding` '
            . 'FROM `product_variant_embeddings` AS `e` '
            . 'JOIN `product_variants` AS `v` ON `v`.`id` = `e`.`variant_id` '
            . 'WHERE `v`.`is_active` = 1 '
            . 'AND `v`.`product_id` IN (' . implode(', ', array_fill(0, count($productIds), '?')) . ')';
        $params = $productIds;

        // Điều kiện giá chỉ thêm khi có truyền (query có điều kiện động).
        if ($minPrice !== null) {
            $sql .= ' AND `v`.`final_price` >= ?';
            $params[] = (string) $minPrice;
        }
        if ($maxPrice !== null) {
            $sql .= ' AND `v`.`final_price` <= ?';
            $params[] = (string) $maxPrice;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            // Đổi chuỗi JSON thành mảng: embedding để tính cosine, attributes (dạng chuẩn [{key, label, value}])
            // để trả cho FE hiển thị.
            $vector = json_decode((string) $row['embedding'], true);
            $rows[] = [
                'product_id' => (int) $row['product_id'],
                'variant_id' => (int) $row['variant_id'],
                'sku' => $row['sku'],
                'variant_name' => $row['variant_name'],
                'final_price' => (float) $row['final_price'],
                'stock' => (int) $row['stock'],
                'attributes' => VariantAttributes::decode($row['attributes']),
                'embed_text' => $row['embed_text'],
                'vector' => is_array($vector) ? $vector : [],
            ];
        }

        return $rows;
    }

    /**
     * Làm sạch mảng ID: ép kiểu số nguyên, bỏ giá trị <= 0, bỏ trùng.
     *
     * @param mixed $ids Mảng ID (giá trị khác mảng coi như rỗng)
     * @return array Mảng ID hợp lệ, chỉ số 0, 1, 2...
     */
    private function cleanIds($ids = [])
    {
        if (!is_array($ids)) {
            return [];
        }

        $ids = array_filter(array_map('intval', $ids), static fn($id) => $id > 0);

        return array_values(array_unique($ids));
    }
}
