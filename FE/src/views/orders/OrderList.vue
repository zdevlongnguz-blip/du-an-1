<script setup>
// Trang "Đơn hàng của tôi": danh sách đơn của user đang đăng nhập, có phân trang.
// Gọi api-orders (danh sách), api-order-payment (thanh toán lại đơn online), api-order-cancel (hủy đơn).
// Bố cục theo màn "Lịch sử đơn hàng" của Stitch, dựng bằng style/breakpoint của dự án (xem trang Giỏ hàng).
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { showToast } from '@/helpers/ui/toast'
import PaginationBar from '@/components/PaginationBar.vue'
import OrderPanel from '@/components/orders/OrderPanel.vue'
import StatusBadge from '@/components/orders/StatusBadge.vue'
import { cancelOrder, startOnlinePayment } from '@/helpers/orders/orderActions'
import {
  canCancelOrder,
  canPayOnline,
  formatOrderDate,
  orderStatusLabel,
  orderStatusTone,
  paymentMethodLabel,
  paymentStatusLabel,
  paymentStatusTone,
} from '@/helpers/orders/orderLabels'

// Số đơn mỗi trang; BE cho phép tối đa 100.
const PER_PAGE = 10

// Danh sách đơn của trang hiện tại. ref: khi giá trị đổi, giao diện tự cập nhật.
const orders = ref([])
// Thông tin phân trang do BE trả về (current_page, total_pages, total_items).
const pagination = ref({ current_page: 1, total_pages: 1, total_items: 0 })
// Trạng thái tải và lỗi tải; lỗi được hiển thị riêng để không bị nhầm với "chưa có đơn".
const loading = ref(false)
const loadError = ref('')
// ID đơn đang thanh toán hoặc hủy; dùng để khóa nút và tránh gửi lặp.
const busyOrderId = ref(null)

// Gọi api-orders lấy một trang đơn. Response: { status, orders: { data: [...], pagination: {...} } }.
// Được gọi khi trang mở, khi đổi trang và sau khi hủy đơn.
async function loadOrders(page = 1) {
  loading.value = true
  loadError.value = ''

  try {
    const response = await api.get('/?action=api-orders', { params: { page, per_page: PER_PAGE } })
    const data = response.data
    if (data?.status !== 'success' || !Array.isArray(data?.orders?.data)) {
      throw new Error('Dữ liệu đơn hàng trả về không hợp lệ.')
    }
    orders.value = data.orders.data
    pagination.value = data.orders.pagination || pagination.value
  } catch (error) {
    loadError.value = error.response?.data?.message || error.message || 'Không tải được đơn hàng.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây.
    loading.value = false
  }
}

// Chuyển sang SePay để thanh toán đơn online còn chờ. Khi thành công trình duyệt rời khỏi trang.
async function payOrder(order) {
  if (busyOrderId.value !== null) return
  busyOrderId.value = order.id

  try {
    await startOnlinePayment(order.id)
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.', 'danger')
    busyOrderId.value = null
  }
}

// Hủy đơn sau khi người dùng xác nhận; BE hoàn tồn kho. Xong thì tải lại danh sách.
async function cancel(order) {
  if (busyOrderId.value !== null) return
  if (!window.confirm(`Bạn có chắc muốn hủy đơn #${order.id} không?`)) return
  busyOrderId.value = order.id

  try {
    showToast(await cancelOrder(order.id), 'success')
    await loadOrders(pagination.value.current_page)
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không hủy được đơn hàng.', 'danger')
  } finally {
    busyOrderId.value = null
  }
}

// onMounted: chạy 1 lần khi trang vừa hiện ra.
onMounted(() => loadOrders())
</script>

<template>
  <section class="orders-page">
    <!-- Đường dẫn phụ; ẩn trên điện thoại giống trang chi tiết sản phẩm. -->
    <nav class="orders-breadcrumb" aria-label="Đường dẫn">
      <RouterLink to="/">Trang chủ</RouterLink>
      <span aria-hidden="true">/</span>
      <RouterLink to="/profile">Tài khoản</RouterLink>
      <span aria-hidden="true">/</span>
      <span class="is-current" aria-current="page">Đơn hàng của tôi</span>
    </nav>

    <!-- Tiêu đề trang và số đơn tổng cộng. -->
    <header class="orders-header">
      <div>
        <p class="orders-eyebrow">Hồ sơ quý ông</p>
        <h1 class="orders-title">Đơn hàng của tôi</h1>
        <p class="orders-subtitle">Theo dõi trạng thái giao hàng, thanh toán và lịch sử mua sắm của bạn.</p>
      </div>
      <p v-if="!loading && !loadError" class="orders-stat">
        <span class="orders-stat__label">Tổng đơn</span>
        <strong class="orders-stat__value">{{ pagination.total_items }}</strong>
      </p>
    </header>

    <!-- Trạng thái tải danh sách đơn. -->
    <div v-if="loading" class="text-center py-5 text-secondary" role="status">Đang tải đơn hàng...</div>

    <!-- Lỗi API có nút tải lại, không bị nhầm với danh sách rỗng. -->
    <div v-else-if="loadError" class="alert alert-danger d-flex align-items-center justify-content-between gap-3" role="alert">
      <span>{{ loadError }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="loadOrders(pagination.current_page)">
        Thử lại
      </button>
    </div>

    <!-- Trạng thái rỗng khi user chưa có đơn nào. -->
    <div v-else-if="orders.length === 0" class="text-center py-5">
      <p class="text-secondary mb-3">Bạn chưa có đơn hàng nào.</p>
      <RouterLink class="btn btn-primary" to="/catalog">Xem sản phẩm</RouterLink>
    </div>

    <!-- Danh sách đơn: mỗi đơn là một thẻ gồm thanh thông tin, nội dung và khối tóm tắt có hành động. -->
    <ul v-else class="list-unstyled orders-list">
      <li v-for="order in orders" :key="order.id">
        <OrderPanel tag="article" class="order-item" :class="{ 'order-item--cancelled': order.order_status === 'cancelled' }">
          <!-- Thanh thông tin: mã đơn, ngày đặt và các huy hiệu trạng thái. -->
          <header class="order-item__meta">
            <div class="order-item__id">
              <RouterLink :to="`/orders/${order.id}`">#{{ order.id }}</RouterLink>
              <span class="order-item__date">{{ formatOrderDate(order.created_at) }}</span>
            </div>
            <div class="order-item__badges">
              <StatusBadge :label="orderStatusLabel(order.order_status)" :tone="orderStatusTone(order.order_status)" />
              <StatusBadge :label="paymentStatusLabel(order.payment_status)" :tone="paymentStatusTone(order.payment_status)" />
            </div>
          </header>

          <div class="order-item__body">
            <!-- Thông tin nhận hàng và phương thức thanh toán. -->
            <dl class="order-item__info">
              <div>
                <dt>Người nhận</dt>
                <dd>{{ order.recipient_name }} · {{ order.recipient_phone }}</dd>
              </div>
              <div>
                <dt>Giao đến</dt>
                <dd class="order-item__address">{{ order.shipping_address }}</dd>
              </div>
              <div>
                <dt>Thanh toán</dt>
                <dd>{{ paymentMethodLabel(order.payment_method) }}</dd>
              </div>
            </dl>

            <!-- Khối tóm tắt: tổng tiền và hành động; thanh toán/hủy chỉ hiện khi đơn còn ở trạng thái cho phép. -->
            <div class="order-item__summary">
              <p class="order-item__total">
                <span>Tổng thanh toán</span>
                <strong>{{ formatPrice(Number(order.total_amount)) }}</strong>
              </p>
              <div class="order-item__actions">
                <RouterLink class="btn btn-primary btn-sm" :to="`/orders/${order.id}`">Xem chi tiết đơn hàng</RouterLink>
                <button
                  v-if="canPayOnline(order)"
                  type="button"
                  class="btn btn-outline-secondary btn-sm"
                  :disabled="busyOrderId !== null"
                  @click="payOrder(order)"
                >
                  {{ busyOrderId === order.id ? 'Đang xử lý...' : 'Thanh toán online' }}
                </button>
                <button
                  v-if="canCancelOrder(order)"
                  type="button"
                  class="btn btn-outline-danger btn-sm"
                  :disabled="busyOrderId !== null"
                  @click="cancel(order)"
                >
                  Hủy đơn
                </button>
              </div>
            </div>
          </div>
        </OrderPanel>
      </li>
    </ul>

    <!-- Phân trang chỉ hiện khi có nhiều hơn một trang. -->
    <PaginationBar
      v-if="!loading && !loadError && pagination.total_pages > 1"
      :current-page="pagination.current_page"
      :total-pages="pagination.total_pages"
      label="Phân trang đơn hàng"
      @change="loadOrders"
    />
  </section>
</template>

<style scoped>
/* Đường dẫn phụ: chữ nhỏ in hoa, trang hiện tại màu vàng đồng như các trang khác. */
.orders-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 16px;
  color: #4a5565;
  font-size: 12px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.orders-breadcrumb a:hover {
  color: #8c6a32;
}

.orders-breadcrumb .is-current {
  color: #8c6a32;
}

.orders-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 24px;
}

.orders-eyebrow {
  margin: 0 0 4px;
  color: #8c6a32;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.orders-title {
  margin: 0;
  color: var(--color-ink);
  font-size: 32px;
  font-weight: 700;
  line-height: 1.2;
}

.orders-subtitle {
  margin: 6px 0 0;
  color: #4a5565;
  font-size: 14px;
}

/* Ô số đơn tổng cộng, giống khối thống kê ở màn Lịch sử đơn hàng của Stitch. */
.orders-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: 0;
  padding: 8px 20px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
}

.orders-stat__label {
  color: #4a5565;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.orders-stat__value {
  color: #8c6a32;
  font-size: 22px;
  line-height: 1.2;
}

.orders-list {
  display: grid;
  gap: 16px;
  margin-bottom: 24px;
}

/* Thẻ đơn: bỏ padding mặc định của OrderPanel vì thanh thông tin và nội dung tự có padding riêng. */
.orders-list .order-item {
  padding: 0;
  overflow: hidden;
}

.order-item__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px 16px;
  padding: 14px 20px;
  border-bottom: 1px solid #d9d4ca;
  background: #ffffff;
}

.order-item__id a {
  color: #8c6a32;
  font-size: 17px;
  font-weight: 700;
}

.order-item__id a:hover {
  color: #7a5200;
}

.order-item__date {
  margin-left: 12px;
  color: #4a5565;
  font-size: 13px;
}

.order-item__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.order-item__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(240px, 320px);
  gap: 24px;
  padding: 20px;
}

/* Đơn đã hủy: làm mờ nội dung để phân biệt với đơn đang xử lý (giống Stitch). */
.order-item--cancelled .order-item__body {
  opacity: 0.7;
}

.order-item__info {
  display: grid;
  align-content: start;
  gap: 12px;
  margin: 0;
}

.order-item__info dt {
  color: #6b7280;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.order-item__info dd {
  margin: 2px 0 0;
  color: var(--color-ink);
  font-size: 14px;
  overflow-wrap: anywhere;
}

/* Địa chỉ dài chỉ hiện tối đa 2 dòng, đủ nhận biết; địa chỉ đầy đủ xem ở trang chi tiết. */
.order-item__address {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.order-item__summary {
  display: grid;
  align-content: start;
  gap: 14px;
  padding: 16px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #f7f4ee;
}

.order-item__total {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin: 0;
}

.order-item__total span {
  color: #4a5565;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.order-item__total strong {
  color: #8c6a32;
  font-size: 22px;
  font-weight: 700;
}

.order-item__actions {
  display: grid;
  gap: 8px;
}

@media (max-width: 767.98px) {
  .order-item__body {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 575.98px) {
  .orders-breadcrumb {
    display: none;
  }

  .orders-title {
    font-size: 26px;
  }

  .order-item__meta {
    padding: 12px 16px;
  }

  .order-item__date {
    display: block;
    margin: 2px 0 0;
  }

  .order-item__body {
    gap: 16px;
    padding: 16px;
  }
}

/* ===== Bổ sung theo Stitch "Lịch sử đơn hàng" ===== */
.orders-title {
  font-family: var(--font-display);
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

/* Thẻ đơn: viền kem, bo 8px, thanh thông tin trên nền kem đậm như Stitch. */
.order-item {
  border-color: #e6e0d4;
  border-radius: 8px;
  overflow: hidden;
}

.order-item__meta {
  background: #efeae0;
}

.order-item__total strong {
  color: #b8893e;
  font-family: var(--font-display);
}

.order-item__actions .btn {
  border-radius: 6px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

</style>
