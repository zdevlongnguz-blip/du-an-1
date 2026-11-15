<?php

/**
 * Controller cho API chat với trợ lý AI (tầng controller).
 * Nhận câu hỏi, kiểm tra quyền sở hữu cuộc hội thoại, lấy lịch sử từ DB, gọi Gemini,
 * rồi lưu các lượt mới vào DB và trả JSON cho client.
 */

class ChatController
{
    // Độ dài tối đa của một câu hỏi (tính theo ký tự), tránh gửi đoạn quá dài tốn token.
    const MAX_MESSAGE_LENGTH = 2000;

    /**
     * Gửi câu hỏi cho trợ lý AI và nhận câu trả lời. Yêu cầu đăng nhập.
     *
     * Method: POST. Body JSON:
     * - message (string, bắt buộc): câu hỏi, 1-2000 ký tự
     * - conversation_id (int, không bắt buộc): cuộc hội thoại đang chat tiếp. Không gửi hoặc gửi null = tạo cuộc mới.
     * - history bị bỏ qua: lịch sử giờ do server lưu trong DB, client không gửi lên nữa.
     *
     * Thành công 200: { status, conversation_id, reply, tool_calls, actions, suggestions }
     *   suggestions: tối đa 3 câu gợi ý (chuỗi) để FE hiện thành chip, mảng rỗng nếu AI không gợi ý.
     *   actions: lệnh cho FE, hiện chỉ có dạng
     *   { type: 'navigate', route: 'catalog'|'product-detail', query: {...}, params: {...} }, mảng rỗng nếu không có.
     * Lỗi: 405 sai method, 400 body sai kiểu hoặc conversation_id không phải số nguyên dương,
     *      401 thiếu/sai token (do requireAuth ở router), 404 cuộc hội thoại không thuộc user này,
     *      422 câu hỏi rỗng/quá dài, 500 lỗi cấu hình hoặc không lưu được lịch sử, 502 Gemini lỗi.
     *
     * @param array $payload Payload JWT đã xác minh (sub = ID user), do router truyền xuống.
     *                       Không lấy user ID từ body để tránh giả danh người khác.
     * @return void
     */
    public function chat($payload = [])
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !is_string($input['message'] ?? null)) {
            response_json(['status' => 'error', 'message' => 'Thiếu câu hỏi (message) hoặc dữ liệu không phải JSON'], 400);
        }

        $message = trim($input['message']);
        // mb_strlen đếm theo ký tự; strlen đếm theo byte nên chữ có dấu tiếng Việt bị tính dài gấp 2-3 lần.
        if ($message === '' || mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_LENGTH) {
            response_json([
                'status' => 'error',
                'message' => 'Câu hỏi không được để trống và tối đa ' . self::MAX_MESSAGE_LENGTH . ' ký tự',
            ], 422);
        }

        // conversation_id không bắt buộc. null nghĩa là cuộc hội thoại mới, sẽ được tạo khi lưu thành công.
        $conversationId = null;
        $rawConversationId = $input['conversation_id'] ?? null;
        if ($rawConversationId !== null) {
            // Chỉ nhận số nguyên dạng số (12) hoặc chuỗi chữ số ("12").
            // Kiểm tra kiểu trước, vì filter_var coi true là 1 và sẽ nhận nhầm.
            $isIntLike = is_int($rawConversationId)
                || (is_string($rawConversationId) && ctype_digit($rawConversationId));
            $conversationId = $isIntLike ? (int)$rawConversationId : 0;
            if ($conversationId < 1) {
                response_json(['status' => 'error', 'message' => 'conversation_id không hợp lệ'], 400);
            }
        }

        // Claim sub trong JWT là ID user. requireAuth() ở router đã kiểm tra sub là số nguyên dương;
        // kiểm tra lại ở đây chỉ để phòng khi controller bị gọi mà quên requireAuth().
        $userId = (int)($payload['sub'] ?? 0);
        if ($userId < 1) {
            response_json(['status' => 'error', 'message' => 'Bạn cần đăng nhập để sử dụng trợ lý AI'], 401);
        }

        $conversations = new AiConversation();
        $messages = new AiMessage();

        // Lịch sử cũ lấy từ DB. Với cuộc hội thoại mới thì bắt đầu rỗng.
        $history = [];
        if ($conversationId !== null) {
            // Kiểm tra quyền trước khi đọc bất kỳ lượt nào. Không thuộc user thì coi như không tồn tại.
            if (!$conversations->belongsToUser($conversationId, $userId)) {
                response_json(['status' => 'error', 'message' => 'Không tìm thấy cuộc hội thoại'], 404);
            }
            // Lấy ít hơn MAX_HISTORY một lượt: Gemini còn thêm câu hỏi mới vào cuối.
            // Nếu lấy đủ MAX_HISTORY thì tổng vượt giới hạn, Gemini sẽ cắt mất cả một lượt hỏi đáp cũ.
            $history = $messages->getRecent($conversationId, Gemini::MAX_HISTORY - 1);
        }

        // Lưu ý: response_json() gọi exit, nên phải gọi nó NGOÀI khối try
        // để tránh nhầm lẫn khi đọc luồng xử lý.
        $result = null;
        $isConfigError = false;
        try {
            // Đăng ký các tool nghiệp vụ Gemini được phép gọi. Tool tra cứu sản phẩm (search_product)
            // chỉ đọc dữ liệu công khai nên không cần user ID. Tool mới thì thêm ->addTool(new GeminiToolXxx()).
            // $context chỉ là thông tin server truyền xuống tool, không do client gửi.
            $result = (new Gemini())
                ->addTool(new GeminiToolSearchProduct())
                // Tool giải trí: Louis hát tặng một bài, mở trang /fule-sing. Không đọc dữ liệu riêng của user.
                ->addTool(new GeminiToolFuleSing())
                ->chat($message, $history, ['user_id' => $userId]);
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

        // Gemini trả về toàn bộ lịch sử (cũ + mới). Phần cũ đã có trong DB nên chỉ lấy phần mới để lưu.
        // Số phần cũ bằng số lượt đã gửi đi, vì getRecent() đã lọc theo đúng điều kiện của Gemini::cleanHistory.
        $newTurns = array_slice($result['history'], count($history));

        try {
            // Cuộc hội thoại mới (conversation_id = 0) được tạo cùng transaction với các lượt,
            // và chỉ sau khi Gemini đã trả lời thành công. Lỗi ở bất kỳ bước nào đều không để lại dữ liệu dở dang.
            $conversationId = $messages->saveTurns($conversationId ?? 0, $newTurns, $userId);
        } catch (Throwable $e) {
            error_log('[ChatController] Lưu lịch sử lỗi: ' . $e->getMessage());
            response_json([
                'status' => 'error',
                'message' => 'Không lưu được lịch sử hội thoại, vui lòng thử lại sau',
            ], 500);
        }

        response_json([
            'status' => 'success',
            'conversation_id' => $conversationId,
            'reply' => $result['text'],
            'tool_calls' => $result['tool_calls'],
            'actions' => $result['actions'],
            'suggestions' => $result['suggestions'],
        ]);
    }
}
