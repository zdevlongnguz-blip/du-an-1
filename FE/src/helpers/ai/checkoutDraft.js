// Đơn nháp do Louis Dé Fule (AI) lên, dùng chung giữa khung chat và trang /ai-checkout.
//
// Một nguồn duy nhất, gồm:
// - items: danh sách món (BE gửi, khách chỉnh số lượng trên trang)
// - recipient: tên, SĐT, địa chỉ (khách sửa trên trang; lần đầu lấy từ hồ sơ)
// - profileLoaded: đã lấy hồ sơ cho đơn này chưa (để chỉ gọi api-profile một lần)
//
// Lưu trong sessionStorage: F5 vẫn còn, đóng tab thì mất. Gắn với user ID: người đăng nhập sau không thấy đơn của người trước.
// Đơn nháp không đi lên URL và không đi qua AI; chỉ items (SKU + số lượng) được gửi cho Louis khi chat.

import { ref } from 'vue'
import { getUser } from '@/helpers/auth/session'
import { fetchProfile } from '@/helpers/auth/profile'
import { normalizeVietnamPhone } from '@/helpers/format/phone'

// Khóa lưu trong sessionStorage.
const STORAGE_KEY = 'ai_checkout_draft'
// Giới hạn khớp với BE (Order::MAX_DIRECT_ITEMS, Order::MAX_ITEM_QUANTITY).
const MAX_ITEMS = 10
const MAX_QUANTITY = 99
const RECIPIENT_KEYS = ['name', 'phone', 'address']

// Đọc đơn nháp đã lưu. Lỗi đọc (storage bị chặn, JSON hỏng) thì coi như chưa có.
function readStorage() {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

// Ghi đơn nháp xuống sessionStorage. Lỗi ghi thì bỏ qua: đơn vẫn còn trong bộ nhớ của trang.
function writeStorage(value) {
  try {
    if (value) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(value))
    else sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // Không làm gì: trải nghiệm chỉ mất khả năng giữ qua F5.
  }
}

// ID của người đang đăng nhập (lấy từ auth_user trong localStorage). Chuỗi rỗng nếu chưa có.
function currentUserId() {
  return String(getUser()?.id ?? '')
}

// Đơn nháp đang dùng. Chỉ đọc lại từ storage nếu đúng người đang đăng nhập.
const storedDraft = readStorage()
const checkoutDraft = ref(
  storedDraft && storedDraft.userId === currentUserId() ? storedDraft.draft : null
)

// Lưu trạng thái hiện tại xuống storage (kèm user ID). Gọi sau mỗi lần thay đổi.
function save() {
  writeStorage(checkoutDraft.value
    ? { userId: currentUserId(), draft: checkoutDraft.value }
    : null)
}

// Kiểm tra và làm sạch một món. Trả null nếu sai định dạng.
function normalizeItem(item) {
  if (!item || typeof item !== 'object') return null
  const quantity = Number(item.quantity)
  const stock = Number(item.stock)
  const price = Number(item.price)
  if (typeof item.sku !== 'string' || item.sku.trim() === ''
    || typeof item.name !== 'string' || item.name.trim() === ''
    || !Number.isFinite(price) || price < 0
    || !Number.isInteger(quantity) || quantity < 1
    || !Number.isInteger(stock) || stock < 1) {
    return null
  }

  const originalPrice = Number(item.original_price)
  return {
    sku: item.sku,
    name: item.name,
    variant_name: typeof item.variant_name === 'string' ? item.variant_name : '',
    image: typeof item.image === 'string' ? item.image : '',
    price,
    original_price: Number.isFinite(originalPrice) && originalPrice > price ? originalPrice : null,
    stock: Math.min(stock, MAX_QUANTITY),
    quantity: Math.min(quantity, stock, MAX_QUANTITY),
  }
}

// Lưu đơn nháp mới từ Louis. Chỉ thay danh sách món; người nhận và trạng thái hồ sơ được giữ nguyên.
// Trả true nếu hợp lệ; false nếu sai định dạng (giữ đơn cũ).
export function setCheckoutDraft(draft) {
  if (!draft || !Array.isArray(draft.items)) return false
  if (draft.items.length < 1 || draft.items.length > MAX_ITEMS) return false

  const items = draft.items.map(normalizeItem)
  if (items.includes(null)) return false

  const previous = checkoutDraft.value
  checkoutDraft.value = {
    items,
    recipient: previous ? previous.recipient : { name: '', phone: '', address: '' },
    profileLoaded: previous ? previous.profileLoaded : false,
  }
  save()
  return true
}

// Ghi chỉnh sửa của khách trên trang: số lượng và người nhận.
// Sửa thuộc tính bên trong, không thay object, nên trang không diễn lại hiệu ứng.
export function syncCheckoutDraft({ quantities = [], recipient = {} } = {}) {
  const draft = checkoutDraft.value
  if (!draft) return

  draft.items.forEach((item, index) => {
    const quantity = Number(quantities[index])
    if (Number.isInteger(quantity)) {
      item.quantity = Math.min(Math.max(1, quantity), item.stock, MAX_QUANTITY)
    }
  })
  for (const key of RECIPIENT_KEYS) {
    if (typeof recipient[key] === 'string') {
      draft.recipient[key] = recipient[key]
    }
  }
  save()
}

// Lấy người nhận từ hồ sơ, chỉ MỘT LẦN cho mỗi đơn nháp.
// Điền vào các ô đang trống; ô đã có giá trị (khách đã nhập) thì giữ nguyên.
// Gọi api-profile lỗi thì không đánh dấu đã tải, lần mở trang sau sẽ thử lại.
// Trả true nếu đã có hồ sơ (vừa tải hoặc tải từ trước), false nếu lỗi.
export async function ensureDraftRecipient() {
  const draft = checkoutDraft.value
  if (!draft) return true
  if (draft.profileLoaded) return true

  try {
    // API api-profile (GET, cần đăng nhập): { status, user: { name, phone, address, ... } }.
    const profile = (await fetchProfile()) || {}
    const values = {
      name: typeof profile.name === 'string' ? profile.name.trim() : '',
      // SĐT trong hồ sơ sai định dạng thì để trống cho khách nhập lại, không điền số mà nút xác nhận sẽ từ chối.
      phone: normalizeVietnamPhone(typeof profile.phone === 'string' ? profile.phone : '') || '',
      address: typeof profile.address === 'string' ? profile.address.trim() : '',
    }
    for (const key of RECIPIENT_KEYS) {
      if (!draft.recipient[key]) draft.recipient[key] = values[key]
    }
    draft.profileLoaded = true
    save()
    return true
  } catch (error) {
    console.error('Không tải được hồ sơ để điền đơn nháp:', error)
    return false
  }
}

// Dạng gửi cho Louis mỗi lần chat: chỉ SKU + số lượng.
// Không có giá (BE tự tra lại) và không có người nhận (không thuộc luồng AI). null nếu chưa có đơn.
export function getCheckoutDraftForChat() {
  const draft = checkoutDraft.value
  if (!draft) return null
  return {
    items: draft.items.map((item) => ({ sku: item.sku, quantity: item.quantity })),
  }
}

// Xóa đơn nháp: sau khi đặt hàng thành công, khi đăng xuất, hoặc khi token hết hạn.
export function clearCheckoutDraft() {
  checkoutDraft.value = null
  save()
}

// Dùng trong component: lấy ref của đơn nháp (chỉ đọc qua .value).
export function useCheckoutDraft() {
  return { checkoutDraft }
}
