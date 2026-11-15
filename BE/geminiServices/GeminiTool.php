<?php

/**
 * Lớp cha cho mọi tool nghiệp vụ mà Gemini được phép gọi (tầng service).
 * Tool mới (ví dụ GeminiToolSearchProduct) chỉ cần kế thừa class này và viết 4 method.
 */

/**
 * Tool call (function calling): Gemini không tự đọc DB được. Nó chỉ trả về
 * "tôi muốn gọi hàm X với tham số Y"; server chạy hàm đó rồi gửi kết quả lại
 * để Gemini viết câu trả lời cho người dùng.
 *
 * Mỗi tool mô tả cho Gemini biết: tên, công dụng, tham số (theo JSON Schema).
 */
abstract class GeminiTool
{
    /**
     * Tên tool, Gemini dùng tên này khi yêu cầu gọi.
     * Chỉ dùng chữ, số, dấu gạch dưới (ví dụ search_product).
     *
     * @return string
     */
    abstract public function name();

    /**
     * Mô tả công dụng, viết rõ để Gemini biết KHI NÀO nên gọi tool này.
     *
     * @return string
     */
    abstract public function description();

    /**
     * Khai báo tham số theo JSON Schema (kiểu OpenAPI mà Gemini hỗ trợ).
     * JSON Schema: cách mô tả dữ liệu, ví dụ "keyword là string, bắt buộc".
     *
     * Ví dụ:
     * [
     *     'type' => 'object',
     *     'properties' => ['keyword' => ['type' => 'string', 'description' => 'Từ khóa']],
     *     'required' => ['keyword'],
     * ]
     *
     * @return array Schema; trả mảng rỗng nếu tool không cần tham số
     */
    abstract public function parameters();

    /**
     * Chạy nghiệp vụ thật với tham số Gemini gửi lên.
     *
     * Lưu ý bảo mật: $args do AI sinh ra, phải validate như dữ liệu người dùng nhập.
     * Không tin user ID trong $args; lấy từ $context (payload JWT của người đang đăng nhập).
     *
     * @param array $args    Tham số Gemini truyền vào
     * @param array $context Ngữ cảnh do server cung cấp (ví dụ ['user_id' => 5])
     * @return array Kết quả trả cho Gemini (sẽ được mã hóa JSON)
     */
    abstract public function execute($args = [], $context = []);

    /**
     * Tạo khai báo function (functionDeclaration) gửi kèm request cho Gemini.
     *
     * @return array
     */
    public function declaration()
    {
        $declaration = [
            'name' => $this->name(),
            'description' => $this->description(),
        ];

        $parameters = $this->parameters();
        // Tool không có tham số thì bỏ hẳn key parameters,
        // vì gửi "properties": [] (mảng) thay vì {} sẽ bị Gemini báo sai schema.
        if (is_array($parameters) && !empty($parameters['properties'])) {
            $declaration['parameters'] = $parameters;
        }

        return $declaration;
    }

    /**
     * Tạo lệnh điều hướng gửi cho FE, dùng chung cho mọi tool cần mở một trang.
     * Tool đặt lệnh này vào key Gemini::ACTION_KEY trong kết quả trả về.
     *
     * FE chỉ chấp nhận tên route trong danh sách cho phép, không nhận URL tự do,
     * nên ở đây cũng chỉ trả tên route và tham số.
     *
     * @param string $route  Tên route bên FE (Vue Router), ví dụ catalog
     * @param array  $query  Tham số trên URL sau dấu ?, ví dụ ['search' => 'sáp']
     * @param array  $params Tham số trong đường dẫn, ví dụ ['slug' => 'sap-vuot-toc']
     * @return array ['type' => 'navigate', 'route' => ..., 'query' => {...}, 'params' => {...}]
     */
    protected function navigateAction($route = 'catalog', $query = [], $params = [])
    {
        return [
            'type' => 'navigate',
            'route' => (string)$route,
            // Mảng rỗng [] phải đổi thành object rỗng {} để FE luôn nhận cùng một kiểu dữ liệu.
            'query' => $query === [] ? new stdClass() : $query,
            'params' => $params === [] ? new stdClass() : $params,
        ];
    }
}
