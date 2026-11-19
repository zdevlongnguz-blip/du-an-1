<?php

/**
 * Ghép thông tin một biến thể sản phẩm thành đoạn văn để tạo vector (tầng service).
 * Danh sách 5 thuộc tính mặc định và định dạng attributes nằm ở VariantAttributes (BE/extensions);
 * file này chỉ giữ MẪU CÂU dùng khi ghép đoạn văn.
 */

/**
 * attributes là mảng [{key, label, value}, ...] (xem VariantAttributes). Khi ghép:
 * - 5 khóa mặc định dùng mẫu câu riêng trong TEMPLATES, theo đúng thứ tự trong mảng.
 * - Khóa tự do (ví dụ mui_huong) chưa có mẫu câu nên chỉ lấy giá trị.
 * - Giá trị null thì bỏ cả câu.
 *
 * Đoạn văn KHÔNG chứa giá, tồn kho, lượt xem, lượt bán: các giá trị này đổi liên tục,
 * đưa vào thì phải tạo lại vector liên tục, mà model cũng không hiểu tốt con số.
 */
class ProductEmbedText
{
    // Mẫu câu cho 5 khóa mặc định. %s được thay bằng giá trị.
    // Đổi mẫu câu thì embed_text đổi, chạy BE/database/embed_products.php để tạo lại vector.
    const TEMPLATES = [
        'dung_tich' => 'Dung tích %s.',
        'do_giu_nep' => 'Độ giữ nếp %s.',
        'do_bong' => 'Độ bóng: %s.',
        'phu_hop' => 'Phù hợp với %s.',
        'kieu_toc' => 'Hợp kiểu tóc %s.',
    ];

    // Tên biến thể mặc định do hệ thống tự đặt, không mang nghĩa nên không đưa vào đoạn văn.
    const PLACEHOLDER_VARIANT_NAME = 'Mặc định';

    /**
     * Ghép đoạn văn cho một biến thể.
     *
     * Ví dụ kết quả: "Pomade Reuzel Blue - Hũ 113g. Danh mục: Pomade. [mô tả]. Dung tích 113g. Độ giữ nếp mạnh. ..."
     *
     * @param array $row Một dòng dữ liệu gồm: product_name, variant_name, category_name, description,
     *                   attributes (chuỗi JSON hoặc mảng, định dạng của VariantAttributes)
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

        // decode() trả mảng chuẩn: 5 khóa mặc định đứng đầu theo thứ tự cố định, khóa tự do theo thứ tự nhập,
        // nên cùng dữ liệu luôn ra cùng đoạn văn (text_hash ổn định).
        foreach (VariantAttributes::decode($row['attributes'] ?? null) as $attribute) {
            $value = self::cleanText($attribute['value'] ?? '');
            if ($value === '') {
                continue;
            }

            $template = self::TEMPLATES[$attribute['key']] ?? null;
            // sprintf chỉ đọc %s trong mẫu câu (do code định nghĩa), giá trị chèn vào không bị hiểu là định dạng.
            $sentences[] = $template !== null ? sprintf($template, $value) : self::endSentence($value);
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
