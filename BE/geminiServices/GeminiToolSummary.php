<?php

/**
 * Câu mô tả ngắn cho mỗi lần trợ lý AI gọi tool, để khung chat hiện chip dưới câu trả lời (tầng service).
 * Ví dụ: "Đã tra cứu sản phẩm: Độ giữ nếp: Mạnh; Đặc điểm: tóc dày", "Đã cập nhật đơn nháp: thêm 2 × CLAYMATION".
 */

/**
 * Vì sao tạo ở BE: câu chữ cần nhãn nghiệp vụ (giữ nếp "Mạnh", độ bóng "Bóng cao"...) mà BE là nơi định nghĩa
 * (VariantAttributes, CatalogFilter). FE chỉ hiện chuỗi có sẵn, không giữ bảng nhãn riêng.
 * Dùng chung cho câu trả lời mới (Gemini::chat) và lịch sử nạp lại (Gemini::toDisplayMessages),
 * nên chip hiện giống nhau dù khách vừa hỏi hay mở lại khung chat.
 *
 * An toàn: chỉ dùng tham số không chứa thông tin cá nhân (bộ lọc sản phẩm, SKU, số lượng, tên bài hát).
 * Tham số sai kiểu thì trả câu chung (không báo lỗi), vì chip chỉ để hiển thị.
 */
class GeminiToolSummary
{
    // Số thao tác tối đa liệt kê trong chip đơn nháp; nhiều hơn thì thêm "...".
    const MAX_DRAFT_OPS = 3;

    /**
     * Tạo câu mô tả cho một lần gọi tool.
     *
     * @param string $name Tên tool
     * @param mixed  $args Tham số AI đã gửi
     * @return string Câu mô tả; chuỗi rỗng nếu là tool không cần hiện chip
     */
    public static function describe($name = '', $args = [])
    {
        $args = is_array($args) ? $args : [];

        switch ($name) {
            case 'search_product':
                return self::describeSearch($args);
            case 'create_order_draft':
                return self::describeDraft($args);
            case 'cancel_order_draft':
                return 'Đã bỏ đơn nháp';
            case 'fule_sing':
                $song = is_string($args['song'] ?? null) ? trim($args['song']) : '';
                return $song !== '' ? 'Đã chọn bài hát: “' . mb_substr($song, 0, 60, 'UTF-8') . '”' : 'Đã chọn bài hát';
            default:
                return '';
        }
    }

    /**
     * Mô tả lần tra cứu sản phẩm bằng đúng câu chữ của CatalogFilter::describe (nguồn duy nhất).
     * Kiểm tra tham số bằng CatalogFilter như tool thật; tham số sai thì trả câu chung.
     *
     * @param array $args Tham số của search_product
     * @return string
     */
    private static function describeSearch($args = [])
    {
        $category = is_string($args['category'] ?? null) ? $args['category'] : '';
        try {
            $filters = CatalogFilter::fromInput([
                'search' => $args['keyword'] ?? '',
                // fromInput chỉ kiểm tra kiểu và độ dài; tên danh mục dùng để hiển thị, không lọc ở đây.
                'category' => $category,
                'min_price' => $args['min_price'] ?? null,
                'max_price' => $args['max_price'] ?? null,
                'hold' => $args['hold'] ?? null,
                'shine' => $args['shine'] ?? null,
                'tags' => $args['tags'] ?? null,
            ]);
        } catch (InvalidArgumentException $exception) {
            return 'Đã tra cứu sản phẩm';
        }

        // Chỉ có từ khóa: giữ cách hiện quen thuộc "Đã tra cứu sản phẩm: “pomade”" (không lặp chữ "Từ khóa:").
        if ($filters['search'] !== '' && CatalogFilter::describe(['search' => ''] + $filters, $category) === []) {
            return 'Đã tra cứu sản phẩm: “' . $filters['search'] . '”';
        }
        $parts = CatalogFilter::describe($filters, $category);

        return $parts === [] ? 'Đã tra cứu sản phẩm' : 'Đã tra cứu sản phẩm: ' . implode('; ', $parts);
    }

    /**
     * Mô tả lần cập nhật đơn nháp: liệt kê tối đa MAX_DRAFT_OPS thao tác, ví dụ "thêm 2 × CLAYMATION".
     *
     * @param array $args Tham số của create_order_draft (['ops' => [{op, sku, quantity}]])
     * @return string
     */
    private static function describeDraft($args = [])
    {
        $labels = [];
        foreach (is_array($args['ops'] ?? null) ? $args['ops'] : [] as $op) {
            if (!is_array($op) || !is_string($op['sku'] ?? null) || !is_string($op['op'] ?? null)) {
                continue;
            }
            $sku = mb_substr(trim($op['sku']), 0, 40, 'UTF-8');
            $quantity = filter_var($op['quantity'] ?? null, FILTER_VALIDATE_INT);
            $quantityText = $quantity === false ? '' : $quantity . ' × ';
            switch ($op['op']) {
                case 'add':
                    $labels[] = 'thêm ' . $quantityText . $sku;
                    break;
                case 'set':
                    $labels[] = 'đổi ' . $sku . ($quantity === false ? '' : ' thành ' . $quantity);
                    break;
                case 'subtract':
                    $labels[] = 'bớt ' . $quantityText . $sku;
                    break;
                case 'remove':
                    $labels[] = 'bỏ ' . $sku;
                    break;
            }
        }
        if ($labels === []) {
            return 'Đã cập nhật đơn nháp';
        }
        $shown = array_slice($labels, 0, self::MAX_DRAFT_OPS);

        return 'Đã cập nhật đơn nháp: ' . implode(', ', $shown) . (count($labels) > count($shown) ? ', ...' : '');
    }
}
