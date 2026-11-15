// Dựng URL video bài hát của trợ lý Louis, nằm trong thư mục BE/assets/fule/songs/.
// Dùng cùng base URL với API (lấy từ client.js) nên không cần ghi cứng địa chỉ server.
import api from '@/helpers/api/client'

// Tên file hợp lệ: chữ không dấu, số, gạch ngang, gạch dưới, dấu chấm, đuôi mp4/webm.
// Regex này chặn "../" hay đường dẫn lạ trong URL, chỉ cho phép đúng một tên file video.
const SONG_FILE_PATTERN = /^[A-Za-z0-9_-][A-Za-z0-9._-]*\.(mp4|webm)$/

/**
 * Tạo URL video bài hát từ tên file.
 *
 * @param {string} fileName Tên file video, ví dụ 'vi-con.mp4'.
 * @returns {string} URL video, hoặc chuỗi rỗng nếu tên file không hợp lệ.
 */
export function resolveSongVideoUrl(fileName) {
  if (typeof fileName !== 'string' || !SONG_FILE_PATTERN.test(fileName.trim())) return ''

  const apiBaseUrl = `${api.defaults.baseURL.replace(/\/+$/, '')}/`
  const songsUrl = new URL('assets/fule/songs/', apiBaseUrl)

  return new URL(fileName.trim(), songsUrl).toString()
}
