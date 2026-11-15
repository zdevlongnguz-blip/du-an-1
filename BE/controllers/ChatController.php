<?php

/**
 * Controller cho API chat với trợ lý AI (tầng controller).
 * Nhận request HTTP, validate dữ liệu, gọi service Gemini rồi trả JSON cho client.
 */

class ChatController
{
    // Độ dài tối đa của một câu hỏi (tính theo ký tự), tránh gửi đoạn quá dài tốn token.
    const MAX_MESSAGE_LENGTH = 2000;

    // Số lượt lịch sử tối đa client được gửi lên, chặn body quá lớn.
    const MAX_HISTORY_ITEMS = 60;

    /**
     * Gửi câu hỏi cho trợ lý AI và nhận câu trả lời.
     *
     * Method: POST. Body JSON:
     * - message (string, bắt buộc): câu hỏi, 1-2000 ký tự
     * - history (array, không bắt buộc): lấy từ "history" của response lượt trước để hỏi tiếp
     *
     * Thành công 200: { status, reply, tool_calls, history }
     * Lỗi: 405 sai method, 400 body sai kiểu, 422 câu hỏi rỗng/quá dài,
     *      502 Gemini lỗi, 500 lỗi cấu hình server.
     *
     * @return void
     */
    public function chat()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['message'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Thiếu câu hỏi (message) hoặc dữ liệu không phải JSON'], 400);
        }

        $history = $input['history'] ?? [];
        if (!is_array($history) || count($history) > self::MAX_HISTORY_ITEMS) {
            response_json(['status' => 'error', 'message' => 'Lịch sử hội thoại (history) không hợp lệ'], 400);
        }

        $message = trim($input['message']);
        // mb_strlen đếm theo ký tự; strlen đếm theo byte nên chữ có dấu tiếng Việt bị tính dài gấp 2-3 lần.
        if ($message === '' || mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            response_json([
                'status' => 'error',
                'message' => 'Câu hỏi không được để trống và tối đa ' . self::MAX_MESSAGE_LENGTH . ' ký tự',
            ], 422);
        }

        // Lưu ý: response_json() gọi exit, nên phải gọi nó NGOÀI khối try
        // để tránh nhầm lẫn khi đọc luồng xử lý.
        $result = null;
        $isConfigError = false;
        try {
            // Chưa đăng ký tool nghiệp vụ nào; khi có thì thêm ->addTool(new GeminiToolXxx()).
            $result = (new Gemini())->chat($message, $history);
        } catch (Throwable $e) {
            // Ghi chi tiết vào log cho dev xem, client chỉ nhận thông báo chung
            // để không lộ thông tin cấu hình hay lỗi nội bộ.
            error_log('[ChatController] ' . $e->getMessage());
            $isConfigError = strpos($e->getMessage(), 'GEMINI_API_KEY') !== false;
        }

        if ($result === null) {
            response_json([
                'status' => 'error',
                'message' => $isConfigError
                    ? 'Trợ lý AI chưa được cấu hình'
                    : 'Trợ lý AI đang gặp sự cố, vui lòng thử lại sau',
            ], $isConfigError ? 500 : 502);
        }

        response_json([
            'status' => 'success',
            'reply' => $result['text'],
            'tool_calls' => $result['tool_calls'],
            'history' => $result['history'],
        ]);
    }
}
