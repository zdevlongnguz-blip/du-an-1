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
        </RouterLink>
      </div>
    </div>
  </article>
</template>

<style scoped>
.catalog-product-card {
  display: flex;
  min-width: 0;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid rgb(197 160 89 / 35%);
  border-radius: 2px;
  background: #1f1f21;
  box-shadow: 0 4px 16px rgb(0 0 0 / 50%);
  color: #ede0d4;
  transition: transform 350ms ease, border-color 350ms ease, box-shadow 350ms ease;
}

.catalog-product-card:hover {
  border-color: rgb(245 206 114 / 80%);
  box-shadow: 0 12px 32px -8px rgb(0 0 0 / 85%), 0 0 24px rgb(223 183 108 / 30%);
  transform: translateY(-4px);
}

.catalog-product-media {
  position: relative;
  display: grid;
  width: 100%;
  aspect-ratio: 4 / 5;
  overflow: hidden;
  place-items: center;
  background: #0e0e10;
}

.catalog-product-image,
.catalog-product-image-fallback {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 500ms ease;
}

.catalog-product-card:hover .catalog-product-image {
  transform: scale(1.05);
}

.catalog-product-image-fallback {
  display: grid;
  place-items: center;
  color: #9a8f80;
  font-size: 32px;
  font-weight: 700;
}

.catalog-product-body {
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
  padding: 16px;
}

.catalog-product-info {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: inherit;
  text-decoration: none;
}

.catalog-product-category {
  margin: 0;
  color: #e9c176;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.catalog-product-name {
  display: -webkit-box;
  min-height: 2.8em;
  margin: 0;
  overflow: hidden;
  color: #ede0d4;
  font-size: 16px;
  font-weight: 600;
  line-height: 1.4;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-product-description {
  display: -webkit-box;
  margin: 0;
  overflow: hidden;
  color: #d1c5b4;
  font-size: 12px;
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
}

.catalog-product-price {
  margin: 4px 0 0;
  color: #e9c176;
  font-size: 18px;
}

.catalog-product-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin: 0;
  color: #9a8f80;
  font-size: 11px;
}

.catalog-product-availability--out {
  color: #ffb4ab;
  font-weight: 700;
}

.catalog-product-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.catalog-product-action {
  display: flex;
  height: 36px;
  min-width: 0;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 8px;
  border: 1px solid transparent;
  border-radius: 2px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-decoration: none;
  text-transform: uppercase;
  transition: background-color 200ms ease, border-color 200ms ease, color 200ms ease;
}

.catalog-product-action svg {
  width: 16px;
  height: 16px;
  flex: 0 0 16px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.catalog-product-action--view {
  border-color: rgb(78 70 57 / 40%);
  background: #2a2a2c;
  color: #ede0d4;
}

.catalog-product-action--view:hover {
  border-color: #dfb76c;
  color: #fce08e;
}

.catalog-product-action--cart {
  background: #c5a059;
  color: #4e3700;
}

.catalog-product-action--cart:hover {
  background: #e9c176;
}

.catalog-product-action:focus-visible,
.catalog-product-info:focus-visible,
.catalog-product-media:focus-visible {
  outline: 2px solid #e9c176;
  outline-offset: 2px;
}

@media (max-width: 575.98px) {
  .catalog-product-body {
    gap: 10px;
    padding: 12px;
  }

  .catalog-product-name {
    font-size: 14px;
  }

  .catalog-product-description {
    display: none;
  }

  .catalog-product-action {
    height: 34px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .catalog-product-card,
  .catalog-product-image,
  .catalog-product-action {
    transition: none;
  }

  .catalog-product-card:hover {
    transform: none;
  }

  .catalog-product-card:hover .catalog-product-image {
    transform: none;
  }
}
</style>
