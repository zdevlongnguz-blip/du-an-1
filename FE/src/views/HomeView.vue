<script setup>
import { onMounted, ref } from 'vue'
import BannerSlideshow from '@/components/BannerSlideshow.vue'
import ProductCardTop5 from '@/components/ProductCardTop5.vue'
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
        <h1 id="top-products-title">Top 5 products</h1>
      </div>

      <!-- Segmented control chuyển giữa sản phẩm xem nhiều và bán chạy. -->
      <div class="top-products-toolbar">
        <p class="top-products-description">A closer look at what everyone is choosing.</p>
        <div class="top-products-modes" role="group" aria-label="Sắp xếp sản phẩm">
          <button
            type="button"
            :class="{ 'is-active': sortBy === 'views' }"
            :aria-pressed="sortBy === 'views'"
            :disabled="isLoadingTopProducts"
            @click="fetchTopProducts('views')"
          >
            Most viewed
          </button>
          <button
            type="button"
            :class="{ 'is-active': sortBy === 'sales' }"
            :aria-pressed="sortBy === 'sales'"
            :disabled="isLoadingTopProducts"
            @click="fetchTopProducts('sales')"
          >
            Best-selling
          </button>
        </div>
      </div>

      <!-- Trạng thái tải, lỗi và danh sách rỗng được trình bày riêng. -->
      <p v-if="isLoadingTopProducts" class="top-products-state" role="status" aria-live="polite">
        Loading top products...
      </p>
      <div v-else-if="topProductsError" class="top-products-state top-products-state--error" role="alert">
        <p>{{ topProductsError }}</p>
        <button type="button" @click="fetchTopProducts()">Try again</button>
      </div>
      <p v-else-if="topProducts.length === 0" class="top-products-state">
        No top products to display yet.
      </p>

      <!-- Mỗi sản phẩm dùng chung card top 5 với thứ hạng và chỉ số hiện tại. -->
      <div v-else class="top-products-grid" :aria-busy="isLoadingTopProducts">
        <ProductCardTop5
          v-for="(product, index) in topProducts"
          :key="product.id"
          :product="product"
          :rank="index + 1"
          :sort-by="sortBy"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.top-products-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin: 20px 0 22px;
}

.top-products-description {
  margin: 0;
  color: #686e73;
  font-size: 14px;
}

.top-products-modes {
  display: inline-flex;
  flex: 0 0 auto;
  padding: 3px;
  border: 1px solid #d7d9db;
  background: #f3f4f5;
}

.top-products-modes button {
  min-height: 38px;
  padding: 0 14px;
  border: 0;
  background: transparent;
  color: #575f67;
  cursor: pointer;
  font-size: 12px;
  font-weight: 600;
  transition: background-color 160ms ease, color 160ms ease;
}

.top-products-modes button.is-active {
  background: #0c1014;
  color: #ffffff;
}

.top-products-modes button:disabled:not(.is-active) {
  cursor: wait;
  opacity: 0.65;
}

.top-products-modes button:focus-visible,
.top-products-state button:focus-visible {
  outline: 3px solid #575f67;
  outline-offset: 2px;
}

.top-products-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 16px;
}

.top-products-state {
  display: grid;
  min-height: 180px;
  place-content: center;
  justify-items: center;
  gap: 12px;
  padding: 24px;
  border: 1px solid #dfe1e2;
  background: #f3f4f5;
  color: #575f67;
  font-size: 14px;
  text-align: center;
}

.top-products-state p {
  margin: 0;
}

.top-products-state--error {
  border-color: #e4b7b7;
  background: #fff5f5;
  color: #8c2525;
}

.top-products-state button {
  min-height: 40px;
  padding: 0 16px;
  border: 1px solid #0c1014;
  background: #0c1014;
  color: #ffffff;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
}

.home-banner-state {
  display: grid;
  min-height: 240px;
  place-content: center;
  gap: 12px;
  padding: 24px;
  border-radius: 4px;
  background: #212529;
  color: #ffffff;
  text-align: center;
}

.home-banner-state p {
  margin: 0;
}

.home-banner-state button {
  justify-self: center;
  min-height: 44px;
  padding: 0 20px;
  border: 1px solid #ffffff;
  border-radius: 4px;
  background: #ffffff;
  color: #212529;
  font-weight: 600;
}

@media (min-width: 768px) {
  .top-products-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
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

@media (min-width: 768px) and (max-width: 991.98px) {
  .top-products-grid {
    grid-template-rows: auto;
  }

  .top-products-grid > :first-child {
    grid-column: 1 / -1;
  }
}

@media (min-width: 992px) {
  .top-products-grid {
    grid-template-columns: repeat(12, minmax(0, 1fr));
    grid-template-rows: clamp(280px, 28vw, 340px) clamp(280px, 28vw, 340px) clamp(210px, 22vw, 270px);
  }

  .top-products-grid > :nth-child(1) {
    grid-column: 1 / span 7;
    grid-row: 1 / span 2;
  }

  .top-products-grid > :nth-child(2) {
    grid-column: 8 / span 5;
    grid-row: 1;
  }

  .top-products-grid > :nth-child(3) {
    grid-column: 8 / span 5;
    grid-row: 2;
  }

  .top-products-grid > :nth-child(4) {
    grid-column: 1 / span 4;
    grid-row: 3;
  }

  .top-products-grid > :nth-child(5) {
    grid-column: 5 / span 8;
    grid-row: 3;
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
</style>
