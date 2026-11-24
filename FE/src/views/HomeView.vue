<script setup>
import { onMounted, ref } from 'vue'
import BannerSlideshow from '@/components/BannerSlideshow.vue'
import ProductCardTop5 from '@/components/ProductCardTop5.vue'
import StateBlock from '@/components/StateBlock.vue'
import api from '@/helpers/api/client'

// Trang chủ: tải banner và top 5 sản phẩm từ các API công khai.

// Danh sách banner do API trả về; BannerSlideshow tự quản lý slide đang chọn.
const banners = ref([])
// Bắt đầu ở trạng thái tải để tránh nháy giao diện rỗng trước request đầu tiên.
const isLoadingBanners = ref(true)
// Lỗi tải banner được hiển thị riêng, không giả thành danh sách rỗng.
const bannerError = ref('')
// Mặc định hiển thị sản phẩm bán chạy; lựa chọn này khớp với API nếu thiếu sort_by.
const sortBy = ref('sales')
// Danh sách top 5 theo chế độ sortBy đang chọn.
const topProducts = ref([])
// Trạng thái của request top 5, tách biệt với request banner.
const isLoadingTopProducts = ref(true)
// Lỗi tải top 5 để hiển thị riêng, không giả thành danh sách rỗng.
const topProductsError = ref('')

// Tải banner khi trang chủ mở và khi người dùng bấm thử lại.
async function fetchBanners() {
  isLoadingBanners.value = true
  bannerError.value = ''

  try {
    // api-banners trả về status và mảng banners; image có thể null.
    const response = await api.get('/?action=api-banners')

    if (response.data?.status !== 'success' || !Array.isArray(response.data.banners)) {
      throw new Error('Dữ liệu banner không hợp lệ.')
    }

    banners.value = response.data.banners
  } catch (error) {
    bannerError.value = error.response?.data?.message || error.message || 'Không tải được banner.'
  } finally {
    isLoadingBanners.value = false
  }
}

// Tải top 5 theo chế độ được chọn; gọi khi mở trang, đổi chế độ hoặc thử lại.
async function fetchTopProducts(nextSortBy = sortBy.value) {
  sortBy.value = nextSortBy
  isLoadingTopProducts.value = true
  topProductsError.value = ''

  try {
    // api-products-top trả sort_by và mảng products; lỗi HTTP được lấy từ response BE.
    const response = await api.get('/?action=api-products-top', {
      params: { sort_by: nextSortBy },
    })

    if (
      response.data?.status !== 'success'
      || response.data.sort_by !== nextSortBy
      || !Array.isArray(response.data.products)
    ) {
      throw new Error('Dữ liệu top sản phẩm không hợp lệ.')
    }

    topProducts.value = response.data.products
  } catch (error) {
    topProductsError.value = error.response?.data?.message || error.message || 'Không tải được top sản phẩm.'
  } finally {
    isLoadingTopProducts.value = false
  }
}

// Tải banner và top 5 độc lập khi trang chủ vừa hiển thị.
onMounted(() => {
  fetchBanners()
  fetchTopProducts()
})
</script>

<template>
  <div class="home-view">
    <!-- Carousel chỉ hiện khi API tải thành công và có banner. -->
    <BannerSlideshow v-if="banners.length > 0 && !isLoadingBanners && !bannerError" :banners="banners" />

    <!-- Trạng thái chờ được giữ riêng với trạng thái danh sách rỗng. -->
    <section v-else-if="isLoadingBanners" class="home-banner-state" role="status" aria-live="polite">
      Đang tải banner...
    </section>

    <!-- Lỗi có nút tải lại để người dùng thử lại mà không cần tải lại trang. -->
    <section v-else-if="bannerError" class="home-banner-state home-banner-state--error" role="alert">
      <p>{{ bannerError }}</p>
      <button type="button" @click="fetchBanners">Thử lại</button>
    </section>

    <!-- API thành công nhưng chưa có banner. -->
    <section v-else class="home-banner-state" aria-label="Banner">
      Hiện chưa có banner.
    </section>

    <!-- Tiêu đề và hai chế độ xếp hạng top 5. -->
    <section class="home-products-section" aria-labelledby="top-products-title">
      <div class="home-section-heading">
        <h1 id="top-products-title">Top 5 sản phẩm</h1>
      </div>

      <!-- Segmented control chuyển giữa sản phẩm xem nhiều và bán chạy. -->
      <div class="top-products-toolbar">
        <p class="top-products-description">Xem kỹ những sản phẩm mọi người đang chọn.</p>
        <div class="top-products-modes" role="group" aria-label="Sắp xếp sản phẩm">
          <button
            type="button"
            :class="{ 'is-active': sortBy === 'views' }"
            :aria-pressed="sortBy === 'views'"
            :disabled="isLoadingTopProducts"
            @click="fetchTopProducts('views')"
          >
            Xem nhiều
          </button>
          <button
            type="button"
            :class="{ 'is-active': sortBy === 'sales' }"
            :aria-pressed="sortBy === 'sales'"
            :disabled="isLoadingTopProducts"
            @click="fetchTopProducts('sales')"
          >
            Bán chạy
          </button>
        </div>
      </div>

      <!-- StateBlock (component dùng chung): đang tải -> lỗi có nút "Thử lại" -> rỗng -> lưới sản phẩm.
           Lỗi API luôn hiện thành lỗi, không bị hiểu nhầm là "chưa có sản phẩm". -->
      <StateBlock
        :loading="isLoadingTopProducts"
        :error="topProductsError"
        :empty="topProducts.length === 0"
        loading-text="Đang tải top sản phẩm..."
        empty-text="Chưa có top sản phẩm để hiển thị."
        :on-retry="() => fetchTopProducts()"
      >
        <!-- Mỗi sản phẩm dùng chung card top 5 với thứ hạng và chỉ số hiện tại. -->
        <!-- data-count để CSS chọn bố cục theo số sản phẩm (đủ 5 thì hạng 1 chiếm khối lớn), tránh ô bị lẻ. -->
        <div class="top-products-grid" :data-count="topProducts.length">
          <ProductCardTop5
            v-for="(product, index) in topProducts"
            :key="product.id"
            :product="product"
            :rank="index + 1"
            :sort-by="sortBy"
          />
        </div>
      </StateBlock>
    </section>
  </div>
</template>

<style scoped>
/*
  Giao diện sáng theo Stitch "Fule BarberShop" (bảng Navy & Brass):
  - Nền kem #f7f4ee, chữ navy #131e2a, nhấn đồng #b8893e.
  - Tiêu đề dùng Playfair Display, chữ thường dùng Inter (cả hai có bộ ký tự tiếng Việt).
  - Viền mỏng #d9d4ca, góc vuông (không bo tròn) như thiết kế gốc.
*/

/* Khung trang: căn giữa, có lề trái/phải tối thiểu 16px trên điện thoại. */
/* Không thêm lề trái/phải riêng: dùng đúng chiều rộng .site-main để thẳng hàng với header và toast. */
.home-view {
  padding: 8px 0 24px;
  background: #f7f4ee;
  color: #131e2a;
}

.top-products-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin: 20px 0 22px;
}

.top-products-description {
  margin: 0;
  color: #5b6573;
  font-size: 14px;
}

.top-products-modes {
  display: inline-flex;
  flex: 0 0 auto;
  padding: 3px;
  border: 1px solid #d9d4ca;
  background: #ffffff;
}

.top-products-modes button {
  min-height: 38px;
  padding: 0 14px;
  border: 0;
  background: transparent;
  color: #1c2a3a;
  cursor: pointer;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  transition: background-color 160ms ease, color 160ms ease;
}

/* Nút đang chọn: nền navy, chữ kem để nổi bật. */
.top-products-modes button.is-active {
  background: #1c2a3a;
  color: #f7f4ee;
}

.top-products-modes button:disabled:not(.is-active) {
  cursor: wait;
  opacity: 0.65;
}

.top-products-modes button:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

/*
  Lưới Top 5:
  - Điện thoại: hạng 1 rộng hết hàng, 4 thẻ còn lại xếp 2 cột.
  - Từ 768px (đủ 5 sản phẩm): 4 cột, hạng 1 chiếm khối 2x2 bên trái, 4 thẻ còn lại 2x2 bên phải.
  - Thiếu sản phẩm (1-4): các thẻ chia đều, tránh lưới bị thủng ô.
  Thẻ tự thu gọn khi hẹp (container query trong ProductCardTop5), nên trang không phải chỉnh từng phần tử bên trong.
*/
.top-products-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.top-products-grid > :first-child {
  grid-column: 1 / -1;
}

.home-banner-state {
  display: grid;
  min-height: 240px;
  place-content: center;
  gap: 12px;
  padding: 24px;
  border: 1px solid #d9d4ca;
  background: #efeae0;
  color: #1c2a3a;
  text-align: center;
}

.home-banner-state p {
  margin: 0;
}

.home-banner-state button {
  justify-self: center;
  min-height: 44px;
  padding: 0 20px;
  border: 1px solid #1c2a3a;
  background: #1c2a3a;
  color: #f7f4ee;
  font-family: inherit;
  font-weight: 600;
  cursor: pointer;
}

.home-banner-state--error {
  border-color: #ba1a1a;
  background: #fdf1f0;
  color: #93000a;
}

/* Tiêu đề phần: serif lớn, có vạch đồng ngắn bên dưới làm điểm nhấn. */
.home-section-heading h1 {
  margin: 0;
  color: #131e2a;
  font-family: var(--font-display);
  font-size: clamp(28px, 4vw, 40px);
  font-weight: 700;
  line-height: 1.15;
}

.home-section-heading h1::after {
  content: '';
  display: block;
  width: 56px;
  height: 2px;
  margin-top: 12px;
  background: #b8893e;
}

@media (min-width: 768px) {
  .top-products-grid {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
  }

  /* Thiếu sản phẩm: hạng 1 không chiếm cả hàng nữa, mọi thẻ rộng bằng nhau. */
  .top-products-grid > :first-child {
    grid-column: auto;
  }

  /* Đủ 5 sản phẩm: bố cục 4 cột, hạng 1 chiếm 2 cột x 2 hàng. */
  .top-products-grid[data-count="5"] {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .top-products-grid[data-count="5"] > :first-child {
    grid-column: 1 / span 2;
    grid-row: 1 / span 2;
  }

  /* Ô hạng 1 cao bằng 2 hàng: cho ảnh giãn lấp phần cao dư, không để khoảng trắng dưới chữ.
     :deep(...) cho phép trang cha chỉnh phần tử bên trong component ProductCardTop5. */
  .top-products-grid[data-count="5"] > :first-child :deep(.top5-card-media) {
    flex: 1 1 auto;
    min-height: 240px;
    aspect-ratio: auto;
  }

  .top-products-grid[data-count="5"] > :first-child :deep(.top5-card-content) {
    flex: 0 0 auto;
  }

  .home-banner-state {
    min-height: 320px;
  }
}

@media (min-width: 1200px) {
  .home-banner-state {
    min-height: 400px;
  }
}

@media (max-width: 767.98px) {
  .top-products-toolbar {
    align-items: flex-start;
    flex-direction: column;
    gap: 14px;
  }

  .top-products-modes {
    width: 100%;
  }

  .top-products-modes button {
    flex: 1;
  }
}

/* Dòng phụ màu đồng phía trên tiêu đề phần, theo Stitch. */
.home-section-heading {
  display: grid;
  gap: 6px;
}

.home-section-heading h1 {
  text-transform: uppercase;
}

/* Nút chọn chế độ đang bật: nền đồng chữ trắng như tab đang chọn trong Stitch. */
.top-products-modes button.is-active {
  background: #b8893e;
  color: #ffffff;
}

.top-products-modes,
.home-banner-state {
  border-color: #e6e0d4;
  border-radius: 8px;
}

.home-banner-state button {
  border-color: #b8893e;
  border-radius: 6px;
  background: #b8893e;
  color: #ffffff;
}

</style>
