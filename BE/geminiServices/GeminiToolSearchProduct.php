<?php

/**
 * Tool "search_product": cho Gemini tra sản phẩm của cửa hàng bằng ĐÚNG bộ lọc của trang catalog (tầng service).
 * Tool chỉ là lớp bọc mỏng: đổi tham số AI sang bộ lọc, kiểm tra bằng CatalogFilter (dùng chung với api-products),
 * rồi gọi Product::getFiltered(). Không viết SQL ở đây, nên trợ lý và trang catalog luôn ra cùng kết quả.
 */

/**
 * Khi nào Gemini gọi tool này: khách hỏi cửa hàng có bán gì, sản phẩm nào, giá bao nhiêu, còn hàng không,
 * hoặc nhờ tư vấn theo nhu cầu ("sáp giữ nếp mạnh, không bóng, cho tóc dày", "dưới 500 nghìn").
 * Tool chỉ đọc dữ liệu công khai (sản phẩm), nên không cần đăng nhập và không cần user ID.
 */
class GeminiToolSearchProduct extends GeminiTool
{
    // Số sản phẩm tối đa đưa cho Gemini. Ít kết quả giúp câu trả lời gọn và tiết kiệm token.
    const MAX_RESULTS = 5;

    // Số phiên bản (biến thể) tối đa mỗi sản phẩm gửi cho Gemini. Kèm luôn trong kết quả tìm kiếm
    // để AI trả lời được câu hỏi về phiên bản mà không phải gọi thêm tool (mỗi lần gọi tool tốn thêm 1 request Gemini).
    const MAX_VARIANTS_PER_PRODUCT = 5;

    // Độ dài tối đa của mô tả sản phẩm gửi cho Gemini (ký tự). Đủ để tư vấn, không làm kết quả quá dài.
    const MAX_DESCRIPTION_LENGTH = 300;

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
        return 'Tra sản phẩm của cửa hàng bằng bộ lọc giống trang danh sách sản phẩm. Mọi tham số đều không bắt buộc, '
            . 'có thể kết hợp nhiều tham số. Kết quả có mô tả, lượt bán, khoảng giá và từng phiên bản đang bán '
            . '(giá, giá gốc nếu giảm giá, tồn kho, độ giữ nếp, độ bóng, dung tích, phù hợp loại tóc, kiểu tóc). '
            . 'Dùng khi khách hỏi cửa hàng có bán gì, giá bao nhiêu, còn hàng không, hoặc nhờ tư vấn theo nhu cầu.';
    }

    /**
     * Khai báo tham số theo JSON Schema. Tên và ý nghĩa khớp bộ lọc catalog (xem CatalogFilter).
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
                    // Mô tả tham số là nơi AI đọc ngay lúc quyết định gọi tool, nên nhắc lại quy tắc chọn keyword
                    // (chi tiết và ví dụ đầy đủ ở GeminiPrompt). Tìm theo kiểu cả cụm liền nhau nên cụm dài sẽ không ra.
                    'description' => 'CHỈ thực thể cốt lõi, 1-3 từ: tên thương hiệu, dòng sản phẩm, tên người, mã hoặc SKU, đã sửa đúng chính tả '
                        . '(ví dụ "Sơn Tùng MTP", "Kevin Murphy", "Reuzel", "CLAYMATION-113G"). KHÔNG đưa cả câu, từ để hỏi, cách nói '
                        . '("được dùng bởi", "có ... không"), tên loại sản phẩm (sáp, pomade: dùng category) hay nhu cầu '
                        . '(giữ nếp, bóng, giá, loại tóc: dùng hold, shine, giá, tags). Tìm theo cả cụm liền nhau trong tên, mã, mô tả, '
                        . 'đặc điểm, SKU, tên phiên bản. Bỏ trống nếu khách chỉ nêu nhu cầu.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Tên danh mục đúng như trong danh sách danh mục của cửa hàng.',
                ],
                'min_price' => ['type' => 'number', 'description' => 'Giá bán thấp nhất (VND), ví dụ 200000.'],
                'max_price' => ['type' => 'number', 'description' => 'Giá bán cao nhất (VND), ví dụ 500000.'],
                'hold' => [
                    'type' => 'integer',
                    'description' => 'Độ giữ nếp: 1 = nhẹ, 2 = trung bình, 3 = mạnh.',
                ],
                'shine' => [
                    'type' => 'integer',
                    'description' => 'Độ bóng: 0 = không bóng (mờ), 1 = bóng nhẹ, 2 = bóng vừa, 3 = bóng cao.',
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Tối đa 5 đặc điểm phải có, ví dụ ["tóc dày", "side part"] hoặc ["113g"]. '
                        . 'Dùng cho loại tóc, kiểu tóc, dung tích, mùi hương.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => array_keys(CatalogFilter::SORTS),
                    'description' => 'newest = mới nhất (mặc định), oldest = cũ nhất.',
                ],
            ],
        ];
    }

    /**
     * Tra sản phẩm theo bộ lọc và trả dữ liệu gọn cho Gemini.
     *
     * Lưu ý: $args do AI sinh ra nên phải kiểm tra như dữ liệu người dùng nhập (CatalogFilter làm việc này).
     * Tham số sai thì ném InvalidArgumentException; Gemini::runTool gửi câu báo lỗi cho Gemini để nó tự sửa.
     *
     * @param array $args    Tham số Gemini gửi lên (xem parameters())
     * @param array $context Ngữ cảnh server (không dùng, vì tool này là tra cứu công khai)
     * @return array ['total_found', 'applied_filters', 'products', 'message'?] kèm lệnh mở trang (Gemini::ACTION_KEY)
     * @throws InvalidArgumentException Khi tham số sai kiểu, ngoài miền giá trị hoặc danh mục không có
     */
    public function execute($args = [], $context = [])
    {
        $args = is_array($args) ? $args : [];
        $product = new Product();

        // AI chỉ biết TÊN danh mục (có trong system prompt), bộ lọc cần slug: đổi tên sang slug.
        $categorySlug = '';
        if (isset($args['category']) && is_string($args['category']) && trim($args['category']) !== '') {
            $found = $product->findCategorySlug($args['category']);
            if ($found['slug'] === null) {
                // Mỗi tên trong ngoặc kép: tên danh mục có thể chứa dấu phẩy (ví dụ "Gel, Sáp vuốt tóc").
                $names = array_map(static fn($name) => '"' . $name . '"', $found['names']);
                throw new InvalidArgumentException(
                    'Không có danh mục "' . trim($args['category']) . '". Danh mục đang có: ' . implode('; ', $names)
                );
            }
            $categorySlug = $found['slug'];
        }

        // Cùng một luật kiểm tra với api-products, nên catalog và trợ lý hiểu bộ lọc giống nhau.
        $filters = CatalogFilter::fromInput([
            'search' => $args['keyword'] ?? '',
            'category' => $categorySlug,
            'min_price' => $args['min_price'] ?? null,
            'max_price' => $args['max_price'] ?? null,
            'hold' => $args['hold'] ?? null,
            'shine' => $args['shine'] ?? null,
            'tags' => $args['tags'] ?? null,
            'sort' => $args['sort'] ?? 'newest',
        ]);

        // Trả lại kho của đơn SePay quá hạn trước khi đọc tồn kho, để Louis không báo "hết hàng" oan.
        Order::releaseExpiredStock();

        $result = $product->getFiltered($filters, 1, self::MAX_RESULTS);
        $rows = $result['data'];
        $total = (int)$result['pagination']['total_items'];
        // Mô tả bộ lọc bằng chữ (CatalogFilter::describe là nguồn duy nhất, chip trong khung chat cũng dùng).
        $applied = CatalogFilter::describe($filters, is_string($args['category'] ?? null) ? $args['category'] : '');

        // Không có kết quả là kết quả hợp lệ, không phải lỗi. Gửi thông báo rõ để Gemini nói lại với khách.
        // Mở trang danh sách KHÔNG lọc để khách tự xem các sản phẩm khác.
        if ($rows === []) {
            return [
                'total_found' => 0,
                'applied_filters' => $applied,
                'products' => [],
                // Nhắc AI thử lại đúng cách trước khi kết luận "không có", vì lỗi hay gặp là keyword quá dài hoặc sai chính tả.
                'message' => 'Không có sản phẩm nào khớp. Nếu chưa thử lại: bỏ category, hoặc rút keyword còn phần đặc trưng nhất '
                    . '(1-2 từ, đúng chính tả, thử có dấu / không dấu) rồi gọi lại (tối đa 2 lần). Đã thử mà vẫn không có thì báo khách '
                    . 'cửa hàng hiện chưa có sản phẩm như vậy và gợi ý nới bớt điều kiện.',
                Gemini::ACTION_KEY => $this->navigateAction('catalog'),
            ];
        }

        // Lấy phiên bản đang bán của tất cả sản phẩm tìm được bằng MỘT truy vấn (không truy vấn từng sản phẩm).
        $variantsByProduct = $product->getActiveVariantsByProductIds(
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
                'description' => $this->shortenDescription($row['description'] ?? ''),
                // Lượt bán cộng dồn mọi phiên bản, để trả lời câu hỏi "loại nào bán chạy".
                'sales_count' => (int)($row['sales_count'] ?? 0),
                // Giá thấp nhất/cao nhất của các biến thể đang bán. Null nếu sản phẩm chưa có biến thể bán.
                'price_from' => $row['min_final_price'] === null ? null : (float)$row['min_final_price'],
                'price_to' => $row['max_final_price'] === null ? null : (float)$row['max_final_price'],
                'in_stock' => (bool)$row['in_stock'],
                // Tổng số phiên bản đang bán; lớn hơn số phần tử trong 'variants' nghĩa là danh sách đã bị cắt bớt.
                'variant_count' => (int)($row['variant_count'] ?? 0),
                'variants' => $this->formatVariants($variantsByProduct[$productId] ?? []),
            ];
        }

        // Đúng 1 sản phẩm: mở thẳng trang chi tiết. Nhiều sản phẩm: mở trang danh sách với ĐÚNG bộ lọc vừa dùng,
        // để khách thấy cùng kết quả với câu trả lời của trợ lý.
        $slug = (string)($rows[0]['slug'] ?? '');
        $action = $total === 1 && $slug !== ''
            ? $this->navigateAction('product-detail', [], ['slug' => $slug])
            : $this->navigateAction('catalog', CatalogFilter::toQuery($filters));

        return [
            // Tổng số sản phẩm khớp; lớn hơn số phần tử trong 'products' nghĩa là danh sách đã bị cắt bớt.
            'total_found' => $total,
            'applied_filters' => $applied,
            'products' => $products,
            Gemini::ACTION_KEY => $action,
        ];
    }

    /**
     * Rút gọn mô tả: bỏ xuống dòng thừa, cắt tối đa MAX_DESCRIPTION_LENGTH ký tự (thêm "..." nếu bị cắt).
     *
     * @param mixed $description Mô tả sản phẩm
     * @return string|null Mô tả đã rút gọn; null nếu không có mô tả
     */
    private function shortenDescription($description = '')
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$description));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH, 'UTF-8') . '...'
            : $text;
    }

    /**
     * Rút gọn dữ liệu phiên bản từ DB thành dạng dễ đọc cho Gemini.
     *
     * - price: giá bán thực tế (đã tính khuyến mãi).
     * - original_price: giá niêm yết, chỉ có khi đang giảm giá (null = không giảm), để AI nói được "giảm từ X còn Y".
     * - stock: số lượng tồn kho tại thời điểm tra cứu; 0 nghĩa là phiên bản đó hết hàng.
     * - hold / shine: nhãn độ giữ nếp, độ bóng (null = cửa hàng chưa cập nhật, AI không được đoán).
     * - attributes: {nhãn: giá trị} của dung tích, phù hợp, kiểu tóc, khóa tự do; bỏ giá trị chưa rõ.
     *
     * @param array $variants Các dòng biến thể từ Product::getActiveVariantsByProductIds
     * @return array Danh sách phiên bản đã rút gọn
     */
    private function formatVariants($variants = [])
    {
        $result = [];
        foreach (is_array($variants) ? $variants : [] as $variant) {
            $onSale = $variant['sale_price'] !== null;
            $hold = VariantAttributes::readLevel($variant['hold_level'] ?? null, VariantAttributes::HOLD_LEVELS);
            $shine = VariantAttributes::readLevel($variant['shine_level'] ?? null, VariantAttributes::SHINE_LEVELS);

            // Đổi mảng [{key, label, value}] thành {nhãn: giá trị} cho gọn token; bỏ giá trị chưa rõ.
            $attributes = [];
            foreach (VariantAttributes::decode($variant['attributes'] ?? null) as $item) {
                if ($item['value'] !== null && $item['value'] !== '') {
                    $attributes[$item['label']] = $item['value'];
                }
            }

            $result[] = [
                'name' => $variant['variant_name'],
                'sku' => $variant['sku'],
                'price' => (float)$variant['final_price'],
                'original_price' => $onSale ? (float)$variant['price'] : null,
                'stock' => (int)$variant['stock'],
                'is_default' => (bool)$variant['is_default'],
                'hold' => is_int($hold) ? VariantAttributes::HOLD_LEVELS[$hold] : null,
                'shine' => is_int($shine) ? VariantAttributes::SHINE_LEVELS[$shine] : null,
                // {} thay vì [] khi rỗng, để Gemini luôn nhận cùng một kiểu dữ liệu (object).
                'attributes' => $attributes === [] ? new stdClass() : $attributes,
            ];
        }

        return $result;
    }
}
