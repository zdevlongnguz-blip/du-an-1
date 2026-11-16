// Helper hồ sơ cá nhân: gọi API api-profile (xem) và api-profile-update (sửa) để làm việc với họ tên, SĐT, địa chỉ,
// rồi lưu lại vào localStorage (auth_user) để lần sau hiển thị nhanh và đúng dữ liệu mới.
// Kèm các hàm validate phía FE khớp với quy tắc BE (BE vẫn là nơi kiểm tra cuối cùng).
import api from '@/helpers/api/client'
import { getToken, getUser, setAuthSession } from '@/helpers/auth/session'
import { validateVietnamPhone } from '@/helpers/format/phone'

// Giới hạn độ dài theo byte UTF-8, giống BE (ProfileController dùng strlen, đếm byte).
// Chữ tiếng Việt có dấu chiếm 2-3 byte, nên số ký tự gõ được sẽ ít hơn con số này.
const NAME_MAX_BYTES = 100
const ADDRESS_MAX_BYTES = 500

/**
 * Gọi GET ?action=api-profile để lấy hồ sơ của user đang đăng nhập.
 * Response thành công: { status: 'success', user: { id, email, role, name, phone, address } }.
 * Các trường name/phone/address có thể là null nếu user chưa điền.
 * Lỗi (401, 404, mạng...) được ném ra để nơi gọi tự hiện thông báo; không trả mảng rỗng.
 *
 * @returns {Promise<object>} Thông tin hồ sơ mới nhất từ server.
 */
export async function fetchProfile() {
  const res = await api.get('/?action=api-profile')
  const profile = res.data.user

  // Lưu lại vào localStorage sau khi lấy thành công, để các trang khác dùng getUser() cũng thấy dữ liệu mới.
  saveProfileToSession(profile)

  return profile
}

/**
 * Gọi PUT ?action=api-profile-update để lưu họ tên, SĐT, địa chỉ.
 * Body luôn gửi đủ 3 key; ô để trống được gửi là null để BE lưu NULL (xóa trường đó).
 * Response thành công: { status: 'success', message, user: { id, email, role, name, phone, address } }.
 * Lỗi 400/422 (dữ liệu sai), 401 (hết phiên), 404 được ném ra để nơi gọi hiện err.response.data.message.
 *
 * @param {{ name?: string, phone?: string, address?: string }} form Dữ liệu từ form.
 * @returns {Promise<{ message: string, user: object }>} Thông báo của BE và hồ sơ đã lưu.
 */
export async function updateProfile(form = {}) {
  const res = await api.put('/?action=api-profile-update', {
    name: toNullableText(form.name),
    phone: toNullableText(form.phone),
    address: toNullableText(form.address),
  })
  const profile = res.data.user

  saveProfileToSession(profile)

  return { message: res.data.message, user: profile }
}

/**
 * Gộp hồ sơ mới vào thông tin user đang lưu trong localStorage (auth_user).
 * Dùng setAuthSession với token hiện tại để không làm mất đăng nhập.
 * Nếu chưa có token (chưa đăng nhập) thì không lưu, vì setAuthSession với token rỗng sẽ xóa session.
 *
 * @param {object} profile Thông tin hồ sơ cần lưu (có thể chỉ một phần các trường).
 * @returns {void}
 */
export function saveProfileToSession(profile = {}) {
  const token = getToken()
  if (!token) return

  // Spread: giữ các trường cũ (id, email, role) và ghi đè bằng dữ liệu mới.
  setAuthSession(token, { ...(getUser() || {}), ...profile })
}

/**
 * Lấy tên để xưng hô với user: ưu tiên họ tên trong hồ sơ; chưa có thì lấy phần trước dấu @ của email
 * (ví dụ "xuanlonghh4@gmail.com" -> "xuanlonghh4", áp dụng cho mọi đuôi email). Không có gì thì trả ''.
 *
 * @param {object|null} user Thông tin user (thường lấy từ getUser()).
 * @returns {string} Tên hiển thị hoặc chuỗi rỗng.
 */
export function getDisplayName(user = null) {
  const name = (user?.name ?? '').trim()
  if (name) return name

  const email = (user?.email ?? '').trim()
  const atIndex = email.indexOf('@')
  // indexOf trả -1 nếu không có '@'; khi đó dùng nguyên chuỗi email.
  return atIndex > 0 ? email.slice(0, atIndex) : email
}

// Kiểm tra họ tên: không bắt buộc, nhưng nếu nhập thì không quá 100 byte. Trả '' nếu hợp lệ.
export function validateProfileName(value = '') {
  const name = (value ?? '').trim()
  if (name && byteLength(name) > NAME_MAX_BYTES) {
    return `Họ tên quá dài (tối đa ${NAME_MAX_BYTES} byte; chữ có dấu chiếm nhiều byte hơn).`
  }
  return ''
}

// Kiểm tra số điện thoại: không bắt buộc, nếu nhập thì phải là số di động Việt Nam (quy tắc chung trong format/phone.js).
export function validateProfilePhone(value = '') {
  return validateVietnamPhone(value, { required: false })
}

// Kiểm tra địa chỉ: không bắt buộc, nhưng nếu nhập thì không quá 500 byte. Trả '' nếu hợp lệ.
export function validateProfileAddress(value = '') {
  const address = (value ?? '').trim()
  if (address && byteLength(address) > ADDRESS_MAX_BYTES) {
    return `Địa chỉ quá dài (tối đa ${ADDRESS_MAX_BYTES} byte; chữ có dấu chiếm nhiều byte hơn).`
  }
  return ''
}

// TextEncoder đổi chuỗi sang byte UTF-8, để đếm độ dài giống strlen của PHP.
function byteLength(text = '') {
  return new TextEncoder().encode(text).length
}

// Bỏ khoảng trắng hai đầu; ô trống thành null để BE hiểu là "xóa trường này".
function toNullableText(value = '') {
  const text = (value ?? '').trim()
  return text === '' ? null : text
}

export default {
  fetchProfile,
  updateProfile,
  saveProfileToSession,
  getDisplayName,
  validateProfileName,
  validateProfilePhone,
  validateProfileAddress,
}
