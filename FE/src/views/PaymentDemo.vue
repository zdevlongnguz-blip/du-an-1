<script setup>
// Trang demo thanh toán: gọi api-orders và tạo form đã ký qua api-order-payment.
import { computed, onMounted, ref } from 'vue'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { submitSePayCheckout } from '@/helpers/payment/sepayForm'
import { showToast } from '@/helpers/ui/toast'

// Danh sách đơn do BE trả về; chỉ lọc đơn có thể thanh toán trong computed.
const orders = ref([])
// Trạng thái tải đơn, lỗi tải và ID đơn đang chuyển sang SePay.
const loading = ref(false)
const loadError = ref('')
const processingOrderId = ref(null)

// Chỉ cho phép bắt đầu thanh toán với đơn còn pending và unpaid.
const unpaidOrders = computed(() => orders.value.filter(
  (order) => order.order_status === 'pending' && order.payment_status === 'unpaid',
))

// Gọi api-orders để tải danh sách đơn của user; lỗi được hiển thị thay vì coi như danh sách rỗng.
async function loadOrders() {
  loading.value = true
  loadError.value = ''

  try {
    const response = await api.get('/?action=api-orders', {
      params: { page: 1, per_page: 100 },
    })
    const data = response.data
    if (data?.status !== 'success' || !Array.isArray(data?.orders?.data)) {
      throw new Error('Dữ liệu đơn hàng trả về không hợp lệ.')
    }
    orders.value = data.orders.data
  } catch (error) {
    loadError.value = error.response?.data?.message || error.message || 'Không tải được đơn hàng.'
  } finally {
    loading.value = false
  }
}

// Gọi api-order-payment để nhận các trường đã ký; sau đó trình duyệt POST trực tiếp sang SePay.
async function startPayment(order) {
  processingOrderId.value = order.id

  try {
    const response = await api.post('/?action=api-order-payment', {}, {
      params: { id: order.id },
    })
    const checkout = response.data?.checkout
    if (response.data?.status !== 'success') {
      throw new Error('Không nhận được dữ liệu thanh toán hợp lệ.')
    }
    submitSePayCheckout(checkout)
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.', 'danger')
    processingOrderId.value = null
  }
}

// Tải các đơn của user một lần khi trang demo vừa mở.
onMounted(loadOrders)
</script>

<template>
  <section class="container py-4">
    <h1 class="h3 mb-3">Thanh toán SePay Sandbox</h1>
    <p class="mb-4">
      Chỉ đơn đang chờ thanh toán mới xuất hiện. IPN chưa được bật nên quay về từ SePay
      không đồng nghĩa hệ thống đã xác nhận thanh toán.
    </p>

    <!-- Trạng thái tải danh sách đơn. -->
    <div v-if="loading" class="alert alert-info" role="status">Đang tải đơn hàng...</div>

    <!-- Lỗi API có nút tải lại, không bị nhầm với danh sách rỗng. -->
    <div v-else-if="loadError" class="alert alert-danger" role="alert">
      <p class="mb-2">{{ loadError }}</p>
      <button type="button" class="btn btn-outline-danger btn-sm" @click="loadOrders">
        Thử tải lại
      </button>
    </div>

    <!-- Trạng thái rỗng khi user chưa có đơn pending/unpaid. -->
    <div v-else-if="unpaidOrders.length === 0" class="alert alert-secondary">
      Không có đơn hàng nào đang chờ thanh toán.
    </div>

    <!-- Mỗi đơn có nút khởi tạo checkout riêng. -->
    <div v-else class="list-group">
      <article v-for="order in unpaidOrders" :key="order.id" class="list-group-item">
        <div class="d-flex flex-column flex-sm-row justify-content-between gap-3">
          <div>
            <h2 class="h6 mb-2">Đơn hàng #{{ order.id }}</h2>
            <p class="mb-1">Tổng tiền: {{ formatPrice(Number(order.total_amount)) }}</p>
            <p class="mb-0 text-muted">Trạng thái: chờ thanh toán</p>
          </div>
          <button
            type="button"
            class="btn btn-primary align-self-start"
            :disabled="processingOrderId !== null"
            @click="startPayment(order)"
          >
            {{ processingOrderId === order.id ? 'Đang chuyển...' : 'Thanh toán qua SePay' }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>
