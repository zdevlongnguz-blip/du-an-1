// Nhãn tiếng Việt, "tone" (sắc thái màu) của huy hiệu và quy tắc "được phép làm gì" cho đơn hàng.
// Dùng chung cho danh sách đơn, chi tiết đơn và trang kết quả thanh toán để hiển thị nhất quán.
// Tone được component StatusBadge.vue chuyển thành màu theo bảng màu của dự án.

// Trạng thái xử lý đơn: khớp ENUM order_status của BE.
const ORDER_STATUS = {
  pending: { label: 'Chờ xác nhận', tone: 'pending' },
  confirmed: { label: 'Đã xác nhận', tone: 'confirmed' },
  shipping: { label: 'Đang giao hàng', tone: 'shipping' },
  delivered: { label: 'Đã giao thành công', tone: 'success' },
  cancelled: { label: 'Đã hủy', tone: 'danger' },
}

// Trạng thái thanh toán: khớp ENUM payment_status của BE.
const PAYMENT_STATUS = {
  unpaid: { label: 'Chưa thanh toán', tone: 'neutral' },
  paid: { label: 'Đã thanh toán', tone: 'success' },
}

// Phương thức thanh toán: khớp ENUM payment_method của BE.
const PAYMENT_METHOD = {
  cod: 'Thanh toán khi nhận hàng (COD)',
  sepay: 'Thanh toán online (SePay)',
}

// Các bước tiến trình của một đơn bình thường, theo thứ tự (dùng vẽ thanh tiến trình ở trang chi tiết).
export const ORDER_PROGRESS_STEPS = ['pending', 'confirmed', 'shipping', 'delivered']

// Nhãn trạng thái đơn; giá trị lạ thì hiện nguyên văn để không che mất dữ liệu.
export function orderStatusLabel(status) {
  return ORDER_STATUS[status]?.label || String(status || '')
}

// Tone màu của huy hiệu tương ứng trạng thái đơn.
export function orderStatusTone(status) {
  return ORDER_STATUS[status]?.tone || 'neutral'
}

// Nhãn trạng thái thanh toán.
export function paymentStatusLabel(status) {
  return PAYMENT_STATUS[status]?.label || String(status || '')
}

// Tone màu của huy hiệu tương ứng trạng thái thanh toán.
export function paymentStatusTone(status) {
  return PAYMENT_STATUS[status]?.tone || 'neutral'
}

// Nhãn phương thức thanh toán.
export function paymentMethodLabel(method) {
  return PAYMENT_METHOD[method] || String(method || '')
}

// Đổi chuỗi thời gian của BE ("2026-11-14 16:57:55") thành "14/11/2026 - 16:57".
// Cắt chuỗi thay vì dùng new Date để không bị lệch múi giờ của trình duyệt.
export function formatOrderDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ''))
  if (!match) return String(value || '')
  return `${match[3]}/${match[2]}/${match[1]} - ${match[4]}:${match[5]}`
}

// Đơn online còn chờ thanh toán: được phép tạo form SePay (BE cũng kiểm tra lại điều kiện này).
export function canPayOnline(order) {
  return order?.payment_method === 'sepay'
    && order?.order_status === 'pending'
    && order?.payment_status === 'unpaid'
}

// Đơn chưa xử lý và chưa trả tiền: user được tự hủy (BE kiểm tra lại).
export function canCancelOrder(order) {
  return order?.order_status === 'pending' && order?.payment_status === 'unpaid'
}
