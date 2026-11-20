// Tạo slug (đoạn đường dẫn trên URL) từ chuỗi tiếng Việt, ví dụ "Sáp Vuốt Tóc Đẹp" -> "sap-vuot-toc-dep".
// Dùng ở form quản trị để tự điền ô slug theo tên sản phẩm.

// Mẫu slug hợp lệ: chữ thường không dấu, số, nối bằng một dấu "-" (không bắt đầu/kết thúc bằng "-").
export const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

// Đổi chuỗi bất kỳ thành slug.
// normalize('NFD') tách chữ có dấu thành chữ gốc + dấu riêng ("á" -> "a" + dấu sắc),
// rồi xóa các dấu đó (mã Unicode U+0300 đến U+036F).
// "đ/Đ" không tách được theo cách đó nên đổi riêng.
export function toSlug(text) {
  return String(text ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .replace(/Đ/g, 'D')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
