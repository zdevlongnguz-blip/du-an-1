<?php
/**
 * Quy tắc số điện thoại di động Việt Nam dùng chung cho mọi API (đặt hàng, hồ sơ).
 * Đặt ở một chỗ để BE chỉ có MỘT quy tắc; FE có bản tương ứng ở FE/src/helpers/format/phone.js, sửa thì sửa cả hai.
 */
class VietnamPhone
{
    /**
     * Chuẩn hóa và kiểm tra số di động Việt Nam.
     * Chấp nhận người dùng gõ kèm khoảng trắng, dấu chấm, gạch ngang, ngoặc và đầu số +84 hoặc 84.
     * Sau khi chuẩn hóa phải đúng 10 chữ số, bắt đầu bằng 03, 05, 07, 08 hoặc 09 (đầu số di động hiện hành).
     * Ví dụ: "+84 867.490.629" -> "0867490629"; "0867 490 629" -> "0867490629"; "024 3826 1234" (số cố định) -> null.
     *
     * @param string $phone Số điện thoại người dùng gửi lên.
     * @return string|null Số đã chuẩn hóa dạng 0xxxxxxxxx, hoặc null nếu không hợp lệ.
     */
    public static function normalize(string $phone = ''): ?string
    {
        // Bỏ các ký tự người dùng hay gõ để dễ đọc: khoảng trắng (\s), dấu chấm, gạch ngang, ngoặc.
        $digits = preg_replace('/[\s.\-()]/', '', trim($phone));

        // Đổi mã quốc gia về số 0 đầu: +84xxxxxxxxx hoặc 84xxxxxxxxx (11 chữ số) thành 0xxxxxxxxx.
        if (strncmp($digits, '+84', 3) === 0) {
            $digits = '0' . substr($digits, 3);
        } elseif (strlen($digits) === 11 && strncmp($digits, '84', 2) === 0) {
            $digits = '0' . substr($digits, 2);
        }

        // Regex: số 0, rồi một chữ số trong [35789] (đầu số di động), rồi đúng 8 chữ số nữa => tổng 10 chữ số.
        // ^ và $ bắt buộc khớp toàn bộ chuỗi, nên còn sót chữ cái hay dấu + ở giữa là không hợp lệ.
        if (!preg_match('/^0[35789][0-9]{8}$/', $digits)) {
            return null;
        }

        return $digits;
    }
}
