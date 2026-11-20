<script setup>
// Trang Tổng quan của khu quản trị (/admin). Hiện chưa gọi API: chỉ giới thiệu các mục quản trị và trạng thái.
// Khi các trang admin con được làm xong, đổi ready = true và điền to (tên route) để thẻ bấm được.
import { RouterLink } from 'vue-router'

// Các mục quản trị. api: các API BE đã có sẵn cho mục đó (để người làm task biết dùng gì).
const SECTIONS = [
  { title: 'Sản phẩm', desc: 'Thêm, sửa, xóa sản phẩm và ảnh.', api: 'api-admin-product-*', to: null, ready: false },
  { title: 'Danh mục', desc: 'Quản lý danh mục sản phẩm.', api: 'api-admin-category-*', to: null, ready: false },
  { title: 'Đơn hàng', desc: 'Xem và cập nhật trạng thái đơn.', api: 'api-admin-orders, api-admin-order-update', to: null, ready: false },
  { title: 'Bình luận', desc: 'Duyệt hoặc xóa bình luận.', api: 'api-admin-comment-*', to: null, ready: false },
  { title: 'Banner', desc: 'Quản lý banner trang chủ.', api: 'api-admin-banner-*', to: null, ready: false },
]
</script>

<template>
  <section class="admin-dashboard" aria-labelledby="admin-dashboard-title">
    <!-- Tiêu đề trang -->
    <header class="admin-page-head">
      <h1 id="admin-dashboard-title">Tổng quan</h1>
      <p>Khu quản trị FuLe BaberShop. Chọn một mục để bắt đầu.</p>
    </header>

    <!-- Lưới thẻ các mục quản trị; mục chưa có trang hiện nhãn "Sắp có" và không bấm được -->
    <ul class="admin-cards">
      <li v-for="section in SECTIONS" :key="section.title">
        <RouterLink v-if="section.ready" class="admin-card" :to="section.to">
          <h2>{{ section.title }}</h2>
          <p>{{ section.desc }}</p>
        </RouterLink>
        <div v-else class="admin-card is-disabled" aria-disabled="true">
          <h2>{{ section.title }} <span class="admin-card-tag">Sắp có</span></h2>
          <p>{{ section.desc }}</p>
          <code>{{ section.api }}</code>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.admin-page-head h1 {
  margin: 0 0 6px;
  color: #131e2a;
  font-family: var(--font-display);
  font-size: 28px;
}

.admin-page-head p {
  margin: 0 0 24px;
  color: #6b6560;
}

.admin-cards {
  display: grid;
  gap: 16px;
  margin: 0;
  padding: 0;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  list-style: none;
}

.admin-card {
  display: block;
  height: 100%;
  padding: 18px;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  color: inherit;
  text-decoration: none;
}

a.admin-card:hover {
  border-color: #b8893e;
}

.admin-card h2 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 6px;
  color: #1c2a3a;
  font-size: 17px;
  font-weight: 700;
}

.admin-card p {
  margin: 0 0 10px;
  color: #4a5565;
  font-size: 14px;
}

.admin-card code {
  color: #6b6560;
  font-size: 12px;
}

.admin-card.is-disabled {
  background: #efeae0;
}

.admin-card-tag {
  padding: 2px 8px;
  border-radius: 4px;
  background: #ffffff;
  color: #6b6560;
  font-size: 11px;
  font-weight: 600;
}
</style>
