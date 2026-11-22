<?php

/**
 * Controller cho API kiểm duyệt bình luận bằng AI (tầng controller).
 * Nhận nội dung user định bình luận, gọi service CommentGuard rồi trả kết quả JSON. Không ghi gì vào DB.
 */

class CommentGuardController
{
    // Độ dài tối đa mỗi nội dung (ký tự), bằng giới hạn bình luận trong CommentController.
    const MAX_TEXT_LENGTH = 2000;

    /**
     * Kiểm duyệt một bình luận trước khi user đăng. Yêu cầu đăng nhập (router đã gọi requireAuth).
     *
     * Method: POST. Body JSON: { "text": "..." }, chuỗi không rỗng, tối đa 2000 ký tự.
     * Mỗi lần chỉ chấm 1 bình luận: user bình luận từng câu một, và chặn gửi chùm
     * để một user không dồn nhiều nội dung làm quá tải dịch vụ AI.
     *
     * Thành công 200: { status: 'success', result: { text, status: VISIBLE|HIDDEN, reason,
     *                   toxic_prob, needs_review, sentiment, sentiment_score } }
     * Lỗi: 405 sai method, 400 body không phải JSON, 422 thiếu text/rỗng/quá dài,
     *      401 thiếu/sai token (do router), 502 dịch vụ AI lỗi hoặc chưa cấu hình.
     *
     * @param array $payload Payload JWT đã xác minh (sub = ID user), do router truyền xuống.
     *                       Hiện chưa dùng, giữ để sau này ghi log/giới hạn số lần gọi theo user.
     * @return void
     */
    public function analyze($payload = [])
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            response_json(['status' => 'error', 'message' => 'Phương thức không được hỗ trợ'], 405);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            response_json(['status' => 'error', 'message' => 'Dữ liệu gửi lên phải là JSON'], 400);
        }

        $text = $this->validateText($input['text'] ?? null);

        try {
            $result = (new CommentGuard())->analyze($text);
        } catch (InvalidArgumentException $e) {
            response_json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            // 502 Bad Gateway: lỗi nằm ở dịch vụ bên ngoài mà server mình gọi tới, không phải ở request của client.
            // Ghi chi tiết vào error_log cho dev xem; client chỉ nhận message chung.
            error_log('[CommentGuard] ' . $e->getMessage());
            response_json(['status' => 'error', 'message' => 'Dịch vụ kiểm duyệt bình luận đang không khả dụng'], 502);
        }

        response_json(['status' => 'success', 'result' => $result], 200);
    }

    /**
     * Kiểm tra một nội dung: phải là chuỗi, bỏ khoảng trắng đầu cuối không rỗng, tối đa MAX_TEXT_LENGTH ký tự.
     *
     * @param mixed $value Giá trị nhận từ JSON
     * @return string Nội dung đã trim
     */
    private function validateText($value = null)
    {
        if (!is_string($value)) {
            response_json(['status' => 'error', 'message' => 'Thiếu nội dung bình luận (text) hoặc text không phải chuỗi'], 422);
        }

        $text = trim($value);
        // preg_match_all('/./us') đếm số KÝ TỰ (cờ u = UTF-8), không phải số byte như strlen.
        // Chữ tiếng Việt có dấu chiếm 2-3 byte nên strlen sẽ đếm sai.
        $length = preg_match_all('/./us', $text);
        if ($text === '' || $length === false || $length > self::MAX_TEXT_LENGTH) {
            response_json([
                'status' => 'error',
                'message' => 'Nội dung không được rỗng và tối đa ' . self::MAX_TEXT_LENGTH . ' ký tự',
            ], 422);
        }

        return $text;
    }
}
