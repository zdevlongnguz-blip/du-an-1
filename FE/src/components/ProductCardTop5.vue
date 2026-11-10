<script setup>
// Thẻ sản phẩm Top 5: mặc định hiện tên/giá, hover hoặc focus để xem đầy đủ thông tin.
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { formatPrice } from '@/helpers/format/price'
import { resolveImageUrl } from '@/helpers/media/imageUrl'

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

// Ghép URL ảnh từ filename trong DB; helper dùng base URL API cho dev và build.
const imageSrc = computed(() => resolveImageUrl(props.product.image))
// Khi ảnh lỗi, thẻ chuyển sang vùng ảnh dự phòng.
const imageFailed = ref(false)
// Luôn có cả lượt xem và lượt bán để không mất một chỉ số theo chế độ xếp hạng.
const viewCount = computed(() => Number(props.product.view_count ?? 0).toLocaleString('vi-VN'))
const salesCount = computed(() => Number(props.product.sales_count ?? 0).toLocaleString('vi-VN'))
// Chỉ đánh dấu hết hàng khi API có stock hợp lệ bằng 0 hoặc nhỏ hơn.
const isOutOfStock = computed(() => {
  const stock = props.product.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock)) && Number(stock) <= 0
})
</script>

<template>
  <!-- Card giữ tên/giá ở đáy; phần thông tin và CTA mở khi hover hoặc focus. -->
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
    </div>

    <!-- Link nội dung mở chi tiết sản phẩm. -->
    <RouterLink
      class="top5-card-content"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <div class="top5-card-details">
        <div class="top5-card-heading">
          <p class="top5-card-category">{{ product.category_name || 'Sharp Barber' }}</p>
          <p v-if="product.code" class="top5-card-code">{{ product.code }}</p>
        </div>
        <p v-if="product.description" class="top5-card-description">{{ product.description }}</p>
        <p v-if="isOutOfStock" class="top5-card-availability top5-card-availability--out" role="status">
          Hết hàng
        </p>
        <p v-else-if="product.stock !== null && product.stock !== undefined" class="top5-card-availability">
          Còn {{ product.stock }} sản phẩm
        </p>
      </div>

      <!-- Tên và giá luôn hiện, giữ cùng điểm neo giữa các thẻ. -->
      <div class="top5-card-summary">
        <h2 class="top5-card-name">{{ product.name }}</h2>
        <div class="top5-card-price">
          <strong>{{ formatPrice(product.sale_price || product.price) }}</strong>
          <span v-if="product.sale_price && Number(product.sale_price) < Number(product.price)">
            {{ formatPrice(product.price) }}
          </span>
        </div>
        <div class="top5-card-stats" aria-label="Thống kê sản phẩm">
          <p class="top5-card-stat" :class="{ 'is-ranked': sortBy === 'views' }">
            <strong>{{ viewCount }}</strong>
            <span>Lượt xem</span>
          </p>
          <p class="top5-card-stat" :class="{ 'is-ranked': sortBy === 'sales' }">
            <strong>{{ salesCount }}</strong>
            <span>Lượt bán</span>
          </p>
        </div>
      </div>
    </RouterLink>

    <!-- Hai CTA nằm ngoài link bao nội dung để không lồng hành động. -->
    <div class="top5-card-actions">
      <RouterLink
        class="top5-card-action top5-card-view"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        :aria-label="`Xem chi tiết ${product.name}`"
        title="Xem sản phẩm"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </RouterLink>
      <!-- Nút thêm giỏ đang giữ giao diện, chưa nối hành vi. -->
      <button
        class="top5-card-action top5-card-cart"
        type="button"
        :disabled="isOutOfStock"
        :aria-label="isOutOfStock ? `${product.name} đã hết hàng` : `Thêm ${product.name} vào giỏ hàng`"
        :title="isOutOfStock ? 'Hết hàng' : 'Thêm vào giỏ'"
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
  transition: filter 320ms ease, opacity 320ms ease, transform 420ms cubic-bezier(0.2, 0.65, 0.3, 1);
}

.top5-card-image-fallback {
  place-items: center;
  color: #75777b;
  font-size: 32px;
  font-weight: 700;
}

.top5-card::after {
  position: absolute;
  z-index: 1;
  inset: 0;
  background: linear-gradient(180deg, rgb(0 0 0 / 2%) 20%, rgb(0 0 0 / 68%) 100%);
  content: '';
  opacity: 0.68;
  pointer-events: none;
  transition: opacity 220ms ease;
}

.top5-card:hover .top5-card-image,
.top5-card:focus-within .top5-card-image {
  filter: grayscale(1) brightness(0.56);
  opacity: 0.82;
  transform: scale(1.035);
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
  opacity: 1;
  transform: translateY(0);
  transition: opacity 220ms ease, transform 220ms ease;
}

.top5-card:hover .top5-card-rank,
.top5-card:focus-within .top5-card-rank {
  opacity: 0;
  transform: translateY(-4px);
}

.top5-card-content {
  position: absolute;
  z-index: 2;
  inset: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 16px;
  transition: padding-bottom 280ms ease;
  color: #ffffff;
  text-decoration: none;
}

.top5-card-details {
  max-height: 0;
  overflow: hidden;
  opacity: 0;
  transform: translateY(10px);
  transition: max-height 320ms ease, opacity 220ms ease, transform 320ms ease;
}

.top5-card:hover .top5-card-details,
.top5-card:focus-within .top5-card-details {
  max-height: 132px;
  opacity: 1;
  transform: translateY(0);
}

.top5-card:hover .top5-card-content,
.top5-card:focus-within .top5-card-content {
  padding-bottom: 62px;
}

.top5-card-heading {
  display: flex;
  min-width: 0;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.top5-card-category {
  margin: 0 0 7px;
  color: rgb(255 255 255 / 78%);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.top5-card-code {
  flex: 0 0 auto;
  margin: 0 0 7px;
  color: rgb(255 255 255 / 70%);
  font-size: 9px;
  text-align: right;
}

.top5-card-description {
  display: -webkit-box;
  margin: 5px 0 0;
  overflow: hidden;
  color: rgb(255 255 255 / 78%);
  font-size: 11px;
  line-height: 1.4;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.top5-card-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 8px;
}

.top5-card-stat {
  display: flex;
  flex-direction: column;
  min-width: 0;
  align-items: flex-start;
  gap: 2px;
  margin: 0;
  color: rgb(255 255 255 / 78%);
  font-size: 10px;
}

.top5-card-stat.is-ranked {
  color: #ffffff;
}

.top5-card-stat strong {
  color: #ffffff;
  font-size: 13px;
}

.top5-card-availability {
  margin: 6px 0 0;
  color: rgb(255 255 255 / 82%);
  font-size: 10px;
}

.top5-card-availability--out {
  color: #ffb4ab;
  font-weight: 700;
}

.top5-card-summary {
  display: grid;
  gap: 6px;
  margin-top: 4px;
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
  line-clamp: 2;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
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
  .top5-card {
    min-height: 320px;
    aspect-ratio: auto;
  }

  .top5-card .top5-card-image {
    filter: grayscale(0.7) brightness(0.7);
  }

  .top5-card .top5-card-content {
    padding-bottom: 62px;
  }

  .top5-card .top5-card-details {
    max-height: 150px;
    opacity: 1;
    transform: none;
  }

  .top5-card-rank {
    opacity: 1;
    transform: none;
  }

  .top5-card-actions {
    opacity: 1;
    pointer-events: auto;
    transform: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .top5-card::after,
  .top5-card-image,
  .top5-card-content,
  .top5-card-details,
  .top5-card-rank,
  .top5-card-actions,
  .top5-card-action {
    transition: none;
  }
}
</style>