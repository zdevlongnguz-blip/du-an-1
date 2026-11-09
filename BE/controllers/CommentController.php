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
     * Trả HTTP 400/401/404/405/422 nếu request, token, sản phẩm hoặc nội dung không hợp lệ.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return void
     */
    public function create(array $authPayload): void
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
        $commentId = $this->comment->createForProductId($productId, $userId, $content);
        if ($commentId === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => 'Thêm bình luận thành công',
            'comment' => [
                'id' => $commentId,
                'product_id' => $productId,
                'content' => $content,
            ],
        ], 201);
    }

    /**
     * Sửa nội dung bình luận của user đang đăng nhập; yêu cầu PUT.
     * Trả HTTP 400/401/404/405/422 nếu request, token, bình luận hoặc nội dung không hợp lệ.
     *
     * @param array $authPayload Payload JWT đã được middleware xác minh.
     * @return void
     */
    public function update(array $authPayload): void
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
        if (!$this->comment->updateOwned($commentId, $userId, $content)) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy bình luận'], 404);
        }

        response_json([
            'status' => 'success',
            'message' => 'Cập nhật bình luận thành công',
            'comment' => [
                'id' => $commentId,
                'content' => $content,
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