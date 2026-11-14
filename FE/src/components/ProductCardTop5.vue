<script setup>
// Thẻ Top 5 theo Stitch "Bán Chạy": huy hiệu hạng, thông tin luôn hiện, hai CTA trong lưới 2 cột.
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
// Giá hiển thị là giá thấp nhất của các biến thể đang bán; có nhiều mức giá thì thêm "Từ".
// Không có biến thể đang bán (min null) thì hiện "Liên hệ" thay vì ₫0.
const priceText = computed(() => {
  const min = props.product.min_final_price
  if (min === null || min === undefined) return 'Liên hệ'
  const max = Number(props.product.max_final_price)
  const prefix = Number.isFinite(max) && max > Number(min) ? 'Từ ' : ''
  return prefix + formatPrice(Number(min))
})
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
  <article class="top5-card">
    <RouterLink
      class="top5-card-media"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <img
        v-if="imageSrc && !imageFailed"
        class="top5-card-image"
        :src="imageSrc"
        :alt="product.name"
        loading="lazy"
        @error="imageFailed = true"
      >
      <div v-else class="top5-card-image-fallback" aria-hidden="true">SB</div>
      <span class="top5-card-rank">#{{ String(rank).padStart(2, '0') }}</span>
    </RouterLink>

    <RouterLink
      class="top5-card-content"
      :to="{ name: 'product-detail', params: { slug: product.slug } }"
      :aria-label="`Xem chi tiết ${product.name}`"
    >
      <p class="top5-card-category">{{ product.category_name || 'FuLe BaberShop' }}</p>
      <h2 class="top5-card-name">{{ product.name }}</h2>
      <p v-if="product.description" class="top5-card-description">{{ product.description }}</p>

      <div class="top5-card-price">
        <strong>{{ priceText }}</strong>
      </div>

      <p v-if="isOutOfStock" class="top5-card-availability top5-card-availability--out" role="status">
        Hết hàng
      </p>
      <p v-else-if="product.stock !== null && product.stock !== undefined" class="top5-card-availability">
        Còn {{ product.stock }} sản phẩm
      </p>

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
    </RouterLink>

    <!-- Hai icon đều dẫn tới trang chi tiết; nằm ngoài link bao nội dung để không lồng link. -->
    <div class="top5-card-actions">
      <RouterLink
        class="top5-card-action top5-card-view"
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
        class="top5-card-action top5-card-cart"
        :to="{ name: 'product-detail', params: { slug: product.slug } }"
        :aria-label="`Xem chi tiết ${product.name}`"
        :title="`Xem chi tiết ${product.name}`"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" />
          <path d="M3 6h18" />
          <path d="M16 10a4 4 0 0 1-8 0" />
        </svg>
      </RouterLink>
    </div>
  </article>
</template>

<style scoped>
.top5-card {
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

.top5-card:hover {
  border-color: rgb(245 206 114 / 80%);
  box-shadow: 0 12px 32px -8px rgb(0 0 0 / 85%), 0 0 24px rgb(223 183 108 / 30%);
  transform: translateY(-4px);
}

.top5-card-media {
  position: relative;
  display: block;
  aspect-ratio: 4 / 5;
  overflow: hidden;
  background: #0e0e10;
}

.top5-card-image,
.top5-card-image-fallback {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 500ms ease;
}

.top5-card:hover .top5-card-image {
  transform: scale(1.05);
}

.top5-card-image-fallback {
  display: grid;
  place-items: center;
  color: #9a8f80;
  font-size: 32px;
  font-weight: 700;
}

.top5-card-rank {
  position: absolute;
  top: 12px;
  left: 12px;
  padding: 4px 10px;
  border-radius: 2px;
  background: #c5a059;
  color: #4e3700;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.top5-card-content {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 6px;
  padding: 16px 16px 12px;
  color: inherit;
  text-decoration: none;
}

.top5-card-category {
  margin: 0;
  color: #e9c176;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

.top5-card-name {
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

.top5-card-description {
  display: -webkit-box;
  margin: 0;
  overflow: hidden;
  color: #d1c5b4;
  font-size: 12px;
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 1;
}

.top5-card-price {
  margin-top: 4px;
  color: #e9c176;
  font-size: 18px;
}

.top5-card-availability {
  margin: 0;
  color: #9a8f80;
  font-size: 11px;
}

.top5-card-availability--out {
  color: #ffb4ab;
  font-weight: 700;
}

.top5-card-stats {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: auto;
  padding-top: 10px;
  border-top: 1px solid rgb(78 70 57 / 60%);
}

.top5-card-stat {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 6px 8px;
  border-radius: 2px;
  background: #1b1b1d;
}

.top5-card-stat strong {
  color: #ede0d4;
  font-size: 14px;
}

.top5-card-stat span {
  color: #9a8f80;
  font-size: 10px;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.top5-card-stat.is-ranked {
  background: rgb(197 160 89 / 18%);
}

.top5-card-stat.is-ranked strong {
  color: #e9c176;
}

.top5-card-actions {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  padding: 0 16px 16px;
}

.top5-card-action {
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

.top5-card-action svg {
  width: 16px;
  height: 16px;
  flex: 0 0 16px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.top5-card-view {
  border-color: rgb(78 70 57 / 40%);
  background: #2a2a2c;
  color: #ede0d4;
}

.top5-card-view:hover {
  border-color: #dfb76c;
  color: #fce08e;
}

.top5-card-cart {
  background: #c5a059;
  color: #4e3700;
}

.top5-card-cart:hover {
  background: #e9c176;
}

.top5-card-action:focus-visible,
.top5-card-content:focus-visible,
.top5-card-media:focus-visible {
  outline: 2px solid #e9c176;
  outline-offset: 2px;
}

@media (max-width: 575.98px) {
  .top5-card-rank {
    top: 8px;
    left: 8px;
    padding: 3px 7px;
    font-size: 10px;
  }

  .top5-card-content {
    gap: 4px;
    padding: 12px 12px 10px;
  }

  .top5-card-name {
    font-size: 14px;
  }

  .top5-card-description {
    display: none;
  }

  .top5-card-actions {
    padding: 0 12px 12px;
  }

  .top5-card-action {
    height: 34px;
    padding: 0 4px;
    font-size: 10px;
    letter-spacing: 0.04em;
  }
}

@media (prefers-reduced-motion: reduce) {
  .top5-card,
  .top5-card-image,
  .top5-card-action {
    transition: none;
  }

  .top5-card:hover,
  .top5-card:hover .top5-card-image {
    transform: none;
  }
}
</style>
