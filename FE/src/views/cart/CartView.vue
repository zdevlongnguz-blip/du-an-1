<script setup>
// Trang giỏ hàng của user đã đăng nhập: xem, đổi số lượng và xóa từng phiên bản sản phẩm.
// Giá, tồn kho và tổng tiền do BE tính; FE chỉ hiển thị và gửi yêu cầu.
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/helpers/api/client'
import { useToast } from '@/helpers/ui/toast'
import { formatPrice } from '@/helpers/format/price'
import { setCartCountFromItems } from '@/helpers/cart/cartCount'
import { resolveImageUrl } from '@/helpers/media/imageUrl'

const { showToast } = useToast()

// Danh sách dòng hàng và tổng tiền tạm tính lấy từ api-cart.
const items = ref([])
const totalAmount = ref('0.00')
const loading = ref(true)
const loadError = ref('')
// ID dòng đang gửi cập nhật hoặc xóa; null khi không có yêu cầu nào đang chạy.
const busyItemId = ref(null)
// Các dòng có ảnh lỗi (file không tồn tại); những dòng này chuyển sang ảnh mặc định.
const failedImageIds = ref([])

const hasItems = computed(() => items.value.length > 0)
const availableCount = computed(() => items.value.filter((item) => item.is_available).length)

// Ảnh sản phẩm của dòng; null hoặc file lỗi thì trả về chuỗi rỗng để hiện ô "SB".
function itemImageSrc(item) {
  if (failedImageIds.value.includes(item.id)) return ''
  return resolveImageUrl(item.image)
}

function markImageFailed(item) {
  if (!failedImageIds.value.includes(item.id)) failedImageIds.value = [...failedImageIds.value, item.id]
}

// Áp dụng dữ liệu giỏ do BE trả về để giao diện luôn khớp với DB.
function applyCart(cart) {
  items.value = cart?.items ?? []
  totalAmount.value = cart?.total_amount ?? '0.00'
  setCartCountFromItems(items.value)
}

// silent: làm mới giỏ sau lỗi tồn kho mà không bật trạng thái loading toàn trang.
async function fetchCart({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
    loadError.value = ''
  }

  try {
    const response = await api.get('/?action=api-cart')
    applyCart(response.data.cart)
  } catch (error) {
    if (!silent) loadError.value = error.response?.data?.message || 'Không tải được giỏ hàng.'
  } finally {
    if (!silent) loading.value = false
  }
}

// Phiên bản ngừng bán hoặc hết hàng thì không cho đổi số lượng.
function canEditQuantity(item) {
  return item.is_available && busyItemId.value === null
}

// Kẹp số lượng về khoảng 1..tồn kho của đúng phiên bản đó.
function clampQuantity(item, rawValue) {
  const parsed = Math.floor(Number(rawValue))
  if (!Number.isFinite(parsed)) return item.quantity
  return Math.min(Math.max(1, parsed), Math.max(1, item.stock))
}

// Gửi số lượng mới lên BE; trả về true nếu thành công để ô nhập biết có giữ giá trị hay không.
async function updateQuantity(item, quantity) {
  if (quantity === item.quantity) return true

  busyItemId.value = item.id
  try {
    const response = await api.put(`/?action=api-cart-update&id=${encodeURIComponent(item.id)}`, { quantity })
    applyCart(response.data.cart)
    return true
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể cập nhật số lượng.', 'danger')
    // Lỗi tồn kho hoặc dòng đã bị xóa: tải lại giỏ để số liệu không bị cũ.
    if ([404, 409].includes(error.response?.status)) await fetchCart({ silent: true })
    return false
  } finally {
    busyItemId.value = null
  }
}

// Ô nhập tay: kẹp giá trị trước khi gửi; nếu BE từ chối thì trả ô về số lượng hiện tại.
async function onQuantityChange(item, event) {
  const input = event.target
  const next = clampQuantity(item, input.value)
  input.value = next

  const ok = await updateQuantity(item, next)
  if (!ok) input.value = item.quantity
}

async function stepQuantity(item, delta) {
  const next = clampQuantity(item, item.quantity + delta)
  if (next === item.quantity) return
  await updateQuantity(item, next)
}

async function removeItem(item) {
  if (busyItemId.value !== null) return
  if (!window.confirm('Bạn có chắc muốn xóa phiên bản này khỏi giỏ hàng không?')) return

  busyItemId.value = item.id
  try {
    const response = await api.delete(`/?action=api-cart-delete&id=${encodeURIComponent(item.id)}`)
    applyCart(response.data.cart)
    showToast(response.data?.message || 'Đã xóa sản phẩm khỏi giỏ hàng.', 'success')
  } catch (error) {
    showToast(error.response?.data?.message || 'Không thể xóa sản phẩm.', 'danger')
    if (error.response?.status === 404) await fetchCart({ silent: true })
  } finally {
    busyItemId.value = null
  }
}

// Ghép thuộc tính thành chuỗi, ví dụ {"dung_tich":"113g"} -> "113g".
function formatAttributes(attributes) {
  return Object.values(attributes || {}).join(' · ')
}

// Ẩn dòng thuộc tính nếu tên phiên bản đã chứa nội dung đó, tránh lặp "Hũ 56g · 56g" (cùng quy tắc với ProductDetail).
function variantAttributesLine(item) {
  const attrs = formatAttributes(item.attributes)
  if (!attrs || item.variant_name?.toLowerCase().includes(attrs.toLowerCase())) return ''
  return attrs
}

onMounted(() => fetchCart())
</script>

<template>
  <section class="cart-page" aria-labelledby="cart-title">
    <!-- Đầu trang: tiêu đề serif + nút tiếp tục mua sắm theo Stitch. -->
    <header class="cart-header">
      <div class="cart-header-row">
        <h1 id="cart-title">Giỏ hàng</h1>
        <RouterLink v-if="hasItems" class="cart-btn cart-btn--outline" to="/catalog">Tiếp tục mua sắm</RouterLink>
      </div>
    </header>

    <!-- Đang tải giỏ hàng. -->
    <div v-if="loading" class="cart-state" role="status">Đang tải giỏ hàng...</div>

    <!-- Lỗi tải giỏ: hiện thông báo thật và nút thử lại. -->
    <div v-else-if="loadError" class="cart-state cart-state--error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" class="cart-btn cart-btn--outline" @click="fetchCart()">Thử lại</button>
    </div>

    <!-- Giỏ trống: thẻ giữa trang có nút xem sản phẩm. -->
    <div v-else-if="!hasItems" class="cart-empty">
      <p class="cart-empty-text">Giỏ hàng của bạn đang trống.</p>
      <RouterLink class="cart-btn cart-btn--primary" to="/catalog">Xem sản phẩm</RouterLink>
    </div>

    <!-- Có hàng: danh sách bên trái (8 cột), tóm tắt bên phải (4 cột). -->
    <div v-else class="cart-layout">
      <section class="cart-list" aria-label="Danh sách sản phẩm trong giỏ">
        <!-- Hàng tiêu đề cột, chỉ hiện trên màn hình rộng. -->
        <div class="cart-list-head" aria-hidden="true">
          <span>Sản phẩm</span>
          <span>Số lượng</span>
          <span>Thành tiền</span>
        </div>

        <ul class="cart-items">
          <li v-for="item in items" :key="item.id" class="cart-item">
            <div class="cart-thumb">
              <img
                v-if="itemImageSrc(item)"
                :src="itemImageSrc(item)"
                :alt="item.product_name"
                @error="markImageFailed(item)"
              />
              <span v-else class="cart-thumb-empty" aria-hidden="true">SB</span>
            </div>

            <!-- Tên, phiên bản, đơn giá và cảnh báo tồn kho. -->
            <div class="cart-item-info">
              <span class="cart-item-variant">
                {{ item.variant_name }}
                <span v-if="variantAttributesLine(item)"> · {{ variantAttributesLine(item) }}</span>
              </span>
              <RouterLink class="cart-item-name" :to="`/product-detail/${item.product_slug}`">
                {{ item.product_name }}
              </RouterLink>
              <p class="cart-item-price">
                <strong>{{ formatPrice(Number(item.unit_price)) }}</strong>
                <span v-if="!item.is_available" class="cart-tag cart-tag--muted">
                  {{ item.stock <= 0 ? 'Hết hàng' : 'Ngừng bán' }}
                </span>
                <span v-else-if="item.quantity > item.stock" class="cart-tag cart-tag--warn">
                  Chỉ còn {{ item.stock }}
                </span>
              </p>
            </div>

            <!-- Bộ tăng giảm số lượng + nút xóa. -->
            <div class="cart-item-qty">
              <div class="cart-stepper" role="group" :aria-label="`Số lượng ${item.product_name}`">
                <button
                  type="button"
                  :disabled="!canEditQuantity(item) || item.quantity <= 1"
                  aria-label="Giảm số lượng"
                  @click="stepQuantity(item, -1)"
                >−</button>
                <input
                  type="number"
                  min="1"
                  :max="Math.max(1, item.stock)"
                  :value="item.quantity"
                  :disabled="!canEditQuantity(item)"
                  aria-label="Nhập số lượng"
                  @change="onQuantityChange(item, $event)"
                />
                <button
                  type="button"
                  :disabled="!canEditQuantity(item) || item.quantity >= item.stock"
                  aria-label="Tăng số lượng"
                  @click="stepQuantity(item, 1)"
                >+</button>
              </div>

              <button
                type="button"
                class="cart-remove"
                :disabled="busyItemId !== null"
                :aria-label="`Xóa ${item.product_name} khỏi giỏ hàng`"
                @click="removeItem(item)"
              >
                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                  <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z" />
                  <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z" />
                </svg>
                Xóa
              </button>
            </div>

            <div class="cart-item-total">
              {{ formatPrice(Number(item.line_total)) }}
            </div>
          </li>
        </ul>
      </section>

      <!-- Tóm tắt đơn hàng: dính khi cuộn trên desktop. -->
      <aside class="cart-summary">
        <div class="cart-summary-head">
          <h2>Tóm tắt</h2>
        </div>
        <div class="cart-summary-row">
          <span>Số sản phẩm</span>
          <span>{{ availableCount }}/{{ items.length }} có thể mua</span>
        </div>
        <div class="cart-summary-total">
          <span>Tạm tính</span>
          <strong>{{ formatPrice(Number(totalAmount)) }}</strong>
        </div>
        <p class="cart-summary-note">Tổng chỉ tính các phiên bản đang bán và còn hàng.</p>

        <RouterLink v-if="availableCount > 0" class="cart-btn cart-btn--primary cart-btn--block" to="/checkout">
          Thanh toán
        </RouterLink>
        <button v-else type="button" class="cart-btn cart-btn--primary cart-btn--block" disabled>Thanh toán</button>
      </aside>
    </div>
  </section>
</template>

<style scoped>
/* Trang giỏ hàng theo Stitch: thẻ trắng viền kem trên nền kem, giá màu đồng, nút đồng/viền navy. */
.cart-page {
  display: grid;
  gap: 24px;
}

.cart-header {
  display: grid;
  gap: 10px;
  padding-bottom: 20px;
  border-bottom: 1px solid #e6e0d4;
}

.cart-header-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
}

.cart-header h1 {
  margin: 0;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: clamp(26px, 3.6vw, 36px);
  font-weight: 600;
  text-transform: uppercase;
}

/* Nút dùng chung trong trang: primary đồng, outline viền navy. */
.cart-btn {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  padding: 0 18px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  transition: background-color 140ms ease, color 140ms ease;
}

.cart-btn--primary {
  border: 1px solid #b8893e;
  background: #b8893e;
  color: #ffffff;
  box-shadow: 0 2px 8px rgba(184, 137, 62, 0.25);
}

.cart-btn--primary:hover:not(:disabled) {
  border-color: #9e7432;
  background: #9e7432;
  color: #ffffff;
}

.cart-btn--primary:disabled {
  border-color: #e6e0d4;
  background: #e6e0d4;
  color: #8a8f98;
  box-shadow: none;
  cursor: not-allowed;
}

.cart-btn--outline {
  border: 1px solid #1c2a3a;
  background: transparent;
  color: #1c2a3a;
}

.cart-btn--outline:hover {
  background: #1c2a3a;
  color: #ffffff;
}

.cart-btn--block {
  width: 100%;
  min-height: 48px;
}

.cart-btn:focus-visible,
.cart-remove:focus-visible,
.cart-stepper button:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.cart-state {
  padding: 48px 24px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  color: #6b6560;
  text-align: center;
}

.cart-state p {
  margin: 0 0 14px;
}

.cart-state--error {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}

.cart-empty {
  display: grid;
  max-width: 640px;
  justify-items: center;
  gap: 10px;
  margin: 0 auto;
  padding: 48px 28px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 4px 16px rgba(28, 42, 58, 0.06);
  text-align: center;
}

.cart-empty h2 {
  margin: 0;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: 26px;
  font-weight: 600;
  text-transform: uppercase;
}

.cart-empty-text {
  max-width: 420px;
  margin: 0 0 8px;
  color: #6b6560;
}

.cart-layout {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  align-items: start;
  gap: 24px;
}

.cart-list {
  display: grid;
  gap: 12px;
}

.cart-list-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 190px 120px;
  gap: 12px;
  padding: 8px 16px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #efeae0;
  color: #6b6560;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.cart-list-head span:nth-child(2) {
  text-align: center;
}

.cart-list-head span:last-child {
  text-align: right;
}

.cart-items {
  display: grid;
  gap: 12px;
  margin: 0;
  padding: 0;
  list-style: none;
}

/* Mỗi dòng giỏ hàng là một thẻ trắng: ảnh | thông tin | số lượng | thành tiền. */
.cart-item {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr) 190px 120px;
  align-items: center;
  gap: 12px 16px;
  padding: 14px 16px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 2px 8px rgba(28, 42, 58, 0.06);
  transition: border-color 140ms ease;
}

.cart-item:hover {
  border-color: #b8893e;
}

.cart-thumb {
  width: 88px;
  height: 100px;
  overflow: hidden;
  padding: 4px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #f7f4ee;
}

.cart-thumb img {
  width: 100%;
  height: 100%;
  border-radius: 6px;
  object-fit: cover;
}

.cart-thumb-empty {
  display: grid;
  width: 100%;
  height: 100%;
  place-items: center;
  color: #b8893e;
  font-family: var(--font-display);
  font-size: 20px;
}

.cart-item-info {
  display: grid;
  min-width: 0;
  gap: 4px;
}

.cart-item-variant {
  color: #b8893e;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.cart-item-name {
  overflow: hidden;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: 18px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cart-item-name:hover {
  color: #8c6a32;
}

.cart-item-price {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin: 0;
  color: #6b6560;
  font-size: 13px;
}

.cart-item-price strong {
  color: #b8893e;
}

.cart-tag {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.cart-tag--muted {
  background: #efeae0;
  color: #6b6560;
}

.cart-tag--warn {
  background: #fef3d6;
  color: #8a5a0a;
}

.cart-item-qty {
  display: grid;
  justify-items: center;
  gap: 8px;
}

/* Bộ tăng giảm: khung kem, nút vuông nhỏ. */
.cart-stepper {
  display: inline-flex;
  align-items: center;
  padding: 2px;
  border: 1px solid #e6e0d4;
  border-radius: 6px;
  background: #f7f4ee;
}

.cart-stepper button {
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: 4px;
  background: transparent;
  color: #1f1f1f;
  cursor: pointer;
  font-size: 16px;
}

.cart-stepper button:hover:not(:disabled) {
  background: #efeae0;
}

.cart-stepper button:disabled {
  color: #b5b0a6;
  cursor: not-allowed;
}

.cart-stepper input {
  width: 44px;
  border: 0;
  background: transparent;
  color: #1f1f1f;
  font-weight: 600;
  text-align: center;
  appearance: textfield;
}

.cart-stepper input::-webkit-outer-spin-button,
.cart-stepper input::-webkit-inner-spin-button {
  margin: 0;
  appearance: none;
}

/* Nút Xóa: khung viền mỏng như nút phụ, đủ vùng bấm; hover đổi sang đỏ nhạt để báo hành động xóa. */
.cart-remove {
  display: inline-flex;
  min-height: 32px;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid #e6e0d4;
  border-radius: 6px;
  background: #ffffff;
  color: #4a5565;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  transition: border-color 140ms ease, color 140ms ease, background-color 140ms ease;
}

.cart-remove:hover:not(:disabled) {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}

.cart-remove:disabled {
  opacity: 0.5;
  cursor: wait;
}

.cart-item-total {
  color: #b8893e;
  font-size: 16px;
  font-weight: 700;
  text-align: right;
}

.cart-summary {
  position: sticky;
  top: 96px;
  display: grid;
  gap: 14px;
  padding: 22px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 4px 16px rgba(28, 42, 58, 0.06);
}

.cart-summary-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-bottom: 12px;
  border-bottom: 1px solid #e6e0d4;
}

.cart-summary-head h2 {
  margin: 0;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: 20px;
  font-weight: 600;
  text-transform: uppercase;
}

.cart-summary-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  color: #6b6560;
  font-size: 14px;
}

.cart-summary-row span:last-child {
  color: #1f1f1f;
  font-weight: 600;
}

.cart-summary-total {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding-top: 12px;
  border-top: 1px solid #e6e0d4;
  color: #6b6560;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.cart-summary-total strong {
  color: #b8893e;
  font-family: var(--font-display);
  font-size: 28px;
  letter-spacing: 0;
}

.cart-summary-note {
  margin: -6px 0 0;
  color: #6b6560;
  font-size: 12px;
  text-align: right;
}

@media (max-width: 991.98px) {
  .cart-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .cart-summary {
    position: static;
  }
}

/* Mobile: thẻ giỏ hàng xếp lại thành 2 hàng, ẩn hàng tiêu đề cột. */
@media (max-width: 767.98px) {
  .cart-list-head {
    display: none;
  }

  .cart-item {
    grid-template-columns: 72px minmax(0, 1fr);
  }

  .cart-thumb {
    width: 72px;
    height: 84px;
  }

  .cart-item-qty {
    grid-column: 1 / -1;
    display: flex;
    justify-content: space-between;
  }

  .cart-item-total {
    grid-column: 1 / -1;
    padding-top: 8px;
    border-top: 1px solid #e6e0d4;
  }
}
</style>
