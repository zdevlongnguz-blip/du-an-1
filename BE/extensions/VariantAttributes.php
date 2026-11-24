<?php

/**
 * Định dạng thuộc tính (attributes) của biến thể sản phẩm (tầng extension, dùng chung cho model và controller).
 * Đây là NƠI DUY NHẤT định nghĩa: 3 thuộc tính mặc định, 2 thang mức "giữ nếp / độ bóng" (kèm nhãn),
 * cách chuẩn hóa product_variants.attributes và cách tạo products.metadata.
 */

/**
 * Thông tin của một biến thể chia làm 2 loại (từ migration 20261123_01):
 *
 * 1. TIÊU CHÍ LỌC dạng chọn (select-option), lưu ở CỘT SỐ riêng, có CHECK giới hạn miền giá trị:
 *    - product_variants.hold_level  (1-3): độ giữ nếp, xem HOLD_LEVELS.
 *    - product_variants.shine_level (0-3): độ bóng, xem SHINE_LEVELS.
 *    Vì sao không để trong JSON: chữ tự do không lọc được ("Cực cao", "bóng lộn", "3"...). Cột số lọc bằng "=".
 *
 * 2. THUỘC TÍNH MÔ TẢ, lưu trong cột product_variants.attributes (JSON): MẢNG các phần tử { key, label, value }.
 *      [
 *        {"key": "dung_tich", "label": "Dung tích", "value": "113g"},
 *        {"key": "phu_hop",   "label": "Phù hợp",   "value": "tóc dày"},
 *        {"key": "kieu_toc",  "label": "Kiểu tóc",  "value": "side part"},
 *        {"key": "mui_huong", "label": "Mùi hương", "value": "cam chanh"}   <- khóa tự do
 *      ]
 *    Các giá trị này được gom vào products.metadata (xem Product::METADATA_REFRESH_SQL) để khách lọc theo tag bằng LIKE.
 *
 * Vì sao dùng mảng thay vì object {"dung_tich": "113g"}:
 * - Giữ đúng thứ tự (object JSON bị MySQL tự sắp lại khóa).
 * - Mỗi thuộc tính có nhãn tiếng Việt đúng dấu (label), kể cả khóa tự do; FE đọc thẳng, không giữ bảng nhãn riêng.
 *
 * Quy tắc chuẩn hóa (normalize):
 * - 3 khóa mặc định LUÔN có và LUÔN đứng đầu theo thứ tự DEFAULTS; nhãn lấy từ DEFAULTS (không tin nhãn bên ngoài);
 *   chưa rõ giá trị thì value = null.
 * - Khóa tự do đứng sau, giữ thứ tự nhập. key chỉ gồm a-z, 0-9, _ (không dấu), không trùng.
 * - Khóa trong RESERVED_KEYS (do_giu_nep, do_bong) bị BỎ: 2 thông tin này đã có cột riêng.
 * - value là chuỗi đã bỏ khoảng trắng hai đầu, hoặc null.
 *
 * Khi trả cho khách xem (trang chi tiết, giỏ hàng), forDisplay() ghép thêm 2 dòng "Độ giữ nếp", "Độ bóng"
 * (lấy nhãn từ 2 cột số) vào ngay sau "Dung tích", nên FE vẫn đọc một mảng attributes như trước.
 */
class VariantAttributes
{
    // 3 thuộc tính mặc định: key => nhãn tiếng Việt. Thứ tự ở đây là thứ tự hiển thị.
    const DEFAULTS = [
        'dung_tich' => 'Dung tích',
        'phu_hop' => 'Phù hợp',
        'kieu_toc' => 'Kiểu tóc',
    ];

    // Khóa đã chuyển sang cột số riêng: không còn được lưu trong JSON, normalize bỏ qua nếu ai đó gửi lên.
    const RESERVED_KEYS = ['do_giu_nep', 'do_bong'];

    // Thang độ giữ nếp (cột hold_level): giá trị => nhãn. 1-2-3 tương ứng low - medium - high.
    const HOLD_LEVELS = [
        1 => 'Nhẹ',
        2 => 'Trung bình',
        3 => 'Mạnh',
    ];

    // Thang độ bóng (cột shine_level): giá trị => nhãn. 0 = không bóng (mờ), 1-2-3 = bóng tăng dần.
    const SHINE_LEVELS = [
        0 => 'Không bóng (mờ)',
        1 => 'Bóng nhẹ',
        2 => 'Bóng vừa',
        3 => 'Bóng cao',
    ];

    // Nhãn hiển thị của 2 tiêu chí, dùng khi ghép vào attributes cho khách xem và trong cấu hình gửi FE.
    const HOLD_LABEL = 'Độ giữ nếp';
    const SHINE_LABEL = 'Độ bóng';

    // Ký tự nối các giá trị trong products.metadata. Dùng " | " để không lẫn với dấu phẩy trong một giá trị
    // (ví dụ "tóc dày, tóc cứng" vẫn là một giá trị).
    const METADATA_SEPARATOR = ' | ';

    // Regex kiểm tra key: chỉ chữ thường không dấu, số, gạch dưới, dài 1-50 ký tự.
    const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /**
     * Đọc attributes từ DB (chuỗi JSON) hoặc từ mảng PHP, trả về dạng chuẩn.
     *
     * Đọc được cả định dạng CŨ dạng object {"dung_tich": "113g"} (dữ liệu chưa chạy migration 20261119_01),
     * để code không vỡ trong lúc chuyển đổi.
     *
     * @param mixed $raw Chuỗi JSON hoặc mảng
     * @return array Mảng chuẩn [{key, label, value}, ...], luôn có 5 khóa mặc định ở đầu
     */
    public static function decode($raw = null)
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            $data = [];
        }

        // array_is_list: true khi chỉ số là 0, 1, 2... (mảng JSON [...]); false khi là object {"khóa": ...}.
        if ($data !== [] && !array_is_list($data)) {
            $items = [];
            foreach ($data as $key => $value) {
                $items[] = ['key' => (string) $key, 'label' => null, 'value' => $value];
            }
            $data = $items;
        }

        return self::normalize($data);
    }

    /**
     * Chuẩn hóa danh sách thuộc tính theo quy tắc ở đầu file.
     * Phần tử sai định dạng (không phải mảng, key không hợp lệ, key trùng) bị bỏ qua.
     *
     * @param array $items Danh sách phần tử dạng ['key' => ..., 'label' => ..., 'value' => ...]
     * @return array Mảng chuẩn [{key, label, value}, ...]
     */
    public static function normalize($items = [])
    {
        $valuesByKey = [];
        $customs = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item) || !is_string($item['key'] ?? null)) {
                continue;
            }

            $key = trim($item['key']);
            // Bỏ khóa đã chuyển sang cột riêng (do_giu_nep, do_bong), để JSON không giữ bản sao lệch với cột.
            if (!preg_match(self::KEY_PATTERN, $key) || array_key_exists($key, $valuesByKey) || in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }

            $valuesByKey[$key] = self::cleanValue($item['value'] ?? null);
            if (!array_key_exists($key, self::DEFAULTS)) {
                $customs[] = ['key' => $key, 'label' => self::cleanLabel($item['label'] ?? null, $key)];
            }
        }

        $result = [];
        // 3 khóa mặc định trước, theo đúng thứ tự và nhãn trong DEFAULTS.
        foreach (self::DEFAULTS as $key => $label) {
            $result[] = ['key' => $key, 'label' => $label, 'value' => $valuesByKey[$key] ?? null];
        }
        // Khóa tự do sau, giữ thứ tự nhập.
        foreach ($customs as $custom) {
            $result[] = ['key' => $custom['key'], 'label' => $custom['label'], 'value' => $valuesByKey[$custom['key']]];
        }

        return $result;
    }

    /**
     * Lấy giá trị của một khóa.
     *
     * @param array  $items Mảng chuẩn từ decode()/normalize()
     * @param string $key   Khóa cần lấy, ví dụ 'dung_tich'
     * @return string|null Giá trị, null nếu không có hoặc chưa rõ
     */
    public static function get($items = [], $key = '')
    {
        foreach (is_array($items) ? $items : [] as $item) {
            if (($item['key'] ?? null) === $key) {
                return $item['value'] ?? null;
            }
        }

        return null;
    }

    /**
     * Đọc một mức (giữ nếp / độ bóng) từ request hoặc DB, kiểm tra có nằm trong thang không.
     * Nhận số hoặc chuỗi số ("2"); null, chuỗi rỗng = chưa rõ.
     *
     * @param mixed $value  Giá trị cần đọc
     * @param array $levels HOLD_LEVELS hoặc SHINE_LEVELS
     * @return int|null|false Mức hợp lệ; null nếu để trống; false nếu sai (ngoài thang hoặc không phải số nguyên)
     */
    public static function readLevel($value = null, $levels = [])
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Chặn true/false trước: filter_var(true, FILTER_VALIDATE_INT) trả 1, sẽ bị hiểu nhầm là mức 1.
        if (is_bool($value) || is_array($value)) {
            return false;
        }
        // filter_var FILTER_VALIDATE_INT: chỉ nhận số nguyên ("2" -> 2), từ chối "2.5", "abc".
        $level = filter_var($value, FILTER_VALIDATE_INT);

        return $level !== false && array_key_exists($level, $levels) ? $level : false;
    }

    /**
     * Danh sách lựa chọn của một thang để gửi FE dựng ô chọn (select-option): [{value, label}, ...].
     *
     * @param array $levels HOLD_LEVELS hoặc SHINE_LEVELS
     * @return array Danh sách lựa chọn theo thứ tự tăng dần
     */
    public static function levelOptions($levels = [])
    {
        $options = [];
        foreach (is_array($levels) ? $levels : [] as $value => $label) {
            $options[] = ['value' => (int) $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * Cấu hình thuộc tính gửi cho FE (form admin, bộ lọc catalog), để FE không viết cứng khóa, số khóa hay nhãn.
     *
     * @return array ['defaults' => [{key, label}], 'hold_levels' => [{value, label}], 'shine_levels' => [{value, label}]]
     */
    public static function config()
    {
        $defaults = [];
        foreach (self::DEFAULTS as $key => $label) {
            $defaults[] = ['key' => $key, 'label' => $label];
        }

        return [
            'defaults' => $defaults,
            'hold_label' => self::HOLD_LABEL,
            'shine_label' => self::SHINE_LABEL,
            'hold_levels' => self::levelOptions(self::HOLD_LEVELS),
            'shine_levels' => self::levelOptions(self::SHINE_LEVELS),
        ];
    }

    /**
     * Thuộc tính để KHÁCH xem: mảng chuẩn từ JSON + 2 dòng "Độ giữ nếp", "Độ bóng" ghép vào ngay sau "Dung tích".
     * Nhờ vậy trang chi tiết và giỏ hàng vẫn đọc một mảng attributes như trước, không phải sửa FE.
     * Mức chưa rõ (null) thì value = null; FE tự ẩn dòng không có giá trị.
     *
     * @param mixed    $raw        Chuỗi JSON attributes hoặc mảng
     * @param int|null $holdLevel  Cột hold_level
     * @param int|null $shineLevel Cột shine_level
     * @return array Mảng [{key, label, value}, ...]
     */
    public static function forDisplay($raw = null, $holdLevel = null, $shineLevel = null)
    {
        $items = self::decode($raw);
        $hold = self::readLevel($holdLevel, self::HOLD_LEVELS);
        $shine = self::readLevel($shineLevel, self::SHINE_LEVELS);
        $levelItems = [
            ['key' => 'do_giu_nep', 'label' => self::HOLD_LABEL, 'value' => is_int($hold) ? self::HOLD_LEVELS[$hold] : null],
            ['key' => 'do_bong', 'label' => self::SHINE_LABEL, 'value' => is_int($shine) ? self::SHINE_LEVELS[$shine] : null],
        ];

        // array_splice chèn 2 dòng mức vào vị trí 1 (sau "Dung tích" luôn đứng đầu), giữ thứ tự hiển thị quen thuộc.
        array_splice($items, 1, 0, $levelItems);

        return $items;
    }

    /**
     * Chuỗi JSON cho biến thể mới tạo: đủ 3 khóa mặc định, value = null.
     *
     * @return string Chuỗi JSON mảng
     */
    public static function defaultsJson()
    {
        // JSON_UNESCAPED_UNICODE: giữ nguyên chữ có dấu thay vì đổi thành \uXXXX.
        return json_encode(self::normalize([]), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Làm sạch giá trị: chuỗi hoặc số thì đổi thành chuỗi đã bỏ khoảng trắng hai đầu; rỗng, null, kiểu khác thì null.
     *
     * @param mixed $value Giá trị đầu vào
     * @return string|null Giá trị đã làm sạch
     */
    private static function cleanValue($value = null)
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Làm sạch nhãn của khóa tự do; không có nhãn thì dùng key, đổi "_" thành dấu cách.
     *
     * @param mixed  $label Nhãn đầu vào
     * @param string $key   Khóa, dùng làm nhãn dự phòng
     * @return string Nhãn hiển thị
     */
    private static function cleanLabel($label = null, $key = '')
    {
        $label = is_string($label) ? trim($label) : '';

        return $label !== '' ? $label : str_replace('_', ' ', $key);
    }
}
