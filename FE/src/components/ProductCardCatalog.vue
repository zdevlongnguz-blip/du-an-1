<script setup>
// Card Catalog hiển thị thông tin sản phẩm và hai CTA dùng chung giao diện Sharp Barber.
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { formatPrice } from '@/helpers/formatPrice'
import { resolveImageUrl } from '@/helpers/imageUrl'

const props = defineProps({
  product: {
    type: Object,
    required: true,
  },
})

// Tạo URL ảnh từ filename trong DB; helper xử lý URL API ở cả dev và build.
const imageSrc = computed(() => resolveImageUrl(props.product.image))

// Khi ảnh lỗi, card dùng vùng thay thế thay vì hiện ảnh hỏng.
const imageFailed = ref(false)

// Chỉ đánh dấu hết hàng khi API có stock hợp lệ bằng 0 hoặc nhỏ hơn.
const isOutOfStock = computed(() => {
  const stock = props.product.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock)) && Number(stock) <= 0
})
</script>

<template>
  <!-- Card giữ tên/giá ở đáy; phần thông tin và CTA mở khi hover hoặc focus. -->
  <article class="catalog-product-card">
    <RouterLink
      class="catalog-product-media"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <img v-if="imageSrc && !imageFailed" :src="imageSrc" :alt="product.name" loading="lazy" @error="imageFailed = true">
      <span v-else class="catalog-product-image-fallback" aria-hidden="true">SB</span>
    </RouterLink>

    <!-- Link nội dung mở chi tiết sản phẩm. -->
    <RouterLink
      class="catalog-product-content"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <div class="catalog-product-details">
        <div class="catalog-product-heading">
          <p class="catalog-product-category">{{ product.category_name || 'Sharp Barber' }}</p>
          <p v-if="product.code" class="catalog-product-code">{{ product.code }}</p>
        </div>
        <p v-if="product.description" class="catalog-product-description">{{ product.description }}</p>
        <span v-if="isOutOfStock" class="catalog-product-availability">Hết hàng</span>
        <span v-else-if="product.stock !== null && product.stock !== undefined" class="catalog-product-availability">
          Còn {{ product.stock }} sản phẩm
        </span>
      </div>

      <!-- Tên và giá luôn hiện, giữ cùng điểm neo giữa các thẻ. -->
      <div class="catalog-product-summary">
        <h2 class="catalog-product-name">{{ product.name }}</h2>
        <div class="catalog-product-price">
          <strong>{{ formatPrice(product.sale_price || product.price) }}</strong>
          <span v-if="product.sale_price && Number(product.sale_price) < Number(product.price)">
            {{ formatPrice(product.price) }}
          </span>
        </div>
        <div class="catalog-product-stats" aria-label="Thống kê sản phẩm">
          <p class="catalog-product-stat">
            <strong>{{ Number(product.view_count ?? 0).toLocaleString('vi-VN') }}</strong>
            <span>Lượt xem</span>
          </p>
          <p class="catalog-product-stat">
            <strong>{{ Number(product.sales_count ?? 0).toLocaleString('vi-VN') }}</strong>
            <span>Lượt bán</span>
          </p>
        </div>
      </div>
    </RouterLink>

    <!-- Hai CTA hiện khi hover/focus; nút thêm giỏ chưa nối hành vi. -->
    <div class="catalog-product-actions">
      <RouterLink
        class="catalog-product-action catalog-product-action--view"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        :aria-label="`Xem chi tiết ${product.name}`"
        title="Xem sản phẩm"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
          <circle cx="12" cy="12" r="3" />
        </svg>
      </RouterLink>
      <button
        class="catalog-product-action catalog-product-cart"
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
.catalog-product-card {
  position: relative;
  min-width: 0;
  overflow: hidden;
  aspect-ratio: 3 / 4;
  border: 1px solid #dfe1e2;
  border-radius: 4px;
  background: #edeeef;
  color: #ffffff;
  isolation: isolate;
}

.catalog-product-card:focus-within {
  outline: 3px solid #575f67;
  outline-offset: 3px;
}

.catalog-product-media {
  position: absolute;
  z-index: 0;
  inset: 0;
  display: grid;
  place-items: center;
  background: #edeeef;
}

.catalog-product-media img,
.catalog-product-image-fallback {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: filter 320ms ease, opacity 320ms ease, transform 420ms cubic-bezier(0.2, 0.65, 0.3, 1);
}

.catalog-product-image-fallback {
  display: grid;
  place-items: center;
  color: #75777b;
  font-size: 32px;
  font-weight: 700;
}

.catalog-product-card::after {
  position: absolute;
  z-index: 1;
  inset: 0;
  background: linear-gradient(180deg, rgb(0 0 0 / 2%) 20%, rgb(0 0 0 / 70%) 100%);
  content: '';
  opacity: 0.72;
  pointer-events: none;
  transition: opacity 220ms ease;
}

.catalog-product-card:hover::after,
.catalog-product-card:focus-within::after {
  opacity: 1;
}

.catalog-product-card:hover .catalog-product-media img,
.catalog-product-card:focus-within .catalog-product-media img {
  filter: grayscale(1) brightness(0.56);
  opacity: 0.82;
  transform: scale(1.035);
}

.catalog-product-content {
  position: absolute;
  z-index: 2;
  inset: 0;
  display: flex;
  min-width: 0;
  flex-direction: column;
  justify-content: flex-end;
  padding: 14px;
  transition: padding-bottom 280ms ease;
  color: #ffffff;
  text-decoration: none;
}

.catalog-product-details {
  max-height: 0;
  overflow: hidden;
  opacity: 0;
  transform: translateY(10px);
  transition: max-height 320ms ease, opacity 220ms ease, transform 320ms ease;
}

.catalog-product-card:hover .catalog-product-details,
.catalog-product-card:focus-within .catalog-product-details {
  max-height: 172px;
  opacity: 1;
  transform: translateY(0);
}

.catalog-product-card:hover .catalog-product-content,
.catalog-product-card:focus-within .catalog-product-content {
  padding-bottom: 62px;
}

.catalog-product-heading {
  display: flex;
  min-width: 0;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.catalog-product-category,
.catalog-product-code {
  margin: 0 0 6px;
  color: rgb(255 255 255 / 78%);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.catalog-product-code {
  flex: 0 0 auto;
  font-size: 9px;
  text-align: right;
}

.catalog-product-name {
  display: -webkit-box;
  min-height: 42px;
  margin: 0;
  overflow: hidden;
  color: #ffffff;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.45;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-description {
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

.catalog-product-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 8px;
}

.catalog-product-stat {
  display: flex;
  flex-direction: column;
  min-width: 0;
  align-items: flex-start;
  gap: 2px;
  margin: 0;
  color: rgb(255 255 255 / 78%);
  font-size: 10px;
}

.catalog-product-stat strong {
  color: #ffffff;
  font-size: 12px;
}

.catalog-product-availability {
  display: flex;
  margin-top: 7px;
  color: rgb(255 255 255 / 82%);
  font-size: 10px;
}

.catalog-product-summary {
  display: grid;
  gap: 6px;
  margin-top: 4px;
}

.catalog-product-price {
  display: grid;
  min-width: 0;
  gap: 2px;
}

.catalog-product-price strong {
  font-size: 14px;
}

.catalog-product-price span {
  color: rgb(255 255 255 / 72%);
  font-size: 11px;
  text-decoration: line-through;
}

.catalog-product-actions {
  position: absolute;
  z-index: 3;
  right: 14px;
  bottom: 14px;
  display: flex;
  gap: 8px;
  opacity: 0;
  pointer-events: none;
  transform: translateY(4px);
  transition: opacity 180ms ease, transform 180ms ease;
}

.catalog-product-action {
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  place-items: center;
  padding: 0;
  border: 1px solid rgb(255 255 255 / 82%);
  cursor: pointer;
  transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease, opacity 180ms ease, transform 180ms ease;
}

.catalog-product-action svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.7;
}

.catalog-product-action--view {
  background: rgb(12 16 20 / 30%);
  color: #ffffff;
}

.catalog-product-action--view:hover {
  border-color: #ffffff;
  background: rgb(255 255 255 / 18%);
  color: #ffffff;
}

.catalog-product-cart {
  border-color: #ffffff;
  background: #ffffff;
  color: #0c1014;
}

.catalog-product-cart:hover:not(:disabled) {
  border-color: #e7e8e9;
  background: #e7e8e9;
  color: #0c1014;
}

.catalog-product-cart:disabled {
  border-color: #e1e3e4;
  background: #e1e3e4;
  color: #575f67;
  cursor: not-allowed;
}

.catalog-product-actions:focus-within,
.catalog-product-card:hover .catalog-product-actions,
.catalog-product-card:focus-within .catalog-product-actions {
  opacity: 1;
  pointer-events: auto;
  transform: translateY(0);
}

@media (hover: none) {
  .catalog-product-card .catalog-product-media img {
    filter: grayscale(0.7) brightness(0.7);
  }

  .catalog-product-card .catalog-product-content {
    padding-bottom: 62px;
  }

  .catalog-product-card .catalog-product-details {
    max-height: 172px;
    opacity: 1;
    transform: none;
  }

  .catalog-product-actions {
    opacity: 1;
    pointer-events: auto;
    transform: none;
  }
}

@media (max-width: 575.98px) {
  .catalog-product-content {
    padding-bottom: 60px;
  }

  .catalog-product-description {
    line-clamp: 1;
    -webkit-line-clamp: 1;
  }

  .catalog-product-actions {
    gap: 6px;
  }

}

@media (prefers-reduced-motion: reduce) {
  .catalog-product-card::after,
  .catalog-product-media img,
  .catalog-product-content,
  .catalog-product-details,
  .catalog-product-actions {
    transition: none;
  }
}
</style>
