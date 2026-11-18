<?php

/**
 * Tool "cancel_order_draft": Louis bỏ (hủy) đơn nháp khách đang mở trên trang "Lên đơn nhanh" (tầng service).
 * Đơn nháp không nằm trong DB mà nằm trong sessionStorage của trình duyệt, nên server không xóa gì cả:
 * tool chỉ trả lệnh 'checkout-draft-clear' để FE tự xóa đơn nháp rồi mở trang /ai-checkout (màn "chưa có đơn nháp").
 * Không dùng để hủy đơn hàng đã đặt (việc đó khách làm ở trang chi tiết đơn).
 */
class GeminiToolCancelOrderDraft extends GeminiTool
{
    /**
     * Tên tool. Gemini dùng tên này khi yêu cầu gọi.
     *
     * @return string
     */
    public function name()
    {
        return 'cancel_order_draft';
    }

    /**
     * Mô tả để Gemini biết lúc nào nên gọi tool, và lúc nào KHÔNG được gọi.
     *
     * @return string
     */
    public function description()
    {
        return 'Bỏ hẳn đơn nháp đang mở trên trang "Lên đơn nhanh" (đơn CHƯA đặt). '
            . 'Chỉ gọi khi khách NÓI RÕ muốn bỏ/hủy/xóa đơn nháp, ví dụ "hủy đơn nháp", "bỏ đơn đó đi", "thôi không lấy nữa, xóa đơn". '
            . 'Không gọi khi khách chỉ muốn bỏ một món (dùng create_order_draft với lệnh remove), '
            . 'và không dùng để hủy đơn hàng đã đặt.';
    }

    /**
     * Tool không cần tham số: đơn nháp đang mở lấy từ $context do server truyền xuống.
     *
     * @return array
     */
    public function parameters()
    {
        return [];
    }

    /**
     * Trả lệnh xóa đơn nháp cho FE. Server không lưu đơn nháp nên không có gì để xóa trong DB.
     *
     * Vẫn gửi lệnh xóa kể cả khi $context không có món nào: đơn nháp trên máy khách có thể chỉ còn
     * các món đã ngừng bán (server lọc bỏ khi mô tả cho AI), xóa đi là đúng ý khách.
     *
     * @param array $args    Tham số Gemini gửi lên (không dùng)
     * @param array $context Ngữ cảnh server: 'checkout_draft' là danh sách món trên trang đã đối chiếu DB
     * @return array ['cancelled' => bool, 'message' => ..., '_action' => lệnh cho FE]
     */
    public function execute($args = [], $context = [])
    {
        $lines = is_array($context['checkout_draft'] ?? null) ? $context['checkout_draft'] : [];
        $hadDraft = $lines !== [];

        return [
            'cancelled' => $hadDraft,
            'message' => $hadDraft
                ? 'Đã bỏ đơn nháp trên trang "Lên đơn nhanh". Đơn chưa từng được đặt nên không giữ hàng, không mất tiền. '
                    . 'Báo khách ngắn gọn và mời khách chọn sản phẩm khác nếu muốn.'
                : 'Khách không có đơn nháp nào đang mở. Báo khách hiện chưa có đơn nháp, không cần làm gì thêm.',
            // Lệnh cho FE: xóa đơn nháp trong sessionStorage rồi mở trang lên đơn (màn "chưa có đơn nháp").
            Gemini::ACTION_KEY => ['type' => 'checkout-draft-clear'],
        ];
    }
}
