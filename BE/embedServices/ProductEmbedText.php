<?php

/**
 * Ghép thông tin một biến thể sản phẩm thành đoạn văn để tạo vector (tầng service).
 * Đây là NƠI DUY NHẤT định nghĩa 5 thuộc tính mặc định trong product_variants.attributes và mẫu câu của chúng.
 */

/**
 * Quy ước attributes (JSON "dẻo" của product_variants):
 * - Khóa: không dấu, chữ thường, snake_case (ví dụ do_bong). Giá trị: tiếng Việt có dấu.
 * - Luôn có đủ 5 khóa mặc định trong DEFAULT_ATTRIBUTES; chưa rõ hoặc không áp dụng thì để null.
 * - Ngoài 5 khóa đó, admin được thêm khóa tự do (ví dụ mui_huong).
 *
 * Đoạn văn KHÔNG chứa giá, tồn kho, lượt xem, lượt bán: các giá trị này đổi liên tục,
 * đưa vào thì phải tạo lại vector liên tục, mà model cũng không hiểu tốt con số.
 */
class ProductEmbedText
{
    // 5 thuộc tính mặc định: khóa => [nhãn hiển thị, mẫu câu khi ghép vào đoạn văn].
    // %s trong mẫu câu được thay bằng giá trị. Thứ tự ở đây là thứ tự câu trong đoạn văn.
    const DEFAULT_ATTRIBUTES = [
        'dung_tich' => ['label' => 'Dung tích', 'template' => 'Dung tích %s.'],
        'do_giu_nep' => ['label' => 'Độ giữ nếp', 'template' => 'Độ giữ nếp %s.'],
        'do_bong' => ['label' => 'Độ bóng', 'template' => 'Độ bóng: %s.'],
        'phu_hop' => ['label' => 'Phù hợp với', 'template' => 'Phù hợp với %s.'],
        'kieu_toc' => ['label' => 'Kiểu tóc', 'template' => 'Hợp kiểu tóc %s.'],
    ];

    // Tên biến thể mặc định do hệ thống tự đặt, không mang nghĩa nên không đưa vào đoạn văn.
    const PLACEHOLDER_VARIANT_NAME = 'Mặc định';

    /**
     * Ghép đoạn văn cho một biến thể.
     *
     * Ví dụ kết quả: "Pomade Reuzel Blue - Hũ 113g. Danh mục: Pomade. [mô tả]. Dung tích 113g. Độ giữ nếp mạnh. ..."
     *
     * @param array $row Một dòng dữ liệu gồm: product_name, variant_name, category_name, description,
     *                   attributes (mảng hoặc chuỗi JSON)
     * @return string Đoạn văn đã ghép, các câu cách nhau bằng dấu cách
     */
    public static function build($row = [])
    {
        if (!is_array($row)) {
            return '';
        }

        $sentences = [];

        // Câu 1: tên sản phẩm, kèm tên biến thể nếu có nghĩa.
        $title = self::cleanText($row['product_name'] ?? '');
        $variantName = self::cleanText($row['variant_name'] ?? '');
        if ($variantName !== '' && $variantName !== self::PLACEHOLDER_VARIANT_NAME) {
            $title .= ' - ' . $variantName;
        }
        $sentences[] = self::endSentence($title);

        $category = self::cleanText($row['category_name'] ?? '');
        if ($category !== '') {
            $sentences[] = 'Danh mục: ' . self::endSentence($category);
        }

        $description = self::cleanText($row['description'] ?? '');
        if ($description !== '') {
            $sentences[] = self::endSentence($description);
        }

        $attributes = $row['attributes'] ?? [];
        if (is_string($attributes)) {
            $attributes = json_decode($attributes, true);
        }
        if (!is_array($attributes)) {
            $attributes = [];
        }

        // 5 thuộc tính mặc định: ghép theo mẫu câu riêng, giá trị null/rỗng thì bỏ qua cả câu.
        foreach (self::DEFAULT_ATTRIBUTES as $key => $config) {
            $value = self::attributeValue($attributes[$key] ?? null);
            if ($value !== '') {
                // sprintf chỉ đọc %s trong mẫu câu (do code định nghĩa), giá trị chèn vào không bị hiểu là định dạng.
                $sentences[] = sprintf($config['template'], $value);
            }
        }

        // Khóa tự do (ví dụ mui_huong): chưa có mẫu câu nên chỉ lấy giá trị.
        // ksort sắp theo tên khóa để cùng dữ liệu luôn ra cùng đoạn văn (text_hash ổn định).
        $customAttributes = array_diff_key($attributes, self::DEFAULT_ATTRIBUTES);
        ksort($customAttributes);
        foreach ($customAttributes as $value) {
            $value = self::attributeValue($value);
            if ($value !== '') {
                $sentences[] = self::endSentence($value);
            }
        }

        return implode(' ', array_filter($sentences, static fn($sentence) => $sentence !== ''));
    }

    /**
     * Tính mã băm của đoạn văn, dùng để biết đoạn văn có đổi so với lần tạo vector trước không.
     *
     * @param string $text Đoạn văn đã ghép
     * @return string Chuỗi SHA-256 dài 64 ký tự
     */
    public static function hash($text = '')
    {
        return hash('sha256', (string) $text);
    }

    /**
     * Lấy giá trị thuộc tính dạng chuỗi. Chỉ nhận chuỗi hoặc số; null, true/false, mảng coi như không có.
     *
     * @param mixed $value Giá trị đọc từ attributes
     * @return string Giá trị đã làm sạch, rỗng nếu không dùng được
     */
    private static function attributeValue($value = null)
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return self::cleanText((string) $value);
        }

        return '';
    }

    /**
     * Gộp nhiều khoảng trắng/xuống dòng thành một dấu cách và bỏ khoảng trắng hai đầu.
     *
     * @param mixed $text Chuỗi cần làm sạch
     * @return string Chuỗi đã làm sạch
     */
    private static function cleanText($text = '')
    {
        if (!is_string($text)) {
            return '';
        }

        // Regex \s+ khớp một hoặc nhiều khoảng trắng liên tiếp; cờ u để xử lý đúng chuỗi UTF-8 tiếng Việt.
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Thêm dấu chấm cuối câu nếu câu chưa kết thúc bằng dấu câu.
     *
     * @param string $sentence Câu cần kiểm tra
     * @return string Câu đã có dấu kết thúc (rỗng giữ nguyên rỗng)
     */
    private static function endSentence($sentence = '')
    {
        if ($sentence === '') {
            return '';
        }

        return preg_match('/[.!?]$/u', $sentence) ? $sentence : $sentence . '.';
    }
}
