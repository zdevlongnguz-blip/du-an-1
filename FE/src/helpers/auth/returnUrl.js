import { isAuthenticated } from '@/helpers/auth/session'

const RETURN_URL_KEY = 'auth_return_url'

// Các trang thuộc luồng tài khoản: không lưu làm "trang để quay lại".
// Ví dụ khách bấm "Đăng nhập" khi đang ở trang Đăng ký thì giữ nguyên trang sản phẩm đã lưu trước đó,
// thay vì ghi đè bằng /register; đăng xuất từ trang Hồ sơ thì không quay lại /profile (cần đăng nhập).
const ACCOUNT_PATHS = ['/login', '/register', '/forgot-password', '/reset-password', '/profile', '/change-password']

// Chỉ giữ URL cùng origin để tránh lưu chuyển hướng ra website bên ngoài.
function getInternalPath(url) {
  if (typeof window === 'undefined' || typeof url !== 'string' || !url.trim()) {
    return ''
  }

  try {
    const parsedUrl = new URL(url, window.location.origin)
    if (parsedUrl.origin !== window.location.origin || ACCOUNT_PATHS.includes(parsedUrl.pathname)) {
      return ''
    }

    return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`
  } catch {
    return ''
  }
}

// Lưu URL hiện tại hoặc URL nội bộ được truyền vào để quay lại sau khi đăng nhập hoặc đăng xuất.
// Gọi lúc khách bấm "Đăng nhập" / nút tài khoản (navbar, khung chat, trang chi tiết sản phẩm).
// URL của trang tài khoản thì bỏ qua và giữ nguyên URL đã lưu trước đó.
export function saveReturnUrl(url) {
  const currentUrl = url ?? (typeof window === 'undefined' ? '' : window.location.href)
  const returnPath = getInternalPath(currentUrl)
  if (!returnPath) return false

  try {
    localStorage.setItem(RETURN_URL_KEY, returnPath)
    return true
  } catch {
    return false
  }
}

// Trang có cần đăng nhập không, dựa vào meta.requiresAuth khai báo trong router/index.js.
// router.resolve: hỏi Vue Router đường dẫn này khớp route nào mà không chuyển trang.
// matched gồm cả route cha, nên route con nằm trong khu cần đăng nhập (ví dụ /admin/...) cũng được tính.
function needsLogin(router, path) {
  try {
    return router.resolve(path).matched.some((record) => record.meta?.requiresAuth)
  } catch {
    return false
  }
}

// Đọc URL đã lưu, xóa để tránh dùng lại lần sau rồi điều hướng bằng Vue Router.
// Dùng sau khi đăng nhập thành công (Login.vue) và sau khi đăng xuất (Profile.vue).
// Trang đã lưu cần đăng nhập mà lúc này chưa đăng nhập (vừa đăng xuất khỏi /cart, /orders...) thì về
// fallbackUrl, vì nếu điều hướng tới đó route guard sẽ đá khách sang trang Đăng nhập.
export function pushToReturnUrl(router, fallbackUrl = '/') {
  let savedUrl = ''

  try {
    savedUrl = localStorage.getItem(RETURN_URL_KEY) || ''
    localStorage.removeItem(RETURN_URL_KEY)
  } catch {
    savedUrl = ''
  }

  let targetUrl = getInternalPath(savedUrl)
  if (targetUrl && !isAuthenticated() && needsLogin(router, targetUrl)) {
    targetUrl = ''
  }

  return router.push(targetUrl || getInternalPath(fallbackUrl) || '/')
}
