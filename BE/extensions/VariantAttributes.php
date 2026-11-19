<?php

/**
 * Định dạng thuộc tính (attributes) của biến thể sản phẩm (tầng extension, dùng chung cho model/controller/service).
 * Đây là NƠI DUY NHẤT định nghĩa 5 thuộc tính mặc định và cách chuẩn hóa product_variants.attributes.
 */

/**
 * Định dạng lưu trong cột product_variants.attributes (JSON): MẢNG các phần tử { key, label, value }.
 *
 *   [
 *     {"key": "dung_tich",  "label": "Dung tích",  "value": "113g"},
 *     {"key": "do_giu_nep", "label": "Độ giữ nếp", "value": "trung bình"},
 *     {"key": "do_bong",    "label": "Độ bóng",    "value": null},
 *     {"key": "phu_hop",    "label": "Phù hợp",    "value": "tóc dày"},
 *     {"key": "kieu_toc",   "label": "Kiểu tóc",   "value": "side part"},
 *     {"key": "mui_huong",  "label": "Mùi hương",  "value": "cam chanh"}   <- khóa tự do
 *   ]
 *
 * Vì sao dùng mảng thay vì object {"dung_tich": "113g"}:
 * - Giữ đúng thứ tự (object JSON bị MySQL tự sắp lại khóa).
 * - Mỗi thuộc tính có nhãn tiếng Việt đúng dấu (label), kể cả khóa tự do; FE đọc thẳng, không giữ bảng nhãn riêng.
 *
 * Quy tắc chuẩn hóa (normalize):
 * - 5 khóa mặc định LUÔN có và LUÔN đứng đầu theo thứ tự DEFAULTS; nhãn lấy từ DEFAULTS (không tin nhãn bên ngoài);
 *   chưa rõ giá trị thì value = null.
 * - Khóa tự do đứng sau, giữ thứ tự nhập. key chỉ gồm a-z, 0-9, _ (không dấu), không trùng.
 * - value là chuỗi đã bỏ khoảng trắng hai đầu, hoặc null.
 */
class VariantAttributes
{
    // 5 thuộc tính mặc định: key => nhãn tiếng Việt. Thứ tự ở đây là thứ tự hiển thị và thứ tự câu trong embed_text.
    const DEFAULTS = [
        'dung_tich' => 'Dung tích',
        'do_giu_nep' => 'Độ giữ nếp',
        'do_bong' => 'Độ bóng',
        'phu_hop' => 'Phù hợp',
        'kieu_toc' => 'Kiểu tóc',
    ];

    // Regex kiểm tra key: chỉ chữ thường không dấu, số, gạch dưới, dài 1-50 ký tự.
    const KEY_PATTERN = '/^[a-z0-9_]{1,50}$/';

    /**
     * Đọc attributes từ DB (chuỗi JSON) hoặc từ mảng PHP, trả về dạng chuẩn.
     *
     * Đọc được cả định dạng CŨ dạng object {"dung_tich": "113g"} (dữ liệu chưa chạy migration 20261119_02),
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
            if (!preg_match(self::KEY_PATTERN, $key) || array_key_exists($key, $valuesByKey)) {
                continue;
            }

            $valuesByKey[$key] = self::cleanValue($item['value'] ?? null);
            if (!array_key_exists($key, self::DEFAULTS)) {
                $customs[] = ['key' => $key, 'label' => self::cleanLabel($item['label'] ?? null, $key)];
            }
        }

        $result = [];
        // 5 khóa mặc định trước, theo đúng thứ tự và nhãn trong DEFAULTS.
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
     * Chuỗi JSON cho biến thể mới tạo: đủ 5 khóa mặc định, value = null.
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
