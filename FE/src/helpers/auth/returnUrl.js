const RETURN_URL_KEY = 'auth_return_url'

// Chỉ giữ URL cùng origin để tránh lưu chuyển hướng ra website bên ngoài.
function getInternalPath(url) {
  if (typeof window === 'undefined' || typeof url !== 'string' || !url.trim()) {
    return ''
  }

  try {
    const parsedUrl = new URL(url, window.location.origin)
    if (parsedUrl.origin !== window.location.origin || parsedUrl.pathname === '/login') {
      return ''
    }

    return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`
  } catch {
    return ''
  }
}

// Lưu URL hiện tại hoặc URL nội bộ được truyền vào để quay lại sau đăng nhập.
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

// Đọc URL đã lưu, xóa để tránh dùng lại lần sau rồi điều hướng bằng Vue Router.
export function pushToReturnUrl(router, fallbackUrl = '/') {
  let savedUrl = ''

  try {
    savedUrl = localStorage.getItem(RETURN_URL_KEY) || ''
    localStorage.removeItem(RETURN_URL_KEY)
  } catch {
    savedUrl = ''
  }

  const targetUrl = getInternalPath(savedUrl)
    || getInternalPath(fallbackUrl)
    || '/'

  return router.push(targetUrl)
}