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
  <main class="container py-4 py-lg-5 cart-page">
    <header class="d-flex align-items-baseline justify-content-between mb-4">
      <h1 class="h3 mb-0">Giỏ hàng</h1>
      <RouterLink v-if="hasItems" class="btn btn-outline-secondary btn-sm cart-continue" to="/catalog">Tiếp tục mua sắm</RouterLink>
    </header>

    <div v-if="loading" class="text-center py-5 text-secondary" role="status">Đang tải giỏ hàng...</div>

    <div v-else-if="loadError" class="alert alert-danger d-flex align-items-center justify-content-between" role="alert">
      <span>{{ loadError }}</span>
      <button type="button" class="btn btn-sm btn-outline-danger" @click="fetchCart()">Thử lại</button>
    </div>

    <div v-else-if="!hasItems" class="text-center py-5">
      <p class="text-secondary mb-3">Giỏ hàng của bạn đang trống.</p>
      <RouterLink class="btn btn-dark" to="/catalog">Xem sản phẩm</RouterLink>
    </div>

    <div v-else class="row g-4">
      <section class="col-lg-8" aria-label="Danh sách sản phẩm trong giỏ">
        <ul class="list-unstyled mb-0">
          <li v-for="item in items" :key="item.id" class="cart-item d-flex gap-3 py-3 border-bottom">
            <div class="cart-thumb flex-shrink-0">
              <img
                v-if="itemImageSrc(item)"
                :src="itemImageSrc(item)"
                :alt="item.product_name"
                class="w-100 h-100 object-fit-cover rounded"
                @error="markImageFailed(item)"
              />
              <span v-else class="cart-thumb-empty rounded" aria-hidden="true">SB</span>
            </div>

            <div class="flex-grow-1 min-w-0">
              <RouterLink class="fw-semibold text-dark text-decoration-none" :to="`/product-detail/${item.product_slug}`">
                {{ item.product_name }}
              </RouterLink>
              <p class="small text-secondary mb-1">
                {{ item.variant_name }}
                <span v-if="variantAttributesLine(item)"> · {{ variantAttributesLine(item) }}</span>
              </p>
              <p class="small mb-2">
                {{ formatPrice(Number(item.unit_price)) }}
                <span v-if="!item.is_available" class="badge text-bg-secondary ms-2">
                  {{ item.stock <= 0 ? 'Hết hàng' : 'Ngừng bán' }}
                </span>
                <span v-else-if="item.quantity > item.stock" class="badge text-bg-warning ms-2">
                  Chỉ còn {{ item.stock }}
                </span>
              </p>

              <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="input-group input-group-sm quantity-group" role="group" :aria-label="`Số lượng ${item.product_name}`">
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="!canEditQuantity(item) || item.quantity <= 1"
                    aria-label="Giảm số lượng"
                    @click="stepQuantity(item, -1)"
                  >−</button>
                  <input
                    type="number"
                    class="form-control text-center"
                    min="1"
                    :max="Math.max(1, item.stock)"
                    :value="item.quantity"
                    :disabled="!canEditQuantity(item)"
                    aria-label="Nhập số lượng"
                    @change="onQuantityChange(item, $event)"
                  />
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    :disabled="!canEditQuantity(item) || item.quantity >= item.stock"
                    aria-label="Tăng số lượng"
                    @click="stepQuantity(item, 1)"
                  >+</button>
                </div>

                <button
                  type="button"
                  class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1"
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
            </div>

            <div class="text-end flex-shrink-0 fw-semibold">
              {{ formatPrice(Number(item.line_total)) }}
            </div>
          </li>
        </ul>
      </section>

      <aside class="col-lg-4">
        <div class="card border-0 bg-light p-4 cart-summary">
          <h2 class="h5 mb-3">Tóm tắt</h2>
          <div class="d-flex justify-content-between mb-2">
            <span class="text-secondary">Số sản phẩm</span>
            <span>{{ availableCount }}/{{ items.length }} có thể mua</span>
          </div>
          <div class="d-flex justify-content-between fw-bold fs-5 border-top pt-3 mt-2">
            <span>Tạm tính</span>
            <span>{{ formatPrice(Number(totalAmount)) }}</span>
          </div>
          <p class="small text-secondary mt-2 mb-3">Tổng chỉ tính các phiên bản đang bán và còn hàng.</p>

          <RouterLink v-if="availableCount > 0" class="btn btn-dark w-100" to="/checkout">Thanh toán</RouterLink>
          <button v-else type="button" class="btn btn-dark w-100" disabled>Thanh toán</button>
        </div>
      </aside>
    </div>
  </main>
</template>

<style scoped>
.cart-continue {
  padding: 0.45rem 1rem;
  white-space: nowrap;
}

.cart-thumb {
  width: 80px;
  height: 80px;
}

.cart-thumb-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  background: #2a2a2c;
  color: #9a8f80;
  font-weight: 600;
}

.quantity-group {
  width: 130px;
}

.quantity-group .form-control {
  appearance: textfield;
}

.min-w-0 {
  min-width: 0;
}
</style>
