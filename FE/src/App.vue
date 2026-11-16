<script setup>
import { RouterView, useRoute } from 'vue-router'
import { onMounted, ref, watch } from 'vue'
import { isAuthenticated } from '@/helpers/auth/session'
import { fetchProfile } from '@/helpers/auth/profile'
import { cartCount, refreshCartCount } from '@/helpers/cart/cartCount'
import { useToast } from '@/helpers/ui/toast'
import ChatAssistant from '@/components/chat/ChatAssistant.vue'

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

// Đồng bộ hồ sơ (họ tên, SĐT, địa chỉ) vào localStorage mỗi khi chuyển sang trạng thái đã đăng nhập:
// lúc vừa tải trang nếu đã đăng nhập sẵn (immediate: true) và ngay sau khi đăng nhập (checkAuth đổi false -> true).
// Đổi trang khi vẫn đăng nhập thì checkAuth không đổi nên watch không chạy lại, tránh gọi API thừa.
// Nhờ vậy lời chào của trợ lý và các trang dùng getUser() có ngay họ tên, không cần mở trang Hồ sơ trước.
watch(checkAuth, async (loggedIn) => {
  if (!loggedIn) return
  try {
    // GET api-profile; helper fetchProfile tự gộp kết quả vào auth_user trong localStorage.
    await fetchProfile()
  } catch (error) {
    // Không chặn giao diện: thiếu hồ sơ thì các nơi khác dùng dữ liệu đăng nhập sẵn có (email).
    // Token hết hạn (401) đã được interceptor trong client.js xử lý.
    console.error('Không đồng bộ được hồ sơ người dùng:', error)
  }
}, { immediate: true })

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
            <span class="site-brand-name">FULE BABERSHOP</span>
          </router-link>

          <div class="site-nav-primary" aria-label="Điều hướng cửa hàng">
            <router-link
              class="site-nav-link site-nav-home"
              to="/"
              :aria-current="route.path === '/' ? 'page' : undefined"
            >Trang chủ</router-link>
            <router-link
              class="site-nav-link site-nav-catalog"
              to="/catalog"
              :aria-current="route.path === '/catalog' ? 'page' : undefined"
            >Sản phẩm</router-link>
            <span class="site-nav-placeholder">Dịch vụ</span>
            <span class="site-nav-placeholder">Barber</span>
            <span class="site-nav-placeholder">Đặt lịch</span>
          </div>

          <div class="site-nav-account">
            <template v-if="!checkAuth">
              <router-link class="site-nav-link" to="/login" :aria-current="route.path === '/login' ? 'page' : undefined">Đăng nhập</router-link>
              <span class="site-nav-divider" aria-hidden="true"></span>
              <router-link class="site-nav-link" to="/register" :aria-current="route.path === '/register' ? 'page' : undefined">Đăng ký</router-link>
            </template>
            <!-- Giỏ hàng và Đơn hàng (desktop): nút tròn chỉ có icon, cùng kiểu nút tài khoản.
                 Chữ vẫn có trong aria-label (trình đọc màn hình) và title (hiện khi rê chuột).
                 Menu điện thoại là khối riêng bên dưới, vẫn hiện chữ. -->
            <router-link
              v-if="checkAuth"
              class="site-icon-button"
              to="/cart"
              :aria-current="route.path === '/cart' ? 'page' : undefined"
              :aria-label="`Giỏ hàng, ${cartCount} sản phẩm`"
              title="Giỏ hàng"
            >
              <!-- Icon giỏ hàng vẽ bằng SVG; stroke="currentColor" để đổi màu theo CSS. -->
              <svg class="site-icon" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="9" cy="20" r="1.4" />
                <circle cx="18" cy="20" r="1.4" />
                <path d="M2 3h3l2.6 12.2a1.8 1.8 0 0 0 1.8 1.4h8.8a1.8 1.8 0 0 0 1.7-1.3L22 7H6.2" />
              </svg>
              <span v-if="cartCount > 0" class="site-cart-count site-icon-badge" aria-hidden="true">{{ cartCount }}</span>
            </router-link>
            <router-link
              v-if="checkAuth"
              class="site-icon-button"
              to="/orders"
              :aria-current="route.path.startsWith('/orders') ? 'page' : undefined"
              aria-label="Đơn hàng của tôi"
              title="Đơn hàng"
            >
              <!-- Icon đơn hàng: tờ biên nhận có các dòng chữ. -->
              <svg class="site-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 2.8h12a1 1 0 0 1 1 1V21l-2.5-1.6L14 21l-2-1.6L10 21l-2.5-1.6L5 21V3.8a1 1 0 0 1 1-1Z" />
                <path d="M8.5 8h7M8.5 11.5h7M8.5 15h4" />
              </svg>
            </router-link>
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
              >Trang chủ</router-link>
              <router-link
                class="site-mobile-link site-mobile-catalog"
                to="/catalog"
                :aria-current="route.path === '/catalog' ? 'page' : undefined"
              >Sản phẩm</router-link>
              <span class="site-mobile-link site-nav-placeholder">Dịch vụ</span>
              <span class="site-mobile-link site-nav-placeholder">Barber</span>
              <span class="site-mobile-link site-nav-placeholder">Đặt lịch</span>
            </div>
            <div class="site-mobile-account">
              <template v-if="!checkAuth">
                <router-link class="site-mobile-link" to="/login" :aria-current="route.path === '/login' ? 'page' : undefined">Đăng nhập</router-link>
                <router-link class="site-mobile-link" to="/register" :aria-current="route.path === '/register' ? 'page' : undefined">Đăng ký</router-link>
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
              >Hồ sơ</router-link>
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
        <span>Bảo lưu mọi quyền.</span>
      </p>
    </footer>

    <!-- Khung chat trợ lý AI dạng popup, đặt ở layout chung nên dùng được ở mọi trang. -->
    <ChatAssistant :authenticated="checkAuth" />
  </div>
</template>

<style scoped>
/* Nút icon Giỏ hàng / Đơn hàng trên desktop: tròn 42px, cùng kích thước và màu với nút tài khoản (.site-account-button). */
.site-icon-button {
  position: relative;
  display: grid;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  place-items: center;
  border: 1px solid #2f4054;
  border-radius: 50%;
  background: #1c2a3a;
  color: #d8b06a;
  transition: background-color 140ms ease, border-color 140ms ease;
}

.site-icon-button:hover {
  border-color: #b8893e;
  background: #25364a;
}

/* Đang ở trang tương ứng: viền đồng bên ngoài, giống nút tài khoản khi active. */
.site-icon-button[aria-current='page'] {
  outline: 2px solid #d8b06a;
  outline-offset: 3px;
}

/* Icon SVG dạng nét: không tô nền, nét lấy màu chữ (currentColor) của nút. */
.site-icon {
  width: 20px;
  height: 20px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.6;
  stroke-linecap: round;
  stroke-linejoin: round;
}

/* Số lượng giỏ hàng: chấm tròn ở góc trên phải nút. Nền đồng chữ trắng để nổi trên nền navy. */
.site-icon-badge {
  position: absolute;
  top: -5px;
  right: -6px;
  min-width: 20px;
  height: 20px;
  border: 2px solid #131e2a;
  background: #b8893e;
  color: #ffffff;
  font-size: 11px;
}
</style>
