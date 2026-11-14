<script setup>
// Trang chi tiết đơn / hóa đơn: thông tin giao hàng, tiến trình xử lý, các dòng hàng (snapshot lúc mua) và hành động.
// Gọi api-order-invoice (chi tiết), api-order-payment (thanh toán), api-order-payment-verify (kiểm tra lại), api-order-cancel (hủy).
// Bố cục theo màn "Chi tiết đơn hàng" của Stitch, dựng bằng style/breakpoint của dự án (xem trang Giỏ hàng).
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { showToast } from '@/helpers/ui/toast'
import OrderPanel from '@/components/orders/OrderPanel.vue'
import StatusBadge from '@/components/orders/StatusBadge.vue'
import { cancelOrder, startOnlinePayment, verifyPayment } from '@/helpers/orders/orderActions'
import {
  ORDER_PROGRESS_STEPS,
  canCancelOrder,
  canPayOnline,
  formatOrderDate,
  orderStatusLabel,
  orderStatusTone,
  paymentMethodLabel,
  paymentStatusLabel,
  paymentStatusTone,
} from '@/helpers/orders/orderLabels'

const route = useRoute()

// Đơn và các dòng hàng từ BE: { order: {...}, items: [...] }. null khi chưa tải xong hoặc lỗi.
const invoice = ref(null)
// Trạng thái tải, lỗi tải và hành động đang chạy (khóa nút để tránh gửi lặp).
const loading = ref(false)
const loadError = ref('')
const acting = ref(false)

// Rút order ra computed để template không phải kiểm tra null nhiều lần.
const order = computed(() => invoice.value?.order || null)
const items = computed(() => invoice.value?.items || [])

// Mô tả ngắn cho từng bước tiến trình (thứ tự khớp ORDER_PROGRESS_STEPS).
const STEP_NOTES = [
  'Đơn đã được ghi nhận, cửa hàng đang kiểm tra kho.',
  'Cửa hàng xác nhận và đóng gói đơn hàng.',
  'Đơn vị vận chuyển đang giao hàng đến bạn.',
  'Bạn đã nhận hàng và hoàn tất đơn.',
]

// Danh sách bước kèm trạng thái: done (đã qua), current (đang ở), upcoming (chưa tới).
// computed: tự tính lại khi trạng thái đơn đổi. Đơn đã hủy không có tiến trình nên trả mảng rỗng.
const steps = computed(() => {
  const status = order.value?.order_status
  const currentIndex = ORDER_PROGRESS_STEPS.indexOf(status)
  if (currentIndex < 0) return []
  return ORDER_PROGRESS_STEPS.map((step, index) => ({
    key: step,
    label: `${index + 1}. ${orderStatusLabel(step)}`,
    note: STEP_NOTES[index],
    state: index < currentIndex ? 'done' : index === currentIndex ? 'current' : 'upcoming',
  }))
})

// Gọi api-order-invoice. Response: { status, invoice: { order, items } }. Lỗi 404 nếu đơn không tồn tại hoặc không phải của user.
async function loadInvoice() {
  loading.value = true
  loadError.value = ''

  try {
    const response = await api.get('/?action=api-order-invoice', { params: { id: route.params.id } })
    if (response.data?.status !== 'success' || !response.data?.invoice?.order) {
      throw new Error('Dữ liệu đơn hàng trả về không hợp lệ.')
    }
    invoice.value = response.data.invoice
  } catch (error) {
    invoice.value = null
    loadError.value = error.response?.data?.message || error.message || 'Không tải được đơn hàng.'
  } finally {
    loading.value = false
  }
}

// Chuyển sang SePay để thanh toán đơn online còn chờ. Khi thành công trình duyệt rời khỏi trang.
async function pay() {
  if (acting.value) return
  acting.value = true

  try {
    await startOnlinePayment(order.value.id)
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.', 'danger')
    acting.value = false
  }
}

// Hỏi BE (BE hỏi lại SePay) xem đơn đã được thanh toán chưa; dùng khi đã trả tiền nhưng trang chưa cập nhật.
async function checkPayment() {
  if (acting.value) return
  acting.value = true

  try {
    const result = await verifyPayment(order.value.id)
    await loadInvoice()
    const paid = result.payment_status === 'paid'
    showToast(paid ? 'Đã ghi nhận thanh toán.' : 'Chưa nhận được xác nhận thanh toán từ SePay.', paid ? 'success' : 'info')
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không kiểm tra được thanh toán.', 'danger')
  } finally {
    acting.value = false
  }
}

// Hủy đơn sau khi người dùng xác nhận; BE hoàn tồn kho.
async function cancel() {
  if (acting.value) return
  if (!window.confirm(`Bạn có chắc muốn hủy đơn #${order.value.id} không?`)) return
  acting.value = true

  try {
    showToast(await cancelOrder(order.value.id), 'success')
    await loadInvoice()
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không hủy được đơn hàng.', 'danger')
  } finally {
    acting.value = false
  }
}

// Tải khi mở trang và tải lại nếu người dùng chuyển sang đơn khác (cùng component, đổi :id trên URL).
onMounted(loadInvoice)
watch(() => route.params.id, loadInvoice)
</script>

<template>
  <section class="detail-page">
    <!-- Đường dẫn phụ và nút quay lại; breadcrumb ẩn trên điện thoại. -->
    <nav class="detail-nav" aria-label="Đường dẫn">
      <div class="detail-breadcrumb">
        <RouterLink to="/">Trang chủ</RouterLink>
        <span aria-hidden="true">/</span>
        <RouterLink to="/orders">Đơn hàng của tôi</RouterLink>
        <span aria-hidden="true">/</span>
        <span class="is-current" aria-current="page">Chi tiết đơn hàng<template v-if="order"> #{{ order.id }}</template></span>
      </div>
      <RouterLink class="detail-back" to="/orders">&larr; Quay lại lịch sử đơn hàng</RouterLink>
    </nav>

    <!-- Trạng thái tải. -->
    <div v-if="loading && !order" class="text-center py-5 text-secondary" role="status">Đang tải đơn hàng...</div>

    <!-- Lỗi (gồm 404 không tìm thấy) có nút thử lại. -->
    <div v-else-if="loadError" class="alert alert-danger d-flex align-items-center justify-content-between gap-3" role="alert">
      <span>{{ loadError }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="loadInvoice">Thử lại</button>
    </div>

    <template v-else-if="order">
      <!-- Khối đầu: mã đơn, ngày đặt, huy hiệu trạng thái, hành động và tiến trình xử lý. -->
      <OrderPanel class="detail-hero">
        <div class="detail-hero__top">
          <div class="detail-hero__title">
            <p class="detail-eyebrow">Biên nhận đơn hàng</p>
            <h1 class="detail-title">Chi tiết đơn hàng #{{ order.id }}</h1>
            <p class="detail-date">Đặt lúc {{ formatOrderDate(order.created_at) }}</p>
            <div class="detail-badges">
              <StatusBadge :label="orderStatusLabel(order.order_status)" :tone="orderStatusTone(order.order_status)" />
              <StatusBadge :label="paymentStatusLabel(order.payment_status)" :tone="paymentStatusTone(order.payment_status)" />
            </div>
          </div>

          <!-- Hành động: chỉ hiện khi trạng thái đơn cho phép (BE vẫn kiểm tra lại). -->
          <div class="detail-actions">
            <button v-if="canPayOnline(order)" type="button" class="btn btn-primary" :disabled="acting" @click="pay">
              {{ acting ? 'Đang xử lý...' : 'Thanh toán online' }}
            </button>
            <button v-if="canPayOnline(order)" type="button" class="btn btn-outline-secondary" :disabled="acting" @click="checkPayment">
              Kiểm tra thanh toán
            </button>
            <button v-if="canCancelOrder(order)" type="button" class="btn btn-outline-danger" :disabled="acting" @click="cancel">
              Hủy đơn hàng
            </button>
          </div>
        </div>

        <!-- Đơn đã hủy không có tiến trình, chỉ báo trạng thái. -->
        <div v-if="order.order_status === 'cancelled'" class="alert alert-danger mb-0 mt-4" role="status">
          Đơn hàng này đã được hủy. Hàng đã được hoàn lại kho.
        </div>

        <!-- Tiến trình xử lý đơn: 4 bước, bước đã qua tô vàng, bước hiện tại có viền sáng. -->
        <ol v-else class="detail-steps" aria-label="Tiến trình xử lý đơn hàng">
          <li v-for="step in steps" :key="step.key" class="detail-steps__item" :class="`is-${step.state}`">
            <span class="detail-steps__marker" aria-hidden="true">{{ step.state === 'done' ? '✓' : '' }}</span>
            <p class="detail-steps__label">{{ step.label }}</p>
            <p class="detail-steps__note">{{ step.note }}</p>
          </li>
        </ol>
      </OrderPanel>

      <!-- Gợi ý theo trạng thái để người dùng biết cần làm gì tiếp. -->
      <div v-if="canPayOnline(order)" class="alert alert-warning mt-4 mb-0" role="status">
        Đơn đang chờ thanh toán online. Đơn chưa thanh toán sau 30 phút sẽ tự hủy và hoàn lại hàng.
        Nếu bạn đã trả tiền mà trạng thái chưa đổi, bấm "Kiểm tra thanh toán".
      </div>
      <div v-else-if="order.payment_status === 'paid' && order.order_status === 'pending'" class="alert alert-success mt-4 mb-0" role="status">
        Đã nhận thanh toán. Cửa hàng sẽ sớm xác nhận và giao hàng cho bạn.
      </div>

      <div class="row g-4 mt-0">
        <!-- Cột trái: các dòng hàng; tên, biến thể, đơn giá và thành tiền là snapshot tại thời điểm mua. -->
        <section class="col-lg-7" aria-label="Sản phẩm trong đơn">
          <OrderPanel class="h-100">
            <h2 class="detail-heading">Sản phẩm trong đơn ({{ items.length }})</h2>
            <ul class="list-unstyled mb-0">
              <li v-for="item in items" :key="item.id" class="detail-item">
                <div class="detail-item__main">
                  <p class="detail-item__name">{{ item.product_name }}</p>
                  <p v-if="item.variant_label" class="detail-item__variant">{{ item.variant_label }}</p>
                  <p class="detail-item__price">{{ formatPrice(Number(item.unit_price)) }} × {{ item.quantity }}</p>
                </div>
                <p class="detail-item__total">{{ formatPrice(Number(item.line_total)) }}</p>
              </li>
            </ul>
          </OrderPanel>
        </section>

        <!-- Cột phải: thông tin giao nhận và tổng kết thanh toán. -->
        <aside class="col-lg-5 detail-side">
          <OrderPanel>
            <h2 class="detail-heading">Thông tin giao nhận</h2>
            <dl class="detail-fields">
              <div>
                <dt>Người nhận</dt>
                <dd>{{ order.recipient_name }}</dd>
              </div>
              <div>
                <dt>Số điện thoại</dt>
                <dd>{{ order.recipient_phone }}</dd>
              </div>
              <div>
                <dt>Địa chỉ giao hàng</dt>
                <dd>{{ order.shipping_address }}</dd>
              </div>
            </dl>
          </OrderPanel>

          <OrderPanel>
            <h2 class="detail-heading">Tổng kết thanh toán</h2>
            <dl class="detail-fields">
              <div>
                <dt>Hình thức thanh toán</dt>
                <dd>{{ paymentMethodLabel(order.payment_method) }}</dd>
              </div>
              <div>
                <dt>Trạng thái</dt>
                <dd>
                  <StatusBadge :label="paymentStatusLabel(order.payment_status)" :tone="paymentStatusTone(order.payment_status)" />
                </dd>
              </div>
            </dl>
            <p class="detail-total">
              <span>Tổng thanh toán</span>
              <strong>{{ formatPrice(Number(order.total_amount)) }}</strong>
            </p>
          </OrderPanel>
        </aside>
      </div>
    </template>
  </section>
</template>

<style scoped>
.detail-nav {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px 16px;
  margin-bottom: 16px;
  font-size: 12px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.detail-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  color: #4a5565;
}

.detail-breadcrumb a:hover,
.detail-back:hover {
  color: #8c6a32;
}

.detail-breadcrumb .is-current {
  color: #8c6a32;
}

.detail-back {
  color: #4a5565;
}

/* Khối đầu trang */
.detail-hero__top {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.detail-eyebrow {
  margin: 0 0 4px;
  color: #8c6a32;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.detail-title {
  margin: 0;
  color: var(--color-ink);
  font-size: 32px;
  font-weight: 700;
  line-height: 1.2;
}

.detail-date {
  margin: 6px 0 12px;
  color: #4a5565;
  font-size: 14px;
}

.detail-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

/* Tiến trình xử lý: 4 cột ngang trên màn hình lớn, xếp dọc có đường nối bên trái trên điện thoại. */
.detail-steps {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
  margin: 24px 0 0;
  padding: 20px;
  border-radius: 4px;
  background: #f7f4ee;
  list-style: none;
}

.detail-steps__item {
  position: relative;
  padding-top: 44px;
}

.detail-steps__marker {
  position: absolute;
  top: 0;
  left: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: 2px solid #d9d4ca;
  border-radius: 50%;
  background: #ffffff;
  color: #7a5200;
  font-size: 14px;
  font-weight: 700;
}

/* Đường nối giữa hai bước, bắt đầu sau vòng tròn và dừng trước bước kế tiếp. */
.detail-steps__item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 15px;
  left: 44px;
  right: -12px;
  height: 2px;
  background: #d9d4ca;
}

.detail-steps__label {
  margin: 0 0 4px;
  color: #6b7280;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.detail-steps__note {
  margin: 0;
  color: #6b7280;
  font-size: 13px;
  line-height: 1.5;
}

/* Bước đã qua: vòng tròn vàng đặc, đường nối vàng. */
.detail-steps__item.is-done .detail-steps__marker {
  border-color: #b8893e;
  background: #b8893e;
}

.detail-steps__item.is-done:not(:last-child)::after {
  background: #b8893e;
}

.detail-steps__item.is-done .detail-steps__label,
.detail-steps__item.is-done .detail-steps__note {
  color: #4a5565;
}

/* Bước hiện tại: vòng tròn vàng có chấm giữa và chữ sáng. */
.detail-steps__item.is-current .detail-steps__marker {
  border-color: #b8893e;
  background: #fff7e6;
  box-shadow: 0 0 0 4px rgb(184 137 62 / 20%);
}

.detail-steps__item.is-current .detail-steps__marker::before {
  content: '';
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #b8893e;
}

.detail-steps__item.is-current .detail-steps__label {
  color: #8c6a32;
}

.detail-steps__item.is-current .detail-steps__note {
  color: #131e2a;
}

/* Tiêu đề từng khối */
.detail-heading {
  margin: 0 0 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #d9d4ca;
  color: var(--color-ink);
  font-size: 22px;
  font-weight: 700;
}

/* Các dòng hàng: cùng kiểu danh sách có đường kẻ như trang Giỏ hàng. */
.detail-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid #d9d4ca;
}

.detail-item:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.detail-item__main {
  min-width: 0;
}

.detail-item__name {
  margin: 0;
  color: var(--color-ink);
  font-weight: 600;
  overflow-wrap: anywhere;
}

.detail-item__variant,
.detail-item__price {
  margin: 2px 0 0;
  color: #4a5565;
  font-size: 13px;
}

.detail-item__total {
  flex-shrink: 0;
  margin: 0;
  color: var(--color-ink);
  font-weight: 600;
}

/* Khối thông tin dạng nhãn - giá trị */
.detail-side {
  display: grid;
  align-content: start;
  gap: 24px;
}

.detail-fields {
  display: grid;
  gap: 14px;
  margin: 0;
}

.detail-fields dt {
  color: #6b7280;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.detail-fields dd {
  margin: 2px 0 0;
  color: var(--color-ink);
  overflow-wrap: anywhere;
}

.detail-total {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin: 16px 0 0;
  padding-top: 16px;
  border-top: 1px solid #d9d4ca;
}

.detail-total span {
  color: #4a5565;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.detail-total strong {
  color: #8c6a32;
  font-size: 24px;
  font-weight: 700;
}

@media (max-width: 991.98px) {
  /* Khoảng cách giữa hai cột khi xếp dọc đã do Bootstrap g-4 xử lý; chỉ cần bỏ chiều cao ép đầy. */
  .detail-side {
    gap: 16px;
  }
}

@media (max-width: 767.98px) {
  /* Tiến trình xếp dọc: vòng tròn bên trái, đường nối dọc đi xuống. */
  .detail-steps {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .detail-steps__item {
    padding: 0 0 20px 48px;
  }

  .detail-steps__item:last-child {
    padding-bottom: 0;
  }

  .detail-steps__item:not(:last-child)::after {
    top: 36px;
    bottom: 4px;
    left: 15px;
    right: auto;
    width: 2px;
    height: auto;
  }
}

@media (max-width: 575.98px) {
  .detail-breadcrumb {
    display: none;
  }

  .detail-title {
    font-size: 26px;
  }

  .detail-actions {
    width: 100%;
  }

  .detail-actions .btn {
    flex: 1 1 100%;
  }

  .detail-steps {
    padding: 16px;
  }
}

/* ===== Bổ sung theo Stitch "Chi tiết đơn hàng" ===== */
.detail-title {
  font-family: var(--font-display);
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

/* Nút hành động ở đầu biên nhận: bo 6px, chữ in hoa nhỏ giãn như Stitch. */
.detail-page .btn {
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
</style>
