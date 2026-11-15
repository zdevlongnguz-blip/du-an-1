import api from '@/helpers/api/client'

/**
 * Tạo URL ảnh từ tên file trong DB, hoặc giữ URL đầy đủ của ảnh ngoài.
 *
 * @param {string|null|undefined} image Tên file ảnh hoặc URL đầy đủ.
 * @returns {string} URL ảnh phù hợp với môi trường đang chạy FE.
 */
export function resolveImageUrl(image) {
  if (typeof image !== 'string' || image.trim() === '') return ''

  const imageValue = image.trim()
  if (/^https?:\/\//i.test(imageValue)) return imageValue

  const apiBaseUrl = `${api.defaults.baseURL.replace(/\/+$/, '')}/`
  const uploadsUrl = new URL('assets/uploads/', apiBaseUrl)

  return new URL(encodeURIComponent(imageValue), uploadsUrl).toString()
}

/**
 * Tạo URL ảnh hệ thống (avatar trợ lý, logo...) nằm trong thư mục BE/assets/system/.
 * Dùng cùng base URL với API như resolveImageUrl, nên không cần ghi cứng địa chỉ server.
 *
 * @param {string} fileName Tên file trong assets/system, ví dụ 'fule.webp'.
 * @returns {string} URL ảnh, hoặc chuỗi rỗng nếu tên file không hợp lệ.
 */
export function resolveSystemImageUrl(fileName) {
  if (typeof fileName !== 'string' || fileName.trim() === '') return ''

  const apiBaseUrl = `${api.defaults.baseURL.replace(/\/+$/, '')}/`
  const systemUrl = new URL('assets/system/', apiBaseUrl)

  return new URL(encodeURIComponent(fileName.trim()), systemUrl).toString()
}
