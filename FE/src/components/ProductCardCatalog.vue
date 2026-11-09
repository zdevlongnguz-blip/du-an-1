<script setup>
// Card Catalog hiển thị thông tin sản phẩm và hai CTA dùng chung giao diện Sharp Barber.
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'
import { formatPrice } from '@/helpers/formatPrice'

const props = defineProps({
  product: {
    type: Object,
    required: true,
  },
})

// Tạo URL ảnh từ base URL API hiện có, không hardcode host môi trường.
const imageSrc = computed(() => {
  const image = props.product.image
  if (!image) return ''
  if (/^https?:\/\//i.test(image)) return image

  return new URL(`assets/uploads/${image}`, `${api.defaults.baseURL}/`).toString()
})

// Khi ảnh lỗi, card dùng vùng thay thế thay vì hiện ảnh hỏng.
const imageFailed = ref(false)

// Chỉ đánh dấu hết hàng khi API có stock hợp lệ bằng 0 hoặc nhỏ hơn.
const isOutOfStock = computed(() => {
  const stock = props.product.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock)) && Number(stock) <= 0
})
</script>

<template>
  <!-- Card tách link chi tiết và cụm CTA để các hành động không lồng nhau. -->
  <article class="catalog-product-card">
    <RouterLink
      class="catalog-product-media"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <img v-if="imageSrc && !imageFailed" :src="imageSrc" :alt="product.name" loading="lazy" @error="imageFailed = true">
      <span v-else class="catalog-product-image-fallback" aria-hidden="true">SB</span>
    </RouterLink>

    <span v-if="isOutOfStock" class="catalog-product-stock-badge" role="status">Hết hàng</span>

    <!-- Link nội dung mở chi tiết; hai CTA độc lập nằm cùng hàng phía dưới. -->
    <RouterLink
      class="catalog-product-content"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <div class="catalog-product-heading">
        <p class="catalog-product-category">{{ product.category_name || 'Sharp Barber' }}</p>
        <p v-if="product.code" class="catalog-product-code">{{ product.code }}</p>
      </div>

      <h2 class="catalog-product-name">{{ product.name }}</h2>
      <p v-if="product.description" class="catalog-product-description">{{ product.description }}</p>

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

      <div class="catalog-product-footer">
        <div class="catalog-product-price">
          <strong>{{ formatPrice(product.sale_price || product.price) }}</strong>
          <span v-if="product.sale_price && Number(product.sale_price) < Number(product.price)">
            {{ formatPrice(product.price) }}
          </span>
        </div>
        <span v-if="!isOutOfStock && product.stock !== null && product.stock !== undefined" class="catalog-product-availability">
          Còn {{ product.stock }}
        </span>
      </div>
    </RouterLink>

    <!-- Hai CTA dùng chung kích thước; nút thêm giỏ chưa nối hành vi. -->
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
  transition: transform 320ms cubic-bezier(0.2, 0.65, 0.3, 1);
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
  background: linear-gradient(180deg, rgb(0 0 0 / 3%) 10%, rgb(0 0 0 / 82%) 100%);
  content: '';
  opacity: 0.94;
  pointer-events: none;
  transition: opacity 220ms ease;
}

.catalog-product-card:hover::after,
.catalog-product-card:focus-within::after {
  opacity: 1;
}

.catalog-product-card:hover .catalog-product-media img,
.catalog-product-card:focus-within .catalog-product-media img {
  transform: scale(1.025);
}

.catalog-product-stock-badge {
  position: absolute;
  z-index: 2;
  top: 12px;
  right: 12px;
  padding: 8px 10px;
  background: #ba1a1a;
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}

.catalog-product-content {
  position: absolute;
  z-index: 2;
  inset: 0;
  display: flex;
  min-width: 0;
  flex-direction: column;
  justify-content: flex-end;
  padding: 12px 14px 68px;
  color: #ffffff;
  text-decoration: none;
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
  min-height: 44px;
  margin: 0;
  overflow: hidden;
  color: #ffffff;
  font-size: 15px;
  font-weight: 600;
  line-height: 1.45;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-description {
  display: -webkit-box;
  margin: 6px 0 0;
  overflow: hidden;
  color: rgb(255 255 255 / 78%);
  font-size: 11px;
  line-height: 1.4;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 10px;
}

.catalog-product-stat {
  display: flex;
  min-width: 0;
  align-items: baseline;
  justify-content: space-between;
  gap: 4px;
  margin: 0;
  color: rgb(255 255 255 / 78%);
  font-size: 10px;
}

.catalog-product-stat strong {
  color: #ffffff;
  font-size: 12px;
}

.catalog-product-footer {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgb(255 255 255 / 45%);
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

.catalog-product-availability {
  flex: 0 0 auto;
  color: rgb(255 255 255 / 78%);
  font-size: 10px;
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
    display: none;
  }

  .catalog-product-actions {
    gap: 6px;
  }

}

@media (prefers-reduced-motion: reduce) {
  .catalog-product-card::after,
  .catalog-product-media img,
  .catalog-product-actions {
    transition: none;
  }
}
</style>
