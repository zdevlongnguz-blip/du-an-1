<script setup>
// Trang "Lên đơn nhanh cho quý ông": hiện đơn nháp do Louis Dé Fule (AI) lên giúp khách.
// Bố cục theo màn "Thông tin giao hàng" của Stitch: cột trái là form (người nhận ở trên, món hàng ở dưới), cột phải là cụm điều khiển.
// Hiệu ứng: Louis chuẩn bị → tự điền form → nhảy tổng tiền → đóng dấu; xong mới mở khóa để khách tự chỉnh.
// Đơn nháp lấy từ helpers/ai/checkoutDraft.js (do khung chat lưu khi BE trả action 'checkout-draft').
// Món hàng do Louis lên; người nhận do FE tự điền từ hồ sơ (api-profile), không đi qua AI.
// Bấm xác nhận gọi API api-order-checkout-items (cần đăng nhập); chọn SePay thì chuyển tiếp sang api-order-payment.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import api from '@/helpers/api/client'
import { formatPrice } from '@/helpers/format/price'
import { validateVietnamPhone } from '@/helpers/format/phone'
import { resolveImageUrl } from '@/helpers/media/imageUrl'
import { startOnlinePayment } from '@/helpers/orders/orderActions'
import { openChat } from '@/helpers/ai/chatAssistant'
import {
  clearCheckoutDraft,
  consumeDraftIntro,
  ensureDraftRecipient,
  refreshCheckoutDraftStock,
  removeCheckoutDraftItem,
  syncCheckoutDraft,
  useCheckoutDraft,
} from '@/helpers/ai/checkoutDraft'
import { showToast } from '@/helpers/ui/toast'
import { useTabVisible } from '@/helpers/ui/tabVisible'

const router = useRouter()
// Đơn nháp dùng chung với khung chat. null = chưa có (khách gõ thẳng URL hoặc vừa tải lại trang).
// discardCount: tăng mỗi khi đơn nháp bị bỏ theo yêu cầu (Louis gọi tool hủy đơn nháp).
const { checkoutDraft, discardCount } = useCheckoutDraft()

// Bản chụp đơn nháp mà trang đang hiển thị. Tách khỏi checkoutDraft vì sau khi đặt hàng thành công
// trang xóa đơn nháp dùng chung, nhưng vẫn cần dữ liệu để hiển thị trong lúc chuyển trang/sang SePay.
const draft = ref(null)

// Các bước Louis "làm việc" ở màn mở đầu. Mỗi bước ứng với việc BE thật sự làm khi lên đơn.
const PREP_STEPS = [
  'Louis đang kiểm tra kho hàng...',
  'Đối chiếu giá mới nhất...',
  'Lấy thông tin giao hàng của quý ông...',
]

// Màn đang diễn: 'prep' (Louis chuẩn bị), 'fill' (Louis tự điền form), 'ready' (đã đóng dấu, khách được chỉnh sửa).
const phase = ref('prep')
// Chữ đang gõ của từng bước ở màn mở đầu. reactive: object "phản ứng", đổi thuộc tính thì giao diện tự cập nhật.
const prepLines = reactive(PREP_STEPS.map(() => ({ text: '', done: false })))
// Số bước màn mở đầu đã bắt đầu hiện (để ẩn các dòng chưa tới lượt).
const prepVisible = ref(0)

// Số món đã "bay" vào form. Món có thứ tự nhỏ hơn số này mới được hiện.
const itemsVisible = ref(0)
// Số lượng từng món (theo thứ tự trong draft.items), khách được tăng/giảm sau khi hiệu ứng xong. Giá thì khóa.
const quantities = ref([])
// Thông tin người nhận, gắn với ô nhập bằng v-model (gõ vào ô thì biến đổi theo và ngược lại).
const form = reactive({ name: '', phone: '', address: '' })
// Lỗi hiển thị ngay dưới từng ô nhập, rỗng = không có lỗi.
const fieldErrors = reactive({ name: '', phone: '', address: '' })
// Ô Louis đang tự điền, dùng để làm viền ô đó phát sáng màu đồng.
const activeField = ref('')
// Tổng tiền đang "nhảy số" trong lúc diễn; null = chưa tới lúc hiện.
const shownTotal = ref(null)
// true khi con dấu "Đã duyệt" vừa đập xuống, dùng để chạy hiệu ứng rơi dấu và rung khối tóm tắt.
const stamped = ref(false)
// Phương thức thanh toán khách chọn: 'cod' (khi nhận hàng) hoặc 'sepay' (online).
const paymentMethod = ref('cod')

// true trong lúc đang gửi đơn hoặc đang chuyển sang SePay, dùng để khóa nút tránh gửi lặp.
const submitting = ref(false)
// Lỗi chung khi gửi đơn (hết hàng, giá đổi, lỗi mạng...), rỗng = không có lỗi.
const checkoutError = ref('')
// Đơn đã tạo nhưng chuyển sang SePay thất bại: giữ ID để khách bấm thanh toán lại mà không tạo đơn lần nữa.
const createdOrder = ref(null)
// Các thay đổi vừa áp theo kho/giá mới nhất (kẹp số lượng, đổi giá, món hết hàng), mỗi phần tử là một câu để hiện cho khách.
const draftChanges = ref([])
// true trong lúc đang tra lại kho vì khách bấm "+" khi số lượng đã chạm tồn kho; khóa các nút +/− trong lúc chờ.
const checkingStock = ref(false)

// computed: chỉ cho sửa khi hiệu ứng đã xong, không đang gửi và chưa tạo đơn.
const editable = computed(() => phase.value === 'ready' && !submitting.value && !createdOrder.value)
// computed: danh sách món của đơn đang hiển thị (rỗng nếu chưa có đơn), để template không phải kiểm tra null.
const items = computed(() => draft.value?.items || [])
// computed: tổng tiền theo số lượng hiện tại. Chỉ để khách xem trước, BE sẽ tính lại khi xác nhận.
// Món đã hết hàng/ngừng bán (available = false) không được cộng, giống tổng tiền của trang Giỏ hàng.
const subtotal = computed(() => items.value.reduce((sum, item, index) => (
  item.available === false ? sum : sum + item.price * (quantities.value[index] || 0)
), 0))
// computed: số tiền khách tiết kiệm nhờ giảm giá (chênh lệch giữa giá niêm yết và giá bán), cũng bỏ món hết hàng.
const savings = computed(() => items.value.reduce((sum, item, index) => (
  item.original_price && item.available !== false
    ? sum + (item.original_price - item.price) * (quantities.value[index] || 0)
    : sum
), 0))
// computed: tổng số sản phẩm (cộng số lượng các món).
// Món hết hàng/ngừng bán không được đếm, cho khớp với tổng tiền (cũng không cộng món này).
const totalQuantity = computed(() => quantities.value.reduce((sum, qty, index) => (
  items.value[index]?.available === false ? sum : sum + qty
), 0))
// computed: true nếu có món đã hết hàng/ngừng bán (đánh dấu sau khi tra lại kho). Khi đó khóa nút đặt hàng
// vì BE chắc chắn từ chối đơn (409); khách cần bỏ món đó trước.
const hasUnavailableItem = computed(() => items.value.some((item) => item.available === false))
// Tổng tiền hiển thị: lúc diễn thì dùng số đang nhảy, diễn xong thì dùng số tính theo số lượng thật.
const displayTotal = computed(() => (phase.value === 'ready' ? subtotal.value : (shownTotal.value ?? 0)))
// "Tạm tính" là tổng theo giá niêm yết (trước giảm giá), để Tạm tính − Đã giảm = Tổng thanh toán.
// Lúc diễn thì nhảy số cùng nhịp với tổng tiền (theo tỉ lệ số đang nhảy / số thật).
const displayListTotal = computed(() => {
  const listTotal = subtotal.value + savings.value
  if (phase.value === 'ready') return listTotal
  return subtotal.value > 0 ? Math.round(listTotal * (shownTotal.value ?? 0) / subtotal.value) : 0
})
// Chữ trên nút chính, đổi theo trạng thái gửi đơn.
const confirmLabel = computed(() => {
  if (createdOrder.value) return submitting.value ? 'Đang chuyển sang SePay...' : 'Thanh toán lại'
  return submitting.value ? 'Đang gửi đơn...' : 'Xác nhận đặt hàng'
})

// Ô còn trống cần khách tự điền (chỉ tính sau khi hiệu ứng xong). Hồ sơ thiếu thì tool để trống,
// trang vẫn mở để khách điền thay vì Louis hỏi trong chat (thông tin cá nhân không đi qua AI).
function isMissing(key) {
  return phase.value === 'ready' && form[key].trim() === ''
}
// computed: true nếu còn ít nhất một ô người nhận trống.
const hasMissingInfo = computed(() => ['name', 'phone', 'address'].some(isMissing))
// computed: chữ ở dòng trạng thái dưới form.
const statusText = computed(() => {
  if (phase.value !== 'ready') return 'Louis đang điền đơn giúp quý ông...'
  if (hasUnavailableItem.value) {
    return items.value.length > 1
      ? 'Có món đã hết hàng hoặc ngừng bán, quý ông bỏ món đó khỏi đơn rồi xác nhận lại.'
      : 'Món trong đơn đã hết hàng hoặc ngừng bán. Quý ông nhờ Louis chọn món khác hoặc bỏ đơn nháp.'
  }
  if (hasMissingInfo.value) return 'Quý ông điền nốt các ô còn trống phía trên để hoàn tất đơn.'
  return 'Đơn đã sẵn sàng, quý ông có thể chỉnh sửa.'
})

// Mã của lượt diễn hiện tại. Mỗi lần chạy lại (hoặc rời trang) thì tăng lên,
// các vòng lặp của lượt cũ thấy mã đổi sẽ tự dừng, tránh hai lượt diễn chồng lên nhau.
let runId = 0
// Giá trị đặc biệt dùng để thoát khỏi chuỗi hiệu ứng khi lượt diễn bị hủy.
const CANCELLED = Symbol('cancelled')

// Chờ ms mili giây. Nếu trong lúc chờ lượt diễn bị hủy thì báo CANCELLED để dừng hẳn.
function wait(ms, id) {
  return new Promise((resolve, reject) => {
    setTimeout(() => (id === runId ? resolve() : reject(CANCELLED)), ms)
  })
}

// Gõ chữ từng ký tự vào obj[key]. Array.from tách đúng từng ký tự, kể cả ký tự đặc biệt.
async function typeInto(obj, key, text, speed, id) {
  const chars = Array.from(text)
  for (let i = 1; i <= chars.length; i++) {
    obj[key] = chars.slice(0, i).join('')
    await wait(speed, id)
  }
}

// "Nhảy số" từ 0 lên giá trị cuối trong duration ms, gọi setter ở mỗi khung hình.
// requestAnimationFrame: trình duyệt gọi hàm trước mỗi lần vẽ lại màn hình (~60 lần/giây), nên số chạy mượt.
function countUp(setter, to, duration, id) {
  return new Promise((resolve, reject) => {
    const start = performance.now()
    function frame(now) {
      if (id !== runId) return reject(CANCELLED)
      const progress = Math.min((now - start) / duration, 1)
      // Ease-out: chạy nhanh lúc đầu, chậm dần khi gần tới số thật, trông "đã mắt" hơn chạy đều.
      const eased = 1 - Math.pow(1 - progress, 3)
      setter(Math.round(to * eased))
      if (progress < 1) requestAnimationFrame(frame)
      else resolve()
    }
    requestAnimationFrame(frame)
  })
}

// Đưa mọi thứ về trạng thái ban đầu theo đơn nháp mới trước khi diễn.
function resetStage(nextDraft) {
  draft.value = nextDraft
  phase.value = 'prep'
  prepVisible.value = 0
  prepLines.forEach((line) => { line.text = ''; line.done = false })
  itemsVisible.value = 0
  quantities.value = nextDraft.items.map((item) => item.quantity)
  form.name = ''
  form.phone = ''
  form.address = ''
  fieldErrors.name = ''
  fieldErrors.phone = ''
  fieldErrors.address = ''
  activeField.value = ''
  shownTotal.value = null
  stamped.value = false
  paymentMethod.value = 'cod'
  checkoutError.value = ''
  createdOrder.value = null
  draftChanges.value = []
}

// Tra lại kho/giá mới nhất cho đơn nháp rồi chép số lượng (có thể đã bị kẹp) sang các bộ tăng/giảm.
// Trả mảng thay đổi; lỗi API thì ném lỗi cho nơi gọi tự quyết định báo thế nào.
async function syncDraftWithStock() {
  const changes = await refreshCheckoutDraftStock()
  quantities.value = items.value.map((item) => item.quantity)
  draftChanges.value = changes
  return changes
}

// Áp kết quả tải hồ sơ và tra kho lên trang: chép số lượng (có thể đã bị kẹp), hiện danh sách thay đổi, báo lỗi nếu có.
// Dùng chung cho đường có hiệu ứng và đường hiện ngay (xem play).
function applyLoadResults(nextDraft, profileLoaded, stockResult) {
  if (!profileLoaded) {
    showToast('Chưa tải được hồ sơ, quý ông điền thông tin nhận hàng trên trang giúp Louis nhé.', 'warning')
  }
  quantities.value = nextDraft.items.map((item) => item.quantity)
  draftChanges.value = stockResult.changes
  if (!stockResult.ok) {
    showToast('Chưa kiểm tra được kho mới nhất. Cửa hàng sẽ kiểm tra lại khi quý ông xác nhận.', 'warning')
  } else if (stockResult.changes.length > 0) {
    showToast('Kho hoặc giá vừa thay đổi, Louis đã cập nhật đơn. Quý ông xem lại giúp Louis nhé.', 'warning')
  }
}

// Hiển thị một đơn nháp: có hiệu ứng nếu Louis vừa gửi đơn, không thì hiện ngay. Gọi khi trang mở và khi Louis gửi đơn mới.
// refreshStock: có tra lại kho/giá không. Mở trang (đơn có thể đã cũ) thì có; đơn Louis vừa gửi thì không,
// vì server vừa kiểm tra giá và tồn kho lúc lên đơn, gọi thêm chỉ tốn request.
async function play(nextDraft, refreshStock = true) {
  const id = ++runId
  resetStage(nextDraft)
  // Tải người nhận từ hồ sơ (nếu đơn chưa có) CHẠY SONG SONG với màn chuẩn bị, để không bắt khách chờ thêm.
  // Đơn nháp từ BE chỉ có món; tên, SĐT, địa chỉ do FE tự lấy (api-profile), không đi qua AI.
  const recipientTask = ensureDraftRecipient()
  // Tra lại kho/giá mới nhất cũng CHẠY SONG SONG với màn chuẩn bị: đơn nháp lưu trong sessionStorage
  // có thể đã cũ (khách mở tab khác mua bớt hàng, giá vừa đổi). Gom kết quả thành object để không ném lỗi giữa chừng.
  const stockTask = !refreshStock
    ? Promise.resolve({ ok: true, changes: [] })
    : refreshCheckoutDraftStock()
      .then((changes) => ({ ok: true, changes }))
      .catch((error) => {
        console.error('Không tra lại được tồn kho của đơn nháp:', error)
        return { ok: false, changes: [] }
      })

  try {
    // Chỉ diễn hiệu ứng "Louis đang lên đơn" khi đơn vừa được Louis gửi tới. Mở lại đơn cũ (nút "Xem đơn nháp"
    // trong khung chat, F5, quay lại trang) thì hiện ngay đơn đầy đủ, vẫn tra kho, để khách không phải chờ diễn lại.
    if (!consumeDraftIntro()) {
      const [profileLoaded, stockResult] = await Promise.all([recipientTask, stockTask])
      if (id !== runId) throw CANCELLED
      applyLoadResults(nextDraft, profileLoaded, stockResult)
      for (const key of ['name', 'phone', 'address']) form[key] = nextDraft.recipient[key] || ''
      itemsVisible.value = nextDraft.items.length
      shownTotal.value = subtotal.value
      stamped.value = true
      phase.value = 'ready'
      return
    }

    // Màn 1: gõ từng bước chuẩn bị, xong bước nào hiện dấu ✓ bước đó.
    await wait(300, id)
    for (let i = 0; i < PREP_STEPS.length; i++) {
      prepVisible.value = i + 1
      await typeInto(prepLines[i], 'text', PREP_STEPS[i], 22, id)
      await wait(220, id)
      prepLines[i].done = true
      await wait(160, id)
    }
    await wait(350, id)

    // Đợi hồ sơ và kết quả tra kho (thường đã xong trong lúc màn 1 chạy), rồi chép số lượng (có thể đã bị kẹp)
    // trước khi các món bay vào và tổng tiền nhảy số. Lỗi thì vẫn tiếp tục, khách tự điền / BE kiểm tra lại.
    const [profileLoaded, stockResult] = await Promise.all([recipientTask, stockTask])
    if (id !== runId) throw CANCELLED
    applyLoadResults(nextDraft, profileLoaded, stockResult)

    // Màn 2: hiện form. Louis tự điền từng ô người nhận (phần trên), ô đang điền sẽ phát sáng.
    phase.value = 'fill'
    await wait(500, id)
    const fields = [['name', 28], ['phone', 45], ['address', 16]]
    for (const [key, speed] of fields) {
      // Ô trống (hồ sơ chưa có): bỏ qua, không gõ; khách sẽ tự điền sau khi hiệu ứng xong.
      if (!nextDraft.recipient[key]) continue
      activeField.value = key
      await typeInto(form, key, nextDraft.recipient[key], speed, id)
      await wait(180, id)
    }
    activeField.value = ''

    // Sau đó các món lần lượt trượt vào phần dưới.
    for (let i = 0; i < nextDraft.items.length; i++) {
      itemsVisible.value = i + 1
      await wait(380, id)
    }

    // Tổng tiền nhảy số chậm cho có "độ nén", rồi đóng dấu duyệt.
    shownTotal.value = 0
    await countUp((value) => { shownTotal.value = value }, subtotal.value, 900, id)
    await wait(250, id)
    stamped.value = true
    await wait(650, id)

    // Hiệu ứng xong: mở khóa cho khách tự chỉnh.
    phase.value = 'ready'
  } catch (err) {
    // CANCELLED nghĩa là lượt diễn bị thay bằng lượt mới hoặc khách rời trang: bỏ qua, không phải lỗi.
    if (err !== CANCELLED) throw err
  }
}

// Tăng/giảm số lượng một món. Giữ trong khoảng 1 đến tồn kho, giống quy tắc của trang Giỏ hàng.
// Bấm "+" khi số lượng đã chạm tồn kho thì không chặn luôn mà tra lại kho (xem increaseAfterStockCheck),
// vì số tồn kho trên trang là số lúc mở trang, có thể đã tăng (khách khác hủy đơn, hàng được hoàn kho).
function changeQuantity(index, delta) {
  if (!editable.value || checkingStock.value) return
  const item = items.value[index]
  // Món đã hết hàng thì không cho đổi số lượng, khách chỉ có thể bỏ món.
  if (item.available === false) return
  if (delta > 0 && quantities.value[index] >= item.stock) {
    increaseAfterStockCheck(item.sku)
    return
  }
  quantities.value[index] = Math.min(Math.max(1, quantities.value[index] + delta), Math.max(1, item.stock))
  checkoutError.value = ''
  draftChanges.value = []
  scheduleStockRefresh()
}

// Hẹn giờ tra kho ngầm sau mỗi lần bấm +/−. Số lượng đổi ngay trên màn hình (khách không phải chờ),
// còn số "còn N" được cập nhật theo kho thật sau khi khách ngừng bấm STOCK_REFRESH_DELAY_MS.
// Bấm liên tục nhiều lần chỉ gửi 1 request: mỗi lần bấm hủy hẹn giờ cũ rồi hẹn lại (kỹ thuật "debounce").
const STOCK_REFRESH_DELAY_MS = 400
let stockRefreshTimer = null
function scheduleStockRefresh() {
  clearTimeout(stockRefreshTimer)
  stockRefreshTimer = setTimeout(refreshStockSilently, STOCK_REFRESH_DELAY_MS)
}

// Tra kho ngầm (không khóa nút): cập nhật số "còn N"; nếu kho đã tụt dưới số khách chọn thì
// syncDraftWithStock tự giảm số lượng và ghi lý do vào draftChanges, trang báo thêm bằng toast.
// Bỏ qua khi trang không còn cho sửa, đang tra kho vì nút "+", hoặc đang hiển thị đơn khác đơn nháp hiện tại.
async function refreshStockSilently() {
  if (!editable.value || checkingStock.value || draft.value !== checkoutDraft.value) return
  try {
    const changes = await syncDraftWithStock()
    if (changes.length > 0) {
      showToast('Kho hoặc giá vừa thay đổi, Louis đã cập nhật đơn. Quý ông xem lại giúp Louis nhé.', 'warning')
    }
  } catch (error) {
    // Lỗi mạng/server: giữ nguyên số đang hiển thị; BE vẫn kiểm tra lại khi khách xác nhận đặt hàng.
    console.error('Không tra lại được tồn kho sau khi đổi số lượng:', error)
  }
}

// Tra lại kho mới nhất (api-order-draft-items, qua syncDraftWithStock) rồi tăng thêm 1 nếu kho đã có thêm hàng.
// Tìm lại món theo SKU sau khi tra, vì kết quả tra có thể đổi thông tin của món (giá, tồn kho, hết hàng).
// Chỉ tra khi trang đang hiển thị đúng đơn nháp đang dùng (giống cách xử lý 409 khi đặt hàng),
// để không sửa nhầm sang đơn khác nếu Louis vừa gửi đơn mới.
async function increaseAfterStockCheck(sku) {
  if (draft.value !== checkoutDraft.value) return
  checkingStock.value = true
  try {
    const changes = await syncDraftWithStock()
    const index = items.value.findIndex((item) => item.sku === sku)
    const item = items.value[index]
    if (!item || item.available === false) {
      // Món vừa hết hàng/ngừng bán: syncDraftWithStock đã đánh dấu và ghi lý do vào draftChanges để khách thấy.
      showToast('Món này vừa hết hàng hoặc ngừng bán, quý ông xem lại đơn giúp Louis.', 'warning')
      return
    }
    if (quantities.value[index] < item.stock) {
      // Kho đã có thêm hàng: tăng như bình thường. Không xóa draftChanges nếu có thay đổi khác (ví dụ giá đổi).
      quantities.value[index] += 1
      checkoutError.value = ''
      if (changes.length === 0) draftChanges.value = []
    } else {
      showToast(`Louis đã kiểm tra lại: món này hiện chỉ đặt được tối đa ${item.stock}.`, 'info')
    }
  } catch (error) {
    // Tra kho lỗi (mạng, server): giữ nguyên số lượng; BE vẫn kiểm tra lại khi khách xác nhận đặt hàng.
    console.error('Không tra lại được tồn kho khi tăng số lượng:', error)
    showToast('Chưa kiểm tra được kho mới nhất, quý ông thử lại sau giây lát.', 'warning')
  } finally {
    // finally luôn chạy dù thành công hay lỗi, nên mở khóa nút ở đây.
    checkingStock.value = false
  }
}

// Khách gõ số lượng tay vào ô rồi rời ô / bấm Enter (sự kiện change, không chạy lúc đang gõ để không ngắt khách).
// Giống nút "+": KHÔNG kẹp theo số kho cũ trên trang mà tra lại kho mới nhất trước (api-order-draft-items,
// qua syncDraftWithStock), rồi kẹp số khách gõ trong khoảng 1 đến số đặt được. Khóa bộ tăng giảm trong lúc tra.
// Số gõ không hợp lệ (trống, chữ, nhỏ hơn 1) thì trả ô về số đang có, không gọi server.
async function onQuantityInput(index, event) {
  const input = event.target
  const item = items.value[index]
  const typed = Math.floor(Number(input.value))
  // Trang không cho sửa, món đã hết hàng, hoặc số gõ không hợp lệ: trả ô về số đang có.
  if (!editable.value || checkingStock.value || !item || item.available === false
    || !Number.isFinite(typed) || typed < 1 || draft.value !== checkoutDraft.value) {
    input.value = quantities.value[index]
    return
  }
  if (typed === quantities.value[index]) return

  // Ghi nhớ SKU để tìm lại món sau khi tra kho (kết quả tra có thể đổi thông tin của món).
  const sku = item.sku
  checkingStock.value = true
  try {
    const changes = await syncDraftWithStock()
    const freshIndex = items.value.findIndex((freshItem) => freshItem.sku === sku)
    const fresh = items.value[freshIndex]
    if (!fresh || fresh.available === false) {
      // Món vừa hết hàng/ngừng bán: syncDraftWithStock đã đánh dấu và ghi lý do vào draftChanges để khách thấy.
      showToast('Món này vừa hết hàng hoặc ngừng bán, quý ông xem lại đơn giúp Louis.', 'warning')
      return
    }
    // fresh.stock là số đặt được tối đa cho món này (đã gồm giới hạn mỗi món của cửa hàng).
    const next = Math.min(typed, Math.max(1, fresh.stock))
    quantities.value[freshIndex] = next
    checkoutError.value = ''
    if (changes.length === 0) draftChanges.value = []
    if (next < typed) {
      showToast(`Louis đã kiểm tra lại: món này hiện chỉ đặt được tối đa ${fresh.stock}.`, 'info')
    }
  } catch (error) {
    // Tra kho lỗi (mạng, server): giữ nguyên số cũ; BE vẫn kiểm tra lại khi khách xác nhận đặt hàng.
    console.error('Không tra lại được tồn kho khi gõ số lượng:', error)
    showToast('Chưa kiểm tra được kho mới nhất, quý ông thử lại sau giây lát.', 'warning')
  } finally {
    checkingStock.value = false
    // Ô nhập có thể vẫn hiện số khách gõ (khi số lượng không đổi Vue không vẽ lại), nên ghi lại số thật vào ô.
    const currentIndex = items.value.findIndex((freshItem) => freshItem.sku === sku)
    if (currentIndex >= 0) input.value = quantities.value[currentIndex]
  }
}

// Bấm nút xóa của một món: bỏ hẳn món đó khỏi đơn nháp.
// Bỏ trong đơn nháp dùng chung trước, rồi bỏ số lượng cùng vị trí, để hai mảng vẫn khớp thứ tự với nhau.
// Lần chat sau, Louis nhận đơn không còn món này. Còn 1 món thì nút bị khóa (dùng "Bỏ đơn nháp").
function removeItem(index) {
  if (!editable.value || items.value.length <= 1) return
  const name = items.value[index]?.name || 'món'
  if (!removeCheckoutDraftItem(index)) return
  quantities.value.splice(index, 1)
  checkoutError.value = ''
  draftChanges.value = []
  showToast(`Đã bỏ ${name} khỏi đơn.`, 'info')
}

// Xóa lỗi của một ô ngay khi khách sửa ô đó.
function clearError(key) {
  fieldErrors[key] = ''
}

// Kiểm tra thông tin người nhận ở FE (cùng quy tắc với trang Checkout: tên ≤ 100, SĐT di động Việt Nam, địa chỉ ≤ 500).
// Chỉ để khách sửa sớm; BE vẫn kiểm tra lại. Trả true nếu hợp lệ.
function validateForm() {
  const name = form.name.trim()
  const address = form.address.trim()
  fieldErrors.name = !name ? 'Vui lòng nhập tên người nhận.' : (name.length > 100 ? 'Tên người nhận tối đa 100 ký tự.' : '')
  fieldErrors.phone = validateVietnamPhone(form.phone, { required: true })
  fieldErrors.address = !address ? 'Vui lòng nhập địa chỉ giao hàng.' : (address.length > 500 ? 'Địa chỉ tối đa 500 ký tự.' : '')
  return !fieldErrors.name && !fieldErrors.phone && !fieldErrors.address
}

// Chuyển sang SePay để thanh toán đơn vừa tạo. Thành công thì trình duyệt rời khỏi trang;
// thất bại thì giữ mã đơn để khách bấm "Thanh toán lại" mà không tạo đơn lần nữa.
async function payCreatedOrder() {
  if (!createdOrder.value) return
  submitting.value = true
  checkoutError.value = ''

  try {
    await startOnlinePayment(createdOrder.value.id)
  } catch (error) {
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể bắt đầu thanh toán.'
    showToast(checkoutError.value, 'danger')
    submitting.value = false
  }
}

// Bấm nút chính. Lần đầu: kiểm tra form rồi gọi api-order-checkout-items để tạo đơn.
// Nếu đơn đã tạo mà chuyển SePay lỗi: chỉ thử chuyển SePay lại.
async function handleConfirm() {
  if (createdOrder.value) {
    await payCreatedOrder()
    return
  }
  // Đang tra lại kho (khách vừa bấm "+") thì chờ xong mới cho gửi, tránh gửi số lượng sắp bị cập nhật.
  if (!editable.value || checkingStock.value) return
  // Hủy lượt tra kho ngầm còn đang hẹn (khách vừa bấm +/− rồi bấm đặt ngay): BE sẽ kiểm tra kho khi tạo đơn,
  // không để lượt tra đó chạy chồng lên lúc gửi đơn.
  clearTimeout(stockRefreshTimer)
  if (hasUnavailableItem.value) {
    showToast('Có món đã hết hàng hoặc ngừng bán, quý ông bỏ món đó trước khi đặt.', 'warning')
    return
  }
  if (!validateForm()) {
    showToast('Quý ông kiểm tra lại thông tin người nhận giúp Louis nhé.', 'warning')
    return
  }

  submitting.value = true
  checkoutError.value = ''

  try {
    // API api-order-checkout-items (POST, cần đăng nhập; interceptor tự gắn Bearer token, user lấy từ token).
    // Chỉ gửi SKU + số lượng, KHÔNG gửi giá: BE tự tính lại. expected_total là tổng khách đang thấy,
    // BE dùng để phát hiện giá vừa đổi (trả 409) chứ không dùng để tính tiền.
    // Thành công 201: { status, message, order: { id, total_amount, order_status, payment_status, payment_method } }.
    const response = await api.post('/?action=api-order-checkout-items', {
      items: items.value.map((item, index) => ({ sku: item.sku, quantity: quantities.value[index] })),
      recipient_name: form.name.trim(),
      recipient_phone: form.phone.trim(),
      shipping_address: form.address.trim(),
      payment_method: paymentMethod.value,
      // Làm tròn 2 chữ số thập phân để tránh sai số kiểu 0.1 + 0.2 của số thực.
      expected_total: Math.round(subtotal.value * 100) / 100,
    })
    const orderId = response.data?.order?.id
    if (response.data?.status !== 'success' || !orderId) {
      throw new Error('Không nhận được mã đơn hàng hợp lệ.')
    }

    // Đơn đã tạo: xóa đơn nháp dùng chung để không bị đặt lại lần nữa. Trang vẫn hiển thị bản chụp draft.
    createdOrder.value = { id: orderId, paymentMethod: paymentMethod.value }
    clearCheckoutDraft()

    if (paymentMethod.value === 'sepay') {
      await payCreatedOrder()
      return
    }

    showToast('Đặt hàng thành công. Quý ông sẽ thanh toán khi nhận hàng.', 'success')
    router.push({ name: 'order-detail', params: { id: orderId } })
  } catch (error) {
    // 409: hết hàng, không đủ số lượng hoặc giá vừa đổi; 422: dữ liệu sai; 401 do interceptor chuyển về trang đăng nhập.
    checkoutError.value = error.response?.data?.message || error.message || 'Không thể tạo đơn hàng.'
    // Chỉ tra lại khi trang vẫn hiển thị đúng đơn nháp đang dùng. Nếu trong lúc gửi, Louis vừa gửi đơn mới
    // (hoặc đơn nháp bị xóa) thì trang sẽ tự diễn lại với đơn mới, không sửa nhầm sang đơn khác.
    if (error.response?.status === 409 && draft.value === checkoutDraft.value) {
      // Không cần biết lý do cụ thể: tra lại kho/giá cho cả đơn rồi cập nhật đơn nháp,
      // để khách thấy số mới (ví dụ kho chỉ còn 13) và bấm xác nhận lại. Nút vẫn khóa trong lúc tra (submitting).
      try {
        const changes = await syncDraftWithStock()
        if (changes.length > 0) {
          checkoutError.value = 'Kho hoặc giá vừa thay đổi, Louis đã cập nhật đơn theo số mới nhất. Quý ông kiểm tra rồi bấm xác nhận lại.'
        }
      } catch (refreshError) {
        // Tra kho lỗi: giữ thông báo 409 gốc của BE, khách vẫn có thể nhờ Louis lên lại đơn.
        console.error('Không tra lại được tồn kho sau khi đặt hàng thất bại:', refreshError)
      }
      showToast(checkoutError.value, 'warning')
    } else {
      showToast(checkoutError.value, 'danger')
    }
  } finally {
    // finally luôn chạy dù thành công hay lỗi. Riêng lúc đang chuyển SePay thành công thì trang đã rời đi.
    if (!createdOrder.value || createdOrder.value.paymentMethod !== 'sepay') {
      submitting.value = false
    }
  }
}

// Bấm "Nhờ Louis sửa đơn" (hoặc "Trò chuyện với Louis" khi chưa có đơn): mở khung chat.
function handleEdit() {
  openChat()
}

// Bấm "Bỏ đơn nháp": xóa đơn nháp trong trình duyệt, không đụng DB (đơn nháp chưa được lưu ở server).
// Hỏi xác nhận bằng hộp thoại của trình duyệt trước khi xóa.
// Khi đơn thật đã được tạo (createdOrder, ví dụ chuyển SePay lỗi) thì không còn đơn nháp để bỏ:
// nút bị ẩn và hàm dừng ngay, tránh báo "đã bỏ" trong khi đơn thật vẫn tồn tại.
function handleDiscard() {
  if (createdOrder.value || submitting.value) return
  if (!window.confirm('Bỏ đơn nháp này? Đơn chưa được đặt, Louis có thể lên lại sau.')) return
  clearCheckoutDraft()
  showToast('Đã bỏ đơn nháp.', 'info')
  router.push({ name: 'catalog' })
}

// watch: Louis gửi đơn nháp mới trong lúc khách đang ở trang này (cùng route nên trang không tải lại)
// thì diễn lại với đơn mới. Bỏ qua khi đơn nháp bị xóa (null) để trang vẫn hiển thị bản chụp.
// watch: khách tăng/giảm số lượng hoặc sửa thông tin người nhận thì ghi lại vào đơn nháp dùng chung,
// để lần nhắn Louis tiếp theo gửi kèm đúng trạng thái này (Louis không "quên" chỉnh sửa của khách).
// deep: true để theo dõi cả thay đổi bên trong mảng quantities và object form.
// Chỉ ghi khi hiệu ứng đã xong (ready) và trang đang hiển thị đúng đơn nháp hiện tại.
watch([quantities, form], () => {
  if (phase.value !== 'ready' || !checkoutDraft.value || draft.value !== checkoutDraft.value) return
  syncCheckoutDraft({
    quantities: quantities.value,
    recipient: { name: form.name, phone: form.phone, address: form.address },
  })
}, { deep: true })

watch(checkoutDraft, (nextDraft) => {
  if (nextDraft && nextDraft !== draft.value) play(nextDraft, false)
})

// watch: Louis vừa bỏ đơn nháp trong lúc khách đang ở trang này (cùng route nên trang không tải lại).
// Dừng hiệu ứng đang chạy và về màn "chưa có đơn nháp". Đơn thật đã tạo (createdOrder) thì giữ nguyên
// để khách vẫn bấm "Thanh toán lại" được; đang gửi đơn thì để kết quả gửi đơn quyết định.
watch(discardCount, () => {
  if (createdOrder.value || submitting.value) return
  runId++
  draft.value = null
  draftChanges.value = []
  checkoutError.value = ''
})

// Khách quay lại tab trang đặt hàng (sau khi xem tab khác): tra lại kho/giá của đơn nháp trong im lặng,
// để số "còn N" và số lượng không bị cũ. Cùng cách với trang Giỏ hàng và trang chi tiết sản phẩm.
// Dùng lại refreshStockSilently: hàm này tự bỏ qua khi trang không cho sửa (đang chạy hiệu ứng, đang đặt hàng,
// đã tạo đơn), khi đang tra kho vì nút "+", hoặc khi đang hiển thị đơn khác đơn nháp hiện tại.
// Hủy hẹn giờ tra kho ngầm còn chờ (nếu có) để không gửi 2 request trùng nhau.
// Không hẹn giờ gọi liên tục: chỉ gọi đúng lúc khách quay lại xem, tránh tốn request khi tab đang ẩn.
// useTabVisible (helper dùng chung) tự gắn / gỡ bộ lắng nghe đổi tab theo vòng đời của trang.
useTabVisible(() => {
  clearTimeout(stockRefreshTimer)
  refreshStockSilently()
})

// onMounted: chạy 1 lần khi trang vừa hiện ra. Có đơn nháp thì bắt đầu hiệu ứng, không thì hiện màn "chưa có đơn".
onMounted(() => {
  if (checkoutDraft.value) play(checkoutDraft.value)
})
// Rời trang giữa chừng: tăng runId để các vòng lặp hiệu ứng đang chạy tự dừng, hủy lượt tra kho ngầm đang hẹn.
onBeforeUnmount(() => {
  runId++
  clearTimeout(stockRefreshTimer)
})
</script>

<template>
  <section class="quick-order">
    <div class="container quick-order-inner">
      <!-- Tiêu đề trang -->
      <header class="qo-head">
        <p class="qo-eyebrow">Louis Dé Fule · Trợ lý lên đơn</p>
        <h1 class="qo-title">Lên đơn nhanh cho quý ông</h1>
        <p class="qo-lead">Louis đã chuẩn bị sẵn đơn theo cuộc trò chuyện. Quý ông chỉnh lại số lượng hoặc thông tin nhận hàng nếu cần.</p>
      </header>

      <!-- Chưa có đơn nháp (khách gõ thẳng URL hoặc vừa tải lại trang): mời trò chuyện với Louis -->
      <div v-if="!draft" class="qo-card qo-empty">
        <p class="qo-empty-title">Chưa có đơn nháp nào</p>
        <p class="qo-empty-text">
          Đơn nháp được giữ trong lúc quý ông còn mở tab này và sẽ mất khi đóng tab, đăng xuất hoặc đặt hàng xong.
          Quý ông nhắn cho Louis sản phẩm muốn mua, Louis sẽ lên đơn ngay.
        </p>
        <div class="qo-empty-actions">
          <button type="button" class="btn-brass" @click="handleEdit">Trò chuyện với Louis</button>
          <RouterLink class="btn-outline-navy" :to="{ name: 'catalog' }">Xem sản phẩm</RouterLink>
        </div>
      </div>

      <!-- Màn mở đầu: Louis chuẩn bị đơn, gõ từng bước kèm dấu ✓ -->
      <Transition name="console">
        <div v-if="draft && phase === 'prep'" class="prep-console" aria-live="polite">
          <div class="prep-dots" aria-hidden="true"><span></span><span></span><span></span></div>
          <p
            v-for="(line, index) in prepLines"
            v-show="index < prepVisible"
            :key="PREP_STEPS[index]"
            class="prep-line"
          >
            <span class="prep-arrow">›</span>
            <span class="prep-text">{{ line.text }}<span v-if="!line.done && index === prepVisible - 1" class="caret"></span></span>
            <span class="prep-check" :class="{ show: line.done }">✓</span>
          </p>
        </div>
      </Transition>

      <!-- Bố cục chính: form bên trái, cụm điều khiển bên phải (điện thoại thì xếp chồng) -->
      <div v-if="draft && phase !== 'prep'" class="row g-4 qo-layout">
        <!-- CỘT TRÁI: form đơn hàng -->
        <div class="col-lg-7">
          <form class="qo-card qo-form" novalidate @submit.prevent="handleConfirm">
            <!-- Phần 1: thông tin người nhận, Louis tự điền rồi khách được sửa -->
            <div class="qo-section-head">
              <h2 class="qo-section-title"><span class="qo-step">1</span> Người nhận kiện hàng</h2>
              <span class="qo-hint">* Bắt buộc</span>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="qo-label" for="qo-name">Họ và tên người nhận *</label>
                <input
                  id="qo-name"
                  v-model="form.name"
                  type="text"
                  class="qo-input"
                  :class="{ 'is-filling': activeField === 'name', 'is-invalid': fieldErrors.name, 'is-missing': isMissing('name') }"
                  :readonly="!editable"
                  maxlength="100"
                  autocomplete="name"
                  @input="clearError('name')"
                >
                <p v-if="fieldErrors.name" class="qo-error">{{ fieldErrors.name }}</p>
                <p v-else-if="isMissing('name')" class="qo-missing-hint">Hồ sơ chưa có họ tên người nhận, quý ông điền giúp Louis nhé.</p>
              </div>
              <div class="col-md-6">
                <label class="qo-label" for="qo-phone">Số điện thoại *</label>
                <input
                  id="qo-phone"
                  v-model="form.phone"
                  type="tel"
                  class="qo-input"
                  :class="{ 'is-filling': activeField === 'phone', 'is-invalid': fieldErrors.phone, 'is-missing': isMissing('phone') }"
                  :readonly="!editable"
                  maxlength="20"
                  autocomplete="tel"
                  @input="clearError('phone')"
                >
                <p v-if="fieldErrors.phone" class="qo-error">{{ fieldErrors.phone }}</p>
                <p v-else-if="isMissing('phone')" class="qo-missing-hint">Hồ sơ chưa có số điện thoại, quý ông điền giúp Louis nhé.</p>
              </div>
              <div class="col-12">
                <label class="qo-label" for="qo-address">Địa chỉ giao hàng *</label>
                <textarea
                  id="qo-address"
                  v-model="form.address"
                  rows="2"
                  class="qo-input"
                  :class="{ 'is-filling': activeField === 'address', 'is-invalid': fieldErrors.address, 'is-missing': isMissing('address') }"
                  :readonly="!editable"
                  maxlength="500"
                  autocomplete="street-address"
                  @input="clearError('address')"
                ></textarea>
                <p v-if="fieldErrors.address" class="qo-error">{{ fieldErrors.address }}</p>
                <p v-else-if="isMissing('address')" class="qo-missing-hint">Hồ sơ chưa có địa chỉ giao hàng, quý ông điền giúp Louis nhé.</p>
              </div>
            </div>

            <!-- Phần 2: các món đã chọn, cho tăng/giảm số lượng, giá khóa -->
            <div class="qo-section-head qo-section-head--spaced">
              <h2 class="qo-section-title"><span class="qo-step">2</span> Món quý ông đã chọn</h2>
              <span class="qo-hint">Giá do cửa hàng chốt</span>
            </div>

            <ul class="qo-items">
              <template v-for="(item, index) in items" :key="item.sku">
                <li v-if="index < itemsVisible" class="qo-item" :class="{ 'qo-item--off': item.available === false }">
                  <!-- Ảnh sản phẩm; chưa có ảnh thì hiện chữ cái đầu của tên -->
                  <img v-if="item.image" class="qo-item-thumb qo-item-img" :src="resolveImageUrl(item.image)" :alt="item.name">
                  <div v-else class="qo-item-thumb" aria-hidden="true">{{ item.name.charAt(0) }}</div>

                  <div class="qo-item-info">
                    <p class="qo-item-name">{{ item.name }}</p>
                    <!-- Món đã hết hàng/ngừng bán (sau khi tra lại kho) thì báo đỏ thay cho số còn lại -->
                    <p class="qo-item-variant">
                      <template v-if="item.variant_name">{{ item.variant_name }} · </template>
                      <span v-if="item.available === false" class="qo-item-off">Hết hàng hoặc ngừng bán</span>
                      <template v-else>còn {{ item.stock }}</template>
                    </p>
                    <!-- Giá đơn vị: khóa, chỉ hiển thị -->
                    <p class="qo-item-price">
                      <svg class="qo-lock" viewBox="0 0 16 16" aria-hidden="true"><path d="M4.5 7V5a3.5 3.5 0 0 1 7 0v2h.5A1.5 1.5 0 0 1 13.5 8.5v5A1.5 1.5 0 0 1 12 15H4a1.5 1.5 0 0 1-1.5-1.5v-5A1.5 1.5 0 0 1 4 7h.5Zm1.5 0h4V5a2 2 0 1 0-4 0v2Z" fill="currentColor"/></svg>
                      <span>{{ formatPrice(item.price) }}</span>
                      <s v-if="item.original_price" class="qo-item-old">{{ formatPrice(item.original_price) }}</s>
                    </p>
                  </div>

                  <div class="qo-item-side">
                    <!-- Bộ tăng/giảm số lượng, giới hạn 1 đến tồn kho. Nút "+" không khóa khi chạm tồn kho:
                         bấm thì Louis tra lại kho, hàng vừa được hoàn kho vẫn tăng được mà không cần tải lại trang.
                         Cả hai nút khóa trong lúc đang tra kho (checkingStock). -->
                    <div class="qo-stepper" role="group" :aria-label="`Số lượng ${item.name}`">
                      <button
                        type="button"
                        :disabled="!editable || checkingStock || item.available === false || quantities[index] <= 1"
                        aria-label="Giảm số lượng"
                        @click="changeQuantity(index, -1)"
                      >−</button>
                      <!-- Ô gõ số lượng tay. Không đặt max theo tồn kho (số đó có thể đã cũ):
                           rời ô / bấm Enter thì Louis tra lại kho rồi mới nhận số (xem onQuantityInput). -->
                      <input
                        type="number"
                        class="qo-qty"
                        min="1"
                        :value="quantities[index]"
                        :disabled="!editable || checkingStock || item.available === false"
                        :aria-label="`Nhập số lượng ${item.name}`"
                        @change="onQuantityInput(index, $event)"
                      />

                      <button
                        type="button"
                        :disabled="!editable || checkingStock || item.available === false"
                        aria-label="Tăng số lượng"
                        @click="changeQuantity(index, 1)"
                      >+</button>
                    </div>
                    <!-- Thành tiền; món hết hàng/ngừng bán thì gạch đi kèm ghi chú, vì tổng tiền đã không cộng món này -->
                    <p class="qo-line-total" :class="{ 'qo-line-total--off': item.available === false }">
                      {{ formatPrice(item.price * quantities[index]) }}
                    </p>
                    <p v-if="item.available === false" class="qo-line-note">không tính vào tổng</p>
                  </div>

                  <!-- Nút bỏ hẳn món khỏi đơn; khóa khi chỉ còn 1 món (dùng "Bỏ đơn nháp" thay thế) -->
                  <button
                    type="button"
                    class="qo-item-remove"
                    :disabled="!editable || items.length <= 1"
                    :title="items.length <= 1 ? 'Đơn còn 1 món, dùng Bỏ đơn nháp' : 'Bỏ món này'"
                    :aria-label="`Bỏ ${item.name} khỏi đơn`"
                    @click="removeItem(index)"
                  >
                    <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6 2h4a1 1 0 0 1 1 1v1h3v1.5H2V4h3V3a1 1 0 0 1 1-1Zm.5 1.5V4h3v-.5h-3ZM3.5 6.5h9l-.7 7.1A1.5 1.5 0 0 1 10.3 15H5.7a1.5 1.5 0 0 1-1.5-1.4L3.5 6.5Z" fill="currentColor"/></svg>
                  </button>
                </li>
              </template>
            </ul>

            <!-- Dòng trạng thái: đang điền / còn ô trống cần khách điền / đã sẵn sàng -->
            <p class="qo-status" :class="{ ready: editable && !hasMissingInfo && !hasUnavailableItem, todo: editable && (hasMissingInfo || hasUnavailableItem) }">
              <span class="qo-status-dot"></span>
              {{ statusText }}
            </p>
          </form>
        </div>

        <!-- CỘT PHẢI: tóm tắt + cụm điều khiển, dính theo khi cuộn trên máy tính -->
        <div class="col-lg-5">
          <aside class="qo-card qo-panel" :class="{ thud: stamped }">
            <h2 class="qo-panel-title">Tóm tắt đơn</h2>
            <p class="qo-panel-sub">{{ totalQuantity }} sản phẩm · {{ items.length }} món</p>

            <!-- Bảng tiền -->
            <dl class="qo-sums">
              <div><dt>Tạm tính</dt><dd>{{ formatPrice(displayListTotal) }}</dd></div>
              <div v-if="savings > 0"><dt>Đã giảm</dt><dd class="qo-save">−{{ formatPrice(savings) }}</dd></div>
            </dl>

            <div class="qo-total">
              <span>Tổng thanh toán</span>
              <strong>{{ formatPrice(displayTotal) }}</strong>
              <!-- Con dấu "Đã duyệt" rơi xuống khi Louis điền xong -->
              <div v-if="stamped" class="stamp" aria-label="Louis Dé Fule đã duyệt đơn">
                <span class="stamp-top">Louis Dé Fule</span>
                <span class="stamp-main">Đã duyệt</span>
                <span class="stamp-bottom">★ FuLe ★</span>
              </div>
            </div>

            <!-- Chọn phương thức thanh toán -->
            <p class="qo-label qo-label--panel">Phương thức thanh toán</p>
            <div class="pay-options">
              <label class="pay-option" :class="{ active: paymentMethod === 'cod', disabled: !editable }">
                <input v-model="paymentMethod" type="radio" value="cod" class="visually-hidden" :disabled="!editable">
                <span class="pay-name">Thanh toán khi nhận hàng</span>
                <span class="pay-desc">Trả tiền mặt cho người giao (COD)</span>
              </label>
              <label class="pay-option" :class="{ active: paymentMethod === 'sepay', disabled: !editable }">
                <input v-model="paymentMethod" type="radio" value="sepay" class="visually-hidden" :disabled="!editable">
                <span class="pay-name">Thanh toán online</span>
                <span class="pay-desc">Chuyển khoản qua SePay</span>
              </label>
            </div>

            <!-- Các thay đổi vừa áp theo kho/giá mới nhất (kẹp số lượng, đổi giá, món hết hàng) -->
            <div v-if="draftChanges.length" class="qo-changes" role="status">
              <p class="qo-changes-title">Louis vừa cập nhật đơn theo kho mới nhất:</p>
              <ul>
                <li v-for="(change, index) in draftChanges" :key="index">{{ change }}</li>
              </ul>
            </div>

            <!-- Lỗi khi gửi đơn (hết hàng, giá vừa đổi, lỗi mạng...) -->
            <p v-if="checkoutError" class="qo-alert" role="alert">{{ checkoutError }}</p>

            <!-- Cụm nút điều khiển. Đơn đã tạo mà chuyển SePay lỗi thì nút chính thành "Thanh toán lại". -->
            <div class="qo-actions">
              <button
                type="button"
                class="btn-brass"
                :disabled="createdOrder ? submitting : (!editable || hasUnavailableItem)"
                @click="handleConfirm"
              >{{ confirmLabel }}</button>
              <button type="button" class="btn-outline-navy" :disabled="submitting" @click="handleEdit">Nhờ Louis sửa đơn</button>
              <!-- Chỉ hiện khi đơn thật chưa được tạo; đơn đã tạo thì hủy ở trang chi tiết đơn -->
              <button v-if="!createdOrder" type="button" class="btn-outline-danger" :disabled="submitting" @click="handleDiscard">Bỏ đơn nháp</button>
            </div>

            <p class="qo-note">Khi xác nhận, cửa hàng sẽ kiểm tra lại giá và tồn kho trước khi tạo đơn.</p>
          </aside>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.quick-order {
  min-height: 70vh;
  padding: 1.5rem 0 3rem;
  font-size: 0.9rem;
  background:
    radial-gradient(circle at 50% 0%, rgba(184, 137, 62, 0.1), transparent 50%),
    var(--color-paper, #f7f4ee);
}

/* Giới hạn bề rộng cho gọn, không trải hết màn hình lớn. */
.quick-order-inner {
  max-width: 1040px;
}

/* ---------- Tiêu đề ---------- */
.qo-head {
  margin-bottom: 1.25rem;
  padding-bottom: 0.9rem;
  border-bottom: 1px solid #e6e0d4;
}

.qo-eyebrow {
  margin: 0 0 0.25rem;
  color: #9e7432;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.qo-title {
  margin: 0 0 0.4rem;
  font-family: var(--font-display);
  font-size: clamp(1.4rem, 3vw, 1.85rem);
}

.qo-lead {
  max-width: 44rem;
  margin: 0;
  color: #6b6560;
  font-size: 0.85rem;
}

/* ---------- Màn mở đầu: bảng điều khiển của Louis ---------- */
.prep-console {
  max-width: 30rem;
  margin: 1.5rem auto;
  padding: 1rem 1.15rem 0.85rem;
  border-radius: 8px;
  background: linear-gradient(160deg, #1c2a3a, #131e2a);
  box-shadow: 0 18px 40px rgba(19, 30, 42, 0.28);
  color: #efeae0;
  font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
  font-size: 0.82rem;
}

.prep-dots {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 1rem;
}

.prep-dots span {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 50%;
  background: #25364a;
}

.prep-dots span:first-child {
  background: #b8893e;
}

.prep-line {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  margin: 0 0 0.55rem;
}

.prep-arrow {
  color: #b8893e;
}

.prep-text {
  flex: 1;
}

.prep-check {
  color: #d8b06a;
  font-weight: 700;
  opacity: 0;
  transform: scale(0.3);
  transition: opacity 0.2s ease, transform 0.25s cubic-bezier(0.34, 1.8, 0.64, 1);
}

.prep-check.show {
  opacity: 1;
  transform: scale(1);
}

.caret {
  display: inline-block;
  width: 0.5em;
  height: 1em;
  margin-left: 2px;
  vertical-align: -0.12em;
  background: #d8b06a;
  animation: blink 0.8s steps(1) infinite;
}

@keyframes blink {
  50% { opacity: 0; }
}

.console-leave-active {
  transition: opacity 0.35s ease, transform 0.35s ease;
}

.console-leave-to {
  opacity: 0;
  transform: scale(0.96) translateY(-8px);
}

/* ---------- Khung chung ---------- */
.qo-layout {
  animation: rise 0.45s ease-out;
}

@keyframes rise {
  from { opacity: 0; transform: translateY(12px); }
}

.qo-card {
  padding: 1.15rem 1.25rem;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 6px 18px rgba(19, 30, 42, 0.05);
}

.qo-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.qo-section-head--spaced {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid #e6e0d4;
}

.qo-section-title {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0;
  font-family: var(--font-display);
  font-size: 1rem;
}

.qo-step {
  display: inline-grid;
  place-items: center;
  width: 1.4rem;
  height: 1.4rem;
  border-radius: 50%;
  background: #b8893e;
  color: #ffffff;
  font-family: Inter, system-ui, sans-serif;
  font-size: 0.75rem;
}

.qo-hint {
  color: #6b6560;
  font-size: 0.7rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

/* ---------- Danh sách món ---------- */
.qo-items {
  display: grid;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.qo-item {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  padding: 0.6rem 0.7rem;
  border: 1px solid #e6e0d4;
  border-radius: 8px;
  background: #fbf9f4;
  animation: item-in 0.45s cubic-bezier(0.2, 0.8, 0.3, 1.2);
}

/* Món trượt từ phải vào như được Louis "đặt" lên đơn. */
@keyframes item-in {
  from { opacity: 0; transform: translateX(24px) scale(0.97); }
}

.qo-item-thumb {
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 2.6rem;
  height: 2.6rem;
  border-radius: 6px;
  background: linear-gradient(150deg, #25364a, #131e2a);
  color: #d8b06a;
  font-family: var(--font-display);
  font-size: 1.1rem;
}

/* object-fit: cover cắt ảnh vừa khung vuông mà không bị méo. */
.qo-item-img {
  object-fit: cover;
}

.qo-item-info {
  flex: 1;
  min-width: 0;
}

.qo-item-name {
  margin: 0;
  font-weight: 600;
  line-height: 1.35;
}

.qo-item-variant {
  margin: 0.05rem 0 0.15rem;
  color: #6b6560;
  font-size: 0.76rem;
}

.qo-item-price {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  color: #9e7432;
  font-weight: 600;
}

.qo-lock {
  width: 0.85rem;
  height: 0.85rem;
  color: #6b6560;
}

.qo-item-old {
  color: #6b6560;
  font-size: 0.8rem;
  font-weight: 400;
}

.qo-item-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.4rem;
}

/* Nút bỏ món: biểu tượng thùng rác xám, rê chuột thì đỏ theo bảng màu lỗi của dự án. */
.qo-item-remove {
  display: grid;
  flex: 0 0 auto;
  place-items: center;
  width: 1.9rem;
  height: 1.9rem;
  padding: 0;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: #6b6560;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.qo-item-remove svg {
  width: 1rem;
  height: 1rem;
}

.qo-item-remove:hover:not(:disabled) {
  background: #fdf1f0;
  color: #ba1a1a;
}

.qo-item-remove:disabled {
  cursor: not-allowed;
  opacity: 0.4;
}

.qo-stepper {
  display: inline-flex;
  align-items: center;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
  background: #ffffff;
}

.qo-stepper button {
  width: 1.7rem;
  height: 1.7rem;
  border: 0;
  background: transparent;
  color: #131e2a;
  font-size: 1.05rem;
  line-height: 1;
}

.qo-stepper button:hover:not(:disabled) {
  background: #efeae0;
}

.qo-stepper button:disabled {
  color: #c4beb3;
  cursor: not-allowed;
}

/* Ô gõ số lượng: trông như con số cũ (không viền, nền trong suốt), ẩn mũi tên tăng giảm mặc định của trình duyệt. */
.qo-qty {
  width: 2.6rem;
  min-width: 1.7rem;
  padding: 0;
  border: 0;
  border-right: 1px solid #e6e0d4;
  border-left: 1px solid #e6e0d4;
  background: transparent;
  color: #131e2a;
  font: inherit;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  text-align: center;
  -moz-appearance: textfield;
}
.qo-qty::-webkit-outer-spin-button,
.qo-qty::-webkit-inner-spin-button {
  margin: 0;
  -webkit-appearance: none;
}
.qo-qty:focus {
  outline: 2px solid #b8893e;
  outline-offset: -2px;
}
.qo-qty:disabled {
  color: #6b6560;
}

/* tabular-nums: các chữ số rộng bằng nhau, nên lúc số đổi giá không bị rung trái phải. */
.qo-line-total {
  margin: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  white-space: nowrap;
}

/* ---------- Ô nhập ---------- */
.qo-label {
  display: block;
  margin-bottom: 0.25rem;
  color: #131e2a;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.qo-input {
  width: 100%;
  padding: 0.5rem 0.7rem;
  font-size: 0.88rem;
  border: 1px solid #d9d4ca;
  border-radius: 6px;
  background: #ffffff;
  color: #131e2a;
  resize: vertical;
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.qo-input:focus {
  border-color: #b8893e;
  outline: none;
  box-shadow: 0 0 0 3px rgba(184, 137, 62, 0.18);
}

/* Lúc Louis đang điền thì ô bị khóa (readonly), nền hơi kem để khách biết chưa sửa được. */
.qo-input:read-only {
  background: #fbf9f4;
}

/* Ô Louis đang điền: viền đồng phát sáng nhấp nháy. */
.qo-input.is-filling {
  border-color: #b8893e;
  animation: glow 1s ease-in-out infinite;
}

@keyframes glow {
  50% { box-shadow: 0 0 0 4px rgba(184, 137, 62, 0.22); }
}

.qo-input.is-invalid {
  border-color: #ba1a1a;
}

.qo-error {
  margin: 0.25rem 0 0;
  color: #ba1a1a;
  font-size: 0.76rem;
}

.qo-status {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 1rem 0 0;
  color: #6b6560;
  font-size: 0.78rem;
}

.qo-status-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #b8893e;
  animation: blink 0.8s steps(1) infinite;
}

.qo-status.ready {
  color: #2f7d4f;
}

.qo-status.ready .qo-status-dot {
  background: #2f7d4f;
  animation: none;
}

/* Còn ô trống khách cần tự điền: chữ đồng đậm, chấm đồng đứng yên. */
.qo-status.todo {
  color: #9e7432;
  font-weight: 600;
}

.qo-status.todo .qo-status-dot {
  animation: none;
}

/* Ô còn trống sau khi Louis điền xong: viền đồng nét đứt và nền kem để khách thấy cần điền. */
.qo-input.is-missing {
  border-style: dashed;
  border-color: #b8893e;
  background: #fffaf0;
}

.qo-missing-hint {
  margin: 0.25rem 0 0;
  color: #9e7432;
  font-size: 0.76rem;
}

/* ---------- Cột phải: tóm tắt + điều khiển ---------- */
/* sticky: khối này "dính" ở mép trên khi cuộn trang trên màn hình rộng. */
@media (min-width: 992px) {
  .qo-panel {
    position: sticky;
    top: 1.5rem;
  }
}

.qo-panel-title {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.1rem;
}

.qo-panel-sub {
  margin: 0.1rem 0 0.75rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid #e6e0d4;
  color: #6b6560;
  font-size: 0.85rem;
}

.qo-sums {
  margin: 0 0 0.75rem;
}

.qo-sums div {
  display: flex;
  justify-content: space-between;
  margin-bottom: 0.35rem;
}

.qo-sums dt {
  color: #4a5565;
  font-weight: 400;
}

.qo-sums dd {
  margin: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.qo-save {
  color: #2f7d4f;
}

.qo-total {
  position: relative;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
  padding: 0.75rem 0;
  border-top: 1px dashed #d9d4ca;
  border-bottom: 1px dashed #d9d4ca;
}

.qo-total span {
  color: #4a5565;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.qo-total strong {
  color: #9e7432;
  font-family: var(--font-display);
  font-size: 1.45rem;
  font-variant-numeric: tabular-nums;
}

/* ---------- Con dấu ---------- */
.stamp {
  position: absolute;
  top: -2rem;
  left: 36%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 5.6rem;
  height: 5.6rem;
  border: 3px solid #b8893e;
  border-radius: 50%;
  outline: 1.5px solid #b8893e;
  outline-offset: -8px;
  color: #b8893e;
  font-weight: 700;
  text-transform: uppercase;
  opacity: 0.85;
  transform: rotate(-12deg);
  /* multiply: màu dấu "ngấm" vào nền như mực thật thay vì phủ đè lên chữ. */
  mix-blend-mode: multiply;
  pointer-events: none;
  animation: stamp-drop 0.45s cubic-bezier(0.3, 1.4, 0.5, 1) both;
}

@keyframes stamp-drop {
  0% { opacity: 0; transform: scale(2.8) rotate(-2deg); }
  60% { opacity: 0.95; transform: scale(0.92) rotate(-12deg); }
  100% { opacity: 0.85; transform: scale(1) rotate(-12deg); }
}

.stamp-top,
.stamp-bottom {
  font-size: 0.48rem;
  letter-spacing: 0.12em;
}

.stamp-main {
  margin: 0.05rem 0;
  font-family: var(--font-display);
  font-size: 0.92rem;
  letter-spacing: 0.04em;
}

/* Khối tóm tắt "giật" nhẹ đúng lúc con dấu chạm xuống (trễ 0.27 giây, khớp nhịp dấu rơi). */
.qo-panel.thud {
  animation: thud 0.35s ease-out 0.27s;
}

@keyframes thud {
  30% { transform: translateY(4px) scale(0.995); }
  60% { transform: translateY(-1px); }
}

/* ---------- Phương thức thanh toán ---------- */
.qo-label--panel {
  margin-bottom: 0.5rem;
}

.pay-options {
  display: grid;
  gap: 0.45rem;
}

.pay-option {
  display: flex;
  flex-direction: column;
  padding: 0.5rem 0.75rem;
  border: 1.5px solid #d9d4ca;
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}

.pay-option.active {
  border-color: #b8893e;
  background: #fbf6ec;
}

.pay-option.disabled {
  cursor: not-allowed;
  opacity: 0.6;
}

.pay-option:focus-within {
  outline: 2px solid #b8893e;
  outline-offset: 2px;
}

.pay-name {
  font-weight: 600;
}

.pay-desc {
  color: #6b6560;
  font-size: 0.75rem;
}

/* ---------- Nút điều khiển ---------- */
.qo-actions {
  display: grid;
  gap: 0.45rem;
  margin-top: 1rem;
}

.btn-brass,
.btn-outline-navy {
  padding: 0.6rem 0.9rem;
  border-radius: 6px;
  font-size: 0.85rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  transition: background-color 0.2s ease, color 0.2s ease, opacity 0.2s ease;
}

.btn-brass {
  border: 1px solid #b8893e;
  background: #b8893e;
  color: #ffffff;
  text-transform: uppercase;
}

.btn-brass:hover:not(:disabled) {
  border-color: #9e7432;
  background: #9e7432;
}

.btn-brass:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.btn-outline-navy {
  border: 1px solid #131e2a;
  background: transparent;
  color: #131e2a;
}

.btn-outline-navy:hover {
  background: #131e2a;
  color: #ffffff;
}

/* Nút hành động xóa (Bỏ đơn nháp): viền và chữ đỏ theo bảng màu lỗi của dự án, nền đỏ nhạt khi rê chuột. */
.btn-outline-danger {
  padding: 0.6rem 0.9rem;
  border: 1px solid #ba1a1a;
  border-radius: 6px;
  background: transparent;
  color: #93000a;
  font-size: 0.85rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.btn-outline-danger:hover:not(:disabled) {
  background: #fdf1f0;
}

.btn-outline-danger:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.btn-outline-navy:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

/* Khối báo lỗi khi gửi đơn: nền đỏ nhạt, chữ đỏ đậm theo bảng màu Stitch. */
.qo-alert {
  margin: 1rem 0 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid rgba(186, 26, 26, 0.3);
  border-radius: 6px;
  background: #fdf1f0;
  color: #93000a;
  font-size: 0.8rem;
}

/* Danh sách thay đổi sau khi tra lại kho: nền kem phụ, viền đồng, để khách để ý mà không giống lỗi. */
.qo-changes {
  /* Cách khối phương thức thanh toán phía trên, tránh hai viền dính liền. */
  margin: 1rem 0 0.75rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid #b8893e;
  border-radius: 6px;
  background: #efeae0;
  color: #1c2a3a;
  font-size: 0.8rem;
}

.qo-changes-title {
  margin: 0 0 0.3rem;
  font-weight: 600;
}

.qo-changes ul {
  margin: 0;
  padding-left: 1.1rem;
}

/* Món đã hết hàng/ngừng bán: viền đỏ nhạt, chữ báo màu đỏ. */
.qo-item--off {
  border-color: rgba(186, 26, 26, 0.45);
  background: #fdf1f0;
}

.qo-item-off {
  color: #ba1a1a;
  font-weight: 600;
}

/* Thành tiền của món hết hàng: gạch ngang, chữ phụ, để khách hiểu tổng không cộng món này. */
.qo-line-total--off {
  color: #6b6560;
  text-decoration: line-through;
}

.qo-line-note {
  margin: 0;
  color: #ba1a1a;
  font-size: 0.72rem;
  text-align: right;
}

/* Màn "chưa có đơn nháp". */
.qo-empty {
  max-width: 34rem;
  margin: 1.5rem auto;
  text-align: center;
}

.qo-empty-title {
  margin: 0 0 0.4rem;
  font-family: var(--font-display);
  font-size: 1.2rem;
  font-weight: 700;
}

.qo-empty-text {
  margin: 0 0 1rem;
  color: #6b6560;
}

.qo-empty-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: center;
}

.qo-empty-actions .btn-brass,
.qo-empty-actions .btn-outline-navy {
  display: inline-block;
  text-decoration: none;
}

.qo-note {
  margin: 0.75rem 0 0;
  color: #6b6560;
  font-size: 0.72rem;
  text-align: center;
}

/* Điện thoại: món hàng xếp dọc để bộ số lượng không bị chật. */
@media (max-width: 575.98px) {
  .qo-card {
    padding: 1.15rem;
  }

  .qo-item {
    flex-wrap: wrap;
  }

  .qo-item-side {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    width: 100%;
  }
}
</style>
