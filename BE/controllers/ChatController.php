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
     * - checkout_draft (object, không bắt buộc): đơn nháp khách đang mở trên trang /ai-checkout, ĐÃ GỒM số lượng khách chỉnh.
     *   { items: [{ sku, quantity }] (1-10 món) }. KHÔNG có thông tin người nhận: phần đó FE tự lo, không đi qua luồng AI.
     *   Dùng để Louis lên đơn tiếp từ đúng trạng thái khách đang thấy (xem docs/chat-ai/len-don-nhanh/11-dong-bo-don-nhap.md).
     *   Server tra lại SKU trong DB, chỉ SKU có thật mới được đưa vào system prompt của lượt này (không lưu lịch sử).
     *
     * Thành công 200: { status, conversation_id, reply, tool_calls, actions, suggestions }
     *   suggestions: tối đa 3 câu gợi ý (chuỗi) để FE hiện thành chip, mảng rỗng nếu AI không gợi ý.
     *   actions: lệnh cho FE (tối đa 1 lệnh), mảng rỗng nếu không có. Có 2 dạng:
     *   - { type: 'navigate', route: 'catalog'|'product-detail'|'fule-sing', query: {...}, params: {...} }
     *   - { type: 'checkout-draft', draft: { items: [...], total_amount } }
     *     do tool create_order_draft tạo; FE giữ đơn nháp trong bộ nhớ, tự điền người nhận từ hồ sơ rồi mở trang /ai-checkout.
     * Lỗi: 405 sai method, 400 body sai kiểu, conversation_id không phải số nguyên dương hoặc checkout_draft sai định dạng,
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

        // Đơn nháp khách đang mở (không bắt buộc). null = không có; false = sai định dạng.
        $checkoutDraft = $this->readCheckoutDraft($input['checkout_draft'] ?? null);
        if ($checkoutDraft === false) {
            response_json(['status' => 'error', 'message' => 'checkout_draft không hợp lệ'], 400);
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
            // Có đơn nháp trên trang: thêm đoạn mô tả đơn nháp vào system prompt CỦA RIÊNG LƯỢT NÀY
            // để Louis lên đơn tiếp từ đúng số lượng khách đang thấy. System prompt không được lưu vào ai_messages,
            // nên đoạn này không nằm lại trong lịch sử.
            $systemPrompt = GeminiPrompt::system();
            if ($checkoutDraft !== null) {
                $systemPrompt .= GeminiPrompt::checkoutDraftContext($this->describeDraftItems($checkoutDraft['items']));
            }

            $result = (new Gemini($systemPrompt))
                ->addTool(new GeminiToolSearchProduct())
                // Tool giải trí: Louis hát tặng một bài, mở trang /fule-sing. Không đọc dữ liệu riêng của user.
                ->addTool(new GeminiToolFuleSing())
                // Tool lên đơn nháp: đọc hồ sơ của chính user đang chat (user_id trong $context, lấy từ JWT).
                // Chỉ tạo đơn nháp cho FE hiển thị, không ghi DB; đơn thật do khách bấm xác nhận trên trang.
                ->addTool(new GeminiToolCreateOrderDraft())
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

    /**
     * Lấy lại cuộc hội thoại gần nhất của user để hiển thị trong khung chat (sau khi đăng nhập lại hoặc tải lại trang).
     * Yêu cầu đăng nhập. Chỉ đọc hội thoại của chính user trong token, không nhận ID cuộc hội thoại từ client.
     *
     * Method: GET.
     * Thành công 200: { status, conversation_id, messages }
     *   - conversation_id: ID cuộc gần nhất, null nếu user chưa chat lần nào (khi đó messages = []).
     *   - messages: [{ role: 'user'|'assistant', text, created_at, tool_calls, suggestions }], cũ trước mới sau,
     *     tối đa theo Gemini::MAX_HISTORY lượt gần nhất. Lượt gọi tool và dòng gợi ý đã được lọc khỏi text.
     * Lỗi: 405 sai method, 401 thiếu/sai token (requireAuth ở router), 500 lỗi đọc DB.
     *
     * Lưu ý: lệnh _action (điều hướng, đơn nháp) không được lưu trong DB, nên tải lại lịch sử không mở lại trang nào.
     *
     * @param array $payload Payload JWT đã xác minh (sub = ID user), do router truyền xuống.
     * @return void
     */
    public function history($payload = [])
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = (int)($payload['sub'] ?? 0);
        if ($userId < 1) {
            response_json(['status' => 'error', 'message' => 'Bạn cần đăng nhập để xem lịch sử trò chuyện'], 401);
        }

        $conversationId = null;
        $messages = [];
        try {
            // Chỉ tìm trong các cuộc hội thoại của chính user (lọc theo user_id), nên không đọc nhầm của người khác.
            $conversationId = (new AiConversation())->findLatestIdByUser($userId);
            if ($conversationId !== null) {
                $turns = (new AiMessage())->getRecentWithTime($conversationId, Gemini::MAX_HISTORY);
                $messages = (new Gemini())->toDisplayMessages($turns);
            }
        } catch (Throwable $e) {
            error_log('[ChatController] Đọc lịch sử lỗi: ' . $e->getMessage());
            response_json(['status' => 'error', 'message' => 'Không tải được lịch sử trò chuyện, vui lòng thử lại sau'], 500);
        }

        response_json([
            'status' => 'success',
            'conversation_id' => $conversationId,
            'messages' => $messages,
        ]);
    }

    /**
     * Kiểm tra trường checkout_draft trong body (đơn nháp khách đang mở, đã gồm số lượng khách chỉnh trên trang).
     * Chỉ đọc danh sách món. Nếu client vẫn gửi kèm thông tin người nhận thì bỏ qua: phần đó không thuộc luồng AI.
     * Đây là dữ liệu client gửi nên kiểm tra như mọi dữ liệu người dùng; giá trị chỉ dùng làm gợi ý cho AI,
     * tool vẫn tra lại giá, tồn kho khi lên đơn.
     *
     * @param mixed $raw Giá trị checkout_draft trong body
     * @return array|null|false null nếu không gửi; false nếu sai định dạng; ['items' => [['sku', 'quantity']]] nếu hợp lệ
     */
    private function readCheckoutDraft($raw = null)
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !is_array($raw['items'] ?? null)) {
            return false;
        }

        $items = $raw['items'];
        if ($items === [] || count($items) > Order::MAX_DIRECT_ITEMS) {
            return false;
        }
        $cleanItems = [];
        foreach ($items as $item) {
            $sku = is_array($item) && is_string($item['sku'] ?? null) ? trim($item['sku']) : '';
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            if ($sku === '' || strlen($sku) > 100 || !is_int($quantity)
                || $quantity < 1 || $quantity > Order::MAX_ITEM_QUANTITY) {
                return false;
            }
            $cleanItems[] = ['sku' => $sku, 'quantity' => $quantity];
        }

        return ['items' => $cleanItems];
    }

    /**
     * Tra các món trong đơn nháp với DB để mô tả cho AI.
     * Chỉ SKU có thật và đang bán mới được giữ, và tên món lấy từ DB, nên chuỗi do client gửi
     * không bao giờ được chép thẳng vào system prompt (tránh khách chèn câu lệnh qua trường sku).
     *
     * @param array $items Danh sách ['sku' => string, 'quantity' => int] đã qua readCheckoutDraft
     * @return array Danh sách ['sku', 'name', 'quantity'] với sku và name lấy từ DB
     */
    private function describeDraftItems($items = [])
    {
        $variants = [];
        foreach ((new Product())->getActiveVariantsBySkus(array_column($items, 'sku')) as $row) {
            $variants[mb_strtolower((string)$row['sku'], 'UTF-8')] = $row;
        }

        $lines = [];
        foreach ($items as $item) {
            $variant = $variants[mb_strtolower($item['sku'], 'UTF-8')] ?? null;
            if ($variant === null) {
                continue;
            }
            $lines[] = [
                'sku' => $variant['sku'],
                'name' => $variant['product_name'] . ' - ' . $variant['variant_name'],
                'quantity' => $item['quantity'],
            ];
        }

        return $lines;
    }

    /**
     * Xóa toàn bộ lịch sử trò chuyện với trợ lý AI của user đang đăng nhập.
     * Yêu cầu đăng nhập. User lấy từ sub trong JWT, không nhận ID từ client nên không xóa được của người khác.
     *
     * Method: DELETE. Không cần body.
     * Thành công 200: { status, message, deleted_conversations } (số cuộc đã xóa, 0 nếu chưa có gì).
     * Lỗi: 405 sai method, 401 thiếu/sai token (requireAuth ở router), 500 lỗi DB.
     *
     * @param array $payload Payload JWT đã xác minh (sub = ID user), do router truyền xuống.
     * @return void
     */
    public function clearHistory($payload = [])
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = (int)($payload['sub'] ?? 0);
        if ($userId < 1) {
            response_json(['status' => 'error', 'message' => 'Bạn cần đăng nhập để xóa lịch sử trò chuyện'], 401);
        }

        try {
            $deleted = (new AiConversation())->deleteByUser($userId);
        } catch (Throwable $e) {
            error_log('[ChatController] Xóa lịch sử lỗi: ' . $e->getMessage());
            response_json(['status' => 'error', 'message' => 'Không xóa được lịch sử trò chuyện, vui lòng thử lại sau'], 500);
        }

        response_json([
            'status' => 'success',
            'message' => 'Đã xóa toàn bộ lịch sử trò chuyện',
            'deleted_conversations' => $deleted,
        ]);
    }
}
