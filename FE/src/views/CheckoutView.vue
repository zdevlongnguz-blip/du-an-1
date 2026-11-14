<script setup>
// Trang đặt hàng: xem giỏ, nhập thông tin nhận hàng, chọn COD hoặc thanh toán online qua SePay.
// Bố cục theo màn "Thông tin giao hàng & đặt hàng" của Stitch, dựng bằng style/breakpoint của dự án (xem trang Giỏ hàng).
// Gọi api-cart (giỏ), api-order-checkout (tạo đơn) và api-order-payment (chuyển sang SePay khi chọn online).
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { refreshCartCount } from '@/helpers/cart/cartCount'
import { formatPrice } from '@/helpers/format/price'
import { startOnlinePayment } from '@/helpers/orders/orderActions'
import { showToast } from '@/helpers/ui/toast'
import OrderPanel from '@/components/orders/OrderPanel.vue'

const router = useRouter()

// Giỏ hàng và trạng thái tải từ API; lỗi được tách riêng với giỏ rỗng.
const cart = ref(null)
const loadingCart = ref(false)
const cartError = ref('')
// Thông tin giao hàng và phương thức khách chọn ('cod' hoặc 'sepay').
const recipientName = ref('')
const recipientPhone = ref('')
const shippingAddress = ref('')
const paymentMethod = ref('cod')
// Lỗi hiển thị ngay dưới từng ô nhập; reactive: object phản ứng, đổi thuộc tính thì giao diện tự cập nhật.
const fieldErrors = reactive({ recipientName: '', recipientPhone: '', shippingAddress: '' })
// Trạng thái gửi đơn, khởi tạo thanh toán, lỗi chung và đơn đã tạo (giữ ID để thử thanh toán lại).
const submitting = ref(false)
const paymentLoading = ref(false)
const checkoutError = ref('')
const createdOrder = ref(null)

// Danh sách item rút từ cart để template không phải kiểm tra null ở nhiều nơi.
const cartItems = computed(() => cart.value?.items || [])
// true nếu giỏ có dòng hết hàng/ngừng bán (BE đặt is_available = false); khi đó khóa nút Đặt hàng
// vì BE sẽ từ chối đơn (409). computed: tự tính lại mỗi khi giỏ hàng thay đổi.
const hasUnavailableItem = computed(() => cartItems.value.some((item) => !item.is_available))

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

// Kiểm tra thông tin nhận hàng ở FE (cùng quy tắc với BE: tên ≤ 100, SĐT 7-20 ký tự số/+()-, địa chỉ ≤ 500).
// Chỉ để người dùng sửa sớm; BE vẫn kiểm tra lại. Trả true nếu hợp lệ.
function validateForm() {
  fieldErrors.recipientName = ''
  fieldErrors.recipientPhone = ''
  fieldErrors.shippingAddress = ''

  const name = recipientName.value.trim()
  const phone = recipientPhone.value.trim()
  const address = shippingAddress.value.trim()
  if (!name) fieldErrors.recipientName = 'Vui lòng nhập tên người nhận.'
  else if (name.length > 100) fieldErrors.recipientName = 'Tên người nhận tối đa 100 ký tự.'
  if (!/^[0-9+()\-\s]{7,20}$/.test(phone)) fieldErrors.recipientPhone = 'Số điện thoại gồm 7-20 ký tự (số, +, -, dấu ngoặc).'
  if (!address) fieldErrors.shippingAddress = 'Vui lòng nhập địa chỉ giao hàng.'
  else if (address.length > 500) fieldErrors.shippingAddress = 'Địa chỉ tối đa 500 ký tự.'

  return !fieldErrors.recipientName && !fieldErrors.recipientPhone && !fieldErrors.shippingAddress
}

// Chuyển sang SePay để thanh toán đơn vừa tạo. Thành công thì trình duyệt rời khỏi trang;
// thất bại thì giữ mã đơn để người dùng thử lại mà không tạo đơn lần nữa.
async function payCreatedOrder() {
  if (!createdOrder.value) return
  paymentLoading.value = true
  checkoutError.value = ''

  try {
    await startOnlinePayment(createdOrder.value.id)
  } catch (error) {
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.'
    showToast(checkoutError.value, 'danger')
    paymentLoading.value = false
  }
}

// Gọi api-order-checkout (POST) với thông tin nhận hàng và phương thức thanh toán.
// COD: xong thì mở trang chi tiết đơn. Online: tiếp tục chuyển sang SePay.
async function submitCheckout() {
  if (cartItems.value.length === 0) {
    checkoutError.value = 'Giỏ hàng đang trống.'
    return
  }
  if (!validateForm()) return

  submitting.value = true
  checkoutError.value = ''
  createdOrder.value = null

  try {
    const response = await api.post('/?action=api-order-checkout', {
      recipient_name: recipientName.value.trim(),
      recipient_phone: recipientPhone.value.trim(),
      shipping_address: shippingAddress.value.trim(),
      payment_method: paymentMethod.value,
    })
    const orderId = response.data?.order?.id
    if (response.data?.status !== 'success' || !orderId) {
      throw new Error('Không nhận được mã đơn hàng hợp lệ.')
    }

    createdOrder.value = { id: orderId, paymentMethod: paymentMethod.value }
    // Giỏ đã được BE làm rỗng: cập nhật số lượng trên thanh điều hướng và giỏ trên trang.
    refreshCartCount()
    await loadCart()

    if (paymentMethod.value === 'sepay') {
      await payCreatedOrder()
      return
    }

    showToast('Đặt hàng thành công. Bạn sẽ thanh toán khi nhận hàng.', 'success')
    router.push({ name: 'order-detail', params: { id: orderId } })
  } catch (error) {
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể tạo đơn hàng.'
    showToast(checkoutError.value, 'danger')
  } finally {
    submitting.value = false
  }
}

// Tải giỏ một lần khi người dùng mở trang đặt hàng.
onMounted(loadCart)
</script>

<template>
  <section class="checkout-page">
    <!-- Đường dẫn phụ; ẩn trên điện thoại giống trang chi tiết sản phẩm. -->
    <nav class="checkout-breadcrumb" aria-label="Đường dẫn">
      <RouterLink to="/">Trang chủ</RouterLink>
      <span aria-hidden="true">/</span>
      <RouterLink to="/cart">Giỏ hàng</RouterLink>
      <span aria-hidden="true">/</span>
      <span class="is-current" aria-current="page">Thông tin giao hàng</span>
    </nav>

    <header class="checkout-header">
      <p class="checkout-eyebrow">Nghi thức chuẩn mực quý ông</p>
      <h1 class="checkout-title">Thông tin giao hàng &amp; đặt hàng</h1>
      <p class="checkout-subtitle">Vui lòng cung cấp thông tin chính xác để cửa hàng chuẩn bị và giao hàng đến tận tay bạn.</p>
    </header>

    <!-- Trạng thái tải giỏ hàng. -->
    <div v-if="loadingCart" class="text-center py-5 text-secondary" role="status">Đang tải giỏ hàng...</div>

    <!-- Lỗi API có nút thử lại, không bị nhầm với giỏ trống. -->
    <div v-else-if="cartError" class="alert alert-danger d-flex align-items-center justify-content-between gap-3" role="alert">
      <span>{{ cartError }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" @click="loadCart">Thử lại</button>
    </div>

    <!-- Giỏ trống có liên kết quay lại danh sách sản phẩm. -->
    <div v-else-if="cartItems.length === 0 && !createdOrder" class="text-center py-5">
      <p class="text-secondary mb-3">Giỏ hàng của bạn đang trống.</p>
      <RouterLink class="btn btn-primary" to="/catalog">Xem sản phẩm</RouterLink>
    </div>

    <!-- Lỗi chung. Với đơn online đã tạo nhưng chưa chuyển được sang SePay: cho thử lại, hoặc để thanh toán sau. -->
    <div v-if="checkoutError" class="alert alert-danger" role="alert">
      <p class="mb-2">{{ checkoutError }}</p>
      <div v-if="createdOrder?.paymentMethod === 'sepay'" class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-outline-danger" :disabled="paymentLoading" @click="payCreatedOrder">
          {{ paymentLoading ? 'Đang kết nối...' : 'Thử thanh toán lại' }}
        </button>
        <RouterLink class="btn btn-sm btn-outline-secondary" :to="`/orders/${createdOrder.id}`">
          Xem đơn hàng (thanh toán sau)
        </RouterLink>
      </div>
    </div>

    <!-- Form đặt hàng chỉ hiện khi còn item trong giỏ và chưa tạo đơn thành công. -->
    <form v-if="cartItems.length > 0 && !createdOrder" class="row g-4" novalidate @submit.prevent="submitCheckout">
      <!-- Cột trái: thông tin nhận hàng và phương thức thanh toán. -->
      <div class="col-lg-7">
        <OrderPanel>
          <section class="checkout-section" aria-labelledby="checkout-recipient">
            <h2 id="checkout-recipient" class="checkout-heading"><span class="checkout-step">1</span>Người tiếp nhận kiện hàng</h2>
            <div class="mb-3">
              <label class="checkout-label" for="recipient-name">Họ và tên người nhận</label>
              <input
                id="recipient-name"
                v-model="recipientName"
                class="form-control"
                :class="{ 'is-invalid': fieldErrors.recipientName }"
                type="text"
                maxlength="100"
                autocomplete="name"
                placeholder="Ví dụ: Nguyễn Hoàng Nam"
                @input="fieldErrors.recipientName = ''"
              >
              <div v-if="fieldErrors.recipientName" class="invalid-feedback">{{ fieldErrors.recipientName }}</div>
            </div>
            <div>
              <label class="checkout-label" for="recipient-phone">Số điện thoại liên hệ</label>
              <input
                id="recipient-phone"
                v-model="recipientPhone"
                class="form-control"
                :class="{ 'is-invalid': fieldErrors.recipientPhone }"
                type="tel"
                maxlength="20"
                autocomplete="tel"
                placeholder="Ví dụ: 0912 345 678"
                @input="fieldErrors.recipientPhone = ''"
              >
              <div v-if="fieldErrors.recipientPhone" class="invalid-feedback">{{ fieldErrors.recipientPhone }}</div>
            </div>
          </section>

          <section class="checkout-section" aria-labelledby="checkout-address">
            <h2 id="checkout-address" class="checkout-heading"><span class="checkout-step">2</span>Địa chỉ nhận hàng</h2>
            <label class="checkout-label" for="shipping-address">Địa chỉ chi tiết (số nhà, đường, phường/xã, quận/huyện, tỉnh/thành)</label>
            <textarea
              id="shipping-address"
              v-model="shippingAddress"
              class="form-control"
              :class="{ 'is-invalid': fieldErrors.shippingAddress }"
              rows="3"
              maxlength="500"
              autocomplete="street-address"
              placeholder="Ví dụ: 128 Phố Cổ, P. Hàng Gai, Q. Hoàn Kiếm, Hà Nội"
              @input="fieldErrors.shippingAddress = ''"
            ></textarea>
            <div v-if="fieldErrors.shippingAddress" class="invalid-feedback">{{ fieldErrors.shippingAddress }}</div>
          </section>

          <!-- Phương thức thanh toán dạng thẻ chọn; radio ẩn nhưng vẫn dùng được bằng bàn phím. -->
          <fieldset class="checkout-section checkout-section--last">
            <legend class="checkout-heading"><span class="checkout-step">3</span>Phương thức thanh toán</legend>
            <label class="pay-option" :class="{ 'is-selected': paymentMethod === 'cod' }" for="payment-cod">
              <input id="payment-cod" v-model="paymentMethod" class="pay-option__input" type="radio" name="payment-method" value="cod">
              <span class="pay-option__check" aria-hidden="true"></span>
              <span class="pay-option__body">
                <strong>Thanh toán khi nhận hàng (COD)</strong>
                <small>Kiểm tra hàng rồi thanh toán tiền mặt cho nhân viên giao hàng.</small>
              </span>
            </label>
            <label class="pay-option" :class="{ 'is-selected': paymentMethod === 'sepay' }" for="payment-sepay">
              <input id="payment-sepay" v-model="paymentMethod" class="pay-option__input" type="radio" name="payment-method" value="sepay">
              <span class="pay-option__check" aria-hidden="true"></span>
              <span class="pay-option__body">
                <strong>Thanh toán online qua SePay</strong>
                <small>Bạn sẽ được chuyển sang cổng SePay. Đơn chưa thanh toán sau 30 phút sẽ tự hủy và hoàn lại hàng.</small>
              </span>
            </label>
          </fieldset>

          <RouterLink class="checkout-back" to="/cart">&larr; Quay lại giỏ hàng</RouterLink>
        </OrderPanel>
      </div>

      <!-- Cột phải: tóm tắt giỏ dùng giá và tổng tiền do BE trả về, kèm nút đặt hàng. -->
      <aside class="col-lg-5">
        <OrderPanel class="checkout-summary">
          <h2 class="checkout-summary__title">Tóm tắt đơn hàng ({{ cartItems.length }})</h2>
          <ul class="list-unstyled mb-0">
            <li v-for="item in cartItems" :key="item.id" class="checkout-item">
              <div class="checkout-item__main">
                <!-- BE trả product_name và variant_name (tên biến thể, ví dụ "Chai 140ml"). -->
                <p class="checkout-item__name">{{ item.product_name }}</p>
                <p class="checkout-item__variant">
                  <span v-if="item.variant_name">{{ item.variant_name }} · </span>SL: {{ item.quantity }}
                </p>
                <!-- Dòng hết hàng/ngừng bán không được tính vào tổng và sẽ làm đặt hàng bị từ chối. -->
                <p v-if="!item.is_available" class="checkout-item__warn">
                  Mặt hàng này đã hết hoặc ngừng bán, vui lòng xóa khỏi giỏ hàng.
                </p>
              </div>
              <p class="checkout-item__total">{{ formatPrice(Number(item.line_total)) }}</p>
            </li>
          </ul>

          <p class="checkout-total">
            <span>Tổng thanh toán</span>
            <strong>{{ formatPrice(Number(cart?.total_amount || 0)) }}</strong>
          </p>

          <button class="btn btn-primary w-100 checkout-submit" type="submit" :disabled="submitting || paymentLoading || hasUnavailableItem">
            {{ submitting ? 'Đang tạo đơn...' : paymentMethod === 'sepay' ? 'Đặt hàng và thanh toán' : 'Đặt hàng' }}
          </button>
        </OrderPanel>
      </aside>
    </form>
  </section>
</template>

<style scoped>
.checkout-breadcrumb {
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

.checkout-breadcrumb a:hover {
  color: #8c6a32;
}

.checkout-breadcrumb .is-current {
  color: #8c6a32;
}

.checkout-header {
  margin-bottom: 24px;
}

.checkout-eyebrow {
  margin: 0 0 4px;
  color: #8c6a32;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.checkout-title {
  margin: 0;
  color: var(--color-ink);
  font-size: 32px;
  font-weight: 700;
  line-height: 1.2;
}

.checkout-subtitle {
  margin: 6px 0 0;
  color: #4a5565;
  font-size: 14px;
}

/* Mỗi phần của form (1, 2, 3) cách nhau bằng đường kẻ mảnh. */
.checkout-section {
  min-width: 0;
  margin: 0 0 24px;
  padding: 0 0 24px;
  border: 0;
  border-bottom: 1px solid #d9d4ca;
}

.checkout-section--last {
  border-bottom: 0;
  margin-bottom: 8px;
  padding-bottom: 0;
}

.checkout-heading {
  display: flex;
  align-items: center;
  gap: 10px;
  width: auto;
  margin: 0 0 16px;
  color: var(--color-ink);
  font-size: 22px;
  font-weight: 700;
}

/* Ô số thứ tự bước, màu vàng đồng như thanh bước của Stitch. */
.checkout-step {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 4px;
  background: #b8893e;
  color: #ffffff;
  font-family: inherit;
  font-size: 15px;
  font-weight: 700;
}

.checkout-label {
  display: block;
  margin-bottom: 6px;
  color: #4a5565;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

/* Thẻ chọn phương thức thanh toán */
.pay-option {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 10px;
  padding: 14px 16px;
  border: 1px solid #d9d4ca;
  border-radius: 4px;
  background: #ffffff;
  cursor: pointer;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.pay-option:hover {
  border-color: #cfc8b8;
}

.pay-option.is-selected {
  border-color: #b8893e;
  background: #fff7e6;
}

/* Radio thật được ẩn bằng cách làm trong suốt (vẫn nhận bàn phím); vòng tròn giả hiển thị trạng thái. */
.pay-option__input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}

.pay-option__check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  margin-top: 2px;
  border: 2px solid #cfc8b8;
  border-radius: 50%;
}

.pay-option.is-selected .pay-option__check {
  border-color: #b8893e;
}

.pay-option.is-selected .pay-option__check::after {
  content: '';
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #b8893e;
}

.pay-option__input:focus-visible + .pay-option__check {
  outline: 2px solid #b8893e;
  outline-offset: 2px;
}

.pay-option__body {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.pay-option__body strong {
  color: var(--color-ink);
  font-weight: 600;
}

.pay-option__body small {
  color: #4a5565;
  font-size: 13px;
  line-height: 1.5;
}

.checkout-back {
  display: inline-block;
  margin-top: 16px;
  color: #4a5565;
  font-size: 12px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.checkout-back:hover {
  color: #8c6a32;
}

/* Cột tóm tắt: dính theo màn hình khi cuộn trên màn hình lớn (dưới thanh điều hướng cố định). */
@media (min-width: 992px) {
  .checkout-summary {
    position: sticky;
    top: 96px;
  }
}

.checkout-summary__title {
  margin: 0 0 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #d9d4ca;
  color: var(--color-ink);
  font-size: 22px;
  font-weight: 700;
}

.checkout-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid #d9d4ca;
}

.checkout-item__main {
  min-width: 0;
}

.checkout-item__name {
  margin: 0;
  color: var(--color-ink);
  font-weight: 600;
  overflow-wrap: anywhere;
}

.checkout-item__variant {
  margin: 2px 0 0;
  color: #4a5565;
  font-size: 13px;
}

.checkout-item__warn {
  margin: 4px 0 0;
  color: #93000a;
  font-size: 13px;
}

.checkout-item__total {
  flex-shrink: 0;
  margin: 0;
  color: var(--color-ink);
  font-weight: 600;
}

.checkout-total {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin: 16px 0;
}

.checkout-total span {
  color: #4a5565;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.checkout-total strong {
  color: #8c6a32;
  font-size: 24px;
  font-weight: 700;
}

@media (max-width: 575.98px) {
  .checkout-breadcrumb {
    display: none;
  }

  .checkout-title {
    font-size: 26px;
  }
}

/* Nút đặt hàng cao hơn, chữ in hoa giãn như CTA chính của Stitch. */
.checkout-submit {
  min-height: 48px;
  margin-top: 8px;
  border-radius: 6px;
  font-size: 13px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  box-shadow: 0 2px 8px rgba(184, 137, 62, 0.25);
}
</style>
