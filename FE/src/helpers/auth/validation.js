// Các validator phía FE dùng chung; BE vẫn là nơi kiểm tra cuối cùng.
export function validateEmail(value) {
  const email = value.trim()

  if (!email) {
    return 'Vui lòng nhập email.'
  }

  if (email.length > 255) {
    return 'Email không được dài quá 255 ký tự.'
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return 'Vui lòng nhập email đúng định dạng, ví dụ ten@email.com.'
  }

  return ''
}

// Kiểm tra trường bắt buộc và trả thông báo phù hợp với nhãn của trường.
export function validateRequired(value, label) {
  return value.trim() ? '' : `Vui lòng nhập ${label}.`
}

// Đếm byte UTF-8 để khớp giới hạn mật khẩu mà BE đang áp dụng.
export function validateNewPassword(value, label = 'mật khẩu mới') {
  if (!value) {
    return `Vui lòng nhập ${label}.`
  }

  const byteLength = new TextEncoder().encode(value).length
  if (byteLength < 8 || byteLength > 72) {
    return 'Mật khẩu phải dài từ 8 đến 72 byte UTF-8; ký tự có dấu có thể chiếm nhiều byte.'
  }

  return ''
}

// Kiểm tra trường xác nhận đã nhập và khớp với mật khẩu chính.
export function validatePasswordConfirmation(password, confirmation) {
  if (!confirmation) {
    return 'Vui lòng nhập lại mật khẩu.'
  }

  return password === confirmation ? '' : 'Mật khẩu xác nhận chưa khớp.'
}