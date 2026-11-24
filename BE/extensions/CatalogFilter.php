<?php

/**
 * Đọc và kiểm tra tham số lọc sản phẩm (tầng extension), DÙNG CHUNG cho:
 * - API danh sách sản phẩm api-products (ProductController::index), tham số lấy từ URL;
 * - tool search_product của trợ lý AI (GeminiToolSearchProduct), tham số do AI sinh ra.
 * Một luật kiểm tra duy nhất, nên catalog và trợ lý AI luôn hiểu bộ lọc giống nhau.
 */

/**
 * Bộ lọc gồm (đều không bắt buộc):
 * - search:    từ khóa tìm rộng theo tên, mã, mô tả, metadata, SKU / tên phiên bản (tối đa 100 ký tự)
 * - category:  slug danh mục
 * - min_price, max_price: khoảng giá bán (VND, số >= 0)
 * - hold:      độ giữ nếp 1-3 (VariantAttributes::HOLD_LEVELS)
 * - shine:     độ bóng 0-3 (VariantAttributes::SHINE_LEVELS)
 * - tags:      tối đa 5 tag, mỗi tag tối đa 50 ký tự, so khớp LIKE trong products.metadata (phải khớp TẤT CẢ tag)
 * - sort:      newest (mới nhất, mặc định) | oldest (cũ nhất)
 *
 * Lỗi: ném InvalidArgumentException với câu tiếng Việt dễ hiểu. Controller đổi thành HTTP 400;
 * tool AI để nguyên exception để Gemini nhận được câu báo lỗi và tự sửa tham số.
 */
class CatalogFilter
{
    // Kiểu sắp xếp cho phép => nhãn hiển thị. NGUỒN DUY NHẤT: api-product-filters gửi danh sách này cho FE dựng ô chọn,
    // tool AI lấy khóa làm enum. Mỗi khóa phải có mệnh đề ORDER BY tương ứng ở Product::CATALOG_SORT_SQL
    // (allowlist, chống SQL injection). Thêm kiểu mới: sửa 2 chỗ đó, FE và AI tự theo.
    const SORTS = [
        'newest' => 'Mới nhất',
        'oldest' => 'Cũ nhất',
    ];

    const MAX_SEARCH_LENGTH = 100;
    const MAX_TAGS = 5;
    const MAX_TAG_LENGTH = 50;
    // Giá tối đa chấp nhận, khớp DECIMAL(10,2) của cột giá biến thể.
    const MAX_PRICE = 99999999.99;

    /**
     * Đọc bộ lọc từ một mảng tham số (ví dụ $_GET hoặc tham số AI gửi).
     *
     * @param array $input Mảng tham số thô
     * @return array Bộ lọc đã chuẩn hóa: ['search' => string, 'category' => ?string, 'min_price' => ?float,
     *               'max_price' => ?float, 'hold' => ?int, 'shine' => ?int, 'tags' => string[], 'sort' => string]
     * @throws InvalidArgumentException Khi có tham số sai kiểu, sai miền giá trị hoặc quá dài
     */
    public static function fromInput($input = [])
    {
        $input = is_array($input) ? $input : [];

        // URL kiểu ?search[]=x làm PHP đọc ra MẢNG thay vì chuỗi: phải chặn, nếu không SQL nhận mảng sẽ lỗi.
        $search = $input['search'] ?? '';
        if (!is_string($search)) {
            throw new InvalidArgumentException('Từ khóa tìm kiếm không hợp lệ');
        }
        $search = trim($search);
        // mb_strlen đếm theo ký tự (tiếng Việt có dấu chiếm nhiều byte, strlen sẽ đếm sai).
        if (mb_strlen($search, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('Từ khóa tìm kiếm tối đa ' . self::MAX_SEARCH_LENGTH . ' ký tự');
        }

        $category = $input['category'] ?? '';
        if (!is_string($category) || strlen(trim($category)) > 255) {
            throw new InvalidArgumentException('Slug danh mục không hợp lệ');
        }
        $category = trim($category) === '' ? null : trim($category);

        $minPrice = self::readPrice($input['min_price'] ?? null, 'min_price');
        $maxPrice = self::readPrice($input['max_price'] ?? null, 'max_price');
        if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
            throw new InvalidArgumentException('Giá thấp nhất không được lớn hơn giá cao nhất');
        }

        $hold = VariantAttributes::readLevel($input['hold'] ?? null, VariantAttributes::HOLD_LEVELS);
        if ($hold === false) {
            throw new InvalidArgumentException('Độ giữ nếp phải là một trong: ' . implode(', ', array_keys(VariantAttributes::HOLD_LEVELS)));
        }
        $shine = VariantAttributes::readLevel($input['shine'] ?? null, VariantAttributes::SHINE_LEVELS);
        if ($shine === false) {
            throw new InvalidArgumentException('Độ bóng phải là một trong: ' . implode(', ', array_keys(VariantAttributes::SHINE_LEVELS)));
        }

        $sort = $input['sort'] ?? 'newest';
        if ($sort === '' || $sort === null) {
            $sort = 'newest';
        }
        if (!is_string($sort) || !array_key_exists($sort, self::SORTS)) {
            throw new InvalidArgumentException('Kiểu sắp xếp phải là một trong: ' . implode(', ', array_keys(self::SORTS)));
        }

        return [
            'search' => $search,
            'category' => $category,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'hold' => $hold,
            'shine' => $shine,
            'tags' => self::readTags($input['tags'] ?? null),
            'sort' => $sort,
        ];
    }

    /**
     * Đọc một giá: null/rỗng = không lọc; phải là số từ 0 đến MAX_PRICE.
     *
     * @param mixed  $value Giá trị thô
     * @param string $name  Tên tham số, dùng trong câu báo lỗi
     * @return float|null
     * @throws InvalidArgumentException Khi không phải số hoặc ngoài khoảng cho phép
     */
    private static function readPrice($value = null, $name = '')
    {
        if ($value === null || $value === '') {
            return null;
        }
        // is_numeric nhận "150000", "150000.5", 150000; từ chối "abc", mảng. Chặn thêm số âm và quá lớn.
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)
            || (float) $value < 0 || (float) $value > self::MAX_PRICE) {
            throw new InvalidArgumentException("{$name} phải là số từ 0 trở lên");
        }

        return (float) $value;
    }

    /**
     * Đọc danh sách tag và chuẩn hóa về MỘT dạng duy nhất. Nhận chuỗi phân tách bằng dấu phẩy (từ URL: "tóc dày,quiff")
     * hoặc mảng chuỗi (từ AI). Phần tử mảng có dấu phẩy cũng được tách ("tóc dày, tóc cứng" -> 2 tag).
     *
     * Vì sao luôn tách theo dấu phẩy: dấu phẩy là ký tự nối tag trên URL (toQuery). Nếu AI gửi 1 tag có dấu phẩy mà
     * không tách, kết quả của trợ lý (1 tag) sẽ khác trang catalog nó mở ra (URL bị tách thành 2 tag).
     * Chuẩn hóa ngay ở đầu vào thì mọi nơi (catalog, trợ lý, URL) hiểu cùng một danh sách tag.
     * Bỏ tag rỗng, bỏ trùng (không phân biệt hoa thường).
     *
     * @param mixed $value Giá trị thô
     * @return string[] Danh sách tag đã trim, không chứa dấu phẩy
     * @throws InvalidArgumentException Khi sai kiểu, quá nhiều tag hoặc tag quá dài
     */
    private static function readTags($value = null)
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Tag không hợp lệ');
        }

        $parts = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Mỗi tag phải là chuỗi');
            }
            foreach (explode(',', $item) as $part) {
                $parts[] = $part;
            }
        }

        $tags = [];
        foreach ($parts as $tag) {
            $tag = trim(preg_replace('/\s+/u', ' ', $tag));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag, 'UTF-8') > self::MAX_TAG_LENGTH) {
                throw new InvalidArgumentException('Mỗi tag tối đa ' . self::MAX_TAG_LENGTH . ' ký tự');
            }
            $tags[mb_strtolower($tag, 'UTF-8')] = $tag;
        }
        if (count($tags) > self::MAX_TAGS) {
            throw new InvalidArgumentException('Tối đa ' . self::MAX_TAGS . ' tag');
        }

        return array_values($tags);
    }

    /**
     * Mô tả bằng chữ các bộ lọc đã áp dụng. NGUỒN DUY NHẤT của câu chữ này, dùng cho:
     * - kết quả tool AI search_product (applied_filters) để Gemini nhắc lại đúng điều kiện với khách;
     * - chip "Đã tra cứu sản phẩm: ..." dưới câu trả lời trong khung chat (GeminiToolSummary).
     *
     * @param array  $filters       Bộ lọc từ fromInput()
     * @param string $categoryLabel Tên danh mục để hiển thị (bộ lọc chỉ giữ slug); rỗng thì dùng slug
     * @return string[] Ví dụ ['Độ giữ nếp: Mạnh', 'Giá đến 500000đ']; rỗng nếu không lọc gì
     */
    public static function describe($filters = [], $categoryLabel = '')
    {
        $parts = [];
        if (($filters['search'] ?? '') !== '') {
            $parts[] = 'Từ khóa: ' . $filters['search'];
        }
        if (($filters['category'] ?? null) !== null) {
            $label = trim((string)$categoryLabel);
            $parts[] = 'Danh mục: ' . ($label !== '' ? $label : $filters['category']);
        }
        if (($filters['min_price'] ?? null) !== null) {
            $parts[] = 'Giá từ ' . (int)$filters['min_price'] . 'đ';
        }
        if (($filters['max_price'] ?? null) !== null) {
            $parts[] = 'Giá đến ' . (int)$filters['max_price'] . 'đ';
        }
        if (($filters['hold'] ?? null) !== null) {
            $parts[] = VariantAttributes::HOLD_LABEL . ': ' . VariantAttributes::HOLD_LEVELS[$filters['hold']];
        }
        if (($filters['shine'] ?? null) !== null) {
            $parts[] = VariantAttributes::SHINE_LABEL . ': ' . VariantAttributes::SHINE_LEVELS[$filters['shine']];
        }
        if (!empty($filters['tags'])) {
            $parts[] = 'Đặc điểm: ' . implode(', ', $filters['tags']);
        }

        return $parts;
    }

    /**
     * Danh sách kiểu sắp xếp cho FE dựng ô chọn: [{value, label}, ...], lấy từ SORTS (nguồn duy nhất).
     *
     * @return array
     */
    public static function sortOptions()
    {
        $options = [];
        foreach (self::SORTS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * Giới hạn đầu vào gửi FE, để FE kiểm tra sớm cùng con số với BE (BE vẫn kiểm tra lại), không viết cứng.
     *
     * @return array ['max_search_length' => int, 'max_tags' => int, 'max_tag_length' => int]
     */
    public static function limits()
    {
        return [
            'max_search_length' => self::MAX_SEARCH_LENGTH,
            'max_tags' => self::MAX_TAGS,
            'max_tag_length' => self::MAX_TAG_LENGTH,
        ];
    }

    /**
     * Bộ lọc dạng tham số URL, để echo lại cho FE và để trợ lý AI mở trang catalog với đúng bộ lọc.
     * Bỏ tham số rỗng / mặc định, nên URL gọn.
     *
     * @param array $filters Bộ lọc từ fromInput()
     * Mọi giá trị là CHUỖI (tham số URL luôn là chuỗi; FE chỉ nhận chuỗi khi trợ lý AI yêu cầu mở trang).
     *
     * @return array Ví dụ ['search' => 'sáp', 'hold' => '3', 'tags' => 'tóc dày,quiff']
     */
    public static function toQuery($filters = [])
    {
        $query = [];
        foreach (['search', 'category', 'min_price', 'max_price', 'hold', 'shine'] as $key) {
            $value = $filters[$key] ?? null;
            if ($value !== null && $value !== '') {
                // Giá nguyên (150000.0) in thành "150000" cho URL gọn.
                $query[$key] = (string) (is_float($value) && floor($value) === $value ? (int) $value : $value);
            }
        }
        if (!empty($filters['tags'])) {
            $query['tags'] = implode(',', $filters['tags']);
        }
        if (($filters['sort'] ?? 'newest') !== 'newest') {
            $query['sort'] = $filters['sort'];
        }

        return $query;
    }
}
