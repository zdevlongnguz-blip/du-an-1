<?php

/**
 * Đồng bộ vector của biến thể sản phẩm với dữ liệu hiện tại (tầng service).
 * Dùng chung cho script BE/database/embed_products.php và cho admin khi tạo/sửa sản phẩm.
 */

/**
 * Cách đồng bộ: ghép lại đoạn văn của từng biến thể, so text_hash với vector đang lưu.
 * Chỉ biến thể có đoạn văn đổi (hoặc chưa có vector) mới được gửi sang server Python,
 * nên sửa giá hay tồn kho (không nằm trong đoạn văn) sẽ không tốn request nào.
 * Đổi model ở server Python thì chạy embed_products.php --force để tạo lại toàn bộ.
 */
class ProductEmbedSync
{
    // Số đoạn văn gửi trong một lần gọi server Python. Nhỏ hơn giới hạn 64 để mỗi request nhẹ.
    const BATCH_SIZE = 32;

    /**
     * Tạo hoặc tạo lại vector cho biến thể của các sản phẩm đã cho.
     *
     * @param array $productIds Chỉ đồng bộ biến thể của các sản phẩm này (mảng rỗng = mọi sản phẩm)
     * @param bool  $force      true = tạo lại tất cả, không so hash
     * @return array ['saved' => số vector đã tạo, 'skipped' => số biến thể không đổi,
     *                'texts' => [variant_id => đoạn văn] của các biến thể vừa tạo vector]
     * @throws RuntimeException Khi server Python chưa bật hoặc trả dữ liệu sai
     */
    public static function sync($productIds = [], $force = false)
    {
        $embeddingModel = new ProductVariantEmbedding();
        $service = new EmbedService();

        // Bước 1: ghép đoạn văn, tách biến thể cần tạo vector ra khỏi biến thể không đổi.
        $rows = $embeddingModel->getVariantsForEmbedding(is_array($productIds) ? $productIds : []);
        $todo = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $text = ProductEmbedText::build($row);
            $hash = ProductEmbedText::hash($text);

            // Đoạn văn không đổi thì vector cũ vẫn đúng, bỏ qua (trừ khi $force).
            if (!$force && $row['current_hash'] === $hash) {
                $skipped++;
                continue;
            }
            $todo[] = ['variant_id' => (int) $row['variant_id'], 'text' => $text, 'hash' => $hash];
        }

        // Không có gì cần làm thì dừng luôn, KHÔNG gọi server Python
        // (admin sửa giá/tồn kho sẽ không bị chậm và không phụ thuộc server Python).
        if ($todo === []) {
            return ['saved' => 0, 'skipped' => $skipped, 'texts' => []];
        }

        // Hỏi tên model để lưu kèm vector, biết vector được tạo bằng model nào.
        $modelName = $service->health()['model'];

        // Bước 2: gửi theo lô sang server Python rồi lưu vector vào DB.
        $texts = [];
        foreach (array_chunk($todo, self::BATCH_SIZE) as $batch) {
            $vectors = $service->embed(array_column($batch, 'text'));

            // embed() trả vector theo đúng thứ tự đoạn văn đã gửi: vector thứ i thuộc biến thể thứ i trong lô.
            foreach ($batch as $index => $item) {
                $embeddingModel->upsert($item['variant_id'], $item['text'], $item['hash'], $vectors[$index], $modelName);
                $texts[$item['variant_id']] = $item['text'];
            }
        }

        return ['saved' => count($texts), 'skipped' => $skipped, 'texts' => $texts];
    }
}
