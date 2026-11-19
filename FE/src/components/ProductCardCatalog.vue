<script setup>
// Card Catalog theo Stitch: thông tin hiện sẵn bên dưới ảnh, hai CTA nằm trong lưới 2 cột.
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { formatPrice } from '@/helpers/format/price'
import { resolveImageUrl } from '@/helpers/media/imageUrl'

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

// Giá hiển thị là giá thấp nhất của các biến thể đang bán; có nhiều mức giá thì thêm "Từ".
// Không có biến thể đang bán (min null) thì hiện "Liên hệ" thay vì ₫0.
const priceText = computed(() => {
  const min = props.product.min_final_price
  if (min === null || min === undefined) return 'Liên hệ'
  const max = Number(props.product.max_final_price)
  const prefix = Number.isFinite(max) && max > Number(min) ? 'Từ ' : ''
  return prefix + formatPrice(Number(min))
})

// Chỉ đánh dấu hết hàng khi API có stock hợp lệ bằng 0 hoặc nhỏ hơn.
const isOutOfStock = computed(() => {
  const stock = props.product.stock
  return stock !== null && stock !== undefined && Number.isFinite(Number(stock)) && Number(stock) <= 0
})

// Lượt xem và lượt bán luôn hiển thị để giữ đủ thông tin như trước.
const viewCount = computed(() => Number(props.product.view_count ?? 0).toLocaleString('vi-VN'))
const salesCount = computed(() => Number(props.product.sales_count ?? 0).toLocaleString('vi-VN'))
</script>

<template>
  <article class="catalog-product-card">
    <!-- Ảnh sản phẩm tỉ lệ 4:5, bấm vào để xem chi tiết. -->
    <RouterLink
      class="catalog-product-media"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <img
        v-if="imageSrc && !imageFailed"
        class="catalog-product-image"
        :src="imageSrc"
        :alt="product.name"
        loading="lazy"
        @error="imageFailed = true"
      >
      <span v-else class="catalog-product-image-fallback" aria-hidden="true">SB</span>
    </RouterLink>

    <div class="catalog-product-body">
      <!-- Thông tin chính: danh mục, tên, mô tả, giá, chỉ số. -->
      <RouterLink
        class="catalog-product-info"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        :aria-label="`Xem chi tiết ${product.name}`"
      >
        <p class="catalog-product-category">{{ product.category_name || 'FuLe BaberShop' }}</p>
        <h2 class="catalog-product-name">{{ product.name }}</h2>
        <p v-if="product.description" class="catalog-product-description">{{ product.description }}</p>
        <p class="catalog-product-price">
          <strong>{{ priceText }}</strong>
        </p>
        <p class="catalog-product-meta">
          <span v-if="product.code" class="catalog-product-code">{{ product.code }}</span>
          <span v-if="isOutOfStock" class="catalog-product-availability catalog-product-availability--out">Hết hàng</span>
          <span v-else-if="product.stock !== null && product.stock !== undefined" class="catalog-product-availability">
            Còn {{ product.stock }} sản phẩm
          </span>
          <span>Lượt xem {{ viewCount }}</span>
          <span>Lượt bán {{ salesCount }}</span>
        </p>
      </RouterLink>

      <!-- Hai nút như Stitch: "Xem" (viền) và "Chọn mua" (đồng); cả hai dẫn tới trang chi tiết để chọn phiên bản. -->
      <div class="catalog-product-actions">
        <RouterLink
          class="catalog-product-action catalog-product-action--view"
          :to="{ name: 'product-detail', params: { slug: product.slug } }"
          :aria-label="`Xem chi tiết ${product.name}`"
          :title="`Xem chi tiết ${product.name}`"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
          </svg>
          <span>Xem</span>
        </RouterLink>
        <RouterLink
          class="catalog-product-action catalog-product-action--cart"
          :to="{ name: 'product-detail', params: { slug: product.slug } }"
          :aria-label="`Chọn mua ${product.name}`"
          :title="`Chọn mua ${product.name}`"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" />
            <path d="M3 6h18" />
            <path d="M16 10a4 4 0 0 1-8 0" />
          </svg>
          <span>Chọn mua</span>
        </RouterLink>
      </div>
    </div>
  </article>
</template>

<style scoped>
/* Card sản phẩm theo Stitch: nền trắng, viền kem, bo 8px, bóng nhẹ; hover nhấc lên nhẹ. */
.catalog-product-card {
  display: flex;
  min-width: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 2px 8px rgba(28, 42, 58, 0.08);
  transition: transform 180ms ease, box-shadow 180ms ease;
}

.catalog-product-card:hover {
  box-shadow: 0 4px 12px rgba(28, 42, 58, 0.12);
  transform: translateY(-2px);
}

.catalog-product-media {
  position: relative;
  display: block;
  aspect-ratio: 4 / 5;
  overflow: hidden;
  background: #f7f4ee;
}

.catalog-product-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 400ms ease;
}

.catalog-product-card:hover .catalog-product-image {
  transform: scale(1.04);
}

.catalog-product-image-fallback {
  display: grid;
  width: 100%;
  height: 100%;
  place-items: center;
  color: #b8893e;
  font-family: var(--font-display);
  font-size: 32px;
  letter-spacing: 0.1em;
}

.catalog-product-body {
  display: flex;
  min-width: 0;
  flex: 1;
  flex-direction: column;
  justify-content: space-between;
  gap: 14px;
  padding: 16px;
}

.catalog-product-info {
  display: grid;
  min-width: 0;
  gap: 6px;
  color: inherit;
}

.catalog-product-info p {
  margin: 0;
}

.catalog-product-category {
  color: #b8893e;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}

.catalog-product-name {
  display: -webkit-box;
  margin: 0;
  overflow: hidden;
  color: #1f1f1f;
  font-family: var(--font-display);
  font-size: 17px;
  font-weight: 600;
  line-height: 1.3;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-info:hover .catalog-product-name {
  color: #8c6a32;
}

.catalog-product-description {
  display: -webkit-box;
  overflow: hidden;
  color: #6b6560;
  font-size: 13px;
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-price strong {
  color: #b8893e;
  font-size: 17px;
  font-weight: 700;
}

.catalog-product-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  color: #6b6560;
  font-size: 11px;
}

.catalog-product-code {
  color: #1c2a3a;
  font-weight: 600;
}

.catalog-product-availability {
  color: #2e7d4f;
  font-weight: 600;
}

.catalog-product-availability--out {
  color: #b3261e;
}

/* Hai nút cùng hàng, chia đôi chiều ngang, ngăn cách với phần thông tin bằng viền trên. */
.catalog-product-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid #e6e0d4;
}

.catalog-product-action {
  display: inline-flex;
  min-height: 38px;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  transition: background-color 140ms ease, color 140ms ease, border-color 140ms ease;
}

.catalog-product-action svg {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.catalog-product-action--view {
  border: 1px solid #e6e0d4;
  background: #f7f4ee;
  color: #1f1f1f;
}

.catalog-product-action--view:hover {
  border-color: #1c2a3a;
  background: #efeae0;
}

.catalog-product-action--cart {
  border: 1px solid #b8893e;
  background: #b8893e;
  color: #ffffff;
}

.catalog-product-action--cart:hover {
  border-color: #9e7432;
  background: #9e7432;
}

.catalog-product-action:focus-visible,
.catalog-product-info:focus-visible,
.catalog-product-media:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

@media (max-width: 575.98px) {
  .catalog-product-body {
    padding: 12px;
  }

  .catalog-product-name {
    font-size: 15px;
  }

  .catalog-product-action span {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .catalog-product-card,
  .catalog-product-image {
    transition: none;
  }

  .catalog-product-card:hover,
  .catalog-product-card:hover .catalog-product-image {
    transform: none;
  }
}
</style>
