<script setup>
// Khung chung của khu quản trị (/admin): menu bên trái + vùng nội dung bên phải. Không gọi API.
// Router hiển thị component này cho route /admin; các trang admin là route CON nên hiện trong <RouterView /> bên dưới.
// Header, footer, khung chat của shop được App.vue ẩn khi route có meta.layout = 'admin'; toast vẫn dùng chung.
import { ref, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { getUser } from '@/helpers/auth/session'
import AdminSidebar from '@/components/admin/AdminSidebar.vue'

const route = useRoute()
// Email admin đang đăng nhập (lấy từ auth_user trong localStorage), hiện ở thanh trên cùng.
const adminEmail = getUser()?.email || ''
// Trên điện thoại menu bên trái ẩn đi; true khi khách bấm nút mở menu.
const menuOpen = ref(false)

// watch: chuyển sang trang admin khác thì tự đóng menu (trên điện thoại menu che nội dung).
watch(() => route.fullPath, () => {
  menuOpen.value = false
})
</script>

<template>
  <div class="admin-shell" :class="{ 'is-menu-open': menuOpen }">
    <!-- Menu bên trái: luôn hiện trên màn hình rộng; trên điện thoại trượt ra khi bấm nút "Menu" -->
    <aside id="admin-sidebar" class="admin-shell-sidebar">
      <AdminSidebar @navigate="menuOpen = false" />
    </aside>

    <!-- Lớp nền tối phía sau menu trên điện thoại; bấm vào để đóng menu -->
    <div v-if="menuOpen" class="admin-shell-backdrop" aria-hidden="true" @click="menuOpen = false"></div>

    <div class="admin-shell-main">
      <!-- Thanh trên cùng: nút mở menu (điện thoại) + email admin đang đăng nhập -->
      <header class="admin-topbar">
        <button
          type="button"
          class="admin-menu-toggle"
          aria-controls="admin-sidebar"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >Menu</button>
        <span v-if="adminEmail" class="admin-topbar-user">{{ adminEmail }}</span>
      </header>

      <!-- Trang admin con (Tổng quan, Sản phẩm...) hiện ở đây -->
      <div class="admin-content">
        <RouterView />
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-shell {
  display: grid;
  min-height: 100vh;
  grid-template-columns: 240px minmax(0, 1fr);
  background: #f7f4ee;
}

.admin-shell-sidebar {
  position: sticky;
  top: 0;
  height: 100vh;
}

.admin-shell-main {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.admin-topbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 12px;
  min-height: 56px;
  padding: 0 24px;
  border-bottom: 1px solid #e6e0d4;
  background: #ffffff;
}

.admin-topbar-user {
  overflow: hidden;
  color: #4a5565;
  font-size: 13px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.admin-menu-toggle {
  display: none;
  margin-right: auto;
  padding: 6px 12px;
  border: 1px solid #1c2a3a;
  border-radius: 6px;
  background: #ffffff;
  color: #1c2a3a;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
}

.admin-menu-toggle:focus-visible {
  outline: 3px solid #b8893e;
  outline-offset: 2px;
}

.admin-content {
  flex: 1;
  padding: 24px;
}

.admin-shell-backdrop {
  display: none;
}

/* Điện thoại / tablet dọc: menu thành ngăn kéo trượt từ trái, nội dung chiếm toàn bộ chiều ngang. */
@media (max-width: 991.98px) {
  .admin-shell {
    grid-template-columns: minmax(0, 1fr);
  }

  .admin-shell-sidebar {
    position: fixed;
    z-index: 1100;
    top: 0;
    left: 0;
    width: 260px;
    transform: translateX(-100%);
    transition: transform 180ms ease;
  }

  .is-menu-open .admin-shell-sidebar {
    transform: translateX(0);
  }

  .admin-shell-backdrop {
    position: fixed;
    z-index: 1050;
    display: block;
    inset: 0;
    background: rgb(19 30 42 / 45%);
  }

  .admin-menu-toggle {
    display: inline-block;
  }

  .admin-topbar,
  .admin-content {
    padding-right: 16px;
    padding-left: 16px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .admin-shell-sidebar {
    transition: none;
  }
}
</style>
