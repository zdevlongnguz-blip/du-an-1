// Số lượng giỏ hàng dùng chung cho badge trên nav; luôn lấy từ BE, không tự tính ở client.
import { ref } from 'vue'
import api from '@/helpers/api/client'
import { isAuthenticated } from '@/helpers/auth/session'

export const cartCount = ref(0)

// Badge hiển thị tổng số lượng của mọi phiên bản trong giỏ.
export function setCartCountFromItems(items) {
  cartCount.value = (items ?? []).reduce((sum, item) => sum + Number(item.quantity || 0), 0)
}

export async function refreshCartCount() {
  if (!isAuthenticated()) {
    cartCount.value = 0
    return
  }

  try {
    const response = await api.get('/?action=api-cart')
    setCartCountFromItems(response.data.cart?.items)
  } catch {
    // Badge chỉ là thông tin phụ, lỗi tải không được chặn điều hướng.
  }
}
