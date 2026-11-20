<?php

/**
 * Lỗi vi phạm quy tắc nghiệp vụ (tầng extension, dùng chung cho model/controller).
 * Model ném lỗi này khi thao tác bị khóa theo nghiệp vụ, ví dụ: xóa sản phẩm đã có trong đơn hàng,
 * hoặc ghi đè tồn kho trong khi kho vừa bị đơn hàng khác thay đổi. Controller bắt lỗi và trả HTTP 409 kèm lý do.
 */
class BusinessConflictException extends RuntimeException
{
    /** @var array Dữ liệu thêm gửi kèm cho FE (ví dụ current_stock, order_count). */
    private array $details;

    /**
     * @param string $message Lý do bằng tiếng Việt, hiển thị thẳng cho admin
     * @param array  $details Dữ liệu thêm (khóa => giá trị) để FE cập nhật giao diện
     */
    public function __construct(string $message = '', array $details = [])
    {
        parent::__construct($message);
        $this->details = $details;
    }

    /**
     * Dữ liệu thêm đi kèm lỗi.
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
