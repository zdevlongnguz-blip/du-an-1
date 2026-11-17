// Quy tắc số điện thoại di động Việt Nam dùng chung cho FE (form đặt hàng, form hồ sơ).
// Phải giống BE/extensions/VietnamPhone.php; sửa quy tắc thì sửa cả hai. BE vẫn là nơi kiểm tra cuối cùng.

// Regex: số 0, rồi một chữ số trong [35789] (đầu số di động), rồi đúng 8 chữ số nữa => tổng 10 chữ số.
const VN_MOBILE_PATTERN = /^0[35789][0-9]{8}$/

/**
 * Chuẩn hóa số di động Việt Nam về dạng 0xxxxxxxxx.
 * Chấp nhận khoảng trắng, dấu chấm, gạch ngang, ngoặc và đầu số +84 hoặc 84.
 * Ví dụ: "+84 867.490.629" -> "0867490629"; "024 3826 1234" (số cố định) -> null.
 *
 * @param {string} value Số người dùng nhập.
 * @returns {string|null} Số đã chuẩn hóa, hoặc null nếu không hợp lệ.
 */
export function normalizeVietnamPhone(value = '') {
  // Bỏ các ký tự người dùng hay gõ để dễ đọc: khoảng trắng, dấu chấm, gạch ngang, ngoặc.
  // Khoảng trắng gồm cả loại Unicode hay dính vào khi copy số từ Zalo/web (U+00A0, U+2009...).
  // Liệt kê tường minh giống hệt BE/extensions/VietnamPhone.php để hai bên luôn cho cùng kết quả.
  let digits = (value ?? '').trim()
    .replace(/[\s\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000\uFEFF.\-()]/g, '')

  // Đổi mã quốc gia về số 0 đầu: +84xxxxxxxxx hoặc 84xxxxxxxxx (11 chữ số) thành 0xxxxxxxxx.
  if (digits.startsWith('+84')) {
    digits = '0' + digits.slice(3)
  } else if (digits.length === 11 && digits.startsWith('84')) {
    digits = '0' + digits.slice(2)
  }

  return VN_MOBILE_PATTERN.test(digits) ? digits : null
}

/**
 * Kiểm tra số di động Việt Nam và trả thông báo lỗi ('' nếu hợp lệ).
 *
 * @param {string} value Số người dùng nhập.
 * @param {{ required?: boolean }} options required = true thì ô trống cũng là lỗi (ví dụ form đặt hàng).
 * @returns {string} Thông báo lỗi hoặc chuỗi rỗng.
 */
export function validateVietnamPhone(value = '', { required = false } = {}) {
  const phone = (value ?? '').trim()

  if (!phone) {
    return required ? 'Vui lòng nhập số điện thoại.' : ''
  }

  if (!normalizeVietnamPhone(phone)) {
    return 'Số điện thoại phải là số di động Việt Nam 10 chữ số, bắt đầu bằng 03, 05, 07, 08 hoặc 09 (ví dụ 0901234567).'
  }

  return ''
}
