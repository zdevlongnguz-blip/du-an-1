import api from '@/services/api'

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