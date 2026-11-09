<script setup>
// Thẻ sản phẩm dùng riêng cho danh sách top 5 trên trang chủ.
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'
import { formatPrice } from '@/helpers/formatPrice'

const props = defineProps({
  product: {
    type: Object,
    required: true,
  },
  rank: {
    type: Number,
    required: true,
  },
  sortBy: {
    type: String,
    required: true,
  },
})

// Tạo URL ảnh từ base URL API hiện có để không hardcode host môi trường.
const imageSrc = computed(() => {
  const image = props.product.image
  if (!image) return ''
  if (/^https?:\/\//i.test(image)) return image

  return new URL(`assets/uploads/${image}`, `${api.defaults.baseURL}/`).toString()
})

// Ẩn ảnh lỗi để hiện vùng thay thế, tránh biểu tượng ảnh hỏng trong thẻ.
const imageFailed = ref(false)

// Chọn số liệu và nhãn khớp với chế độ xếp hạng đang xem.
const rankingCount = computed(() => Number(
  props.sortBy === 'views' ? props.product.view_count : props.product.sales_count
))
const rankingLabel = computed(() => props.sortBy === 'views' ? 'lượt xem' : 'lượt bán')
// Chỉ đánh dấu hết hàng khi API có stock hợp lệ bằng 0 hoặc nhỏ hơn.
const isOutOfStock = computed(() => {
  const stock = props.product.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock)) && Number(stock) <= 0
})
</script>

<template>
  <!-- Khung thẻ tách link chi tiết và nút giỏ để hai hành động không lồng nhau. -->
  <article class="top5-card">
    <div class="top5-card-media">
      <img
        v-if="imageSrc && !imageFailed"
        class="top5-card-image"
        :src="imageSrc"
        :alt="product.name"
        loading="lazy"
        @error="imageFailed = true"
      >
      <div v-else class="top5-card-image-fallback" aria-hidden="true">SB</div>
      <span class="top5-card-rank">{{ String(rank).padStart(2, '0') }}</span>
      <span v-if="isOutOfStock" class="top5-card-stock" role="status">Out of stock</span>
    </div>

    <!-- Link chi tiết phủ phần nội dung; nút giỏ là phần tử riêng bên cạnh. -->
    <RouterLink
      class="top5-card-content"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <p class="top5-card-category">{{ product.category_name || 'Sharp Barber' }}</p>
      <h2 class="top5-card-name">{{ product.name }}</h2>

      <div class="top5-card-meta">
        <div class="top5-card-price">
          <strong>{{ formatPrice(product.sale_price || product.price) }}</strong>
          <span v-if="product.sale_price && Number(product.sale_price) < Number(product.price)">
            {{ formatPrice(product.price) }}
          </span>
        </div>
        <p class="top5-card-stat">
          <strong>{{ rankingCount.toLocaleString('vi-VN') }}</strong>
          <span>{{ rankingLabel }}</span>
        </p>
      </div>
    </RouterLink>

    <!-- Gom hai icon CTA cạnh nhau ở cùng một vị trí. -->
    <div class="top5-card-actions">
      <RouterLink
        class="top5-card-action top5-card-view"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        :aria-label="`Xem chi tiết ${product.name}`"
        title="Xem sản phẩm"
      >
        <svg viewBox="0 0 24 24" focusable="false">
          <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </RouterLink>
      <!-- Chỉ hiển thị nút giỏ theo thiết kế; chưa gắn chức năng thêm giỏ. -->
      <button
        class="top5-card-action top5-card-cart"
        type="button"
        :disabled="isOutOfStock"
        :aria-label="isOutOfStock ? `${product.name} is out of stock` : `Add ${product.name} to cart`"
        :title="isOutOfStock ? 'Out of stock' : 'Add to cart'"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <circle cx="9" cy="21" r="1" />
          <circle cx="19" cy="21" r="1" />
          <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L22 7H6" />
        </svg>
      </button>
    </div>
  </article>
</template>

<style scoped>
.top5-card {
  position: relative;
  display: block;
  min-width: 0;
  overflow: hidden;
  aspect-ratio: 4 / 3;
  border-radius: 4px;
  background: #edeeef;
  color: #ffffff;
  isolation: isolate;
}

.top5-card:focus-visible {
  outline: 3px solid #575f67;
  outline-offset: 3px;
}

.top5-card-media {
  position: absolute;
  z-index: 0;
  inset: 0;
  background: #edeeef;
}

.top5-card-image,
.top5-card-image-fallback {
  display: grid;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 320ms cubic-bezier(0.2, 0.65, 0.3, 1);
}

.top5-card::after {
  position: absolute;
  z-index: 1;
  inset: 0;
  background: linear-gradient(180deg, rgb(0 0 0 / 4%) 18%, rgb(0 0 0 / 68%) 100%);
  content: '';
  opacity: 0.9;
  pointer-events: none;
  transition: opacity 220ms ease;
}

.top5-card-image-fallback {
  place-items: center;
  color: #75777b;
  font-size: 32px;
  font-weight: 700;
}

.top5-card:hover .top5-card-image,
.top5-card:focus-within .top5-card-image {
  transform: scale(1.025);
}

.top5-card:hover::after,
.top5-card:focus-within::after {
  opacity: 1;
}

.top5-card-rank {
  position: absolute;
  z-index: 2;
  top: 12px;
  left: 12px;
  display: grid;
  width: 36px;
  height: 36px;
  place-items: center;
  background: #0c1014;
  color: #ffffff;
  font-size: 12px;
  font-weight: 700;
}

.top5-card-stock {
  position: absolute;
  z-index: 2;
  top: 12px;
  right: 12px;
  padding: 9px 12px;
  background: #ba1a1a;
  color: #ffffff;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
}

.top5-card-content {
  position: absolute;
  z-index: 2;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: clamp(14px, 2vw, 24px);
  color: #ffffff;
  text-decoration: none;
}

.top5-card-category {
  margin: 0 0 7px;
  color: rgb(255 255 255 / 78%);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.top5-card-name {
  display: -webkit-box;
  min-height: 48px;
  margin: 0;
  overflow: hidden;
  color: #ffffff;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.top5-card-meta {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid rgb(255 255 255 / 45%);
}

.top5-card-price {
  display: grid;
  min-width: 0;
  gap: 2px;
}

.top5-card-price strong {
  font-size: 14px;
}

.top5-card-price span {
  color: rgb(255 255 255 / 72%);
  font-size: 12px;
  text-decoration: line-through;
}

.top5-card-stat {
  display: grid;
  flex: 0 0 auto;
  justify-items: end;
  gap: 2px;
  margin: 0;
  color: rgb(255 255 255 / 78%);
  font-size: 11px;
}

.top5-card-stat strong {
  color: #ffffff;
  font-size: 13px;
}

.top5-card-actions {
  position: absolute;
  z-index: 3;
  right: clamp(14px, 2vw, 24px);
  bottom: clamp(14px, 2vw, 24px);
  display: flex;
  gap: 8px;
  opacity: 0;
  pointer-events: none;
  transform: translateY(4px);
  transition: opacity 180ms ease, transform 180ms ease;
}

.top5-card-action {
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  place-items: center;
  padding: 0;
  border: 1px solid rgb(255 255 255 / 78%);
  border-radius: 4px;
  cursor: pointer;
  text-decoration: none;
  transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease;
}

.top5-card-view {
  background: rgb(12 16 20 / 44%);
  color: #ffffff;
}

.top5-card-view:hover {
  background: rgb(12 16 20 / 72%);
}

.top5-card-view svg,
.top5-card-cart svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.7;
}

.top5-card-cart {
  border: 1px solid #ffffff;
  background: #ffffff;
  color: #0c1014;
}

.top5-card-cart:hover:not(:disabled) {
  background: #e7e8e9;
}

.top5-card-cart:disabled {
  border-color: #e1e3e4;
  background: #e1e3e4;
  color: #575f67;
  cursor: not-allowed;
}

.top5-card-action:focus-visible {
  outline: 3px solid #ffffff;
  outline-offset: 3px;
}

.top5-card:focus-within .top5-card-actions,
.top5-card:hover .top5-card-actions {
  opacity: 1;
  pointer-events: auto;
  transform: translateY(0);
}

@media (min-width: 992px) {
  .top5-card {
    aspect-ratio: auto;
  }
}

@media (min-width: 768px) and (max-width: 991.98px) {
  .top5-card:first-child {
    aspect-ratio: 16 / 9;
  }
}

@media (hover: none) {
  .top5-card-actions {
    opacity: 1;
    pointer-events: auto;
    transform: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .top5-card::after,
  .top5-card-image,
  .top5-card-actions,
  .top5-card-action {
    transition: none;
  }
}
</style>