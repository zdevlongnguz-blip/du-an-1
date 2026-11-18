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
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
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
    // Món đã hết hàng (stock = 0) thì giữ nguyên số lượng: khách thấy món bị đánh dấu và tự bỏ món.
    if (Number.isInteger(quantity) && item.stock >= 1) {
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

// Bỏ hẳn một món khỏi đơn nháp (khách bấm nút xóa món trên trang).
// Sửa mảng items tại chỗ (splice), không thay object, nên trang không diễn lại hiệu ứng.
// Không cho bỏ món cuối cùng: đơn rỗng thì khách dùng nút "Bỏ đơn nháp".
// Trả true nếu đã bỏ, false nếu không có đơn, sai vị trí hoặc chỉ còn 1 món.
export function removeCheckoutDraftItem(index = -1) {
  const draft = checkoutDraft.value
  if (!draft || !Number.isInteger(index) || index < 0 || index >= draft.items.length) return false
  if (draft.items.length <= 1) return false

  draft.items.splice(index, 1)
  save()
  return true
}

// Tra lại giá và tồn kho mới nhất của các món trong đơn nháp, rồi cập nhật đơn nháp tại chỗ.
// Gọi khi mở trang /ai-checkout (đơn nháp có thể đã cũ) và khi đặt hàng bị 409 (hết hàng, thiếu hàng, giá đổi).
// - Giá, giá gốc, tồn kho: lấy theo server.
// - Số lượng lớn hơn tồn kho: kẹp về tồn kho (ví dụ đặt 17, kho còn 13 thì thành 13).
// - Món hết hàng hoặc ngừng bán: đánh dấu available = false, stock = 0; trang khóa nút đặt cho đến khi khách bỏ món.
// Trả mảng câu mô tả thay đổi để trang báo cho khách (rỗng = không có gì đổi). Lỗi API thì ném lỗi cho trang tự xử lý.
export async function refreshCheckoutDraftStock() {
  const draft = checkoutDraft.value
  if (!draft) return []

  // API api-order-draft-items (GET, cần đăng nhập). axios gửi mảng params thành skus[]=A&skus[]=B,
  // PHP tự gom thành mảng. Thành công 200: { status, items: [{ sku, price, original_price, stock, available, ... }] },
  // mỗi SKU gửi lên có đúng một dòng.
  const response = await api.get('/?action=api-order-draft-items', {
    params: { skus: draft.items.map((item) => item.sku) },
  })
  const freshItems = response.data?.items
  if (response.data?.status !== 'success' || !Array.isArray(freshItems)) {
    throw new Error('Dữ liệu tồn kho trả về không hợp lệ.')
  }

  // Tra theo SKU viết thường vì server không phân biệt hoa thường.
  const freshBySku = new Map(freshItems.map((fresh) => [String(fresh?.sku ?? '').toLowerCase(), fresh]))
  const changes = []

  for (const item of draft.items) {
    const fresh = freshBySku.get(item.sku.toLowerCase())
    const stock = Number(fresh?.stock)
    const price = Number(fresh?.price)

    // Không còn bán được: đánh dấu, không tự bỏ món (để khách thấy món nào bị ảnh hưởng).
    if (!fresh || fresh.available !== true || !Number.isInteger(stock) || stock < 1
      || !Number.isFinite(price) || price < 0) {
      if (item.available !== false) {
        changes.push(`${item.name}: đã hết hàng hoặc ngừng bán, quý ông bỏ món này khỏi đơn giúp Louis.`)
      }
      item.available = false
      item.stock = 0
      continue
    }

    item.available = true
    // Tên, tên phiên bản, ảnh: lấy theo server để đơn nháp cũ không hiển thị thông tin đã đổi.
    if (typeof fresh.name === 'string' && fresh.name.trim() !== '') item.name = fresh.name
    if (typeof fresh.variant_name === 'string') item.variant_name = fresh.variant_name
    if (typeof fresh.image === 'string') item.image = fresh.image

    const originalPrice = Number(fresh.original_price)
    const nextOriginalPrice = Number.isFinite(originalPrice) && originalPrice > price ? originalPrice : null
    if (price !== item.price) {
      changes.push(`${item.name}: giá đổi từ ${formatPrice(item.price)} thành ${formatPrice(price)}.`)
    } else if (item.original_price && !nextOriginalPrice) {
      // Giá bán giữ nguyên nhưng hết chương trình giảm giá: "Đã giảm" sẽ mất, nên báo để khách không bất ngờ.
      changes.push(`${item.name}: đã hết chương trình giảm giá.`)
    }
    item.price = price
    item.original_price = nextOriginalPrice
    item.stock = Math.min(stock, MAX_QUANTITY)
    if (item.quantity > item.stock) {
      changes.push(`${item.name}: kho chỉ còn ${item.stock}, Louis đã giảm từ ${item.quantity} xuống ${item.stock}.`)
      item.quantity = item.stock
    }
  }

  save()
  return changes
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
