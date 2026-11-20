<script setup>
// Menu bên trái của khu quản trị (dùng trong AdminLayout). Không gọi API.
// Thêm trang admin mới: thêm route con trong router/index.js (nhóm /admin) rồi thêm một mục vào ADMIN_MENU bên dưới.
import { RouterLink, useRoute } from 'vue-router'

// emit: báo cho AdminLayout biết khách vừa bấm một mục, để đóng menu trên điện thoại.
const emit = defineEmits(['navigate'])
const route = useRoute()

// Danh sách mục menu. to: tên route (name) trong router; exact: chỉ sáng khi đúng trang đó (dùng cho Tổng quan).
// Mục chưa có trang thì để ready: false: hiện mờ, không bấm được, để nhóm thấy việc còn lại.
const ADMIN_MENU = [
  { label: 'Tổng quan', to: { name: 'admin-dashboard' }, exact: true, ready: true },
  { label: 'Sản phẩm', to: null, ready: false },
  { label: 'Danh mục', to: null, ready: false },
  { label: 'Đơn hàng', to: null, ready: false },
  { label: 'Bình luận', to: null, ready: false },
  { label: 'Banner', to: null, ready: false },
]

// true nếu mục menu ứng với trang đang mở. Mục không exact thì sáng cho cả các trang con
// (ví dụ "Sản phẩm" sáng ở /admin/products và /admin/products/5/edit).
function isActive(item) {
  if (!item.ready || !item.to) return false
  const target = item.to.name
  if (item.exact) return route.name === target
  return route.matched.some((record) => record.name === target)
}
</script>

<template>
  <nav class="admin-sidebar" aria-label="Điều hướng quản trị">
    <!-- Logo + nhãn khu quản trị -->
    <RouterLink class="admin-brand" :to="{ name: 'admin-dashboard' }" @click="emit('navigate')">
      <span class="admin-brand-name">FULE</span>
      <span class="admin-brand-tag">Quản trị</span>
    </RouterLink>

    <!-- Danh sách mục quản trị; mục chưa làm hiện mờ kèm chữ "Sắp có" -->
    <ul class="admin-menu">
      <li v-for="item in ADMIN_MENU" :key="item.label">
        <RouterLink
          v-if="item.ready"
          class="admin-menu-link"
          :class="{ 'is-active': isActive(item) }"
          :to="item.to"
          :aria-current="isActive(item) ? 'page' : undefined"
          @click="emit('navigate')"
        >{{ item.label }}</RouterLink>
        <span v-else class="admin-menu-link is-disabled" aria-disabled="true">
          {{ item.label }}
          <small>Sắp có</small>
        </span>
      </li>
    </ul>

    <!-- Quay lại giao diện cửa hàng (layout shop có header, giỏ hàng, khung chat) -->
    <RouterLink class="admin-back-link" to="/" @click="emit('navigate')">← Về cửa hàng</RouterLink>
  </nav>
</template>

<style scoped>
.admin-sidebar {
  display: flex;
  height: 100%;
  flex-direction: column;
  gap: 24px;
  padding: 24px 16px;
  background: #131e2a;
  color: #f7f4ee;
}

.admin-brand {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 0 8px;
  color: #f7f4ee;
  text-decoration: none;
}

.admin-brand-name {
  font-family: var(--font-display);
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.admin-brand-tag {
  color: #b8893e;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.admin-menu {
  display: grid;
  flex: 1;
  align-content: start;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.admin-menu-link {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border-radius: 6px;
  color: #d9d4ca;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
}

.admin-menu-link:hover:not(.is-disabled) {
  background: #25364a;
  color: #ffffff;
}

.admin-menu-link.is-active {
  background: #b8893e;
  color: #ffffff;
}

.admin-menu-link:focus-visible,
.admin-back-link:focus-visible,
.admin-brand:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.admin-menu-link.is-disabled {
  color: #6b6560;
  cursor: not-allowed;
}

.admin-menu-link small {
  font-size: 11px;
}

.admin-back-link {
  padding: 10px 12px;
  border: 1px solid #25364a;
  border-radius: 6px;
  color: #d9d4ca;
  font-size: 13px;
  text-align: center;
  text-decoration: none;
}

.admin-back-link:hover {
  border-color: #b8893e;
  color: #ffffff;
}
</style>
