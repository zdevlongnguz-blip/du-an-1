<script setup>
import { RouterView, useRoute } from 'vue-router'
import { onMounted, ref, watch } from 'vue'
import { isAuthenticated } from '@/helpers/auth/session'
import { cartCount, refreshCartCount } from '@/helpers/cart/cartCount'
import { useToast } from '@/helpers/ui/toast'

const checkAuth = ref(isAuthenticated())
// Trạng thái mở menu điều hướng trên màn hình nhỏ.
const menuOpen = ref(false)
const route = useRoute()
const { toast, showToast, dismissToast } = useToast()
const currentYear = new Date().getFullYear()

// Đồng bộ trạng thái đăng nhập và số lượng giỏ để menu cập nhật sau login, logout hoặc chuyển trang.
watch(() => route.fullPath, () => {
  checkAuth.value = isAuthenticated()
  menuOpen.value = false
  refreshCartCount()
})

// Dùng toast hệ thống cho lời chào; giữ lại thông báo khác nếu route đã tạo trước đó.
onMounted(() => {
  if (!toast.value) {
    showToast('Chào mừng đến với FuLe BaberShop.', 'info')
  }
  refreshCartCount()
})
</script>

<template>
  <div class="site-shell">
    <div class="site-sticky-stack">
      <!-- Navbar và alert dùng chung luôn nằm cùng nhau khi cuộn trang. -->
      <header class="site-header">
        <nav class="site-navigation" aria-label="Điều hướng chính">
          <router-link class="site-brand" to="/" aria-label="FuLe BaberShop, trang chủ">
            FULE BABERSHOP
          </router-link>

          <div class="site-nav-primary" aria-label="Điều hướng cửa hàng">
            <router-link
              class="site-nav-link site-nav-home"
              to="/"
              :aria-current="route.path === '/' ? 'page' : undefined"
            >Home</router-link>
            <router-link
              class="site-nav-link site-nav-catalog"
              to="/catalog"
              :aria-current="route.path === '/catalog' ? 'page' : undefined"
            >Catalog</router-link>
            <span class="site-nav-placeholder">Services</span>
            <span class="site-nav-placeholder">Barbers</span>
            <span class="site-nav-placeholder">Book now</span>
          </div>

          <div class="site-nav-account">
            <template v-if="!checkAuth">
              <router-link class="site-nav-link" to="/login" :aria-current="route.path === '/login' ? 'page' : undefined">Login</router-link>
              <span class="site-nav-divider" aria-hidden="true"></span>
              <router-link class="site-nav-link" to="/register" :aria-current="route.path === '/register' ? 'page' : undefined">Register</router-link>
            </template>
            <router-link
              v-if="checkAuth"
              class="site-nav-link site-cart-link"
              to="/cart"
              :aria-current="route.path === '/cart' ? 'page' : undefined"
              :aria-label="`Giỏ hàng, ${cartCount} sản phẩm`"
            >
              <span>Giỏ hàng</span>
              <span v-if="cartCount > 0" class="site-cart-count" aria-hidden="true">{{ cartCount }}</span>
            </router-link>
            <router-link
              v-if="checkAuth"
              class="site-nav-link"
              to="/orders"
              :aria-current="route.path.startsWith('/orders') ? 'page' : undefined"
            >Đơn hàng</router-link>
            <router-link
              class="site-account-button"
              :to="checkAuth ? '/profile' : '/login'"
              :class="{ 'is-active': checkAuth && ['/profile', '/change-password'].includes(route.path) }"
              :aria-label="checkAuth ? 'Hồ sơ tài khoản' : 'Đăng nhập tài khoản'"
              :title="checkAuth ? 'Hồ sơ tài khoản' : 'Đăng nhập tài khoản'"
            >
              <span class="site-account-icon" aria-hidden="true"></span>
            </router-link>
          </div>

          <button
            type="button"
            class="site-menu-toggle"
            :aria-expanded="menuOpen"
            aria-controls="mobile-navigation"
            :aria-label="menuOpen ? 'Đóng menu' : 'Mở menu'"
            :title="menuOpen ? 'Đóng menu' : 'Mở menu'"
            @click="menuOpen = !menuOpen"
          >
            <span class="site-menu-icon" :class="{ 'site-menu-icon--open': menuOpen }" aria-hidden="true">
              <span></span>
              <span></span>
              <span></span>
            </span>
          </button>
        </nav>

        <!-- Menu nhỏ toàn chiều ngang, tài khoản được đặt ở cuối danh sách. -->
        <div v-if="menuOpen" id="mobile-navigation" class="site-mobile-menu">
          <div class="site-mobile-menu-inner">
            <div class="site-mobile-links" aria-label="Điều hướng cửa hàng">
              <router-link
                class="site-mobile-link site-mobile-home"
                to="/"
                :aria-current="route.path === '/' ? 'page' : undefined"
              >Home</router-link>
              <router-link
                class="site-mobile-link site-mobile-catalog"
                to="/catalog"
                :aria-current="route.path === '/catalog' ? 'page' : undefined"
              >Catalog</router-link>
              <span class="site-mobile-link site-nav-placeholder">Services</span>
              <span class="site-mobile-link site-nav-placeholder">Barbers</span>
              <span class="site-mobile-link site-nav-placeholder">Book now</span>
            </div>
            <div class="site-mobile-account">
              <template v-if="!checkAuth">
                <router-link class="site-mobile-link" to="/login" :aria-current="route.path === '/login' ? 'page' : undefined">Login</router-link>
                <router-link class="site-mobile-link" to="/register" :aria-current="route.path === '/register' ? 'page' : undefined">Register</router-link>
              </template>
              <router-link
                v-else
                class="site-mobile-link site-mobile-cart"
                to="/cart"
                :aria-current="route.path === '/cart' ? 'page' : undefined"
              >
                <span>Giỏ hàng</span>
                <span v-if="cartCount > 0" class="site-cart-count" aria-hidden="true">{{ cartCount }}</span>
              </router-link>
              <router-link
                v-if="checkAuth"
                class="site-mobile-link"
                to="/orders"
                :aria-current="route.path.startsWith('/orders') ? 'page' : undefined"
              >Đơn hàng</router-link>
              <router-link
                v-if="checkAuth"
                class="site-mobile-link"
                to="/profile"
                :aria-current="['/profile', '/change-password'].includes(route.path) ? 'page' : undefined"
              >Profile</router-link>
            </div>
          </div>
        </div>
      </header>

      <!-- Toast lỗi/thành công dùng chung cũng nằm trong vùng sticky. -->
      <div
        v-if="toast"
        class="site-toast"
        :class="`site-toast--${toast.type}`"
        :role="toast.type === 'danger' ? 'alert' : 'status'"
        aria-live="polite"
        aria-atomic="true"
      >
        <span class="site-toast-copy">
          <strong class="site-toast-label">Thông báo</strong>
          <span class="site-toast-message">{{ toast.message }}</span>
        </span>
        <button type="button" class="site-toast-close" aria-label="Đóng thông báo" @click="dismissToast">
          &times;
        </button>
      </div>
    </div>

    <!-- Vùng hiển thị toast toàn cục và view theo route hiện tại. -->
    <main class="site-main">
      <RouterView />
    </main>

    <!-- Footer chung cho trang chủ và các màn hình tài khoản. -->
    <footer class="site-footer">
      <p>
        <span>&copy; {{ currentYear }} FuLe BaberShop.</span>
        <span>All rights reserved.</span>
      </p>
    </footer>
  </div>
</template>
