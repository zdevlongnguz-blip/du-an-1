// Các thao tác gọi API cho đơn hàng dùng chung cho nhiều trang: thanh toán online, hủy đơn, xác minh thanh toán.
// Hàm ném lỗi (throw) khi thất bại; trang gọi dùng try/catch để hiện thông báo cho người dùng.
import api from '@/helpers/api/client'
import { submitSePayCheckout } from '@/helpers/payment/sepayForm'

// Gọi api-order-payment để nhận các trường đã ký rồi chuyển trình duyệt sang SePay bằng form POST.
// Response thành công: { status: 'success', checkout: { action, fields } }. Lỗi: err.response.data.message.
// Khi thành công, trình duyệt rời khỏi trang nên hàm không cần trả về gì.
export async function startOnlinePayment(orderId) {
  const response = await api.post('/?action=api-order-payment', {}, { params: { id: orderId } })
  if (response.data?.status !== 'success') {
    throw new Error('Không nhận được dữ liệu thanh toán hợp lệ.')
  }
  submitSePayCheckout(response.data.checkout)
}

// Gọi api-order-cancel để hủy đơn pending/unpaid; BE hoàn lại tồn kho. Lỗi 409 nếu đơn không còn hủy được.
export async function cancelOrder(orderId) {
  const response = await api.post('/?action=api-order-cancel', null, { params: { id: orderId } })
  if (response.data?.status !== 'success') {
    throw new Error('Không hủy được đơn hàng.')
  }
  return response.data.message || 'Đã hủy đơn hàng.'
}

// Gọi api-order-payment-verify: BE hỏi lại SePay xem đơn đã được thanh toán chưa rồi trả trạng thái mới nhất.
// Response thành công: { status: 'success', order: { id, order_status, payment_status, payment_method } }.
// Trả về object order đó. Lỗi 502 nghĩa là BE chưa kết nối được SePay (cho phép thử lại).
export async function verifyPayment(orderId) {
  const response = await api.post('/?action=api-order-payment-verify', null, { params: { id: orderId } })
  if (response.data?.status !== 'success' || !response.data?.order) {
    throw new Error('Không nhận được trạng thái thanh toán hợp lệ.')
  }
  return response.data.order
}
