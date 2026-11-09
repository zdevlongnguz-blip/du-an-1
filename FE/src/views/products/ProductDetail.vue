<script setup>
// Trang chi tiết sản phẩm: khi mở trang gọi api-product-detail và api-product-comments theo slug.
// Nút bình luận chỉ ẩn hoặc hiện khu vực, không quyết định thời điểm tải dữ liệu.
// Khu vực mua hàng và form bình luận hiện là giao diện demo, chưa nối xử lý.
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import api from '@/services/api'
import { isAuthenticated } from '@/services/auth'
import { formatPrice } from '@/helpers/formatPrice'
import { resolveImageUrl } from '@/helpers/imageUrl'

// Số bình luận mỗi trang, khớp với query per_page đã thống nhất với BE.
const COMMENTS_PER_PAGE = 10

const route = useRoute()

// ref: biến "phản ứng", khi giá trị đổi thì giao diện tự cập nhật.
// Dữ liệu sản phẩm từ api-product-detail; null khi đang tải hoặc bị lỗi.
const product = ref(null)
// Bắt đầu ở trạng thái tải để không nháy giao diện rỗng trước request đầu tiên.
const productLoading = ref(true)
// Thông báo lỗi khi không tải được sản phẩm (slug sai, server lỗi...).
const productError = ref('')

// Vị trí ảnh đang hiển thị lớn trong album.
const activeImageIndex = ref(0)
// Vị trí các ảnh tải lỗi, để thay bằng khung "SB" thay vì hiện ảnh hỏng.
const failedImages = ref(new Set())

// Danh sách bình luận. null = chưa từng tải, khác với [] = đã tải nhưng không có bình luận.
const comments = ref(null)
// Thông tin phân trang bình luận do BE trả về (trang hiện tại, tổng số...).
const commentsPagination = ref(null)
const commentsLoading = ref(false)
const commentsError = ref('')
// Khu vực bình luận đang mở hay thu gọn. Ẩn/hiện lại không gọi API lần nữa.
const commentsVisible = ref(false)

// Chỉ dùng để chọn giao diện cho khách hay thành viên; quyền thật do BE kiểm soát.
const loggedIn = isAuthenticated()

// computed: giá trị tự tính lại mỗi khi dữ liệu nó dùng thay đổi.
// Album ảnh: lấy từ product.images; nếu rỗng thì dùng ảnh đại diện product.image.
const galleryImages = computed(() => {
  if (!product.value) return []

  const images = Array.isArray(product.value.images)
    ? product.value.images.map((item) => item.image_path).filter(Boolean)
    : []

  if (images.length === 0 && product.value.image) {
    images.push(product.value.image)
  }

  return images
})

const activeImage = computed(() => galleryImages.value[activeImageIndex.value] ?? '')

// Có giá giảm khi sale_price hợp lệ và nhỏ hơn giá gốc.
const hasSale = computed(() => {
  const salePrice = Number(product.value?.sale_price)
  return product.value?.sale_price != null && salePrice > 0 && salePrice < Number(product.value.price)
})

// Chỉ coi là hết hàng khi API trả stock là số và nhỏ hơn hoặc bằng 0.
const hasStock = computed(() => {
  const stock = product.value?.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock))
})
const isOutOfStock = computed(() => hasStock.value && Number(product.value.stock) <= 0)

// Tổng số bình luận chỉ biết sau khi đã tải; trước đó không hiện số để tránh hiện sai.
const commentCount = computed(() => commentsPagination.value?.total_items ?? null)

// Gọi khi thẻ <img> báo lỗi tải ảnh, để chuyển sang khung thay thế.
function markImageFailed(index) {
  failedImages.value = new Set(failedImages.value).add(index)
}

// Hiện số theo kiểu Việt Nam, ví dụ 4412 -> "4.412".
function formatCount(value) {
  return Number(value ?? 0).toLocaleString('vi-VN')
}

// API bình luận chưa có tên hiển thị, chỉ có email; chỉ hiện phần trước @ để không lộ email.
function commentAuthor(comment) {
  return String(comment.email || '').split('@')[0] || 'Khách hàng'
}

function commentInitials(comment) {
  return commentAuthor(comment).slice(0, 2).toUpperCase()
}

// Đổi "2026-11-09 12:32:18" từ BE sang ngày giờ dạng Việt Nam.
function formatCommentDate(value) {
  if (!value) return ''
  const date = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString('vi-VN', { dateStyle: 'short', timeStyle: 'short' })
}

// Gọi api-product-detail để lấy sản phẩm theo slug. Chạy khi mở trang và khi slug trên URL đổi.
// Thành công: res.data.product (kèm mảng images). Lỗi: BE trả message, ví dụ 404 "Không tìm thấy sản phẩm".
async function fetchProduct(slug) {
  productLoading.value = true
  productError.value = ''
  product.value = null

  try {
    const res = await api.get('/?action=api-product-detail', { params: { slug } })
    // Nếu người dùng đã chuyển sang sản phẩm khác trong lúc chờ thì bỏ kết quả cũ.
    if (slug !== route.params.slug) return
    product.value = res.data.product
  } catch (err) {
    if (slug !== route.params.slug) return
    productError.value = err.response?.data?.message || 'Không tải được thông tin sản phẩm.'
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên tắt loading ở đây.
    if (slug === route.params.slug) productLoading.value = false
  }
}

// Gọi api-product-comments cho slug hiện tại khi mở trang, thử lại hoặc đổi trang bình luận.
// Thành công: res.data.comments.data (danh sách) và res.data.comments.pagination.
async function fetchComments(page = 1) {
  const slug = route.params.slug
  commentsLoading.value = true
  commentsError.value = ''

  try {
    const res = await api.get('/?action=api-product-comments', {
      params: { slug, page, per_page: COMMENTS_PER_PAGE },
    })
    if (slug !== route.params.slug) return
    comments.value = res.data.comments.data
    commentsPagination.value = res.data.comments.pagination
  } catch (err) {
    if (slug !== route.params.slug) return
    // Hiện lỗi thật thay vì danh sách rỗng, để không nhầm với "chưa có bình luận".
    commentsError.value = err.response?.data?.message || 'Không tải được bình luận.'
  } finally {
    if (slug === route.params.slug) commentsLoading.value = false
  }
}

// Nút này chỉ mở hoặc thu gọn khu vực; tải dữ liệu được thực hiện khi slug trang thay đổi.
function toggleComments() {
  commentsVisible.value = !commentsVisible.value
}

// watch + immediate: chạy ngay khi mở trang và chạy lại khi slug trên URL đổi.
// Cần watch vì Vue Router dùng lại component này khi chỉ đổi slug, onMounted sẽ không chạy lại.
watch(
  () => route.params.slug,
  (slug) => {
    // Sang sản phẩm khác thì xoá bình luận và ảnh của sản phẩm cũ.
    comments.value = null
    commentsPagination.value = null
    commentsError.value = ''
    commentsLoading.value = false
    commentsVisible.value = false
    activeImageIndex.value = 0
    failedImages.value = new Set()

    if (typeof slug === 'string' && slug !== '') {
      fetchProduct(slug)
      fetchComments(1)
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="pd-page" :class="{ 'pd-page--has-bar': product && loggedIn }">
    <!-- Trạng thái đang tải sản phẩm. -->
    <div v-if="productLoading" class="pd-card pd-state" role="status">Đang tải sản phẩm...</div>

    <!-- Lỗi tải sản phẩm: slug không tồn tại hoặc server lỗi. -->
    <div v-else-if="productError" class="pd-card pd-state pd-state--error" role="alert">
      <p>{{ productError }}</p>
      <RouterLink class="pd-btn pd-btn--outline pd-btn--inline" :to="{ name: 'catalog' }">
        Quay lại danh sách sản phẩm
      </RouterLink>
    </div>

    <article v-else-if="product" class="pd-card">
      <!-- Breadcrumb cho desktop/tablet; mobile dùng link quay lại bên dưới. -->
      <nav class="pd-breadcrumb" aria-label="Breadcrumb">
        <RouterLink :to="{ name: 'catalog' }">Danh sách sản phẩm</RouterLink>
        <span class="pd-breadcrumb-separator" aria-hidden="true">/</span>
        <template v-if="product.category_name">
          <span>{{ product.category_name }}</span>
          <span class="pd-breadcrumb-separator" aria-hidden="true">/</span>
        </template>
        <span class="pd-breadcrumb-current" aria-current="page">{{ product.name }}</span>
      </nav>
      <RouterLink class="pd-back" :to="{ name: 'catalog' }">← Danh sách sản phẩm</RouterLink>

      <div class="pd-main">
        <!-- Album ảnh: ảnh lớn và các ảnh nhỏ để chọn khi có nhiều hơn một ảnh. -->
        <section class="pd-gallery" aria-label="Ảnh sản phẩm">
          <div class="pd-gallery-main">
            <img
              v-if="activeImage && !failedImages.has(activeImageIndex)"
              :src="resolveImageUrl(activeImage)"
              :alt="product.name"
              @error="markImageFailed(activeImageIndex)"
            >
            <span v-else class="pd-image-fallback" aria-hidden="true">SB</span>
          </div>

          <div v-if="galleryImages.length > 1" class="pd-thumbs">
            <button
              v-for="(image, index) in galleryImages"
              :key="`${index}-${image}`"
              type="button"
              class="pd-thumb"
              :class="{ 'is-active': index === activeImageIndex }"
              :aria-label="`Xem ảnh ${index + 1}`"
              :aria-pressed="index === activeImageIndex"
              @click="activeImageIndex = index"
            >
              <img v-if="!failedImages.has(index)" :src="resolveImageUrl(image)" alt="" @error="markImageFailed(index)">
              <span v-else class="pd-image-fallback pd-image-fallback--small" aria-hidden="true">{{ index + 1 }}</span>
            </button>
          </div>
        </section>

        <!-- Thông tin sản phẩm: danh mục, tên, mã, chỉ số, giá, tồn kho, mua hàng và mô tả. -->
        <section class="pd-info">
          <p v-if="product.category_name" class="pd-category">{{ product.category_name }}</p>
          <h1 class="pd-name">{{ product.name }}</h1>
          <p v-if="product.code" class="pd-meta">Mã: {{ product.code }}</p>
          <p class="pd-meta">{{ formatCount(product.view_count) }} lượt xem · {{ formatCount(product.sales_count) }} đã bán</p>

          <div class="pd-price">
            <strong :class="{ 'is-sale': hasSale }">{{ formatPrice(hasSale ? product.sale_price : product.price) }}</strong>
            <template v-if="hasSale">
              <del>{{ formatPrice(product.price) }}</del>
              <span class="pd-sale-badge">Giảm giá</span>
            </template>
          </div>

          <p v-if="isOutOfStock" class="pd-stock pd-stock--out">Hết hàng</p>
          <p v-else-if="hasStock" class="pd-stock">Còn {{ product.stock }} sản phẩm</p>

          <!-- Khu vực mua hàng cho thành viên: giao diện demo, các nút chưa nối xử lý giỏ hàng. -->
          <div v-if="loggedIn" class="pd-purchase">
            <div class="pd-quantity-row">
              <span id="pd-quantity-label" class="pd-quantity-label">Số lượng</span>
              <div class="pd-stepper" role="group" aria-labelledby="pd-quantity-label">
                <button type="button" :disabled="isOutOfStock" aria-label="Giảm số lượng">−</button>
                <span class="pd-stepper-value">{{ isOutOfStock ? 0 : 1 }}</span>
                <button type="button" :disabled="isOutOfStock" aria-label="Tăng số lượng">+</button>
              </div>
            </div>
            <div class="pd-purchase-actions">
              <button type="button" class="pd-btn pd-btn--primary" :disabled="isOutOfStock">Thêm vào giỏ</button>
              <button type="button" class="pd-btn pd-btn--outline" :disabled="isOutOfStock">Mua ngay</button>
            </div>
            <p class="pd-demo-note">Giao diện demo, chức năng mua hàng chưa hoạt động.</p>
          </div>

          <!-- Khách chưa đăng nhập: không có khu vực mua hàng, chỉ có gợi ý đăng nhập. -->
          <p v-else class="pd-login-hint">
            <RouterLink :to="{ name: 'login' }">Đăng nhập</RouterLink> để mua hàng và bình luận.
          </p>

          <hr class="pd-divider">
          <h2 class="pd-subheading">Mô tả sản phẩm</h2>
          <p class="pd-description">{{ product.description || 'Chưa có mô tả cho sản phẩm này.' }}</p>
        </section>
      </div>

      <!-- Bình luận được tải khi mở trang; nút chỉ thu gọn hoặc mở khu vực này. -->
      <section class="pd-comments" aria-labelledby="pd-comments-title">
        <div class="pd-comments-header">
          <h2 id="pd-comments-title" class="pd-comments-title">
            Bình luận<template v-if="commentCount !== null"> ({{ commentCount }})</template>
          </h2>
          <button
            type="button"
            class="pd-btn pd-btn--outline pd-comments-toggle"
            :aria-expanded="commentsVisible"
            aria-controls="pd-comments-body"
            @click="toggleComments"
          >
            {{ commentsVisible ? 'Ẩn bình luận' : 'Xem bình luận' }}
          </button>
        </div>

        <!-- v-show chỉ ẩn bằng CSS nên dữ liệu đã tải vẫn được giữ khi thu gọn. -->
        <div v-show="commentsVisible" id="pd-comments-body" class="pd-comments-body">
          <!-- Form bình luận cho thành viên: giao diện demo, chưa gửi dữ liệu lên BE. -->
          <div v-if="loggedIn" class="pd-comment-form">
            <span class="pd-avatar" aria-hidden="true">SB</span>
            <div class="pd-comment-form-body">
              <label class="visually-hidden" for="pd-comment-input">Nội dung bình luận</label>
              <textarea
                id="pd-comment-input"
                class="pd-textarea"
                rows="3"
                placeholder="Viết bình luận của bạn..."
              ></textarea>
              <div class="pd-comment-form-footer">
                <span class="pd-demo-note">Giao diện demo, chưa gửi được bình luận.</span>
                <button type="button" class="pd-btn pd-btn--primary pd-btn--inline">Gửi bình luận</button>
              </div>
            </div>
          </div>

          <!-- Các trạng thái tải bình luận: đang tải, lỗi (có thử lại), rỗng, có dữ liệu. -->
          <p v-if="commentsLoading" class="pd-comments-state" role="status">Đang tải bình luận...</p>

          <div v-else-if="commentsError" class="pd-comments-state pd-comments-state--error" role="alert">
            <span>{{ commentsError }}</span>
            <button
              type="button"
              class="pd-btn pd-btn--outline pd-btn--inline"
              @click="fetchComments(commentsPagination?.current_page || 1)"
            >
              Thử lại
            </button>
          </div>

          <p v-else-if="comments && comments.length === 0" class="pd-comments-state">
            Chưa có bình luận nào cho sản phẩm này.
          </p>

          <template v-else-if="comments">
            <ul class="pd-comment-list">
              <li v-for="comment in comments" :key="comment.id" class="pd-comment">
                <span class="pd-avatar" aria-hidden="true">{{ commentInitials(comment) }}</span>
                <div class="pd-comment-body">
                  <p class="pd-comment-meta">
                    <strong>{{ commentAuthor(comment) }}</strong>
                    <time :datetime="comment.created_at">{{ formatCommentDate(comment.created_at) }}</time>
                  </p>
                  <p class="pd-comment-content">{{ comment.content }}</p>
                </div>
              </li>
            </ul>

            <!-- Phân trang bình luận, chỉ hiện khi có từ 2 trang trở lên. -->
            <nav
              v-if="commentsPagination && commentsPagination.total_pages > 1"
              class="pd-pagination"
              aria-label="Phân trang bình luận"
            >
              <button
                type="button"
                class="pd-btn pd-btn--outline pd-btn--inline"
                :disabled="commentsPagination.current_page <= 1"
                @click="fetchComments(commentsPagination.current_page - 1)"
              >
                Trang trước
              </button>
              <span class="pd-pagination-indicator">
                {{ commentsPagination.current_page }} / {{ commentsPagination.total_pages }}
              </span>
              <button
                type="button"
                class="pd-btn pd-btn--outline pd-btn--inline"
                :disabled="commentsPagination.current_page >= commentsPagination.total_pages"
                @click="fetchComments(commentsPagination.current_page + 1)"
              >
                Trang sau
              </button>
            </nav>
          </template>
        </div>
      </section>
    </article>
  </div>
</template>

<style scoped>
.pd-page {
  color: #212529;
}

.pd-card {
  padding: 32px 24px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #ffffff;
  box-shadow: 0 1px 3px rgb(0 0 0 / 2%);
}

.pd-state {
  padding: 56px 24px;
  color: #6c757d;
  text-align: center;
}

.pd-state p {
  margin: 0 0 16px;
}

.pd-state--error {
  color: #a42d2d;
}

.pd-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
  margin-bottom: 16px;
  color: #6c757d;
  font-size: 13px;
  line-height: 1.5;
}

.pd-breadcrumb a:hover {
  color: #212529;
  text-decoration: underline;
}

.pd-breadcrumb-separator {
  color: #adb5bd;
}

.pd-breadcrumb-current {
  color: #212529;
  font-weight: 500;
}

.pd-back {
  display: none;
  margin-bottom: 16px;
  color: #212529;
  font-size: 14px;
}

.pd-main {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px;
  padding-bottom: 24px;
}

.pd-gallery-main {
  display: grid;
  overflow: hidden;
  aspect-ratio: 1 / 1;
  place-items: center;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #e9ecef;
}

.pd-gallery-main img,
.pd-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.pd-image-fallback {
  color: #6c757d;
  font-size: 32px;
  font-weight: 700;
}

.pd-image-fallback--small {
  font-size: 12px;
}

.pd-thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
}

.pd-thumb {
  display: grid;
  width: 60px;
  height: 60px;
  overflow: hidden;
  place-items: center;
  padding: 0;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #e9ecef;
  cursor: pointer;
}

.pd-thumb.is-active {
  border: 2px solid #212529;
}

.pd-category {
  margin: 0 0 4px;
  color: #6c757d;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.pd-name {
  margin: 0 0 6px;
  color: #212529;
  font-size: 28px;
  font-weight: 600;
  line-height: 1.25;
}

.pd-meta {
  margin: 0 0 2px;
  color: #6c757d;
  font-size: 13px;
}

.pd-price {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 10px;
  margin-top: 14px;
}

.pd-price strong {
  font-size: 24px;
  font-weight: 700;
  letter-spacing: -0.01em;
}

.pd-price strong.is-sale {
  color: #dc3545;
}

.pd-price del {
  color: #6c757d;
  font-size: 15px;
}

.pd-sale-badge {
  padding: 2px 6px;
  border: 1px solid #f1aeb5;
  border-radius: 4px;
  background: #f8d7da;
  color: #dc3545;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.pd-stock {
  margin: 4px 0 12px;
  color: #6c757d;
  font-size: 13px;
  font-weight: 500;
}

.pd-stock--out {
  color: #dc3545;
}

.pd-purchase {
  display: grid;
  gap: 16px;
  padding: 24px 16px 16px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #f8f9fa;
}

.pd-quantity-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.pd-quantity-label {
  font-size: 14px;
  font-weight: 600;
}

.pd-stepper {
  display: flex;
  overflow: hidden;
  align-items: stretch;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  background: #ffffff;
}

.pd-stepper button {
  width: 44px;
  height: 44px;
  padding: 0;
  border: 0;
  background: transparent;
  color: #212529;
  font-size: 18px;
  cursor: pointer;
}

.pd-stepper button:disabled {
  background: #e9ecef;
  color: #adb5bd;
  cursor: not-allowed;
}

.pd-stepper-value {
  display: grid;
  width: 48px;
  place-items: center;
  border-right: 1px solid #dee2e6;
  border-left: 1px solid #dee2e6;
  font-size: 15px;
  font-weight: 600;
}

.pd-purchase-actions {
  display: grid;
  gap: 8px;
}

.pd-btn {
  display: inline-flex;
  min-height: 44px;
  align-items: center;
  justify-content: center;
  padding: 0 20px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  cursor: pointer;
  transition: background-color 140ms ease, border-color 140ms ease;
}

.pd-btn--inline {
  font-size: 14px;
}

.pd-btn--primary {
  border-color: #212529;
  background: #212529;
  color: #ffffff;
}

.pd-btn--primary:hover:not(:disabled) {
  border-color: #000000;
  background: #000000;
}

.pd-btn--outline {
  background: #ffffff;
  color: #212529;
}

.pd-btn--outline:hover:not(:disabled) {
  border-color: #adb5bd;
  background: #f8f9fa;
}

.pd-btn:disabled {
  border-color: #dee2e6;
  background: #e9ecef;
  color: #adb5bd;
  cursor: not-allowed;
}

.pd-btn:focus-visible,
.pd-stepper button:focus-visible,
.pd-thumb:focus-visible,
.pd-textarea:focus-visible,
.pd-page a:focus-visible {
  outline: 2px solid #212529;
  outline-offset: 2px;
}

.pd-demo-note {
  margin: 0;
  color: #6c757d;
  font-size: 12px;
}

.pd-login-hint {
  margin: 20px 0 0;
  color: #6c757d;
  font-size: 14px;
}

.pd-login-hint a {
  color: #212529;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.pd-divider {
  margin: 24px 0 20px;
  border: 0;
  border-top: 1px solid #dee2e6;
  opacity: 1;
}

.pd-subheading {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 700;
}

.pd-description {
  margin: 0;
  color: #495057;
  font-size: 14px;
  line-height: 1.6;
  white-space: pre-line;
}

.pd-comments {
  padding-top: 32px;
  border-top: 1px solid #dee2e6;
}

.pd-comments-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.pd-comments-title {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
}

.pd-comments-toggle {
  font-size: 14px;
}

.pd-comments-body {
  display: grid;
  gap: 32px;
  margin-top: 24px;
}

.pd-avatar {
  display: grid;
  width: 40px;
  height: 40px;
  flex: 0 0 40px;
  place-items: center;
  border-radius: 50%;
  background: #dee2e6;
  color: #6c757d;
  font-size: 12px;
  font-weight: 700;
}

.pd-comment-form {
  display: flex;
  gap: 16px;
}

.pd-comment-form-body {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 14px;
}

.pd-textarea {
  width: 100%;
  min-height: 84px;
  padding: 12px;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  color: #212529;
  font-size: 14px;
  resize: vertical;
}

.pd-textarea::placeholder {
  color: #757575;
}

.pd-comment-form-footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.pd-comments-state {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin: 0;
  color: #6c757d;
  font-size: 14px;
}

.pd-comments-state--error {
  color: #a42d2d;
}

.pd-comment-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.pd-comment {
  display: flex;
  gap: 16px;
  padding: 16px 0;
  border-top: 1px solid #dee2e6;
}

.pd-comment:last-child {
  border-bottom: 1px solid #dee2e6;
}

.pd-comment-body {
  min-width: 0;
  flex: 1;
}

.pd-comment-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 8px;
  margin: 0 0 4px;
}

.pd-comment-meta strong {
  font-size: 14px;
  font-weight: 600;
}

.pd-comment-meta time {
  color: #6c757d;
  font-size: 12px;
}

.pd-comment-content {
  margin: 0;
  color: #343a40;
  font-size: 14px;
  line-height: 1.5;
  overflow-wrap: anywhere;
  white-space: pre-line;
}

.pd-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-top: 24px;
}

.pd-pagination-indicator {
  min-width: 48px;
  color: #6c757d;
  font-size: 14px;
  font-weight: 600;
  text-align: center;
}

@media (max-width: 767.98px) {
  .pd-main {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 575.98px) {
  .pd-card {
    padding: 16px;
  }

  .pd-breadcrumb {
    display: none;
  }

  .pd-back {
    display: inline-block;
  }

  .pd-name {
    font-size: 20px;
  }

  .pd-purchase {
    padding: 16px;
  }

  /* Mobile: hai nút mua cố định ở đáy màn hình như thiết kế 1E. */
  .pd-purchase-actions {
    position: fixed;
    z-index: 900;
    right: 0;
    bottom: 0;
    left: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
    border-top: 1px solid #dee2e6;
    background: #ffffff;
  }

  /* Chừa chỗ để thanh mua cố định không che nội dung cuối trang. */
  .pd-page--has-bar {
    padding-bottom: 72px;
  }

  .pd-comments-header {
    flex-direction: column;
    align-items: stretch;
  }

  .pd-comment-form .pd-avatar {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .pd-btn {
    transition: none;
  }
}
</style>
