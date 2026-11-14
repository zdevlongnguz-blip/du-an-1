<script setup>
// Trang kết quả sau khi quay về từ SePay (đường dẫn /payment/success, /payment/error, /payment/cancel).
// URL quay về do chính BE tạo kèm ?order=<ID đơn>. Riêng trang success gọi api-order-payment-verify:
// BE hỏi lại SePay bằng khóa của server, nên trạng thái hiển thị là thật, không dựa vào việc "đã quay về".
// Bố cục theo màn "Thanh toán thành công / thất bại" của Stitch, dựng bằng style của dự án.
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { isAuthenticated } from '@/helpers/auth/session'
import { verifyPayment } from '@/helpers/orders/orderActions'
import OrderPanel from '@/components/orders/OrderPanel.vue'
import StatusBadge from '@/components/orders/StatusBadge.vue'

const route = useRoute()

// Loại kết quả lấy từ meta của route: 'success' | 'error' | 'cancel'.
const kind = computed(() => route.meta.paymentResult || 'error')
// ID đơn lấy từ query ?order=...; chỉ nhận số nguyên dương, sai thì coi như không có.
const orderId = computed(() => {
  const value = Number(route.query.order)
  return Number.isInteger(value) && value > 0 ? value : null
})
// Verify cần đăng nhập. Token lưu theo từng địa chỉ trang, nên mở lại ở địa chỉ khác sẽ chưa đăng nhập.
const loggedIn = isAuthenticated()

// Trạng thái hỏi BE: đang hỏi, lỗi (nếu có) và trạng thái thanh toán mới nhất ('' = chưa biết).
const checking = ref(false)
const checkError = ref('')
const paymentStatus = ref('')

// Hỏi BE xem đơn đã thanh toán chưa. Được gọi khi trang success mở và khi bấm "Kiểm tra lại".
async function checkPayment() {
  if (!orderId.value || checking.value) return
  checking.value = true
  checkError.value = ''

  try {
    const order = await verifyPayment(orderId.value)
    paymentStatus.value = order.payment_status
  } catch (error) {
    checkError.value = error.response?.data?.message || error.message || 'Không kiểm tra được trạng thái thanh toán.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt trạng thái đang kiểm tra ở đây.
    checking.value = false
  }
}

// Chỉ trang success mới cần hỏi BE; error/cancel nghĩa là chưa trả tiền nên không cần.
onMounted(() => {
  if (kind.value === 'success' && orderId.value && loggedIn) checkPayment()
})

// Nội dung từng tình huống: tone quyết định màu (success | danger | pending | neutral), icon là ký tự trong vòng tròn.
// computed: tự tính lại khi trạng thái thay đổi.
const result = computed(() => {
  if (kind.value === 'cancel') {
    return {
      tone: 'neutral', icon: '×', eyebrow: 'Thông báo phiên giao dịch', title: 'Bạn đã hủy thanh toán',
      badge: 'Đã hủy thanh toán',
      message: 'Đơn hàng vẫn được giữ chờ thanh toán trong 30 phút. Bạn có thể thanh toán lại ở trang chi tiết đơn.',
    }
  }
  if (kind.value === 'error') {
    return {
      tone: 'danger', icon: '!', eyebrow: 'Thông báo phiên giao dịch', title: 'Giao dịch chưa hoàn tất',
      badge: 'Giao dịch thất bại · Chưa trừ tiền',
      message: 'SePay báo giao dịch không thành công, đơn hàng chưa được thanh toán. Bạn có thể thử lại ở trang chi tiết đơn.',
    }
  }
  // kind === 'success': tùy kết quả hỏi BE.
  if (paymentStatus.value === 'paid') {
    return {
      tone: 'success', icon: '✓', eyebrow: 'Nghi thức đặt hàng chuẩn mực', title: 'Thanh toán thành công',
      badge: 'Đã thanh toán',
      message: 'Cảm ơn bạn đã tin tưởng FuLe BaberShop. Hệ thống đã ghi nhận thanh toán, đơn hàng đang được chuẩn bị.',
    }
  }
  if (checking.value) {
    return {
      tone: 'pending', icon: '…', eyebrow: 'Đang xử lý', title: 'Đang xác nhận thanh toán',
      badge: 'Đang xác nhận', message: 'Hệ thống đang hỏi SePay về kết quả giao dịch, vui lòng chờ trong giây lát...',
    }
  }
  if (!orderId.value) {
    return {
      tone: 'pending', icon: '…', eyebrow: 'Đã quay về từ SePay', title: 'Đã quay về từ SePay',
      badge: 'Chờ xác nhận', message: 'Vui lòng mở trang Đơn hàng của tôi để xem trạng thái thanh toán.',
    }
  }
  if (!loggedIn) {
    return {
      tone: 'pending', icon: '…', eyebrow: 'Đã quay về từ SePay', title: 'Đã quay về từ SePay',
      badge: 'Chờ xác nhận', message: 'Hãy đăng nhập để xem trạng thái thanh toán của đơn hàng.',
    }
  }
  if (checkError.value) {
    return {
      tone: 'danger', icon: '!', eyebrow: 'Chưa xác nhận được', title: 'Chưa xác nhận được thanh toán',
      badge: 'Chưa xác nhận', message: checkError.value,
    }
  }
  return {
    tone: 'pending', icon: '…', eyebrow: 'Đang chờ SePay', title: 'Chưa nhận được xác nhận',
    badge: 'Chờ xác nhận thanh toán',
    message: 'SePay chưa báo kết quả cho đơn này. Hệ thống sẽ tự cập nhật khi nhận được; bạn cũng có thể bấm "Kiểm tra lại".',
  }
})

// Tone của huy hiệu: các tone của trang kết quả đều trùng tên với tone của StatusBadge (neutral dùng cho "đã hủy").
const badgeTone = computed(() => result.value.tone)

// Hiện nút "Kiểm tra lại" khi đã biết đơn, đã đăng nhập và đơn chưa được xác nhận là đã trả tiền.
const canRecheck = computed(() => kind.value === 'success' && orderId.value && loggedIn && paymentStatus.value !== 'paid')
</script>

<template>
  <section class="result-page">
    <OrderPanel class="result-card">
      <!-- Vòng tròn biểu tượng; màu theo kết quả (vàng = thành công, đỏ = thất bại). -->
      <div class="result-icon" :class="`is-${result.tone}`" aria-hidden="true">{{ result.icon }}</div>
      <p class="result-eyebrow">{{ result.eyebrow }}</p>
      <h1 class="result-title" :class="`is-${result.tone}`">{{ result.title }}</h1>

      <!-- Huy hiệu trạng thái và mã đơn. role="status": trình đọc màn hình đọc lại khi nội dung đổi. -->
      <div class="result-status" role="status">
        <StatusBadge :label="result.badge" :tone="badgeTone" />
        <span v-if="orderId" class="result-order">Mã đơn hàng <strong>#{{ orderId }}</strong></span>
      </div>

      <p class="result-message">{{ result.message }}</p>

      <!-- Các hướng đi tiếp theo. -->
      <div class="result-actions">
        <button v-if="canRecheck" type="button" class="btn btn-outline-secondary" :disabled="checking" @click="checkPayment">
          {{ checking ? 'Đang kiểm tra...' : 'Kiểm tra lại' }}
        </button>
        <RouterLink v-if="orderId && loggedIn" class="btn btn-primary" :to="`/orders/${orderId}`">Xem đơn hàng</RouterLink>
        <RouterLink v-if="!loggedIn" class="btn btn-primary" to="/login">Đăng nhập</RouterLink>
        <RouterLink v-if="loggedIn && !orderId" class="btn btn-primary" to="/orders">Đơn hàng của tôi</RouterLink>
        <RouterLink class="btn btn-outline-secondary" to="/catalog">Tiếp tục mua sắm</RouterLink>
      </div>
    </OrderPanel>
  </section>
</template>

<style scoped>
/* Thẻ kết quả căn giữa, rộng vừa phải như màn Thanh toán thành công/thất bại của Stitch. */
.result-card {
  max-width: 720px;
  margin: 0 auto;
  padding: 40px 32px;
  text-align: center;
}

.result-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  margin: 0 auto 16px;
  border: 2px solid #d9d4ca;
  border-radius: 50%;
  background: #ffffff;
  color: #4a5565;
  font-size: 32px;
  font-weight: 700;
  line-height: 1;
}

.result-icon.is-success {
  border-color: #b8893e;
  background: #fff7e6;
  color: #8c6a32;
  box-shadow: 0 0 24px rgb(184 137 62 / 30%);
}

.result-icon.is-danger {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}

.result-icon.is-pending {
  border-color: #b8893e;
  background: #ffffff;
  color: #8c6a32;
}

.result-eyebrow {
  margin: 0 0 6px;
  color: #8c6a32;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.result-title {
  margin: 0 0 14px;
  color: var(--color-ink);
  font-size: 36px;
  font-weight: 700;
  line-height: 1.2;
}

/* Thành công: tiêu đề màu vàng đồng như Stitch; các trường hợp khác giữ màu chữ thường. */
.result-title.is-success {
  color: #8c6a32;
}

.result-status {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 8px 16px;
  margin-bottom: 18px;
}

.result-order {
  color: #4a5565;
  font-size: 14px;
}

.result-order strong {
  color: var(--color-ink);
}

.result-message {
  max-width: 520px;
  margin: 0 auto 28px;
  color: #4a5565;
  line-height: 1.6;
}

.result-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
}

@media (max-width: 575.98px) {
  .result-card {
    padding: 28px 16px;
  }

  .result-title {
    font-size: 28px;
  }

  .result-actions .btn {
    flex: 1 1 100%;
  }
}

/* ===== Bổ sung theo Stitch "Thanh toán thành công / thất bại" ===== */
.result-page {
  display: grid;
  max-width: 880px;
  gap: 24px;
  margin: 0 auto;
}

/* Thẻ kết quả: nền trắng, đầu thẻ chuyển nhẹ từ kem sang trắng, bo 12px. */
.result-card {
  border-color: #e6e0d4;
  border-radius: 12px;
  background: linear-gradient(180deg, #faf8f5 0%, #ffffff 40%);
  box-shadow: 0 4px 20px rgba(28, 42, 58, 0.06);
  text-align: center;
}

.result-title {
  font-family: var(--font-display);
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.result-actions .btn {
  min-height: 44px;
  border-radius: 6px;
  font-size: 12px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

</style>
