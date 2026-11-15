<?php

/**
 * Service gọi Gemini (AI của Google) có hỗ trợ tool call (tầng service).
 * Controller chỉ cần gọi Gemini::chat(); việc gọi API và vòng lặp gọi tool được xử lý ở đây.
 */

/**
 * Luồng xử lý một câu hỏi:
 * 1. Thêm câu hỏi vào lịch sử, gửi Gemini kèm system prompt và danh sách tool.
 * 2. Nếu Gemini trả về functionCall ("tôi muốn gọi tool X"): server chạy tool, gửi kết quả lại, quay về bước 1.
 * 3. Nếu Gemini trả về chữ: đó là câu trả lời cuối cho người dùng.
 *
 * Lịch sử hội thoại (history) là mảng "contents" theo định dạng Gemini, mỗi phần tử là một lượt:
 * ['role' => 'user'|'model', 'parts' => [...]].
 * Gemini không tự nhớ các lượt trước, nên mỗi request phải gửi lại toàn bộ lịch sử.
 */
class Gemini
{
    // ===================== CẤU HÌNH =====================

    // Địa chỉ gốc của Gemini API (bản v1beta hỗ trợ function calling).
    const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    // Số giây tối đa chờ Gemini trả lời một request, tránh treo PHP quá lâu.
    const TIMEOUT_SECONDS = 30;

    // Số vòng tối đa "Gemini gọi tool -> server chạy tool -> gửi kết quả lại".
    // Giới hạn này chặn vòng lặp vô hạn nếu model cứ gọi tool mãi.
    const MAX_TOOL_ROUNDS = 5;

    // Số lượt tối đa giữ trong lịch sử, tránh gửi quá nhiều token (tốn tiền, chậm).
    const MAX_HISTORY = 30;

    // Model dùng khi GEMINI_MODEL trong BE/.env để trống hoặc sai định dạng.
    const DEFAULT_MODEL = 'gemini-2.5-flash';

    // Key dành riêng trong kết quả tool để gửi lệnh cho FE (ví dụ điều hướng trang).
    // Key này bị tách ra trước khi gửi kết quả cho Gemini: AI không thấy và không sửa được lệnh.
    const ACTION_KEY = '_action';

    // Mẫu dòng gợi ý mà system prompt yêu cầu Gemini viết ở cuối câu trả lời:
    // [[GOI_Y: Thêm vào giỏ hàng | Đặt mua ngay | Xem sản phẩm tương tự]]
    // Regex chấp nhận cả "GỢI Ý" có dấu, phòng khi model tự thêm dấu tiếng Việt.
    const SUGGESTION_PATTERN = '/\[\[\s*G[OỢ]I[ _]?[YÝ]\s*:(.*?)\]\]/iu';

    // Số gợi ý tối đa và độ dài tối đa (ký tự) mỗi gợi ý gửi cho FE. AI có thể viết dài hoặc nhiều hơn yêu cầu.
    const MAX_SUGGESTIONS = 3;
    const MAX_SUGGESTION_LENGTH = 40;

    /** @var string System prompt gửi kèm mọi request */
    private $systemInstruction;

    /** @var GeminiTool[] Danh sách tool, key là tên tool */
    private $tools = [];

    /**
     * @param string|null $systemInstruction System prompt riêng (null = dùng GeminiPrompt::system())
     */
    public function __construct($systemInstruction = null)
    {
        $this->systemInstruction = is_string($systemInstruction)
            ? trim($systemInstruction)
            : GeminiPrompt::system();
    }

    // ===================== ĐĂNG KÝ TOOL =====================

    /**
     * Đăng ký một tool cho Gemini được phép gọi.
     *
     * @param GeminiTool $tool Tool nghiệp vụ
     * @return $this Trả về chính đối tượng để gọi nối tiếp: ->addTool(a)->addTool(b)
     * @throws InvalidArgumentException Khi tên tool sai định dạng hoặc bị trùng
     */
    public function addTool($tool = null)
    {
        if (!$tool instanceof GeminiTool) {
            throw new InvalidArgumentException('Tool phải kế thừa GeminiTool.');
        }

        $name = (string) $tool->name();
        // Gemini chỉ chấp nhận tên bắt đầu bằng chữ/gạch dưới, gồm chữ, số, gạch dưới, tối đa 64 ký tự.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException('Tên tool không hợp lệ: ' . $name);
        }
        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException('Tool bị đăng ký trùng: ' . $name);
        }

        $this->tools[$name] = $tool;
        return $this;
    }

    // ===================== HÀM CHÍNH =====================

    /**
     * Gửi một câu hỏi và nhận câu trả lời cuối cùng (đã xử lý xong các lần gọi tool).
     *
     * @param string $message Câu hỏi của người dùng
     * @param array  $history Lịch sử hội thoại các lượt trước (rỗng = hội thoại mới)
     * @param array  $context Ngữ cảnh server truyền xuống tool (ví dụ ['user_id' => sub trong JWT])
     * @return array [
     *     'text'       => câu trả lời của Gemini,
     *     'tool_calls' => danh sách tool đã chạy (tên, tham số) để debug/hiển thị,
     *     'history'    => lịch sử sau khi cập nhật (lưu lại, lần sau truyền vào để hỏi tiếp),
     *     'actions'    => lệnh cho FE do tool tạo ra (tối đa 1 lệnh, của lần gọi tool cuối cùng),
     *     'suggestions'=> các câu gợi ý khách có thể hỏi tiếp (do Gemini sinh, đã tách khỏi 'text'),
     * ]
     * @throws InvalidArgumentException Khi câu hỏi rỗng
     * @throws RuntimeException Khi gọi Gemini lỗi, bị chặn hoặc vượt quá số vòng tool
     */
    public function chat($message = '', $history = [], $context = [])
    {
        $message = trim((string) $message);
        if ($message === '') {
            throw new InvalidArgumentException('Câu hỏi không được để trống.');
        }

        $history = $this->cleanHistory($history);
        $context = is_array($context) ? $context : [];
        $history[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $toolCalls = [];
        $actions = [];

        // Vòng đầu là câu hỏi gốc, các vòng sau là gửi kết quả tool, nên tổng là MAX + 1 request.
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $response = $this->callApi($this->buildPayload($history));
            $content = $response['candidates'][0]['content'] ?? null;

            if (!is_array($content) || empty($content['parts']) || !is_array($content['parts'])) {
                // Gemini có thể chặn câu trả lời (an toàn nội dung) và không trả parts.
                $reason = $response['candidates'][0]['finishReason']
                    ?? ($response['promptFeedback']['blockReason'] ?? 'UNKNOWN');
                throw new RuntimeException('Gemini không trả về nội dung (lý do: ' . $reason . ').');
            }

            // Lưu NGUYÊN VẸN lượt của Gemini (kể cả thoughtSignature nếu có), vì khi gửi
            // kết quả tool lại, Gemini cần thấy đúng lượt functionCall mà nó đã tạo.
            $history[] = ['role' => 'model', 'parts' => $this->fixEmptyArgs($content['parts'])];

            $calls = $this->extractFunctionCalls($content['parts']);
            if ($calls === []) {
                // Tách dòng gợi ý khỏi câu trả lời. Lịch sử vẫn giữ nguyên dòng này
                // để ở lượt sau Gemini thấy lại đúng định dạng mà nó đã dùng.
                [$text, $suggestions] = $this->splitSuggestions($this->extractText($content['parts']));
                return [
                    'text' => $text,
                    'suggestions' => $suggestions,
                    'tool_calls' => $toolCalls,
                    'history' => $history,
                    'actions' => $actions,
                ];
            }

            // Gemini có thể yêu cầu nhiều tool cùng lúc; chạy hết rồi gửi kết quả trong MỘT lượt,
            // đúng thứ tự các functionCall, vì Gemini ghép kết quả theo thứ tự đó.
            $responseParts = [];
            foreach ($calls as $call) {
                $toolCalls[] = ['name' => $call['name'], 'args' => $call['args']];
                $result = $this->runTool($call['name'], $call['args'], $context);

                // Tách lệnh cho FE ra khỏi kết quả trước khi gửi cho Gemini và trước khi lưu lịch sử.
                // Chỉ giữ lệnh của lần gọi tool cuối cùng: nếu Gemini tìm nhiều lần,
                // trang được mở phải khớp với lần tìm sau cùng mà câu trả lời dựa vào.
                if (is_array($result) && array_key_exists(self::ACTION_KEY, $result)) {
                    if (is_array($result[self::ACTION_KEY])) {
                        $actions = [$result[self::ACTION_KEY]];
                    }
                    unset($result[self::ACTION_KEY]);
                }

                $responseParts[] = [
                    'functionResponse' => [
                        'name' => $call['name'],
                        'response' => $this->toJsonObject($result),
                    ],
                ];
            }
            // Theo tài liệu Gemini, kết quả tool được gửi lại với role 'user'.
            $history[] = ['role' => 'user', 'parts' => $responseParts];
        }

        throw new RuntimeException('Gemini gọi tool quá ' . self::MAX_TOOL_ROUNDS . ' vòng, đã dừng.');
    }

    // ===================== GỌI API =====================

    /**
     * Gửi payload tới endpoint generateContent của Gemini bằng cURL.
     *
     * cURL: thư viện có sẵn trong PHP để gửi request HTTP từ server tới server khác.
     * Để protected (không phải private) để khi thử nghiệm có thể viết class con giả lập API.
     *
     * @param array $payload Body theo định dạng Gemini (contents, tools, systemInstruction)
     * @return array Response của Gemini dạng mảng
     * @throws RuntimeException Khi thiếu API key, lỗi mạng, Gemini trả HTTP lỗi hoặc JSON không hợp lệ
     */
    protected function callApi($payload = [])
    {
        $apiKey = defined('GEMINI_API_KEY') ? trim((string) GEMINI_API_KEY) : '';
        if ($apiKey === '') {
            // Không in key ra thông báo lỗi, chỉ báo tên biến còn thiếu.
            throw new RuntimeException('Thiếu GEMINI_API_KEY trong BE/.env.');
        }

        $model = defined('GEMINI_MODEL') ? trim((string) GEMINI_MODEL) : '';
        // Tên model chỉ gồm chữ, số, dấu chấm, gạch ngang; chặn ký tự lạ chèn vào URL.
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/', $model)) {
            $model = self::DEFAULT_MODEL;
        }
        $url = self::BASE_URL . '/models/' . $model . ':generateContent';

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Không mã hóa được dữ liệu gửi Gemini.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            // RETURNTRANSFER: lấy response thành chuỗi thay vì in thẳng ra màn hình.
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                // Gửi key qua header thay vì query string để key không lọt vào log URL.
                'x-goog-api-key: ' . $apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Không kết nối được Gemini: ' . $curlError);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Gemini trả về dữ liệu không phải JSON (HTTP ' . $httpCode . ').');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Chỉ lấy message lỗi Gemini gửi về, không kèm header hay key.
            $message = $data['error']['message'] ?? 'Lỗi không xác định';
            throw new RuntimeException('Gemini báo lỗi (HTTP ' . $httpCode . '): ' . $message);
        }

        return $data;
    }

    /**
     * Tạo body request gửi Gemini.
     *
     * @param array $history Lịch sử hội thoại (đã gồm câu hỏi mới)
     * @return array Payload
     */
    private function buildPayload($history = [])
    {
        $payload = ['contents' => $this->trimHistory($history)];

        if ($this->systemInstruction !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $this->systemInstruction]]];
        }

        if ($this->tools !== []) {
            $declarations = [];
            foreach ($this->tools as $tool) {
                $declarations[] = $tool->declaration();
            }
            $payload['tools'] = [['functionDeclarations' => $declarations]];
        }

        return $payload;
    }

    // ===================== CHẠY TOOL =====================

    /**
     * Chạy một tool và luôn trả về mảng kết quả cho Gemini.
     *
     * Lỗi trong tool không làm hỏng cả request: ta gửi thông báo lỗi cho Gemini
     * để nó tự giải thích lại cho người dùng. Chỉ gửi message ngắn, không gửi stack trace.
     *
     * @param string $name    Tên tool Gemini yêu cầu
     * @param array  $args    Tham số Gemini truyền
     * @param array  $context Ngữ cảnh server
     * @return mixed Kết quả tool hoặc ['error' => ...]
     */
    private function runTool($name = '', $args = [], $context = [])
    {
        if (!isset($this->tools[$name])) {
            // Gemini đôi khi "bịa" tên tool không có; báo lại để nó chọn cách khác.
            return ['error' => 'Tool không tồn tại: ' . $name];
        }

        try {
            return $this->tools[$name]->execute($args, $context);
        } catch (InvalidArgumentException $e) {
            // Lỗi do tham số sai: message do chính tool viết, an toàn để gửi cho Gemini.
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            // Lỗi hệ thống (DB...): ghi log cho dev, chỉ gửi thông báo chung.
            error_log('[Gemini] Tool ' . $name . ' lỗi: ' . $e->getMessage());
            return ['error' => 'Tool gặp lỗi hệ thống, không lấy được dữ liệu.'];
        }
    }

    // ===================== XỬ LÝ DỮ LIỆU =====================

    /**
     * Lọc lịch sử truyền vào, bỏ các lượt sai định dạng để không gửi dữ liệu hỏng sang Gemini.
     *
     * @param array $history Lịch sử thô
     * @return array Lịch sử hợp lệ
     */
    private function cleanHistory($history = [])
    {
        $clean = [];
        foreach (is_array($history) ? $history : [] as $content) {
            if (is_array($content)
                && in_array($content['role'] ?? '', ['user', 'model'], true)
                && !empty($content['parts']) && is_array($content['parts'])) {
                $clean[] = ['role' => $content['role'], 'parts' => $this->fixEmptyArgs($content['parts'])];
            }
        }
        return $clean;
    }

    /**
     * Cắt bớt lịch sử quá dài, chỉ giữ MAX_HISTORY lượt gần nhất.
     *
     * Lịch sử phải bắt đầu bằng câu hỏi dạng chữ của người dùng. Nếu cắt trúng giữa
     * cặp functionCall/functionResponse thì bỏ tiếp các lượt đầu cho tới khi hợp lệ,
     * nếu không Gemini sẽ báo lỗi thiếu lượt gọi tool tương ứng.
     *
     * @param array $history Lịch sử đầy đủ
     * @return array Lịch sử đã cắt
     */
    private function trimHistory($history = [])
    {
        if (count($history) <= self::MAX_HISTORY) {
            return $history;
        }

        $history = array_slice($history, -self::MAX_HISTORY);
        while ($history !== [] && !(($history[0]['role'] ?? '') === 'user' && isset($history[0]['parts'][0]['text']))) {
            array_shift($history);
        }
        return $history;
    }

    /**
     * Đổi "args": [] thành "args": {} trong các functionCall.
     *
     * Vì sao: json_decode(..., true) biến object rỗng {} thành mảng rỗng [].
     * Gửi lại "args": [] sẽ bị Gemini coi là sai kiểu, nên phải đổi về object rỗng (stdClass).
     *
     * @param array $parts Các part của một lượt
     * @return array Các part đã sửa
     */
    private function fixEmptyArgs($parts = [])
    {
        foreach ($parts as $i => $part) {
            if (isset($part['functionCall']) && ($part['functionCall']['args'] ?? null) === []) {
                $parts[$i]['functionCall']['args'] = new stdClass();
            }
        }
        return $parts;
    }

    /**
     * Ép kết quả tool về dạng object JSON {...} mà functionResponse bắt buộc.
     *
     * - Mảng rỗng hoặc null -> {}
     * - Mảng danh sách [a, b] (array_is_list) hoặc giá trị đơn -> {"result": ...}
     *
     * @param mixed $result Kết quả tool trả về
     * @return array|stdClass
     */
    private function toJsonObject($result = null)
    {
        if ($result === null || $result === []) {
            return new stdClass();
        }
        if (!is_array($result) || array_is_list($result)) {
            return ['result' => $result];
        }
        return $result;
    }

    /**
     * Lấy các yêu cầu gọi tool trong câu trả lời của Gemini.
     *
     * @param array $parts Các part của content
     * @return array Danh sách ['name' => ..., 'args' => [...]]
     */
    private function extractFunctionCalls($parts = [])
    {
        $calls = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall']['name'])) {
                $args = $part['functionCall']['args'] ?? [];
                $calls[] = [
                    'name' => (string) $part['functionCall']['name'],
                    'args' => is_array($args) ? $args : [],
                ];
            }
        }
        return $calls;
    }

    /**
     * Tách dòng gợi ý [[GOI_Y: a | b | c]] ra khỏi câu trả lời.
     *
     * Gợi ý do AI viết nên phải làm sạch như dữ liệu người dùng nhập:
     * bỏ khoảng trắng thừa, bỏ câu rỗng, bỏ trùng, cắt bớt câu quá dài, giới hạn số lượng.
     * Nếu model viết dòng này nhiều lần thì lấy lần cuối, và xóa hết khỏi câu trả lời.
     *
     * @param string $text Câu trả lời gốc của Gemini
     * @return array [câu trả lời đã bỏ dòng gợi ý, danh sách gợi ý (mảng chuỗi)]
     */
    private function splitSuggestions($text = '')
    {
        $text = (string) $text;
        if (!preg_match_all(self::SUGGESTION_PATTERN, $text, $matches) || $matches[1] === []) {
            return [trim($text), []];
        }

        $suggestions = [];
        foreach (explode('|', end($matches[1])) as $item) {
            // preg_replace('/\s+/u'...): gộp nhiều khoảng trắng/xuống dòng thành một dấu cách.
            $item = trim(preg_replace('/\s+/u', ' ', $item));
            if ($item === '' || in_array($item, $suggestions, true)) {
                continue;
            }
            $suggestions[] = mb_substr($item, 0, self::MAX_SUGGESTION_LENGTH, 'UTF-8');
            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return [trim(preg_replace(self::SUGGESTION_PATTERN, '', $text)), $suggestions];
    }

    /**
     * Ghép các part dạng chữ thành câu trả lời.
     *
     * @param array $parts Các part của content
     * @return string
     */
    private function extractText($parts = [])
    {
        $texts = [];
        foreach ($parts as $part) {
            // Bỏ qua part "thought" (suy nghĩ nội bộ của model), chỉ lấy câu trả lời thật.
            if (isset($part['text']) && empty($part['thought'])) {
                $texts[] = $part['text'];
            }
        }
        return trim(implode('', $texts));
    }
}
