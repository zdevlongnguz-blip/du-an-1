import axios from 'axios'
import { getToken, clearAuthSession } from '../auth/session'

const getApiBaseUrl = () => {
  const { origin, hostname, pathname, port } = window.location
  const configuredBaseUrl = import.meta.env.VITE_API_BASE_URL?.trim()

  // Ưu tiên VITE_API_BASE_URL; chấp nhận cả đường dẫn tương đối như /du-an-1/BE.
  if (configuredBaseUrl) {
    return new URL(configuredBaseUrl, origin).toString().replace(/\/+$/, '')
  }

  if (pathname.includes('/du-an-1')) {
    return `${origin}/du-an-1/BE`
  }

  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    if (port === '5173' || port === '5174' || port === '4173') {
      return 'http://localhost/du-an-1/BE'
    }
  }

  return `${origin}/BE`
}

const api = axios.create({
  baseURL: getApiBaseUrl(),
  timeout: 20000,
  headers: {
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use(
  (config) => {
    const token = getToken()

    if (token) {
      config.headers = config.headers || {}
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  (error) => Promise.reject(error)
)

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      clearAuthSession()

      if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }

    console.error('API Error:', error.response?.data || error.message)
    return Promise.reject(error)
  }
)

export default api
