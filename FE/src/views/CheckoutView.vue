<script setup>
// Màn checkout tối giản: xem giỏ, nhập thông tin giao hàng, chọn COD hoặc SePay sandbox.
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { submitSePayCheckout } from '@/helpers/payment/sepayForm'
import { showToast } from '@/helpers/ui/toast'

// Giỏ hàng và trạng thái tải từ API; lỗi được tách riêng với giỏ rỗng.
const cart = ref(null)
const loadingCart = ref(false)
const cartError = ref('')
// Sản phẩm có thể thêm trực tiếp từ trang checkout để demo không phụ thuộc ProductDetail.
const products = ref([])
const loadingProducts = ref(false)
const productsError = ref('')
const selectedProductId = ref('')
const addingProduct = ref(false)
// Thông tin giao hàng và phương thức khách chọn.
const recipientName = ref('')
const recipientPhone = ref('')
const shippingAddress = ref('')
const paymentMethod = ref('cod')
// Trạng thái gửi đơn, khởi tạo thanh toán và kết quả đặt hàng.
const submitting = ref(false)
const paymentLoading = ref(false)
const checkoutError = ref('')
const createdOrder = ref(null)

// Danh sách item rút từ cart để template không phải kiểm tra null ở nhiều nơi.
const cartItems = computed(() => cart.value?.items || [])
// Chỉ cho chọn sản phẩm còn tồn kho theo dữ liệu backend.
const availableProducts = computed(() => products.value.filter(
  (product) => Number(product.stock) > 0,
))

// Gọi api-cart để lấy mặt hàng và tổng tiền do backend tính.
async function loadCart() {
  loadingCart.value = true
  cartError.value = ''

  try {
    const response = await api.get('/?action=api-cart')
    if (response.data?.status !== 'success' || !Array.isArray(response.data?.cart?.items)) {
      throw new Error('Dữ liệu giỏ hàng trả về không hợp lệ.')
    }
    cart.value = response.data.cart
  } catch (error) {
    cartError.value = error.response?.data?.message || error.message || 'Không tải được giỏ hàng.'
  } finally {
    loadingCart.value = false
  }
}

// Gọi api-products để có thể thêm mặt hàng vào giỏ ngay tại màn checkout.
async function loadProducts() {
  loadingProducts.value = true
  productsError.value = ''

  try {
    const response = await api.get('/?action=api-products', {
      params: { page: 1, per_page: 100 },
    })
    if (response.data?.status !== 'success' || !Array.isArray(response.data?.products?.data)) {
      throw new Error('Dữ liệu sản phẩm trả về không hợp lệ.')
    }
    products.value = response.data.products.data
  } catch (error) {
    productsError.value = error.response?.data?.message || error.message || 'Không tải được sản phẩm.'
  } finally {
    loadingProducts.value = false
  }
}

// Gọi api-cart-add để thêm một sản phẩm mẫu vào giỏ và tải lại tổng tiền từ backend.
async function addProductToCart() {
  if (!selectedProductId.value || addingProduct.value) return

  addingProduct.value = true
  try {
    const response = await api.post('/?action=api-cart-add', {
      product_id: Number(selectedProductId.value),
      quantity: 1,
    })
    if (response.data?.status !== 'success') {
      throw new Error('Không thêm được sản phẩm vào giỏ.')
    }
    showToast(response.data.message || 'Đã thêm sản phẩm vào giỏ.', 'success')
    await loadCart()
  } catch (error) {
    showToast(error.response?.data?.message || error.message || 'Không thêm được sản phẩm vào giỏ.', 'danger')
  } finally {
    addingProduct.value = false
  }
}

// Gọi api-order-payment cho đơn vừa tạo rồi POST form đã ký sang SePay.
async function startOnlinePayment(orderId) {
  paymentLoading.value = true
  checkoutError.value = ''

  try {
    const response = await api.post('/?action=api-order-payment', {}, {
      params: { id: orderId },
    })
    if (response.data?.status !== 'success') {
      throw new Error('Không nhận được dữ liệu thanh toán hợp lệ.')
    }
    submitSePayCheckout(response.data.checkout)
  } catch (error) {
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.'
    showToast(checkoutError.value, 'danger')
  } finally {
    paymentLoading.value = false
  }
}

// Gọi api-order-checkout; COD kết thúc tại trang này, còn online tiếp tục sang SePay.
async function submitCheckout() {
  if (cartItems.value.length === 0) {
    checkoutError.value = 'Giỏ hàng đang trống.'
    return
  }

  submitting.value = true
  checkoutError.value = ''
  createdOrder.value = null

  try {
    const response = await api.post('/?action=api-order-checkout', {
      recipient_name: recipientName.value.trim(),
      recipient_phone: recipientPhone.value.trim(),
      shipping_address: shippingAddress.value.trim(),
    })
    const orderId = response.data?.order?.id
    if (response.data?.status !== 'success' || !orderId) {
      throw new Error('Không nhận được mã đơn hàng hợp lệ.')
    }

    createdOrder.value = { id: orderId, paymentMethod: paymentMethod.value }
    await loadCart()

    if (paymentMethod.value === 'sepay') {
      await startOnlinePayment(orderId)
      return
    }

    showToast('Đặt hàng thành công. Bạn sẽ thanh toán khi nhận hàng.', 'success')
  } catch (error) {
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể tạo đơn hàng.'
    showToast(checkoutError.value, 'danger')
  } finally {
    submitting.value = false
  }
}

// Tải giỏ và sản phẩm một lần khi người dùng mở trang checkout.
onMounted(() => {
  loadCart()
  loadProducts()
})
</script>

<template>
  <section class="container py-4">
    <h1 class="h3 mb-4">Checkout</h1>

    <!-- Bộ chọn sản phẩm giúp kiểm thử checkout mà không cần sửa trang chi tiết hiện có. -->
    <div class="card mb-4">
      <div class="card-body">
        <h2 class="h5 mb-3">Thêm sản phẩm vào giỏ</h2>
        <div v-if="loadingProducts" class="alert alert-info" role="status">Đang tải sản phẩm...</div>
        <div v-else-if="productsError" class="alert alert-danger" role="alert">
          <p class="mb-2">{{ productsError }}</p>
          <button type="button" class="btn btn-outline-danger btn-sm" @click="loadProducts">
            Thử tải lại
          </button>
        </div>
        <div v-else-if="availableProducts.length === 0" class="alert alert-secondary mb-0">
          Hiện không có sản phẩm còn hàng.
        </div>
        <div v-else class="d-flex flex-column flex-sm-row gap-2">
          <label class="visually-hidden" for="checkout-product">Chọn sản phẩm</label>
          <select id="checkout-product" v-model="selectedProductId" class="form-select">
            <option value="" disabled>Chọn sản phẩm</option>
            <option v-for="product in availableProducts" :key="product.id" :value="String(product.id)">
              {{ product.name }}
            </option>
          </select>
          <button
            type="button"
            class="btn btn-outline-primary flex-shrink-0"
            :disabled="!selectedProductId || addingProduct"
            @click="addProductToCart"
          >
            {{ addingProduct ? 'Đang thêm...' : 'Thêm vào giỏ' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Trạng thái tải giỏ hàng. -->
    <div v-if="loadingCart" class="alert alert-info" role="status">Đang tải giỏ hàng...</div>

    <!-- Lỗi API có nút thử lại, không bị nhầm với giỏ trống. -->
    <div v-else-if="cartError" class="alert alert-danger" role="alert">
      <p class="mb-2">{{ cartError }}</p>
      <button type="button" class="btn btn-outline-danger btn-sm" @click="loadCart">Thử lại</button>
    </div>

    <!-- Giỏ trống có liên kết quay lại danh sách sản phẩm. -->
    <div v-else-if="cartItems.length === 0 && !createdOrder" class="alert alert-secondary">
      <p>Giỏ hàng đang trống.</p>
      <RouterLink class="btn btn-primary btn-sm" to="/catalog">Xem sản phẩm</RouterLink>
    </div>

    <!-- Kết quả COD: đơn đã tạo nhưng vẫn chưa thanh toán cho đến khi giao hàng. -->
    <div v-if="createdOrder?.paymentMethod === 'cod'" class="alert alert-success" role="status">
      Đã tạo đơn #{{ createdOrder.id }}. Bạn sẽ thanh toán khi nhận hàng.
    </div>

    <!-- Lỗi khởi tạo online giữ mã đơn để user có thể thử lại mà không tạo đơn lần nữa. -->
    <div v-if="checkoutError" class="alert alert-danger" role="alert">
      <p class="mb-2">{{ checkoutError }}</p>
      <button
        v-if="createdOrder?.paymentMethod === 'sepay'"
        type="button"
        class="btn btn-outline-danger btn-sm"
        :disabled="paymentLoading"
        @click="startOnlinePayment(createdOrder.id)"
      >
        {{ paymentLoading ? 'Đang kết nối...' : 'Thử thanh toán lại' }}
      </button>
    </div>

    <!-- Form checkout chỉ hiện khi còn item trong giỏ và chưa tạo đơn thành công. -->
    <form
      v-if="cartItems.length > 0 && !createdOrder"
      class="row g-4"
      @submit.prevent="submitCheckout"
    >
      <div class="col-12 col-lg-7">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">Thông tin nhận hàng</h2>
            <div class="mb-3">
              <label class="form-label" for="recipient-name">Tên người nhận</label>
              <input
                id="recipient-name"
                v-model="recipientName"
                class="form-control"
                type="text"
                maxlength="100"
                autocomplete="name"
                required
              >
            </div>
            <div class="mb-3">
              <label class="form-label" for="recipient-phone">Số điện thoại</label>
              <input
                id="recipient-phone"
                v-model="recipientPhone"
                class="form-control"
                type="tel"
                maxlength="20"
                minlength="7"
                pattern="(?:[0-9]|\+|\(|\)| |-){7,20}"
                autocomplete="tel"
                required
              >
            </div>
            <div class="mb-3">
              <label class="form-label" for="shipping-address">Địa chỉ giao hàng</label>
              <textarea
                id="shipping-address"
                v-model="shippingAddress"
                class="form-control"
                rows="3"
                maxlength="500"
                autocomplete="street-address"
                required
              ></textarea>
            </div>

            <fieldset>
              <legend class="h6">Phương thức thanh toán</legend>
              <div class="form-check">
                <input
                  id="payment-cod"
                  v-model="paymentMethod"
                  class="form-check-input"
                  type="radio"
                  name="payment-method"
                  value="cod"
                >
                <label class="form-check-label" for="payment-cod">Thanh toán khi nhận hàng</label>
              </div>
              <div class="form-check">
                <input
                  id="payment-sepay"
                  v-model="paymentMethod"
                  class="form-check-input"
                  type="radio"
                  name="payment-method"
                  value="sepay"
                >
                <label class="form-check-label" for="payment-sepay">Thanh toán online qua SePay (Sandbox)</label>
              </div>
            </fieldset>
          </div>
        </div>
      </div>

      <!-- Tóm tắt giỏ sử dụng giá và tổng tiền do BE trả về. -->
      <div class="col-12 col-lg-5">
        <div class="card">
          <div class="card-body">
            <h2 class="h5 mb-3">Đơn hàng</h2>
            <ul class="list-group list-group-flush mb-3">
              <li v-for="item in cartItems" :key="item.id" class="list-group-item px-0">
                <div class="d-flex justify-content-between gap-3">
                  <span>{{ item.name }} × {{ item.quantity }}</span>
                  <span>{{ formatPrice(Number(item.line_total)) }}</span>
                </div>
              </li>
            </ul>
            <p class="d-flex justify-content-between fw-bold">
              <span>Tổng cộng</span>
              <span>{{ formatPrice(Number(cart?.total_amount || 0)) }}</span>
            </p>
            <button
              class="btn btn-primary w-100"
              type="submit"
              :disabled="submitting || paymentLoading"
            >
              {{ submitting ? 'Đang tạo đơn...' : 'Đặt hàng' }}
            </button>
          </div>
        </div>
      </div>
    </form>

    <p class="mt-3 small text-muted">
      Khi thanh toán online, trình duyệt sẽ chuyển sang SePay. IPN chưa bật nên trang quay về
      chưa xác nhận giao dịch.
    </p>
  </section>
</template>
