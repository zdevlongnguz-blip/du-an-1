<?php
/** Controller xử lý API đọc và quản lý bình luận sản phẩm. */
class CommentController
{
    private Comment $comment;

    /** Khởi tạo model truy cập bảng comments. */
    public function __construct()
    {
        $this->comment = new Comment();
    }

    /**
     * Trả về bình luận theo slug sản phẩm, có phân trang và không yêu cầu đăng nhập.
     * Thiếu dữ liệu hợp lệ trả HTTP 400; không có sản phẩm trả HTTP 404.
     *
     * @return void
     */
    public function byProductSlug(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $slug = $_GET['slug'] ?? null;
        if (!is_string($slug) || trim($slug) === '' || strlen(trim($slug)) > 255) {
            response_json(['status' => 'error', 'message' => 'Slug sản phẩm không hợp lệ'], 400);
        }

        $pageInput = $_GET['page'] ?? '1';
        $perPageInput = $_GET['per_page'] ?? '10';
        $page = is_string($pageInput) && ctype_digit($pageInput) ? (int) $pageInput : 0;
        $perPage = is_string($perPageInput) && ctype_digit($perPageInput) ? (int) $perPageInput : 0;

        if ($page < 1 || $perPage < 1 || $perPage > 100 || ($page - 1) > intdiv(PHP_INT_MAX, $perPage)) {
            response_json(['status' => 'error', 'message' => 'Thông tin phân trang không hợp lệ'], 400);
        }

        $comments = $this->comment->getByProductSlug(trim($slug), $page, $perPage);
        if ($comments === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        response_json([
            'status' => 'success',
            'comments' => $comments,
        ]);
    }

    /**
     * Tạo bình luận theo product_id; yêu cầu POST và user đăng nhập.
     * Nội dung được AI kiểm duyệt (xem moderate) trước khi lưu.
     * - AI chặn (ngôn từ độc hại): KHÔNG lưu, trả 422 kèm moderation 'blocked' để FE giữ ô nhập cho khách sửa.
     * - Còn lại: lưu, AI chỉ quyết định status (hiện ngay hoặc chờ duyệt).
     *
     * Thành công 201: { status, message, comment: { id, product_id, content, status, moderation } }
     *   moderation: 'approved' (đã hiện) hoặc 'pending' (chờ admin duyệt).
     * Bị AI chặn 422: { status: 'error', message, moderation: 'blocked' }.
     * Trả HTTP 400/401/404/405/422 nếu request, token, sản phẩm hoặc nội dung không hợp lệ.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return void
     */
    public function create(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getAuthenticatedUserId($authPayload);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu bình luận không hợp lệ'], 400);
        }

        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) {
            response_json(['status' => 'error', 'message' => 'ID sản phẩm không hợp lệ'], 422);
        }

        $content = $this->validateContent($input['content'] ?? null);

        // Kiểm tra sản phẩm TRƯỚC khi gọi AI: product_id không tồn tại thì trả 404 ngay,
        // không tốn lượt gọi Colab và khách không phải chờ AI chỉ để nhận lỗi.
        if (!$this->comment->productExists($productId)) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        // Gọi AI SAU khi validate (không tốn lượt gọi cho dữ liệu sai)
        // và TRƯỚC khi lưu (để lưu luôn status + nhãn trong một câu INSERT).
        $decision = $this->moderate($content);
        $this->rejectIfBlocked($decision);

        $commentId = $this->comment->createForProductId(
            $productId,
            $userId,
            $content,
            $decision['status'],
            $decision['labels']
        );
        // Vẫn giữ kiểm tra này: sản phẩm có thể bị admin xóa trong lúc đang chờ AI chấm.
        if ($commentId === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => $this->moderationMessage($decision['moderation'], 'Thêm'),
            'comment' => [
                'id' => $commentId,
                'product_id' => $productId,
                'content' => $content,
                'status' => $decision['status'],
                'moderation' => $decision['moderation'],
            ],
        ], 201);
    }

    /**
     * Sửa nội dung bình luận của user đang đăng nhập; yêu cầu PUT.
     * Nội dung mới được AI kiểm duyệt lại như khi tạo. Nội dung không đổi thì giữ nguyên trạng thái, không gọi AI.
     * AI chặn nội dung mới: KHÔNG ghi đè, bình luận cũ giữ nguyên nội dung và trạng thái, trả 422 như create.
     *
     * Thành công 200: { status, message, comment: { id, content, status, moderation } } (moderation như create).
     * Trả HTTP 400/401/404/405/422 nếu request, token, bình luận hoặc nội dung không hợp lệ.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return void
     */
    public function update(array $authPayload = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getAuthenticatedUserId($authPayload);
        $commentId = $this->getCommentId();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu bình luận không hợp lệ'], 400);
        }

        $content = $this->validateContent($input['content'] ?? null);

        // Kiểm tra quyền sở hữu trước khi gọi AI: bình luận của người khác thì trả 404 luôn, không tốn lượt gọi.
        $current = $this->comment->findOwned($commentId, $userId);
        if ($current === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        // Nội dung giữ nguyên: không chấm lại, trả về đúng trạng thái đang lưu trong DB.
        if ($current['content'] === $content) {
            $status = (int) $current['status'];
            response_json([
                'status' => 'success',
                'message' => 'Nội dung bình luận không thay đổi',
                'comment' => [
                    'id' => $commentId,
                    'content' => $content,
                    'status' => $status,
                    'moderation' => $this->moderationFromRow($status, $current['moderation_label']),
                ],
            ]);
        }

        $decision = $this->moderate($content);
        $this->rejectIfBlocked($decision);

        if (!$this->comment->updateOwned($commentId, $userId, $content, $decision['status'], $decision['labels'])) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => $this->moderationMessage($decision['moderation'], 'Cập nhật'),
            'comment' => [
                'id' => $commentId,
                'content' => $content,
                'status' => $decision['status'],
                'moderation' => $decision['moderation'],
            ],
        ]);
    }

    /**
     * Xóa bình luận của user đang đăng nhập; yêu cầu DELETE.
     * Trả HTTP 400/401/404/405 nếu request, token hoặc quyền sở hữu không hợp lệ.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return void
     */
    public function delete(array $authPayload): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $userId = $this->getAuthenticatedUserId($authPayload);
        $commentId = $this->getCommentId();
        if (!$this->comment->deleteOwned($commentId, $userId)) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => 'Xóa bình luận thành công',
        ]);
    }

    /**
     * Lấy danh sách bình luận quản trị theo status=0/1 và phân trang.
     * Sai method hoặc query không hợp lệ trả HTTP 405/400.
     *
     * @return void
     */
    public function adminIndex(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $status = null;
        if (array_key_exists('status', $_GET)) {
            $statusInput = $_GET['status'];
            if (!is_string($statusInput) || !in_array($statusInput, ['0', '1'], true)) {
                response_json(['status' => 'error', 'message' => 'status phải là 0 hoặc 1'], 400);
            }
            $status = (int) $statusInput;
        }
        $pageInput = $_GET['page'] ?? '1';
        $perPageInput = $_GET['per_page'] ?? '10';
        $page = is_string($pageInput) && ctype_digit($pageInput) ? (int) $pageInput : 0;
        $perPage = is_string($perPageInput) && ctype_digit($perPageInput) ? (int) $perPageInput : 0;
        if ($page < 1 || $perPage < 1 || $perPage > 100 || ($page - 1) > intdiv(PHP_INT_MAX, $perPage)) {
            response_json(['status' => 'error', 'message' => 'Thông tin phân trang không hợp lệ'], 400);
        }

        response_json([
            'status' => 'success',
            'comments' => $this->comment->getAdminList($status, $page, $perPage),
        ]);
    }

    /**
     * Duyệt bình luận theo ID; sai method trả 405, ID sai 400, không có bình luận 404.
     *
     * @return void
     */
    public function adminApprove(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        if (!$this->comment->approveAdmin($this->getCommentId())) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        response_json(['status' => 'success', 'message' => 'Đã duyệt bình luận']);
    }

    /**
     * Xóa bình luận khỏi trang quản trị; sai method trả 405, ID sai 400, không có 404.
     *
     * @return void
     */
    public function adminDelete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }
        if (!$this->comment->deleteAdmin($this->getCommentId())) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        response_json(['status' => 'success', 'message' => 'Đã xóa bình luận']);
    }

    /**
     * Chốt chặn kiểm duyệt: gửi nội dung cho AI Comment Guard rồi quyết định status của bình luận.
     *
     * Bảng quyết định (chỉ dựa vào status và needs_review của AI, không liệt kê cứng từng nhãn,
     * vì Colab có thể thêm nhãn mới):
     * - AI trả HIDDEN (độc hại)               -> moderation 'blocked'. create/update KHÔNG lưu, trả 422 (rejectIfBlocked).
     * - AI trả VISIBLE nhưng needs_review     -> status 0, moderation 'pending' (AI không chắc, admin duyệt tay).
     * - AI trả VISIBLE, không cần review      -> status 1, moderation 'approved' (hiện ngay).
     * - Colab/service lỗi (tắt, timeout, ...) -> status 0, moderation 'pending', nhãn NULL (= chưa chấm).
     *   Không chặn khách bình luận chỉ vì dịch vụ AI sập; admin duyệt tay sau.
     * - COMMENT_GUARD_ENABLED tắt trong .env    -> như trường hợp lỗi, nhưng KHÔNG gọi Colab nên lưu ngay.
     *
     * @param string $content Nội dung đã validate.
     * @return array ['status' => 0|1, 'moderation' => 'approved'|'blocked'|'pending', 'labels' => nhãn AI]
     */
    private function moderate(string $content = ''): array
    {
        $noLabels = ['moderation_label' => null, 'sentiment' => null, 'toxic_prob' => null];

        // Tắt kiểm duyệt: bỏ qua Colab để khách không phải chờ timeout khi biết chắc Colab đang tắt.
        // Vẫn để status 0 (an toàn): không có AI thì admin phải duyệt tay, giống cách hệ thống chạy trước khi có AI.
        if (!COMMENT_GUARD_ENABLED) {
            $this->logModeration('Kiểm duyệt AI đang TẮT (COMMENT_GUARD_ENABLED=0), bình luận chuyển sang chờ duyệt.');

            return ['status' => 0, 'moderation' => 'pending', 'labels' => $noLabels];
        }

        try {
            $result = (new CommentGuard())->analyze($content);
        } catch (Throwable $e) {
            // Bắt Throwable chứ không chỉ Exception: ngoài RuntimeException (lỗi mạng/Colab) còn có Error của PHP,
            // ví dụ máy chủ thiếu extension curl thì curl_init() ném Error. Bắt hết để bình luận vẫn được lưu
            // ở trạng thái chờ duyệt thay vì trả 500. Chi tiết chỉ ghi log, không trả cho khách.
            error_log('[CommentGuard] Kiểm duyệt lỗi, chuyển bình luận sang chờ duyệt: ' . $e->getMessage());
            $this->logModeration('Kiểm duyệt AI LỖI, bình luận chuyển sang chờ duyệt: ' . $this->hideGuardUrl($e->getMessage()), 'WARN');

            return ['status' => 0, 'moderation' => 'pending', 'labels' => $noLabels];
        }

        $labels = [
            'moderation_label' => $result['reason'],
            'sentiment' => $result['sentiment'],
            'toxic_prob' => $result['toxic_prob'],
        ];

        if ($result['status'] !== 'VISIBLE') {
            $decision = ['status' => 0, 'moderation' => 'blocked', 'labels' => $labels];
        } elseif ($result['needs_review']) {
            $decision = ['status' => 0, 'moderation' => 'pending', 'labels' => $labels];
        } else {
            $decision = ['status' => 1, 'moderation' => 'approved', 'labels' => $labels];
        }

        // Ghi kết quả AI vào log API để biết vì sao bình luận được hiện/ẩn (khách không thấy dòng này).
        $this->logModeration(sprintf(
            'Kiểm duyệt AI: %s (nhãn %s, cảm xúc %s, toxic_prob %.4f%s).',
            $decision['moderation'],
            $labels['moderation_label'] ?? 'NULL',
            $labels['sentiment'] ?? 'NULL',
            (float) $labels['toxic_prob'],
            $result['needs_review'] ? ', AI không chắc chắn' : ''
        ));

        return $decision;
    }

    /**
     * Gắn ghi chú kiểm duyệt vào khối log API của request hiện tại (BE/storage/logs/api-*.log).
     * Kiểm tra class_exists vì ApiLogger chỉ được nạp khi chạy qua index.php (chạy script dòng lệnh thì không có).
     *
     * @param string $message Nội dung ghi chú
     * @param string $level 'INFO' hoặc 'WARN'
     * @return void
     */
    private function logModeration(string $message = '', string $level = 'INFO'): void
    {
        if (class_exists('ApiLogger', false)) {
            ApiLogger::note($message, $level);
        }
    }

    /**
     * Thay URL dịch vụ AI trong thông báo lỗi bằng chữ "COMMENT_GUARD_URL",
     * vì lỗi cURL có kèm tên máy chủ mà quy tắc dự án không cho ghi thông tin kết nối vào log API.
     *
     * @param string $message Thông báo lỗi gốc
     * @return string Thông báo đã che URL
     */
    private function hideGuardUrl(string $message = ''): string
    {
        $host = (string) parse_url(COMMENT_GUARD_URL, PHP_URL_HOST);

        return $host === '' ? $message : str_replace($host, '<COMMENT_GUARD_URL>', $message);
    }

    /**
     * Trả 422 và dừng request nếu AI chặn nội dung. Bình luận KHÔNG được lưu/ghi đè.
     * Vì sao không lưu: FE giữ nguyên ô nhập để khách sửa rồi gửi lại; nếu lưu thì mỗi lần thử
     * sẽ để lại một dòng rác bị ẩn trong DB. Nội dung và nhãn vẫn có trong log API (ghi chú của moderate()).
     * Key moderation = 'blocked' giúp FE phân biệt với lỗi 422 khác (nội dung rỗng, quá dài).
     *
     * @param array $decision Kết quả của moderate().
     * @return void
     */
    private function rejectIfBlocked(array $decision = []): void
    {
        if (($decision['moderation'] ?? '') === 'blocked') {
            response_json([
                'status' => 'error',
                'message' => $this->moderationMessage('blocked'),
                'moderation' => 'blocked',
            ], 422);
        }
    }

    /**
     * Suy ra moderation từ dữ liệu đã lưu (dùng khi sửa mà nội dung không đổi).
     * Bình luận bị chặn không còn được lưu từ 22/11/2026, nhưng DB có thể còn dòng 'blocked' cũ (status 0, nhãn khác CLEAN).
     * Đã duyệt -> approved; bị AI gắn nhãn khác CLEAN -> blocked; còn lại (needs_review, chưa chấm) -> pending.
     *
     * @param int $status Cột status trong DB.
     * @param string|null $label Cột moderation_label trong DB.
     * @return string 'approved'|'blocked'|'pending'
     */
    private function moderationFromRow(int $status = 0, ?string $label = null): string
    {
        if ($status === 1) {
            return 'approved';
        }

        return ($label !== null && $label !== 'CLEAN') ? 'blocked' : 'pending';
    }

    /**
     * Câu thông báo cho khách theo kết quả kiểm duyệt.
     * Không nói nhãn hay điểm AI, để người cố tình lách luật không dò được ngưỡng chặn.
     *
     * @param string $moderation 'approved'|'blocked'|'pending'
     * @param string $action Động từ đầu câu: 'Thêm' hoặc 'Cập nhật'
     * @return string Câu thông báo
     */
    private function moderationMessage(string $moderation = 'pending', string $action = 'Thêm'): string
    {
        if ($moderation === 'approved') {
            return $action . ' bình luận thành công!';
        }
        if ($moderation === 'blocked') {
            return 'Bình luận chứa ngôn từ không phù hợp. Vui lòng sửa lại nội dung lịch sự hơn.';
        }

        return $action . ' bình luận thành công, bình luận đang chờ quản trị viên duyệt.';
    }

    /**
     * Lấy ID user từ claim `sub` của JWT đã xác minh, không lấy từ request.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return int ID user hợp lệ.
     */
    private function getAuthenticatedUserId(array $authPayload): int
    {
        $userId = filter_var($authPayload['sub'] ?? null, FILTER_VALIDATE_INT);
        if ($userId === false || $userId < 1) {
            response_json(['status' => 'error', 'message' => 'Token không hợp lệ'], 401);
        }

        return $userId;
    }

    /**
     * Đọc và kiểm tra ID bình luận dương từ query string.
     *
     * @return int ID bình luận hợp lệ.
     */
    private function getCommentId(): int
    {
        $commentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($commentId === false || $commentId < 1) {
            response_json(['status' => 'error', 'message' => 'ID bình luận không hợp lệ'], 400);
        }

        return $commentId;
    }

    /**
     * Kiểm tra nội dung bình luận không rỗng và không quá 2.000 ký tự.
     * Regex UTF-8 đếm ký tự Unicode để giới hạn đúng cả nội dung tiếng Việt.
     *
     * @param mixed $value Giá trị content nhận từ JSON.
     * @return string Nội dung đã trim.
     */
    private function validateContent($value): string
    {
        if (!is_string($value)) {
            response_json(['status' => 'error', 'message' => 'Nội dung bình luận không hợp lệ'], 400);
        }

        $content = trim($value);
        $contentLength = preg_match_all('/./us', $content);
        if ($content === '' || $contentLength === false || $contentLength > 2000) {
            response_json(['status' => 'error', 'message' => 'Bình luận phải có nội dung và không quá 2.000 ký tự'], 422);
        }

        return $content;
    }
}