<?php

/**
 * Tool "search_product": cho Gemini tìm sản phẩm của cửa hàng theo từ khóa (tầng service).
 * Tool chỉ là lớp bọc mỏng: kiểm tra tham số rồi gọi Product::searchByKeyword(), không viết SQL ở đây.
 */

/**
 * Khi nào Gemini gọi tool này: khách hỏi cửa hàng có bán gì, sản phẩm nào, giá bao nhiêu.
 * Tool chỉ đọc dữ liệu công khai (sản phẩm), nên không cần đăng nhập và không cần user ID.
 */
class GeminiToolSearchProduct extends GeminiTool
{
    // Độ dài tối đa của từ khóa (ký tự), chặn chuỗi quá dài do AI sinh ra.
    const MAX_KEYWORD_LENGTH = 100;

    // Số sản phẩm tối đa đưa cho Gemini. Ít kết quả giúp câu trả lời gọn và tiết kiệm token.
    const MAX_RESULTS = 5;

    // Số phiên bản (biến thể) tối đa mỗi sản phẩm gửi cho Gemini. Kèm luôn trong kết quả tìm kiếm
    // để AI trả lời được câu hỏi về phiên bản mà không phải gọi thêm tool (mỗi lần gọi tool tốn thêm 1 request Gemini).
    const MAX_VARIANTS_PER_PRODUCT = 5;

    /**
     * Tên tool. Gemini dùng tên này khi yêu cầu gọi.
     *
     * @return string
     */
    public function name()
    {
        return 'search_product';
    }

    /**
     * Mô tả để Gemini biết lúc nào nên gọi tool.
     *
     * @return string
     */
    public function description()
    {
        return 'Tìm sản phẩm của cửa hàng theo từ khóa (tên, mã hoặc mô tả). '
            . 'Kết quả có kèm từng phiên bản đang bán (tên phiên bản, giá, giá gốc nếu giảm giá, số lượng tồn kho). '
            . 'Dùng khi khách hỏi cửa hàng có bán gì, sản phẩm nào, có những phiên bản nào, giá bao nhiêu, còn hàng không.';
    }

    /**
     * Khai báo tham số theo JSON Schema: một từ khóa bắt buộc.
     *
     * @return array
     */
    public function parameters()
    {
        return [
            'type' => 'object',
            'properties' => [
                'keyword' => [
                    'type' => 'string',
                    'description' => 'Từ khóa khách hỏi, ví dụ: sáp, gel, pomade',
                ],
            ],
            'required' => ['keyword'],
        ];
    }

    /**
     * Tìm sản phẩm theo từ khóa và trả dữ liệu gọn cho Gemini.
     *
     * Lưu ý: $args do AI sinh ra nên phải kiểm tra như dữ liệu người dùng nhập.
     * Với lỗi do tham số sai, ném InvalidArgumentException để Gemini nhận được thông báo rõ ràng.
     *
     * @param array $args    Tham số Gemini gửi lên, cần có 'keyword' (chuỗi 1-100 ký tự)
     * @param array $context Ngữ cảnh server (không dùng, vì tool này là tra cứu công khai)
     * @return array ['products' => danh sách sản phẩm, 'message' => thông báo khi không có kết quả]
     * @throws InvalidArgumentException Khi thiếu từ khóa, không phải chuỗi hoặc quá dài
     */
    public function execute($args = [], $context = [])
    {
        $args = is_array($args) ? $args : [];

        // Gemini có thể gửi số hoặc mảng thay vì chuỗi, nên kiểm tra kiểu trước khi dùng.
        $keyword = $args['keyword'] ?? '';
        if (!is_string($keyword)) {
            throw new InvalidArgumentException('Từ khóa tìm kiếm phải là chuỗi.');
        }
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new InvalidArgumentException('Thiếu từ khóa tìm kiếm.');
        }
        // mb_strlen đếm theo ký tự tiếng Việt đúng hơn strlen (đếm theo byte).
        if (mb_strlen($keyword, 'UTF-8') > self::MAX_KEYWORD_LENGTH) {
            throw new InvalidArgumentException('Từ khóa quá dài, tối đa ' . self::MAX_KEYWORD_LENGTH . ' ký tự.');
        }

        $rows = (new Product())->searchByKeyword($keyword, self::MAX_RESULTS);

        // Không có kết quả là kết quả hợp lệ, không phải lỗi. Gửi thông báo rõ để Gemini nói lại với khách.
        // Vẫn mở trang danh sách (không lọc) để khách tự xem các sản phẩm khác.
        if ($rows === []) {
            return [
                'products' => [],
                'message' => 'Không tìm thấy sản phẩm nào khớp với từ khóa "' . $keyword . '". '
                    . 'Hãy báo khách là cửa hàng hiện chưa có hoặc chưa cập nhật sản phẩm này.',
                Gemini::ACTION_KEY => $this->navigateAction('catalog'),
            ];
        }

        // Lấy phiên bản đang bán của tất cả sản phẩm tìm được bằng MỘT truy vấn (không truy vấn từng sản phẩm).
        $variantsByProduct = (new Product())->getActiveVariantsByProductIds(
            array_column($rows, 'id'),
            self::MAX_VARIANTS_PER_PRODUCT
        );

        // Chỉ lấy các cột AI cần, không trả cả bản ghi từ DB.
        $products = [];
        foreach ($rows as $row) {
            $productId = (int)$row['id'];
            $products[] = [
                'id' => $productId,
                'name' => $row['name'],
                'code' => $row['code'],
                'category' => $row['category_name'],
                // Giá thấp nhất/cao nhất của các biến thể đang bán. Null nếu sản phẩm chưa có biến thể bán.
                'price_from' => $row['min_final_price'] === null ? null : (float)$row['min_final_price'],
                'price_to' => $row['max_final_price'] === null ? null : (float)$row['max_final_price'],
                'in_stock' => (bool)$row['in_stock'],
                // Tổng số phiên bản đang bán; lớn hơn số phần tử trong 'variants' nghĩa là danh sách đã bị cắt bớt.
                'variant_count' => (int)($row['variant_count'] ?? 0),
                'variants' => $this->formatVariants($variantsByProduct[$productId] ?? []),
            ];
        }

        // Đúng 1 sản phẩm: mở thẳng trang chi tiết. Nhiều sản phẩm: mở trang danh sách đã lọc theo từ khóa.
        $slug = (string)($rows[0]['slug'] ?? '');
        $action = count($rows) === 1 && $slug !== ''
            ? $this->navigateAction('product-detail', [], ['slug' => $slug])
            : $this->navigateAction('catalog', ['search' => $keyword]);

        return [
            'products' => $products,
            Gemini::ACTION_KEY => $action,
        ];
    }

    /**
     * Rút gọn dữ liệu phiên bản từ DB thành dạng dễ đọc cho Gemini.
     *
     * - price: giá bán thực tế (đã tính khuyến mãi).
     * - original_price: giá niêm yết, chỉ có khi đang giảm giá (null = không giảm), để AI nói được "giảm từ X còn Y".
     * - stock: số lượng tồn kho tại thời điểm tra cứu; 0 nghĩa là phiên bản đó hết hàng.
     *
     * @param array $variants Các dòng biến thể từ Product::getActiveVariantsByProductIds
     * @return array Danh sách phiên bản đã rút gọn
     */
    private function formatVariants($variants = [])
    {
        $result = [];
        foreach (is_array($variants) ? $variants : [] as $variant) {
            $onSale = $variant['sale_price'] !== null;
            $result[] = [
                'name' => $variant['variant_name'],
                'sku' => $variant['sku'],
                'price' => (float)$variant['final_price'],
                'original_price' => $onSale ? (float)$variant['price'] : null,
                'stock' => (int)$variant['stock'],
                'is_default' => (bool)$variant['is_default'],
            ];
        }

        return $result;
    }
}
